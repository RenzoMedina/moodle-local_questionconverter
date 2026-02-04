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
 * PDF parser class.
 *
 * @package     local_questionconverter
 * @copyright   2026 Renzo Medina <medinast30@gmail.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_questionconverter\form;
define( 'MOODLE_INTERNAL', true );
global $CFG;
require_once( $CFG->libdir . '/formslib.php' );

/**
 * Upload form class.
 * @package     local_questionconverter
 * @copyright   2026 Renzo Medina <medinast30@gmail.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload_form extends \moodleform {
    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);
        $mform->addElement('filepicker', 'pdffile', get_string('text-uploadpdf', 'local_questionconverter'),
            null, [
                'accepted_types' => ['.pdf'],
                'maxfiles' => 1,
                'maxbytes' => 10485760 // 10 MB
            ]);
        $mform->addRule('pdffile', null, 'required', null, 'client');
        $mform->addElement('advcheckbox', 'with_indicators', '', get_string('text-indicators', 'local_questionconverter'), '', [], ['0', '1']);
        $this->add_action_buttons(true, get_string('text-convert-and-import', 'local_questionconverter'));
    }
    /**
     * Form validation.
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (empty($files['pdffile']['name'])) {
            $errors['pdffile'] = get_string('erroruploadfile', 'local_questionconverter');
        } else {
            $filetype = mimeinfo('type', $files['pdffile']['name']);
            if ($filetype !== 'application/pdf') {
                $errors['pdffile'] = get_string('invalidpdffile', 'local_questionconverter');
            }
        }
        return $errors;
    }
}
