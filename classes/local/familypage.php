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
 * What the page of a family shows (DESIGN.md of Gaudí): the name used at home as the heading, written by hand, and the
 * backpack on its hook; their visit in the colour of the centre; the people who will receive them; what they told us
 * and their data. After the visit, what comes next. In plain words, without the words of the trade.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class familypage {
    /** @var string[] Strings the template uses. */
    const STRINGS = ['pub_your_turn', 'pub_add_calendar', 'pub_how_get', 'pub_band_next', 'pub_receives', 'pub_receives_sub',
        'pub_receives_team', 'pub_told', 'pub_who_comes', 'pub_contact', 'pub_data', 'pub_data_hint', 'pub_data_download',
        'pub_data_erase', 'pub_if_you_wish', 'pub_drawing_close'];

    /**
     * Names joined as people say them: «Leo», «Leo y Noa», «Leo, Noa y Gael».
     *
     * @param string[] $names
     * @param string $lang
     * @return string
     */
    public static function join(array $names, string $lang): string {
        $names = array_values($names);
        if (count($names) < 2) {
            return $names[0] ?? '';
        }
        return implode(', ', array_slice($names, 0, -1)) . ' ' . publiclang::str('pub_and', $lang) . ' ' . end($names);
    }

    /**
     * The name on the label of a child: the one used at home, else the first of the name.
     *
     * @param \stdClass $child
     * @return string
     */
    public static function label_name(\stdClass $child): string {
        $tag = trim((string) ($child->tagname ?? ''));
        return $tag !== '' ? $tag : (preg_split('/\s+/', trim((string) $child->firstname))[0] ?? '');
    }

    /**
     * The hours of a visit: «De 10:00 a 13:00».
     *
     * @param int $start
     * @param int $end
     * @param string $lang
     * @return string
     */
    public static function hours(int $start, int $end, string $lang): string {
        $from = publicpage::date_parts($start, $lang)['hour'];
        return $end > $start ? publiclang::str('pub_hours', $lang, (object) ['from' => $from,
            'to' => publicpage::date_parts($end, $lang)['hour']]) : $from;
    }

    /**
     * Who receives the families, for a template.
     *
     * @param \stdClass[] $hosts From centres::hosts().
     * @return array
     */
    public static function hosts(array $hosts): array {
        return array_map(fn($h) => ['name' => $h->name, 'first' => $h->first, 'role' => $h->role, 'says' => $h->says,
            'photo' => $h->photo ? $h->photo->out(false) : '', 'initials' => $h->initials], $hosts);
    }

    /**
     * The context of the template public_family.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param string $lang
     * @param string $token
     * @param bool $new Whether the family has just signed up.
     * @param array $flags asked: they have just sent what they were left to ask; saved: what they told was kept.
     * @return array
     */
    public static function context(
        \stdClass $household,
        \stdClass $centre,
        string $lang,
        string $token,
        bool $new,
        array $flags = []
    ): array {
        $full = households::full($household);
        $offers = centres::offers((int) $centre->id);
        $link = fn(array $params) => (new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token] + $params))->out(false);
        $ucfirst = fn(string $s) => \core_text::strtoupper(\core_text::substr($s, 0, 1)) . \core_text::substr($s, 1);
        $now = time();
        $children = array_values($full->children);
        $tags = array_values(array_filter(array_map([self::class, 'label_name'], $children), fn($n) => $n !== ''));
        $names = self::join($tags, $lang);
        $upcoming = array_values(array_filter($full->signups, fn($s) => (int) $s->timeend >= $now));
        $pending = $upcoming ? null : visits::pending((int) $household->id);
        $attended = array_values(array_filter($full->signups, fn($s) => !empty($s->attended)));
        // Who receives them: those of the next visit, or after it, those of the last one they came to.
        $visit = $upcoming[0] ?? end($attended) ?: null;
        $ids = $visit ? array_filter(array_map('intval', explode(',', (string) ($visit->hosts ?? '')))) : [];
        $hosts = $ids ? centres::hosts((int) $centre->id, $ids) : [];
        $host = $hosts[0]->first ?? '';
        $greeting = $upcoming || !$attended ? 'pub_wait' : 'pub_thanks';
        // After «Enrolled», the last moment of the page: that is it, who carries them from now on, and it stays still.
        $enrolled = settled::enrolled($children);
        $issettled = $enrolled && !$upcoming;
        if ($issettled) {
            $offer = $offers[$enrolled[0]->offerid] ?? null;
            if (count($enrolled) > 1) {
                $all = self::join(array_map([self::class, 'label_name'], $enrolled), $lang);
                $lead = s(publiclang::str('pub_settled_many', $lang, $all));
            } else if ($offer) {
                $a = (object) ['name' => self::label_name($enrolled[0]), 'course' => format_string($offer->name)];
                $lead = s(publiclang::str('pub_settled_one', $lang, $a));
            } else {
                $lead = s(publiclang::str('pub_settled_nocourse', $lang));
            }
        } else if ($upcoming) {
            $a = (object) ['date' => '<b>' . s(publicpage::date_parts((int) $upcoming[0]->timestart, $lang)['long']) . '</b>',
                'host' => s($host), 'names' => s($names)];
            $key = ($host !== '' ? 'pub_lead_host' : 'pub_lead_team') . (!$tags ? '_nochild' : (count($tags) > 1 ? '_many' : ''));
            $lead = publiclang::str($key, $lang, $a);
        } else if ($pending) {
            $lead = s(publiclang::str('pub_lead_pending', $lang));
        } else if ($attended) {
            // The open question under it already says who answers them, and how soon.
            $lead = s(publiclang::str('pub_lead_after_short', $lang));
        } else {
            $lead = s(publiclang::str('pub_lead_novisit', $lang));
        }
        $visitrow = function (\stdClass $s) use ($lang, $token, $ucfirst, $centre): array {
            $parts = publicpage::date_parts((int) $s->timestart, $lang);
            $name = format_string($s->eventname);
            return [
                'weekday' => $ucfirst($parts['weekday']),
                'daymonth' => $parts['daymonth'],
                'long' => $ucfirst($parts['long']),
                'name' => $name,
                'hours' => self::hours((int) $s->timestart, (int) $s->timeend, $lang),
                'turn' => !empty($s->slotid),
                'place' => trim(implode(' · ', array_unique(array_filter([$s->place ? format_string($s->place) : '',
                    (string) ($centre->address ?? '')])))),
                'people' => (int) $s->people === 1 ? publiclang::str('pub_people_one', $lang) :
                    publiclang::str('pub_people_count', $lang, (int) $s->people),
                'ics' => (new \moodle_url('/local/oksigeniaeguahe/ics.php', ['k' => $token, 'e' => $s->eventid]))->out(false),
                'addlabel' => publiclang::str('pub_add_calendar', $lang) . ': ' . $name,
            ];
        };
        $year = '';
        foreach ($children as $child) {
            $year = $year ?: (string) ($offers[$child->offerid]->schoolyear ?? '');
        }
        // What they answered in the forms of the centre, the last answer to each question.
        $answers = [];
        foreach (forms::answers_of((int) $household->id) as $answer) {
            $answers[$answer->fieldid . ':' . $answer->label] = $answers[$answer->fieldid . ':' . $answer->label] ??
                ['label' => format_string($answer->label), 'value' => (string) $answer->value];
        }
        $answers = array_values($answers);
        // The notices on the phone too, when the site has Marandu and the centre wants them.
        [$joinlinks, $joined] = messaging::join($household, $centre);
        $childrows = [];
        foreach ($children as $child) {
            $offer = $offers[$child->offerid] ?? null;
            $tag = trim((string) ($child->tagname ?? ''));
            $drawing = drawings::file((int) $child->id);
            $childrows[] = [
                'name' => $child->firstname,
                // Their drawing, small, to see it big; and sending it, or another one.
                'drawing' => $drawing ? $link(['dibujo' => $child->id, 't' => $drawing->get_timemodified()]) : '',
                'drawingalt' => publiclang::str('pub_drawing_alt', $lang, $tag !== '' ? $tag : $child->firstname),
                'drawingsend' => $link(['action' => 'drawing', 'nino' => $child->id, 'lang' => $lang]),
                'drawingsendname' => publiclang::str($drawing ? 'pub_drawing_change' : 'pub_drawing_send', $lang),
                // Stories to read together at home, from the library of the centre, by the age of its course.
                'tales' => tales::url($centre, $offer ? (string) $offer->name : '') ?? '',
                'talesname' => publiclang::str('pub_tales', $lang),
                'course' => $offer ? publiclang::str('pub_course_year', $lang, (object) ['course' => format_string($offer->name),
                    'year' => s($offer->schoolyear)]) : publiclang::str('pub_course_unknown', $lang),
                'ontag' => $tag !== '' && $tag !== $child->firstname ? publiclang::str('pub_on_tag', $lang, $tag) : '',
            ];
        }
        $adults = [];
        foreach ($full->adults as $adult) {
            $adults[] = ['name' => trim($adult->firstname . ' ' . $adult->lastname),
                'reach' => array_values(array_filter([(string) $adult->phone, (string) $adult->email]))];
        }
        // The ways they allowed and gave the means for: WhatsApp or a call without a phone is no way.
        $means = ['email' => false, 'whatsapp' => false, 'phone' => false];
        foreach ($full->adults as $adult) {
            $means['email'] = $means['email'] || !empty($adult->email);
            $means['whatsapp'] = $means['phone'] = $means['phone'] || !empty($adult->phone);
        }
        $channels = [];
        foreach (households::permissions((int) $household->id) as $list) {
            foreach ($list as $channel => $granted) {
                if ($granted && !empty($means[$channel])) {
                    $channels[$channel] = publiclang::str('pub_ch_' . $channel, $lang);
                }
            }
        }
        $emailed = false;
        foreach ($full->adults as $adult) {
            $emailed = $emailed || !empty($adult->email);
        }
        $address = trim((string) ($centre->address ?? ''));
        // After the visit: one open question on the wall or, if the centre turned on the survey of that event, the
        // survey in the band with the open question first; never both waiting at once.
        $last = !$upcoming && !$pending ? surveys::last_visit($household, $now) : null;
        $surveyform = $last && surveys::is_open($last, $now) ? surveys::form($last) : null;
        $told = $last ? surveys::answers((int) $last->id, (int) $household->id) : [];
        $by = $joined ? 'pub_by_messages' : (isset($channels['email']) ? 'pub_by_email' : (isset($channels['whatsapp']) ?
            'pub_by_whatsapp' : (isset($channels['phone']) ? 'pub_by_phone' : '')));
        $ask = $last ? self::ask_context($centre, $last, $lang, $token, $told, $by, $flags) : false;
        if ($issettled) {
            $ask = self::settled_ask($household, $centre, $hosts, $lang, $token, $by, $flags);
            $surveyform = null;
        }
        $outcome = $last && $told ? trim((string) ($last->outcome ?? '')) : '';
        $hall = false;
        if (vestibulo::on($centre)) {
            $scene = vestibulo::family($centre, $full, (bool) $attended || $issettled, $now, $token, $lang);
            $hall = ['json' => json_encode($scene, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'base' => (new \moodle_url('/local/oksigeniaeguahe/'))->out(false)];
        }
        return publicpage::strings($lang, self::STRINGS) + publicpage::reach($centre, $lang) + [
            'greeting' => $issettled ? publiclang::str('pub_settled', $lang) :
                publiclang::str($tags ? $greeting : $greeting . '_nochild', $lang),
            'names' => $issettled ? '' : $names,
            // Once they came, the label stuck down, as the team left it at the door.
            'pegs' => publicpage::pegs($tags ?: [''], $new || (bool) $upcoming) + ['came' => !$upcoming &&
                ((bool) $attended || $issettled)],
            'settled' => $issettled ? self::settled_context($household, $full, $centre, $hosts, $lang, $token) : false,
            'lead' => $lead,
            'new' => $new,
            'saved' => publiclang::str($pending ? 'pub_saved_request' : 'pub_saved', $lang),
            'savedline' => publiclang::str('pub_done_keep', $lang),
            'emailed' => $new && $emailed ? publiclang::str('pub_done_emailed', $lang) : '',
            'hasnext' => (bool) $upcoming,
            // The living hall: their hooks wait with their names, and once they came their backpacks hang.
            'hall' => $hall,
            'wallask' => $surveyform ? false : $ask,
            'howitwent' => $surveyform ? self::survey_context($centre, $surveyform, $last, $lang, $token, $told, $ask, $flags) :
                false,
            'outcome' => !$issettled && $outcome !== '' ? ['title' => publiclang::str('pub_outcome', $lang),
                'text' => $outcome] : false,
            'bandlabel' => publiclang::str(count($upcoming) > 1 ? 'pub_band_visits' : 'pub_band_visit', $lang),
            'first' => $upcoming ? $visitrow($upcoming[0]) : null,
            'others' => array_map($visitrow, array_slice($upcoming, 1)),
            'arrive' => trim((string) ($centre->arrive ?? '')),
            'mapurl' => $address !== '' ? 'https://www.openstreetmap.org/search?query=' .
                rawurlencode(format_string($centre->name) . ', ' . $address) : '',
            'nextline' => $pending ? publiclang::str('pub_pending_title', $lang) : ($year !== '' ?
                publiclang::str('pub_next_year', $lang, s($year)) : publiclang::str('pub_next_noyear', $lang)),
            'novisit' => $pending ? publiclang::str('pub_pending_line', $lang, visits::suits($pending, $lang)) :
                publiclang::str($attended ? 'pub_novisit_after' : 'pub_novisit', $lang),
            'bandnext' => publiclang::str($pending ? 'pub_band_visit' : 'pub_band_next', $lang),
            'receivespending' => $pending ? publiclang::str('pub_receives_pending', $lang) : '',
            'hashosts' => (bool) $hosts,
            'hosts' => self::hosts($hosts),
            'hasjoin' => (bool) $joinlinks,
            'joinlinks' => $joinlinks,
            'joined' => $joined,
            'jointitle' => publiclang::str('pub_join_title', $lang),
            'joinlead' => publiclang::str('pub_join_lead', $lang),
            'joinedline' => $joined ? publiclang::str('pub_joined', $lang, messaging::optout_word()) : '',
            'answers' => $answers,
            'hasanswers' => (bool) $answers,
            'answerstitle' => publiclang::str('pub_answers', $lang),
            'haschildren' => (bool) $childrows,
            'children' => $childrows,
            'adults' => $adults,
            'writeby' => $channels ? publiclang::str('pub_write_by', $lang, self::join(array_values($channels), $lang)) : '',
            'downloadurl' => $link(['action' => 'download']),
            'eraseurl' => $link(['action' => 'erase', 'lang' => $lang]),
        ];
    }

    /**
     * The open question after a visit: «What were you left to ask?», with the button that names whoever receives
     * them and how soon they answer; once asked, that it was passed on, and «Anything else?».
     *
     * @param \stdClass $centre
     * @param \stdClass $last The event they came to last, from surveys::last_visit().
     * @param string $lang
     * @param string $token
     * @param array $told What they told after it.
     * @param string $by The string of the way they are answered, or ''.
     * @param array $flags
     * @return array
     */
    protected static function ask_context(
        \stdClass $centre,
        \stdClass $last,
        string $lang,
        string $token,
        array $told,
        string $by,
        array $flags
    ): array {
        return self::question(
            $centre,
            surveys::host($last),
            isset($told[0]),
            'pub_after_question',
            'ask',
            $lang,
            $token,
            $by,
            $flags
        );
    }

    /**
     * The one open question, after a visit or once enrolled: the title, the button with the face of whoever answers,
     * how soon they answer and, once asked, that it was passed on.
     *
     * @param \stdClass $centre
     * @param \stdClass|null $host Whoever answers, from centres::hosts(); null, the team.
     * @param bool $asked Whether they already asked.
     * @param string $title The string of the question.
     * @param string $do The action of the form.
     * @param string $lang
     * @param string $token
     * @param string $by
     * @param array $flags
     * @return array
     */
    protected static function question(
        \stdClass $centre,
        ?\stdClass $host,
        bool $asked,
        string $title,
        string $do,
        string $lang,
        string $token,
        string $by,
        array $flags
    ): array {
        $first = $host ? $host->first : '';
        $days = max(1, (int) ($centre->replydays ?? 2));
        $a = (object) ['host' => $first, 'by' => $by !== '' ? publiclang::str($by, $lang) : '',
            'days' => publiclang::str($days === 1 ? 'pub_reply_day' : 'pub_reply_days', $lang, $days)];
        $replykey = ($host ? 'pub_after_reply_host' : 'pub_after_reply_team') . ($by !== '' ? '' : '_noby');
        return [
            'do' => $do,
            'title' => publiclang::str($asked ? 'pub_after_more' : $title, $lang),
            'help' => publiclang::str('pub_after_help', $lang),
            'sent' => $asked ? publiclang::str($host ? 'pub_after_sent_host' : 'pub_after_sent_team', $lang, $first) : '',
            'justsent' => !empty($flags['asked']),
            'button' => $host ? publiclang::str('pub_after_send_host', $lang, $first) : publiclang::str('pub_after_send', $lang),
            'face' => $host ? ['photo' => $host->photo ? $host->photo->out(false) : '', 'initials' => $host->initials] : false,
            'reply' => publiclang::str($replykey, $lang, $a),
            'action' => (new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'lang' => $lang]))->out(false),
            'sesskey' => sesskey(),
            'sending' => publiclang::str('pub_after_sending', $lang),
        ];
    }

    /**
     * The question once enrolled, «What were you left to ask before the first day?», answered by whoever attends the
     * family while Eguahe still carries it, else by whoever carries them from now on, else by the team.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param array $hosts Who received them at their last visit.
     * @param string $lang
     * @param string $token
     * @param string $by
     * @param array $flags
     * @return array
     */
    protected static function settled_ask(
        \stdClass $household,
        \stdClass $centre,
        array $hosts,
        string $lang,
        string $token,
        string $by,
        array $flags
    ): array {
        $host = null;
        foreach (centres::hosts((int) $centre->id) as $member) {
            if ((int) $member->userid === (int) $household->ownerid) {
                $host = $member;
            }
        }
        $handover = $host ? null : settled::handover($centre, array_values(households::full($household)->children));
        $host = $host ?? ($handover['person'] ?? null) ?? ($hosts[0] ?? null);
        return self::question(
            $centre,
            $host,
            settled::asked((int) $household->id),
            'pub_settled_question',
            'settledask',
            $lang,
            $token,
            $by,
            $flags
        );
    }

    /**
     * The last moment of the page of an enrolled family: who carries them from now on (a person with their face, or a
     * place on the colour of the centre), how the centre writes to them, the offer to tell another family how it went
     * as a sentence with a yes, and the thanks of whoever received them.
     *
     * @param \stdClass $household
     * @param \stdClass $full
     * @param \stdClass $centre
     * @param array $hosts Who received them at their last visit.
     * @param string $lang
     * @param string $token
     * @return array
     */
    protected static function settled_context(
        \stdClass $household,
        \stdClass $full,
        \stdClass $centre,
        array $hosts,
        string $lang,
        string $token
    ): array {
        $handover = settled::handover($centre, array_values($full->children));
        $person = $handover['person'] ?? null;
        $firsts = array_values(array_filter(array_map(fn($a) => trim((string) $a->firstname), $full->adults)));
        $host = $hosts[0] ?? null;
        $volunteered = (int) ($household->volunteer ?? 0) > 0;
        return [
            'person' => $person ? ['label' => publiclang::str('pub_handover_person', $lang), 'name' => $person->name,
                'role' => $person->role, 'photo' => $person->photo ? $person->photo->out(false) : '',
                'initials' => $person->initials] : false,
            'place' => !$person && isset($handover['place']) ? ['label' => publiclang::str('pub_handover_place', $lang),
                'name' => $handover['place']] : false,
            'team' => $handover ? '' : publiclang::str('pub_handover_team', $lang),
            'how' => trim((string) ($centre->handoverhow ?? '')),
            'volunteer' => $volunteered ? false : ['question' => publiclang::str('pub_volunteer_ask', $lang),
                'yes' => publiclang::str('pub_volunteer_yes', $lang), 'sesskey' => sesskey(),
                'action' => (new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'lang' => $lang]))->out(false)],
            'volunteered' => $volunteered ? publiclang::str('pub_volunteer_done', $lang) : '',
            'thanks' => $firsts ? ['text' => publiclang::str('pub_settled_thanks', $lang, self::join($firsts, $lang)),
                'who' => $host ? $host->first : '', 'photo' => $host && $host->photo ? $host->photo->out(false) : '',
                'initials' => $host ? $host->initials : '', 'hashost' => (bool) $host] : false,
        ];
    }

    /**
     * The survey of the visit in the band, «How did it go?»: the open question first, then the questions of the
     * centre, each kept when it is touched, and that the team reads it.
     *
     * @param \stdClass $centre
     * @param \stdClass $entry The survey.
     * @param \stdClass $last The event they came to.
     * @param string $lang
     * @param string $token
     * @param array $told What they told after the visit.
     * @param array $ask The open question, from ask_context().
     * @param array $flags
     * @return array
     */
    protected static function survey_context(
        \stdClass $centre,
        \stdClass $entry,
        \stdClass $last,
        string $lang,
        string $token,
        array $told,
        array $ask,
        array $flags
    ): array {
        $answers = $told;
        unset($answers[0]);
        $fields = forms::context($entry, $lang, (string) ($centre->lang ?: 'es'), $answers, []);
        $questions = array_values(array_filter($fields, fn($f) => !$f['issection']));
        // Done once the questions to choose are answered: the texts are «if you wish» and never hold it back (Gaudí).
        $choose = array_values(array_filter($questions, fn($f) => $f['ischips'] || $f['ismulti']));
        $needed = $choose ?: $questions;
        $answered = count(array_filter($needed, fn($f) => isset($answers[$f['id']])));
        $parts = publicpage::date_parts((int) $last->timeend + surveys::DAYS * DAYSECS, $lang);
        return [
            'label' => publiclang::str('pub_howitwent', $lang),
            'lead' => publiclang::str('pub_howitwent_lead', $lang, count($questions) + 1),
            'reads' => publiclang::str(!empty($ask['face']) ? 'pub_howitwent_reads' : 'pub_howitwent_reads_team', $lang),
            'farewell' => publiclang::str('pub_howitwent_farewell', $lang),
            'changeline' => publiclang::str('pub_howitwent_change', $lang, $parts['daymonth']),
            'ask' => $ask,
            'fields' => $fields,
            'autosave' => true,
            'saved' => !empty($flags['saved']) ? publiclang::str('pub_howitwent_kept', $lang) : '',
            'done' => $needed && $answered === count($needed) ? publiclang::str('pub_howitwent_thanks', $lang) : '',
            'savetext' => publiclang::str('pub_howitwent_save', $lang),
            'savedtext' => publiclang::str('pub_howitwent_saved', $lang),
            'action' => (new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'lang' => $lang]))->out(false),
            'sesskey' => sesskey(),
        ];
    }

    /**
     * The page where a family sends the drawing of one of their children: its photo, cut and straightened in the
     * browser, the one already sent, and taking it away.
     *
     * @param \stdClass $child
     * @param string $lang
     * @param string $token
     * @param bool $failed Whether the photo before did not go in.
     * @return array
     */
    public static function drawing_context(\stdClass $child, string $lang, string $token, bool $failed): array {
        $name = self::label_name($child) ?: (string) $child->firstname;
        $action = new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'lang' => $lang, 'action' => 'drawing',
            'nino' => $child->id]);
        $file = drawings::file((int) $child->id);
        $s = fn(string $id, $a = null) => publiclang::str($id, $lang, $a);
        return [
            'title' => $s('pub_drawing_title', $name),
            'name' => $name,
            'lead' => $s('pub_drawing_lead', $name),
            'current' => $file ? (new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'dibujo' => $child->id,
                't' => $file->get_timemodified()]))->out(false) : '',
            'alt' => $s('pub_drawing_alt', $name),
            'currentline' => $s('pub_drawing_now'),
            'failed' => $failed ? $s('pub_drawing_not_taken') : '',
            'action' => $action->out(false),
            'back' => (new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'lang' => $lang], 'quien-vendria'))
                ->out(false),
            'sesskey' => sesskey(),
            'str' => ['take' => $s('pub_drawing_take'), 'takehelp' => $s('pub_drawing_take_help'),
                'corners' => $s('pub_drawing_corners'), 'save' => $s($file ? 'pub_drawing_replace' : 'pub_drawing_save'),
                'saving' => $s('pub_drawing_saving'), 'nottaken' => $s('pub_drawing_not_taken'),
                'delete' => $s('pub_drawing_delete'), 'shape' => $s('pub_drawing_shape'),
                'portrait' => $s('pub_drawing_portrait'), 'landscape' => $s('pub_drawing_landscape'),
                'turn' => $s('pub_drawing_turn'), 'back' => $s('pub_drawing_back')],
        ];
    }

    /**
     * Keeps the drawing a family sent of one of their children, or takes it away; the team reads it in the history of
     * the family, without it waiting for an answer.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param \stdClass $child
     * @param string|null $bytes The photo they sent, or null to take the drawing away.
     * @return bool Whether it was kept (or taken away).
     */
    public static function drawing_sent(\stdClass $household, \stdClass $centre, \stdClass $child, ?string $bytes): bool {
        if ($bytes === null) {
            drawings::delete((int) $child->id);
            return true;
        }
        if ($bytes === '' || !drawings::save($child, $bytes)) {
            return false;
        }
        $lang = in_array($centre->lang, lists::LANGS, true) ? $centre->lang : 'es';
        $name = self::label_name($child) ?: (string) $child->firstname;
        households::received($household, lists::PAGE, publiclang::str('pub_drawing_sent_note', $lang, $name), false);
        return true;
    }

    /**
     * The context of the template public_erase: the question, with what it does in its buttons.
     *
     * @param \stdClass $household
     * @param \stdClass $centre
     * @param string $lang
     * @param string $token
     * @return array
     */
    public static function erase_context(\stdClass $household, \stdClass $centre, string $lang, string $token): array {
        return publicpage::strings($lang, ['pub_erase_title', 'pub_erase_text', 'pub_erase_yes', 'pub_erase_no']) + [
            'action' => (new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'lang' => $lang]))->out(false),
            'back' => (new \moodle_url('/local/oksigeniaeguahe/f.php', ['k' => $token, 'lang' => $lang]))->out(false),
            'sesskey' => sesskey(),
        ];
    }
}
