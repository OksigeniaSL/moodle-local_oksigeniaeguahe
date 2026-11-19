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
use local_oksigeniaeguahe\local\costs;
use local_oksigeniaeguahe\local\entries;
use local_oksigeniaeguahe\local\forms;
use local_oksigeniaeguahe\local\grades;
use local_oksigeniaeguahe\local\households;
use local_oksigeniaeguahe\local\panel_metrics;

/**
 * Each place a form is shared from has its own link with its utm, and Numbers counts what was measured and each campaign
 * with what it cost.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniaeguahe\local\entries
 * @covers     \local_oksigeniaeguahe\local\panel_metrics
 */
#[\PHPUnit\Framework\Attributes\CoversClass(entries::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(panel_metrics::class)]
final class campaigns_test extends \advanced_testcase {
    /**
     * The link of each place carries its utm; without a shortener it is the long address, with a QR drawn here.
     */
    public function test_site_links(): void {
        $this->resetAfterTest();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $entry = entries::get(entries::create($id, 'form', 'Pedir información'), $id);
        $key = forms::add_origin($entry, 'Cartel de la puerta');
        $entry = entries::get((int) $entry->id, $id);
        $place = entries::site_link($entry, $key);
        $url = new \moodle_url($place['url']);
        $this->assertSame($key, $url->get_param('d'));
        $this->assertSame($key, $url->get_param('utm_source'));
        $this->assertSame('formulario-' . $entry->id, $url->get_param('utm_campaign'));
        $this->assertStringContainsString('<svg', $place['qr']);
        $general = new \moodle_url(entries::site_link($entry, '')['url']);
        $this->assertNull($general->get_param('d'));
        $this->assertSame('enlace', $general->get_param('utm_source'));
    }

    /**
     * Families measured with their source and campaign: each voice lists them, and the centre can say what each cost.
     */
    public function test_campaign_voice(): void {
        $this->resetAfterTest();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        $centre = centres::get($id);
        foreach ([['instagram', 'matricula-2027'], ['instagram', 'matricula-2027'], ['chatgpt.com', '']] as [$src, $cmp]) {
            households::create($id, (object) ['lang' => 'es', 'firstchannel' => 'webform',
                'arrival' => ['src' => $src, 'cmp' => $cmp]]);
        }
        $campaign = panel_metrics::campaigns($centre)['live'];
        $families = panel_metrics::families($centre, $campaign);
        $this->assertSame(
            ['Instagram', 'Instagram', get_string('arrival_ai_group', 'local_oksigeniaeguahe')],
            array_values(array_column(array_column($families, 'voices'), 'measured'))
        );
        $this->assertSame(['matricula-2027'], array_values(panel_metrics::spend_keys(
            $centre,
            $campaign,
            $families,
            'campaign'
        )));
        // What is posted is checked against every voice the centre pays for.
        $all = panel_metrics::spend_keys($centre, $campaign, $families);
        $this->assertContains('matricula-2027', $all);
        $this->assertContains('Instagram', $all);
    }

    /**
     * A word beside each stage: in the courses of the sign-up and first in what it costs; an empty one is not kept.
     */
    public function test_stage_notes(): void {
        $this->resetAfterTest();
        $id = centres::create((object) ['name' => 'Colegio de ejemplo', 'lang' => 'es', 'country' => 'ES']);
        centres::update($id, (object) ['system' => 'es', 'stagenotes' => [0 => 'privada', 3 => 'concertada', 6 => '  '],
            'costs' => 'Comedor: 120 €']);
        $centre = centres::get($id);
        $this->assertSame([0 => 'privada', 3 => 'concertada'], centres::stage_notes($centre));
        $offer = (object) ['id' => 1, 'name' => 'Infantil 3 años', 'schoolyear' => '2027-28', 'sortorder' => 0];
        [$groups] = grades::grouped([$offer], $centre);
        $this->assertStringContainsString('(concertada)', $groups[0]['label']);
        $rows = costs::rows($centre);
        $this->assertSame(['what' => 'Primer ciclo de Infantil', 'amount' => 'privada'], $rows[0]);
        $this->assertSame('Comedor', end($rows)['what']);
    }
}
