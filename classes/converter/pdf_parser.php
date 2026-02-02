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
namespace local_questionconverter\converter;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/local/questionconverter/vendor/autoload.php');

use Smalot\PdfParser\Parser;

/**
 * Summary of pdf_parser
 */
class pdf_parser {
    /** @var Parser Parser de PDF */
    private $parser;
    /**
     * Constructor
     */
    public function __construct() {
        $this->parser = new Parser();
    }
    /**
     * Parsear PDF con formato estándar (sin indicadores)
     * @param string $filepath Ruta del archivo PDF
     * @return array Array de preguntas
     */
    public function parse_standard($filepath) {
        /* Comprobar si el archivo existe y no está vacío */
        if (!file_exists($filepath) || filesize($filepath) === 0) {
            throw new \moodle_exception('invalidpdffile', 'local_questionconverter');
        }
        try {
            $pdf = $this->parser->parseFile($filepath);
            $text = $pdf->getText();
            $questions = $this->parse_new_format($text);
            if (empty($questions)) {
                $questions = $this->parse_old_format($text);
            }
        } catch (\Throwable $e) {
            throw new \moodle_exception('errorparsingpdf', 'local_questionconverter', '', null, $e->getMessage());
        }
        if (empty(trim($text))) {
            throw new \moodle_exception('noquestionsfound', 'local_questionconverter');
        }
        return $questions;
    }
     /**
      * Summary of parse_with_indicators
      * @param mixed $filepath
      * @return array{indicators: array, success: bool}
      */
    public function parse_with_indicators($filepath) {
        if (!file_exists($filepath) || filesize($filepath) === 0) {
            return ['success' => false,
                'indicators' => [],
                ];
        }
        try {
            $pdf = $this->parser->parseFile($filepath);
            $text = $pdf->getText();
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'indicators' => [],
                ];
        }
        if (empty(trim($text))) {
            return [
                'success' => false,
                'indicators' => [],
                ];
        }
        /* Extraer indicadores */
        $indicators = $this->extract_indicators($text);
        if (empty($indicators)) {
            return [
                'success' => false,
                'indicators' => [],
                ];
        }
        $result = [];
        foreach ($indicators as $num => $indicator) {
            $questions = $this->process_indicator($indicator);
            /* Filtrar preguntas válidas */
            $validquestions = array_filter ($questions, function($q) {
                return isset($q['type']) &&
                       $q['type'] !== 'ERROR' &&
                       in_array($q['type'], ['multichoice', 'essay', 'truefalse']);
            });
            if (!empty($validquestions)) {
                $result[] = [
                    'number' => $num,
                    'title' => $indicator['title'],
                    'questions' => array_values($validquestions),
                ];
            }
        }
        return [
            'success' => true,
            'indicators' => $result,
        ];
    }
    /**
     * Summary of parse_new_format
     * @param mixed $text
     * @return array{feedback: mixed, number: mixed, question: string, type: array|string[]}
     */
    private function parse_new_format($text) {
        $text = $this->clean_full_text($text);
        $pattern = '/N°\s*de\s+pregunta:\s*(\d+)\s*(.*?)\s*Retroalimentación:\s*(.*?)(?=N°\s*de\s+pregunta:|$)/s';
        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);
        $questions = [];
        foreach ($matches as $m) {
            $block = $m[0];
            $question = $this->proccess_question($block, $m);
            if ($question) {
                $questions[] = $question;
            }
        }
        return $questions;
    }
    /**
     * Summary of parse_old_format
     * @param mixed $text
     * @return array{correct_answer: string, feedback: string, number: string, options: array, question: string, type: string[]}
     */
    private function parse_old_format($text) {
        $text = preg_replace("/\r\n|\r/", "\n", $text);
        $text = preg_replace('/.*?plazos\s+establecidos\.\s*/is', '', $text);
        $text = preg_replace('/^(?:[A-ZÁÉÍÓÚÜÑ0-9\s\.\-]+?\n){1,3}/', '', $text);
        $pattern = '/^\s*(\d+)\.\s*'
                    . '(.*?) '
                    . '\s+'
                    . 'a\)\s*(.*?)\n'
                    . 'b\)\s*(.*?)\n'
                    . 'c\)\s*(.*?)\n'
                    . 'd\)\s*(.*?)\n'
                    . 'e\)\s*(.*?)\n'
                    . '.*?Respuesta\s*correcta\s*'
                    . 'Retroalimentación\s*'
                    . '([aA-eE])'
                    . '\s*'
                    . '((?:(?!\n\s*\d+\.\s).)*)'
                    . '/smx';
        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);
        $questions = [];
        foreach ($matches as $m) {
            $questions[] = [
                'type' => 'multichoice',
                'number' => trim($m[1]),
                'question' => trim($m[2]),
                'options' => [
                    'a' => trim($m[3]),
                    'b' => trim($m[4]),
                    'c' => trim($m[5]),
                    'd' => trim($m[6]),
                    'e' => trim($m[7]),
                ],
                'correct_answer' => strtolower(trim($m[8])),
                'feedback' => trim($m[9]),
            ];
        }
        return $questions;
    }
    /**
     * Summary of extract_indicators
     * @param mixed $text
     * @return array{content: string, title: string[]}
     */
    private function extract_indicators($text) {
        preg_match_all('/Indicador\s+(\d+)\s*[:\s]*(.*?)(?=\n|$)/is', $text, $matches, PREG_OFFSET_CAPTURE);
        $indicatorcount = isset($matches[0]) ? count($matches[0]) : 0;
        if ($indicatorcount > 0) {
            $list = [];
            for ($i = 0; $i < min(10, $indicatorcount); $i++) {
                $list[] = trim($matches[1][$i][0]) . ':' . trim($matches[2][$i][0]);
            }
        }
        $indicators = [];
        for ($i = 0; $i < count($matches[0]); $i++) {
            $num = (int)$matches[1][$i][0];
            $title = trim($matches[2][$i][0]);
            $start = $matches[0][$i][1] + strlen($matches[0][$i][0]);
            if ($i < count($matches[0]) - 1) {
                $end = $matches[0][$i + 1][1];
            } else {
                $end = strlen($text);
            }
            $content = substr($text, $start, $end - $start);
            $indicators[$num] = [
                'title' => $title,
                'content' => $content,
            ];
        }
        return $indicators;
    }
    /**
     * Summary of process_indicator
     * @param mixed $indicator
     * @return array{feedback: string, number: string, question: string, type: array|string[]}
     */
    private function process_indicator($indicator) {
        $content = $indicator['content'];
        preg_match_all('/N°\s*de\s*pregunta:\s*(\d+)/is', $content, $matches, PREG_OFFSET_CAPTURE);
        $questions = [];
        for ($i = 0; $i < count($matches[0]); $i++) {
            $start = $matches[0][$i][1];
            if ($i < count($matches[0]) - 1) {
                $end = $matches[0][$i + 1][1];
            } else {
                $end = strlen($content);
            }
            $block = substr($content, $start, $end - $start);
            $block = trim($block);
            $question = $this->process_question_from_block($block);
            if ($question) {
                $questions[] = $question;
            }
        }
        return $questions;
    }
    /**
     * Summary of category_question
     * @param mixed $content
     * @return string|string[]
     */
    private function category_question($content) {
        list($alternativetf, $typeft) = $this->extract_options_truefalse($content);
        if ($typeft !== '') {
            return $typeft;
        }
        $typeessay = $this->extract_options_essay($content);
        if ($typeessay !== '') {
            return $typeessay;
        }
        list($alternativemulti, $typemultichoices) = $this->extract_options_multichoice($content);
        if ($typemultichoices !== '') {
            return $typemultichoices;
        }
        return '';
    }
    /**
     * Summary of process_question_from_block
     * @param mixed $block
     * @return array[]|array{feedback: string, number: string, question: string, type: string|string[]|null}
     */
    private function process_question_from_block($block) {
        $pattern = '/N°\s*de\s+pregunta:\s*(\d+)\s*(.*?)\s*Retroalimentación:\s*(.*?)(?=N°\s*de\s+pregunta:\s*\d+|\z)/s';
        if (!preg_match($pattern, $block, $m)) {
            return null;
        }
        $number = trim($m[1]);
        $content = trim($m[2]);
        $feedback = isset($m[3]) ? $this->clean_feedback($m[3]) : '';
        $type = $this->category_question($content);

        if (empty($type)) {
            return null;
        }
        $question = [
            'type' => $type,
            'number' => $number,
            'question' => $this->clean_question($content),
            'feedback' => $feedback,
        ];
        if ($type === 'multichoice') {
            $options = $this->get_options($content, $type);
            $question['options'] = $options;
            $answer = $this->extract_answer($content);
            $question['correct_answer'] = $this->clean_answer($answer);
        } else if ($type === 'truefalse') {
            $options = $this->get_options($content, $type);
            $question['options'] = $options;
            $answer = $this->extract_answer($content);
            $question['correct_answer'] = $this->clean_answer($answer);
        }
        return $question;
    }
    /**
     * Summary of extract_options_multichoice
     * @param mixed $content
     * @return array<string|string[]>
     */
    private function extract_options_multichoice($content) {
        $options = [];
        $type = '';
        $pattern = '/Alternativas\s*(.*?)(?=Respuesta\s+correcta|$)/is';
        if (!preg_match($pattern, $content, $m)) {
            return [$options, $type];
        }
        $optionstext = trim($m[1]);
        $patternoptions = '/([a-e])\)\s*(.*?)(?=\s*[a-e]\)|$)/s';
        preg_match_all($patternoptions, $optionstext, $matches, PREG_SET_ORDER);
        foreach ($matches as $opt) {
            $letter = strtolower($opt[1]);
            $text = trim($opt[2]);
            if (stripos($text, 'Alternativas') === false) {
                $options[$letter] = $text;
                $type = 'multichoice';
            }
        }
        return [$options, $type];
    }
    /**
     * Summary of extract_options_essay
     * @param mixed $content
     * @return string
     */
    private function extract_options_essay($content) {
        $type = '';
        $pattern = '/Escribe aquí tu respuesta/s';
        if (preg_match($pattern, $content)) {
            $type = 'essay';
        }
        return $type;
    }
    /**
     * Summary of extract_options_truefalse
     * @param mixed $content
     * @return array<string|string[]>
     */
    private function extract_options_truefalse($content) {
        $type = '';
        $options = [];
        $pattern = '/(verdadero\s*o\s*falso|v\s*\/\s*f|v\s*o\s*f)\s*(.*?)(?=respuesta\s+correcta|$)/isu';
        if (!preg_match($pattern, $content, $match)) {
            return [$options, $type];
        }
        $type = 'truefalse';
        $optionstext = $match[1];
        $patternoptions = '/([a-b])\)\s*([^\n]+)/';
        preg_match_all($patternoptions, $optionstext, $matches, PREG_SET_ORDER);
        foreach ($matches as $opt) {
            $letter = strtolower($opt[1]);
            $text = trim($opt[2]);
            if (stripos($text, 'Verdadero o falso') === false) {
                $options[$letter] = $text;
            }
        }
        return [$options, $type];
    }
    /**
     * Summary of extract_answer
     * @param mixed $content
     * @return string
     */
    private function extract_answer($content) {
        $pattern = '/Respuesta\s*correcta\s*([a-e]|verdadero|falso)/si';
        if (preg_match($pattern, $content, $match)) {
            return strtolower(trim($match[1]));
        }
        return '';
    }
    /**
     * Summary of clean_question
     * @param mixed $content
     * @return string
     */
    private function clean_question($content) {
        $cleaned = preg_replace('/\n+/', ' ', $content);
        $cleaned = preg_replace('/\s+Alternativas\s+[a-e]\).*$/is', '', $content);
        $cleaned = preg_replace('/\s+Escribe\s+aquí\s+tu\s+respuesta.*$/is', '', $cleaned);
        $cleaned = preg_replace('/\s+Verdadero\s+o\s+falso\s+[a-b]\).*$/is', '', $cleaned);
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        return trim($cleaned);
    }
    /**
     * Summary of clean_feedback
     * @param mixed $feedback
     * @return string
     */
    private function clean_feedback($feedback) {
        if (empty($feedback)) {
            return '';
        }
        $text = trim($feedback);
        // Limpiar hasta "semana.".
        if (preg_match('/(.*?semana\.)/s', $text, $match)) {
            return trim(preg_replace('/\s+/', ' ', $match[1]));
        }
        // Dividir por líneas y limpiar.
        $lines = explode("\n", $text);
        $validlines = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            if (preg_match('/^\d+$/', $line)) {
                break;
            }
            if (preg_match('/^(Indicador|N°\s*de\s*pregunta)/i', $line)) {
                break;
            }
            $validlines[] = $line;
        }
        $text = implode(' ', $validlines);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }
    /**
     * Summary of proccess_question
     * @param mixed $content
     * @param mixed $matches
     * @return array[]|array{feedback: mixed, number: mixed, question: string, type: string|string[]|null}
     */
    private function proccess_question($content, $matches = null) {
        if ($matches === null) {
            $pattern = '/N°\s*de\s+pregunta:\s*(\d+)\s*(.*?)\s*Retroalimentación:\s*(.*?)(?=N°\s*de\s+pregunta:\s*\d+|\z)/s';
            if (!preg_match($pattern, $content, $matches)) {
                return null;
            }
        }
        $number = isset($matches[1]) ? $matches[1] : '';
        $content = isset($matches[2]) ? $matches[2] : '';
        $feedback = isset($matches[3]) ? $matches[3] : '';
        $type = $this->category_question($content);
        if (empty($type)) {
            return null;
        }
        $question = [
            'type' => $type,
            'number' => $number,
            'question' => $this->clean_question($content),
            'feedback' => $feedback,
        ];
        if ($type === 'multichoice') {
            $options = $this->get_options($content, $type);
            $question['options'] = $options;
            $question['correct_answer'] = $this->extract_answer($content);
        } else if ($type === 'truefalse') {
            $options = $this->get_options($content, $type);
            $question['options'] = $options;
            $question['correct_answer'] = $this->extract_answer($content);
        }
        return $question;
    }
    /**
     * Summary of get_options
     * @param mixed $text
     * @param mixed $type
     * @return string|string[]
     */
    private function get_options($text, $type) {
        switch ($type) {
            case 'multichoice':
                list($options, $type) = $this->extract_options_multichoice($text);
                return $options;
            case 'truefalse':
                list($options, $type) = $this->extract_options_truefalse($text);
                return $options;
            case 'essay':
            default:
                return [];
        }
    }
    /**
     * Summary of clean_answer
     * @param mixed $answer
     * @return string
     */
    private function clean_answer($answer) {
        $answer = trim($answer);
        if (preg_match('/^[A-Ea-e]$/', $answer)) {
            return strtolower($answer);
        } else if (preg_match('/^(verdadero|falso)$/i', $answer)) {
            return strtolower($answer);
        } else {
            return '';
        }
    }
    /**
     * Summary of clean_full_text
     * @param mixed $content
     * @return string
     */
    private function clean_full_text($content) {
        $question = preg_split('/(N°\s*de\s*pregunta:\s*\d+)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        $cleantext = '';
        for ($i = 1; $i < count($question); $i += 2) {
            if (isset($question[$i]) && isset($question[$i + 1])) {
                $numberquestion = $question[$i];
                $contentquestions = $question[$i + 1];
            }
            $contentquestions = $this->clean_question_block($contentquestions);
            $cleantext .= $numberquestion . $contentquestions . "\n\n";
        }
        return trim($cleantext);
    }
    /**
     * Summary of clean_question_block
     * @param mixed $block
     * @return string
     */
    private function clean_question_block($block) {
        if (preg_match('/(.*?Retroalimentación:.*?)(?=\n\s*\d+\s+[A-Z]|$)/s', $block, $matches)) {
            return trim($matches[1]);
        }
        return trim($block);
    }
}
