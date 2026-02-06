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
    /** @var Parser Parser of PDF */
    private $parser;
    /** @var array Cached pattern strings for current language */
    private $patterns = [];
    /**
     * Construct
     */
    public function __construct() {
        $this->parser = new Parser();
        $this->load_patterns();
    }
    /**
     * Load patterns
     * @return void
     */
    public function load_patterns() {
        $this->patterns = [
            'question_number' => get_string('pdf_pattern_question_number', 'local_questionconverter'),
            'alternatives' => get_string('pdf_pattern_alternatives', 'local_questionconverter'),
            'correct_answer' => get_string('pdf_pattern_correct_answer', 'local_questionconverter'),
            'feedback' => get_string('pdf_pattern_feedback', 'local_questionconverter'),
            'indicator' => get_string('pdf_pattern_indicator', 'local_questionconverter'),
            'evaluation_indicator' => get_string('pdf_pattern_evaluation_indicator', 'local_questionconverter'),
            'truefalse' => get_string('pdf_pattern_truefalse', 'local_questionconverter'),
            'truefalse_short1' => get_string('pdf_pattern_truefalse_short1', 'local_questionconverter'),
            'truefalse_short2' => get_string('pdf_pattern_truefalse_short2', 'local_questionconverter'),
            'essay' => get_string('pdf_pattern_essay', 'local_questionconverter'),
            'true' => get_string('pdf_pattern_true', 'local_questionconverter'),
            'false' => get_string('pdf_pattern_false', 'local_questionconverter'),
        ];
    }
    /**
     * Parse standard format PDF (without indicators)
     * @param string $filepath PDF file path
     * @return array Array of questions
     */
    public function parse_standard($filepath) {
        /* Check if the file exists and is not empty */
        if (!file_exists($filepath) || filesize($filepath) === 0) {
            throw new \moodle_exception('invalidpdffile', 'local_questionconverter');
        }
        try {
            $pdf = $this->parser->parseFile($filepath);
            $text = $pdf->getText();
            $questions = $this->parse_new_format($text);
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
            throw new \moodle_exception('invalidpdffile', 'local_questionconverter');
        }
        try {
            $pdf = $this->parser->parseFile($filepath);
            $text = $pdf->getText();
        } catch (\Throwable $e) {
            throw new \moodle_exception('errorparsingpdf', 'local_questionconverter', '', null, $e->getMessage());
        }
        if (empty(trim($text))) {
            throw new \moodle_exception('noquestionsfound', 'local_questionconverter');
        }
        /* Extract indicators */
        $indicators = $this->extract_indicators($text);
        if (empty($indicators)) {
            throw new \moodle_exception('noindicatorsfound', 'local_questionconverter');
        }
        $result = [];
        foreach ($indicators as $num => $indicator) {
            $questions = $this->process_indicator($indicator);
            /* Filter valid questions */
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
     * @return array Array of questions
     */
    private function parse_new_format($text) {
        $text = $this->clean_full_text($text);
        $qnum = preg_quote($this->patterns['question_number'], '/');
        $feedback = preg_quote($this->patterns['feedback'], '/');
        $pattern = '/'. $qnum . '\s*(\d+)\s*(.*?)\s*' . $feedback . ':\s*(.*?)(?=' . $qnum . '|$)/s';
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
     * Summary of extract_indicators
     * @param mixed $text
     * @return array Array of indicators
     */
    private function extract_indicators($text) {
        $indicatorpattern = preg_quote($this->patterns['indicator'], '/');
        $pattern = '/'. $indicatorpattern . '\s+(\d+)\s*[:\s]*(.*?)(?=\n|$)/is';
        preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
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
     * @return array Array of questions
     */
    private function process_indicator($indicator) {
        $content = $indicator['content'];
        $qnmun = preg_quote($this->patterns['question_number'], '/');
        preg_match_all('/' . $qnmun . '\s*(\d+)/is', $content, $matches, PREG_OFFSET_CAPTURE);
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
     * @return array Array of questions
     */
    private function process_question_from_block($block) {
        $qnmun = preg_quote($this->patterns['question_number'], '/');
        $feedback = preg_quote($this->patterns['feedback'], '/');
        $pattern = '/'. $qnmun . '\s*(\d+)\s*(.*?)\s*' . $feedback . ':\s*(.*?)(?=' . $qnmun . '\s*\d+|\z)/s';
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
            $question['correctanswer'] = $this->clean_answer($answer);
        } else if ($type === 'truefalse') {
            $options = $this->get_options($content, $type);
            $question['options'] = $options;
            $answer = $this->extract_answer($content);
            $question['correctanswer'] = $this->clean_answer($answer);
        }
        return $question;
    }
    /**
     * Summary of extract_options_multichoice
     * @param mixed $content
     * @return array Array of questions
     */
    private function extract_options_multichoice($content) {
        $options = [];
        $type = '';
        $alternativespattern = preg_quote($this->patterns['alternatives'], '/');
        $evalindicatorpattern = preg_quote($this->patterns['evaluation_indicator'], '/');
        $correctanswerpattern = preg_quote($this->patterns['correct_answer'], '/');
        $pattern = '/'. $alternativespattern . '\s*(.*?)(?=' . $evalindicatorpattern . '|' . $correctanswerpattern . ')/s';
        if (!preg_match($pattern, $content, $m)) {
            return [$options, $type];
        }
        $optionstext = trim($m[1]);
        $patternoptions = '/([a-e])\)\s*(.*?)(?=\s*[a-e]\s*\)|$)/s';
        preg_match_all($patternoptions, $optionstext, $matches, PREG_SET_ORDER);
        foreach ($matches as $opt) {
            $letter = strtolower($opt[1]);
            $text = trim($opt[2]);
            $text = preg_replace('/\s+/', ' ', $text);
            if (!empty($text) && stripos($text, $this->patterns['alternatives']) === false) {
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
        $essaypattern = preg_quote($this->patterns['essay'], '/');
        $pattern = '/'. $essaypattern . '/s';
        if (preg_match($pattern, $content)) {
            $type = 'essay';
        }
        return $type;
    }
    /**
     * Summary of extract_options_truefalse
     * @param mixed $content
     * @return array Array of questions
     */
    private function extract_options_truefalse($content) {
        $type = '';
        $options = [];
        $tf = preg_quote($this->patterns['truefalse'], '/');
        $tf1 = preg_quote($this->patterns['truefalse_short1'], '/');
        $tf2 = preg_quote($this->patterns['truefalse_short2'], '/');
        $correctanswerpattern = preg_quote($this->patterns['correct_answer'], '/');
        $pattern = '/(' . $tf . '|' . $tf1 . '|' . $tf2 . ')\s*(.*?)(?=' . $correctanswerpattern . '|$)/isu';
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
            if (stripos($text, $this->patterns['truefalse']) === false) {
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
        $correctanswerpattern = preg_quote($this->patterns['correct_answer'], '/');
        $true = preg_quote($this->patterns['true'], '/');
        $false = preg_quote($this->patterns['false'], '/');
        $pattern = '/'. $correctanswerpattern . '\s*([a-e]|' . $true . '|' . $false . '|verdadero|falso|true|false)/si';
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
        $alternativespattern = preg_quote($this->patterns['alternatives'], '/');
        $essaypattern = preg_quote($this->patterns['essay'], '/');
        $truefalsepattern = preg_quote($this->patterns['truefalse'], '/');
        $cleaned = preg_replace('/\s+' . $alternativespattern . '\s+[a-e]\).*$/is', '', $content);
        $cleaned = preg_replace('/\s+' . $essaypattern . '.*$/is', '', $cleaned);
        $cleaned = preg_replace('/\s+' . $truefalsepattern . '\s+[a-b]\).*$/is', '', $cleaned);
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
        // Clean up until "week ".
        if (preg_match('/(.*?(week|semana)\.)/s', $text, $match)) {
            return trim(preg_replace('/\s+/', ' ', $match[1]));
        }
        // Divide by lines and clean.
        $lines = explode("\n", $text);
        $validlines = [];
        $indicatorpattern = preg_quote($this->patterns['indicator'], '/');
        $qnum = preg_quote($this->patterns['question_number'], '/');
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            if (preg_match('/^\d+$/', $line)) {
                break;
            }
            if (preg_match('/^(' . $indicatorpattern . '|' . $qnum . ')/i', $line)) {
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
     * @return array Array of questions
     */
    private function proccess_question($content, $matches = null) {
        if ($matches === null) {
            $qmun = preg_quote($this->patterns['question_number'], '/');
            $feedback = preg_quote($this->patterns['feedback'], '/');
            $pattern = '/'. $qmun . '\s*(\d+)\s*(.*?)\s*' . $feedback . ':\s*(.*?)(?=' . $qmun . '\s*\d+|\z)/s';
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
            $question['correctanswer'] = $this->extract_answer($content);
        } else if ($type === 'truefalse') {
            $options = $this->get_options($content, $type);
            $question['options'] = $options;
            $question['correctanswer'] = $this->extract_answer($content);
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
        } else if (preg_match('/^(verdadero|falso|true|false)$/i', $answer)) {
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
        $qnunm = preg_quote($this->patterns['question_number'], '/');
        $question = preg_split('/(' . $qnunm . '\s*\d+)/i', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
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
        $feedback = preg_quote($this->patterns['feedback'], '/');
        if (preg_match('/(.*?' . $feedback . ':.*?)(?=\n\s*\d+\s+[A-Z]|$)/s', $block, $matches)) {
            return trim($matches[1]);
        }
        return trim($block);
    }
}
