<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Target-aware FlexAccess entry page scaffold.
 *
 * @copyright  2026 Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

$wantsurl = optional_param('wantsurl', '', PARAM_LOCALURL);
$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/auth/flexaccess/access.php', ['wantsurl' => $wantsurl]));
$PAGE->set_pagelayout('login');
$PAGE->set_title(get_string('accessprovider', 'auth_flexaccess'));
$PAGE->set_heading(get_string('accessprovider', 'auth_flexaccess'));

echo $OUTPUT->header();
echo $OUTPUT->notification(get_string('stubnotice', 'auth_flexaccess'), 'info');
echo $OUTPUT->footer();
