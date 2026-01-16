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
 * Page to display success message after importing questions.
 *
 * @package    local_questionconverter
 * @copyright  2025 Tu Nombre
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

// Obtener parámetros
$courseid = required_param('courseid', PARAM_INT);
$total = required_param('total', PARAM_INT);
$categories = optional_param('categories', 1, PARAM_INT);
$categoryid = required_param('categoryid', PARAM_INT);

// Verificar acceso
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('moodle/question:add', $context);

$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('pluginname', 'local_questionconverter'));
$PAGE->set_heading($course->fullname);
$PAGE->requires->css(new moodle_url('/local/questionconverter/tailwindcss/dist/output.css'));

// URL para ir al banco de preguntas
$question_bank_url = new moodle_url('/question/edit.php', [
    'courseid' => $courseid,
    'cat' => $categoryid . ',' . $context->id
]);

echo $OUTPUT->header();

$templatedata = [
    'total' => $total,
    'categories' => $categories,
    'questionbankurl' => $question_bank_url->out(false),
    'courseurl' => (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false),

    'message-success' => get_string('message-success', 'local_questionconverter'),
    'importsuccess' => get_string('importsuccess', 'local_questionconverter'), 
    'questionsimported' => get_string('questionsimported', 'local_questionconverter'),
    'categoriescreated' => get_string('categoriescreated', 'local_questionconverter'),
    'redirecting' => get_string('redirecting', 'local_questionconverter'),
    'seconds' => get_string('seconds', 'local_questionconverter'),
    'gotoquestionbank' => get_string('gotoquestionbank', 'local_questionconverter'),
    'backtocourse' => get_string('backtocourse', 'local_questionconverter'),
];

echo $OUTPUT->render_from_template('local_questionconverter/success', $templatedata);

echo $OUTPUT->footer();