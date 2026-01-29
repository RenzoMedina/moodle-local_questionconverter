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
 * Question importer class.
 *
 * @package     local_questionconverter
 * @copyright   2026 Renzo Medina <medinast30@gmail.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_questionconverter\converter;
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/question/format.php');
require_once($CFG->dirroot . '/question/editlib.php');
require_once($CFG->dirroot . '/question/format/xml/format.php');

/**
 * Summary of question_importer
 */
class question_importer {
    /** @var \context El contexto del curso */
    private $context;
    /**
     * Constructor
     * @param \context $context Contexto del curso
     */
    public function __construct($courseid) {
        $this->context = \context_course::instance($courseid);
    }
    /**
     * Importar preguntas directamente al banco de preguntas
     * @param array $questions Array de preguntas parseadas
     * @param string $category_name Nombre de la categoría
     * @param int $courseid ID del curso
     * @return array Información de la importación
     */
    public function import_questions($questions, $categoryname, $courseid) {
        global $DB;
        // Crear o buscar la categoría.
        $category = $this->get_or_create_category($categoryname, $courseid);
        $importedcount = 0;
        foreach ($questions as $q) {
            if (empty($q['question']) || empty($q['type'])) {
                continue;
            }
            $type = strtolower(trim($q['type']));
            try {
                switch($type) {
                    case 'multichoice':
                        $this->import_multichoice($q, $category->id);
                        $importedcount++;
                        break;
                    case 'essay':
                        $this->import_essay($q, $category->id);
                        $importedcount++;
                        break;
                    case 'truefalse':
                        $this->import_truefalse($q, $category->id);
                        $importedcount++;
                        break;
                    default:
                        debugging("Tipo de pregunta no reconocido: {$q['type']}", DEBUG_DEVELOPER);
                }
            } catch (\Exception $e) {
                debugging('Error importing question: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        $this->update_category_question_count($category->id);
        return [
            'categoryid' => $category->id,
            'category' => $category->name,
            'count' => $importedcount,
        ];
    }
    /**
     * Obtener o crear una categoría
     * @param string $name Nombre de la categoría
     * @param int $courseid ID del curso
     * @return object Categoría
     */
    private function get_or_create_category($name, $courseid) {
        global $DB;
        $context = \context_course::instance($courseid);
        // Buscar categoría existente.
        $category = $DB->get_record('question_categories', [
            'name' => $name,
            'contextid' => $context->id,
        ]);
        if ($category) {
            return $category;
        }
        // Obtener la categoría top para este contexto.
        $topcategory = $DB->get_record('question_categories', [
            'contextid' => $context->id,
            'parent' => 0,
        ]);
        if (!$topcategory) {
            // Crear categoría top si no existe.
            $topcategory = new \stdClass();
            $topcategory->name = get_string('topcategoryname', 'local_questionconverter');
            $topcategory->contextid = $context->id;
            $topcategory->info = get_string('topcategoryinfo', 'local_questionconverter');
            $topcategory->infoformat = FORMAT_HTML;
            $topcategory->stamp = make_unique_id_code();
            $topcategory->parent = 0;
            $topcategory->sortorder = 999;
            $topcategory->id = $DB->insert_record('question_categories', $topcategory);
        }
        // Crear nueva categoría.
        $category = new \stdClass();
        $category->name = $name;
        $category->contextid = $context->id;
        $category->info = get_string('importedcategoryinfo', 'local_questionconverter');
        $category->infoformat = FORMAT_HTML;
        $category->stamp = make_unique_id_code();
        $category->parent = $topcategory->id;
        $category->sortorder = 999;
        $category->id = $DB->insert_record('question_categories', $category);
        return $category;
    }
    /**
     * Summary of update_category_question_count
     * @param mixed $categoryid
     * @return void
     */
    private function update_category_question_count($categoryid) {
        global $DB;
        // Actualizar el campo 'questioncount' en la categoría (si existe en tu versión de Moodle).
        if ($DB->get_manager()->field_exists('question_categories', 'questioncount')) {
            $count = $DB->count_records('question', ['category' => $categoryid]);
            $DB->set_field('question_categories', 'questioncount', $count, ['id' => $categoryid]);
        }
        // Limpiar caché de preguntas.
        \cache::make('core', 'questiondata')->purge();
        \question_bank::notify_question_edited($categoryid);
    }
    /**
     * Importar pregunta de opción múltiple
     * @param array $data Datos de la pregunta
     * @param int $categoryid ID de la categoría
     * @return int ID de la pregunta creada
     */
    private function import_multichoice($data, $categoryid) {
        global $DB, $USER;
        // Crear pregunta base.
        $question = new \stdClass();
        $question->category = $categoryid;
        $question->name = get_string('questionnameprefix', 'local_questionconverter') . $data['number'];
        $question->questiontext = $data['question'];
        $question->questiontextformat = FORMAT_HTML;
        $question->generalfeedback = '';
        $question->generalfeedbackformat = FORMAT_HTML;
        $question->defaultmark = 1;
        $question->penalty = 0.3333333;
        $question->qtype = 'multichoice';
        $question->length = 1;
        $question->stamp = make_unique_id_code();
        $question->version = make_unique_id_code();
        $question->hidden = 0;
        $question->timecreated = time();
        $question->timemodified = time();
        $question->createdby = $USER->id;
        $question->modifiedby = $USER->id;
        $question->id = $DB->insert_record('question', $question);
        // Crear entrada en el banco de preguntas (Moodle 4.0+).
        $this->create_question_bank_entry($question);
        // Opciones de multichoice.
        $multichoice = new \stdClass();
        $multichoice->questionid = $question->id;
        $multichoice->single = 1;
        $multichoice->shuffleanswers = 0;
        $multichoice->answernumbering = 'abc';
        $multichoice->correctfeedback = '';
        $multichoice->correctfeedbackformat = FORMAT_HTML;
        $multichoice->partiallycorrectfeedback = '';
        $multichoice->partiallycorrectfeedbackformat = FORMAT_HTML;
        $multichoice->incorrectfeedback = '';
        $multichoice->incorrectfeedbackformat = FORMAT_HTML;
        $multichoice->answernumbering = 'abc';
        $multichoice->shownumcorrect = 0;
        $multichoice->showstandardinstruction = 0;
        $DB->insert_record('qtype_multichoice_options', $multichoice);
        $fractionmap = [
            'a' => 0, 'b' => 0, 'c' => 0, 'd' => 0, 'e' => 0,
        ];
        if (isset($data['correctanswer']) && isset($fractionmap[$data['correctanswer']])) {
            $fractionmap[$data['correctanswer']] = 1;
        }
        foreach (['a', 'b', 'c', 'd', 'e'] as $letter) {
            if (!isset($data['options'][$letter])) {
                continue;
            }
            $answer = new \stdClass();
            $answer->question = $question->id;
            $answer->answer = '<p>' . $data['options'][$letter] . '</p>';
            $answer->answerformat = FORMAT_HTML;
            $answer->fraction = $fractionmap[$letter];
            $answer->feedback = ($letter === $data['correctanswer']) ? ($data['feedback'] ?? '') : '';
            $answer->feedbackformat = FORMAT_HTML;
            $answer->maxmark = 1;
            $DB->insert_record('question_answers', $answer);
        }
        return $question->id;
    }
    /**
     * Summary of import_truefalse
     * @param mixed $data
     * @param mixed $categoryid
     */
    private function import_truefalse($data, $categoryid) {
        global $DB, $USER;
        // Crear pregunta base.
        $question = new \stdClass();
        $question->category = $categoryid;
        $question->parent = 0;
        $question->name = get_string('questionnameprefix', 'local_questionconverter') . $data['number'];
        $question->questiontext = $data['question'];
        $question->questiontextformat = FORMAT_HTML;
        $question->generalfeedback = '';
        $question->generalfeedbackformat = FORMAT_HTML;
        $question->defaultmark = 1;
        $question->penalty = 1;
        $question->qtype = 'truefalse';
        $question->length = 1;
        $question->stamp = make_unique_id_code();
        $question->version = make_unique_id_code();
        $question->hidden = 0;
        $question->timecreated = time();
        $question->timemodified = time();
        $question->createdby = $USER->id;
        $question->modifiedby = $USER->id;
        $question->id = $DB->insert_record('question', $question);
        // Crear entrada en el banco de preguntas (Moodle 4.0+).
        $this->create_question_bank_entry($question);
        // Determinar respuesta correcta.
        $correctanswer = isset($data['correctanswer']) ? strtolower(trim($data['correctanswer'])) : '';
        $correctanswer = trim($correctanswer, ". \t\n\r\0\x0B");
        $istrue = false;
        if (in_array($correctanswer, [
            'verdadero',
            'true',
            'v',
            'a',
            '1',
            'verdadero.',
            'true.',
            'verdadero',
            'verdadero ',
            ], true)) {
            $istrue = true;
        } else if (in_array($correctanswer, [
            'falso',
            'false',
            'f',
            'b',
            '0',
            'falso.',
            'false.',
            'falso',
            'falso ',
            ], true)) {
            $istrue = false;
        } else {
            $istrue = false;
        }
        $feedbackcorrecto = $data['feedback'] ?? '';
        // Crear respuesta "Verdadero".
        $answertrue = new \stdClass();
        $answertrue->question = $question->id;
        $answertrue->answer = get_string('true', 'qtype_truefalse');
        $answertrue->answerformat = FORMAT_HTML;
        $answertrue->fraction = $istrue ? 1 : 0;
        $answertrue->feedback = $istrue ? $feedbackcorrecto : '';
        $answertrue->feedbackformat = FORMAT_HTML;
        $trueid = $DB->insert_record('question_answers', $answertrue);
        // Crear respuesta "Falso".
        $answerfalse = new \stdClass();
        $answerfalse->question = $question->id;
        $answerfalse->answer = get_string('false', 'qtype_truefalse');
        $answerfalse->answerformat = FORMAT_HTML;
        $answerfalse->fraction = $istrue ? 0 : 1;
        $answerfalse->feedback = !$istrue ? $feedbackcorrecto : '';
        $answerfalse->feedbackformat = FORMAT_HTML;
        $falseid = $DB->insert_record('question_answers', $answerfalse);
        // Opciones de truefalse.
        $truefalse = new \stdClass();
        $truefalse->question = $question->id;
        $truefalse->trueanswer = $trueid;
        $truefalse->falseanswer = $falseid;
        $truefalse->showstandardinstruction = 1;
        $DB->insert_record('question_truefalse', $truefalse);
        return $question->id;
    }
    /**
     * Importar pregunta de ensayo
     * @param array $data Datos de la pregunta
     * @param int $categoryid ID de la categoría
     * @return int ID de la pregunta creada
     */
    private function import_essay($data, $categoryid) {
        global $DB, $USER;
        // Crear pregunta base.
        $question = new \stdClass();
        $question->category = $categoryid;
        $question->name = get_string('questionnameprefix', 'local_questionconverter') . $data['number'];
        $question->questiontext = $data['question'];
        $question->questiontextformat = FORMAT_HTML;
        $question->generalfeedback = $data['feedback'] ?? '';
        $question->generalfeedbackformat = FORMAT_HTML;
        $question->defaultmark = 1;
        $question->penalty = 0;
        $question->qtype = 'essay';
        $question->length = 1;
        $question->stamp = make_unique_id_code();
        $question->version = make_unique_id_code();
        $question->hidden = 0;
        $question->timecreated = time();
        $question->timemodified = time();
        $question->createdby = $USER->id;
        $question->modifiedby = $USER->id;
        $question->id = $DB->insert_record('question', $question);
        // Crear entrada en el banco de preguntas (Moodle 4.0+).
        $this->create_question_bank_entry($question);
        // Opciones de essay.
        $essay = new \stdClass();
        $essay->questionid = $question->id;
        $essay->responseformat = 'editor';
        $essay->responserequired = 1;
        $essay->responsefieldlines = 15;
        $essay->attachments = 0;
        $essay->attachmentsrequired = 0;
        $essay->maxbytes = 0;
        $essay->filetypeslist = '';
        $essay->graderinfo = '';
        $essay->graderinfoformat = FORMAT_HTML;
        $essay->responsetemplate = '';
        $essay->responsetemplateformat = FORMAT_HTML;
        $DB->insert_record('qtype_essay_options', $essay);
        return $question->id;
    }
    /**
     * Crear entrada en el banco de preguntas (Moodle 4.0+)
     * @param object $question Pregunta
     */
    private function create_question_bank_entry($question) {
        global $DB, $USER;
        // Verificar si la tabla existe (Moodle 4.0+).
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('question_bank_entries');
        // Tabla no existe en versiones antiguas.
        if (!$dbman->table_exists($table)) {
            return;
        }
         // Verificar si ya existe una entrada para esta pregunta.
        $existing = $DB->get_record('question_versions', ['questionid' => $question->id]);
        if ($existing) {
            return true;
        }
        // Crear entrada en question_bank_entries.
        $entry = new \stdClass();
        $entry->questioncategoryid = $question->category;
        $entry->idnumber = null;
        $entry->ownerid = $USER->id;
        $entry->status = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;
        $entry->timecreated = time();
        $entry->timemodified = time();
        $entry->questionusageid = null;
        $entry->id = $DB->insert_record('question_bank_entries', $entry);
        // Crear versión en question_versions.
        $version = new \stdClass();
        $version->questionbankentryid = $entry->id;
        $version->version = 1;
        $version->questionid = $question->id;
        $version->status = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;
        $version->timecreated = time();
        $DB->insert_record('question_versions', $version);
        $entry->versionid = $version->id;
        $DB->update_record('question_bank_entries', $entry);
    }
}
