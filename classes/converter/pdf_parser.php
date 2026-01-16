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
use \Smalot\PdfParser\Parser;

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
     * 
     * @param string $filepath Ruta del archivo PDF
     * @return array Array de preguntas
     */
    public function parse_standard($filepath) {
        // Validaciones y manejo de errores del archivo
        if (!file_exists($filepath) || filesize($filepath) === 0) {
            throw new \moodle_exception('invalidpdffile', 'local_questionconverter');
        }

        try {
            $pdf = $this->parser->parseFile($filepath);
            $text = $pdf->getText();
            
            /* $keywords = ['N° de pregunta', 'Alternativas', 'Respuesta correcta', 'Indicador'];
            foreach ($keywords as $kw) {
                $count = is_string($text) ? substr_count($text, $kw) : 0;
            
            } */
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
    
/*     public function parse_with_indicators($filepath) {
        if (!file_exists($filepath) || filesize($filepath) === 0) {
            return ['success' => false, 'indicators' => []];
        }

        try {
            $pdf = $this->parser->parseFile($filepath);
            $text = $pdf->getText();
        } catch (\Throwable $e) {
            return ['success' => false, 'indicators' => []];
        }

        if (empty(trim($text))) {
            return ['success' => false, 'indicators' => []];
        }
        // Extraer indicadores
        $indicators = $this->extract_indicators($text);
        
        if (empty($indicators)) {
            return ['success' => false, 'indicators' => []];
        }
        
        $result = [];
        
        foreach ($indicators as $num => $indicator) {
            $questions = $this->process_indicator($indicator);
            
            // Filtrar preguntas válidas
            $valid_questions = array_filter($questions, function($q) {
                return isset($q['type']) && 
                       $q['type'] !== 'ERROR' && 
                       in_array($q['type'], ['multichoice', 'essay']);
            });
            
            if (!empty($valid_questions)) {
                $result[] = [
                    'number' => $num,
                    'title' => $indicator['title'],
                    'questions' => array_values($valid_questions)
                ];
            }
        }
        
        return [
            'success' => true,
            'indicators' => $result
        ];
    } */
    
    private function parse_new_format($text) {
            
        $pattern = '/N° de pregunta:\s*(\d+)\s*(.*?)\nAlternativas\s*'
                    . 'a\)\s*(.*?)\n'
                    . 'b\)\s*(.*?)\n'
                    . 'c\)\s*(.*?)\n'
                    . 'd\)\s*(.*?)\n'
                    . 'e\)\s*(.*?)\n'
                    .'.*?Respuesta correcta\s*([aA-eE])\s*'
                    .'Retroalimentación:\s*(.*?)(?=N° de pregunta:|$)/s';             

        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);

        $questions = [];
        foreach ($matches as $m) {
            $feedback = isset($m[9]) ? $this->clean_feedback($m[9]) : '';
            
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
                'feedback' => $feedback
            ];
        }
        
        return $questions; 
    }
    
    private function parse_old_format($text) {
        $text = preg_replace("/\r\n|\r/", "\n", $text);
        $text = preg_replace('/.*?plazos\s+establecidos\.\s*/is', '', $text);
        $text = preg_replace('/^(?:[A-ZÁÉÍÓÚÜÑ0-9\s\.\-]+?\n){1,3}/', '', $text);
        
         $pattern = '/^\s*(\d+)\.\s*'
                    .'(.*?) '                                     
                    .'\s+'                                      
                    . 'a\)\s*(.*?)\n'
                    . 'b\)\s*(.*?)\n'
                    . 'c\)\s*(.*?)\n'
                    . 'd\)\s*(.*?)\n'
                    . 'e\)\s*(.*?)\n'                         
                    .'.*?Respuesta\s*correcta\s*'                
                    .'Retroalimentación\s*'                     
                    .'([aA-eE])'
                    .'\s*'                               
                    .'((?:(?!\n\s*\d+\.\s).)*)'                      
                    .'/smx';     
        
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
                'feedback' => trim($m[9])
            ];
        }
        
        return $questions;
    }
    
/*     private function extract_indicators($text) {
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
                'content' => $content
            ];
        }
        
        return $indicators;
    } */
    
/*     private function process_indicator($indicator) {
        $content = $indicator['content'];
        
        preg_match_all('/N°\s*de\s*pregunta:\s*(\d+)/is', $content, $matches, PREG_OFFSET_CAPTURE);
        
        $questions = [];
        
        for ($i = 0; $i < count($matches[0]); $i++) {
            $number = (int)$matches[1][$i][0];
            $start = $matches[0][$i][1];
            
            if ($i < count($matches[0]) - 1) {
                $end = $matches[0][$i + 1][1];
            } else {
                $end = strlen($content);
            }
            
            $block = substr($content, $start, $end - $start);
            $block = trim($block);
            
             $has_options = preg_match('/Alternativas|a\s*[)\.]\s*.*?\s*b\s*[)\.]\s*.*?\s*c\s*[)\.]/is', $block);
            
            if ($has_options) {
                $question = $this->process_multichoice($block);
            } else {
                $question = $this->process_essay($block);
            }
            
            if ($question) {
                $questions[] = $question;
            }
        }
        
        return $questions;
    } */
    
/*     private function process_multichoice($block) {
        $pattern = '/N°\s*de\s*pregunta:\s*(\d+)\s+'
                . '(.*?)'                                          
                . '\s*Alternativas\s*'                             
                . '[aA]\s*[)\.]\s+(.*?)(?=\s*[bB]\s*[)\.])'       
                . '\s*[bB]\s*[)\.]\s+(.*?)(?=\s*[cC]\s*[)\.])'    
                . '\s*[cC]\s*[)\.]\s+(.*?)(?=\s*[dD]\s*[)\.])'    
                . '\s*[dD]\s*[)\.]\s+(.*?)(?=\s*[eE]\s*[)\.])'    
                . '\s*[eE]\s*[)\.]\s+(.*?)(?=Respuesta)'           
                . '\s*Respuesta\s*correcta\s*[:\s]*([a-eA-E])'     
                . '(.*?)$'                                          
                . '/is';
        
        if (preg_match($pattern, $block, $m)) {
            $rest = isset($m[9]) ? $m[9] : '';
            $feedback = '';
            
            if (preg_match('/Retroalimentación\s*[:\s]*(.*?)$/is', $rest, $feedback_match)) {
                $feedback = trim($feedback_match[1]);
            }
            
            $feedback = $this->clean_feedback($feedback);
            
            return [
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
                'feedback' => $feedback
            ];
        }
        
        return null;
    }
    
    private function process_essay($block) {
        $pattern = '/N°\s*de\s*pregunta:\s*(\d+)\s+'
            . '(.*?)'
            . '\s*Escribe\s+aquí\s+tu\s+respuesta\s*'
            . '(?:Retroalimentación\s*[:\s]*(.*?))?$'
            . '/is';
        
        if (preg_match($pattern, $block, $m)) {
            $feedback = isset($m[3]) ? trim($m[3]) : '';
            $feedback = $this->clean_feedback($feedback);
            
            return [
                'type' => 'essay',
                'number' => trim($m[1]),
                'question' => trim($m[2]),
                'feedback' => $feedback
            ];
        }
        
        return null;
    } */
    
    private function clean_feedback($feedback) {
        if (empty($feedback)) {
            return '';
        }
        
        $text = trim($feedback);
        
        // Limpiar hasta "semana."
        if (preg_match('/(.*?semana\.)/is', $text, $match)) {
            return trim(preg_replace('/\s+/', ' ', $match[1]));
        }
        
        // Dividir por líneas y limpiar
        $lines = explode("\n", $text);
        $valid_lines = [];
        
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
            
            $valid_lines[] = $line;
        }
        
        $text = implode(' ', $valid_lines);
        $text = preg_replace('/\s+/', ' ', $text);
        
        return trim($text);
    }
}