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
 * The families that go, and those that never were: «me equivoqué», «buscaba empleo», «mi hija me cogió el móvil», «no me
 * interesa», «hemos elegido otro centro». Said with one touch, by who, when and why, instead of in a note nobody counts. What
 * was not a family is out of the numbers of who is attracted and is counted apart, by channel: it says how many false
 * contacts each ad brings. What was and goes stays in them, with its reason, its channel and how far it had come. And who does
 * not want anything gets nothing more. A person can always take it back; and one that writes again is answered.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class outs {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var string[] They were not a family that looks for a centre. */
    const NEVER = ['mistake', 'job', 'someoneelse', 'elsewhere', 'spam'];

    /** @var string[] They were, and they go. */
    const LEFT = ['notinterested', 'othercentre', 'far', 'cost', 'noplace', 'noanswer', 'another'];

    /** @var string[] What stops: every message, the invitations to events, or nothing. */
    const STOPS = ['all', 'invites', 'none'];

    /** @var array What each reason stops, unless a person says something else. */
    const STOP = ['notinterested' => 'all', 'othercentre' => 'invites', 'far' => 'invites', 'cost' => 'invites',
        'noplace' => 'none', 'noanswer' => 'none', 'another' => 'invites'];

    /** @var array The reason the way of each child is closed with. */
    const CLOSED = ['othercentre' => 'othercentre', 'cost' => 'cost', 'noplace' => 'noplace', 'noanswer' => 'noanswer'];

    /** @var int How far back the notes are read to propose a reason. */
    const NOTES = 400 * DAYSECS;

    /**
     * What a reason stops by itself.
     *
     * @param string $reason
     * @return string One of STOPS.
     */
    public static function stop(string $reason): string {
        return in_array($reason, self::NEVER, true) ? 'all' : (self::STOP[$reason] ?? 'none');
    }

    /**
     * Whether a family said it goes, or never was one.
     *
     * @param \stdClass $household
     * @return bool
     */
    public static function is_out(\stdClass $household): bool {
        return trim((string) ($household->outreason ?? '')) !== '';
    }

    /**
     * A family goes, or never was one: the way of its children is closed, nobody waits for it nor has anything promised, what
     * it had ahead is called off without telling it anything, and what the reason stops, stops.
     *
     * @param \stdClass $household Changed in place.
     * @param \stdClass $centre
     * @param string $reason Of NEVER or LEFT.
     * @param string $note What they said, in a line.
     * @param string $stops One of STOPS; empty, what the reason stops.
     * @param int $userid
     * @param int $now
     * @return bool False when it cannot be: it has a child enrolled, or it was already out.
     */
    public static function set(
        \stdClass $household,
        \stdClass $centre,
        string $reason,
        string $note,
        string $stops,
        int $userid,
        int $now = 0
    ): bool {
        global $DB;
        $now = $now ?: time();
        if (!in_array($reason, array_merge(self::NEVER, self::LEFT), true) || self::is_out($household)) {
            return false;
        }
        $full = households::full($household);
        $enrolled = array_filter($full->children, fn($k) => $k->stage === 'enrolled' && !(int) ($k->lefttime ?? 0));
        if ($enrolled || (int) ($household->roster ?? 0)) {
            // A family of the centre does not «go» from admissions: that is «Ya no están».
            return false;
        }
        $stops = in_array($stops, self::STOPS, true) ? $stops : self::stop($reason);
        $was = ['waitingsince' => (int) $household->waitingsince, 'waitingdayonly' => (int) ($household->waitingdayonly ?? 0),
            'guest' => (int) ($household->guest ?? 0), 'noinvites' => (int) ($household->noinvites ?? 0),
            'writeby' => trim((string) ($household->writeby ?? '')), 'email' => familymail::may_email($household), 'children' => []];
        $step = 1;
        foreach ($full->children as $child) {
            if ($child->stage === 'closed' || ($child->stage === 'enrolled' && (int) ($child->lefttime ?? 0))) {
                // One that left already did.
                continue;
            }
            $was['children'][(int) $child->id] = [$child->stage, (int) $child->stagetime];
            $step = max($step, min(5, lists::STAGE_STEP[$child->stage] ?? 1));
        }
        foreach ($full->signups as $signup) {
            $step = max($step, !empty($signup->attended) && !actmail::is($signup) ? 2 : 1);
        }
        $transaction = $DB->start_delegated_transaction();
        foreach ($full->children as $child) {
            if (isset($was['children'][(int) $child->id])) {
                households::set_stage($child, 'closed', self::CLOSED[$reason] ?? 'other');
            }
        }
        foreach ($DB->get_records(attend::PROMISES, ['householdid' => $household->id, 'timedone' => 0]) as $promise) {
            attend::done($household, (int) $promise->id, $userid);
        }
        // What it had ahead, and what it asked for and has no day (a visit called off gives it back).
        $ahead = [];
        foreach ($full->signups as $signup) {
            if ((int) $signup->timestart <= $now || !empty($signup->attended)) {
                continue;
            }
            $event = $DB->get_record(events::T, ['id' => $signup->eventid]);
            if ($event && (int) $event->householdid === (int) $household->id) {
                appointments::off($household, $centre, (int) $event->id, '', $userid, false, $now);
            } else if ($event) {
                events::unsign($event, $household, get_string('out_unsigned', self::C, format_string($event->name)), false);
            }
            $ahead[] = format_string((string) $signup->eventname);
        }
        $DB->set_field(visits::REQUESTS, 'status', 'withdrawn', ['householdid' => $household->id, 'status' => 'pending']);
        if ($stops === 'all') {
            writeby::set($household, [], 'team', $userid, $now);
        } else if ($stops === 'invites') {
            invites::wanted($household, false, 'team', $userid, $now);
        }
        $never = in_array($reason, self::NEVER, true);
        $note = \core_text::substr(trim(clean_param($note, PARAM_TEXT)), 0, 255);
        $changes = ['id' => $household->id, 'outreason' => $reason, 'outtime' => $now, 'outuserid' => $userid, 'outnote' => $note,
            'outstep' => $step, 'outstops' => $stops, 'outwas' => json_encode($was), 'waitingsince' => 0, 'waitingdayonly' => 0,
            'timemodified' => $now] + ($never ? ['guest' => 1] : []);
        $DB->update_record(households::T, (object) $changes);
        foreach (array_diff_key($changes, ['id' => 1]) as $field => $value) {
            $household->$field = $value;
        }
        $lang = (string) ($centre->lang ?? '') ?: current_language();
        $strings = get_string_manager();
        households::note($household, $userid, $strings->get_string($never ? 'out_noted_never' : 'out_noted_left', self::C, (object) [
            'reason' => $strings->get_string('out_' . $reason, self::C, null, $lang), 'note' => $note !== '' ? ' «' . $note . '»' : '',
            'stops' => $strings->get_string('out_stops_' . $stops, self::C, null, $lang)], $lang));
        $transaction->allow_commit();
        who::reset();
        \cache::make(self::C, 'outs')->delete('c' . (int) $centre->id);
        return true;
    }

    /**
     * It was not so, or they want to go on: everything as it was, but what they had ahead, which a person gives again.
     *
     * @param \stdClass $household Changed in place.
     * @param \stdClass $centre
     * @param int $userid
     * @param int $now
     * @return bool
     */
    public static function undo(\stdClass $household, \stdClass $centre, int $userid, int $now = 0): bool {
        global $DB;
        $now = $now ?: time();
        if (!self::is_out($household)) {
            return false;
        }
        $was = json_decode((string) ($household->outwas ?? ''), true) ?: [];
        $transaction = $DB->start_delegated_transaction();
        foreach ((array) ($was['children'] ?? []) as $id => $before) {
            $child = $DB->get_record(households::CHILDREN, ['id' => $id, 'householdid' => $household->id]);
            if ($child && $child->stage === 'closed' && in_array($before[0], lists::STAGES, true)) {
                households::set_stage($child, (string) $before[0]);
                $DB->set_field(households::CHILDREN, 'stagetime', (int) $before[1], ['id' => $child->id]);
            }
        }
        // What was stopped with it, only if it is still as it was left.
        if ((string) ($household->outstops ?? '') === 'all' && writeby::silent($household)) {
            $ways = ($was['writeby'] ?? '') === 'none' ? null : array_values(array_filter(explode(',', (string) ($was['writeby'] ?? ''))));
            if ($ways) {
                writeby::set($household, $ways, 'team', $userid, $now);
            } else if ($ways === [] && !empty($was['email'])) {
                writeby::set($household, ['email'], 'team', $userid, $now);
            } else if ($ways === []) {
                $DB->set_field(households::T, 'writeby', null, ['id' => $household->id]);
                $household->writeby = null;
            }
        } else if ((string) ($household->outstops ?? '') === 'invites' && empty($was['noinvites'])) {
            invites::wanted($household, true, 'team', $userid, $now);
        }
        $changes = ['id' => $household->id, 'outreason' => null, 'outtime' => 0, 'outuserid' => 0, 'outnote' => null, 'outstep' => 0,
            'outstops' => null, 'outwas' => null, 'guest' => (int) ($was['guest'] ?? 0), 'timemodified' => $now];
        if (!(int) $household->waitingsince && !empty($was['waitingsince'])) {
            $changes += ['waitingsince' => (int) $was['waitingsince'], 'waitingdayonly' => (int) ($was['waitingdayonly'] ?? 0)];
        }
        $DB->update_record(households::T, (object) $changes);
        foreach (array_diff_key($changes, ['id' => 1]) as $field => $value) {
            $household->$field = $value;
        }
        $lang = (string) ($centre->lang ?? '') ?: current_language();
        households::note($household, $userid, get_string_manager()->get_string('out_noted_undone', self::C, null, $lang));
        $transaction->allow_commit();
        who::reset();
        \cache::make(self::C, 'outs')->delete('c' . (int) $centre->id);
        return true;
    }

    /**
     * A family that had gone signs up or writes by a form again: it is back, by itself. A message alone does not: a person
     * reads it and says.
     *
     * @param \stdClass $household Changed in place.
     * @param \stdClass $centre
     */
    public static function back(\stdClass $household, \stdClass $centre): void {
        if (self::is_out($household)) {
            self::undo($household, $centre, 0);
        }
    }

    /**
     * What the notes of the team of a family suggest it said, for a person to say: the newest one that does.
     *
     * @param \stdClass[] $contacts Its history.
     * @param int $now
     * @return array|null reason, text (the note, cut) and time
     */
    public static function proposal(array $contacts, int $now): ?array {
        $patterns = self::patterns();
        usort($contacts, fn($a, $b) => (int) $b->timecreated <=> (int) $a->timecreated);
        foreach ($contacts as $contact) {
            if ($contact->direction === 'out' || (int) $contact->timecreated < $now - self::NOTES) {
                continue;
            }
            $body = trim((string) $contact->body);
            $plain = recognise::plain($body);
            foreach ($patterns as [$expression, $reason]) {
                if ($plain !== '' && preg_match($expression, $plain, $m, PREG_OFFSET_CAPTURE)) {
                    return ['reason' => $reason, 'text' => shorten_text($body, 140), 'time' => (int) $contact->timecreated];
                }
            }
        }
        return null;
    }

    /**
     * The words that say it (data/out-words.json), as expressions on a text in plain letters.
     *
     * @return array[] expression and reason
     */
    protected static function patterns(): array {
        static $patterns = null;
        if ($patterns === null) {
            $patterns = [];
            $said = json_decode((string) file_get_contents(__DIR__ . '/../../data/out-words.json'), true);
            foreach (is_array($said) ? ($said['words'] ?? []) : [] as $one) {
                if (in_array($one['reason'] ?? '', array_merge(self::NEVER, self::LEFT), true)) {
                    $patterns[] = ['~' . str_replace('~', '\~', (string) $one['re']) . '~u', (string) $one['reason']];
                }
            }
        }
        return $patterns;
    }

    /**
     * The families of a centre whose notes say it and nobody has said anything about.
     *
     * @param int $centreid
     * @param int $now
     * @return array household id => proposal
     */
    public static function proposals(int $centreid, int $now): array {
        global $DB;
        $cache = \cache::make(self::C, 'outs');
        $kept = $cache->get('c' . $centreid);
        if (is_array($kept) && $kept[0] > $now - 10 * MINSECS) {
            return $kept[1];
        }
        $rows = $DB->get_records_sql(
            'SELECT k.id, k.householdid, k.direction, k.body, k.timecreated
               FROM {' . households::CONTACTS . '} k
               JOIN {' . households::T . "} h ON h.id = k.householdid
              WHERE k.centreid = :centreid AND k.direction <> 'out' AND k.timecreated >= :since AND h.guest = 0 AND h.roster = 0
                    AND COALESCE(h.outreason, '') = '' AND h.outasked = 0
                    AND NOT EXISTS (SELECT 1 FROM {" . households::CHILDREN . "} c
                                     WHERE c.householdid = h.id AND c.stage IN ('enrolled', 'admitted', 'application', 'closed'))",
            ['centreid' => $centreid, 'since' => $now - self::NOTES]
        );
        $by = [];
        foreach ($rows as $row) {
            $by[(int) $row->householdid][] = $row;
        }
        $found = array_filter(array_map(fn($contacts) => self::proposal($contacts, $now), $by));
        $cache->set('c' . $centreid, [$now, $found]);
        return $found;
    }

    /**
     * «No es eso»: what the notes suggest is not asked again.
     *
     * @param \stdClass $household
     */
    public static function not_so(\stdClass $household): void {
        global $DB;
        $DB->set_field(households::T, 'outasked', time(), ['id' => $household->id]);
        \cache::make(self::C, 'outs')->delete('c' . (int) $household->centreid);
    }

    /**
     * What the file shows: what was said, with who and what they no longer get, and taking it back; or the reasons, each with
     * what it stops, and what the notes suggest.
     *
     * @param \stdClass $household
     * @param \stdClass $full
     * @param \stdClass[] $contacts
     * @param bool $canact
     * @param int $now
     * @return array|bool
     */
    public static function context(\stdClass $household, \stdClass $full, array $contacts, bool $canact, int $now) {
        global $DB;
        $c = self::C;
        if (!self::is_out($household)) {
            $enrolled = array_filter($full->children, fn($k) => $k->stage === 'enrolled' && !(int) ($k->lefttime ?? 0));
            // Nor of a family that was at the centre and left it: that is said already.
            if (!$canact || $enrolled || (int) ($household->roster ?? 0) || settled::gone($full->children)) {
                return false;
            }
        }
        $base = ['action' => (new \moodle_url('/local/oksigeniaeguahe/household.php', ['id' => $household->id]))->out(false),
            'sesskey' => sesskey(), 'canact' => $canact];
        if (self::is_out($household)) {
            $never = in_array($household->outreason, self::NEVER, true);
            $user = (int) $household->outuserid ? $DB->get_record('user', ['id' => (int) $household->outuserid]) : null;
            return $base + ['is' => true, 'never' => $never, 'title' => get_string($never ? 'out_is_never' : 'out_is_left', $c),
                'reason' => get_string('out_' . $household->outreason, $c), 'note' => trim((string) $household->outnote),
                'when' => userdate((int) $household->outtime, get_string('strftimedate', 'langconfig')) .
                    ($user ? ' · ' . fullname($user) : ''),
                'stops' => get_string('out_stops_' . ($household->outstops ?: 'none'), $c)];
        }
        // Each reason under what it stops, so that is said once.
        $group = function (string $key, array $reasons) use ($c): array {
            $by = [];
            foreach ($reasons as $reason) {
                $by[self::stop($reason)][] = ['value' => $reason, 'name' => get_string('out_' . $reason, $c)];
            }
            $stops = [];
            foreach (['invites', 'all', 'none'] as $stop) {
                if (!empty($by[$stop])) {
                    $stops[] = ['name' => get_string('out_stops_' . $stop, $c), 'reasons' => $by[$stop]];
                }
            }
            return ['key' => $key, 'name' => get_string('out_group_' . $key, $c), 'stops' => $stops];
        };
        $proposal = (int) ($household->outasked ?? 0) ? null : self::proposal($contacts, $now);
        $ahead = array_filter($full->signups, fn($s) => (int) $s->timestart > $now && empty($s->attended));
        return $base + ['is' => false, 'groups' => [$group('left', self::LEFT), $group('never', self::NEVER)],
            'proposal' => $proposal ? ['reason' => $proposal['reason'], 'name' => get_string('out_' . $proposal['reason'], $c),
                'text' => $proposal['text'], 'when' => userdate($proposal['time'], get_string('strftimedateshort', 'langconfig')),
                'stops' => get_string('out_stops_' . self::stop($proposal['reason']), $c)] : false,
            'ahead' => $ahead ? get_string('out_ahead', $c, implode(', ', array_map(
                fn($s) => format_string($s->eventname) . ' (' . userdate((int) $s->timestart, get_string('strftimedateshort', 'langconfig')) . ')',
                $ahead
            ))) : ''];
    }

    /**
     * The families of a campaign that went, and the contacts that were not families: by reason, with the channel they came by
     * and how far they had come. A family whose children a person closed one by one, with their reason, went too.
     *
     * @param \stdClass $centre
     * @param array[] $families Of facts, with the closed ones.
     * @param int $since When the campaign started.
     * @param int $until When it ended; 0, under way.
     * @return array left (total, of, rows: reason, name, n, steps, channels; bychannel: name, n and of), never (total, rows:
     *     reason, name, n; channels: name and n)
     */
    public static function figures(\stdClass $centre, array $families, int $since, int $until = 0): array {
        global $DB;
        $c = self::C;
        $out = ['left' => ['total' => 0, 'of' => count($families), 'rows' => [], 'bychannel' => []],
            'never' => ['total' => 0, 'rows' => [], 'channels' => []]];
        $came = [];
        $count = [];
        $gone = [];
        foreach ($families as $family) {
            $channel = (string) ($family['voices']['channel'] ?? '');
            $came[$channel] = ($came[$channel] ?? 0) + 1;
            if (empty($family['closed'])) {
                continue;
            }
            $reason = (string) ($family['why'] ?: 'another');
            $count[$reason] = $count[$reason] ?? ['n' => 0, 'channels' => [], 'steps' => []];
            $count[$reason]['n']++;
            $count[$reason]['channels'][$channel] = ($count[$reason]['channels'][$channel] ?? 0) + 1;
            $step = max(1, min(5, (int) $family['step']));
            $count[$reason]['steps'][$step] = ($count[$reason]['steps'][$step] ?? 0) + 1;
            $gone[$channel] = ($gone[$channel] ?? 0) + 1;
            $out['left']['total']++;
        }
        uksort($count, fn($a, $b) => [$count[$b]['n'], $a] <=> [$count[$a]['n'], $b]);
        $list = fn(array $counts) => array_map(fn($name, $n) => ['name' => (string) $name, 'n' => $n], array_keys($counts), $counts);
        foreach ($count as $reason => $one) {
            arsort($one['channels']);
            $one['steps'] = $one['steps'] ?? [];
            ksort($one['steps']);
            $out['left']['rows'][] = ['reason' => $reason, 'name' => get_string('out_' . $reason, $c), 'n' => $one['n'],
                'channels' => $list($one['channels']),
                'steps' => array_map(
                    fn($step, $n) => ['name' => get_string('out_step_' . $step, $c), 'n' => $n],
                    array_keys($one['steps']),
                    $one['steps']
                )];
        }
        // Of those that came by each channel, how many went: a channel is also what it loses.
        arsort($gone);
        foreach ($gone as $channel => $n) {
            if ((string) $channel !== '') {
                $out['left']['bychannel'][] = ['name' => (string) $channel, 'n' => $n, 'of' => $came[$channel]];
            }
        }
        $never = [];
        $channels = [];
        $rows = $DB->get_records_select(
            households::T,
            'centreid = ? AND outreason IS NOT NULL AND timecreated >= ?' . ($until ? ' AND timecreated < ?' : ''),
            array_merge([$centre->id, $since], $until ? [$until] : []),
            'id ASC'
        );
        foreach ($rows as $household) {
            if (!in_array($household->outreason, self::NEVER, true)) {
                continue;
            }
            $never[$household->outreason] = ($never[$household->outreason] ?? 0) + 1;
            $name = arrival::channel_name(arrival::channel($household, $centre));
            $channels[$name] = ($channels[$name] ?? 0) + 1;
        }
        arsort($never);
        arsort($channels);
        $out['never'] = ['total' => array_sum($never),
            'rows' => array_map(fn($r, $n) => ['reason' => $r, 'name' => get_string('out_' . $r, $c), 'n' => $n], array_keys($never), $never),
            'channels' => $list($channels)];
        return $out;
    }

    /**
     * What Numbers and the reports show of it.
     *
     * @param \stdClass $centre
     * @param array[] $families
     * @param int $since
     * @param int $until
     * @return array|null left, never, channels (sentences), rows (name, n, channels and steps, in words), has and def; null
     *     without families
     */
    public static function summary(\stdClass $centre, array $families, int $since, int $until = 0): ?array {
        $c = self::C;
        $figures = self::figures($centre, $families, $since, $until);
        $left = $figures['left'];
        $never = $figures['never'];
        if (!$left['of'] && !$never['total']) {
            return null;
        }
        $words = fn(array $list) => implode(' · ', array_map(fn($one) => $one['name'] . ': ' . $one['n'], array_filter($list, fn($o) => $o['name'] !== '')));
        $rows = array_map(fn($row) => ['name' => $row['name'], 'n' => $row['n'], 'channels' => $words($row['channels']),
            'steps' => $words($row['steps'])], $left['rows']);
        $notfamilies = array_map(fn($row) => ['name' => $row['name'], 'n' => $row['n']], $never['rows']);
        $bychannel = array_map(fn($row) => get_string('outs_channel', $c, (object) ($row + [
            'share' => panel_metrics::share($row['n'], $row['of'], true)])), $left['bychannel']);
        $one = fn(int $n) => $n === 1 ? '_one' : '';
        return [
            'left' => !$left['total'] ? get_string('outs_left_none' . $one($left['of']), $c, $left['of']) : get_string(
                'outs_left' . $one($left['total']),
                $c,
                (object) ['of' => $left['of'], 'n' => $left['total'],
                'share' => panel_metrics::share($left['total'], $left['of'])]
            ),
            'rows' => $rows, 'hasrows' => (bool) $rows,
            'bychannel' => $bychannel ? get_string('outs_bychannel', $c, implode(' · ', $bychannel)) : '',
            'never' => $never['total'] ? get_string('outs_never' . $one($never['total']), $c, $never['total']) : '',
            'neverrows' => $notfamilies,
            'neverchannels' => $never['channels'] ? get_string('outs_never_channels', $c, $words($never['channels'])) : '',
            'def' => get_string('outs_def', $c),
            'raw' => ['families of the campaign' => $left['of'], 'of them that went' => $left['total'],
                'that went, by reason' => array_map(fn($r) => ['reason' => $r['name'], 'families' => $r['n'],
                    'came by' => $r['channels'], 'went' => $r['steps']], $rows),
                'that went, of those that came by each channel' => $bychannel,
                'contacts that were not families, in no other number' => $never['total'],
                'those, by reason' => array_map(fn($r) => ['reason' => $r['name'], 'contacts' => $r['n']], $never['rows']),
                'those, by channel' => $words($never['channels'])],
        ];
    }
}
