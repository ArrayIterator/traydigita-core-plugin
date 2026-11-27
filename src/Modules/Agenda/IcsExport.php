<?php
declare(strict_types=1);

namespace TrayDigita\WP\Plugin\Core\Modules\Agenda;

use DateTimeImmutable;
use DateTimeZone;
use function get_bloginfo;
use function gmdate;
use function home_url;
use function implode;
use function is_string;
use function ord;
use function parse_url;
use function preg_replace;
use function sprintf;
use function str_replace;
use function strlen;
use function strtolower;
use function substr;
use function trim;

/**
 * iCalendar (.ics) export for a single agenda event.
 *
 * Hand-built, no library: one VEVENT inside a VCALENDAR 2.0
 * document, served by AgendaRenderer::maybeServeIcs(). Datetimes
 * are converted from the site timezone to UTC and suffixed with
 * `Z` (DTSTART / DTEND), as RFC 5545 requires for UTC times.
 *
 * Text values (SUMMARY, DESCRIPTION, LOCATION) are escaped per
 * RFC 5545 - backslash, semicolon, comma, and newlines - and
 * every content line is folded at 75 octets, so a long title or
 * description can never produce an invalid line.
 */
final class IcsExport
{
    /**
     * Maximum line length in octets before folding (RFC 5545 Sec. 3.1).
     */
    public const int MAX_LINE_OCTETS = 75;

    /**
     * Build the complete .ics document for one event.
     *
     * DTSTAMP is the generation time in UTC. The UID is stable
     * per event (`agenda-<id>@<site-host>`) so calendar apps can
     * de-duplicate re-downloads of the same event. An event
     * without an end datetime gets DTEND equal to DTSTART. The
     * optional link is exported as the URL property.
     *
     * @param array<string, mixed> $event Event row (see AgendaTable).
     * @return string Complete iCalendar document (CRLF line endings).
     */
    public static function build(array $event): string
    {
        $id = (int) ($event['id'] ?? 0);
        $start = AgendaTable::parseStoredDatetime((string) ($event['start_datetime'] ?? ''));
        if ($start === null) {
            $start = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }
        $endRaw = (string) ($event['end_datetime'] ?? '');
        $end = $endRaw !== '' ? AgendaTable::parseStoredDatetime($endRaw) : null;
        if ($end === null) {
            $end = $start;
        }
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//TrayDigita//Agenda//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:agenda-' . $id . '@' . self::siteHost(),
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . self::formatUtc($start),
            'DTEND:' . self::formatUtc($end),
            'SUMMARY:' . self::escapeText((string) ($event['title'] ?? '')),
        ];
        $description = trim((string) ($event['description'] ?? ''));
        if ($description !== '') {
            $lines[] = 'DESCRIPTION:' . self::escapeText($description);
        }
        $location = trim((string) ($event['location'] ?? ''));
        if ($location !== '') {
            $lines[] = 'LOCATION:' . self::escapeText($location);
        }
        $link = trim((string) ($event['link'] ?? ''));
        if ($link !== '') {
            $lines[] = 'URL:' . self::escapeText($link);
        }
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';
        $folded = [];
        foreach ($lines as $line) {
            $folded[] = self::foldLine($line);
        }
        return implode("\r\n", $folded) . "\r\n";
    }

    /**
     * Suggested download filename for one event.
     *
     * @param array<string, mixed> $event Event row.
     * @return string e.g. `agenda-12.ics`.
     */
    public static function filename(array $event): string
    {
        return sprintf('agenda-%d.ics', (int) ($event['id'] ?? 0));
    }

    /**
     * Escape a text value per RFC 5545 Sec. 3.3.11.
     *
     * Backslash first (so the escapes added below are not
     * re-escaped), then semicolon, comma, and newlines (CRLF and
     * LF both become the literal `\n` sequence).
     *
     * @param string $value Raw text.
     * @return string Escaped text safe for a single ICS content line.
     */
    public static function escapeText(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(';', '\\;', $value);
        $value = str_replace(',', '\\,', $value);
        $value = str_replace(["\r\n", "\n", "\r"], '\\n', $value);
        return $value;
    }

    /**
     * Fold one content line at 75 octets (RFC 5545 Sec. 3.1).
     *
     * Continuation lines start with a single space. Folding is
     * byte-based but never splits a multi-byte character: the cut
     * point is walked back while it lands inside a UTF-8
     * continuation byte. ASCII-only lines fold exactly at 75.
     *
     * @param string $line Unfolded content line.
     * @return string Folded line (CRLF + space continuations).
     */
    public static function foldLine(string $line): string
    {
        if (strlen($line) <= self::MAX_LINE_OCTETS) {
            return $line;
        }
        $out = '';
        $rest = $line;
        while (strlen($rest) > self::MAX_LINE_OCTETS) {
            $cut = self::MAX_LINE_OCTETS;
            while ($cut > 0 && (ord($rest[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            if ($cut === 0) {
                $cut = self::MAX_LINE_OCTETS;
            }
            $out .= substr($rest, 0, $cut) . "\r\n ";
            $rest = substr($rest, $cut);
        }
        $out .= $rest;
        return $out;
    }

    /**
     * Format a site-timezone datetime as a UTC `Z` timestamp.
     *
     * @param DateTimeImmutable $datetime Datetime in the site timezone.
     * @return string e.g. `20261201T030000Z`.
     */
    public static function formatUtc(DateTimeImmutable $datetime): string
    {
        return $datetime->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /**
     * Site host for the UID domain part (no user input involved).
     *
     * Falls back to the site name slugified by WordPress when the
     * home URL has no host (an unusual but possible setup).
     *
     * @return string
     */
    private static function siteHost(): string
    {
        $host = parse_url(home_url('/'), PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return $host;
        }
        $name = (string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) get_bloginfo('name')));
        $name = trim($name, '-');
        return $name !== '' ? $name : 'traydigita';
    }
}
