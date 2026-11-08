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

namespace local_oksigeniaeguahe;

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\panel_channels;
use local_oksigeniaeguahe\local\panel_metrics;
use local_oksigeniaeguahe\local\seen;

/**
 * «Where from, month by month» (0.34.0): each channel with its twelve months and the twelve before, in the three voices,
 * nothing folded into «Others»; what goes up and down; and the mails, without making up what is not counted. With
 * example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(panel_channels::class)]
final class channels_test extends \advanced_testcase {
    /**
     * A family that came at a time, how it heard of the centre, and a message from it by a channel.
     *
     * @param \stdClass $centre
     * @param int $time
     * @param string $heard
     * @param string $net
     * @param string $channel
     * @return \stdClass
     */
    protected function family(\stdClass $centre, int $time, string $heard, string $net, string $channel): \stdClass {
        global $DB;
        $h = households::get(households::create((int) $centre->id, (object) ['lang' => 'es', 'firstchannel' => $channel,
            'heardfrom' => $heard, 'heardnet' => $net]), (int) $centre->id);
        $DB->set_field(households::T, 'timecreated', $time, ['id' => $h->id]);
        households::add_adult($h, (object) ['firstname' => 'Ana', 'lastname' => 'Ejemplo',
            'email' => 'ana' . $h->id . '@example.com']);
        $DB->insert_record(households::CONTACTS, (object) ['centreid' => $centre->id, 'householdid' => $h->id,
            'direction' => 'in', 'channel' => $channel, 'body' => 'Hola', 'userid' => 0, 'timecreated' => $time]);
        return households::get((int) $h->id, (int) $centre->id);
    }

    /**
     * The three voices: a network that starts goes up and keeps its own row, one that is used less goes down, and the
     * months are on one scale for the whole voice.
     */
    public function test_voices(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $centre = centres::get($id);
        $now = time();
        $bounds = panel_channels::bounds($now);
        $this->assertCount(25, $bounds);
        $this->assertLessThanOrEqual($now, $bounds[23]);
        $this->assertGreaterThan($now, $bounds[24]);
        // A year before, four on Facebook in its last three months; this year, one; TikTok starts with three.
        foreach ([9, 10, 10, 11] as $m) {
            $this->family($centre, $bounds[$m] + DAYSECS, 'social', 'Facebook', 'whatsapp');
        }
        $this->family($centre, $bounds[22] + DAYSECS, 'social', 'Facebook', 'whatsapp');
        foreach ([21, 22, 23] as $m) {
            $this->family($centre, min($now - HOURSECS, $bounds[$m] + DAYSECS), 'social', 'TikTok', 'phone');
        }
        $this->family($centre, $bounds[15] + DAYSECS, 'search', '', 'email');
        $context = panel_channels::context($centre, $now);
        $this->assertSame(['know', 'arrive', 'talk'], array_column($context['voices'], 'key'));
        $know = array_column($context['voices'][0]['rows'], null, 'name');
        $this->assertSame(['TikTok', 'Facebook', get_string('heard_search', 'local_oksigeniaeguahe')], array_keys($know));
        $this->assertSame('sube', $know['TikTok']['trend']);
        $this->assertSame(get_string('heard_social', 'local_oksigeniaeguahe'), $know['TikTok']['group']);
        $this->assertSame([1, 4], [$know['Facebook']['total'], $know['Facebook']['before']]);
        $this->assertSame('baja', $know['Facebook']['trend']);
        $this->assertCount(12, $know['TikTok']['months']);
        // One scale for the whole voice: the busiest month is the one of the year before.
        $bv = array_merge(...array_map(fn($r) => array_column($r['months'], 'bv'), $context['voices'][0]['rows']));
        $this->assertEquals(1.0, max($bv));
        $this->assertStringContainsString('TikTok', $context['voices'][0]['rest']);
        $talk = array_column($context['voices'][2]['rows'], 'total', 'name');
        $this->assertSame(3, $talk[get_string('channel_phone', 'local_oksigeniaeguahe')]);
        // No mail went, and Marandu, if there is one, has no notices of this centre: nothing to show there.
        $this->assertFalse($context['notices']);
        $this->assertFalse($context['channel']);

        // The sheet in Cifras, for the campaign under way.
        $this->assertSame(get_string('panel_ct_title', 'local_oksigeniaeguahe'), $context['title']);
        $this->assertNotFalse(panel_metrics::context($centre, [], true, $now)['channelstime']);
    }

    /**
     * The mails: what went and what was pressed inside, by kind; whether they are opened is not counted, never a zero.
     */
    public function test_mails(): void {
        $this->resetAfterTest();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $centre = centres::get($id);
        $now = time();
        $a = $this->family($centre, $now - 5 * DAYSECS, 'search', '', 'email');
        $b = $this->family($centre, $now - 5 * DAYSECS, 'search', '', 'email');
        foreach ([$a, $b] as $h) {
            seen::mail_sent($h, 'confirmation', $now - 4 * DAYSECS);
        }
        seen::mail_sent($a, 'reminder', $now - 2 * DAYSECS);
        seen::record($a, 'open', '', 'confirmation', $now - 3 * DAYSECS);
        seen::record($a, 'touch', 'calendar', 'confirmation', $now - 3 * DAYSECS);
        $notices = panel_channels::context($centre, $now)['notices'];
        $email = array_column($notices['rows'], null, 'channel')['email'];
        $this->assertSame([3, 1], [$email['sent'], $email['clicked']]);
        $stages = array_column($email['stages'], null, 'name');
        $read = $stages[get_string('panel_ct_stage_read', 'local_oksigeniaeguahe')];
        $this->assertTrue($read['missing']);
        $this->assertSame(get_string('panel_ct_not_counted', 'local_oksigeniaeguahe'), $read['missingtext']);
        $kinds = array_column($notices['bykind'], null, 'kind');
        $email = get_string('channel_email', 'local_oksigeniaeguahe');
        $confirmation = array_column($kinds['confirmation']['cells'], null, 'name')[$email];
        $this->assertSame([2, 1, true], [$confirmation['sent'], $confirmation['clicked'], $confirmation['best']]);
        $this->assertSame(get_string('panel_ct_rate', 'local_oksigeniaeguahe', 50), $confirmation['rate']);
        $this->assertSame(0, array_column($kinds['reminder']['cells'], 'clicked')[0]);
    }
}
