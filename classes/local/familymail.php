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
    const KINDS = ['confirmation', 'reminder', 'invitation', 'request', 'received', 'reply', 'change', 'cancelled', 'thanks',
        'openinvite', 'renewal', 'writeby'];

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
     * The colours of the centre for the mail, light and dark; with its brand, its paper, its wall and its titles, as on
     * its pages.
     *
     * @param string $colour
     * @param string[] $brand centres::brand()
     * @return array
     */
    protected static function colours(string $colour, array $brand = []): array {
        $map = function (bool $dark) use ($colour, $brand): array {
            $v = publicpage::centre_vars($colour, $dark);
            $m = $brand ? publicpage::brand_vars($brand, $colour, $dark) : null;
            $out = ['acento' => $v['--c-acento'], 'sobre_acento' => $v['--c-sobre-acento'],
                'acento_texto' => $m ? $m['--m-enlace'] : $v['--c-acento-texto'], 'vivo' => $v['--c-acento-vivo'],
                'sobre_vivo' => $v['--c-sobre-vivo'],
                'pared' => $m ? $m['--m-pared'] : $v['--c-pared'],
                'papel' => $m ? $m['--m-papel'] : ($dark ? '#171413' : '#FBF7F1'),
                'titulo' => $m ? $m['--m-titulo'] : ($dark ? '#F3ECE6' : '#2B2226'),
                'sup' => $m ? $m['--m-superficie'] : ($dark ? '#211D1B' : '#FFFFFF'),
                'linea' => $m ? $m['--m-linea'] : ($dark ? '#36302D' : '#E7DED3')];
            // The fill of what is marked (the way they are written to now): pale by day, a tint of the surface at night.
            $out['relleno'] = publicpage::mix($out['sup'], $colour, $dark ? 0.28 : 0.09);
            return $out;
        };
        return $map(false) + ['oscuro' => $map(true)];
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
     * @param string|null $choose A link of the family for choosing how they are written to, when the mail has none.
     * @param string $to The address it goes to, when it is not the usual one: whoever reads it is who is greeted.
     * @return array [subject, html, text, images]
     */
    public static function build(
        string $kind,
        \stdClass $household,
        \stdClass $centre,
        ?\stdClass $signup,
        string $lang,
        ?string $token = null,
        string $text = '',
        ?string $choose = null,
        string $to = ''
    ): array {
        global $CFG, $DB;
        // A student who comes on their own is written to as one person.
        publiclang::speak_to($household);
        if ($kind === 'openinvite') {
            [$subject, $html, $plain, $images] = self::letter(
                self::first_name($household),
                $centre,
                $signup,
                $lang,
                (string) $token,
                $text,
                !empty($household->incentre)
            );
            return [$subject, $html, $plain, $images];
        }
        // Of an act that is not a visit: a letter of its own in the common mail, about the act.
        $act = actmail::act($kind, $signup, $centre);
        if ($act) {
            return actmail::build($kind, $household, $centre, $act, $signup, $lang, (string) ($token ?? $choose ?? ''), $text, $to);
        }
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
        $colour = centres::colour($centre);
        $full = households::full($household);
        $tags = array_values(array_filter(
            array_map([familypage::class, 'label_name'], array_values($full->children)),
            fn($n) => $n !== ''
        ));
        $names = familypage::join($tags, $lang);
        // Those who would still come to the centre: nobody already in it, waiting for a place, closed or gone is shown where
        // it would leave its backpack, nor told how to ask for a place.
        $coming = array_filter($full->children, fn($c) => in_array($c->stage, ['inquiry', 'visit', 'application', 'admitted'], true));
        $asking = array_filter($coming, fn($c) => in_array($c->stage, ['inquiry', 'visit'], true)) ||
            (!$full->children && empty($household->incentre));
        $comingtags = array_values(array_filter(
            array_map([familypage::class, 'label_name'], array_values($coming)),
            fn($n) => $n !== ''
        ));
        $comingnames = familypage::join($comingtags, $lang);
        // A request has no day yet: what suits them stands for it.
        $signup = $signup ?? (object) ['timestart' => time(), 'timeend' => time(), 'place' => '', 'hosts' => '',
            'eventid' => 0, 'eventname' => '', 'people' => 0];
        $parts = publicpage::date_parts((int) $signup->timestart, $lang);
        $hours = familypage::hours((int) $signup->timestart, (int) $signup->timeend, $lang);
        $place = trim(implode(' · ', array_filter([$signup->place ? publicpage::text($signup->place) : '',
            (string) ($centre->address ?? '')])));
        $ids = array_filter(array_map('intval', explode(',', (string) ($signup->hosts ?? ''))));
        $hosts = $ids ? centres::hosts((int) $centre->id, $ids) : [];
        $host = $hosts[0]->first ?? '';
        // Each link says which mail it is in, so what is pressed inside is counted (never whether it is opened).
        $pageurl = $token ? (new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'idioma' => $lang,
            'de' => $kind]))->out(false) : '';
        $icsurl = $token && $signup->eventid ? (new \moodle_url('/local/oksigeniaeguahe/ics.php', ['k' => $token,
            'e' => $signup->eventid, 'de' => $kind]))->out(false) : '';
        $address = trim((string) ($centre->address ?? ''));
        $mapurl = centres::map_url($centre);
        $phone = trim((string) ($centre->phone ?? ''));
        $tel = preg_replace('/[^\d+]/', '', $phone);
        // Its WhatsApp, which opens the chat; the same number as the phone is written once.
        $whatsapp = centres::whatsapp($centre, $lang);

        // The centre: its logo on its own plate, and its name.
        $plate = ['', 0, 0];
        $logo = centres::logo_raster((int) $centre->id);
        if ($logo && sticker::available()) {
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
        $faceof = function (\stdClass $h, string $bytes) use ($embed): array {
            $face = $bytes !== '' ? sticker::crop($bytes, 56, 56) : '';
            return ['nombre' => $h->name, 'papel' => $h->role, 'iniciales' => $h->initials,
                'cara' => $face !== '' ? $embed($face, 'cara-' . $h->memberid . '.jpg', 'image/jpeg') : ''];
        };
        $bytesof = function (\stdClass $h): string {
            $file = centres::host_photo_file((int) $h->memberid);
            return $file && sticker::available() ? $file->get_content() : '';
        };
        $faces = [];
        $portraits = [];
        foreach ($hosts as $h) {
            $bytes = $bytesof($h);
            $portrait = $bytes !== '' ? sticker::crop($bytes, 248, 310) : '';
            $faces[] = $faceof($h, $bytes);
            $portraits[] = ['nombre' => $h->name, 'papel' => $h->role, 'dice' => $h->says ? '«' . $h->says . '»' : '',
                'foto' => $portrait !== '' ? $embed($portrait, 'retrato-' . $h->memberid . '.jpg', 'image/jpeg') : '',
                'iniciales' => $h->initials];
        }
        $strong = '<strong>' . s($parts['long']) . '</strong>';
        $plain = $parts['long'];
        // When to come: at its hour for a turn or a short visit; any time within its hours for a long open act.
        $lower = fn(string $s) => \core_text::strtolower(\core_text::substr($s, 0, 1)) . \core_text::substr($s, 1);
        $open = empty($signup->slotid) && (int) $signup->timeend - (int) $signup->timestart >= 2 * HOURSECS;
        $when = $open ? $lower($hours) : $str('mail_at_hour', $parts['hour']);
        // The day with its hour, for the first line: «el sábado 21 de noviembre, a las 10:00, los recibe…». Each language
        // puts its own commas: the lead of English and Portuguese already has one after the date.
        // From right to left, a mark of that direction first: the day and the hour are isolated each on their own, so
        // nothing else would tell the isolate around both which way it goes, and the hour came before the day.
        $rlm = lists::dir($lang) === 'rtl' ? "\u{200F}" : '';
        $strongwhen = $rlm . $str('mail_date_when', (object) ['date' => $strong, 'when' => s($when)]);
        $plainwhen = $rlm . $str('mail_date_when', (object) ['date' => $plain, 'when' => $when]);
        // The centre in a sentence, with its article: «al Colegio Los Arces», «del Colegio Los Arces».
        $tocentre = share::to_centre($centre, $lang);
        $from = share::from_centre($centre, $lang);
        // Why they get it, by the moment: they signed up, came, wrote, asked for a visit or are a family of the centre.
        $why = ['thanks' => 'mail_why_came', 'received' => 'mail_why_wrote', 'reply' => 'mail_why_wrote',
            'request' => 'mail_why_request', 'renewal' => 'mail_why_family'][$kind] ?? 'mail_why';
        // Written by the team on its own, and not to answer them: never «because you wrote to us».
        $told = $kind === 'reply' && empty($household->waitingsince);
        $why = $told ? 'mail_why_touch' : $why;
        // A visit of their own, which the centre gave them: nobody signed up to it, and there is no place to pass on.
        $own = $signup->eventid && (int) $DB->get_field(events::T, 'householdid', ['id' => $signup->eventid]) > 0;
        $why = $own && $why === 'mail_why' ? 'mail_why_visit' : $why;
        $older = ages::older(array_values($coming), $centre);
        $lead = function (string $date) use ($host, $comingtags, $comingnames, $str, $older): string {
            $key = ($host !== '' ? 'pub_lead_host' : 'pub_lead_team') . (!$comingtags ? '_nochild' :
                ($older ? '_older' : '') . (count($comingtags) > 1 ? '_many' : ''));
            return $str($key, (object) ['date' => $date, 'host' => s($host), 'names' => s($comingnames)]);
        };
        $band = [
            'rotulo' => $str('pub_band_visit'),
            'dia_semana' => $ucfirst($parts['weekday']),
            'dia_mes' => $parts['daymonth'],
            'acto' => publicpage::text(visits::shown((string) $signup->eventname, $lang)),
            'hora' => $open ? $str('mail_hours_open', $lower($hours)) : $hours .
                (!empty($signup->slotid) ? ' · ' . $str('pub_your_turn') : ''),
            'lugar' => $place,
            'personas' => publiclang::count('pub_people_one', 'pub_people_count', (int) $signup->people, $lang),
        ];
        $calendar = $icsurl ? ['texto' => $str('pub_add_calendar'), 'url' => $icsurl, 'url_texto' => $icsurl] : null;
        // The phone of the centre in a sentence: left to right and in one piece, also inside Arabic.
        $phonelink = '<a class="eg-enlace" dir="ltr" href="tel:' . s($tel) . '" style="color:' .
            s(self::colours($colour)['acento_texto']) . '; text-decoration:underline; white-space:nowrap;">' . s($phone) . '</a>';
        // Not coming after all: one touch on their page, where the visit has its «We cannot come»; or a call.
        $cantgo = $token && $signup->eventid ? (new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'idioma' => $lang,
            'de' => $kind, 'action' => 'nopodemos', 'acto' => (int) $signup->eventid]))->out(false) : '';
        $cantcome = $cantgo ? [
            'titulo' => $str('mail_cant_come'),
            'texto' => $str('mail_cant_come_' . ($own ? 'own' : 'page') . ($phone !== '' ? '_phone' : ''), $phonelink),
            'texto_plano' => $str('mail_cant_come_' . ($own ? 'own' : 'page') . ($phone !== '' ? '_phone' : ''), $phone),
            'enlace' => ['texto' => $str('mail_cant_come_go'), 'url' => $cantgo, 'url_texto' => $cantgo],
        ] : null;
        $map = $mapurl ? ['texto' => $str('pub_how_get'), 'url' => $mapurl, 'url_texto' => $mapurl] : null;
        $context = [
            'lang' => $lang === 'pt_br' ? 'pt-BR' : $lang,
            // Written from right to left, the mail turns round: the side where things end.
            'dir' => lists::dir($lang),
            'fin' => lists::dir($lang) === 'rtl' ? 'left' : 'right',
            'fuente_url' => $CFG->wwwroot . '/local/oksigeniaeguahe/pub/fonts/schibsted-grotesk.woff2',
            'c' => self::colours($colour, centres::brand($centre)),
            // The centre wants its pages and mails always light, whatever the phone of the family says.
            'soloclaro' => !empty($centre->familylight),
            // The stripe of the brand; at night each colour lightened until it shows on the dark paper of the brand.
            'franja' => array_map(
                fn($code, $night, $n) => ['claro' => $code, 'oscuro' => $night, 'n' => $n],
                centres::brand($centre),
                publicpage::brand_on_dark($centre),
                array_keys(centres::brand($centre))
            ),
            'centro' => [
                'nombre' => publicpage::text($centre->name),
                'placa' => $plate[0],
                'placa_ancho' => $plate[1],
                'placa_alto' => $plate[2],
                'nombre_visible' => true,
                // Without a logo, its initials on its colour, as at the head of its pages.
                'iniciales' => publicpage::initials(publicpage::text($centre->name)),
                'direccion' => $address,
                'telefono' => $phone,
                'whatsapp' => $whatsapp['text'] ?? '',
                'whatsapp_url' => $whatsapp['url'] ?? '',
                'telefono_url' => $tel !== '' ? 'tel:' . $tel : '',
                'correo' => (string) ($centre->email ?? ''),
                'separa' => $address !== '' || $phone !== '' || $whatsapp,
                'contacto' => implode(' · ', array_filter([$phone, $whatsapp ? publiclang::str('pub_whatsapp', $lang) .
                    ($whatsapp['same'] ? '' : ' ' . $whatsapp['text']) : '', trim((string) ($centre->email ?? ''))])),
            ],
            'nombres' => $names,
            'etiquetas_filas' => $rows,
            'pie' => [
                'porque' => $str($why, $kind === 'renewal' ? $from : $tocentre),
                'datos_texto' => $str('pub_privacy_link'),
                'datos_url' => publicpage::privacy_url($centre, $lang)->out(false),
                'firma' => $credit ? $str('mail_credit', $creditlink) : '',
                'firma_texto' => $credit ? $str('mail_credit', $credit['text']) . ': ' . $credit['url'] : '',
            ],
            'titular' => $str($tags ? 'pub_wait' : 'pub_wait_nochild'),
            // How they want to be written to, at sight and in normal type: never the small print at the foot.
            'escribir' => writeby::mail_block($household, $centre, (string) ($choose ?? $token ?? ''), $lang, $kind),
        ];
        if ($kind === 'writeby') {
            // They chose to be written to by email: said back, short and warm, in the same mail.
            $context['titular'] = $str('mail_writeby_titular');
            $context['etiquetas_filas'] = [];
            $context['nombres'] = '';
            $context['pie']['porque'] = $str('mail_writeby_why', $tocentre);
            $changeurl = $token ? writeby::url($token, $lang, '', $kind) : '';
            $ways = writeby::words(writeby::chosen($household) ?: ['email'], $lang);
            $context += [
                'asunto' => $str('mail_writeby_subject', (object) ['ways' => $ways, 'centre' => publicpage::text($centre->name)]),
                'preencabezado' => $str('mail_writeby_preheader'),
                'entradilla' => $str('mail_writeby_lead', $ways),
                'entradilla_texto' => $str('mail_writeby_lead', $ways),
                'secciones' => $changeurl ? [['titulo' => $str('mail_writeby_change'),
                    'texto' => s($str('mail_writeby_change_text')), 'texto_plano' => $str('mail_writeby_change_text'),
                    'enlace' => ['texto' => $str('mail_writeby_change_go'), 'url' => $changeurl,
                    'url_texto' => $changeurl]]] : null,
            ];
        } else if ($kind === 'confirmation') {
            $context += [
                'asunto' => $str('mail_confirm_subject', (object) ['date' => $plain, 'when' => $when]),
                'preencabezado' => $str($tags ? 'mail_confirm_preheader' : 'mail_confirm_preheader_nochild', $names),
                'entradilla' => $lead($strongwhen),
                'entradilla_texto' => html_to_text($lead(s($plainwhen)), 0, false),
                'aviso' => ['titulo' => $str('mail_saved_title'), 'texto' => $str('mail_saved_text')],
                'boton_principal' => $pageurl ? ['texto' => $str('mail_open_page'), 'url' => $pageurl,
                    'url_texto' => $pageurl] : null,
                'banda' => $band + ['boton' => $calendar, 'enlace' => $map],
                'anfitriones_breve' => $faces ? ['titulo' => $str('pub_receives'), 'personas' => $faces] : null,
                'secciones' => $cantcome ? [$cantcome] : null,
                // Of a talk or a party there is no place to speak of; nor for whoever has asked for one already.
                'nota' => $str(!$asking || $DB->get_field(events::T, 'kind', ['id' => $signup->eventid]) === 'other' ?
                    'mail_note_confirm_act' : 'mail_note_confirm'),
            ];
        } else if ($kind === 'received') {
            // A family that wrote through a form or an entry: it was received, and a person will answer.
            // The thanks go to whoever wrote, never to the child: no label under the heading.
            // Until which day, by the working days of the centre: the day itself, not a count to make.
            $days = workdays::promise($centre, time(), $lang);
            // Who will answer, with their face: the person who attends them, or those who usually answer; with nobody
            // to name, «a person of the centre».
            $answer = centres::answerers($centre, $household);
            $one = count($answer) === 1;
            $received = $one ? $str('mail_received_lead_host', (object) ['host' => $answer[0]->first, 'days' => $days]) :
                $str('mail_received_lead', $days);
            // What they have just asked for, in the words of the form: «Pedir cita».
            $asked = forms::asked_for((int) $household->id, time());
            $received = ($asked !== '' ? $str('mail_received_said', $asked) . ' ' : '') . $received;
            $first = self::first_name($household, $to);
            $context['titular'] = $first !== '' ? $str('mail_received_titular_name', $first) : $str('mail_received_titular');
            $context['etiquetas_filas'] = [];
            $context['nombres'] = '';
            $context += [
                'asunto' => $str('mail_received_subject'),
                'preencabezado' => $received,
                'entradilla' => s($received),
                'entradilla_texto' => $received,
                // They have no visit yet: what they told is what is in their page.
                'aviso' => ['titulo' => $str('mail_saved_title_received'), 'texto' => $str('mail_saved_text_wrote')],
                'boton_principal' => $pageurl ? ['texto' => $str('mail_open_page'), 'url' => $pageurl,
                    'url_texto' => $pageurl] : null,
                'anfitriones_breve' => $answer ? ['titulo' => $str($one ? 'mail_received_who' : 'mail_received_who_many'),
                    'personas' => array_map(fn($h) => $faceof($h, $bytesof($h)), $answer)] : null,
                'nota' => ($asking ? $str('pub_no_place_form') . ' ' : '') . $str('mail_note_confirm_act'),
            ];
        } else if ($kind === 'reply') {
            // What a person of the team writes, as it was written, with its page below: to answer them, or on its own.
            $paragraphs = array_filter(array_map('trim', preg_split('/\R{2,}/', trim($text))));
            $context['titular'] = $str($told ? 'mail_told_titular' : 'mail_reply_titular');
            $context['etiquetas_filas'] = [];
            $context['nombres'] = '';
            $context += [
                'asunto' => $str($told ? 'mail_told_subject' : 'mail_reply_subject', $from),
                'preencabezado' => \core_text::substr(preg_replace('/\s+/', ' ', $text), 0, 120),
                'entradilla' => implode('<br><br>', array_map(fn($p) => nl2br(s($p), false), $paragraphs)),
                'entradilla_texto' => trim($text),
                'boton_principal' => $pageurl ? ['texto' => $str('mail_open_page'), 'url' => $pageurl,
                    'url_texto' => $pageurl] : null,
                // Never twice: what the team wrote may say already that they can answer.
                'nota' => self::says_answer($text, $lang) ? null : $str('mail_note_reply'),
            ];
        } else if ($kind === 'change') {
            // The team changed the hour, the place or who receives them: what it is now, with its band and calendar. With no
            // words of its own, what the data says.
            if (trim($text) === '') {
                $message = panel_eventform::message($household, $centre, $signup, $lang);
                $text = trim($message['greeting'] . "\n\n" . $message['text']);
            }
            $paragraphs = array_filter(array_map('trim', preg_split('/\R{2,}/', trim($text))));
            $context['titular'] = $str('mail_change_titular');
            $context['etiquetas_filas'] = [];
            $context['nombres'] = '';
            $context += [
                'asunto' => $own ? $str('mail_change_subject_visit', (object) ['date' => $plain, 'when' => $when]) :
                    $str('mail_change_subject', publicpage::text(visits::shown((string) $signup->eventname, $lang))),
                'preencabezado' => \core_text::substr(preg_replace('/\s+/', ' ', end($paragraphs) ?: $text), 0, 120),
                'entradilla' => implode('<br><br>', array_map(fn($p) => nl2br(s($p), false), $paragraphs)),
                'entradilla_texto' => trim($text),
                // What it is now comes first, in its band; their page, after it.
                'boton_despues' => $pageurl ? ['texto' => $str('mail_open_page'), 'url' => $pageurl,
                    'url_texto' => $pageurl] : null,
                'banda' => $band + ['boton' => $calendar, 'enlace' => $map],
                'anfitriones_breve' => $faces ? ['titulo' => $str('pub_receives'), 'personas' => $faces] : null,
                'nota' => $str('mail_note_reply'),
            ];
        } else if ($kind === 'cancelled') {
            // The centre called the act off: said plainly, with whatever the team added, and the way to write back.
            $a = (object) ['event' => publicpage::text(visits::shown((string) $signup->eventname, $lang)),
                'when' => publicpage::date_parts((int) $signup->timestart, $lang)['long']];
            $lead = $str($own ? 'mail_cancelled_lead_visit' : 'mail_cancelled_lead', $a);
            $paragraphs = array_filter(array_map('trim', preg_split('/\R{2,}/', trim($text))));
            $openday = $DB->get_field(events::T, 'kind', ['id' => $signup->eventid]) === 'openday';
            $context['titular'] = $str($own ? 'mail_cancelled_titular_visit' : ($openday ? 'mail_cancelled_titular_openday' :
                'mail_cancelled_titular'));
            $context['etiquetas_filas'] = [];
            $context['nombres'] = '';
            $context += [
                'asunto' => $str($own ? 'mail_cancelled_subject_visit' : 'mail_cancelled_subject', $a),
                'preencabezado' => $lead,
                'entradilla' => s($lead) . ($paragraphs ? '<br><br>' . implode('<br><br>', array_map(
                    fn($p) => nl2br(s($p), false),
                    $paragraphs
                )) : ''),
                'entradilla_texto' => $lead . ($paragraphs ? "\n\n" . implode("\n\n", $paragraphs) : ''),
                'boton_principal' => $pageurl ? ['texto' => $str('mail_open_page'), 'url' => $pageurl,
                    'url_texto' => $pageurl] : null,
                // Of their own visit, the words above already say how to ask for another day.
                'nota' => $own || self::says_answer($text, $lang) ? null : $str('mail_note_reply'),
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
            // Whoever is already of the centre was known before coming.
            $known = !empty($household->incentre) || array_filter($full->children, fn($c) => $c->stage === 'enrolled');
            $leadtext = s($str($known ? 'mail_thanks_lead_known' : 'pub_lead_after_short'));
            $go = $pageurl ? $pageurl . ($survey ? '#como-fue' : '#pregunta') : '';
            // How to ask for a place, as the centre wrote it and as their page says it.
            $offers = centres::offers((int) $centre->id);
            $year = '';
            foreach ($full->children as $child) {
                $year = $year ?: (string) ($offers[$child->offerid]->schoolyear ?? '');
            }
            $apply = familypage::apply_context($centre, $year, $lang, time());
            $applytext = trim(($apply['when'] ? trim($apply['when']['line'] . ' ' . $apply['when']['day']) . '. ' : '') .
                $apply['how']);
            // Only where the centre offers it after the visit, or the team already sent it.
            $offered = $asking && households::offered_apply($household, $centre, true);
            $applysection = $offered && $applytext !== '' && $apply['next'] === '' ? [
                'titulo' => $apply['label'],
                'texto' => ($apply['when'] ? '<strong>' . s(trim($apply['when']['line'] . ' ' . $apply['when']['day'])) .
                    '.</strong> ' : '') . nl2br(s($apply['how']), false),
                'texto_plano' => $applytext,
                'enlace' => $apply['go'] ? ['texto' => $apply['go']['label'], 'url' => $apply['go']['url'],
                    'url_texto' => $apply['go']['url']] : ($pageurl ? ['texto' => $str('mail_apply_page'),
                    'url' => $pageurl . '#pedir-plaza', 'url_texto' => $pageurl] : null),
            ] : null;
            // What it all costs, when the centre shows it once they came (costs): a line per thing.
            $lines = costs::rows($centre);
            $plain = fn($r) => $r['what'] . ($r['amount'] !== '' ? ': ' . $r['amount'] : '');
            $costsection = costs::shown($centre, true) ? [
                'titulo' => $str('pub_costs_title'),
                'texto' => implode('<br>', array_map(fn($r) => s($r['what']) . ($r['amount'] !== '' ? ': <strong>' .
                    s($r['amount']) . '</strong>' : ''), $lines)),
                'texto_plano' => implode("\n", array_map($plain, $lines)),
                'enlace' => false,
            ] : null;
            $context['titular'] = $str($tags ? 'pub_thanks' : 'pub_thanks_nochild');
            $context += [
                'asunto' => $str('mail_thanks_subject', $tocentre),
                'preencabezado' => $str($survey ? 'mail_thanks_preheader_survey' : 'mail_thanks_preheader'),
                'entradilla' => $leadtext,
                'entradilla_texto' => html_to_text($leadtext, 0, false),
                'secciones' => array_merge($applysection ? [$applysection] : [], $costsection ? [$costsection] : [], [[
                    'titulo' => $str($survey ? 'pub_howitwent' : 'pub_after_question'),
                    'texto' => s($text),
                    'texto_plano' => $text,
                    'enlace' => $go ? ['texto' => $survey ? $str('mail_thanks_go_survey') : ($host !== '' ?
                        $str('mail_thanks_go_host', $host) : $str('mail_thanks_go_team')), 'url' => $go, 'url_texto' => $go] :
                        null,
                ]]),
                // The question above already says how to write to them.
                'nota' => null,
            ];
        } else if ($kind === 'renewal') {
            // The centre asks whether its children go on next school year; they answer on their page, before the day.
            $year = (string) ($centre->renewalyear ?? '');
            $until = (int) ($centre->renewaluntil ?? 0);
            // Only the children asked about, not a younger one still on its way in.
            $asked = array_column(renewal::children($centre, (int) $household->id), 0);
            $a = (object) ['names' => familypage::said_names($asked ?: array_values($full->children), $lang), 'year' => $year,
                'until' => $until ? publicpage::date_parts($until - DAYSECS, $lang)['daymonth'] : ''];
            $leadkey = $year === '' ? 'mail_renewal_lead_noyear' : ($until ? 'mail_renewal_lead' : 'mail_renewal_lead_nodate');
            $context['titular'] = $year !== '' ? $str('mail_renewal_titular', $year) : $str('mail_renewal_titular_noyear');
            // A question for heading: the names go in the line under it, not after the question mark.
            $context['nombres'] = '';
            $context += [
                'asunto' => $str('mail_renewal_subject' . ($year !== '' ? '' : '_noyear'), (object) [
                    'centre' => publicpage::text($centre->name), 'year' => $year]),
                'preencabezado' => $str('mail_renewal_preheader', $a),
                'entradilla' => s($str($leadkey, $a)),
                'entradilla_texto' => $str($leadkey, $a),
                'boton_principal' => $pageurl ? ['texto' => $str('mail_renewal_go'), 'url' => $pageurl . '#rematricula',
                    'url_texto' => $pageurl] : null,
                'nota' => $str('mail_note_reply'),
            ];
        } else if ($kind === 'request') {
            $pending = visits::pending((int) $household->id);
            $when = $pending ? visits::suits($pending, $lang) : $str('visit_anyday');
            // There is no day yet: nobody is expected.
            $context['titular'] = $str('mail_request_titular');
            $context['nombres'] = '';
            $context += [
                'asunto' => $str('mail_request_subject'),
                'preencabezado' => $str('mail_request_lead', $when),
                'entradilla' => s($str('mail_request_lead', $when)),
                'entradilla_texto' => $str('mail_request_lead', $when),
                'aviso' => ['titulo' => $str('mail_saved_title_request'), 'texto' => $str('mail_saved_text_asked')],
                'boton_principal' => $pageurl ? ['texto' => $str('mail_open_page'), 'url' => $pageurl,
                    'url_texto' => $pageurl] : null,
                'nota' => $str('mail_note_request') . ' ' . $str('mail_note_confirm_act'),
            ];
        } else if ($kind === 'reminder') {
            // How they get in on the day, as the centre wrote it.
            $arrive = trim((string) ($centre->arrive ?? ''));
            $context['titular'] = $str($tags ? 'mail_tomorrow' : 'mail_tomorrow_nochild');
            $a = (object) ['date' => $plain, 'hour' => $parts['hour'], 'host' => s($host), 'hours' => $lower($hours)];
            $leadkey = ($host !== '' ? 'mail_reminder_lead' : 'mail_reminder_lead_team') . ($open ? '_open' : '');
            $a->date = $strong;
            $html = $str($leadkey, $a);
            $a->date = s($plain);
            $context += [
                'asunto' => $str($tags ? 'mail_reminder_subject' : 'mail_reminder_subject_nochild', (object) ['names' => $names,
                    'when' => $when]),
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
                ]] : [], $cantcome ? [$cantcome] : [[
                    'titulo' => $str('mail_cant_come'),
                    'titulo_texto' => $str('mail_cant_come'),
                    'texto' => $phone !== '' ? $str('mail_cant_come_text', $phonelink) : $str('mail_cant_come_text_nophone'),
                    'texto_plano' => $phone !== '' ? $str('mail_cant_come_text', $phone) : $str('mail_cant_come_text_nophone'),
                    'enlace' => !empty($centre->email) ? ['texto' => $str('pub_write_us'), 'url' => 'mailto:' . $centre->email,
                        'url_texto' => $centre->email] : null,
                ]]),
            ];
        } else {
            $context += [
                'asunto' => $str('mail_invite_subject', (object) ['centre' => $tocentre, 'date' => $plain, 'when' => $when]),
                'preencabezado' => $host !== '' && $comingtags ? $str('mail_invite_preheader', (object) ['names' => $comingnames,
                    'host' => $hosts[0]->name, 'hour' => $parts['hour']]) : $ucfirst($plain) . ', ' . $lower($hours) . '.',
                'entradilla' => $lead($strongwhen),
                'entradilla_texto' => html_to_text($lead(s($plainwhen)), 0, false),
                'banda' => $band + ['boton' => $calendar, 'enlace' => $map],
                'anfitriones' => $portraits ? ['titulo' => $str('pub_receives'), 'sub' => familypage::receives_sub($hosts, true, $lang),
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
        // The labels are pictures, which many mail programs do not show until they are asked to: the heading is read whole
        // without them, and the text of each is its name. In plain text, the names go after the comma.
        $context['titular_texto'] = $context['titular'];
        if ($context['etiquetas_filas']) {
            $context['titular'] = preg_replace('/[,،]\s*$/u', '', $context['titular']);
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
     * The invitation to an act, as a person would write it: a few lines, one button and the name of who receives them, in
     * the common mail of the Oksigenia plugins (the mould: the logo and the colours of the centre, the poster of the act
     * when it has one, and the same words as plain text). How they are written to is chosen on the page the link opens.
     *
     * @param string $name The first name of who it goes to, or ''.
     * @param \stdClass $centre
     * @param \stdClass $signup eventname, timestart, timeend, place, hosts and token of the act.
     * @param string $lang
     * @param string $token The private link of the family; '' for a copy to look at.
     * @param string $words What the team adds, in its own words.
     * @param bool $ofcentre Whether the family is already of the centre.
     * @return array subject, html, text, images and what the team sees of it: words (the letter as words alone), url
     *     and go (the button).
     */
    public static function letter(
        string $name,
        \stdClass $centre,
        \stdClass $signup,
        string $lang,
        string $token,
        string $words = '',
        bool $ofcentre = false
    ): array {
        $str = fn(string $id, $a = null) => publiclang::str($id, $lang, $a);
        $plain = fn(string $s) => trim(html_entity_decode($s, ENT_QUOTES, 'UTF-8'));
        $parts = publicpage::date_parts((int) $signup->timestart, $lang);
        $hours = familypage::hours((int) $signup->timestart, (int) $signup->timeend, $lang);
        // Of a talk or a party, until what time too.
        $long = empty($signup->slotid) && ((int) $signup->timeend - (int) $signup->timestart >= 2 * HOURSECS ||
            (($signup->kind ?? '') === 'other' && (int) $signup->timeend > (int) $signup->timestart));
        $when = $long ? \core_text::strtolower(\core_text::substr($hours, 0, 1)) . \core_text::substr($hours, 1) :
            $str('mail_at_hour', $parts['hour']);
        $event = $plain(publicpage::text(visits::shown((string) $signup->eventname, $lang)));
        $centrename = $plain(publicpage::text($centre->name));
        $url = (new \moodle_url('/local/oksigeniaeguahe/j.php', ['t' => $signup->token, 'idioma' => $lang] +
            ($token !== '' ? ['k' => $token, 'de' => 'openinvite'] : [])))->out(false);
        $ids = array_filter(array_map('intval', explode(',', (string) ($signup->hosts ?? ''))));
        $host = $ids ? (centres::hosts((int) $centre->id, $ids)[0] ?? null) : null;

        // As anybody is told: when, who and what; then, for whom and where.
        $before = [$name !== '' ? $str('mail_letter_hello', $name) : $str('mail_letter_hello_noname')];
        $before[] = acts::letter_lead($signup, $centre, $lang, $parts['long'], $when);
        $before[] = implode(' ', array_filter([acts::letter_whom($signup, $centre, $lang),
            acts::letter_place($signup, $centre, $lang)]));
        if (trim((string) ($signup->topic ?? '')) === '' && trim((string) ($signup->about ?? '')) !== '') {
            $before[] = preg_replace('/\R{2,}/', "\n", trim((string) $signup->about));
        }
        foreach (preg_split('/\R{2,}/', trim($words)) as $paragraph) {
            if (trim($paragraph) !== '') {
                $before[] = trim($paragraph);
            }
        }
        $before = array_values(array_filter($before, fn($l) => trim($l) !== ''));
        // What there is to do: sign up, look at it or say they come.
        $free = (int) ($signup->free ?? 0);
        $join = $free ? $str($free === acts::FREE_ASKS ? 'mail_letter_free_asks' : 'mail_letter_free') : '';
        $go = $str($free === acts::FREE_ASKS ? 'mail_letter_come' : ($free ? 'mail_letter_see' : 'mail_letter_go'));
        $after = implode("\n", array_filter([$str('mail_letter_bye'), $host ? $host->name : '', $host ? (string) $host->role : '',
            $centrename, self::ltr(trim((string) ($centre->phone ?? '')), $lang)], fn($l) => trim($l) !== ''));
        // Why it reaches them and how to say no, small, at the end.
        $small = trim(($ofcentre ? '' : $str('mail_letter_why', $plain(acts::by_centre($centre, $lang))) . ' ') .
            (!empty($centre->email) && validate_email($centre->email) ? $str('mail_letter_stop') : ''));

        // The letter as words alone: what the team reads on the page of the act before inviting.
        $words = implode("\n\n", array_filter(array_merge($before, [($join !== '' ? $join : $go . ':') . "\n" . $url, $after,
            $small]))) . "\n";

        // The same words in the common mail: the greeting, the lines, the poster, the button and who signs.
        $blocks = [];
        foreach (array_slice($before, 1) as $paragraph) {
            foreach (preg_split('/\R/', $paragraph) as $line) {
                if (trim($line) !== '') {
                    $blocks[] = ['paragraph' => trim($line)];
                }
            }
        }
        if ($join !== '') {
            $blocks[] = ['paragraph' => $join];
        }
        $blocks[] = ['action' => ['actionurl' => $url, 'actiontext' => $go]];
        $hero = false;
        $poster = !empty($signup->eventid) ? acts::poster_file((int) $signup->eventid) : null;
        if ($poster && ($picture = commonmail::picture($poster->get_content()))) {
            $alt = $plain(publicpage::text((string) ($signup->posteralt ?? ''))) ?: $event;
            if ($picture['wide']) {
                // A poster wider than tall across the top, over the band; a standing one after the button, smaller: on a
                // phone it would leave the button out of the first screen.
                $hero = ['bytes' => $picture['bytes'], 'alt' => $alt];
            } else {
                $blocks[] = ['picture' => ['picturebytes' => $picture['bytes'], 'picturealt' => $alt,
                    'picturewidth' => commonmail::STANDING]];
            }
        }
        $whatsapp = centres::whatsapp($centre, $lang);
        $contact = implode(' · ', array_filter([self::ltr(phone::display(trim((string) ($centre->phone ?? ''))), $lang),
            $whatsapp && !$whatsapp['same'] ? 'WhatsApp ' . self::ltr($whatsapp['text'], $lang) : ''], fn($l) => $l !== ''));
        $subject = acts::letter_subject($signup, $lang, $parts);
        [$html, $text, $images] = commonmail::make($centre, $lang, [
            'subject' => $subject,
            'preheader' => implode(' ', array_filter([$plain(acts::letter_whom($signup, $centre, $lang)),
                $plain(acts::letter_place($signup, $centre, $lang))])),
            'heading' => $event,
            'hello' => $before[0],
            'blocks' => $blocks,
            'hero' => $hero,
            'signature' => ['signoff' => $str('mail_letter_bye'), 'signer' => $host ? $host->name : $centrename,
                'signrole' => $host ? (string) $host->role : ''],
            'contact' => $contact,
            'reason' => $small,
        ]);
        return [$subject, $html, $text, $images, ['words' => $words, 'url' => $url, 'go' => $go]];
    }

    /**
     * A phone in a letter, read from left to right also inside Arabic.
     *
     * @param string $phone
     * @param string $lang
     * @return string
     */
    public static function ltr(string $phone, string $lang): string {
        return $phone !== '' && lists::dir($lang) === 'rtl' ? "\u{2066}" . $phone . "\u{2069}" : $phone;
    }

    /**
     * Whether some words of the team already say that the mail can be answered.
     *
     * @param string $text
     * @param string $lang
     * @return bool
     */
    protected static function says_answer(string $text, string $lang): bool {
        $mail = ['es' => 'est[ea] (correo|mensaje)', 'en' => 'this (e-?mail|message)', 'pt' => 'est[ea] (e-?mail|mensagem)',
            'pt_br' => 'est[ea] (e-?mail|mensagem)', 'ar' => 'هذا البريد|هذه الرسالة'][$lang] ?? '';
        return $mail !== '' && preg_match('/' . $mail . '/iu', $text);
    }

    /**
     * The first name of the adult a family is written to, as it is said: never in capitals, never an address.
     *
     * @param \stdClass $household
     * @param string $to The address it goes to, when it is not the usual one.
     * @return string Empty when there is none to say.
     */
    public static function first_name(\stdClass $household, string $to = ''): string {
        $email = $to !== '' ? $to : households::recipient($household);
        foreach (households::full($household)->adults as $adult) {
            // Without an address to write to (they left only a phone), the first one.
            if ($email !== '' && \core_text::strtolower((string) $adult->email) !== \core_text::strtolower($email)) {
                continue;
            }
            return greeting::name((string) $adult->firstname, trim((string) $adult->lastname) !== '');
        }
        return '';
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
        // A family that asked not to be written to gets nothing, but the answer to what it did after asking.
        if (!writeby::may_send($household, $kind)) {
            notices::log($household, $kind, $signup, 'email', 'skipped', 'silent');
            return false;
        }
        // A family already of the centre answers to the office that looks after it, when the centre has one.
        $centre = centres::for_family($centre, $household, $kind);
        // The same notice on the phones the family joined with, when the site has Marandu and they did not take them out.
        // Of an act that is not a visit, the phones have no words yet: theirs are of a visit. It goes by email.
        $act = actmail::act($kind, $signup, $centre) !== null;
        if (in_array($kind, ['change', 'cancelled'], true)) {
            // What is still waiting for the phones speaks of the day it was.
            messaging::withdraw((int) $household->id, ['confirmation', 'reminder', 'invitation', 'change']);
        }
        $phones = $kind !== 'reply' && !$act ? messaging::notify($kind, $household, $centre, $signup, $lang, $text) : 0;
        if ($phones) {
            notices::log($household, $kind, $signup, 'phone', notices::onphone(), notices::pieces($phones));
        }
        // By email only if they marked it (or never chose); else, while no phone they marked is joined, and what they must
        // know.
        if (!writeby::email_ok($household, $centre, $kind, $act)) {
            if (!$phones) {
                notices::log($household, $kind, $signup, 'email', 'skipped', notices::why($household));
            }
            return $phones > 0;
        }
        // To whoever let the centre write by email, never to an address someone else added.
        $email = $to === null ? households::recipient($household) : (string) $to;
        if ($email === '') {
            if (!$phones) {
                notices::log($household, $kind, $signup, 'email', 'skipped', 'noemail');
            }
            return false;
        }
        // Every mail carries how to choose the way they are written to, with its own link if the mail has none.
        $choose = $token === null && $kind !== 'writeby' ? households::new_link($household, 'writeby') : null;
        [$subject, $html, $plain, $images] = self::build(
            $kind,
            $household,
            $centre,
            $signup,
            $lang,
            $token,
            $text,
            $choose,
            $to === null ? '' : $email
        );
        $link = (string) ($token ?? $choose ?? '');
        // The letter of an invitation says how to stop in its own words; the header that says it too, which sends it to
        // «Promotions», only where the site wants it (letterunsubscribe). And as plain text only, with letterplain.
        $letter = $kind === 'openinvite' || $act;
        $unsubscribe = $link !== '' && (!$letter || get_config('local_oksigeniaeguahe', 'letterunsubscribe')) ?
            writeby::unsubscribe_url($link, $lang) : '';
        if ($letter && get_config('local_oksigeniaeguahe', 'letterplain')) {
            $html = '';
        }
        if (!mail::send_rich($centre, $email, $subject, $html, $plain, $images, $unsubscribe)) {
            notices::log($household, $kind, $signup, 'email', 'failed', 'mail');
            return false;
        }
        notices::log($household, $kind, $signup, 'email', 'sent');
        seen::mail_sent($household, $kind);
        return true;
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
        $event = (object) (['name' => visits::shown((string) $event->name, $lang)] + (array) $event);
        $tags = array_values(array_filter(array_map(
            [familypage::class, 'label_name'],
            array_values(households::full($household)->children)
        ), fn($n) => $n !== ''));
        $ids = array_filter(array_map('intval', explode(',', (string) ($signup->hosts ?? ''))));
        $hosts = $ids ? centres::hosts((int) $centre->id, $ids) : [];
        $link = (new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token]))->out(false);
        if ($event->kind === 'other') {
            // A talk, a workshop, a party: how many come and where to say they do not, and nothing of a visit.
            $when = events::when($event, (int) $signup->slotid);
            $answer = actmail::answer_url($event, $token, $lang, '', true);
            return events::ics($event, $centre, implode("\n", [
                publiclang::count('pub_people_one', 'pub_people_count', (int) $signup->people, $lang) . '.',
                $str('ics_act_answer', $answer),
            ]), $when, ['uid' => 'visita-' . $event->id . '-familia-' . $household->id, 'url' => $answer,
                'alarm' => $str('ics_alarm', (object) ['hour' => publicpage::date_parts((int) $when->timestart, $lang)['hour'],
                'centre' => publicpage::text($centre->name)])]);
        }
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
                'centre' => publicpage::text($centre->name)]),
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
