<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\ContributorFrontend;

use function __;
use function absint;
use function check_ajax_referer;
use function current_user_can;
use function delete_post_thumbnail;
use function get_current_user_id;
use function get_post;
use function get_post_meta;
use function get_post_thumbnail_id;
use function getimagesize;
use function in_array;
use function is_array;
use function is_string;
use function is_uploaded_file;
use function is_wp_error;
use function max;
use function media_handle_upload;
use function sanitize_text_field;
use function set_post_thumbnail;
use function sprintf;
use function update_post_meta;
use function wp_check_filetype_and_ext;
use function wp_delete_attachment;
use function wp_get_attachment_image_src;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;

/**
 * Scoped featured-image upload for the contributor dashboard.
 *
 * Contributors never gain the `upload_files` capability and can
 * never open the Media Library. Instead this endpoint uploads
 * exactly one featured image per post, and only for the author's
 * own editable posts (draft or needs-revision):
 *
 * - The file must really be an image: the extension and MIME
 *   type are checked with wp_check_filetype_and_ext() and the
 *   bytes are checked with getimagesize(); the MIME type the
 *   browser sent is never trusted.
 * - Only the MIME types the admin enabled (JPEG, PNG, WebP,
 *   AVIF) are accepted, and AVIF only when the server image
 *   editor supports it (see ContributorFrontendModule::
 *   avifSupported()).
 * - The file size and the longest side are capped by the module
 *   settings (defaults 2 MB and 2400 px). Oversized files are
 *   refused, never silently downscaled.
 * - Replacing or removing an image deletes the old attachment
 *   ONLY when it was uploaded through this endpoint (flagged
 *   with the `_traydigita_contributor_featured` attachment
 *   meta). A featured image an editor set is detached, never
 *   deleted.
 */
final class ContributorFrontendUpload
{
    /**
     * Post statuses an author may attach a featured image to.
     *
     * Drafts and needs-revision posts are editable; pending,
     * published and rejected posts are read-only for the author.
     *
     * @var array<int, string>
     */
    private const array EDITABLE_STATUSES = ['draft', 'needs-revision'];

    /**
     * Handle the featured-image upload AJAX request.
     *
     * Expects `post_id`, the `_wpnonce` for the featured nonce
     * action, and the file under `traydigita_featured_image`.
     * Answers with JSON: on success the attachment id, the
     * preview URL and the image dimensions.
     *
     * @return void
     */
    public static function handleUpload(): void
    {
        [$postId] = self::checkRequest();
        if (!isset($_FILES['traydigita_featured_image']) || !is_array($_FILES['traydigita_featured_image'])) {
            wp_send_json_error(['message' => __('Please choose an image file.', 'traydigita-core')]);
        }
        $file = $_FILES['traydigita_featured_image'];
        $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => self::uploadErrorMessage($error)]);
        }
        $tmpName = isset($file['tmp_name']) && is_string($file['tmp_name']) ? $file['tmp_name'] : '';
        $fileName = isset($file['name']) && is_string($file['name']) ? sanitize_text_field(wp_unslash($file['name'])) : '';
        $size = isset($file['size']) ? (int) $file['size'] : 0;
        if ($tmpName === '' || $fileName === '' || $size <= 0 || !is_uploaded_file($tmpName)) {
            wp_send_json_error(['message' => __('Please choose an image file.', 'traydigita-core')]);
        }
        $settings = ContributorFrontendModule::getSettings();
        $maxBytes = $settings['upload_max_mb'] * 1024 * 1024;
        if ($size > $maxBytes) {
            wp_send_json_error(['message' => sprintf(
                /* translators: %d: maximum file size in megabytes. */
                __('The image is too large. Maximum file size is %d MB.', 'traydigita-core'),
                $settings['upload_max_mb']
            )]);
        }
        $type = wp_check_filetype_and_ext($tmpName, $fileName);
        $ext = isset($type['ext']) && is_string($type['ext']) ? $type['ext'] : '';
        $mime = isset($type['type']) && is_string($type['type']) ? $type['type'] : '';
        if ($ext === '' || $mime === '') {
            wp_send_json_error(['message' => __('This file type is not allowed. Please upload an image.', 'traydigita-core')]);
        }
        $allowedMimes = self::allowedMimes($settings['allowed_types']);
        if (!isset($allowedMimes[$ext]) || $allowedMimes[$ext] !== $mime) {
            wp_send_json_error(['message' => __('This image type is not allowed.', 'traydigita-core')]);
        }
        if ($mime === 'image/avif' && !ContributorFrontendModule::avifSupported()) {
            wp_send_json_error(['message' => __('AVIF images are not supported on this server.', 'traydigita-core')]);
        }
        $imageSize = getimagesize($tmpName);
        if ($imageSize === false || $imageSize['mime'] !== $mime) {
            wp_send_json_error(['message' => __('This file is not a valid image.', 'traydigita-core')]);
        }
        $width = (int) $imageSize[0];
        $height = (int) $imageSize[1];
        if ($width <= 0 || $height <= 0) {
            wp_send_json_error(['message' => __('This file is not a valid image.', 'traydigita-core')]);
        }
        if (max($width, $height) > $settings['upload_max_px']) {
            wp_send_json_error(['message' => sprintf(
                /* translators: %d: maximum image dimension in pixels. */
                __('The image is too large. The longest side must be %d px or less.', 'traydigita-core'),
                $settings['upload_max_px']
            )]);
        }
        $attachmentId = media_handle_upload('traydigita_featured_image', $postId);
        if (is_wp_error($attachmentId)) {
            wp_send_json_error(['message' => __('The image could not be uploaded. Please try again.', 'traydigita-core')]);
        }
        $attachmentId = (int) $attachmentId;
        $oldId = get_post_thumbnail_id($postId);
        if ($oldId > 0 && $oldId !== $attachmentId) {
            self::detachOrDelete($postId, $oldId);
        }
        set_post_thumbnail($postId, $attachmentId);
        update_post_meta($attachmentId, ContributorFrontendModule::FEATURED_META, '1');
        $preview = wp_get_attachment_image_src($attachmentId, 'medium');
        wp_send_json_success([
            'attachment_id' => $attachmentId,
            'url' => is_array($preview) && isset($preview[0]) ? (string) $preview[0] : '',
            'width' => $width,
            'height' => $height,
        ]);
    }

    /**
     * Handle the featured-image removal AJAX request.
     *
     * Expects `post_id` and the `_wpnonce` for the featured
     * nonce action. Detaches the image, deleting the attachment
     * only when it was uploaded through this endpoint.
     *
     * @return void
     */
    public static function handleRemove(): void
    {
        [$postId] = self::checkRequest();
        $oldId = get_post_thumbnail_id($postId);
        if ($oldId <= 0) {
            wp_send_json_success(['removed' => false]);
        }
        self::detachOrDelete($postId, $oldId);
        wp_send_json_success(['removed' => true]);
    }

    /**
     * Validate the shared part of an upload/remove request.
     *
     * Verifies the nonce and the `edit_posts` capability, then
     * checks the post exists, belongs to the current user, is a
     * post, and is in an editable status (draft or
     * needs-revision). A failed check answers with a JSON error
     * and never returns: `wp_send_json_error()` ends the
     * request (wp_die), so the `return null` lines after it
     * would be unreachable dead code.
     *
     * @return array{0: int} Post id.
     */
    private static function checkRequest(): array
    {
        if (!check_ajax_referer(ContributorFrontendModule::FEATURED_NONCE_ACTION, '_wpnonce', false)) {
            wp_send_json_error(['message' => __('Security check failed. Please try again.', 'traydigita-core')]);
        }
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('You do not have permission to submit articles.', 'traydigita-core')]);
        }
        $postId = isset($_POST['post_id']) ? absint(wp_unslash((string) $_POST['post_id'])) : 0;
        if ($postId <= 0) {
            wp_send_json_error(['message' => __('Save the article as a draft first, then add a featured image.', 'traydigita-core')]);
        }
        $post = get_post($postId);
        if ($post === null
            || (int) $post->post_author !== get_current_user_id()
            || $post->post_type !== 'post'
            || !in_array($post->post_status, self::EDITABLE_STATUSES, true)
        ) {
            wp_send_json_error(['message' => __('This article cannot be edited.', 'traydigita-core')]);
        }
        return [$postId];
    }

    /**
     * Detach a featured image, deleting the attachment only
     * when it was uploaded through this endpoint.
     *
     * @param int $postId Post the image is attached to.
     * @param int $attachmentId Attachment to detach or delete.
     * @return void
     */
    private static function detachOrDelete(int $postId, int $attachmentId): void
    {
        delete_post_thumbnail($postId);
        $scoped = get_post_meta($attachmentId, ContributorFrontendModule::FEATURED_META, true);
        if ($scoped === '1') {
            wp_delete_attachment($attachmentId, true);
        }
    }

    /**
     * Allowed MIME types for the enabled extensions.
     *
     * @param array<int, string> $types Enabled extensions (jpg, png, webp, avif).
     * @return array<string, string> Extension to MIME type map.
     */
    private static function allowedMimes(array $types): array
    {
        $allowed = [];
        foreach ($types as $type) {
            if (!is_string($type)) {
                continue;
            }
            if ($type === 'jpg' || $type === 'jpeg') {
                $allowed['jpg'] = 'image/jpeg';
                $allowed['jpeg'] = 'image/jpeg';
                continue;
            }
            $mime = ContributorFrontendModule::ALLOWED_MIMES[$type] ?? null;
            if ($mime !== null) {
                $allowed[$type] = $mime;
            }
        }
        return $allowed;
    }

    /**
     * Human-readable message for a PHP upload error code.
     *
     * @param int $code UPLOAD_ERR_* code.
     * @return string
     */
    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => __(
                'The image is too large. Please choose a smaller file.',
                'traydigita-core'
            ),
            UPLOAD_ERR_PARTIAL => __('The upload was interrupted. Please try again.', 'traydigita-core'),
            UPLOAD_ERR_NO_FILE => __('Please choose an image file.', 'traydigita-core'),
            default => __('The image could not be uploaded. Please try again.', 'traydigita-core'),
        };
    }
}
