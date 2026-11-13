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
 * The panel of the team: pages of their own, with none of the look of Moodle. Moodle gives the session, the
 * permissions and the colour mode; the panel gives the bar with the sections, the page, and at the foot who makes
 * Eguahe. Its pieces: the time told in words, the rail of the places of a course, the leaf of a calendar and the
 * backpack of each child, hanging from its knob.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class panel {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var array Line icons of 24, drawn with the colour of the text: name => [paths, stroke width]. */
    const ICONS = [
        'bandeja' => ['<path d="M3.5 13.5h4.5l1.5 2.5h5l1.5-2.5h4.5"/><path d="M5.5 5h13l2 8.5V19H3.5v-5.5z"/>', 2],
        'actos' => ['<rect x="3.5" y="5" width="17" height="15" rx="3"/><path d="M8 3v4M16 3v4M3.5 10h17"/><path d="M8 14h3"/>', 2],
        'cifras' => ['<path d="M4 20V10M10 20V4M16 20v-7M21 20H3"/>', 2],
        'mas' => ['<circle cx="5.5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="18.5" cy="12" r="1.3"/>', 2.4],
        'entradas' => ['<path d="M4 6.5h16M4 12h10M4 17.5h7"/><path d="M17 15l3 2.5-3 2.5"/>', 2],
        'prevision' => ['<path d="M3.5 18l5-6 4 3 7.5-9"/><path d="M15.5 6h4.5v4.5"/>', 2],
        'ajustes' => ['<circle cx="12" cy="12" r="3"/>' .
            '<path d="M12 2.8v2.4M12 18.8v2.4M2.8 12h2.4M18.8 12h2.4M5.5 5.5l1.7 1.7M16.8 16.8l1.7 1.7' .
            'M5.5 18.5l1.7-1.7M16.8 7.2l1.7-1.7"/>', 2],
        'chev' => ['<path d="M7 10l5 5 5-5"/>', 2],
        'der' => ['<path d="M9 6l6 6-6 6"/>', 2],
        'atras' => ['<path d="M15 6l-6 6 6 6"/>', 2],
        'buscar' => ['<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/>', 2],
        'mas1' => ['<path d="M12 5v14M5 12h14"/>', 2.2],
        'check' => ['<path d="M5 12.5l4.5 4.5L19 7.5"/>', 2.6],
        'correo' => ['<rect x="3.5" y="5.5" width="17" height="13" rx="2.5"/><path d="M4 7l8 6 8-6"/>', 2],
        'whatsapp' => ['<path d="M4.5 19.5l1.2-3.6A8 8 0 1 1 8.4 18.6z"/>' .
            '<path d="M9.2 9.3c.3 1.9 1.6 3.6 3.6 4.5l1.2-1 1.7.8v1.4c-3.6.4-7.3-3.1-7-6.6h1.4z"/>', 1.7],
        'telefono' => ['' .
            '<path d="M6.5 3.5h3l1.5 4-2 1.5a11 11 0 0 0 6 6l1.5-2 4 1.5v3a2 2 0 0 1-2 2A16 16 0 0 1 4.5 5.5a2 2 0 0 1 2-2z"/>', 2],
        'formulario' => ['<rect x="5" y="3.5" width="14" height="17" rx="2.5"/><path d="M8.5 8.5h7M8.5 12h7M8.5 15.5h4"/>', 2],
        'persona' => ['<circle cx="12" cy="8" r="3.6"/><path d="M5 20c.8-3.6 3.6-5.5 7-5.5s6.2 1.9 7 5.5"/>', 2],
        'nota' => ['<path d="M5 4.5h14v10l-5 5H5z"/><path d="M14 19.5v-5h5"/>', 2],
        'enviado' => ['<path d="M20.5 3.5L10 14M20.5 3.5l-6.5 17-4-6.5-6.5-4z"/>', 2],
        'enlace' => ['<path d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1 1"/>' .
            '<path d="M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1-1"/>', 2],
        'fuera' => ['<path d="M14 4h6v6M20 4l-9 9"/>' .
            '<path d="M18 14v4.5a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 4 18.5v-11A1.5 1.5 0 0 1 5.5 6H10"/>', 2],
        'copiar' => ['<rect x="8.5" y="8.5" width="11" height="11" rx="2.5"/>' .
            '<path d="M15.5 8.5V6a1.5 1.5 0 0 0-1.5-1.5H6A1.5 1.5 0 0 0 4.5 6v8A1.5 1.5 0 0 0 6 15.5h2.5"/>', 2],
        'imprimir' => ['<path d="M7 9V3.5h10V9"/><rect x="3.5" y="9" width="17" height="8" rx="2"/><path d="M7 14h10v6.5H7z"/>', 2],
        'arriba' => ['<path d="M12 19V5M6 11l6-6 6 6"/>', 2],
        'abajo' => ['<path d="M12 5v14M6 13l6 6 6-6"/>', 2],
        'borrar' => ['<path d="M4.5 7h15M9.5 7V4.5h5V7M6.5 7l1 13h9l1-13"/>', 2],
        'lapiz' => ['<path d="M15.5 4.5l4 4L8 20H4v-4z"/>', 2],
        'agarre' => ['<circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/>' .
            '<circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/>', 2],
        'rama' => ['<path d="M6 4v8a4 4 0 0 0 4 4h8"/><path d="M15 13l3 3-3 3"/>', 2],
        'info' => ['<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5.5M12 7.8v.01"/>', 2.2],
        'qr' => ['<rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/>' .
            '<rect x="4" y="14" width="6" height="6" rx="1"/><path d="M14 14h2v2M18 14h2M14 18h2v2h4v-2"/>', 2],
        'candado' => ['<rect x="5" y="10.5" width="14" height="10" rx="2.5"/><path d="M8.5 10.5V8a3.5 3.5 0 0 1 7 0v2.5"/>', 2],
        'ojo' => ['<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/>' .
            '<circle cx="12" cy="12" r="3"/>', 2],
        'plano' => ['<path d="M12 21s-6.5-5.6-6.5-11a6.5 6.5 0 0 1 13 0c0 5.4-6.5 11-6.5 11z"/>' .
            '<circle cx="12" cy="10" r="2.3"/>', 2],
        'movil' => ['<rect x="6.5" y="2.8" width="11" height="18.4" rx="2.6"/><path d="M10.5 18h3"/>', 2],
        'sistema' => ['<path d="M12 3.5l2 2.8 3.4-.5.5 3.4 2.8 2-2.8 2-.5 3.4-3.4-.5-2 2.8-2-2.8-3.4.5-.5-3.4-2.8-2 2.8-2 ' .
            '.5-3.4 3.4.5z"/>', 1.6],
        'fichero' => ['<rect x="3.5" y="7" width="17" height="12.5" rx="2.5"/><path d="M7 7V4.5h10V7M3.5 12h17"/>', 2],
        'filas' => ['<path d="M4 6h16M4 12h16M4 18h16"/>', 2],
        'altavoz' => ['<path d="M4.5 9.5h3l4.5-4v13l-4.5-4h-3z"/><path d="M16 9a4 4 0 0 1 0 6M18.5 6.5a7.5 7.5 0 0 1 0 11"/>', 2],
        'teclado' => ['<rect x="2.5" y="6" width="19" height="12" rx="2.5"/>' .
            '<path d="M6 10h.01M10 10h.01M14 10h.01M18 10h.01M7 14h10"/>', 2],
        'cerrar' => ['<path d="M6 6l12 12M18 6L6 18"/>', 2.2],
        'etiqueta' => ['<path d="M3.5 6.5a2 2 0 0 1 2-2h13a2 2 0 0 1 2 2V14l-6 6h-9a2 2 0 0 1-2-2z"/><path d="M14.5 20v-6h6"/>', 2],
        'mano' => ['<path d="M8 12V5.5a1.5 1.5 0 0 1 3 0V11M11 10.5V4a1.5 1.5 0 0 1 3 0v6.5M14 10.5V5.5a1.5 1.5 0 0 1 3 0V13' .
            'c0 4-2.5 7-6.5 7-2.6 0-4.4-1.4-5.6-3.6L3.5 13.2a1.5 1.5 0 0 1 2.6-1.5L8 14"/>', 1.8],
        'fundir' => ['<path d="M6 4v5a5 5 0 0 0 5 5h1M18 4v5a5 5 0 0 1-5 5"/><path d="M12 14v6M9 17.5l3 3 3-3"/>', 2],
        'subir' => ['<path d="M12 15.5V4M7 8.5l5-4.5 5 4.5"/>' .
            '<path d="M4.5 14.5v3A2.5 2.5 0 0 0 7 20h10a2.5 2.5 0 0 0 2.5-2.5v-3"/>', 2],
        'clave' => ['<circle cx="8" cy="12" r="3.8"/><path d="M11.8 12H20.5M17.5 12v3M20.5 12v2.2"/>', 2],
        'ensenar' => ['<rect x="3.5" y="4" width="17" height="11.5" rx="2"/><path d="M12 15.5V20M8.5 20h7"/>', 2],
        'probar' => ['<path d="M8 5.5v13l10.5-6.5z"/>', 2],
        'codigo' => ['<path d="M8.5 7.5L4 12l4.5 4.5M15.5 7.5L20 12l-4.5 4.5M13.5 5l-3 14"/>', 2],
        'puente' => ['<path d="M3 16.5h18M5 16.5V12M19 16.5V12M3 12c3.5-5 14.5-5 18 0"/><path d="M9 16.5v-5.5M15 16.5v-5.5"/>', 2],
        'hoja' => ['<rect x="4" y="4" width="16" height="16" rx="2.5"/><path d="M4 9.5h16M4 14.5h16M10 4v16"/>', 2],
        'globo' => ['<circle cx="12" cy="12" r="8.5"/>' .
            '<path d="M3.5 12h17M12 3.5c2.6 2.4 3.6 5.2 3.6 8.5s-1 6.1-3.6 8.5c-2.6-2.4-3.6-5.2-3.6-8.5s1-6.1 3.6-8.5z"/>', 1.8],
        'reloj' => ['<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>', 2],
        'papelera' => ['<path d="M4.5 7h15M9.5 7V4.5h5V7M6.5 7l1 13h9l1-13"/>', 2],
        'duplicar' => ['<rect x="8.5" y="8.5" width="11" height="11" rx="2.5"/>' .
            '<path d="M15.5 8.5V6a1.5 1.5 0 0 0-1.5-1.5H6A1.5 1.5 0 0 0 4.5 6v8A1.5 1.5 0 0 0 6 15.5h2.5"/>' .
            '<path d="M14 11.5v5M11.5 14h5"/>', 2],
        'senalar' => ['<path d="M9 11V4.5a1.5 1.5 0 0 1 3 0V11l5.2 1.2a2 2 0 0 1 1.5 2.3' .
            'l-1 4.5H9.5L6 14.6a1.6 1.6 0 0 1 2.4-2.1L9 13"/>', 1.8],
        'ojo2' => ['<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/>' .
            '<circle cx="12" cy="12" r="3"/><path d="M4 20L20 4"/>', 2],
        'salir' => ['<path d="M10 4.5H6A1.5 1.5 0 0 0 4.5 6v12A1.5 1.5 0 0 0 6 19.5h4"/><path d="M15 8l4 4-4 4M19 12H9.5"/>', 2],
        'ia' => ['<path d="M12 3v3M12 18v3M3 12h3M18 12h3"/><rect x="7" y="7" width="10" height="10" rx="3"/>', 2],
    ];

    /** @var string[] The skins of the panel (decision of Enrique, 8 November 2026): the same panel, another scene. */
    const SKINS = ['vestibulo', 'mostrador', 'cinematico'];

    /** @var array A skin drawn over another one (Gaudí, 8 November 2026): the living hall is the paper desk in the hall. */
    const UNDER = ['vestibulo' => 'mostrador', 'cinematico' => 'mostrador'];

    /** @var string What hangs for each child by the age of its course, as data: the steps of the ladder. */
    const LADDER = '/local/oksigeniaeguahe/data/escalera.json';

    /** @var array|null The ladder, read once. */
    protected static $ladder = null;

    /** @var array The sections of the bar, in order: key => [string, icon, page]. */
    const SECTIONS = [
        'inbox' => ['tab_inbox', 'bandeja', '/local/oksigeniaeguahe/index.php'],
        'events' => ['tab_events', 'actos', '/local/oksigeniaeguahe/events.php'],
        'entries' => ['tab_entries', 'entradas', '/local/oksigeniaeguahe/entries.php'],
        'metrics' => ['tab_metrics', 'cifras', '/local/oksigeniaeguahe/metrics.php'],
        'forecast' => ['tab_forecast', 'prevision', '/local/oksigeniaeguahe/forecast.php'],
        'settings' => ['tab_settings', 'ajustes', '/local/oksigeniaeguahe/centre.php'],
    ];

    /**
     * The icons, as the templates take them.
     *
     * @return array Name => SVG.
     */
    public static function icons(): array {
        $out = [];
        foreach (array_keys(self::ICONS) as $name) {
            $out[$name] = self::icon($name);
        }
        return $out;
    }

    /**
     * One icon.
     *
     * @param string $name
     * @param string $class Another class for it.
     * @return string SVG.
     */
    public static function icon(string $name, string $class = ''): string {
        [$paths, $width] = self::ICONS[$name] ?? self::ICONS['formulario'];
        return '<svg class="ic' . ($class !== '' ? ' ' . $class : '') . '" viewBox="0 0 24 24" fill="none" ' .
            'stroke="currentColor" stroke-width="' . $width .
            '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
    }

    /**
     * The icon of a channel.
     *
     * @param string $channel
     * @return string SVG.
     */
    public static function channel_icon(string $channel): string {
        $icons = ['whatsapp' => 'whatsapp', 'email' => 'correo', 'phone' => 'telefono', 'telegram' => 'enviado',
            'openday' => 'actos', 'visit' => 'actos', 'event' => 'actos', 'walkin' => 'persona', 'meta' => 'persona'];
        return self::icon($icons[$channel] ?? 'formulario');
    }

    /**
     * Sets up a page of the panel: Moodle knows where it is, and nothing of its look is printed.
     *
     * @param \moodle_url $url
     * @param string $title
     */
    public static function setup(\moodle_url $url, string $title): void {
        global $PAGE;
        $PAGE->set_context(\context_system::instance());
        $PAGE->set_url($url);
        $PAGE->set_pagelayout('embedded');
        $PAGE->set_title($title);
        $PAGE->set_cacheable(false);
    }

    /**
     * The skin a centre's offer suggests: the living hall for the little ones (nursery and the first courses of
     * primary), the paper desk for whole schools, and the cinematic one for older students (secondary on, academies,
     * universities). A centre without an offer yet gets the paper desk.
     *
     * @param int $centreid
     * @return string One of SKINS.
     */
    public static function proposed_skin(int $centreid): string {
        $offers = $centreid ? centres::offers($centreid, true) : [];
        if (!$offers) {
            return 'mostrador';
        }
        $centre = centres::get($centreid);
        $ages = array_filter(array_map(fn($o) => grades::age((string) $o->name, $centre), $offers), fn($a) => $a !== null);
        if (!$ages) {
            // Courses that are not school years (languages, degrees, trades): older students.
            return 'cinematico';
        }
        if (max($ages) <= 8) {
            return 'vestibulo';
        }
        return min($ages) >= 12 ? 'cinematico' : 'mostrador';
    }

    /**
     * The skin of the panel of a centre: the one it chose, else the one its offer suggests.
     *
     * @param \stdClass $centre
     * @return string One of SKINS.
     */
    public static function skin(\stdClass $centre): string {
        $chosen = (string) ($centre->skin ?? '');
        return in_array($chosen, self::SKINS, true) ? $chosen : self::proposed_skin((int) $centre->id);
    }

    /**
     * The colour mode the person chose in Moodle (Boost, from Moodle 5.3), for the html of the page: «light» or «dark»;
     * empty for «automatic», or before 5.3, when the page follows the device.
     *
     * @return string
     */
    public static function mode(): string {
        $class = '\\theme_boost\\colour_mode';
        if (!class_exists($class) || !$class::is_boost_theme() || !$class::is_enabled()) {
            return '';
        }
        $mode = (string) $class::get_current_mode();
        return in_array($mode, ['light', 'dark'], true) ? $mode : '';
    }

    /**
     * The sections of the bar for a centre, with the one of now and how many families wait.
     *
     * @param \stdClass $centre
     * @param string $current
     * @return array
     */
    public static function sections(\stdClass $centre, string $current): array {
        $waiting = households::waiting_count((int) $centre->id);
        $manage = has_capability('local/oksigeniaeguahe:manage', \context_system::instance());
        $out = [];
        foreach (self::SECTIONS as $key => [$string, $icon, $page]) {
            if ($key === 'settings' && !$manage) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'name' => get_string($string, self::C),
                'url' => (new \moodle_url($page, $key === 'settings' ? ['id' => $centre->id] : ['c' => $centre->id]))->out(false),
                'short' => get_string($key === 'events' ? 'panel_events_short' : $string, self::C),
                'icon' => self::icon($icon),
                'current' => $key === $current,
                'count' => $key === 'inbox' && $waiting ? $waiting : 0,
            ];
        }
        return $out;
    }

    /**
     * A person of the team as the panel shows them: the first name, and the picture of Moodle or the initials.
     *
     * @param \stdClass $user
     * @return array name, first, picture (URL or ''), initials
     */
    public static function person(\stdClass $user): array {
        global $PAGE;
        $first = trim((string) ($user->firstname ?? ''));
        $picture = '';
        if (!empty($user->picture)) {
            $pic = new \user_picture($user);
            $pic->size = 72;
            $picture = $pic->get_url($PAGE)->out(false);
        }
        return [
            'name' => fullname($user),
            'first' => $first,
            'picture' => $picture,
            'initials' => \core_text::strtoupper(\core_text::substr($first, 0, 1) .
                \core_text::substr(trim((string) ($user->lastname ?? '')), 0, 1)),
        ];
    }

    /**
     * Prints the whole page and stops: the bar of the panel, the page, the bar of the phone and the foot. The colours
     * of the centre become variables here, for light and dark, as on the pages of the families.
     *
     * @param \stdClass $centre
     * @param string $section The section the page belongs to.
     * @param string $title
     * @param string $template Of the plugin, without its component.
     * @param array $context
     * @param bool $door Whether it is the door of an event: a way of working, without the bar of the phone.
     */
    public static function output(
        \stdClass $centre,
        string $section,
        string $title,
        string $template,
        array $context,
        bool $door = false
    ): void {
        global $CFG, $OUTPUT, $USER;
        $base = $CFG->wwwroot . '/local/oksigeniaeguahe/pub/';
        $version = (int) get_config('local_oksigeniaeguahe', 'version');
        $colour = $centre->colour ?: '#2f5d62';
        $name = format_string($centre->name);
        $lang = current_language();
        $sections = self::sections($centre, $section);
        $more = array_values(array_filter($sections, fn($s) => in_array($s['key'], ['entries', 'forecast', 'settings'], true)));
        $phone = array_values(array_filter($sections, fn($s) => in_array($s['key'], ['inbox', 'events', 'metrics'], true)));
        $centres = [];
        foreach (centres::for_user((int) $USER->id) as $each) {
            if ((int) $each->id !== (int) $centre->id) {
                $centres[] = ['name' => format_string($each->name), 'url' => (new \moodle_url(
                    '/local/oksigeniaeguahe/index.php',
                    ['c' => $each->id]
                ))->out(false)];
            }
        }
        $logo = centres::logo((int) $centre->id);
        $credit = publicpage::credit($lang);
        $notices = [];
        foreach (\core\notification::fetch() as $notice) {
            $notices[] = ['text' => $notice->get_message(), 'error' => $notice->get_message_type() === 'error'];
        }
        // The skin: a template of its own for the page if it has one, and its styles and scene if they exist; a skin
        // drawn over another one carries the one under it first.
        $skin = self::skin($centre);
        $layers = isset(self::UNDER[$skin]) ? [self::UNDER[$skin], $skin] : [$skin];
        foreach (array_reverse($layers) as $layer) {
            $own = $template . '_' . $layer;
            if (file_exists(dirname(__DIR__, 2) . '/templates/' . $own . '.mustache')) {
                $template = $own;
                break;
            }
        }
        $skincss = '';
        $skinjs = '';
        foreach ($layers as $layer) {
            $skindir = dirname(__DIR__, 2) . '/pub/pieles/' . $layer . '/';
            $skincss .= file_exists($skindir . 'piel.css') ? '<link rel="stylesheet" href="' . $base . 'pieles/' . $layer .
                '/piel.css?v=' . $version . '">' . "\n" : '';
            $skinjs .= file_exists($skindir . 'escena.js') ? '<script src="' . $base . 'pieles/' . $layer . '/escena.js?v=' .
                $version . '" defer></script>' . "\n" : '';
        }
        // The page by its template, on the html and the body: what printing needs (the report, the sheets of labels)
        // without :has(), which the browsers of the classrooms do not have.
        $page = 'pl-' . str_replace('_', '-', $template) . (!empty($context['letter']) ? ' pl-carta' : '');
        $body = $OUTPUT->render_from_template('local_oksigeniaeguahe/' . $template, $context + ['i' => self::icons(),
            'piel' => array_fill_keys($layers, true), 'pielnombre' => $skin, 'h24' => self::h24($centre)]);
        $frame = $OUTPUT->render_from_template('local_oksigeniaeguahe/panel_frame', [
            'i' => self::icons(),
            'homeurl' => (new \moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centre->id]))->out(false),
            'centre' => $name,
            'initials' => \core_text::strtoupper(\core_text::substr($name, 0, 1)),
            'logo' => $logo ? $logo->out(false) : '',
            'centres' => $centres,
            'hascentres' => (bool) $centres,
            'sections' => $sections,
            'phone' => $phone,
            'more' => $more,
            'morecurrent' => in_array($section, ['entries', 'forecast', 'settings'], true),
            'door' => $door,
            'me' => self::person($USER),
            'moodleurl' => $CFG->wwwroot . '/',
            'logouturl' => (new \moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(false),
            'notices' => $notices,
            'hasnotices' => (bool) $notices,
            'footnote' => '',
            'sesskey' => sesskey(),
            'awayurl' => (new \moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centre->id, 'away' => 1]))->out(false),
            'untildate' => self::date_input('until', '', $centre, 'fuera-hasta'),
            'away' => ($until = attend::away_until((int) $centre->id, (int) $USER->id)) ? get_string(
                'panel_you_away',
                self::C,
                userdate($until, get_string('strftimedateshort', 'langconfig'))
            ) : '',
            'jsstrings' => json_encode(self::js_strings(), JSON_UNESCAPED_UNICODE),
            'credit' => $credit ? get_string('panel_credit_after', self::C, \html_writer::link(
                $credit['url'],
                'Oksigenia',
                ['target' => '_blank', 'rel' => 'noopener']
            )) : '',
        ]);
        [$top, $bottom] = explode('<!-- main -->', $frame, 2) + [1 => ''];
        $vars = fn(array $list) => implode(' ', array_map(fn($k, $v) => "$k: $v;", array_keys($list), $list));
        $mode = self::mode();
        self::headers();
        echo '<!DOCTYPE html>
<html class="' . $page . '" lang="' . s(str_replace('_', '-', $lang === 'pt_br' ? 'pt-BR' : $lang)) . '"' .
            ($mode !== '' ? ' data-bs-theme="' . $mode . '"' : '') . '>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="same-origin">
<meta name="color-scheme" content="light dark">
<title>' . s($title) . ' · ' . s($name) . '</title>
<link rel="preload" href="' . $base . 'fonts/schibsted-grotesk.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="' . $base . 'panel.css?v=' . $version . '">
' . $skincss . '<style>:root { ' . $vars(publicpage::centre_vars($colour, $skin === 'cinematico')) . ' }
@media (prefers-color-scheme: dark) { :root:not([data-bs-theme]) { ' . $vars(publicpage::centre_vars($colour, true)) . ' } }
:root[data-bs-theme="dark"] { ' . $vars(publicpage::centre_vars($colour, true)) . ' }</style>
<script src="' . $base . 'panel.js?v=' . $version . '" defer></script>
<script src="' . $base . 'panel-latido.js?v=' . $version . '" defer></script>
<script src="' . $base . 'dibujo.js?v=' . $version . '" defer></script>
' . $skinjs . '</head>
<body class="panel ' . $page . ' ' . implode(' ', array_map(fn($l) => 'piel-' . $l, $layers)) . ($door ? '' : ' con-abajo') .
            '">
' . $top . $body . $bottom . '
</body>
</html>';
        die();
    }

    /**
     * The strings the script of the panel says, with their placeholders as they are.
     *
     * @return array Key => text.
     */
    public static function js_strings(): array {
        $out = [];
        $all = get_string_manager()->load_component_strings(self::C, current_language());
        foreach (array_keys($all) as $key) {
            if (strpos($key, 'panel_js_') === 0) {
                $out[$key] = get_string($key, self::C);
            }
        }
        foreach (['panel_came_at', 'panel_came_button', 'panel_came_count', 'inbox_waiting', 'inbox_waiting_one'] as $key) {
            $out[$key] = get_string($key, self::C);
        }
        return $out;
    }

    /**
     * The headers of every page of the panel: nothing from elsewhere, never inside another page.
     */
    public static function headers(): void {
        @header('Content-Type: text/html; charset=utf-8');
        @header('X-Content-Type-Options: nosniff');
        @header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; " .
            "script-src 'self'; connect-src 'self'; frame-src 'self'; frame-ancestors 'self'; form-action 'self'; " .
            "base-uri 'none'; object-src 'none'");
        @header('Cache-Control: no-store');
    }

    /**
     * The head of a page with all its parts, the ones it does not have empty: a template must not take them from the
     * page around it. Empty is false, never null: an older Mustache (Moodle 4.5) looks a null up in the page around it,
     * and would draw its «action» as a button.
     *
     * @param array $head title and, if any, back, ante, sub, what («What is this?») and action.
     * @return array
     */
    public static function head(array $head): array {
        $head = array_map(fn($v) => $v ?? false, $head);
        return $head + ['back' => false, 'ante' => '', 'title' => '', 'sub' => '', 'what' => false, 'action' => false];
    }

    /**
     * How long someone has waited, in words: what goes first (bold), what goes below, and how the head of the inbox
     * says it («La que más lleva espera desde el jueves a las 18:40»).
     *
     * @param int $since
     * @param int $now
     * @return array main, sub, since
     */
    public static function wait_words(int $since, int $now = 0): array {
        $now = $now ?: time();
        $time = self::hour($since);
        $days = self::days_between($since, $now);
        if ($days === 0) {
            $minutes = max(1, intdiv($now - $since, MINSECS));
            if ($minutes < 60) {
                return [
                    'main' => get_string($minutes === 1 ? 'panel_wait_minute' : 'panel_wait_minutes', self::C, $minutes),
                    'sub' => get_string('panel_today', self::C, $time),
                    'since' => get_string($minutes === 1 ? 'panel_since_minute' : 'panel_since_minutes', self::C, $minutes),
                ];
            }
            $hours = intdiv($minutes, 60);
            return [
                'main' => get_string($hours === 1 ? 'panel_wait_hour' : 'panel_wait_hours', self::C, $hours),
                'sub' => get_string('panel_today', self::C, $time),
                'since' => get_string('panel_since_today', self::C, $time),
            ];
        }
        if ($days === 1) {
            return [
                'main' => get_string('panel_wait_yesterday', self::C),
                'sub' => get_string('panel_yesterday', self::C, $time),
                'since' => get_string('panel_since_yesterday', self::C, $time),
            ];
        }
        $day = $days < 7 ? userdate($since, '%A') : userdate($since, get_string('strftimedateshort', 'langconfig'));
        return [
            'main' => get_string('panel_wait_day', self::C, $day),
            'sub' => get_string('panel_ago_days', self::C, $days),
            'since' => get_string('panel_since_day', self::C, (object) ['day' => $day, 'time' => $time]),
        ];
    }

    /**
     * When something happened, in words: «hoy a las 8:15», «ayer a las 19:05», «el viernes a las 9:02», «el 28 de
     * octubre a las 9:02».
     *
     * @param int $time
     * @param int $now
     * @return string
     */
    public static function when_words(int $time, int $now = 0): string {
        $now = $now ?: time();
        $hour = self::hour($time);
        $days = self::days_between($time, $now);
        if ($days === 0) {
            return get_string('panel_when_today', self::C, $hour);
        }
        if ($days === 1) {
            return get_string('panel_when_yesterday', self::C, $hour);
        }
        $day = $days > 0 && $days < 7 ? userdate($time, '%A') : userdate($time, get_string('strftimedateshort', 'langconfig'));
        return get_string('panel_when_day', self::C, (object) ['day' => $day, 'time' => $hour]);
    }

    /**
     * The hour of a moment, as the language of the person writes it, without a leading zero.
     *
     * @param int $time
     * @return string
     */
    public static function hour(int $time): string {
        return ltrim(userdate($time, get_string('strftimetime', 'langconfig')), '0 ');
    }

    /**
     * A date of the team in three fields, in the order of the language and country of the centre, never of the browser
     * (Enrique, 12-11: «dependerá del idioma del centro»): a field of type date writes mm/dd/yyyy in an English one.
     *
     * @param string $name
     * @param string $value YYYY-MM-DD, or empty.
     * @param \stdClass $centre
     * @param string $id The id of its fields when several dates share a name.
     * @return array For the partial public_date.
     */
    public static function date_input(string $name, string $value, \stdClass $centre, string $id = ''): array {
        return publicpage::date_input($name, $value, (string) ($centre->lang ?: 'es'), $centre, $id);
    }

    /**
     * Whether the clock of the centre goes to 24 hours, by the international data of its language and country: then the
     * hours of the panel are written as hh:mm, since a field of type time writes AM and PM in an English browser.
     *
     * @param \stdClass $centre
     * @return bool
     */
    public static function h24(\stdClass $centre): bool {
        if (!class_exists('\IntlDateFormatter')) {
            return true;
        }
        $lang = explode('_', (string) ($centre->lang ?: 'es'));
        $locale = $lang[0] . (($lang[1] ?? $centre->country ?? '') !== '' ? '_' . strtoupper($lang[1] ?? $centre->country) : '');
        $pattern = (string) \IntlDateFormatter::create($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::SHORT)->getPattern();
        return strpos(preg_replace("/'[^']*'/", '', $pattern), 'a') === false;
    }

    /**
     * An hour as the team writes it (9, 930, 9:30, 9.30, 21h) as hh:mm; empty when it is not one.
     *
     * @param string $value
     * @return string
     */
    public static function clock(string $value): string {
        $v = trim(\core_text::strtolower($value));
        if (!preg_match('/^(\d{1,2})(?:\s*[:.h]\s*(\d{2}))?\s*h?$/', $v, $m) && !preg_match('/^(\d{1,2})(\d{2})$/', $v, $m)) {
            return '';
        }
        [$h, $min] = [(int) $m[1], (int) ($m[2] ?? 0)];
        return $h <= 23 && $min <= 59 ? sprintf('%02d:%02d', $h, $min) : '';
    }

    /**
     * How many days apart two moments are, by the calendar of the person: 0 the same day, 1 the day before.
     *
     * @param int $then
     * @param int $now
     * @return int
     */
    public static function days_between(int $then, int $now): int {
        $zone = \core_date::get_user_timezone_object();
        $a = (new \DateTime('@' . $then))->setTimezone($zone)->setTime(0, 0);
        $b = (new \DateTime('@' . $now))->setTimezone($zone)->setTime(0, 0);
        return (int) round(($b->getTimestamp() - $a->getTimestamp()) / DAYSECS);
    }

    /**
     * The leaf of a calendar: the day of the week, the day and the month.
     *
     * @param int $time
     * @return array weekday, day, month
     */
    public static function leaf(int $time): array {
        return [
            'weekday' => rtrim(userdate($time, '%a'), '.'),
            'day' => userdate($time, '%d'),
            'month' => rtrim(userdate($time, '%b'), '.'),
        ];
    }

    /**
     * How many places are free of a course, «19 free of 25», with its singular for one.
     *
     * @param int $free
     * @param int $total
     * @return string
     */
    public static function free_of(int $free, int $total): string {
        $a = (object) ['free' => $free, 'total' => $total];
        return get_string($free === 1 ? 'panel_free_of_one' : 'panel_free_of', 'local_oksigeniaeguahe', $a);
    }

    /**
     * The places of a course as a real coat rail, front view: a
     * wooden plank screwed to the wall, one turned peg per place in fives, and the backpack of each enrolled child hanging
     * from its peg, each a little crooked its own way. Over 40 places, a peg every ten with its number and, under the
     * plank, the backpacks pressed together as a band. Every colour is a class (.perchero .r-* in pub/panel.css), so light,
     * dark and each skin paint it. The mock-up draws it with the same numbers (maqueta/perchero.cjs). With rows, what may
     * come under it on the same scale, peg by peg: a band from the lowest to the highest, the most likely as a mark, the
     * last place as a dotted line, and past it the band blurs. With the object of the course, what hangs is what the
     * partial panel_hanging draws for its age (the ladder of data/escalera.json), drawn small.
     *
     * @param int $total
     * @param int $taken
     * @param array $rows [['low' => int, 'mid' => int, 'high' => int, 'text' => string], ...]
     * @param string $label The accessible sentence, when the rows need one of their own.
     * @param string $object What hangs (self::hanging_object()): mochila, escolar, figura, llavero, equipaje, credencial or
     *     credencial-neutra.
     * @return string SVG.
     */
    public static function rail(int $total, int $taken, array $rows = [], string $label = '', string $object = 'mochila'): string {
        $taken = max(0, min($taken, $total));
        $label = $label !== '' ? $label : get_string('panel_places_label', self::C, (object) ['taken' => $taken,
            'total' => $total]);
        $f = fn(float $n) => rtrim(rtrim(sprintf('%.1f', $n), '0'), '.');
        $tilt = [-3.5, 3, -2, 4];
        $py = 13;
        $objects = ['escolar', 'figura', 'llavero', 'equipaje', 'credencial', 'credencial-neutra'];
        $object = in_array($object, $objects, true) ? $object : 'mochila';
        // What hangs, drawn small with the numbers of the partial panel_hanging: from (x, y), the top of the peg.
        $backpack = function (float $x, float $y, float $t) use ($f, $object): string {
            $svg = '<g class="r-mochila" transform="translate(' . $f($x) . ' ' . $f($y) . ') rotate(' . $t . ')' .
                ' scale(0.36) translate(-32 -7)">';
            if ($object === 'escolar') {
                // The school backpack of six and seven: taller than wide, its bottle out of the side pocket, the label
                // on its pocket.
                return $svg . '<path class="r-m-sombra" d="M19.5 22 C19.5 17.4 22 15 26.4 14.8 Q34 14.4 41.6 14.8 C46 15 48.5' .
                    ' 17.4 48.5 22 L48.5 59 A4 4 0 0 1 44.5 63 L23.5 63 A4 4 0 0 1 19.5 59 Z"/>' .
                    '<path class="r-m-asa" d="M28 13.5 L28 11 C28 8.8 29.8 7 32 7 C34.2 7 36 8.8 36 11 L36 13.5 L34.4 13.5' .
                    ' L34.4 11 C34.4 9.7 33.3 8.6 32 8.6 C30.7 8.6 29.6 9.7 29.6 11 L29.6 13.5 Z"/>' .
                    '<path class="r-m-correa" d="M44.2 18 C49.8 22 51 39.6 49.6 61.6 L47.4 61.4 C48.8 41 47.8 25.2 42.8 20.8 Z"/>' .
                    '<path class="r-m-correa" d="M11.6 21.6 A1 1 0 0 1 12.6 20.6 L15.4 20.6 A1 1 0 0 1 16.4 21.6 L16.4 24.2' .
                    ' L11.6 24.2 Z"/>' .
                    '<path class="r-m-banda" d="M10.4 27.2 C10.4 25 11.6 23.8 14 23.8 C16.4 23.8 17.6 25 17.6 27.2 L17.6 46' .
                    ' L10.4 46 Z"/>' .
                    '<path class="r-m-correa" d="M18.4 38 L13.4 39 C11 39.6 10 40.8 10 42.4 L10 56.6 C10 58.2 11.2 59.2 12.8' .
                    ' 59.2 L18.4 59.2 Z"/>' .
                    '<path class="r-m-cuerpo" d="M17.5 20 C17.5 15.4 20 13 24.4 12.8 Q32 12.4 39.6 12.8 C44 13 46.5 15.4 46.5' .
                    ' 20 L46.5 57 A4 4 0 0 1 42.5 61 L21.5 61 A4 4 0 0 1 17.5 57 Z"/>' .
                    '<path class="r-m-luz" d="M19.6 24 C19.8 18.6 21.6 15.8 25.6 15.4 L27.6 15.4 C23.4 16.4 21.8 19 21.8 25 Z"/>' .
                    '<path class="r-m-tapa" fill-rule="evenodd" d="M24.4 33 L39.6 33 A4 4 0 0 1 43.6 37 L43.6 54 A4 4 0 0 1' .
                    ' 39.6 58 L24.4 58 A4 4 0 0 1 20.4 54 L20.4 37 A4 4 0 0 1 24.4 33 Z M24.4 34.3 A2.7 2.7 0 0 0 21.7 37' .
                    ' L21.7 54 A2.7 2.7 0 0 0 24.4 56.7 L39.6 56.7 A2.7 2.7 0 0 0 42.3 54 L42.3 37 A2.7 2.7 0 0 0 39.6 34.3 Z"/>' .
                    '<g transform="translate(32 46) rotate(-4) scale(0.56)"><path class="r-m-etq" d="M-23 -12 Q-23 -16' .
                    ' -19 -16 L19 -16 Q23 -16 23 -12 L23 8 L15 16 L-19 16 Q-23 16 -23 12 Z"/><path class="r-m-banda"' .
                    ' d="M-23 -12 Q-23 -16 -19 -16 L19 -16 Q23 -16 23 -12 L23 -6.2 L-23 -6.2 Z"/></g></g>';
            }
            if ($object === 'figura') {
                // The sporty backpack of eight and nine, wider below, in a less sweet tone: a reflective stripe, a ball
                // on its side zip, and the name in a clear holder hanging plumb from the handle.
                return $svg . '<path class="r-m-sombra" d="M20.6 23 C20.8 18.2 23.4 16 27.6 16 L40.4 16 C44.6 16 47.2 18.2 47.4' .
                    ' 23 L52.4 58.6 A4 4 0 0 1 48.4 63 L19.6 63 A4 4 0 0 1 15.6 58.6 Z"/>' .
                    '<path class="r-m-asa" d="M28.3 16 L28.3 10 A3 3 0 0 1 31.3 7 L32.7 7 A3 3 0 0 1 35.7 10 L35.7 16 L34.1 16' .
                    ' L34.1 10.2 A1.6 1.6 0 0 0 32.5 8.6 L31.5 8.6 A1.6 1.6 0 0 0 29.9 10.2 L29.9 16 Z"/>' .
                    '<path class="r-m-correa" d="M46.6 19 C53 23.4 54.6 40 53.6 62.2 L51.4 62 C52.4 41.6 51 25.6 45.6 21.4 Z"/>' .
                    '<path class="r-m-cuerpo" d="M18.6 21 C18.8 16.2 21.4 14 25.6 14 L38.4 14 C42.6 14 45.2 16.2 45.4 21' .
                    ' L50.4 56.6 A4 4 0 0 1 46.4 61 L17.6 61 A4 4 0 0 1 13.6 56.6 Z"/>' .
                    '<path class="r-m-velo" d="M18.6 21 C18.8 16.2 21.4 14 25.6 14 L38.4 14 C42.6 14 45.2 16.2 45.4 21' .
                    ' L50.4 56.6 A4 4 0 0 1 46.4 61 L17.6 61 A4 4 0 0 1 13.6 56.6 Z"/>' .
                    '<path class="r-m-reflectante" d="M14.5 51 L48.3 42.6 L49 48 L13.9 56.4 Z"/>' .
                    '<path class="r-m-luz" d="M20.6 26 C20.8 19.6 22.6 16.6 26.6 16.2 L28.6 16.2 C24.6 17.2 22.8 20 22.8 27 Z"/>' .
                    '<circle class="r-m-correa" cx="13.4" cy="38.6" r="3.8"/>' .
                    '<g transform="translate(32 12.8) rotate(' . $f(-$t) . ') translate(-32 -12.8)">' .
                    '<path class="r-m-correa" d="M30.7 12.8 L33.3 12.8 L33.3 22.4 L30.7 22.4 Z"/>' .
                    '<path class="r-m-etq" d="M19 23.6 A2.6 2.6 0 0 1 21.6 21 L42.4 21 A2.6 2.6 0 0 1 45 23.6 L45 38.6' .
                    ' A2.6 2.6 0 0 1 42.4 41.2 L21.6 41.2 A2.6 2.6 0 0 1 19 38.6 Z"/></g></g>';
            }
            if ($object === 'credencial' || $object === 'credencial-neutra') {
                // The card on its lanyard of sixteen on: the band in the colour of the centre; from eighteen, a plain
                // lanyard. Lower than in the partial, so that the lanyard shows under the big head of the peg.
                $lanyard = $object === 'credencial' ? 'r-m-cuerpo' : 'r-m-cinta-neutra';
                return $svg . '<path class="' . $lanyard . '" d="M27.2 9.4 L31 9.4 L32.9 34 L29.5 34 Z M33 9.4 L36.8 9.4' .
                    ' L34.5 34 L31.1 34 Z"/>' .
                    '<path class="r-m-sombra" d="M17 38.4 A2.4 2.4 0 0 1 19.4 36 L48.6 36 A2.4 2.4 0 0 1 51 38.4 L51 59.6' .
                    ' A2.4 2.4 0 0 1 48.6 62 L19.4 62 A2.4 2.4 0 0 1 17 59.6 Z"/>' .
                    '<path class="r-m-etq" d="M15 36.4 A2.4 2.4 0 0 1 17.4 34 L46.6 34 A2.4 2.4 0 0 1 49 36.4 L49 57.6' .
                    ' A2.4 2.4 0 0 1 46.6 60 L17.4 60 A2.4 2.4 0 0 1 15 57.6 Z"/>' .
                    '<path class="r-m-banda" d="M15 36.4 A2.4 2.4 0 0 1 17.4 34 L46.6 34 A2.4 2.4 0 0 1 49 36.4 L49 41.4' .
                    ' L15 41.4 Z"/>' .
                    '<path class="r-m-correa" d="M30.1 32.6 L33.9 32.6 L33.5 35.6 L30.5 35.6 Z"/></g>';
            }
            if ($object === 'llavero') {
                // The backpack of ten and eleven: the narrowest and flattest, one block with its dark pocket; its plate
                // hangs plumb from the ring of the zip.
                return $svg . '<path class="r-m-sombra" d="M21 18 A2.5 2.5 0 0 1 23.5 15.5 L44.5 15.5 A2.5 2.5 0 0 1 47 18' .
                    ' L47 60.5 A2.5 2.5 0 0 1 44.5 63 L23.5 63 A2.5 2.5 0 0 1 21 60.5 Z"/>' .
                    '<path class="r-m-asa" d="M28.3 14 L28.3 10 A3 3 0 0 1 31.3 7 L32.7 7 A3 3 0 0 1 35.7 10 L35.7 14 L34.1 14' .
                    ' L34.1 10.2 A1.6 1.6 0 0 0 32.5 8.6 L31.5 8.6 A1.6 1.6 0 0 0 29.9 10.2 L29.9 14 Z"/>' .
                    '<path class="r-m-cuerpo" d="M19 16 A2.5 2.5 0 0 1 21.5 13.5 L42.5 13.5 A2.5 2.5 0 0 1 45 16 L45 58.5' .
                    ' A2.5 2.5 0 0 1 42.5 61 L21.5 61 A2.5 2.5 0 0 1 19 58.5 Z"/>' .
                    '<path class="r-m-luz" d="M20.6 16.4 L22 16.4 L22 44 L20.6 44 Z"/>' .
                    '<path class="r-m-correa" d="M21.5 30 A1.5 1.5 0 0 1 23 28.5 L41 28.5 A1.5 1.5 0 0 1 42.5 30 L42.5 56' .
                    ' A1.5 1.5 0 0 1 41 57.5 L23 57.5 A1.5 1.5 0 0 1 21.5 56 Z"/>' .
                    '<g transform="translate(32 35.4) rotate(' . $f(-$t) . ') scale(0.56) translate(0 10.6)">' .
                    '<path class="r-m-banda" d="M-21.5 -4 A5 5 0 0 1 -16.5 -9 L-5.5 -9 L-5.5 -10 A5.5 5.5 0 0 1 5.5 -10' .
                    ' L5.5 -9 L16.5 -9 A5 5 0 0 1 21.5 -4 L21.5 12.6 A5 5 0 0 1 16.5 17.6 L-16.5 17.6 A5 5 0 0 1 -21.5' .
                    ' 12.6 Z"/>' .
                    '<path class="r-m-etq" d="M-19.2 -3.6 A3 3 0 0 1 -16.2 -6.6 L16.2 -6.6 A3 3 0 0 1 19.2 -3.6 L19.2 12.2' .
                    ' A3 3 0 0 1 16.2 15.2 L-16.2 15.2 A3 3 0 0 1 -19.2 12.2 Z"/></g></g>';
            }
            if ($object === 'equipaje') {
                // The luggage tag of secondary on: cream card, its eyelet in the colour of the centre, on its cord. Lower
                // and on a thicker cord than in the partial, so that both show under the big head of the peg.
                return $svg . '<path class="r-m-sombra" d="M24 26 L44 26 L49.5 31.5 L49.5 64 A2 2 0 0 1 47.5 66 L20.5 66' .
                    ' A2 2 0 0 1 18.5 64 L18.5 31.5 Z"/>' .
                    '<path class="r-m-carton" d="M22 24 L42 24 L47.5 29.5 L47.5 62 A2 2 0 0 1 45.5 64 L18.5 64' .
                    ' A2 2 0 0 1 16.5 62 L16.5 29.5 Z"/>' .
                    '<circle class="r-m-banda" cx="32" cy="30" r="3.4"/>' .
                    '<path class="r-m-asa" d="M29.8 30 L28.4 9.6 A3.6 3.6 0 0 1 35.6 9.6 L34.2 30 L32.2 30 L33.4 9.6' .
                    ' A1.4 1.4 0 0 0 30.6 9.6 L31.8 30 Z"/></g>';
            }
            return $svg . '<path class="r-m-sombra" d="M15 32 C15 23 21.5 16 30.5 16 L37.5 16 C46.5 16 53 23 53 32 L53 57.5' .
                ' C53 60 51 62 48.5 62 L19.5 62 C17 62 15 60 15 57.5 Z"/>' .
                '<path class="r-m-asa" d="M28 15.5 L28 11 C28 8.8 29.8 7 32 7 C34.2 7 36 8.8 36 11 L36 15.5 L34.4 15.5 L34.4 11 ' .
                'C34.4 9.7 33.3 8.6 32 8.6 C30.7 8.6 29.6 9.7 29.6 11 L29.6 15.5 Z"/>' .
                '<path class="r-m-correa" d="M48 19 C55 23 56.4 40 54.6 62 L52.4 61.8 C54 41 52.6 25.4 46.6 21.6 Z"/>' .
                '<path class="r-m-cuerpo" d="M13 30 C13 21 19.5 14 28.5 14 L35.5 14 C44.5 14 51 21 51 30 L51 55.5 C51' .
                ' 58 49 60 46.5 60 ' .
                'L17.5 60 C15 60 13 58 13 55.5 Z"/>' .
                '<path class="r-m-luz" d="M17 24 C18.5 19 22.5 16.4 28 16.2 L30 16.2 C24.5 17.4 20.6 20.6 19.2 26 Z"/>' .
                '<path class="r-m-tapa" d="M16 22 L16 28.5 C16 31.5 18.4 33.6 21.4 33.6 L42.6 33.6 C45.6 33.6 48 31.5' .
                ' 48 28.5 L48 22 ' .
                'L46.2 22 L46.2 28.5 C46.2 30.5 44.6 31.8 42.6 31.8 L21.4 31.8 C19.4 31.8 17.8 30.5 17.8 28.5 L17.8 22 Z"/>' .
                '<g transform="translate(32 47) rotate(-8) scale(0.68)"><path class="r-m-etq" d="M-23 -12 Q-23 -16' .
                ' -19 -16 L19 -16 ' .
                'Q23 -16 23 -12 L23 8 L15 16 L-19 16 Q-23 16 -23 12 Z"/><path class="r-m-banda" d="M-23 -12 Q-23 -16' .
                ' -19 -16 L19 -16 ' .
                'Q23 -16 23 -12 L23 -6.2 L-23 -6.2 Z"/></g></g>';
        };
        $peg = fn(float $x, float $y): string => '<g class="r-pomo"><ellipse class="r-p-sombra" cx="' . $f($x + 1.3) . '" cy="' .
            $f($y + 2.2) . '" rx="4.4" ry="3.6"/><circle class="r-p-cuello" cx="' . $f($x) . '" cy="' . $f($y) . '" r="3.1"/>' .
            '<circle class="r-p-cabeza" cx="' . $f($x - 0.3) . '" cy="' . $f($y - 0.4) . '" r="4.1"/><circle' .
            ' class="r-p-brillo" cx="' .
            $f($x - 1.6) . '" cy="' . $f($y - 1.9) . '" r="1.2"/></g>';
        $screws = fn(float $x, float $y): string => implode('', array_map(fn($sy) => '<g class="r-tornillo"><circle cx="' . $f($x) .
            '" cy="' . $f($sy) . '" r="1.5"/><path d="M' . $f($x - 1) . ' ' . $f($sy - 0.5) . ' L' . $f($x + 1) . '' .
            ' ' . $f($sy + 0.5) .
            '"/></g>', [$y - 3.2, $y + 3.2]));
        $body = '';
        if ($total <= 40) {
            $step = 15;
            $gap = 7;
            $gaps = $total > 0 ? intdiv($total - 1, 5) : 0;
            $width = 22 + $total * $step + $gaps * $gap + 10;
            for ($i = 0; $i < $total; $i++) {
                $x = 18 + $i * $step + intdiv($i, 5) * $gap + $step / 2;
                // The loop of the backpack goes round the neck of the peg, behind its head.
                if ($i < $taken) {
                    $body .= $backpack($x, $py - 1.6, $tilt[$i % 4]);
                }
                $body .= $peg($x, $py);
            }
        } else {
            $width = 400;
            [$x0, $x1] = [18, $width - 18];
            $w = ($x1 - $x0) * $taken / $total;
            $body .= '<rect class="r-franja-sombra" x="' . $f($x0 + 1.5) . '" y="' . $f($py + 6) . '" width="' . $f($w) .
                '" height="17" rx="3.5"/><rect class="r-franja" x="' . $f($x0) . '" y="' . $f($py + 4) . '" width="' . $f($w) .
                '" height="17" rx="3.5"/>';
            for ($i = 1; $i < $taken; $i++) {
                $sx = $x0 + ($x1 - $x0) * $i / $total;
                $body .= '<path class="r-costura" d="M' . $f($sx) . ' ' . $f($py + 6) . ' V' . $f($py + 19) . '"/>';
            }
            $body .= '<rect class="r-franja-luz" x="' . $f($x0 + 2) . '" y="' . $f($py + 5.5) . '" width="' . $f(max(0, $w - 4)) .
                '" height="2" rx="1"/>';
            for ($i = 0; $i <= $total; $i += 10) {
                $x = $x0 + ($x1 - $x0) * $i / $total;
                $body .= $peg($x, $py);
                if ($i > 0) {
                    $body .= '<text class="r-num" x="' . $f($x) . '" y="' . $f($py + 36) . '"' .
                        ' text-anchor="middle">' . $i . '</text>';
                }
            }
        }
        $height = $total > 40 ? 54 : 44;
        // What may come: one band per row, on the scale of the pegs. Rounded as the mock-up does (half up).
        $g = fn(float $n): string => rtrim(rtrim(sprintf('%.1f', round($n * 10) / 10), '0'), '.');
        $extra = '';
        $full = $width;
        if ($rows) {
            $small = $total <= 40;
            $at = fn(float $v): float => $small ? 18 + ($v - 1) * 15 + floor(max(0, $v - 1) / 5) * 7 + 7.5 :
                18 + ($width - 36) * $v / $total;
            $edge = fn(float $v): float => $small ? $at($v) + 7.5 : $at($v);
            $start = fn(float $v): float => $small ? $at($v) - 7.5 : $at($v - 1);
            $cap = $width * 1.5;
            $tope = $small ? $edge($total) + 3.5 : $at($total);
            $top = $height + 4;
            foreach (array_values($rows) as $k => $r) {
                $y = $top + $k * 18;
                $x0 = max(4, $start(max(1, $r['low'])));
                $x1 = min($cap, $edge(max($r['low'], $r['high'])));
                $inside = min($x1, max($x0, $tope));
                $extra .= '<rect class="r-rango" x="' . $g($x0) . '" y="' . $g($y) . '" width="' . $g(max(2, $inside - $x0)) .
                    '" height="10" rx="2"/>';
                if ($x1 > $tope) {
                    // Past the last place the band blurs: it says «more than fit», not how many more.
                    $span = $x1 - $tope;
                    foreach ([0.85, 0.6, 0.38, 0.18] as $q => $opacity) {
                        $extra .= '<rect class="r-rango" x="' . $g($tope + $span * $q / 4) . '" y="' . $g($y) . '" width="' .
                            $g($span / 4 + 0.4) . '" height="10" opacity="' . $opacity . '"/>';
                    }
                }
                if (!empty($r['mid'])) {
                    $extra .= '<rect class="r-probable" x="' . $g(min($cap, $at($r['mid'])) - 1.5) . '" y="' . $g($y - 3) .
                        '" width="3" height="16" rx="1"/>';
                }
                $text = (string) ($r['text'] ?? '');
                if ($text !== '') {
                    $extra .= '<text class="r-num r-rango-t" x="' . $g(max($x1, $tope) + 7) . '" y="' . $g($y + 8.6) . '">' .
                        s($text) . '</text>';
                }
                $full = max($full, max($x1, $tope) + 7 + ($text !== '' ? \core_text::strlen($text) * 6.4 : 0) + 4);
            }
            $bottom = $top + count($rows) * 18 - 4;
            $extra = '<path class="r-tope" d="M' . $g($tope) . ' ' . $g($height - 6) . ' V' . $g($bottom) . '"/>' . $extra;
            $height = $bottom + 4;
        }
        $full = (int) ceil($full);
        $plank = '<rect class="r-pared-sombra" x="3" y="9" width="' . ($width - 4) . '" height="12" rx="3"/>' .
            '<rect class="r-tabla" x="2" y="6" width="' . ($width - 4) . '" height="14" rx="3"/>' .
            '<rect class="r-canto" x="2.6" y="6.6" width="' . $f($width - 5.2) . '" height="2" rx="1"/>' .
            '<rect class="r-bajo" x="2.6" y="17.4" width="' . $f($width - 5.2) . '" height="2" rx="1"/>';
        for ($k = 0; $k < 3; $k++) {
            $y = 10.4 + $k * 2.6;
            $d = 'M8 ' . $f($y);
            for ($x = 8; $x < $width - 8; $x += 36) {
                $d .= ' Q' . $f($x + 9) . ' ' . $f($y + ($k % 2 ? 0.9 : -0.9)) . ' ' . $f($x + 18) . ' ' . $f($y) . ' T' .
                    $f(min($x + 36, $width - 8)) . ' ' . $f($y);
            }
            $plank .= '<path class="r-veta" d="' . $d . '"/>';
        }
        $plank .= $screws(8, 13) . $screws($width - 8, 13);
        // Drawn a third larger than its units, and never wider than its box (the CSS lets it shrink).
        return '<svg class="perchero' . ($rows ? ' con-rango' : '') . '" viewBox="0 0 ' . $full . ' ' . $g($height) .
            '" width="' . round($full * 1.3) . '" height="' . round($height * 1.3) . '" role="img" aria-label="' . s($label) .
            '">' . $plank . $body . $extra . '</svg>';
    }

    /**
     * What hangs for a child by the age its course starts at, from the ladder of data/escalera.json: fine ages and no
     * stages, so that it holds in any country. Up to five the small backpack; at six and seven the school backpack; at
     * eight and nine the backpack with a ball; at ten and eleven the plainer one with a key ring; from twelve to fifteen
     * the luggage tag; from sixteen the card on a lanyard, and from eighteen with a plain lanyard. A child without a
     * course yet, and a course of no known age (languages, trades, degrees), as the ladder says.
     *
     * @param string $course The name of the course, as the centre writes it.
     * @param \stdClass|null $centre Its school system. Every skin hangs the same: the ladder.
     * @return string The object, and its variant after a hyphen: mochila, escolar, figura, llavero, equipaje, credencial
     *     or credencial-neutra.
     */
    public static function hanging_object(string $course, ?\stdClass $centre = null): string {
        if (trim($course) === '') {
            return self::step_object(self::ladder()['sin_curso'] ?? []);
        }
        return self::age_object(grades::age($course, $centre));
    }

    /**
     * What hangs at an age, by the step of the ladder it falls in; without an age, what the ladder hangs for a course
     * of no known age.
     *
     * @param int|null $age The age turned in the calendar year the course starts (grades::age()).
     * @return string As hanging_object().
     */
    public static function age_object(?int $age): string {
        $ladder = self::ladder();
        foreach ($age === null ? [] : ($ladder['peldanos'] ?? []) as $step) {
            $from = $step['desde'] ?? null;
            $to = $step['hasta'] ?? null;
            if (($from === null || $age >= $from) && ($to === null || $age <= $to)) {
                return self::step_object($step);
            }
        }
        return self::step_object($ladder['sin_edad'] ?? []);
    }

    /**
     * The ladder of what hangs by age, as data: what hangs without a course, without a known age, and each step.
     *
     * @return array sin_curso, sin_edad and peldanos (desde, hasta, objeto, variante, tono).
     */
    public static function ladder(): array {
        global $CFG;
        if (self::$ladder === null) {
            self::$ladder = json_decode((string) file_get_contents($CFG->dirroot . self::LADDER), true) ?: [];
        }
        return self::$ladder;
    }

    /**
     * What a step of the ladder hangs: its object, and its variant after a hyphen («credencial-neutra»). Without one,
     * the backpack.
     *
     * @param array $step
     * @return string
     */
    protected static function step_object(array $step): string {
        $object = (string) ($step['objeto'] ?? '');
        if ($object === '') {
            return 'mochila';
        }
        return $object . (empty($step['variante']) ? '' : '-' . $step['variante']);
    }

    /**
     * A backpack hanging from its knob, for the partial panel_hanging: a little crooked, each its own way, with the
     * name used at home on its label; brothers and sisters in other tones. With the course, what hangs by its age.
     *
     * @param int $i
     * @param string $name
     * @param string $course
     * @param \stdClass|null $centre
     * @return array
     */
    public static function hanging(int $i, string $name, string $course = '', ?\stdClass $centre = null): array {
        $style = '';
        if ($i) {
            $tone = ['#6F5E6E', '#5E7F6A', '#B98256'][($i - 1) % 3];
            $style = '--mochila:' . $tone . ';--mochila-sombra:' . publicpage::mix($tone, '#000000', 0.24) .
                ';--mochila-luz:' . publicpage::mix($tone, '#FFFFFF', 0.6) . ';';
        }
        return ['name' => $name, 'style' => $style . '--reposo:' . ['-3.5', '3', '-2', '4'][$i % 4] . 'deg',
            'object' => self::hanging_object($course, $centre)];
    }

    /**
     * The name a child goes by on its label: the one used at home, else the first name.
     *
     * @param \stdClass $child
     * @return string
     */
    public static function tag(\stdClass $child): string {
        $tag = trim((string) ($child->tagname ?? ''));
        return $tag !== '' ? $tag : trim((string) $child->firstname);
    }

    /**
     * The adults of a household in a line, with their first surname: «Marta Ruiz y Daniel Ortega».
     *
     * @param array $adults
     * @return string
     */
    public static function adults_line(array $adults): string {
        $names = [];
        foreach ($adults as $adult) {
            $surname = trim((string) $adult->lastname);
            // Particles stay with the surname: «Mohamed El Amrani», «Ana de la Torre».
            if (preg_match('/^((?:(?:de|del|la|las|los|da|do|dos|das|van|von|el|al|di|le)\s+)*\S+)/iu', $surname, $m)) {
                $surname = $m[1];
            }
            $names[] = trim(trim((string) $adult->firstname) . ' ' . $surname);
        }
        $names = array_values(array_filter($names));
        if (!$names) {
            return get_string('household_noname', self::C);
        }
        $last = array_pop($names);
        return $names ? get_string('andlist', self::C, (object) ['list' => implode(', ', $names), 'last' => $last]) : $last;
    }

    /**
     * Keeps a file sent with a form of the panel in a draft area of whoever sent it, until it is used or left.
     *
     * @param string $accept The endings it may have: «.csv,.xlsx».
     * @return int The draft, or 0 if no such file came.
     */
    public static function keep_upload(string $accept): int {
        global $CFG, $USER;
        require_once($CFG->libdir . '/filelib.php');
        $sent = $_FILES['file'] ?? null;
        if (!$sent || (int) ($sent['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($sent['tmp_name'])) {
            return 0;
        }
        $filename = clean_param((string) $sent['name'], PARAM_FILE);
        // No bigger than the site lets anyone upload: a whole file of years of a centre fits well within it.
        $most = get_max_upload_file_size($CFG->maxbytes ?? 0);
        if ($most > 0 && (int) filesize($sent['tmp_name']) > $most) {
            return 0;
        }
        $endings = array_map(fn($t) => preg_quote(ltrim(trim($t), '.'), '/'), explode(',', $accept));
        if (!preg_match('/\\.(' . implode('|', $endings) . ')$/i', $filename)) {
            return 0;
        }
        $draft = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_pathname(
            ['contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user', 'filearea' => 'draft', 'itemid' => $draft, 'filepath' => '/', 'filename' => $filename],
            $sent['tmp_name']
        );
        return $draft;
    }

    /**
     * The file kept in a draft area of whoever is looking.
     *
     * @param int $draft
     * @return \stored_file|null
     */
    public static function draft_file(int $draft): ?\stored_file {
        global $USER;
        if (!$draft) {
            return null;
        }
        $files = get_file_storage()->get_area_files(
            \context_user::instance($USER->id)->id,
            'user',
            'draft',
            $draft,
            'id DESC',
            false
        );
        return reset($files) ?: null;
    }

    /**
     * Forgets a file kept in a draft area.
     *
     * @param int $draft
     */
    public static function forget_draft(int $draft): void {
        global $USER;
        if ($draft) {
            get_file_storage()->delete_area_files(\context_user::instance($USER->id)->id, 'user', 'draft', $draft);
        }
    }

    /**
     * A sentence with its first letter in capitals, as a list shows it.
     *
     * @param string $text
     * @return string
     */
    public static function upper_first(string $text): string {
        return \core_text::strtoupper(\core_text::substr($text, 0, 1)) . \core_text::substr($text, 1);
    }
}
