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
 * The way in of a site that is only Eguahe: the teams of the centres come in here, with the paper of Eguahe instead of
 * the page of the platform. The platform's own stays at login/index.php for whoever runs the site.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- This is the page where one logs in.
require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\lists;
use local_oksigeniaeguahe\local\onlyeguahe;
use local_oksigeniaeguahe\local\publiclang;
use local_oksigeniaeguahe\local\publicpage;

// Somebody already in goes to the inbox, and a site that is more than Eguahe keeps the platform's entry.
if (isloggedin() && !isguestuser()) {
    redirect(new moodle_url('/local/oksigeniaeguahe/index.php'));
}
if (!onlyeguahe::on()) {
    redirect(new moodle_url('/login/index.php'));
}

$asked = optional_param('idioma', '', PARAM_ALPHAEXT);
$lang = in_array($asked, lists::LANGS, true) ? $asked :
    (in_array(current_language(), lists::LANGS, true) ? current_language() :
    (publiclang::from_header((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) ?? 'en'));
$url = new moodle_url('/local/oksigeniaeguahe/entrar.php', $asked ? ['idioma' => $lang] : []);
publicpage::setup($url);
$str = fn(string $key, $a = null) => publiclang::str($key, $lang, $a);

$error = optional_param('errorcode', 0, PARAM_INT);
// What an outside account (Google, Microsoft…) said when it could not let someone in: Moodle sends them here before
// showing it, so it is shown here, once.
$said = (string) ($SESSION->loginerrormsg ?? '');
unset($SESSION->loginerrormsg);
require_once($CFG->libdir . '/authlib.php');
$providers = \auth_plugin_base::prepare_identity_providers_for_output(
    \auth_plugin_base::get_identity_providers(get_enabled_auth_plugins()),
    $PAGE->get_renderer('core')
);
$legal = onlyeguahe::legal();
publicpage::$privacy = $legal !== '' ? ['url' => $legal, 'label' => $str('entrar_legal')] : ['url' => '', 'label' => ''];

$site = format_string($SITE->fullname, true, ['escape' => false]);
$context = [
    'heading' => $str('entrar_heading'),
    'lead' => $str('entrar_lead'),
    'error' => in_array($error, [1, 2, 3, 4], true) ? $str('entrar_error' . $error) : $said,
    'action' => (new moodle_url('/login/index.php'))->out(false),
    'logintoken' => \core\session\manager::get_login_token(),
    'username' => !empty($CFG->rememberusername) ? (string) get_moodle_cookie() : '',
    'userlabel' => $str(!empty($CFG->authloginviaemail) ? 'entrar_user_email' : 'entrar_user'),
    'passwordlabel' => $str('entrar_password'),
    'go' => $str('entrar_go'),
    'forgot' => (new moodle_url('/login/forgot_password.php'))->out(false),
    'forgotlabel' => $str('entrar_forgot'),
    'providers' => array_values(array_map(fn($p) => ['url' => (string) $p['url'], 'name' => (string) $p['name'],
        'iconurl' => (string) ($p['iconurl'] ?? '')], $providers)),
    'hasproviders' => (bool) $providers,
    'providersheading' => $str('entrar_providers'),
    'noaccount' => $str('entrar_noaccount'),
    'dog' => (new moodle_url('/local/oksigeniaeguahe/pub/guia/perro-saluda-256.webp'))->out(false),
    'dog2x' => (new moodle_url('/local/oksigeniaeguahe/pub/guia/perro-saluda-512.webp'))->out(false),
    'hello' => $str('entrar_hello'),
    // Without an account, to try it in a centre of one's own, when the site lets.
    'demourl' => \local_oksigeniaeguahe\local\demo::on() ?
        (new moodle_url('/local/oksigeniaeguahe/probar.php', ['idioma' => $lang]))->out(false) : '',
    'demo' => $str('entrar_demo'),
];
// The site as the centre at the top, in the aubergine of Eguahe.
$centre = (object) ['id' => 0, 'name' => $site, 'colour' => '#6a3f78'];
publicpage::output($centre, $lang, $str('entrar_title'), 'public_entrar', $context, $url);
