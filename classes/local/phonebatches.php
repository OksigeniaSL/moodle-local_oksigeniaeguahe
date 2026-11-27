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
 * The letter of an act by phone in batches: so many families every so often, during the day, to watch what happens between
 * one and the next (who reads it, who answers, the quality Meta gives the number, who asks to stop). Each batch is a send of
 * Marandu, as one of all at once, taken from the families that have the letter by mail and nothing by phone yet, spread over
 * the whole list; whatever already went by phone, in a batch or not, never goes again. It stops by itself when the number
 * stops being green for Meta, when several families of a batch ask to stop, or when most of a batch fails, and goes on only
 * when a person says so.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class phonebatches {
    /** @var string */
    const C = 'local_oksigeniaeguahe';

    /** @var int[] The families of a batch a person picks from. */
    const SIZES = [10, 20, 40, 60, 100];

    /** @var int[] The minutes between batches a person picks from. */
    const EVERY = [30, 60, 120, 240];

    /** @var int The size picked when nobody picks. */
    const SIZE = 20;

    /** @var int The minutes picked when nobody picks. */
    const GAP = 60;

    /** @var int From how many families of a batch asking to stop it stops, at least. */
    const OUTS = 3;

    /** @var float Or from this share of the batch, when it is more. */
    const OUTSHARE = 0.05;

    /**
     * The batches of an act, as they were set.
     *
     * @param int $eventid
     * @return array|null size, every (minutes), userid, next, paused (why: user, quality, optout, failed, off, error; empty,
     *     going), since (when it stopped), checked (the send whose brake a person already let pass), started.
     */
    public static function plan(int $eventid): ?array {
        $plan = json_decode((string) get_config(self::C, 'phoneplan_' . $eventid), true);
        return is_array($plan) && !empty($plan['size']) ? $plan + ['paused' => '', 'since' => 0, 'checked' => 0, 'next' => 0] : null;
    }

    /**
     * Keeps the batches of an act, or forgets them.
     *
     * @param int $eventid
     * @param array|null $plan
     */
    protected static function keep(int $eventid, ?array $plan): void {
        if ($plan === null) {
            unset_config('phoneplan_' . $eventid, self::C);
            return;
        }
        set_config('phoneplan_' . $eventid, json_encode($plan), self::C);
    }

    /**
     * The acts that go by batches now, stopped or not.
     *
     * @return int[]
     */
    public static function acts(): array {
        $out = [];
        foreach ((array) get_config(self::C) as $name => $value) {
            if (preg_match('/^phoneplan_(\d+)$/', (string) $name, $m) && $value !== '') {
                $out[] = (int) $m[1];
            }
        }
        return $out;
    }

    /**
     * Starts the batches of an act: the first goes now if it is daytime, if not when the day starts.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param int $size
     * @param int $every Minutes.
     * @param int $userid Who starts them: the sends are asked in their name.
     * @param int $now
     * @return array phone (families of the first batch, if it went now), status and said, as queue_phone_too().
     */
    public static function start(\stdClass $event, \stdClass $centre, int $size, int $every, int $userid, int $now): array {
        $size = in_array($size, self::SIZES, true) ? $size : self::SIZE;
        $every = in_array($every, self::EVERY, true) ? $every : self::GAP;
        // Whatever went before by phone is no reason to stop the first of these.
        self::keep((int) $event->id, ['size' => $size, 'every' => $every, 'userid' => $userid, 'next' => invites::daytime($now),
            'paused' => '', 'since' => 0, 'checked' => self::last_send((int) $event->id), 'started' => $now]);
        return self::run($event, $centre, $now);
    }

    /**
     * Stops the batches of an act until a person goes on.
     *
     * @param int $eventid
     * @param string $why user, quality, optout, failed, off or error.
     * @param int $now
     */
    public static function pause(int $eventid, string $why, int $now): void {
        $plan = self::plan($eventid);
        if ($plan && $plan['paused'] === '') {
            self::keep($eventid, ['paused' => $why, 'since' => $now] + $plan);
        }
    }

    /**
     * Goes on with the batches of an act: the next goes now if it is daytime, and the batch that stopped them no longer does.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param int $now
     * @return array As run().
     */
    public static function resume(\stdClass $event, \stdClass $centre, int $now): array {
        $plan = self::plan((int) $event->id);
        if (!$plan) {
            return ['phone' => 0, 'status' => '', 'said' => ''];
        }
        self::keep((int) $event->id, ['paused' => '', 'since' => 0, 'next' => invites::daytime($now),
            'checked' => self::last_send((int) $event->id)] + $plan);
        return self::run($event, $centre, $now);
    }

    /**
     * No more batches of an act: what went, went.
     *
     * @param int $eventid
     */
    public static function stop(int $eventid): void {
        self::keep($eventid, null);
    }

    /**
     * The newest send by phone of an act.
     *
     * @param int $eventid
     * @return int
     */
    protected static function last_send(int $eventid): int {
        return invites::last_bulk($eventid);
    }

    /**
     * Every act whose batch is due: by the task, every five minutes.
     *
     * @param int $now
     * @return int How many families the batches that went now had.
     */
    public static function due(int $now): int {
        global $DB;
        $n = 0;
        foreach (self::acts() as $eventid) {
            $event = $DB->get_record(events::T, ['id' => $eventid]) ?: null;
            $centre = $event ? centres::get((int) $event->centreid) : null;
            if (!$event || !$centre) {
                self::stop($eventid);
                continue;
            }
            $n += (int) (self::run($event, $centre, $now)['phone'] ?? 0);
        }
        return $n;
    }

    /**
     * Sends the next batch of an act if it is its time and nothing says to stop.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @param int $now
     * @return array phone, status and said.
     */
    public static function run(\stdClass $event, \stdClass $centre, int $now): array {
        $none = ['phone' => 0, 'status' => '', 'said' => ''];
        $id = (int) $event->id;
        $plan = self::plan($id);
        if (!$plan) {
            return $none;
        }
        if (!invites::fits($event, $now) || !empty($event->cancelled)) {
            // Over, called off or closed: there is nothing to invite to.
            self::stop($id);
            return $none;
        }
        if ($plan['paused'] !== '') {
            return $none;
        }
        $last = self::batches($event, false)[0] ?? null;
        if ($last && (int) $last['bulk'] > (int) $plan['checked']) {
            $brake = self::brake($last);
            if ($brake !== '') {
                self::pause($id, $brake, $now);
                return $none;
            }
        }
        if ($last && $last['pending']) {
            // It waits for whoever approves it: the next counts from when this one goes.
            return $none;
        }
        if (self::next($event, $plan, $last) > $now || invites::daytime($now) !== $now) {
            // Not yet, or it is night: never a batch out of daytime.
            return $none;
        }
        $too = invites::phone_too($event, $centre);
        if (!$too['households']) {
            if ($too['waiting'] || !invites::by_phone($centre)) {
                // The phones of the centre are off, or Marandu cannot write to numbers yet: nothing is lost while it waits.
                self::pause($id, 'off', $now);
            } else {
                self::stop($id);
            }
            return $none;
        }
        $ids = self::spread(array_keys($too['households']), (int) $plan['size']);
        $sent = invites::queue_phone_too($event, $centre, (int) $plan['userid'], $now, $ids);
        if (empty($sent['phone'])) {
            self::pause($id, 'error', $now);
            return $none;
        }
        self::keep($id, ['next' => $now + (int) $plan['every'] * MINSECS] + self::plan($id));
        return $sent;
    }

    /**
     * When the next batch of an act goes: as it was set, never sooner than its gap after the last send by phone (in batches
     * or not), and in daytime.
     *
     * @param \stdClass $event
     * @param array $plan Of plan().
     * @param array|null $last The newest of batches(); null to look it up.
     * @return int
     */
    public static function next(\stdClass $event, array $plan, ?array $last = null): int {
        $last = $last ?? (self::batches($event, false)[0] ?? null);
        $due = max((int) $plan['next'], $last && $last['time'] ? (int) $last['time'] + (int) $plan['every'] * MINSECS : 0);
        return invites::daytime($due);
    }

    /**
     * How many families of an act the letter would still go to by phone.
     *
     * @param \stdClass $event
     * @param \stdClass $centre
     * @return int
     */
    public static function left(\stdClass $event, \stdClass $centre): int {
        return count(invites::phone_too($event, $centre)['households']);
    }

    /**
     * Some of a list, spread over all of it: so a batch has families of every part of it (the first who came and the last).
     *
     * @param int[] $ids
     * @param int $n
     * @return int[]
     */
    public static function spread(array $ids, int $n): array {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        if ($n <= 0) {
            return [];
        }
        if ($n >= count($ids)) {
            return $ids;
        }
        $step = count($ids) / $n;
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = $ids[(int) floor($i * $step)];
        }
        return $out;
    }

    /**
     * Why a batch stops the next: the number is no longer green for Meta (its messages wait for it), several families of it
     * asked to stop, or most of it failed.
     *
     * @param array $batch Of batches().
     * @return string quality, optout, failed or empty.
     */
    public static function brake(array $batch): string {
        if (!empty($batch['quality'])) {
            return 'quality';
        }
        if ((int) $batch['out'] >= max(self::OUTS, (int) ceil((int) $batch['n'] * self::OUTSHARE))) {
            return 'optout';
        }
        if ((int) $batch['n'] >= 4 && 2 * (int) $batch['failed'] >= (int) $batch['n']) {
            return 'failed';
        }
        return '';
    }

    /**
     * Each send by phone of an act, newest first, with what happened to it: those it went to, reached, read, opened the
     * letter from it, said they come or not, wrote back on WhatsApp, or asked to stop; all at once or in batches.
     *
     * @param \stdClass $event
     * @param bool $wrote Whether to ask Marandu who wrote back, one family at a time.
     * @return array Each with bulk, time (when it went; 0 while it waits), pending, n, reached, read, failed, waiting,
     *     quality, opened, yes, no, wrote, out.
     */
    public static function batches(\stdClass $event, bool $wrote = true): array {
        global $DB;
        $rows = $DB->get_records_select(
            invites::T,
            'eventid = ? AND bulkid > 0',
            [$event->id],
            'bulkid DESC, id ASC',
            'id, householdid, bulkid, phonesent, via, reply, replied, phoneout'
        );
        $by = [];
        foreach ($rows as $row) {
            $by[(int) $row->bulkid][] = $row;
        }
        $out = [];
        foreach ($by as $bulk => $list) {
            $state = invites::state($bulk);
            $items = invites::phone_states($bulk);
            $count = fn(array $statuses) => count(array_filter($items, fn($i) => in_array($i['status'], $statuses, true)));
            $sent = array_filter(array_map(fn($r) => (int) $r->phonesent, $list));
            $one = ['bulk' => $bulk, 'time' => $sent ? min($sent) : 0, 'pending' => ($state['status'] ?? '') === 'pending',
                'n' => count($list), 'reached' => $count(['delivered', 'read']), 'read' => $count(['read']),
                'failed' => $count(['failed']), 'waiting' => $count(['queued', 'waiting']),
                'quality' => (bool) array_filter($items, fn($i) => $i['why'] === 'quality'),
                'opened' => count(array_filter($list, fn($r) => (string) $r->via === 'phone')),
                'yes' => count(array_filter($list, fn($r) => (string) $r->reply === 'yes')),
                'no' => count(array_filter($list, fn($r) => (string) $r->reply === 'no')),
                'out' => count(array_filter($list, fn($r) => (int) $r->phoneout > 0)), 'wrote' => 0];
            if ($wrote) {
                foreach ($list as $row) {
                    $one['wrote'] += self::wrote_back((int) $row->householdid) ? 1 : 0;
                }
            }
            $out[] = $one;
        }
        return $out;
    }

    /**
     * Whether a family wrote or answered on WhatsApp since it was written to by permission: its number is joined by itself.
     *
     * @param int $householdid
     * @return bool
     */
    protected static function wrote_back(int $householdid): bool {
        try {
            $ways = method_exists('\local_oksigeniamarandu\api', 'item_channels') ?
                (array) \local_oksigeniamarandu\api::item_channels(messaging::COMPONENT, $householdid) : [];
        } catch (\Throwable $e) {
            return false;
        }
        return !empty($ways['whatsapp']['joined']) && (string) ($ways['whatsapp']['implied'] ?? '') === '';
    }
}
