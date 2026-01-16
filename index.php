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
require_once($CFG->libdir. '/questionlib.php');

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once(__DIR__ . '/vendor/autoload.php');
}

require_once(__DIR__ . '/classes/converter/pdf_parser.php');
require_once(__DIR__ . '/classes/converter/question_importer.php');

use local_questionconverter\converter\pdf_parser;
use local_questionconverter\converter\question_importer;


$courseid = optional_param('courseid',0, PARAM_INT);
if (empty($courseid)) {
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if (preg_match('/[?&]id=(\d+)/', $referer,$matches)) {
        $courseid = (int)$matches[1];
    } else {
        redirect(new moodle_url('/my/'));
        die();
    }
}
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('moodle/question:add', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/questionconverter/index.php',['courseid'=>$courseid]));
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('pluginname', 'local_questionconverter'));
$PAGE->set_heading(get_string('pluginname', 'local_questionconverter'));
$PAGE->requires->css(new moodle_url('/local/questionconverter/tailwindcss/dist/output.css'));
$PAGE->requires->js(new moodle_url('/local/questionconverter/js/uploader.js'));

// Procesar el formulario si se ha enviado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    
    try {
        // Verificar que se subió un archivo (soporta upload directo y filepicker draft)
        $file = null;
        $filepath = null;
        $isdraft = false;

        if (isset($_FILES['pdffile']) && $_FILES['pdffile']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['pdffile'];
        } else {
            $errcode = $_FILES['pdffile']['error'] ?? null;

            // Intentar obtener archivo desde draft area (filepicker)
            $draftid = file_get_submitted_draft_itemid('pdffile');

            if (!empty($draftid)) {
                $fs = get_file_storage();
                $usercontext = context_user::instance($USER->id);
                $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftid, 'id', false);

                if (!empty($files)) {
                    // Tomar el primer archivo real (no directorio)
                    foreach ($files as $f) {
                        if ($f->is_directory()) {
                            continue;
                        }
                        $tempdir = make_temp_directory('questionconverter');
                        if (!is_dir($tempdir)) {
                            @mkdir($tempdir, 0777, true);
                        }
                        $filename = $f->get_filename();
                        $filepath = $tempdir . '/' . $filename;
                        file_put_contents($filepath, $f->get_content());
                        $file = [
                            'tmp_name' => $filepath,
                            'name' => $filename,
                            'type' => $f->get_mimetype(),
                            'size' => $f->get_filesize(),
                            'error' => UPLOAD_ERR_OK
                        ];
                        $isdraft = true;
                        break;
                    }
                } 
            }

            if (!$file) {
                // Si no hay archivo, lanzar la excepción de siempre
                throw new moodle_exception('erroruploadfile', 'local_questionconverter');
            }
        }

        // Si llegamos aquí tenemos $file (o creado desde draft)
        // Validar que sea PDF
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimetype = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if ($mimetype !== 'application/pdf') {
            throw new moodle_exception('invalidpdffile', 'local_questionconverter');
        }

        // Si no es draft, mover archivo a directorio temporal (si ya creamos el archivo desde draft, ya está en temp)
        if (!$isdraft) {
            $filename = clean_filename($file['name']);
            $tempdir = make_temp_directory('questionconverter');

            // Asegurar que el directorio temporal exista
            if (!is_dir($tempdir)) {
                @mkdir($tempdir, 0777, true);
            }

            $filepath = $tempdir . '/' . $filename;

            // Comprobar que el fichero temporal existe y fue subido vía HTTP POST
            if (!is_uploaded_file($file['tmp_name']) || !file_exists($file['tmp_name'])) {
                throw new moodle_exception('erroruploadfile', 'local_questionconverter');
            }

            // Intentar mover; si falla, intentar copiar como fallback
            if (!@move_uploaded_file($file['tmp_name'], $filepath)) {
                // Fallback: copy
                if (!@copy($file['tmp_name'], $filepath)) {
                    $err = error_get_last();
                    throw new moodle_exception('erroruploadfile', 'local_questionconverter');
                } else {
                    // Borrar el temporal original si copy tuvo éxito
                    @unlink($file['tmp_name']);
                }
            }
        }
        
        // Obtener si tiene indicadores
        $with_indicators = optional_param('with_indicators', 0, PARAM_INT);
        
        // Parsear el PDF
        $parser = new pdf_parser();
        
        if ($with_indicators) {
            // Formato con indicadores
            $result = $parser->parse_with_indicators($filepath);
            
            if (empty($result['indicators'])) {
                throw new moodle_exception('noindicatorsfound', 'local_questionconverter');
            }
            
            // Importar cada indicador como categoría separada
            $importer = new question_importer($context);
            $imported_data = [];
            
            foreach ($result['indicators'] as $indicator) {
                $category_name = "Indicador {$indicator['number']}: {$indicator['title']}";
                $imported = $importer->import_questions(
                    $indicator['questions'],
                    $category_name,
                    $courseid
                );
                
                $imported_data[] = [
                    'category' => $category_name,
                    'categoryid' => $imported['categoryid'],
                    'count' => $imported['count']
                ];
            }
            
            $total_questions = array_sum(array_column($imported_data, 'count'));
            
        } else {
            // Formato sin indicadores
            $questions = $parser->parse_standard($filepath);
            
            if (empty($questions)) {
                throw new moodle_exception('noquestionsfound', 'local_questionconverter');
            }
            
            // Importar a una sola categoría
            $category_name = clean_param($filename, PARAM_TEXT);
            $category_name = preg_replace('/\.pdf$/i', '', $category_name);
            
            $importer = new question_importer($context);
            $imported = $importer->import_questions($questions, $category_name, $courseid);
            
            $total_questions = $imported['count'];
            $imported_data = [[
                'category' => $category_name,
                'categoryid' => $imported['categoryid'],
                'count' => $imported['count']
            ]];
        }
        
        // Limpiar archivo temporal
        if (file_exists($filepath)) {
            unlink($filepath);
        }
        
        // Redirigir a página de éxito
        $success_url = new moodle_url('/local/questionconverter/success.php', [
            'courseid' => $courseid,
            'total' => $total_questions,
            'categories' => count($imported_data),
            'categoryid' => $imported_data[0]['categoryid'],
        ]);
        
        redirect($success_url);
        
    } catch (Exception $e) {
        // Limpiar archivo temporal en caso de error
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
    'year'=> date('Y'),
    'message' => get_string('message', 'local_questionconverter'),
    'footer' => get_string('stringfooter', 'local_questionconverter'),
    'name-return' => get_string('name-return', 'local_questionconverter'),
    'link-return' => (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
    ];

echo $OUTPUT->render_from_template('local_questionconverter/main', $templatedata);
echo $OUTPUT->footer();
