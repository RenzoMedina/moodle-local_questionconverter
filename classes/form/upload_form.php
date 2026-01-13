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
 * Upload form class
 *
 * @package     local_questionconverter
 * @copyright   2026 Renzo Medina <medinast30@gmail.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_questionconverter\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class upload_form extends \moodleform {
    
    /**
     * Define el formulario
     */
    public function definition() {
        $mform = $this->_form;
        $courseid = $this->_customdata['courseid'];
        
        // Título de sección
        $mform->addElement('header', 'general', get_string('uploadpdf', 'local_questionconverter'));
        
        // Checkbox para indicadores
        $mform->addElement(
            'advcheckbox',
            'with_indicators',
            get_string('withindicators', 'local_questionconverter'),
            get_string('withindicators_help', 'local_questionconverter')
        );
        $mform->setType('with_indicators', PARAM_BOOL);
        $mform->setDefault('with_indicators', 0);
        $mform->addHelpButton('with_indicators', 'withindicators', 'local_questionconverter');
        
        // Campo de archivo
        $mform->addElement(
            'filepicker',
            'pdffile',
            get_string('pdffile', 'local_questionconverter'),
            null,
            [
                'maxbytes' => 10485760, // 10 MB
                'accepted_types' => ['.pdf']
            ]
        );
        $mform->addRule('pdffile', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('pdffile', 'pdffile', 'local_questionconverter');
        
        // Campo oculto con el ID del curso
        $mform->addElement('hidden', 'courseid', $courseid);
        $mform->setType('courseid', PARAM_INT);
        
        // Botones de acción
        $this->add_action_buttons(true, get_string('convert', 'local_questionconverter'));
    }
    
    /**
     * Validación del formulario
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        
        // Validar que el archivo sea PDF
        if (isset($files['pdffile'])) {
            $file = $files['pdffile'];
            
            if ($file && !empty($file['tmp_name'])) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mimetype = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
                
                if ($mimetype !== 'application/pdf') {
                    $errors['pdffile'] = get_string('invalidpdffile', 'local_questionconverter');
                }
            }
        }
        
        return $errors;
    }
}