<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\EPaper;

use DateTimeImmutable;
use function __;
use function absint;
use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function bin2hex;
use function content_url;
use function count;
use function fclose;
use function file_exists;
use function file_put_contents;
use function finfo_close;
use function finfo_file;
use function finfo_open;
use function fopen;
use function fread;
use function get_option;
use function get_post;
use function home_url;
use function is_array;
use function is_int;
use function is_string;
use function move_uploaded_file;
use function random_bytes;
use function sanitize_file_name;
use function sanitize_text_field;
use function sprintf;
use function strtolower;
use function substr;
use function time;
use function trim;
use function unlink;
use function update_option;
use function usort;
use function wp_attachment_is_image;
use function wp_basename;
use function wp_check_filetype_and_ext;
use function wp_get_attachment_image_url;
use function wp_mkdir_p;
use const FILEINFO_MIME_TYPE;
use const UPLOAD_ERR_OK;

/**
 * Filesystem + option storage for E-Paper issues.
 *
 * Issues are NOT a custom post type: the whole issue list lives in
 * the single option `traydigita_epaper_issues` (autoload `no`),
 * one record per issue:
 * `['id' => string, 'title' => string, 'date' => 'Y-m-d',
 * 'file' => string, 'size' => int, 'cover_id' => int,
 * 'created' => int]`. The list is capped at MAX_ISSUES records
 * (500); beyond that the admin must delete an old issue before
 * adding a new one, so the option never grows without bound.
 *
 * PDF files live under `wp-content/traydigita-epaper/` (never in
 * the uploads directory), created with `wp_mkdir_p()` plus an
 * `index.php` silence file. Filenames are sanitized with
 * `sanitize_file_name()` and prefixed with the issue id, so two
 * uploads of the same original name never collide and no user
 * input ever reaches the filesystem unchecked. Absolute server
 * paths are never exposed: the UI and the frontend only ever see
 * the public content URL.
 *
 * Cover images are media-library attachment ids. The module never
 * renders a PDF first page as a cover: rendering is out of scope,
 * and the UI always shows a generated placeholder (a document
 * icon) when no cover image was uploaded. Deleting an issue
 * deletes its PDF file and drops the cover reference; the media
 * library attachment itself is NOT deleted, because it may be
 * used elsewhere on the site.
 *
 * PDF verification never trusts the file extension or the
 * client-sent MIME type alone: the file must pass all three
 * checks - `wp_check_filetype_and_ext()` reporting
 * `application/pdf`, `finfo` reporting `application/pdf`, and
 * the first bytes being the `%PDF-` magic header.
 */
final class EPaperStorage
{
    /**
     * Option name for the module settings (autoload `no`).
     */
    public const string OPTION_SETTINGS = 'traydigita_epaper';

    /**
     * Option name for the issue list (autoload `no`).
     */
    public const string OPTION_ISSUES = 'traydigita_epaper_issues';

    /**
     * Directory name under wp-content holding the PDF files.
     */
    public const string DIR_NAME = 'traydigita-epaper';

    /**
     * Default maximum PDF upload size in megabytes.
     */
    public const int DEFAULT_MAX_SIZE_MB = 20;

    /**
     * Hard cap for the configurable maximum upload size (MB).
     *
     * The admin-configurable limit is clamped to this value, so a
     * typo cannot turn the upload form into an unbounded one.
     */
    public const int MAX_SIZE_MB_CAP = 100;

    /**
     * Default number of issues per archive page.
     */
    public const int DEFAULT_PER_PAGE = 12;

    /**
     * Maximum number of issues stored in the option.
     *
     * The archive paginates from this in-memory list, so a very
     * large list costs memory on every archive render; 500 issues
     * is the documented cap and the admin is told to delete old
     * issues past it.
     */
    public const int MAX_ISSUES = 500;

    /**
     * Absolute path of the E-Paper directory.
     *
     * @return string
     */
    public static function dir(): string
    {
        return WP_CONTENT_DIR . '/' . self::DIR_NAME;
    }

    /**
     * Public base URL of the E-Paper directory.
     *
     * @return string
     */
    public static function urlBase(): string
    {
        return content_url('/' . self::DIR_NAME);
    }

    /**
     * Create the E-Paper directory (with an index.php silence file)
     * when it does not exist yet.
     *
     * @return bool True when the directory exists and is usable.
     */
    public static function ensureDir(): bool
    {
        $dir = self::dir();
        if (!wp_mkdir_p($dir)) {
            return false;
        }
        $silence = $dir . '/index.php';
        if (!file_exists($silence)) {
            file_put_contents($silence, "<?php\n// Silence is golden.\n");
        }
        return true;
    }

    /**
     * Read the module settings merged over the defaults.
     *
     * @return array{max_size_mb: int, issues_per_page: int}
     */
    public static function getSettings(): array
    {
        $stored = get_option(self::OPTION_SETTINGS, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        $settings = array_merge(self::getDefaults(), $stored);
        $maxSize = isset($settings['max_size_mb']) ? absint($settings['max_size_mb']) : self::DEFAULT_MAX_SIZE_MB;
        if ($maxSize < 1) {
            $maxSize = 1;
        }
        if ($maxSize > self::MAX_SIZE_MB_CAP) {
            $maxSize = self::MAX_SIZE_MB_CAP;
        }
        $perPage = isset($settings['issues_per_page']) ? absint($settings['issues_per_page']) : self::DEFAULT_PER_PAGE;
        if ($perPage < 1) {
            $perPage = 1;
        }
        if ($perPage > 50) {
            $perPage = 50;
        }
        return [
            'max_size_mb' => $maxSize,
            'issues_per_page' => $perPage,
        ];
    }

    /**
     * Default settings.
     *
     * @return array{max_size_mb: int, issues_per_page: int}
     */
    public static function getDefaults(): array
    {
        return [
            'max_size_mb' => self::DEFAULT_MAX_SIZE_MB,
            'issues_per_page' => self::DEFAULT_PER_PAGE,
        ];
    }

    /**
     * Maximum PDF upload size in bytes, from the settings.
     *
     * @return int
     */
    public static function maxBytes(): int
    {
        return self::getSettings()['max_size_mb'] * 1048576;
    }

    /**
     * Normalize one raw stored record into an issue array.
     *
     * Malformed records (missing id, title, or file, or an
     * unparseable date) are dropped instead of rendered with
     * invented data. The file value is reduced to its basename,
     * so a stored path can never escape the E-Paper directory.
     *
     * @param mixed $record Raw record from the option.
     * @return array{id: string, title: string, date: string, file: string, size: int, cover_id: int, created: int}|null
     */
    public static function normalizeIssue(mixed $record): ?array
    {
        if (!is_array($record)) {
            return null;
        }
        $id = isset($record['id']) && is_string($record['id']) ? trim($record['id']) : '';
        $title = isset($record['title']) && is_string($record['title']) ? trim($record['title']) : '';
        $date = isset($record['date']) && is_string($record['date']) ? trim($record['date']) : '';
        $file = isset($record['file']) && is_string($record['file']) ? wp_basename(trim($record['file'])) : '';
        if ($id === '' || $title === '' || $file === '' || self::validateDate($date) === null) {
            return null;
        }
        return [
            'id' => $id,
            'title' => $title,
            'date' => $date,
            'file' => $file,
            'size' => isset($record['size']) ? absint($record['size']) : 0,
            'cover_id' => isset($record['cover_id']) ? absint($record['cover_id']) : 0,
            'created' => isset($record['created']) && is_int($record['created']) ? $record['created'] : 0,
        ];
    }

    /**
     * Read all issues, newest first (date descending, then
     * creation time descending for same-day issues).
     *
     * @return array<int, array{id: string, title: string, date: string, file: string, size: int, cover_id: int, created: int}>
     */
    public static function getIssues(): array
    {
        $stored = get_option(self::OPTION_ISSUES, []);
        if (!is_array($stored)) {
            return [];
        }
        $issues = array_values(array_filter(array_map([self::class, 'normalizeIssue'], $stored)));
        usort($issues, static function (array $a, array $b): int {
            if ($a['date'] !== $b['date']) {
                return $b['date'] <=> $a['date'];
            }
            return $b['created'] <=> $a['created'];
        });
        return $issues;
    }

    /**
     * Find one issue by id, or null when it does not exist.
     *
     * @param string $id Issue id.
     * @return array{id: string, title: string, date: string, file: string, size: int, cover_id: int, created: int}|null
     */
    public static function getIssue(string $id): ?array
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }
        foreach (self::getIssues() as $issue) {
            if ($issue['id'] === $id) {
                return $issue;
            }
        }
        return null;
    }

    /**
     * The newest issues, newest first.
     *
     * @param int $count How many to return (clamped to 1-10).
     * @return array<int, array{id: string, title: string, date: string, file: string, size: int, cover_id: int, created: int}>
     */
    public static function latest(int $count = 1): array
    {
        if ($count < 1) {
            $count = 1;
        }
        if ($count > 10) {
            $count = 10;
        }
        return array_values(array_slice(self::getIssues(), 0, $count));
    }

    /**
     * Prev/next neighbours of one issue in date order.
     *
     * `prev` is the older issue (shown as "Previous issue"),
     * `next` is the newer issue (shown as "Next issue"). Either
     * side is null at the ends of the list or when the id is
     * unknown.
     *
     * @param string $id Issue id.
     * @return array{prev: array{id: string, title: string, date: string, file: string, size: int, cover_id: int, created: int}|null, next: array{id: string, title: string, date: string, file: string, size: int, cover_id: int, created: int}|null}
     */
    public static function neighbours(string $id): array
    {
        $issues = self::getIssues();
        $count = count($issues);
        for ($index = 0; $index < $count; $index++) {
            if ($issues[$index]['id'] !== $id) {
                continue;
            }
            return [
                'prev' => $issues[$index + 1] ?? null,
                'next' => $issues[$index - 1] ?? null,
            ];
        }
        return ['prev' => null, 'next' => null];
    }

    /**
     * Public URL of the issue archive.
     *
     * @return string
     */
    public static function archiveUrl(): string
    {
        return home_url('/epaper/');
    }

    /**
     * Public URL of one issue's single view.
     *
     * @param array{id: string, title: string, date: string, file: string, size: int, cover_id: int, created: int} $issue Issue record.
     * @return string
     */
    public static function issueUrl(array $issue): string
    {
        return home_url('/epaper/' . $issue['id'] . '/');
    }

    /**
     * Public URL of one issue's PDF file.
     *
     * @param array{id: string, title: string, date: string, file: string, size: int, cover_id: int, created: int} $issue Issue record.
     * @return string
     */
    public static function pdfUrl(array $issue): string
    {
        return self::urlBase() . '/' . $issue['file'];
    }

    /**
     * Cover image URL for an issue, or null when it has none.
     *
     * The cover is a media-library attachment id; 0 (or an
     * attachment that is no longer an image) means "no cover",
     * and the frontend shows the generated placeholder instead.
     * The first page of the PDF is never rendered as a cover.
     *
     * @param array{id: string, title: string, date: string, file: string, size: int, cover_id: int, created: int} $issue Issue record.
     * @param string $size Registered image size name.
     * @return string|null
     */
    public static function coverUrl(array $issue, string $size = 'medium'): ?string
    {
        if ($issue['cover_id'] <= 0) {
            return null;
        }
        if (!wp_attachment_is_image($issue['cover_id'])) {
            return null;
        }
        $url = wp_get_attachment_image_url($issue['cover_id'], $size);
        if ($url === false || $url === '') {
            return null;
        }
        return $url;
    }

    /**
     * Validate a date string as `Y-m-d`, or return null.
     *
     * @param string $date Raw date value.
     * @return string|null The normalized `Y-m-d` date, or null when invalid.
     */
    public static function validateDate(string $date): ?string
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            return null;
        }
        return $date;
    }

    /**
     * Validate a cover attachment id: 0 means "no cover", any
     * other value must be an existing image attachment.
     *
     * @param int $coverId Attachment id.
     * @return bool
     */
    public static function isValidCoverId(int $coverId): bool
    {
        if ($coverId === 0) {
            return true;
        }
        $post = get_post($coverId);
        if ($post === null || $post->post_type !== 'attachment') {
            return false;
        }
        return wp_attachment_is_image($coverId);
    }

    /**
     * Verify that a local file is a real PDF.
     *
     * Three independent checks, because neither the extension nor
     * the client-sent MIME type can be trusted: the file must
     * start with the `%PDF-` magic header, `finfo` must report
     * `application/pdf`, and `wp_check_filetype_and_ext()` must
     * agree (extension `pdf`, type `application/pdf`).
     *
     * @param string $tmpPath Absolute path of the file to check.
     * @param string $originalName Original uploaded filename.
     * @return array{success: bool, message: string}
     */
    public static function verifyPdf(string $tmpPath, string $originalName): array
    {
        $handle = fopen($tmpPath, 'rb');
        if ($handle === false) {
            return [
                'success' => false,
                'message' => __('The uploaded file could not be read.', 'traydigita-core'),
            ];
        }
        $header = fread($handle, 5);
        fclose($handle);
        if ($header !== '%PDF-') {
            return [
                'success' => false,
                'message' => __('The uploaded file is not a PDF file.', 'traydigita-core'),
            ];
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return [
                'success' => false,
                'message' => __('The uploaded file could not be checked.', 'traydigita-core'),
            ];
        }
        $mime = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);
        if ($mime !== 'application/pdf') {
            return [
                'success' => false,
                'message' => __('The uploaded file is not a PDF file.', 'traydigita-core'),
            ];
        }
        $checked = wp_check_filetype_and_ext($tmpPath, $originalName);
        if (!is_array($checked) || ($checked['ext'] ?? '') !== 'pdf' || ($checked['type'] ?? '') !== 'application/pdf') {
            return [
                'success' => false,
                'message' => __('The uploaded file is not a PDF file.', 'traydigita-core'),
            ];
        }
        return [
            'success' => true,
            'message' => __('The uploaded file is a valid PDF.', 'traydigita-core'),
        ];
    }

    /**
     * Store an uploaded PDF as a new issue.
     *
     * Expects one entry of the `$_FILES` array. The upload must
     * be a real HTTP upload within the configured size limit and
     * pass verifyPdf(); the title and date are validated here too,
     * and the issue list cap is enforced before anything is
     * written. The stored filename is the issue id plus the
     * sanitized original name, so it can never collide or escape
     * the E-Paper directory.
     *
     * @param mixed $file One `$_FILES` entry.
     * @param string $title Issue title.
     * @param string $date Issue date (`Y-m-d`).
     * @param int $coverId Media-library cover attachment id (0 for none).
     * @return array{success: bool, message: string, issue?: array{id: string, title: string, date: string, file: string, size: int, cover_id: int, created: int}}
     */
    public static function storeUploadedPdf(mixed $file, string $title, string $date, int $coverId): array
    {
        $title = sanitize_text_field($title);
        if ($title === '') {
            return [
                'success' => false,
                'message' => __('Enter a title for the issue.', 'traydigita-core'),
            ];
        }
        $validDate = self::validateDate($date);
        if ($validDate === null) {
            return [
                'success' => false,
                'message' => __('Enter a valid issue date.', 'traydigita-core'),
            ];
        }
        if (!self::isValidCoverId($coverId)) {
            return [
                'success' => false,
                'message' => __('The cover image is not a valid media library image.', 'traydigita-core'),
            ];
        }
        if (count(self::getIssues()) >= self::MAX_ISSUES) {
            return [
                'success' => false,
                'message' => sprintf(
                    /* translators: %d is the maximum number of stored E-Paper issues. */
                    __('The E-Paper library is full (%d issues). Delete an old issue before adding a new one.', 'traydigita-core'),
                    self::MAX_ISSUES
                ),
            ];
        }
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'message' => __('No PDF file was uploaded, or the upload failed.', 'traydigita-core'),
            ];
        }
        $tmpPath = $file['tmp_name'] ?? '';
        if (!is_string($tmpPath) || $tmpPath === '') {
            return [
                'success' => false,
                'message' => __('The uploaded file could not be read.', 'traydigita-core'),
            ];
        }
        $size = $file['size'] ?? 0;
        if (!is_int($size)) {
            $size = 0;
        }
        $maxBytes = self::maxBytes();
        if ($size <= 0 || $size > $maxBytes) {
            return [
                'success' => false,
                'message' => sprintf(
                    /* translators: %d is the maximum PDF upload size in megabytes. */
                    __('The PDF file is too large. Maximum size is %d MB.', 'traydigita-core'),
                    self::getSettings()['max_size_mb']
                ),
            ];
        }
        if (!is_uploaded_file($tmpPath)) {
            return [
                'success' => false,
                'message' => __('The uploaded file could not be read.', 'traydigita-core'),
            ];
        }
        $originalName = isset($file['name']) && is_string($file['name']) ? $file['name'] : 'issue.pdf';
        $verified = self::verifyPdf($tmpPath, $originalName);
        if (!$verified['success']) {
            return $verified;
        }
        if (!self::ensureDir()) {
            return [
                'success' => false,
                'message' => __('The E-Paper directory could not be created.', 'traydigita-core'),
            ];
        }
        $id = 'ep' . bin2hex(random_bytes(6));
        $cleanName = sanitize_file_name($originalName);
        if ($cleanName === '' || substr(strtolower($cleanName), -4) !== '.pdf') {
            $cleanName = 'issue.pdf';
        }
        $storedName = $id . '-' . $cleanName;
        $dest = self::dir() . '/' . $storedName;
        $suffix = 2;
        while (file_exists($dest)) {
            $storedName = $id . '-' . $suffix . '-' . $cleanName;
            $dest = self::dir() . '/' . $storedName;
            $suffix++;
        }
        if (!move_uploaded_file($tmpPath, $dest)) {
            return [
                'success' => false,
                'message' => __('The PDF file could not be saved.', 'traydigita-core'),
            ];
        }
        $issue = [
            'id' => $id,
            'title' => $title,
            'date' => $validDate,
            'file' => $storedName,
            'size' => $size,
            'cover_id' => $coverId,
            'created' => time(),
        ];
        $issues = self::getIssues();
        $issues[] = $issue;
        self::saveIssues($issues);
        return [
            'success' => true,
            'message' => __('Issue uploaded.', 'traydigita-core'),
            'issue' => $issue,
        ];
    }

    /**
     * Update one issue's metadata (title, date, cover).
     *
     * The PDF file itself is never replaced here; to change the
     * PDF, delete the issue and upload a new one.
     *
     * @param string $id Issue id.
     * @param string $title New title.
     * @param string $date New date (`Y-m-d`).
     * @param int $coverId New cover attachment id (0 for none).
     * @return array{success: bool, message: string, issue?: array{id: string, title: string, date: string, file: string, size: int, cover_id: int, created: int}}
     */
    public static function updateIssue(string $id, string $title, string $date, int $coverId): array
    {
        $issues = self::getIssues();
        $found = false;
        $updated = null;
        foreach ($issues as $index => $issue) {
            if ($issue['id'] !== $id) {
                continue;
            }
            $found = true;
            $title = sanitize_text_field($title);
            if ($title === '') {
                return [
                    'success' => false,
                    'message' => __('Enter a title for the issue.', 'traydigita-core'),
                ];
            }
            $validDate = self::validateDate($date);
            if ($validDate === null) {
                return [
                    'success' => false,
                    'message' => __('Enter a valid issue date.', 'traydigita-core'),
                ];
            }
            if (!self::isValidCoverId($coverId)) {
                return [
                    'success' => false,
                    'message' => __('The cover image is not a valid media library image.', 'traydigita-core'),
                ];
            }
            $issues[$index]['title'] = $title;
            $issues[$index]['date'] = $validDate;
            $issues[$index]['cover_id'] = $coverId;
            $updated = $issues[$index];
            break;
        }
        if (!$found || $updated === null) {
            return [
                'success' => false,
                'message' => __('Unknown issue.', 'traydigita-core'),
            ];
        }
        self::saveIssues($issues);
        return [
            'success' => true,
            'message' => __('Issue updated.', 'traydigita-core'),
            'issue' => $updated,
        ];
    }

    /**
     * Delete one issue: its PDF file plus its record.
     *
     * The cover reference is dropped with the record; the media
     * library attachment itself is kept, because it may be used
     * elsewhere on the site.
     *
     * @param string $id Issue id.
     * @return array{success: bool, message: string}
     */
    public static function deleteIssue(string $id): array
    {
        $issues = self::getIssues();
        $remaining = [];
        $deleted = null;
        foreach ($issues as $issue) {
            if ($issue['id'] === $id) {
                $deleted = $issue;
                continue;
            }
            $remaining[] = $issue;
        }
        if ($deleted === null) {
            return [
                'success' => false,
                'message' => __('Unknown issue.', 'traydigita-core'),
            ];
        }
        $path = self::dir() . '/' . $deleted['file'];
        if (file_exists($path)) {
            unlink($path);
        }
        self::saveIssues($remaining);
        return [
            'success' => true,
            'message' => __('Issue deleted.', 'traydigita-core'),
        ];
    }

    /**
     * Persist the issue list (already normalized by getIssues()).
     *
     * @param array<int, array{id: string, title: string, date: string, file: string, size: int, cover_id: int, created: int}> $issues Issues to store.
     * @return void
     */
    private static function saveIssues(array $issues): void
    {
        update_option(self::OPTION_ISSUES, array_values($issues), false);
    }
}
