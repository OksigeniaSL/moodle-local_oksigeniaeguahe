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

/**
 * A new centre with its first person, who looks after its settings and its team: for whoever runs a site shared by
 * several centres. The person gets an account (or keeps the one they have) and their password by email.
 *
 * php local/oksigeniaeguahe/cli/alta.php --centre="Maple Grove" --email=head@example.org --name="Daniel Hughes" --lang=en
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_oksigeniaeguahe\local\centres;
use local_oksigeniaeguahe\local\lists;
use local_oksigeniaeguahe\local\onlyeguahe;

[$options] = cli_get_params(['centre' => '', 'email' => '', 'name' => '', 'lang' => 'en', 'help' => false], ['h' => 'help']);
if ($options['help'] || trim($options['centre']) === '' || !validate_email(trim($options['email']))) {
    cli_writeln('php local/oksigeniaeguahe/cli/alta.php --centre="Name" --email=someone@example.org --name="Name Surname" ' .
        '[--lang=es|en|pt|pt_br]');
    exit($options['help'] ? 0 : 1);
}
\core\session\manager::set_user(get_admin());
$lang = in_array($options['lang'], lists::LANGS, true) ? $options['lang'] : 'en';
$user = onlyeguahe::new_account(trim($options['email']), trim($options['name']), $lang);
$id = centres::create((object) ['name' => trim($options['centre']), 'lang' => $lang, 'retention' => 24]);
centres::add_member($id, (int) $user->id);
centres::set_admin($id, (int) $user->id, true);
cli_writeln(json_encode(['centre' => $id, 'user' => (int) $user->id, 'username' => $user->username]));
