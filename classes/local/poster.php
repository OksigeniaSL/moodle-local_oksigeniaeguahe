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
 * The poster of an open day or a visit (DESIGN.md of the sendings): the wall of the colour of the centre with its logo
 * and the event, «Vengan a conocernos», the backpack on its peg with its label blank and the question of the name, the
 * day, and the band with the QR of the short link to sign up. For A4 or A3: the same sheet, scaled.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class poster {
    /** @var array Paper => scale of the sheet of A4. */
    const PAPERS = ['A4' => '1', 'A3' => '1.41421', 'A5' => '0.70711'];

    /**
     * The poster, as a whole page to print.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param string $paper A4, A3 or A5.
     * @param bool $door The poster of the door, on the day: families sign in when they arrive, and they are here.
     * @return string
     */
    public static function html(\stdClass $event, \stdClass $centre, string $paper = 'A4', bool $door = false): string {
        global $CFG;
        $paper = isset(self::PAPERS[$paper]) ? $paper : 'A4';
        $lang = $centre->lang ?: 'es';
        $str = fn(string $id, $a = null) => publiclang::str($id, $lang, $a);
        $lines = fn(string $text) => implode('<br>', array_map('s', explode('|', $text)));
        $ucfirst = fn(string $s) => \core_text::strtoupper(\core_text::substr($s, 0, 1)) . \core_text::substr($s, 1);
        $v = publicpage::centre_vars($centre->colour ?: '#2f5d62', false);
        $parts = publicpage::date_parts((int) $event->timestart, $lang);
        if ($door) {
            [$doorlink, $doorqr] = events::door($event, $lang);
            $short = strlen($doorlink) <= 40 ? $doorlink : null;
        } else {
            $short = events::short_url($event);
        }
        $shown = $short ? preg_replace('#^https?://#', '', $short) : '';
        $words = $door ? 'poster_door_' : 'poster_';
        $logo = centres::logo((int) $centre->id);
        $phone = trim((string) ($centre->phone ?? ''));
        return documents::render('poster.html', [
            'lang' => $lang === 'pt_br' ? 'pt-BR' : $lang,
            'papel' => $paper,
            'escala' => self::PAPERS[$paper],
            'fuente_sans' => $CFG->wwwroot . '/local/oksigeniaeguahe/pub/fonts/schibsted-grotesk.woff2',
            'c' => ['acento' => $v['--c-acento'], 'sobre_acento' => $v['--c-sobre-acento'],
                'acento_texto' => $v['--c-acento-texto'], 'vivo' => $v['--c-acento-vivo'], 'pared' => $v['--c-pared']],
            'centro' => ['nombre' => format_string($centre->name), 'logo' => $logo ? $logo->out(false) : '',
                'nombre_visible' => true],
            'acto' => format_string($event->name),
            'titular' => str_replace('|', ' ', $str($words . 'title')),
            'titular_html' => $lines($str($words . 'title')),
            'pregunta_html' => $lines($str($words . 'question')),
            'pregunta_sub' => $str($words . 'question_sub'),
            'fecha' => $ucfirst($parts['long']),
            'hora' => familypage::hours((int) $event->timestart, (int) $event->timeend, $lang),
            'lugar' => implode(' · ', array_filter([$event->place ? format_string($event->place) : '',
                (string) ($centre->address ?? '')])),
            'llamada' => $str($words . 'call'),
            'como' => $str($words . 'how'),
            'url_corta' => $shown,
            'telefono_texto' => $phone !== '' && !$door ? $str('poster_phone', $phone) : '',
            'qr_svg' => $door ? $doorqr : events::qr_svg($event),
            'qr_alt' => $str($words . 'qr', $shown ?: format_string($event->name)),
            'firma' => publicpage::credit($lang)['text'] ?? '',
        ]);
    }
}
