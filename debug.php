<?php
/**
 * Debug paso a paso - Ver qué hace cada función
 */

require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once(__DIR__ . '/vendor/autoload.php');
}

require_once(__DIR__ . '/classes/converter/pdf_parser.php');
require_once(__DIR__ . '/classes/converter/question_importer.php');

use local_questionconverter\converter\pdf_parser;
use local_questionconverter\converter\question_importer;

$courseid = required_param('courseid', PARAM_INT);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);

require_login($course);
require_capability('moodle/question:add', $context);

$PAGE->set_url(new moodle_url('/local/questionconverter/debug.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title('Debug Paso a Paso');

echo $OUTPUT->header();
echo '<h2>🔍 Debug Paso a Paso - Sin Indicadores</h2>';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    try {
        // === PASO 1: RECIBIR ARCHIVO ===
        echo '<div style="background: #e3f2fd; padding: 20px; margin: 20px 0; border-left: 4px solid #2196F3;">';
        echo '<h3>📥 PASO 1: Recibir Archivo</h3>';
        
        if (!isset($_FILES['pdffile']) || $_FILES['pdffile']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Error al subir archivo');
        }
        
        $file = $_FILES['pdffile'];
        echo '<p>✅ Archivo recibido: <strong>' . htmlspecialchars($file['name']) . '</strong></p>';
        echo '<p>📏 Tamaño: <strong>' . number_format($file['size']) . '</strong> bytes</p>';
        
        // Guardar temporalmente
        $tempdir = make_temp_directory('questionconverter');
        $filepath = $tempdir . '/' . clean_filename($file['name']);
        move_uploaded_file($file['tmp_name'], $filepath);
        echo '<p>💾 Guardado en: <code>' . htmlspecialchars($filepath) . '</code></p>';
        echo '</div>';
        
        // === PASO 2: EXTRAER TEXTO ===
        echo '<div style="background: #fff3e0; padding: 20px; margin: 20px 0; border-left: 4px solid #ff9800;">';
        echo '<h3>📄 PASO 2: Extraer Texto del PDF</h3>';
        
        $pdfparser = new \Smalot\PdfParser\Parser();
        $pdf = $pdfparser->parseFile($filepath);
        $text = $pdf->getText();
        
        echo '<p>📏 Longitud del texto: <strong>' . number_format(strlen($text)) . '</strong> caracteres</p>';
        echo '<p>📝 Primeros 500 caracteres:</p>';
        echo '<pre style="background: white; padding: 10px; border: 1px solid #ddd; max-height: 200px; overflow: auto;">';
        echo htmlspecialchars(substr($text, 0, 500));
        echo '</pre>';
        echo '</div>';
        
        // === PASO 3: BUSCAR PALABRAS CLAVE ===
        echo '<div style="background: #f3e5f5; padding: 20px; margin: 20px 0; border-left: 4px solid #9c27b0;">';
        echo '<h3>🔍 PASO 3: Buscar Palabras Clave</h3>';
        
        $keywords = [
            'N° de pregunta' => substr_count($text, 'N° de pregunta'),
            'Alternativas' => substr_count($text, 'Alternativas'),
            'alternativas' => substr_count($text, 'alternativas'),
            'Respuesta correcta' => substr_count($text, 'Respuesta correcta'),
            'a)' => substr_count($text, 'a)'),
            'A)' => substr_count($text, 'A)'),
            'b)' => substr_count($text, 'b)'),
            'B)' => substr_count($text, 'B)'),
        ];
        
        echo '<table style="background: white; border-collapse: collapse; width: 100%;">';
        echo '<tr style="background: #f5f5f5;"><th style="padding: 10px; border: 1px solid #ddd; text-align: left;">Palabra Clave</th><th style="padding: 10px; border: 1px solid #ddd;">Ocurrencias</th></tr>';
        foreach ($keywords as $kw => $count) {
            $color = $count > 0 ? 'green' : 'red';
            echo '<tr>';
            echo '<td style="padding: 10px; border: 1px solid #ddd;">' . htmlspecialchars($kw) . '</td>';
            echo '<td style="padding: 10px; border: 1px solid #ddd; text-align: center; color: ' . $color . '; font-weight: bold;">' . $count . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        echo '</div>';
        
        // === PASO 4: INTENTAR parse_new_format ===
        echo '<div style="background: #e8f5e9; padding: 20px; margin: 20px 0; border-left: 4px solid #4caf50;">';
        echo '<h3>🔬 PASO 4: Intentar parse_new_format()</h3>';
        
        $pattern_new = '/N°\s*de\s*pregunta:\s*(\d+)\s+'
                . '(.*?)'
                . '\s*Alternativas\s*'
                . '[aA]\s*[)\.]\s*(.*?)\s*'
                . '[bB]\s*[)\.]\s*(.*?)\s*'
                . '[cC]\s*[)\.]\s*(.*?)\s*'
                . '[dD]\s*[)\.]\s*(.*?)\s*'
                . '[eE]\s*[)\.]\s*(.*?)\s*'
                . 'Respuesta\s*correcta\s*[:\s]*([a-eA-E])\s*'
                . '(?:Retroalimentación\s*[:\s]*(.*?))?'
                . '(?=N°\s*de\s*pregunta:|$)/is';
        
        preg_match_all($pattern_new, $text, $matches_new, PREG_SET_ORDER);
        
        $count_new = count($matches_new);
        echo '<p>🎯 Coincidencias encontradas: <strong style="font-size: 24px; color: ' . ($count_new > 0 ? 'green' : 'red') . ';">' . $count_new . '</strong></p>';
        
        if ($count_new > 0) {
            echo '<p>✅ <strong>parse_new_format() FUNCIONÓ</strong></p>';
            echo '<p>Primera pregunta detectada:</p>';
            echo '<ul>';
            echo '<li><strong>Número:</strong> ' . htmlspecialchars($matches_new[0][1]) . '</li>';
            echo '<li><strong>Pregunta:</strong> ' . htmlspecialchars(substr($matches_new[0][2], 0, 100)) . '...</li>';
            echo '<li><strong>Opción A:</strong> ' . htmlspecialchars(substr($matches_new[0][3], 0, 50)) . '...</li>';
            echo '<li><strong>Respuesta correcta:</strong> ' . htmlspecialchars($matches_new[0][8]) . '</li>';
            echo '</ul>';
        } else {
            echo '<p>❌ <strong>parse_new_format() NO encontró preguntas</strong></p>';
        }
        echo '</div>';
        
        // === PASO 5: INTENTAR parse_old_format ===
        echo '<div style="background: #fff9c4; padding: 20px; margin: 20px 0; border-left: 4px solid #ffc107;">';
        echo '<h3>🔬 PASO 5: Intentar parse_old_format()</h3>';
        
        $text_old = preg_replace("/\r\n|\r/", "\n", $text);
        $text_old = preg_replace('/.*?plazos\s+establecidos\.\s*/is', '', $text_old);
        
        $pattern_old = '/^\s*(\d+)\s*[\.)\s]\s*'
                . '(.*?)'
                . '\s+[aA]\s*[)\.]\s*(.*?)\s*'
                . '[bB]\s*[)\.]\s*(.*?)\s*'
                . '[cC]\s*[)\.]\s*(.*?)\s*'
                . '[dD]\s*[)\.]\s*(.*?)\s*'
                . '[eE]\s*[)\.]\s*(.*?)\s*'
                . 'Respuesta\s*correcta\s*'
                . '(?:Retroalimentación\s*)?'
                . '([a-eA-E])\s*'
                . '(.*?)'
                . '(?=^\s*\d+\s*[\.)\s]|$)/ismx';
        
        preg_match_all($pattern_old, $text_old, $matches_old, PREG_SET_ORDER);
        
        $count_old = count($matches_old);
        echo '<p>🎯 Coincidencias encontradas: <strong style="font-size: 24px; color: ' . ($count_old > 0 ? 'green' : 'red') . ';">' . $count_old . '</strong></p>';
        
        if ($count_old > 0) {
            echo '<p>✅ <strong>parse_old_format() FUNCIONÓ</strong></p>';
            echo '<p>Primera pregunta detectada:</p>';
            echo '<ul>';
            echo '<li><strong>Número:</strong> ' . htmlspecialchars($matches_old[0][1]) . '</li>';
            echo '<li><strong>Pregunta:</strong> ' . htmlspecialchars(substr($matches_old[0][2], 0, 100)) . '...</li>';
            echo '<li><strong>Opción A:</strong> ' . htmlspecialchars(substr($matches_old[0][3], 0, 50)) . '...</li>';
            echo '<li><strong>Respuesta correcta:</strong> ' . htmlspecialchars($matches_old[0][8]) . '</li>';
            echo '</ul>';
        } else {
            echo '<p>❌ <strong>parse_old_format() NO encontró preguntas</strong></p>';
        }
        echo '</div>';
        
        // === PASO 6: PARSEAR CON CLASE ===
        echo '<div style="background: #e1f5fe; padding: 20px; margin: 20px 0; border-left: 4px solid #03a9f4;">';
        echo '<h3>⚙️ PASO 6: Parsear con la Clase pdf_parser</h3>';
        
        $parser = new pdf_parser();
        $questions = $parser->parse_standard($filepath);
        
        echo '<p>📊 Total de preguntas parseadas: <strong style="font-size: 24px; color: green;">' . count($questions) . '</strong></p>';
        
        if (!empty($questions)) {
            echo '<p>Tipos de preguntas:</p>';
            $types = array_count_values(array_column($questions, 'type'));
            foreach ($types as $type => $count) {
                $badge_color = $type === 'multichoice' ? '#28a745' : '#ffc107';
                echo '<span style="background: ' . $badge_color . '; color: white; padding: 5px 15px; border-radius: 5px; margin-right: 10px; display: inline-block; margin-bottom: 5px;">';
                echo strtoupper($type) . ': ' . $count;
                echo '</span>';
            }
            
            echo '<h4>Primeras 2 preguntas:</h4>';
            foreach (array_slice($questions, 0, 2) as $i => $q) {
                echo '<div style="background: white; padding: 15px; margin: 10px 0; border: 1px solid #ddd;">';
                echo '<p><strong>Pregunta ' . ($i + 1) . ':</strong> Tipo = <strong>' . strtoupper($q['type']) . '</strong></p>';
                echo '<p><strong>Número:</strong> ' . htmlspecialchars($q['number']) . '</p>';
                echo '<p><strong>Texto:</strong> ' . htmlspecialchars(substr($q['question'], 0, 150)) . '...</p>';
                
                if ($q['type'] === 'multichoice') {
                    echo '<p><strong>Opciones:</strong></p><ul>';
                    foreach ($q['options'] as $letter => $option) {
                        $correct = ($letter === $q['correct_answer']) ? ' ✓' : '';
                        echo '<li>' . strtoupper($letter) . ') ' . htmlspecialchars(substr($option, 0, 60)) . $correct . '</li>';
                    }
                    echo '</ul>';
                }
                echo '</div>';
            }
        }
        echo '</div>';
        
        // === PASO 7: IMPORTAR A MOODLE ===
        echo '<div style="background: #c8e6c9; padding: 20px; margin: 20px 0; border-left: 4px solid #4caf50;">';
        echo '<h3>💾 PASO 7: Importar a Moodle</h3>';
        
        $category_name = 'TEST - ' . clean_param(basename($filepath), PARAM_TEXT);
        $category_name = preg_replace('/\.pdf$/i', '', $category_name);
        
        $importer = new question_importer($context);
        $imported = $importer->import_questions($questions, $category_name, $courseid);
        
        echo '<p>✅ <strong>Importación completada</strong></p>';
        echo '<p>📁 Categoría: <strong>' . htmlspecialchars($imported['category']) . '</strong></p>';
        echo '<p>📝 Preguntas importadas: <strong>' . $imported['count'] . '</strong></p>';
        echo '<p>🔢 ID de categoría: <strong>' . $imported['categoryid'] . '</strong></p>';
        
        $question_bank_url = new moodle_url('/question/edit.php', [
            'courseid' => $courseid,
            'cat' => $imported['categoryid'] . ',' . $context->id
        ]);
        
        echo '<p><a href="' . $question_bank_url . '" class="btn btn-success" style="background: #4caf50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 10px;">→ Ver en Banco de Preguntas</a></p>';
        echo '</div>';
        
        // Limpiar
        if (file_exists($filepath)) {
            unlink($filepath);
        }
        
        echo '<hr>';
        echo '<a href="' . $PAGE->url . '" class="btn btn-primary">← Probar otro PDF</a>';
        
    } catch (Exception $e) {
        echo '<div style="background: #ffebee; padding: 20px; margin: 20px 0; border-left: 4px solid #f44336; color: #c62828;">';
        echo '<h3>❌ ERROR</h3>';
        echo '<p><strong>Mensaje:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '<p><strong>Archivo:</strong> ' . htmlspecialchars($e->getFile()) . '</p>';
        echo '<p><strong>Línea:</strong> ' . $e->getLine() . '</p>';
        echo '</div>';
    }
    
} else {
    // Formulario
    echo '<div class="alert alert-info">';
    echo '<h4>📝 Este test hace lo siguiente:</h4>';
    echo '<ol>';
    echo '<li><strong>Extrae el texto</strong> del PDF y te muestra los primeros 500 caracteres</li>';
    echo '<li><strong>Cuenta palabras clave</strong> como "N° de pregunta", "Alternativas", etc.</li>';
    echo '<li><strong>Prueba parse_new_format()</strong> y te dice cuántas coincidencias encontró</li>';
    echo '<li><strong>Prueba parse_old_format()</strong> y te dice cuántas coincidencias encontró</li>';
    echo '<li><strong>Parsea con la clase</strong> pdf_parser completa</li>';
    echo '<li><strong>Importa a Moodle</strong> y te lleva al banco de preguntas</li>';
    echo '</ol>';
    echo '<p><strong>Sin indicadores</strong> - Solo multichoice normal</p>';
    echo '</div>';
    
    echo '<form method="post" enctype="multipart/form-data" style="background: white; padding: 30px; border: 1px solid #ddd;">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
    echo '<input type="hidden" name="courseid" value="' . $courseid . '">';
    echo '<div class="form-group">';
    echo '<label style="font-size: 18px; font-weight: bold;">Selecciona tu PDF:</label>';
    echo '<input type="file" name="pdffile" accept=".pdf" required class="form-control" style="margin: 15px 0;">';
    echo '</div>';
    echo '<button type="submit" class="btn btn-primary btn-lg">🔍 Analizar e Importar</button>';
    echo '</form>';
}

echo '<p style="color: red; margin-top: 30px;"><strong>IMPORTANTE:</strong> Elimina este archivo en producción.</p>';

echo $OUTPUT->footer();