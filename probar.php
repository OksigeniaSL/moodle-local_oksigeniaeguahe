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
 * Trying Eguahe without an account, in a site that is only Eguahe: one tick against robots and the visitor is in a
 * centre of their own, with made-up families and an open day under way, where nothing is ever sent. It goes after a day.
 *
 * @package    local_oksigeniaeguahe
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing -- The visitor has no account yet: this page makes one.
require(__DIR__ . '/../../config.php');

use local_oksigeniaeguahe\local\antispam;
use local_oksigeniaeguahe\local\demo;
use local_oksigeniaeguahe\local\lists;
use local_oksigeniaeguahe\local\onlyeguahe;
use local_oksigeniaeguahe\local\publiclang;
use local_oksigeniaeguahe\local\publicpage;

if (!demo::on()) {
    redirect(onlyeguahe::on() ? onlyeguahe::entry() : new moodle_url('/'));
}
// Someone already in goes to their inbox: a visitor to their own centre.
if (isloggedin() && !isguestuser()) {
    $mine = demo::centre_of((int) $USER->id);
    redirect(new moodle_url('/local/oksigeniaeguahe/index.php', $mine ? ['c' => $mine->id] : []));
}

$asked = optional_param('idioma', '', PARAM_ALPHAEXT);
$lang = in_array($asked, lists::LANGS, true) ? $asked :
    (publiclang::from_header((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) ?? 'en');
$lang = $lang === 'ar' ? 'en' : $lang;
$url = new moodle_url('/local/oksigeniaeguahe/probar.php', $asked ? ['idioma' => $lang] : []);
publicpage::setup($url);
$str = fn(string $key, $a = null) => publiclang::str($key, $lang, $a);

$error = '';
if (data_submitted()) {
    if (!antispam::verify(optional_param('altcha', '', PARAM_RAW))) {
        $error = $str('demo_robot');
    } else if (!demo::allowed_from(getremoteaddr())) {
        $error = $str('demo_busy');
    } else {
        $centre = demo::start($lang);
        redirect(new moodle_url('/local/oksigeniaeguahe/index.php', ['c' => $centre->id]));
    }
}

$legal = onlyeguahe::legal();
publicpage::$privacy = $legal !== '' ? ['url' => $legal, 'label' => $str('entrar_legal')] : ['url' => '', 'label' => ''];
$altchalang = ['es' => 'es-es', 'en' => 'en', 'pt' => 'pt-pt', 'pt_br' => 'pt-br'][$lang] ?? 'en';
$context = [
    'heading' => $str('demo_heading'),
    'lead' => $str('demo_lead'),
    'points' => array_map(fn($k) => ['text' => $str($k)], ['demo_point_1', 'demo_point_2', 'demo_point_3']),
    'error' => $error,
    'action' => $url->out(false),
    'go' => $str('demo_go'),
    'altchaurl' => $CFG->wwwroot . '/local/oksigeniaeguahe/altcha.php',
    'altchaconfig' => json_encode(['auto' => 'onfocus', 'hideFooter' => true, 'humanInteractionSignature' => false,
        'language' => $altchalang, 'type' => 'checkbox']),
    'altchalang' => $altchalang,
    'altchai18n' => $altchalang === 'en' ? '' : $altchalang,
    'entryurl' => onlyeguahe::entry()->out(false),
    'entry' => $str('demo_have_account'),
    'dog' => (new moodle_url('/local/oksigeniaeguahe/pub/guia/perro-presenta-256.webp'))->out(false),
    'dog2x' => (new moodle_url('/local/oksigeniaeguahe/pub/guia/perro-presenta-512.webp'))->out(false),
    'hello' => $str('demo_hello'),
];
$site = format_string($SITE->fullname, true, ['escape' => false]);
$centre = (object) ['id' => 0, 'name' => $site, 'colour' => '#6a3f78'];
publicpage::output($centre, $lang, $str('demo_title'), 'public_probar', $context, $url);
