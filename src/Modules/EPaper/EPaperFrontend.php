<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\EPaper;

use function __;
use function absint;
use function add_filter;
use function add_rewrite_rule;
use function add_shortcode;
use function array_slice;
use function ceil;
use function count;
use function date_i18n;
use function esc_attr;
use function esc_html;
use function esc_html__;
use function esc_url;
use function file_exists;
use function get_option;
use function get_query_var;
use function is_string;
use function max;
use function set_query_var;
use function shortcode_atts;
use function size_format;
use function status_header;
use function strtotime;

/**
 * Frontend rendering for the E-Paper module.
 *
 * Routes (registered on `init` from EPaperModule::boot(), only
 * while the module is enabled):
 * - `/epaper/` — the issue archive, newest first, paginated with
 *   `?epage=N`.
 * - `/epaper/<issue-id>/` — one issue: the cover (or the
 *   generated placeholder), a native browser PDF embed, a
 *   download link, and prev/next issue navigation.
 *
 * Both views are plain theme templates
 * (`templates/epaper/archive.php` and `templates/epaper/single.php`)
 * selected through the `template_include` filter; the view data is
 * passed with `set_query_var('traydigita_epaper_view', ...)`, so
 * the templates read it with `get_query_var()` instead of relying
 * on function-scope variables. An unknown issue id answers 404.
 * No custom post type is involved: the issue list comes from the
 * `traydigita_epaper_issues` option via EPaperStorage.
 *
 * The shortcode `[traydigita_epaper]` (attributes: `count` 1–5,
 * default 1; `show_cover` 1/0, default 1) renders the newest
 * issue(s) as cards, and the EPaperWidget renders the same cards
 * through renderIssueCard(), so the shortcode, the widget, and
 * the archive share exactly one card markup.
 *
 * Nothing here makes network calls: the PDF is embedded from the
 * site's own `wp-content/traydigita-epaper/` directory.
 */
final class EPaperFrontend
{
    /**
     * Query variable flagging the E-Paper archive request.
     */
    public const string ARCHIVE_QUERY_VAR = 'traydigita_epaper_archive';

    /**
     * Query variable carrying the requested issue id.
     */
    public const string ISSUE_QUERY_VAR = 'traydigita_epaper_issue';

    /**
     * Query variable carrying the view data array for the templates.
     */
    public const string VIEW_QUERY_VAR = 'traydigita_epaper_view';

    /**
     * Rewrite rule matching the archive URL.
     */
    public const string ARCHIVE_REGEX = '^epaper/?$';

    /**
     * Rewrite rule matching a single-issue URL.
     */
    public const string ISSUE_REGEX = '^epaper/([^/]+)/?$';

    /**
     * Register the rewrite rules and the query variables.
     *
     * Called on `init` from EPaperModule::boot(). The rules only
     * exist while the module is enabled; after enabling the module
     * the rules must be flushed once (saving the module settings
     * does this, as does visiting Settings → Permalinks).
     *
     * @return void
     */
    public static function registerRewrites(): void
    {
        add_filter('query_vars', [self::class, 'addQueryVars']);
        add_rewrite_rule(
            self::ARCHIVE_REGEX,
            'index.php?' . self::ARCHIVE_QUERY_VAR . '=1',
            'top'
        );
        add_rewrite_rule(
            self::ISSUE_REGEX,
            'index.php?' . self::ISSUE_QUERY_VAR . '=$matches[1]',
            'top'
        );
    }

    /**
     * Add the E-Paper query variables to the allowlist.
     *
     * @param array<int, string> $vars Registered public query variables.
     * @return array<int, string>
     */
    public static function addQueryVars(array $vars): array
    {
        $vars[] = self::ARCHIVE_QUERY_VAR;
        $vars[] = self::ISSUE_QUERY_VAR;
        $vars[] = self::VIEW_QUERY_VAR;
        return $vars;
    }

    /**
     * Select the E-Paper template for archive and single requests.
     *
     * Hooked on `template_include`. Non-E-Paper requests return
     * the template untouched. An archive request sets the view
     * data (paginated issue list) and returns
     * `templates/epaper/archive.php`; a single request with an
     * unknown issue id marks the query 404 and returns the
     * template untouched (WordPress renders the 404 template),
     * otherwise it sets the view data (issue + neighbours) and
     * returns `templates/epaper/single.php`.
     *
     * @param string $template Template path WordPress selected.
     * @return string
     */
    public static function filterTemplate(string $template): string
    {
        if (get_query_var(self::ARCHIVE_QUERY_VAR) !== '') {
            $file = \TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/templates/epaper/archive.php';
            if (!file_exists($file)) {
                return $template;
            }
            set_query_var(self::VIEW_QUERY_VAR, self::buildArchiveView());
            return $file;
        }
        $issueId = get_query_var(self::ISSUE_QUERY_VAR);
        if (!is_string($issueId) || $issueId === '') {
            return $template;
        }
        $issue = EPaperStorage::getIssue($issueId);
        if ($issue === null) {
            $GLOBALS['wp_query']->set_404();
            status_header(404);
            return $template;
        }
        $file = \TrayDigita\WP\Plugin\Core\Core::pluginDir() . '/templates/epaper/single.php';
        if (!file_exists($file)) {
            return $template;
        }
        set_query_var(self::VIEW_QUERY_VAR, self::buildSingleView($issue));
        return $file;
    }

    /**
     * Adjust the document title on E-Paper views.
     *
     * Hooked on `document_title_parts`: the archive gets "E-Paper",
     * a single issue gets its title. Other pages are untouched.
     *
     * @param array<string, string> $parts Document title parts.
     * @return array<string, string>
     */
    public static function filterDocumentTitle(array $parts): array
    {
        if (get_query_var(self::ARCHIVE_QUERY_VAR) !== '') {
            $parts['title'] = __('E-Paper', 'traydigita-core');
            return $parts;
        }
        $issueId = get_query_var(self::ISSUE_QUERY_VAR);
        if (is_string($issueId) && $issueId !== '') {
            $issue = EPaperStorage::getIssue($issueId);
            if ($issue !== null) {
                $parts['title'] = $issue['title'];
            }
        }
        return $parts;
    }

    /**
     * Build the archive view data (paginated issue list).
     *
     * The page comes from `?epage=N`, clamped to the available
     * range; out-of-range pages show the nearest valid page
     * instead of an empty list.
     *
     * @return array{issues: array<int, array{id: string, title: string, date: string, file: string, size: int, cover_id: int, created: int}>, page: int, total_pages: int, total: int, archive_url: string}
     */
    public static function buildArchiveView(): array
    {
        $issues = EPaperStorage::getIssues();
        $perPage = EPaperStorage::getSettings()['issues_per_page'];
        $total = count($issues);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = isset($_GET['epage']) ? absint($_GET['epage']) : 1;
        if ($page < 1) {
            $page = 1;
        }
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        return [
            'issues' => array_slice($issues, ($page - 1) * $perPage, $perPage),
            'page' => $page,
            'total_pages' => $totalPages,
            'total' => $total,
            'archive_url' => EPaperStorage::archiveUrl(),
        ];
    }

    /**
     * Build the single-issue view data.
     *
     * @param array{id: string, title: string, date: string, file: string, size: int, cover_id: int, created: int} $issue Issue record.
     * @return array{issue: array{id: string, title: string, date: string, file: string, size: int, cover_id: int, created: int}, prev: array{id: string, title: string, date: string, file: string, size: int, cover_id: int, created: int}|null, next: array{id: string, title: string, date: string, file: string, size: int, cover_id: int, created: int}|null, pdf_url: string, cover_url: string|null, archive_url: string}
     */
    public static function buildSingleView(array $issue): array
    {
        $neighbours = EPaperStorage::neighbours($issue['id']);
        return [
            'issue' => $issue,
            'prev' => $neighbours['prev'],
            'next' => $neighbours['next'],
            'pdf_url' => EPaperStorage::pdfUrl($issue),
            'cover_url' => EPaperStorage::coverUrl($issue, 'large'),
            'archive_url' => EPaperStorage::archiveUrl(),
        ];
    }

    /**
     * Register the `[traydigita_epaper]` shortcode.
     *
     * @return void
     */
    public static function registerShortcode(): void
    {
        add_shortcode('traydigita_epaper', [self::class, 'renderShortcode']);
    }

    /**
     * Render the `[traydigita_epaper]` shortcode.
     *
     * Attributes: `count` (1–5, default 1) and `show_cover`
     * (1/0, default 1). Shows the newest issue(s) as cards; with
     * no issues at all it renders a short "no issues yet" note
     * instead of an empty block.
     *
     * @param array<string, mixed> $atts Shortcode attributes.
     * @return string
     */
    public static function renderShortcode(array $atts): string
    {
        $parsed = shortcode_atts(
            [
                'count' => '1',
                'show_cover' => '1',
            ],
            $atts,
            'traydigita_epaper'
        );
        $count = absint($parsed['count'] ?? '1');
        if ($count < 1) {
            $count = 1;
        }
        if ($count > 5) {
            $count = 5;
        }
        $showCover = ($parsed['show_cover'] ?? '1') !== '0';
        $issues = EPaperStorage::latest($count);
        if ($issues === []) {
            return '<p class="epaper-empty">' . esc_html__('No E-Paper issues have been published yet.', 'traydigita-core') . '</p>';
        }
        $html = '<div class="epaper-shortcode">';
        foreach ($issues as $issue) {
            $html .= self::renderIssueCard($issue, $showCover);
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * Render one issue card (cover or placeholder, title, date,
     * read link). Shared by the archive, the shortcode, and the
     * widget, so the card looks the same everywhere.
     *
     * The cover is the admin-uploaded media-library image when
     * one exists; otherwise a generated placeholder with a
     * document icon is shown. The placeholder is honest about
     * what it is: the first page of the PDF is never rendered
     * as a cover.
     *
     * @param array{id: string, title: string, date: string, file: string, size: int, cover_id: int, created: int} $issue Issue record.
     * @param bool $showCover Whether to render the cover area at all.
     * @return string
     */
    public static function renderIssueCard(array $issue, bool $showCover = true): string
    {
        $url = EPaperStorage::issueUrl($issue);
        $coverUrl = $showCover ? EPaperStorage::coverUrl($issue, 'medium') : null;
        $dateLabel = date_i18n(get_option('date_format', 'F j, Y'), strtotime($issue['date']));
        $html = '<article class="epaper-card">';
        if ($showCover) {
            $html .= '<a class="epaper-cover" href="' . esc_url($url) . '" tabindex="-1" aria-hidden="true">';
            if ($coverUrl !== null) {
                $html .= '<img class="epaper-cover-img" src="' . esc_url($coverUrl) . '" alt="" loading="lazy">';
            } else {
                $html .= '<span class="epaper-cover-placeholder">';
                $html .= '<svg class="epaper-cover-placeholder-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2h9l5 5v15H6V2zm8 1.5V8h4.5L14 3.5zM8 12h8v1.5H8V12zm0 3.5h8V17H8v-1.5z"/></svg>';
                $html .= '<span class="epaper-cover-placeholder-label">' . esc_html__('PDF', 'traydigita-core') . '</span>';
                $html .= '</span>';
            }
            $html .= '</a>';
        }
        $html .= '<h3 class="epaper-card-title"><a class="epaper-card-link" href="' . esc_url($url) . '">' . esc_html($issue['title']) . '</a></h3>';
        $html .= '<p class="epaper-card-date"><time datetime="' . esc_attr($issue['date']) . '">' . esc_html($dateLabel) . '</time>';
        if ($issue['size'] > 0) {
            $html .= ' <span class="epaper-card-size">' . esc_html((string) size_format($issue['size'])) . '</span>';
        }
        $html .= '</p>';
        $html .= '<p class="epaper-card-more"><a class="epaper-card-link" href="' . esc_url($url) . '">' . esc_html__('Read issue', 'traydigita-core') . '</a></p>';
        $html .= '</article>';
        return $html;
    }
}
