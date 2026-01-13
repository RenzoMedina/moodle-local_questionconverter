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

class question_importer {
    
    /** @var \context El contexto del curso */
    private $context;
    
    /** @var \question_category_object Manejador de categorías */
    private $qcobject;
    
    /**
     * Constructor
     * 
     * @param \context $context Contexto del curso
     */
    public function __construct($context) {
        $this->context = $context;
        $this->qcobject = new \question_category_object(
            null,
            new \moodle_url('/local/questionconverter/index.php'),
            $context,
            0,
            null,
            0,
            null
        );
    }
    
    /**
     * Importar preguntas directamente al banco de preguntas
     * 
     * @param array $questions Array de preguntas parseadas
     * @param string $category_name Nombre de la categoría
     * @param int $courseid ID del curso
     * @return array Información de la importación
     */
    public function import_questions($questions, $category_name, $courseid) {
        global $DB;
        
        // Crear o buscar la categoría
        $category = $this->get_or_create_category($category_name, $courseid);
        
        $imported_count = 0;
        
        foreach ($questions as $q) {
            try {
                if ($q['type'] === 'multichoice') {
                    $this->import_multichoice($q, $category->id);
                    $imported_count++;
                } elseif ($q['type'] === 'essay') {
                    $this->import_essay($q, $category->id);
                    $imported_count++;
                }
            } catch (\Exception $e) {
                debugging('Error importing question: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        
        return [
            'categoryid' => $category->id,
            'category' => $category->name,
            'count' => $imported_count
        ];
    }
    
    /**
     * Obtener o crear una categoría
     * 
     * @param string $name Nombre de la categoría
     * @param int $courseid ID del curso
     * @return object Categoría
     */
    private function get_or_create_category($name, $courseid) {
        global $DB;
        
        // Buscar categoría existente
        $category = $DB->get_record('question_categories', [
            'name' => $name,
            'contextid' => $this->context->id
        ]);
        
        if ($category) {
            return $category;
        }
        
        // Obtener la categoría top para este contexto
        $top_category = $DB->get_record('question_categories', [
            'contextid' => $this->context->id,
            'parent' => 0
        ]);
        
        if (!$top_category) {
            // Crear categoría top si no existe
            $top_category = new \stdClass();
            $top_category->name = 'top';
            $top_category->contextid = $this->context->id;
            $top_category->info = 'Top category';
            $top_category->infoformat = FORMAT_HTML;
            $top_category->stamp = make_unique_id_code();
            $top_category->parent = 0;
            $top_category->sortorder = 999;
            $top_category->id = $DB->insert_record('question_categories', $top_category);
        }
        
        // Crear nueva categoría
        $category = new \stdClass();
        $category->name = $name;
        $category->contextid = $this->context->id;
        $category->info = 'Categoría importada desde PDF';
        $category->infoformat = FORMAT_HTML;
        $category->stamp = make_unique_id_code();
        $category->parent = $top_category->id;
        $category->sortorder = 999;
        
        $category->id = $DB->insert_record('question_categories', $category);
        
        return $category;
    }
    
    /**
     * Importar pregunta de opción múltiple
     * 
     * @param array $data Datos de la pregunta
     * @param int $categoryid ID de la categoría
     * @return int ID de la pregunta creada
     */
    private function import_multichoice($data, $categoryid) {
        global $DB, $USER;
        
        // Crear pregunta base
        $question = new \stdClass();
        $question->category = $categoryid;
        $question->name = 'P' . $data['number'];
        $question->questiontext = $data['question'];
        $question->questiontextformat = FORMAT_HTML;
        $question->generalfeedback = '';
        $question->generalfeedbackformat = FORMAT_HTML;
        $question->defaultmark = 0.5;
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
        
        // Opciones de multichoice
        $multichoice = new \stdClass();
        $multichoice->questionid = $question->id;
        $multichoice->single = 1;
        $multichoice->shuffleanswers = 0;
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
        
        // Insertar respuestas
        $fraction_map = [
            'a' => 0, 'b' => 0, 'c' => 0, 'd' => 0, 'e' => 0
        ];
        $fraction_map[$data['correct_answer']] = 1;
        
        $order = 1;
        foreach (['a', 'b', 'c', 'd', 'e'] as $letter) {
            $answer = new \stdClass();
            $answer->question = $question->id;
            $answer->answer = $data['options'][$letter];
            $answer->answerformat = FORMAT_HTML;
            $answer->fraction = $fraction_map[$letter];
            
            // Feedback solo para la respuesta correcta
            if ($letter === $data['correct_answer']) {
                $answer->feedback = $data['feedback'] ?? '';
            } else {
                $answer->feedback = '';
            }
            $answer->feedbackformat = FORMAT_HTML;
            
            $answer->id = $DB->insert_record('question_answers', $answer);
            
            // Vincular respuesta con multichoice
            $DB->insert_record('qtype_multichoice_answers', [
                'questionid' => $question->id,
                'answerid' => $answer->id,
                'sortorder' => $order++
            ]);
        }
        
        // Crear entrada en question_bank_entries
        $this->create_question_bank_entry($question);
        
        return $question->id;
    }
    
    /**
     * Importar pregunta de ensayo
     * 
     * @param array $data Datos de la pregunta
     * @param int $categoryid ID de la categoría
     * @return int ID de la pregunta creada
     */
    private function import_essay($data, $categoryid) {
        global $DB, $USER;
        
        // Crear pregunta base
        $question = new \stdClass();
        $question->category = $categoryid;
        $question->name = 'P' . $data['number'];
        $question->questiontext = $data['question'];
        $question->questiontextformat = FORMAT_HTML;
        $question->generalfeedback = $data['feedback'] ?? '';
        $question->generalfeedbackformat = FORMAT_HTML;
        $question->defaultmark = 0.5;
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
        
        // Opciones de essay
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
        
        // Crear entrada en question_bank_entries
        $this->create_question_bank_entry($question);
        
        return $question->id;
    }
    
    /**
     * Crear entrada en el banco de preguntas (Moodle 4.0+)
     * 
     * @param object $question Pregunta
     */
    private function create_question_bank_entry($question) {
        global $DB, $USER;
        
        // Verificar si la tabla existe (Moodle 4.0+)
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('question_bank_entries');
        
        if (!$dbman->table_exists($table)) {
            return; // Tabla no existe en versiones antiguas
        }
        
        // Crear entrada en question_bank_entries
        $entry = new \stdClass();
        $entry->questioncategoryid = $question->category;
        $entry->idnumber = null;
        $entry->ownerid = $USER->id;
        
        $entry->id = $DB->insert_record('question_bank_entries', $entry);
        
        // Crear versión en question_versions
        $version = new \stdClass();
        $version->questionbankentryid = $entry->id;
        $version->version = 1;
        $version->questionid = $question->id;
        $version->status = 'ready';
        
        $DB->insert_record('question_versions', $version);
    }
}