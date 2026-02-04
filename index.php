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
 * Main file to view questionconverter plugin.
 *
 * @package     local_questionconverter
 * @copyright   2026 Renzo Medina <medinast30@gmail.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/questionlib.php');

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once(__DIR__ . '/vendor/autoload.php');
}

require_once(__DIR__ . '/classes/converter/pdf_parser.php');
require_once(__DIR__ . '/classes/converter/question_importer.php');

use local_questionconverter\form\upload_form;
use local_questionconverter\converter\pdf_parser;
use local_questionconverter\converter\question_importer;

$courseid = optional_param('courseid', 0, PARAM_INT);
if (empty($courseid)) {
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if (preg_match('/[?&]id=(\d+)/', $referer, $matches)) {
        $courseid = (int)$matches[1];
    } else {
        redirect(new moodle_url('/my/'));
    }
}
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('moodle/question:add', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/questionconverter/index.php', [
    'courseid' => $courseid,
]));
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('pluginname', 'local_questionconverter'));
$PAGE->set_heading(get_string('pluginname', 'local_questionconverter'));
$PAGE->requires->css(new moodle_url('/local/questionconverter/tailwindcss/dist/output.css'));
$PAGE->requires->js(new moodle_url('/local/questionconverter/js/uploader.js'));

$form = new upload_form(null, ['courseid' => $courseid]);
$form->set_data(['courseid' => $courseid]);
// Process the form if it has been submitted.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    try {
        // Verify that a file has been uploaded (supports direct upload and filepicker draft).
        $file = null;
        $filepath = null;
        $isdraft = false;
        $filename = null;
        if (isset($_FILES['pdffile']) && $_FILES['pdffile']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['pdffile'];
            $filename = clean_filename($file['name']);
        } else {
            $errcode = $_FILES['pdffile']['error'] ?? null;
            // Attempt to retrieve file from draft area (filepicker).
            $draftid = file_get_submitted_draft_itemid('pdffile');
            if (!empty($draftid)) {
                $fs = get_file_storage();
                $usercontext = context_user::instance($USER->id);
                $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftid, 'id', false);
                if (!empty($files)) {
                    // Take the first actual file (not a directory).
                    foreach ($files as $f) {
                        if ($f->is_directory()) {
                            continue;
                        }
                        $tempdir = make_temp_directory('questionconverter');
                        if ($filename === null) {
                            $filename = clean_filename($f->get_filename());
                        }
                        $filepath = $tempdir . '/' . $filename;
                        file_put_contents($filepath, $f->get_content());
                        $file = [
                            'tmp_name' => $filepath,
                            'name' => $filename,
                            'type' => $f->get_mimetype(),
                            'size' => $f->get_filesize(),
                            'error' => UPLOAD_ERR_OK,
                        ];
                        $isdraft = true;
                        break;
                    }
                }
            }
            if (!$file) {
                // If there is no file, throw the usual exception.
                throw new moodle_exception('erroruploadfile', 'local_questionconverter');
            }
        }

        // If we get here, we have $file (or created from draft).
        // Validate that it is a PDF.
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimetype = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if ($mimetype !== 'application/pdf') {
            throw new moodle_exception('invalidpdffile', 'local_questionconverter');
        }

        // If it is not a draft, move the file to a temporary directory.
        // (if we already created the file from a draft, it is already in temp).
        if (!$isdraft) {
            $tempdir = make_temp_directory('questionconverter');

            $filepath = $tempdir . '/' . $filename;

            // Verify that the temporary file exists and was uploaded via HTTP POST.
            if (!is_uploaded_file($file['tmp_name']) || !file_exists($file['tmp_name'])) {
                throw new moodle_exception('erroruploadfile', 'local_questionconverter');
            }

            // Attempt to move; if that fails, attempt to copy as a fallback.
            if (!@move_uploaded_file($file['tmp_name'], $filepath)) {
                // Fallback: copy.
                if (!@copy($file['tmp_name'], $filepath)) {
                    $err = error_get_last();
                    throw new moodle_exception('erroruploadfile', 'local_questionconverter');
                } else {
                    /* Delete the original temporary file if copying was successful */
                    @unlink($file['tmp_name']);
                }
            }
        }
        /* Get if it has indicators */
        $withindicators = optional_param('with_indicators', 0, PARAM_INT);
        /* Parsear the PDF */
        $parser = new pdf_parser();
        if ($withindicators) {
            /* Format with indicators */
            $result = $parser->parse_with_indicators($filepath);
            if (empty($result['indicators'])) {
                throw new moodle_exception('noindicatorsfound', 'local_questionconverter');
            }
            /* Import each indicator as a separate category */
            $importer = new question_importer($courseid);
            $importeddata = [];
            foreach ($result['indicators'] as $indicator) {
                $namefile = clean_param($filename, PARAM_TEXT);
                $namefile = preg_replace('/\.pdf$/i', '', $namefile);
                $categoryname = $namefile . '_Indicador_' . $indicator['number'];
                $imported = $importer->import_questions(
                    $indicator['questions'],
                    $categoryname,
                    $courseid,
                );
                $importeddata[] = [
                    'category' => $categoryname,
                    'categoryid' => $imported['categoryid'],
                    'count' => $imported['count'],
                ];
            }
            $totalquestions = array_sum(array_column($importeddata, 'count'));
        } else {
            /* Format without indicators */
            $questions = $parser->parse_standard($filepath);
            if (empty($questions)) {
                throw new moodle_exception('noquestionsfound', 'local_questionconverter');
            }
            /* Import to a single category */
            $categoryname = clean_param($filename, PARAM_TEXT);
            $categoryname = preg_replace('/\.pdf$/i', '', $categoryname);
            $importer = new question_importer($courseid);
            $imported = $importer->import_questions($questions, $categoryname, $courseid);
            $totalquestions = $imported['count'];
            $importeddata = [[
                'category' => $categoryname,
                'categoryid' => $imported['categoryid'],
                'count' => $imported['count'],
            ]];
        }
        /* Clear temporary file */
        if (file_exists($filepath)) {
            unlink($filepath);
        }
        /* Redirect to success page */
        $successurl = new moodle_url('/local/questionconverter/success.php', [
            'courseid' => $courseid,
            'total' => $totalquestions,
            'categories' => count($importeddata),
            'categoryid' => $importeddata[0]['categoryid'],
        ]);
        redirect($successurl);
    } catch (Exception $e) {
        /* Clean temporary file in case of error */
        if (isset($filepath) && file_exists($filepath)) {
            unlink($filepath);
        }
        \core\notification::error($e->getMessage());
    }
}
echo $OUTPUT->header();
$templatedata = [
    'form_action' => (new moodle_url('/local/questionconverter/index.php'))->out(false),
    'sesskey' => sesskey(),
    'courseid' => $courseid,
    'year' => date('Y'),
    'link_return' => (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
    'form_html' => $form->render(),
    ];
echo $OUTPUT->render_from_template('local_questionconverter/main', $templatedata);
echo $OUTPUT->footer();
