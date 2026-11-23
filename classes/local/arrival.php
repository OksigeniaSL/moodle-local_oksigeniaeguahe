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

    /** @var string The profile of the centre on Google (its business card in Search and Maps), apart from searching. */
    const GOOGLE_PROFILE = 'google-ficha';

    /** @var string[] The mediums that say a link of Google comes from the profile of the centre, not from a search. */
    const PROFILE_MEDIA = ['ficha', 'gbp', 'business', 'businessprofile', 'business-profile', 'mybusiness', 'perfil',
        'profile', 'maps', 'local'];

    /** @var string[] The channels a family arrives by, for Numbers, in their order: the work of the centre itself (its
     * profile on Google, the search engines, the AI assistants, its networks, its mails and messages, word of mouth, its
     * own web), what it pays for (advertising), print and QR, other webs, what never went through a web, and what was
     * not measured. */
    const CHANNELS = ['profile', 'search', 'ai', 'social', 'mail', 'wordofmouth', 'direct', 'campaign', 'print', 'web',
        'offline', 'unmeasured'];

    /** @var string[] The channels that are the work of the centre itself, not something it paid for. */
    const OWN = ['profile', 'search', 'ai', 'social', 'mail', 'wordofmouth', 'direct'];

    /** @var string[] The places of a form that are the door of an act (its poster at the door, its table): a door, never
     * where a family came from. */
    const DOORS = ['puerta', 'mesa', 'door', 'desk', 'porta', 'entrada'];

    /** @var array With nothing measured, what the family said of how it heard of the centre, as a channel. */
    const HEARD_CHANNELS = ['search' => 'search', 'ai' => 'ai', 'social' => 'social', 'centremessage' => 'mail',
        'family' => 'wordofmouth', 'centrefamilies' => 'wordofmouth', 'referral' => 'wordofmouth', 'ad' => 'campaign',
        'press' => 'offline', 'passingby' => 'offline', 'fair' => 'offline'];

    /** @var string The mediums of paid campaigns. */
    const PAID = '/^(cpc|ppc|cpm|paid|ads?|display|banner|paid[_-]?social|social[_-]?paid|campana|campaign|marketing)$/';

    /** @var string[] The mediums of what the centre sends itself: mails, newsletters, messages. */
    const SENT = ['email', 'e-mail', 'mail', 'newsletter', 'boletin', 'sms', 'mailing', 'whatsapp', 'telegram'];

    /** @var string[] The mediums of print: a QR, or an address written on paper. */
    const PRINT = ['qr', 'impreso', 'print', 'papel', 'cartel', 'folleto', 'flyer', 'poster'];

    /** @var string[] The mediums of a post on a network. */
    const SOCIAL = ['social', 'rrss', 'redes', 'organic-social', 'organic_social', 'social-organic'];

    /** @var string[] The search engines, by their name. */
    const SEARCH_NAMES = ['Google', 'Bing', 'Yahoo', 'DuckDuckGo', 'Ecosia'];

    /** @var string[] The networks, by their name. */
    const SOCIAL_NAMES = ['Instagram', 'Facebook', 'Meta', 'TikTok', 'YouTube', 'WhatsApp', 'Telegram', 'X', 'LinkedIn',
        'Pinterest', 'Threads'];

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
     * Whether an arrival is by a paid ad. An ad only counts as where a family came from when the family is new: a family
     * already known that leaves its contact in an ad later did not come by it, so it never fills its arrival.
     *
     * @param \stdClass|array $arrival The fields of a household (arrivalmedium) or of fields().
     * @return bool
     */
    public static function paid($arrival): bool {
        $arrival = (array) $arrival;
        return (bool) preg_match(self::PAID, \core_text::strtolower(trim((string) ($arrival['arrivalmedium'] ?? ''))));
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
        // The link of the profile of the centre on Google («Sitio web» of its card) is told apart from a search.
        $medium = \core_text::strtolower(trim((string) ($household->arrivalmedium ?? '')));
        if ($source !== '' && self::label($source) === 'Google' && in_array($medium, self::PROFILE_MEDIA, true)) {
            return [self::GOOGLE_PROFILE, false];
        }
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
        if ($what === self::GOOGLE_PROFILE) {
            return get_string('arrival_google_profile', 'local_oksigeniaeguahe');
        }
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
     * The channel a family arrived by (CHANNELS): what was measured the first time it arrived, never written over by a
     * later act or ad; with nothing measured, what it said of how it heard of the centre; then whether a person noted it
     * from the web of the centre. A call, an open day, a form or the poster at its door is a door, never where a family
     * came from: with nothing measured or said, it is «not measured».
     *
     * @param \stdClass $household
     * @param \stdClass|null $centre Its own web is told by the domain of its email.
     * @return string
     */
    public static function channel(\stdClass $household, ?\stdClass $centre = null): string {
        [$src, $med, $ref] = self::measured($household);
        if ($src === '' && $ref === '') {
            $heard = (string) ($household->heardfrom ?? '');
            if (isset(self::HEARD_CHANNELS[$heard])) {
                return self::HEARD_CHANNELS[$heard];
            }
            // The team noted that it came from the web of the centre.
            return (string) ($household->firstchannel ?? '') === 'web' ? 'direct' : 'unmeasured';
        }
        $name = self::label($src !== '' ? $src : $ref);
        if (self::of($household)[0] === self::GOOGLE_PROFILE) {
            return 'profile';
        }
        if (self::is_ai($src) || self::is_ai($ref)) {
            return 'ai';
        }
        if (preg_match(self::PAID, $med)) {
            return 'campaign';
        }
        if (in_array($med, self::SENT, true)) {
            return 'mail';
        }
        if (in_array($med, self::PRINT, true) || ($med === 'formulario' && preg_match('/qr|cartel|folleto|impreso/', $src))) {
            return 'print';
        }
        if (in_array($med, self::SOCIAL, true) || in_array($name, self::SOCIAL_NAMES, true)) {
            return 'social';
        }
        // The web of the centre itself (its domain, the one of its email), or the link of a place of one of its forms.
        if (self::own($src !== '' && strpos($src, '.') !== false ? $src : $ref, $centre) || $med === 'formulario') {
            return 'direct';
        }
        if (in_array($name, self::SEARCH_NAMES, true)) {
            return 'search';
        }
        return 'web';
    }

    /**
     * What the channel of a family rests on: measured when it arrived, only what the family said, noted by the team, or
     * nothing. So no channel is given more than was measured.
     *
     * @param \stdClass $household
     * @return string measured, said, noted or none.
     */
    public static function basis(\stdClass $household): string {
        [$src, , $ref] = self::measured($household);
        if ($src !== '' || $ref !== '') {
            return 'measured';
        }
        if (isset(self::HEARD_CHANNELS[(string) ($household->heardfrom ?? '')])) {
            return 'said';
        }
        return (string) ($household->firstchannel ?? '') === 'web' ? 'noted' : 'none';
    }

    /**
     * What was measured when a family arrived: source, medium and site, in lower case. The poster at the door of an act,
     * or its table, is left out: the family was already there, so it says nothing of where it came from.
     *
     * @param \stdClass $household
     * @return string[]
     */
    protected static function measured(\stdClass $household): array {
        $src = \core_text::strtolower(trim((string) ($household->arrivalsource ?? '')));
        $med = \core_text::strtolower(trim((string) ($household->arrivalmedium ?? '')));
        $ref = \core_text::strtolower(trim((string) ($household->arrivalref ?? '')));
        if ($src !== '' && $med === 'formulario' && in_array($src, self::DOORS, true) && $ref === '') {
            $src = '';
        }
        return [$src, $med, $ref];
    }

    /** @var int The families a channel needs behind it before it is said to enrol more or less than another. */
    const FAIR = 10;

    /**
     * The families of a list by channel: how many came by each, how far they went, how many of them only said it, and how
     * many left their contact in an ad later; the work of the centre itself against what it paid for, with what was not
     * measured apart; and, among the channels with enough families behind them, the one that enrols most and least. For
     * Numbers and the reports, with no channel given more than was measured.
     *
     * @param array[] $families Each with voices.channel (its name), step and, when known, who, basis and adlater.
     * @param array[] $centre The families already of the centre that came in the same time, the same way: only set
     *     against the others, never in their numbers.
     * @return array rows (key, name, group, n, visited, applied, enrolled, said, adlater, who: who::count() of the
     *     families and those of the centre), own, ownenrolled, paid, paidenrolled, unmeasured, other, total, adlater, who,
     *     and best and worst (name and frac) or false.
     */
    public static function by_channel(array $families, array $centre = []): array {
        $out = ['rows' => [], 'own' => 0, 'ownenrolled' => 0, 'paid' => 0, 'paidenrolled' => 0, 'unmeasured' => 0, 'other' => 0,
            'total' => count($families), 'adlater' => 0, 'best' => false, 'worst' => false,
            'who' => who::count(array_merge($families, $centre))];
        foreach (self::CHANNELS as $key) {
            $name = self::channel_name($key);
            $here = array_values(array_filter($families, fn($f) => ($f['voices']['channel'] ?? '') === $name));
            $theirs = array_values(array_filter($centre, fn($f) => ($f['voices']['channel'] ?? '') === $name));
            if (!$here && !$theirs) {
                continue;
            }
            $enrolled = count(array_filter($here, fn($f) => $f['step'] >= 5));
            $adlater = $key === 'campaign' ? 0 : count(array_filter($here, fn($f) => !empty($f['adlater'])));
            $out['rows'][] = ['key' => $key, 'name' => $name, 'group' => self::block($key), 'n' => count($here),
                'visited' => count(array_filter($here, fn($f) => $f['step'] >= 2)),
                'applied' => count(array_filter($here, fn($f) => $f['step'] >= 3)), 'enrolled' => $enrolled,
                'said' => count(array_filter($here, fn($f) => in_array($f['basis'] ?? '', ['said', 'noted'], true))),
                'adlater' => $adlater, 'who' => who::count(array_merge($here, $theirs))];
            $out['adlater'] += $adlater;
            if (in_array($key, self::OWN, true)) {
                $out['own'] += count($here);
                $out['ownenrolled'] += $enrolled;
            } else if ($key === 'campaign') {
                $out['paid'] += count($here);
                $out['paidenrolled'] += $enrolled;
            } else if ($key === 'unmeasured') {
                $out['unmeasured'] += count($here);
            } else {
                $out['other'] += count($here);
            }
        }
        // What works better and worse, whatever the channel: the share of its families that enrolled, only with enough of
        // them behind it, and never what was not measured.
        $fair = array_values(array_filter($out['rows'], fn($r) => $r['n'] >= self::FAIR && $r['key'] !== 'unmeasured'));
        $out['rows'] = array_values(array_filter($out['rows'], fn($r) => $r['n'] || array_sum(array_column($r['who'], 'n'))));
        if (count($fair) >= 2) {
            usort($fair, fn($a, $b) => $b['enrolled'] * $a['n'] <=> $a['enrolled'] * $b['n'] ?: $b['n'] <=> $a['n']);
            $frac = fn($r) => round(100 * $r['enrolled'] / $r['n']) . ' %';
            $best = $fair[0];
            $worst = $fair[count($fair) - 1];
            if ($best['enrolled'] * $worst['n'] !== $worst['enrolled'] * $best['n']) {
                $out['best'] = ['name' => $best['name'], 'frac' => $frac($best), 'n' => $best['n']];
                $out['worst'] = ['name' => $worst['name'], 'frac' => $frac($worst), 'n' => $worst['n']];
            }
        }
        return $out;
    }

    /**
     * The sentences that go with the channels: how many came by the centre's own channels and by advertising, which
     * enrols most and least, and how many of the other channels also left their contact in an ad.
     *
     * @param array $sum From by_channel().
     * @return string
     */
    public static function by_channel_text(array $sum): string {
        $c = 'local_oksigeniaeguahe';
        $out = [];
        $who = who::sentence($sum['who'], false);
        if ($who !== '') {
            $out[] = get_string('panel_bychannel_whoall', $c, $who);
        }
        $out[] = get_string('panel_bychannel_rest', $c, (object) array_intersect_key($sum, array_flip(['own', 'ownenrolled',
            'paid', 'paidenrolled', 'unmeasured', 'total'])));
        if ($sum['best']) {
            $out[] = get_string('panel_bychannel_best', $c, (object) ['fair' => self::FAIR, 'best' => $sum['best']['name'],
                'bestfrac' => $sum['best']['frac'], 'worst' => $sum['worst']['name'], 'worstfrac' => $sum['worst']['frac']]);
        }
        if ($sum['adlater']) {
            $out[] = get_string('panel_bychannel_adlater_all', $c, $sum['adlater']);
        }
        return implode(' ', $out);
    }

    /**
     * What goes with a channel, after how far its families went: who they are (the really new, those already of the
     * centre, of former students), how many of them only said it, and how many left their contact in an ad later.
     *
     * @param array $row A row of by_channel(); empty, nothing.
     * @return string
     */
    public static function row_note(array $row): string {
        if (!$row) {
            return '';
        }
        $c = 'local_oksigeniaeguahe';
        $parts = [];
        $who = who::sentence($row['who'], false);
        if ($who !== '') {
            $parts[] = $who;
        }
        if (!empty($row['said'])) {
            $parts[] = get_string('who_part_said', $c, $row['said']);
        }
        if (!empty($row['adlater'])) {
            $parts[] = get_string('who_part_adlater', $c, $row['adlater']);
        }
        if (!$parts) {
            return '';
        }
        $text = implode('; ', $parts) . '.';
        return \core_text::strtoupper(\core_text::substr($text, 0, 1)) . \core_text::substr($text, 1);
    }

    /**
     * The block of a channel in Numbers and the reports: the centre's own work, advertising, other ways, or not measured.
     *
     * @param string $channel One of CHANNELS.
     * @return string own, paid, other or unmeasured.
     */
    public static function block(string $channel): string {
        if (in_array($channel, self::OWN, true)) {
            return 'own';
        }
        return $channel === 'campaign' ? 'paid' : ($channel === 'unmeasured' ? 'unmeasured' : 'other');
    }

    /**
     * The name of a channel, as the team reads it.
     *
     * @param string $channel One of CHANNELS.
     * @return string
     */
    public static function channel_name(string $channel): string {
        return get_string(
            'arrival_channel_' . (in_array($channel, self::CHANNELS, true) ? $channel : 'web'),
            'local_oksigeniaeguahe'
        );
    }

    /**
     * Whether a site is the web of the centre: the domain of its email, or a part of it («nueva.example.org»).
     *
     * @param string $site
     * @param \stdClass|null $centre
     * @return bool
     */
    protected static function own(string $site, ?\stdClass $centre): bool {
        if ($site === '' || !$centre) {
            return false;
        }
        foreach ([(string) ($centre->email ?? ''), (string) ($centre->emailsettled ?? '')] as $email) {
            $domain = \core_text::strtolower(trim(substr((string) strrchr($email, '@'), 1)));
            if ($domain !== '' && ($site === $domain || substr($site, -strlen($domain) - 1) === '.' . $domain)) {
                return true;
            }
        }
        return false;
    }

    /**
     * A source or a site as the team reads it in a sentence: by its own name when it is known («google» says Google), an
     * unknown site as its address («nueva.example.org»), anything else with its capital.
     *
     * @param string $what A utm_source or a domain.
     * @return string
     */
    public static function shown(string $what): string {
        $name = self::label($what);
        $raw = \core_text::strtoupper(\core_text::substr($what, 0, 1)) . \core_text::substr($what, 1);
        return strpos($what, '.') !== false && $name === $raw ? $what : $name;
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
