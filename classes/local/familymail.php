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
 * What is sent to a family (DESIGN.md of the sendings, by the art director): the confirmation when they sign up, the
 * reminder the day before and the invitation when the centre gives them a visit. The brand is the centre's, never
 * Eguahe's; under the heading, the label of each child with the name used at home written by hand; the visit in a band
 * of the colour of the centre; who receives them, with their face. The images travel inside the message.
 *
 * Never the private link of a family where it can be guessed or shortened: only in its own message.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class familymail {
    /** @var string[] The kinds of mail. */
    const KINDS = ['confirmation', 'reminder', 'invitation'];

    /**
     * Names joined for the text of the labels, so they read as a phrase with the images off: «Leo», «y Noa». In
     * Spanish «y» is «e» before the sound «i» (Íñigo, Inés, Hilario; not before «hie»).
     *
     * @param string[] $names
     * @param string $lang
     * @return string[] One text per label.
     */
    public static function alt_names(array $names, string $lang): array {
        $names = array_values($names);
        $out = [];
        foreach ($names as $i => $name) {
            if ($i < count($names) - 1) {
                // A comma after each but the one before the last: «Leo, Noa y Mateo».
                $out[] = $name . ($i < count($names) - 2 ? ',' : '');
                continue;
            }
            if ($i === 0) {
                $out[] = $name;
                continue;
            }
            $and = publiclang::str('pub_and', $lang);
            if (strpos($lang, 'es') === 0 && $and === 'y' && preg_match('/^h?[iíIÍ](?![eéEÉ])/u', grades::plain($name) ?: $name)) {
                $and = 'e';
            }
            $out[] = $and . ' ' . $name;
        }
        return $out;
    }

    /**
     * The colours of the centre for the mail, light and dark.
     *
     * @param string $colour
     * @return array
     */
    protected static function colours(string $colour): array {
        $map = fn(array $v) => ['acento' => $v['--c-acento'], 'sobre_acento' => $v['--c-sobre-acento'],
            'acento_texto' => $v['--c-acento-texto'], 'vivo' => $v['--c-acento-vivo'], 'sobre_vivo' => $v['--c-sobre-vivo'],
            'pared' => $v['--c-pared']];
        return $map(publicpage::centre_vars($colour, false)) + ['oscuro' => $map(publicpage::centre_vars($colour, true))];
    }

    /**
     * The mail of a family, ready to send.
     *
     * @param string $kind One of KINDS.
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param \stdClass $signup A sign-up as households::full() gives them, with its event.
     * @param string $lang
     * @param string|null $token The private link of the family, when it is known (only at the moment it is made).
     * @return array [subject, html, text, images]
     */
    public static function build(
        string $kind,
        \stdClass $household,
        \stdClass $centre,
        \stdClass $signup,
        string $lang,
        ?string $token = null
    ): array {
        global $CFG;
        $images = [];
        $embed = function (string $data, string $name, string $mime) use (&$images): string {
            $cid = 'eg' . count($images) . '-' . substr(sha1($data), 0, 12);
            $images[] = ['data' => $data, 'cid' => $cid, 'name' => $name, 'mime' => $mime];
            return 'cid:' . $cid;
        };
        $ucfirst = fn(string $s) => \core_text::strtoupper(\core_text::substr($s, 0, 1)) . \core_text::substr($s, 1);
        $str = fn(string $id, $a = null) => publiclang::str($id, $lang, $a);
        $colour = $centre->colour ?: '#2f5d62';
        $full = households::full($household);
        $tags = array_values(array_filter(
            array_map([familypage::class, 'label_name'], array_values($full->children)),
            fn($n) => $n !== ''
        ));
        $names = familypage::join($tags, $lang);
        $parts = publicpage::date_parts((int) $signup->timestart, $lang);
        $hours = familypage::hours((int) $signup->timestart, (int) $signup->timeend, $lang);
        $place = trim(implode(' · ', array_filter([$signup->place ? format_string($signup->place) : '',
            (string) ($centre->address ?? '')])));
        $ids = array_filter(array_map('intval', explode(',', (string) ($signup->hosts ?? ''))));
        $hosts = $ids ? centres::hosts((int) $centre->id, $ids) : [];
        $host = $hosts[0]->first ?? '';
        $pageurl = $token ? (new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'lang' => $lang]))->out(false) :
            '';
        $icsurl = $token ? (new \moodle_url('/local/oksigeniaeguahe/ics.php', ['k' => $token,
            'e' => $signup->eventid]))->out(false) : '';
        $address = trim((string) ($centre->address ?? ''));
        $mapurl = $address !== '' ? 'https://www.openstreetmap.org/search?query=' .
            rawurlencode(format_string($centre->name) . ', ' . $address) : '';
        $phone = trim((string) ($centre->phone ?? ''));
        $tel = preg_replace('/[^\d+]/', '', $phone);

        // The centre: its logo on its own plate, and its name.
        $plate = ['', 0, 0];
        $logo = centres::logo_file((int) $centre->id);
        if ($logo && sticker::available() && strpos($logo->get_mimetype(), 'svg') === false) {
            $made = sticker::plate($logo->get_content());
            if ($made) {
                $plate = [$embed($made[0], 'logo.png', 'image/png'), $made[1], $made[2]];
            }
        }
        // The labels, two by row.
        $labels = [];
        if (sticker::available()) {
            [$w, $h] = sticker::size();
            $alts = self::alt_names($tags, $lang);
            foreach ($tags as $i => $tag) {
                $labels[] = ['src' => $embed(
                    sticker::png($tag, publicpage::centre_vars($colour, false)['--c-acento-vivo']),
                    'etiqueta-' . ($i + 1) . '.png',
                    'image/png'
                ), 'alt' => $alts[$i], 'ancho' => $w, 'alto' => $h];
            }
        }
        $rows = [];
        foreach (array_chunk($labels, 2) as $row) {
            $rows[] = ['etiquetas' => array_map(fn($l) => $l + ['porcentaje' => count($row) > 1 ? 50 : 100], $row),
                'ancho_fila' => 240 * count($row)];
        }
        // Who receives them: the faces, round, for the short list; the portraits for the invitation.
        $faces = [];
        $portraits = [];
        foreach ($hosts as $h) {
            $file = centres::host_photo_file((int) $h->memberid);
            $bytes = $file && sticker::available() ? $file->get_content() : '';
            $face = $bytes !== '' ? sticker::crop($bytes, 56, 56) : '';
            $portrait = $bytes !== '' ? sticker::crop($bytes, 248, 310) : '';
            $faces[] = ['nombre' => $h->name, 'papel' => $h->role, 'iniciales' => $h->initials,
                'cara' => $face !== '' ? $embed($face, 'cara-' . $h->memberid . '.jpg', 'image/jpeg') : ''];
            $portraits[] = ['nombre' => $h->name, 'papel' => $h->role, 'dice' => $h->says ? '«' . $h->says . '»' : '',
                'foto' => $portrait !== '' ? $embed($portrait, 'retrato-' . $h->memberid . '.jpg', 'image/jpeg') : '',
                'iniciales' => $h->initials];
        }
        $strong = '<strong>' . s($parts['long']) . '</strong>';
        $plain = $parts['long'];
        $lead = function (string $date) use ($host, $tags, $names, $str): string {
            $key = ($host !== '' ? 'pub_lead_host' : 'pub_lead_team') . (!$tags ? '_nochild' : (count($tags) > 1 ? '_many' : ''));
            return $str($key, (object) ['date' => $date, 'host' => s($host), 'names' => s($names)]);
        };
        $band = [
            'rotulo' => $str('pub_band_visit'),
            'dia_semana' => $ucfirst($parts['weekday']),
            'dia_mes' => $parts['daymonth'],
            'acto' => format_string($signup->eventname),
            'hora' => $hours,
            'lugar' => $place,
            'personas' => (int) $signup->people === 1 ? $str('pub_people_one') : $str('pub_people_count', (int) $signup->people),
        ];
        $calendar = $icsurl ? ['texto' => $str('pub_add_calendar'), 'url' => $icsurl, 'url_texto' => $icsurl] : null;
        $map = $mapurl ? ['texto' => $str('pub_how_get'), 'url' => $mapurl, 'url_texto' => $mapurl] : null;
        $context = [
            'lang' => $lang === 'pt_br' ? 'pt-BR' : $lang,
            'fuente_url' => $CFG->wwwroot . '/local/oksigeniaeguahe/pub/fonts/schibsted-grotesk.woff2',
            'c' => self::colours($colour),
            'centro' => [
                'nombre' => format_string($centre->name),
                'placa' => $plate[0],
                'placa_ancho' => $plate[1],
                'placa_alto' => $plate[2],
                'nombre_visible' => true,
                'direccion' => $address,
                'telefono' => $phone,
                'telefono_url' => $tel !== '' ? 'tel:' . $tel : '',
                'correo' => (string) ($centre->email ?? ''),
            ],
            'nombres' => $names,
            'etiquetas_filas' => $rows,
            'pie' => [
                'porque' => $str('mail_why', format_string($centre->name)),
                'datos_texto' => $str('pub_privacy_link'),
                'datos_url' => (new \moodle_url('/local/oksigeniaeguahe/privacy.php', ['c' => $centre->id,
                    'lang' => $lang]))->out(false),
            ],
            'titular' => $str($tags ? 'pub_wait' : 'pub_wait_nochild'),
        ];
        if ($kind === 'confirmation') {
            $context += [
                'asunto' => $str('mail_confirm_subject', $plain),
                'preencabezado' => $str($tags ? 'mail_confirm_preheader' : 'mail_confirm_preheader_nochild', $names),
                'entradilla' => $lead($strong),
                'entradilla_texto' => html_to_text($lead(s($plain)), 0, false),
                'aviso' => ['titulo' => $str('mail_saved_title'), 'texto' => $str('mail_saved_text')],
                'boton_principal' => $pageurl ? ['texto' => $str('mail_open_page'), 'url' => $pageurl,
                    'url_texto' => $pageurl] : null,
                'banda' => $band + ['boton' => $calendar, 'enlace' => $map],
                'anfitriones_breve' => $faces ? ['titulo' => $str('pub_receives'), 'personas' => $faces] : null,
                'nota' => $str('mail_note_confirm'),
            ];
        } else if ($kind === 'reminder') {
            $context['titular'] = $str($tags ? 'mail_tomorrow' : 'mail_tomorrow_nochild');
            $a = (object) ['date' => $plain, 'hour' => $parts['hour'], 'host' => s($host)];
            $leadkey = $host !== '' ? 'mail_reminder_lead' : 'mail_reminder_lead_team';
            $a->date = $strong;
            $html = $str($leadkey, $a);
            $a->date = s($plain);
            $context += [
                'asunto' => $str($tags ? 'mail_reminder_subject' : 'mail_reminder_subject_nochild', $names),
                'preencabezado' => trim($ucfirst($plain) . ', ' . \core_text::strtolower(\core_text::substr($hours, 0, 1)) .
                    \core_text::substr($hours, 1) . ($place !== '' ? ', ' . $place : '') . '.' .
                    ($host !== '' ? ' ' . $str('mail_reminder_preheader_host', $host) : '')),
                'entradilla' => $html,
                'entradilla_texto' => html_to_text($str($leadkey, $a), 0, false),
                'banda' => ['rotulo' => $str('mail_band_tomorrow')] + $band + ['boton' => $map, 'enlace' => $calendar],
                'anfitriones_breve' => $faces ? ['titulo' => $str('pub_receives'), 'personas' => $faces] : null,
                'secciones' => [[
                    'titulo' => $str('mail_cant_come'),
                    'texto' => $phone !== '' ? $str('mail_cant_come_text', '<a class="eg-enlace" href="tel:' . s($tel) .
                        '" style="color:' . s(self::colours($colour)['acento_texto']) . '; text-decoration:underline;">' .
                        s($phone) . '</a>') : $str('mail_cant_come_text_nophone'),
                    'texto_plano' => $phone !== '' ? $str('mail_cant_come_text', $phone) : $str('mail_cant_come_text_nophone'),
                    'enlace' => !empty($centre->email) ? ['texto' => $str('pub_write_us'), 'url' => 'mailto:' . $centre->email,
                        'url_texto' => $centre->email] : null,
                ]],
            ];
        } else {
            $context += [
                'asunto' => $str('mail_invite_subject', (object) ['centre' => format_string($centre->name),
                    'date' => $plain]),
                'preencabezado' => $host !== '' ? $str('mail_invite_preheader', (object) ['names' => $names,
                    'host' => $hosts[0]->name, 'hour' => $parts['hour']]) : $ucfirst($plain) . ', ' . $hours . '.',
                'entradilla' => $lead($strong),
                'entradilla_texto' => html_to_text($lead(s($plain)), 0, false),
                'banda' => $band + ['boton' => $calendar, 'enlace' => $map],
                'anfitriones' => $portraits ? ['titulo' => $str('pub_receives'), 'sub' => $str('pub_receives_sub'),
                    'filas' => array_map(
                        fn($row) => ['personas' => $row, 'hueco' => count($row) === 1],
                        array_chunk($portraits, 2)
                    )] : null,
                'secciones' => $pageurl ? [['titulo' => $str('mail_your_page'), 'texto' => s($str('mail_your_page_text')),
                    'texto_plano' => $str('mail_your_page_text'), 'enlace' => ['texto' => $str('mail_open_page'),
                    'url' => $pageurl, 'url_texto' => $pageurl]]] : null,
                'nota' => $phone !== '' ? $str('mail_invite_note', $phone) : $str('mail_invite_note_nophone'),
            ];
        }
        // The plain text keeps the band and the headings in capitals, and writes the links out.
        foreach (['banda' => 'rotulo', 'anfitriones' => 'titulo', 'anfitriones_breve' => 'titulo'] as $part => $field) {
            if (!empty($context[$part])) {
                $context[$part][$field . '_texto'] = \core_text::strtoupper($context[$part][$field]);
            }
        }
        foreach ($context['secciones'] ?? [] as $i => $section) {
            $context['secciones'][$i]['titulo_texto'] = \core_text::strtoupper($section['titulo']);
        }
        $html = documents::render('family_mail.html', $context);
        $text = trim(preg_replace("/\n{3,}/", "\n\n", documents::render('family_mail.txt', $context))) . "\n";
        return [$context['asunto'], $html, $text, $images];
    }

    /**
     * Sends a mail to the first adult of a family with an email.
     *
     * @param string $kind
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param \stdClass $signup
     * @param string $lang
     * @param string|null $token
     * @return bool Whether it was sent.
     */
    public static function send(
        string $kind,
        \stdClass $household,
        \stdClass $centre,
        \stdClass $signup,
        string $lang,
        ?string $token = null
    ): bool {
        $email = '';
        foreach (households::full($household)->adults as $adult) {
            $email = $email ?: (string) $adult->email;
        }
        if ($email === '') {
            return false;
        }
        [$subject, $html, $text, $images] = self::build($kind, $household, $centre, $signup, $lang, $token);
        return mail::send_rich($centre, $email, $subject, $html, $text, $images);
    }

    /**
     * The calendar file of the visit of a family: who receives them, how many come, their page and the phone, and an
     * alarm two hours before.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param \stdClass $event
     * @param \stdClass $signup As households::full() gives them.
     * @param string $lang
     * @param string $token
     * @return string
     */
    public static function ics(
        \stdClass $household,
        \stdClass $centre,
        \stdClass $event,
        \stdClass $signup,
        string $lang,
        string $token
    ): string {
        $str = fn(string $id, $a = null) => publiclang::str($id, $lang, $a);
        $tags = array_values(array_filter(array_map(
            [familypage::class, 'label_name'],
            array_values(households::full($household)->children)
        ), fn($n) => $n !== ''));
        $ids = array_filter(array_map('intval', explode(',', (string) ($signup->hosts ?? ''))));
        $hosts = $ids ? centres::hosts((int) $centre->id, $ids) : [];
        $link = (new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token]))->out(false);
        $lines = [];
        if ($tags) {
            $lines[] = $str('pub_wait') . ' ' . familypage::join($tags, $lang) . '.';
        }
        if ($hosts) {
            $first = array_shift($hosts);
            $lines[] = $str('ics_host', $first->name . ($first->role !== '' ? ' (' . $first->role . ')' : ''));
            if ($hosts) {
                $lines[] = $str(count($hosts) > 1 ? 'ics_host_also_many' : 'ics_host_also', familypage::join(array_map(
                    fn($h) => $h->name . ($h->role !== '' ? ' (' . $h->role . ')' : ''),
                    $hosts
                ), $lang));
            }
        }
        $people = (int) $signup->people;
        $lines[] = ($people === 1 ? $str('pub_people_one') : $str('pub_people_count', $people)) . '.';
        $lines[] = '';
        $lines[] = $str('ics_page', $link);
        if (!empty($centre->phone)) {
            $lines[] = $str('ics_cantcome', $centre->phone);
        }
        $when = events::when($event, (int) $signup->slotid);
        return events::ics($event, $centre, implode("\n", $lines), $when, [
            'uid' => 'visita-' . $event->id . '-familia-' . $household->id,
            'url' => $link,
            'alarm' => $str('ics_alarm', (object) ['hour' => publicpage::date_parts((int) $when->timestart, $lang)['hour'],
                'centre' => format_string($centre->name)]),
        ]);
    }

    /**
     * A sign-up of a family as households::full() gives them, by its event.
     *
     * @param \stdClass $household
     * @param int $eventid
     * @return \stdClass|null
     */
    public static function signup(\stdClass $household, int $eventid): ?\stdClass {
        foreach (households::full($household)->signups as $signup) {
            if ((int) $signup->eventid === $eventid) {
                return $signup;
            }
        }
        return null;
    }
}
