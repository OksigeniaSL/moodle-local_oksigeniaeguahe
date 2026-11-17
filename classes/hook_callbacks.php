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
use local_oksigeniaeguahe\local\onlyeguahe;
use local_oksigeniaeguahe\local\publicpage;

/**
 * The way in for the team of a centre, who are not administrators: «Admissions» in the main bar. And who makes Eguahe,
 * at the foot of its screens.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * «Admissions» in the main bar, for whoever works for a centre.
     *
     * @param \core\hook\navigation\primary_extend $hook
     */
    public static function primary_extend(\core\hook\navigation\primary_extend $hook): void {
        global $USER;
        if (during_initial_install()) {
            return;
        }
        // A site that is only Eguahe has no home, dashboard or courses to go to, except for whoever runs it.
        if (onlyeguahe::on() && !onlyeguahe::runs_the_site()) {
            foreach (['home', 'myhome', 'mycourses'] as $key) {
                $node = $hook->get_primaryview()->get($key);
                if ($node) {
                    $node->remove();
                }
            }
        }
        if (!isloggedin() || isguestuser()) {
            return;
        }
        if (!centres::for_user((int) $USER->id)) {
            return;
        }
        $hook->get_primaryview()->add(
            get_string('navname', 'local_oksigeniaeguahe'),
            new \moodle_url('/local/oksigeniaeguahe/index.php'),
            \navigation_node::TYPE_ROOTNODE,
            null,
            'local_oksigeniaeguahe'
        );
    }

    /**
     * «Eguahe, by Oksigenia» at the foot of the screens of the team, unless the site turns it off.
     *
     * @param \core\hook\output\before_footer_html_generation $hook
     */
    public static function before_footer(\core\hook\output\before_footer_html_generation $hook): void {
        global $PAGE;
        if (during_initial_install() || !$PAGE->has_set_url()) {
            return;
        }
        if (strpos($PAGE->url->out_omit_querystring(), '/local/oksigeniaeguahe/') === false) {
            return;
        }
        $credit = publicpage::credit(current_language());
        if ($credit) {
            $link = \html_writer::link($credit['url'], s($credit['text']), ['target' => '_blank', 'rel' => 'noopener']);
            $hook->add_html(\html_writer::tag('p', $link, ['class' => 'eguahe-credit']));
        }
    }

    /**
     * In a site that is only Eguahe, the way in is Eguahe's own. The platform's stays at login/index.php.
     *
     * @param \core\hook\after_config $hook
     */
    public static function after_config(\core\hook\after_config $hook): void {
        global $CFG;
        if (during_initial_install() || (defined('CLI_SCRIPT') && CLI_SCRIPT) || !onlyeguahe::on()) {
            return;
        }
        $CFG->alternateloginurl = onlyeguahe::entry()->out(false);
    }

    /**
     * In a site that is only Eguahe, the home, the dashboard and the courses lead to Eguahe.
     *
     * @param \core\hook\output\before_http_headers $hook
     */
    public static function before_http_headers(\core\hook\output\before_http_headers $hook): void {
        global $PAGE;
        if (during_initial_install() || (defined('AJAX_SCRIPT') && AJAX_SCRIPT)) {
            return;
        }
        $instead = onlyeguahe::instead_of((string) $PAGE->pagetype);
        if ($instead) {
            redirect($instead);
        }
    }

    /**
     * In a site that is only Eguahe, the pages left of the platform (a forgotten password, one's profile) without the
     * footer of the platform, except for whoever runs the site.
     *
     * @param \core\hook\output\before_standard_head_html_generation $hook
     */
    public static function before_standard_head(\core\hook\output\before_standard_head_html_generation $hook): void {
        if (during_initial_install() || !onlyeguahe::on() || onlyeguahe::runs_the_site()) {
            return;
        }
        $hook->add_html('<style>.btn-footer-popover,#page-footer,.footer-popover{display:none!important}</style>');
    }
}
