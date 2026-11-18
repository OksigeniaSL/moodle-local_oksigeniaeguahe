<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_oksigeniaeguahe\local;

/**
 * Where a family arrived from, measured: the utm_source, utm_medium and utm_campaign of the link it came by and the
 * site it came from, next to what it says («¿Cómo nos conocieron?»), which is what it remembers. People mistake where
 * they heard of a centre: a family that came from an AI assistant may say Instagram.
 *
 * The first source of a visit to the centre's own web comes from pub/embed.js, which keeps it in that browser and
 * passes it to the links and the form of Eguahe (eg_src, eg_med, eg_cmp and eg_ref); without it, what the link to
 * Eguahe itself carries. Only names of sites and of campaigns are kept, never an address with its path.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class arrival {
    /** @var string[] The sites of AI assistants, by their domain: a link from one of them, or its utm_source. */
    const AI_SITES = ['chatgpt.com', 'chat.openai.com', 'openai.com', 'perplexity.ai', 'copilot.microsoft.com',
        'gemini.google.com', 'bard.google.com', 'claude.ai', 'meta.ai', 'chat.deepseek.com', 'deepseek.com', 'grok.com',
        'chat.mistral.ai', 'you.com', 'phind.com', 'poe.com', 'chat.qwen.ai', 'kimi.com', 'kimi.ai'];

    /** @var string[] What a utm_source of an AI assistant may say besides its domain. */
    const AI_NAMES = ['chatgpt', 'openai', 'perplexity', 'copilot', 'gemini', 'claude', 'deepseek', 'grok', 'mistral',
        'meta.ai', 'kimi', 'qwen'];

    /** @var array Known sites and sources by their own name, for the lists of Numbers: what a utm_source or a domain may
     *     say => how it is written. */
    const NAMES = ['instagram' => 'Instagram', 'ig' => 'Instagram', 'facebook' => 'Facebook', 'fb' => 'Facebook',
        'meta' => 'Meta', 'google' => 'Google', 'bing' => 'Bing', 'yahoo' => 'Yahoo', 'duckduckgo' => 'DuckDuckGo',
        'ecosia' => 'Ecosia', 'youtube' => 'YouTube', 'tiktok' => 'TikTok', 'whatsapp' => 'WhatsApp', 'wa.me' => 'WhatsApp',
        'telegram' => 'Telegram', 't.me' => 'Telegram', 'twitter' => 'X', 'x' => 'X', 'x.com' => 'X', 't.co' => 'X',
        'linkedin' => 'LinkedIn', 'lnkd.in' => 'LinkedIn', 'pinterest' => 'Pinterest', 'threads' => 'Threads'];

    /** @var array The fields of the form that carry it from the first page to the sending, with their lengths. */
    const FIELDS = ['src' => 100, 'med' => 30, 'cmp' => 255, 'ref' => 100];

    /**
     * Where this request came from. When the form is sent, what it brought from its first page; when it is opened,
     * what the web of the centre passed on (its first source), and if not, the link's utm and the site it came from.
     *
     * @return array src, med, cmp and ref; '' for what is not known.
     */
    public static function read(): array {
        // Sent: the hidden fields of the form, even empty (the page itself is its referrer then).
        if (optional_param('llegadasent', 0, PARAM_BOOL)) {
            $out = [];
            foreach (self::FIELDS as $key => $length) {
                $out[$key] = self::clean(optional_param('llegada' . $key, '', PARAM_RAW_TRIMMED), $length, $key);
            }
            return $out;
        }
        $first = self::clean(optional_param('eg_src', '', PARAM_RAW_TRIMMED), 100, 'src');
        $firstref = self::clean(optional_param('eg_ref', '', PARAM_RAW_TRIMMED), 100, 'ref');
        if ($first !== '' || $firstref !== '') {
            return ['src' => $first, 'med' => self::clean(optional_param('eg_med', '', PARAM_RAW_TRIMMED), 30, 'med'),
                'cmp' => self::clean(optional_param('eg_cmp', '', PARAM_RAW_TRIMMED), 255, 'cmp'), 'ref' => $firstref];
        }
        return [
            'src' => self::clean(optional_param('utm_source', '', PARAM_RAW_TRIMMED), 100, 'src'),
            'med' => self::clean(optional_param('utm_medium', '', PARAM_RAW_TRIMMED), 30, 'med'),
            'cmp' => self::clean(optional_param('utm_campaign', '', PARAM_RAW_TRIMMED), 255, 'cmp'),
            'ref' => self::referrer((string) ($_SERVER['HTTP_REFERER'] ?? '')),
        ];
    }

    /**
     * The site a referrer names, without www, its path or its query; nothing when it is this site.
     *
     * @param string $referrer
     * @return string
     */
    public static function referrer(string $referrer): string {
        global $CFG;
        $host = self::host($referrer);
        return $host !== '' && $host !== self::host((string) $CFG->wwwroot) ? $host : '';
    }

    /**
     * A site by its domain: lower case, without www, without its address.
     *
     * @param string $url An address or a domain.
     * @return string
     */
    public static function host(string $url): string {
        $url = trim(\core_text::strtolower($url));
        if ($url === '') {
            return '';
        }
        $host = parse_url(strpos($url, '//') === false ? 'http://' . $url : $url, PHP_URL_HOST);
        $host = preg_replace('/^www\./', '', (string) $host);
        return preg_match('/^[a-z0-9.-]{1,100}$/', $host) ? $host : '';
    }

    /**
     * One value, short and plain: a source or a site in lower case, a medium or a campaign as it came.
     *
     * @param string $value
     * @param int $length
     * @param string $key src, med, cmp or ref.
     * @return string
     */
    public static function clean(string $value, int $length, string $key): string {
        $value = trim(clean_param($value, PARAM_TEXT));
        if ($key === 'ref') {
            return self::host($value);
        }
        if ($key === 'src') {
            $value = \core_text::strtolower(preg_replace('/^(https?:\/\/)?(www\.)?/i', '', $value));
            $value = preg_replace('/[^a-z0-9._\-]/', '', $value);
        }
        return \core_text::substr($value, 0, $length);
    }

    /**
     * The fields a household keeps of where it arrived from, the first time.
     *
     * @param array|null $arrival From read().
     * @return array arrivalsource, arrivalmedium, arrivalcampaign and arrivalref, null for what is not known.
     */
    public static function fields(?array $arrival): array {
        $map = ['src' => 'arrivalsource', 'med' => 'arrivalmedium', 'cmp' => 'arrivalcampaign', 'ref' => 'arrivalref'];
        $out = [];
        foreach ($map as $key => $field) {
            $value = self::clean((string) ($arrival[$key] ?? ''), self::FIELDS[$key], $key);
            $out[$field] = $value !== '' ? $value : null;
        }
        return $out;
    }

    /**
     * Whether a source or a site is an AI assistant.
     *
     * @param string $value A utm_source or a domain.
     * @return bool
     */
    public static function is_ai(string $value): bool {
        $value = \core_text::strtolower(trim($value));
        if ($value === '') {
            return false;
        }
        foreach (self::AI_SITES as $site) {
            if ($value === $site || substr($value, -strlen($site) - 1) === '.' . $site) {
                return true;
            }
        }
        $plain = preg_replace('/\.(com|ai|net|org)$/', '', $value);
        return in_array($plain, self::AI_NAMES, true);
    }

    /**
     * Where a household arrived from, as the team reads it: its source, and if not, the site it came from.
     *
     * @param \stdClass $household
     * @return array [what, whether it is an AI assistant]; what is '' when nothing was measured.
     */
    public static function of(\stdClass $household): array {
        $source = trim((string) ($household->arrivalsource ?? ''));
        $site = trim((string) ($household->arrivalref ?? ''));
        $what = $source !== '' ? $source : $site;
        return [$what, $what !== '' && (self::is_ai($source) || self::is_ai($site))];
    }

    /**
     * A source or a site by its own name, for the lists of Numbers: AI assistants together, the known ones as they are
     * written («instagram.com», «l.facebook.com» or «ig» say Instagram and Facebook), anything else with its capital.
     *
     * @param string $what A utm_source or a domain.
     * @return string
     */
    public static function label(string $what): string {
        $what = trim($what);
        if ($what === '' || self::is_ai($what)) {
            return $what === '' ? '' : get_string('arrival_ai_group', 'local_oksigeniaeguahe');
        }
        $plain = \core_text::strtolower($what);
        // As it came; then the site without its subdomain of links (l., lm., m., www.); then its name without its
        // ending (google.es, google.co.uk), for names of three letters or more.
        $host = preg_replace('/^(l|lm|m|www|web|mobile)\./', '', $plain);
        $parts = explode('.', $host);
        $base = count($parts) > 1 ? $parts[count($parts) - 2] : $parts[0];
        if (count($parts) > 2 && in_array($base, ['co', 'com'], true)) {
            $base = $parts[count($parts) - 3];
        }
        foreach ([$plain, $host] as $key) {
            if (isset(self::NAMES[$key])) {
                return self::NAMES[$key];
            }
        }
        if (strlen($base) >= 3 && isset(self::NAMES[$base])) {
            return self::NAMES[$base];
        }
        return \core_text::strtoupper(\core_text::substr($what, 0, 1)) . \core_text::substr($what, 1);
    }

    /**
     * What a source or a site is, for a list of where families came from: AI assistants together.
     *
     * @param string $what
     * @return string
     */
    public static function group(string $what): string {
        return self::is_ai($what) ? get_string('arrival_ai_group', 'local_oksigeniaeguahe') : $what;
    }
}
