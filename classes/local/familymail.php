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
    const KINDS = ['confirmation', 'reminder', 'invitation', 'request', 'received', 'reply', 'change', 'thanks', 'openinvite'];

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
     * @param \stdClass|null $signup A sign-up as households::full() gives them, with its event; null for a request.
     * @param string $lang
     * @param string|null $token The private link of the family, when it is known (only at the moment it is made).
     * @param string $text What a person of the team writes, for a reply.
     * @return array [subject, html, text, images]
     */
    public static function build(
        string $kind,
        \stdClass $household,
        \stdClass $centre,
        ?\stdClass $signup,
        string $lang,
        ?string $token = null,
        string $text = ''
    ): array {
        global $CFG, $DB;
        $images = [];
        $embed = function (string $data, string $name, string $mime) use (&$images): string {
            $cid = 'eg' . count($images) . '-' . substr(sha1($data), 0, 12);
            $images[] = ['data' => $data, 'cid' => $cid, 'name' => $name, 'mime' => $mime];
            return 'cid:' . $cid;
        };
        $ucfirst = fn(string $s) => \core_text::strtoupper(\core_text::substr($s, 0, 1)) . \core_text::substr($s, 1);
        $str = fn(string $id, $a = null) => publiclang::str($id, $lang, $a);
        $credit = publicpage::credit($lang);
        $creditlink = $credit ? \html_writer::link($credit['url'], s($credit['text']), ['class' => 'eg-enlace',
            'style' => 'color:#5F5459; text-decoration:underline;']) : '';
        $colour = $centre->colour ?: '#2f5d62';
        $full = households::full($household);
        $tags = array_values(array_filter(
            array_map([familypage::class, 'label_name'], array_values($full->children)),
            fn($n) => $n !== ''
        ));
        $names = familypage::join($tags, $lang);
        // A request has no day yet: what suits them stands for it.
        $signup = $signup ?? (object) ['timestart' => time(), 'timeend' => time(), 'place' => '', 'hosts' => '',
            'eventid' => 0, 'eventname' => '', 'people' => 0];
        $parts = publicpage::date_parts((int) $signup->timestart, $lang);
        $hours = familypage::hours((int) $signup->timestart, (int) $signup->timeend, $lang);
        $place = trim(implode(' · ', array_filter([$signup->place ? format_string($signup->place) : '',
            (string) ($centre->address ?? '')])));
        $ids = array_filter(array_map('intval', explode(',', (string) ($signup->hosts ?? ''))));
        $hosts = $ids ? centres::hosts((int) $centre->id, $ids) : [];
        $host = $hosts[0]->first ?? '';
        $pageurl = $token ? (new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'lang' => $lang]))->out(false) :
            '';
        $icsurl = $token && $signup->eventid ? (new \moodle_url('/local/oksigeniaeguahe/ics.php', ['k' => $token,
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
            'personas' => publiclang::count('pub_people_one', 'pub_people_count', (int) $signup->people, $lang),
        ];
        $calendar = $icsurl ? ['texto' => $str('pub_add_calendar'), 'url' => $icsurl, 'url_texto' => $icsurl] : null;
        $map = $mapurl ? ['texto' => $str('pub_how_get'), 'url' => $mapurl, 'url_texto' => $mapurl] : null;
        $context = [
            'lang' => $lang === 'pt_br' ? 'pt-BR' : $lang,
            // Written from right to left, the mail turns round: the side where things end.
            'dir' => lists::dir($lang),
            'fin' => lists::dir($lang) === 'rtl' ? 'left' : 'right',
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
                'firma' => $credit ? $str('mail_credit', $creditlink) : '',
                'firma_texto' => $credit ? $str('mail_credit', $credit['text']) . ': ' . $credit['url'] : '',
            ],
            'titular' => $str($tags ? 'pub_wait' : 'pub_wait_nochild'),
        ];
        if ($kind === 'openinvite') {
            // An open act of the centre for a family that is not of it yet: what, when and where, and how to sign up.
            $joinurl = !empty($signup->token) ? (new \moodle_url('/local/oksigeniaeguahe/j.php', ['t' => $signup->token,
                'lang' => $lang]))->out(false) : '';
            $event = format_string($signup->eventname);
            $context['titular'] = $str($tags ? 'mail_open_titular' : 'mail_open_titular_nochild');
            // Why they get it: they let the centre write to them, not that they signed up to a visit.
            $context['pie']['porque'] = $str('mail_open_why', format_string($centre->name));
            $context += [
                'asunto' => $str('mail_open_subject', (object) ['centre' => format_string($centre->name), 'event' => $event]),
                'preencabezado' => $ucfirst($plain) . ', ' . $hours . '.',
                'entradilla' => $str('mail_open_lead', (object) ['event' => '<strong>' . s($event) . '</strong>',
                    'date' => $strong]),
                'entradilla_texto' => $str('mail_open_lead', (object) ['event' => $event, 'date' => $plain]),
                'banda' => ['rotulo' => $str('mail_open_band'), 'personas' => ''] + $band + ['personas' => '',
                    'boton' => $joinurl ? ['texto' => $str('mail_open_join'), 'url' => $joinurl, 'url_texto' => $joinurl] : null,
                    'enlace' => $map],
                'secciones' => $pageurl ? [['titulo' => $str('mail_your_page'), 'texto' => s($str('mail_your_page_text')),
                    'texto_plano' => $str('mail_your_page_text'), 'enlace' => ['texto' => $str('mail_open_page'),
                    'url' => $pageurl, 'url_texto' => $pageurl]]] : null,
                'nota' => $str('mail_open_note'),
            ];
        } else if ($kind === 'confirmation') {
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
        } else if ($kind === 'received') {
            // A family that wrote through a form or an entry: it was received, and a person will answer.
            $context['titular'] = $str('mail_received_titular');
            $context += [
                'asunto' => $str('mail_received_subject'),
                'preencabezado' => $str('mail_received_lead'),
                'entradilla' => s($str('mail_received_lead')),
                'entradilla_texto' => $str('mail_received_lead'),
                'aviso' => ['titulo' => $str('mail_saved_title_received'), 'texto' => $str('mail_saved_text')],
                'boton_principal' => $pageurl ? ['texto' => $str('mail_open_page'), 'url' => $pageurl,
                    'url_texto' => $pageurl] : null,
                'nota' => $str('mail_note_confirm'),
            ];
        } else if ($kind === 'reply') {
            // What a person of the team writes, as it was written, with its page below.
            $paragraphs = array_filter(array_map('trim', preg_split('/\R{2,}/', trim($text))));
            $context['titular'] = $str('mail_reply_titular');
            $context += [
                'asunto' => $str('mail_reply_subject', format_string($centre->name)),
                'preencabezado' => \core_text::substr(preg_replace('/\s+/', ' ', $text), 0, 120),
                'entradilla' => implode('<br><br>', array_map(fn($p) => nl2br(s($p), false), $paragraphs)),
                'entradilla_texto' => trim($text),
                'boton_principal' => $pageurl ? ['texto' => $str('mail_open_page'), 'url' => $pageurl,
                    'url_texto' => $pageurl] : null,
                'nota' => $str('mail_note_reply'),
            ];
        } else if ($kind === 'change') {
            // The team changed the hour, the place or who receives them: what it is now, with its band and calendar.
            $paragraphs = array_filter(array_map('trim', preg_split('/\R{2,}/', trim($text))));
            $context['titular'] = $str('mail_change_titular');
            $context += [
                'asunto' => $str('mail_change_subject', format_string($signup->eventname)),
                'preencabezado' => \core_text::substr(preg_replace('/\s+/', ' ', end($paragraphs) ?: $text), 0, 120),
                'entradilla' => implode('<br><br>', array_map(fn($p) => nl2br(s($p), false), $paragraphs)),
                'entradilla_texto' => trim($text),
                'boton_principal' => $pageurl ? ['texto' => $str('mail_open_page'), 'url' => $pageurl,
                    'url_texto' => $pageurl] : null,
                'banda' => $band + ['boton' => $calendar, 'enlace' => $map],
                'anfitriones_breve' => $faces ? ['titulo' => $str('pub_receives'), 'personas' => $faces] : null,
                'nota' => $str('mail_note_reply'),
            ];
        } else if ($kind === 'thanks') {
            // After the visit: thanks, and the one open question; or, if the centre turned it on, the survey, which asks
            // it first. Never both waiting.
            $event = $DB->get_record(events::T, ['id' => $signup->eventid]);
            $survey = $event && surveys::is_open($event, time()) ? surveys::form($event) : null;
            $count = $survey ? 1 + count(array_filter(forms::fields((int) $survey->id), fn($f) => $f->type !== 'section')) : 0;
            $days = max(1, (int) ($centre->replydays ?? 2));
            $a = (object) ['host' => $host, 'days' => publiclang::count('pub_reply_day', 'pub_reply_days', $days, $lang),
                'n' => $count];
            $key = $survey ? 'mail_thanks_survey' : ($host !== '' ? 'mail_thanks_ask_host' : 'mail_thanks_ask_team');
            $text = $str($key, $a);
            $leadtext = $host !== '' ? $str('pub_lead_after_host', s($host)) : $str('pub_lead_after_team');
            $go = $pageurl ? $pageurl . ($survey ? '#como-fue' : '#pregunta') : '';
            $context['titular'] = $str($tags ? 'pub_thanks' : 'pub_thanks_nochild');
            $context += [
                'asunto' => $str('mail_thanks_subject', format_string($centre->name)),
                'preencabezado' => $str($survey ? 'mail_thanks_preheader_survey' : 'mail_thanks_preheader'),
                'entradilla' => $leadtext,
                'entradilla_texto' => html_to_text($leadtext, 0, false),
                'secciones' => [[
                    'titulo' => $str($survey ? 'pub_howitwent' : 'pub_after_question'),
                    'texto' => s($text),
                    'texto_plano' => $text,
                    'enlace' => $go ? ['texto' => $survey ? $str('mail_thanks_go_survey') : ($host !== '' ?
                        $str('mail_thanks_go_host', $host) : $str('mail_thanks_go_team')), 'url' => $go, 'url_texto' => $go] :
                        null,
                ]],
                'nota' => $str('mail_note_reply'),
            ];
        } else if ($kind === 'request') {
            $pending = visits::pending((int) $household->id);
            $when = $pending ? visits::suits($pending, $lang) : $str('visit_anyday');
            $context += [
                'asunto' => $str('mail_request_subject'),
                'preencabezado' => $str('mail_request_lead', $when),
                'entradilla' => s($str('mail_request_lead', $when)),
                'entradilla_texto' => $str('mail_request_lead', $when),
                'aviso' => ['titulo' => $str('mail_saved_title_request'), 'texto' => $str('mail_saved_text')],
                'boton_principal' => $pageurl ? ['texto' => $str('mail_open_page'), 'url' => $pageurl,
                    'url_texto' => $pageurl] : null,
                'nota' => $str('mail_note_confirm'),
            ];
        } else if ($kind === 'reminder') {
            // How they get in on the day, as the centre wrote it.
            $arrive = trim((string) ($centre->arrive ?? ''));
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
                'secciones' => array_merge($arrive !== '' ? [[
                    'titulo' => $str('pub_how_get'),
                    'titulo_texto' => $str('pub_how_get'),
                    'texto' => nl2br(s($arrive), false),
                    'texto_plano' => $arrive,
                    'enlace' => $map,
                ]] : [], [[
                    'titulo' => $str('mail_cant_come'),
                    'titulo_texto' => $str('mail_cant_come'),
                    'texto' => $phone !== '' ? $str('mail_cant_come_text', '<a class="eg-enlace" href="tel:' . s($tel) .
                        '" style="color:' . s(self::colours($colour)['acento_texto']) . '; text-decoration:underline;">' .
                        s($phone) . '</a>') : $str('mail_cant_come_text_nophone'),
                    'texto_plano' => $phone !== '' ? $str('mail_cant_come_text', $phone) : $str('mail_cant_come_text_nophone'),
                    'enlace' => !empty($centre->email) ? ['texto' => $str('pub_write_us'), 'url' => 'mailto:' . $centre->email,
                        'url_texto' => $centre->email] : null,
                ]]),
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
     * Whether the family let the centre write to them by email.
     *
     * @param \stdClass $household
     * @return bool
     */
    public static function may_email(\stdClass $household): bool {
        foreach (households::permissions((int) $household->id) as $channels) {
            if (!empty($channels['email'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Sends a mail to the first adult of a family with an email.
     *
     * @param string $kind
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param \stdClass|null $signup Null for a request.
     * @param string $lang
     * @param string|null $token
     * @param string|null $to Another address of the family, instead of the first one.
     * @param string $text What a person of the team writes, for a reply.
     * @return bool Whether it was sent.
     */
    public static function send(
        string $kind,
        \stdClass $household,
        \stdClass $centre,
        ?\stdClass $signup,
        string $lang,
        ?string $token = null,
        ?string $to = null,
        string $text = ''
    ): bool {
        if ($kind !== 'reply') {
            // The same notice on the phones the family joined with, when the site has Marandu.
            messaging::notify($kind, $household, $centre, $signup, $lang, $text);
        }
        $email = (string) $to;
        foreach ($to === null ? households::full($household)->adults : [] as $adult) {
            $email = $email ?: (string) $adult->email;
        }
        if ($email === '') {
            return false;
        }
        [$subject, $html, $plain, $images] = self::build($kind, $household, $centre, $signup, $lang, $token, $text);
        return mail::send_rich($centre, $email, $subject, $html, $plain, $images);
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
        $lines[] = publiclang::count('pub_people_one', 'pub_people_count', $people, $lang) . '.';
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
