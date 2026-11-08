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
use local_oksigeniaeguahe\local\events;
use local_oksigeniaeguahe\local\familymail;
use local_oksigeniaeguahe\local\hobbies;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\invites;
use local_oksigeniaeguahe\local\panel_household;
use local_oksigeniaeguahe\local\panel_likes;
use local_oksigeniaeguahe\local\panel_metrics;
use local_oksigeniaeguahe\local\seen;
use local_oksigeniaeguahe\local\siblings;

/**
 * What families do and who they are (0.33.0): what is counted on their page and with the mails, the facts in their file,
 * «What they like most» in Cifras, what the adults like doing, the network they named, and that all of it goes when a
 * family erases its data. With example data.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(seen::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(hobbies::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(panel_likes::class)]
final class seen_test extends \advanced_testcase {
    /**
     * A centre of example with one offer.
     *
     * @return array [centre, offer id]
     */
    protected function centre(): array {
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES',
            'municipalities' => "Getafe\nLeganés"]);
        $offer = centres::save_offer($id, (object) ['schoolyear' => '2027-28', 'name' => 'Infantil 3 años', 'places' => 25,
            'active' => 1]);
        return [centres::get($id), $offer];
    }

    /**
     * A family with one adult and one child.
     *
     * @param \stdClass $centre
     * @param int $offer
     * @param string $name
     * @param string $place
     * @return \stdClass
     */
    protected function family(\stdClass $centre, int $offer, string $name, string $place = 'Getafe'): \stdClass {
        $household = households::get(households::create((int) $centre->id, (object) ['lang' => 'es',
            'firstchannel' => 'webform', 'municipalityname' => $place]), (int) $centre->id);
        households::add_adult($household, (object) ['firstname' => $name, 'lastname' => 'Ejemplo',
            'email' => \core_text::strtolower($name) . '@example.com']);
        households::add_child($household, 'Hijo de ' . $name, $offer);
        return households::get((int) $household->id, (int) $centre->id);
    }

    /**
     * What is noted: only what can be, the same visit once, a press from a mail always, and a top a day.
     */
    public function test_record(): void {
        global $DB;
        $this->resetAfterTest();
        [$centre, $offer] = $this->centre();
        $ana = $this->family($centre, $offer, 'Ana');
        $now = time();
        $this->assertTrue(seen::record($ana, 'open', '', '', $now));
        $this->assertFalse(seen::record($ana, 'open', '', '', $now + 60), 'The same visit.');
        $this->assertTrue(seen::record($ana, 'open', '', 'reminder', $now + 120), 'A press in a mail is always one.');
        $this->assertFalse(seen::record($ana, 'open', '', '', $now + seen::VISIT + 1), 'Half an hour from the press, too.');
        $this->assertTrue(seen::record($ana, 'open', '', '', $now + seen::VISIT + 121));
        $this->assertFalse(seen::record($ana, 'touch', 'something', '', $now));
        $this->assertFalse(seen::record($ana, 'part', 'nowhere', '', $now));
        $this->assertFalse(seen::record($ana, 'score', '', '', $now));
        $this->assertTrue(seen::record($ana, 'part', 'hosts', '', $now));
        $this->assertTrue(seen::record($ana, 'touch', 'tales', 'nomail', $now));
        $this->assertNull($DB->get_field(seen::T, 'via', ['householdid' => $ana->id, 'what' => 'tales']), 'Only mails of Eguahe.');
        $this->assertSame(5, $DB->count_records(seen::T, ['householdid' => $ana->id]));

        $berta = $this->family($centre, $offer, 'Berta');
        for ($i = 0; $i < seen::MOST; $i++) {
            $DB->insert_record(seen::T, (object) ['centreid' => $centre->id, 'householdid' => $berta->id, 'kind' => 'touch',
                'what' => 'tales', 'timecreated' => $now]);
        }
        $this->assertFalse(seen::record($berta, 'touch', 'tales', '', $now), 'No more today.');
        $this->assertTrue(seen::record($berta, 'touch', 'tales', '', $now + DAYSECS + 1));
    }

    /**
     * The facts of one family in its file, and its mails counted when they go and when something inside is pressed.
     */
    public function test_facts_and_mails(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$centre, $offer] = $this->centre();
        $ana = $this->family($centre, $offer, 'Ana');
        $context = panel_household::context($ana, $centre, true);
        $this->assertSame([get_string('panel_facts_none', 'local_oksigeniaeguahe')], $context['seen']['facts']);

        $sink = $this->redirectEmails();
        $this->assertTrue(familymail::send('received', $ana, $centre, null, 'es', households::new_link($ana)));
        $this->assertCount(1, $sink->get_messages());
        $this->assertSame(1, $DB->count_records(seen::T, ['householdid' => $ana->id, 'kind' => 'mailsent',
            'what' => 'received']));
        $this->assertMatchesRegularExpression('/de=(3D)?received/', $sink->get_messages()[0]->body);
        $sink->close();

        $day = time() - 3 * DAYSECS;
        seen::record($ana, 'open', '', 'received', $day);
        seen::record($ana, 'part', 'wall', '', $day);
        seen::record($ana, 'part', 'told', '', $day);
        seen::record($ana, 'touch', 'drawing_enlarged', '', $day);
        seen::record($ana, 'touch', 'drawing_enlarged', '', $day + DAYSECS);
        seen::record($ana, 'touch', 'tales', '', $day + DAYSECS);
        seen::record($ana, 'open', '', '', $day + DAYSECS);
        $summary = seen::summary((int) $ana->id);
        $this->assertSame(2, $summary['opens']);
        $this->assertSame(2, $summary['days']);
        $this->assertSame('told', $summary['deepest']);
        $this->assertSame(['drawing_enlarged' => 2, 'tales' => 1], $summary['touches']);
        $this->assertSame(['received' => 1], $summary['mails']);
        $facts = panel_household::context($ana, $centre, true)['seen']['facts'];
        $this->assertCount(5, $facts);
        $this->assertStringContainsString('2 times', $facts[0]);
        $this->assertStringContainsString('what they told us', $facts[2]);
        $this->assertStringContainsString('acknowledgement (1)', $facts[4]);
    }

    /**
     * What the adults like doing: each one their own, replaced when told again, and added up without naming anyone.
     */
    public function test_hobbies(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$centre, $offer] = $this->centre();
        $ana = $this->family($centre, $offer, 'Ana');
        $pablo = households::add_adult($ana, (object) ['firstname' => 'Pablo', 'lastname' => 'Ejemplo']);
        $adults = array_keys(households::full($ana)->adults);
        $berta = $this->family($centre, $offer, 'Berta');
        $bertaadult = (int) array_key_first(households::full($berta)->adults);

        $this->assertTrue(hobbies::save($ana, $adults[0], ['cooking', 'boardgames', 'nothing'], 'Parchís ', time()));
        $this->assertTrue(hobbies::save($ana, $pablo, ['sport'], '', time()));
        $this->assertFalse(hobbies::save($ana, $bertaadult, ['sport'], '', time()), 'Not an adult of theirs.');
        $this->assertTrue(hobbies::save($berta, $bertaadult, ['boardgames'], 'parchís', time()));
        $told = hobbies::of((int) $ana->id);
        $this->assertSame(['cooking', 'boardgames'], $told[$adults[0]]['keys']);
        $this->assertSame('Parchís', $told[$adults[0]]['other']);
        $this->assertSame('cooking, board games and parchís', hobbies::words($told[$adults[0]]));
        $this->assertSame('cocina, juegos de mesa y parchís', hobbies::words($told[$adults[0]], 'es'));

        $counts = hobbies::counts([(int) $ana->id, (int) $berta->id]);
        $this->assertSame(3, $counts['adults']);
        $this->assertSame(3, $counts['told']);
        $this->assertSame(2, $counts['list']['boardgames']);
        $this->assertSame(['Parchís' => 2], $counts['others'], 'The same thing written a little differently.');
        $this->assertEquals(['boardgames' => 2, 'cooking' => 1, 'sport' => 1], hobbies::families_by([(int) $ana->id,
            (int) $berta->id]));

        // Unmarking everything takes it all out.
        hobbies::save($ana, $pablo, [], '', time());
        $this->assertArrayNotHasKey($pablo, hobbies::of((int) $ana->id));
        $adult = panel_household::context($ana, $centre, true)['adults'][0];
        $this->assertSame('Likes: cooking, board games and parchís.', $adult['likes']);

        // An open act that goes with board games: only the families where someone marked them.
        foreach ([$ana, $berta] as $household) {
            $adultid = (int) array_key_first(households::full($household)->adults);
            households::consent($household, $adultid, 'email', true, 'form', 'es');
        }
        $carlos = $this->family($centre, $offer, 'Carlos');
        households::consent($carlos, (int) array_key_first(households::full($carlos)->adults), 'email', true, 'form', 'es');
        $eventid = events::save((int) $centre->id, (object) ['kind' => 'other', 'name' => 'Taller de juegos de mesa',
            'timestart' => time() + 5 * DAYSECS, 'timeend' => time() + 5 * DAYSECS + HOURSECS, 'capacity' => 0, 'open' => 1]);
        $event = events::get($eventid, (int) $centre->id);
        $this->assertCount(3, invites::eligible($event));
        $sink = $this->redirectEmails();
        $this->assertSame(2, invites::send($event, $centre, (int) get_admin()->id, time(), 'boardgames'));
        $this->assertSame(1, invites::send($event, $centre, (int) get_admin()->id, time()), 'Then the rest.');
        $sink->close();
    }

    /**
     * «What they like most», from the same families as the rest of Cifras: only for the campaign under way, almost
     * empty with few families, and with what is looked at again, the page, the mails and where they come from.
     */
    public function test_likes(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$centre, $offer] = $this->centre();
        $now = time();
        $families = [];
        foreach (['Ana', 'Berta', 'Carlos', 'Diana', 'Elena', 'Fermín'] as $i => $name) {
            $families[] = $this->family($centre, $offer, $name, $i < 4 ? 'Getafe' : 'Leganés');
        }
        $campaign = panel_metrics::campaigns($centre)['live'];
        $mine = panel_metrics::families($centre, $campaign);
        $empty = panel_likes::context($centre, $campaign, $mine, $now);
        $this->assertTrue($empty['empty']);
        $this->assertSame(get_string('panel_likes_visits_none', 'local_oksigeniaeguahe'), $empty['pages']['visitstext']);

        foreach ($families as $i => $household) {
            seen::record($household, 'open', '', $i === 0 ? 'confirmation' : '', $now - 2 * DAYSECS);
            seen::record($household, 'part', 'wall', '', $now - 2 * DAYSECS);
            seen::record($household, 'touch', 'drawing_seen', '', $now - 2 * DAYSECS);
            seen::record($household, 'touch', 'drawing_seen', '', $now - DAYSECS);
            seen::record($household, 'touch', 'directions', '', $now - DAYSECS);
            seen::mail_sent($household, 'confirmation', $now - 3 * DAYSECS);
            if ($i < 3) {
                seen::record($household, 'open', '', '', $now - DAYSECS);
                seen::record($household, 'touch', 'drawing_seen', '', $now - DAYSECS);
            }
        }
        $likes = panel_likes::context($centre, $campaign, panel_metrics::families($centre, $campaign), $now);
        $this->assertFalse($likes['empty']);
        $this->assertSame(6, $likes['pages']['opened']);
        $this->assertSame(3, $likes['pages']['returned']);
        $this->assertSame([3, 3, 0, 0, 0], array_column($likes['pages']['distribution'], 'families'));
        $this->assertSame('drawing_seen', $likes['touches'][0]['key']);
        $this->assertSame(2.5, $likes['touches'][0]['again']);
        $this->assertCount(3, $likes['touches'][0]['marks']);
        $this->assertTrue($likes['touches'][0]['vuelve']);
        $this->assertFalse($likes['touches'][1]['vuelve']);
        $this->assertSame(['confirmation'], array_column($likes['mails'], 'kind'));
        $this->assertSame([6, 1], [$likes['mails'][0]['sent'], $likes['mails'][0]['clicked']]);
        $this->assertSame(['Getafe', 'Leganés'], array_column($likes['origins'], 'place'));
        $this->assertSame([4, 2], array_column($likes['origins'], 'families'));
        $this->assertSame('Infantil 3 años', $likes['courses'][0]['name']);
        $this->assertSame(6, $likes['courses'][0]['asked']);
        $this->assertFalse($likes['hashobbies']);
        $this->assertFalse($likes['firstday']);

        // An imported campaign has none of it.
        $this->assertFalse(panel_likes::context($centre, ['import' => (object) ['id' => 1]] + $campaign, [], $now));
        // And the page of Cifras carries it.
        $page = panel_metrics::context($centre, [], true, $now);
        $this->assertSame(6, $page['likes']['pages']['opened']);
    }

    /**
     * The network a family named, as the centre writes it, only when it was a network.
     */
    public function test_networks(): void {
        $this->resetAfterTest();
        [$centre] = $this->centre();
        $this->assertSame(\local_oksigeniaeguahe\local\lists::NETWORKS, centres::networks($centre));
        centres::update((int) $centre->id, (object) ['name' => $centre->name, 'networks' => "TikTok\nInstagram\n\nTikTok"]);
        $centre = centres::get((int) $centre->id);
        $this->assertSame(['TikTok', 'Instagram'], centres::networks($centre));
        $this->assertSame('TikTok', centres::network($centre, ' tiktok '));
        $this->assertSame('Mastodon', centres::network($centre, 'Mastodon'));
        $social = households::get(households::create((int) $centre->id, (object) ['lang' => 'es', 'firstchannel' => 'webform',
            'heardfrom' => 'social', 'heardnet' => 'TikTok']), (int) $centre->id);
        $this->assertSame('TikTok', $social->heardnet);
        $search = households::get(households::create((int) $centre->id, (object) ['lang' => 'es', 'firstchannel' => 'webform',
            'heardfrom' => 'search', 'heardnet' => 'TikTok']), (int) $centre->id);
        $this->assertNull($search->heardnet);
    }

    /**
     * Erasing a family takes everything about it: what was counted, what they like, their brothers and sisters for
     * later and the invitations; and what they take with their data has it.
     */
    public function test_erase(): void {
        global $DB;
        $this->resetAfterTest();
        [$centre, $offer] = $this->centre();
        $DB->set_field(centres::T, 'siblingage', 3, ['id' => $centre->id]);
        $ana = $this->family($centre, $offer, 'Ana');
        $adult = (int) array_key_first(households::full($ana)->adults);
        seen::record($ana, 'open', '', '', time());
        hobbies::save($ana, $adult, ['music'], '', time());
        siblings::add($ana, 'Sofía', (int) date('Y') - 1, 'signup', time());
        $DB->insert_record(invites::T, (object) ['centreid' => $centre->id, 'eventid' => 1, 'householdid' => $ana->id,
            'userid' => 2, 'timecreated' => time()]);
        $export = households::export($ana);
        $this->assertSame(['music'], $export['likes'][0]['likes']);
        $this->assertSame('Sofía', $export['brothers_and_sisters_for_later'][0]['first_name']);
        $this->assertSame('open', $export['what_was_counted'][0]['kind']);

        households::erase($ana);
        foreach ([seen::T, hobbies::T, siblings::T, invites::T] as $table) {
            $this->assertSame(0, $DB->count_records($table, ['householdid' => $ana->id]), $table);
        }
    }
}
