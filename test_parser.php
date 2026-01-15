<?php
/**
 * Test parser - Probar extracción de preguntas del PDF
 * ELIMINAR en producción
 *
 * @package    local_questionconverter
 */

require_once(__DIR__ . '/../../config.php');

// Cargar autoloader de Composer
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once(__DIR__ . '/vendor/autoload.php');
}

require_once(__DIR__ . '/classes/converter/pdf_parser.php');

use local_questionconverter\converter\pdf_parser;

// Requiere login y permisos de admin
require_login();
require_capability('moodle/site:config', context_system::instance());

// Configurar la página
$PAGE->set_url(new moodle_url('/local/questionconverter/test_parser.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Test PDF Parser');
$PAGE->set_heading('Test PDF Parser - Question Converter');

echo $OUTPUT->header();

echo '<h2>Prueba de Extracción de PDF</h2>';

// Formulario para subir PDF
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['testpdf'])) {
    echo '<h3>Resultados del Análisis:</h3>';
    
    try {
        $file = $_FILES['testpdf'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Error al subir el archivo');
        }
        
        // Guardar temporalmente
        $filepath = make_temp_directory('questionconverter') . '/' . clean_filename($file['name']);
        move_uploaded_file($file['tmp_name'], $filepath);
        
        // Parsear PDF
        $parser = new pdf_parser();
        
        // Intentar sin indicadores
        echo '<div style="background: #f0f0f0; padding: 15px; margin: 10px 0; border-left: 4px solid #2196F3;">';
        echo '<h4>📄 Texto Extraído del PDF (primeros 2000 caracteres):</h4>';
        
        $pdfparser = new \Smalot\PdfParser\Parser();
        $pdf = $pdfparser->parseFile($filepath);
        $raw_text = $pdf->getText();
        
        echo '<pre style="background: white; padding: 10px; overflow: auto; max-height: 300px;">';
        echo htmlspecialchars(substr($raw_text, 0, 2000));
        if (strlen($raw_text) > 2000) {
            echo "\n\n... (texto truncado, total: " . strlen($raw_text) . " caracteres)";
        }
        echo '</pre>';
        echo '</div>';
        
        echo '<div style="background: #e7f3ff; padding: 15px; margin: 10px 0; border-left: 4px solid #2196F3;">';
        echo '<h4>🔍 Intentando parsear SIN indicadores:</h4>';
        $questions = $parser->parse_standard($filepath);
        
        if (empty($questions)) {
            echo '<p style="color: red;">❌ No se encontraron preguntas con formato estándar</p>';
        } else {
            echo '<p style="color: green;">✓ Se encontraron ' . count($questions) . ' preguntas</p>';
            
            foreach ($questions as $i => $q) {
                $type_label = $q['type'] === 'multichoice' ? '✓ Opción Múltiple' : '📝 Ensayo';
                $type_color = $q['type'] === 'multichoice' ? 'green' : 'orange';
                
                echo '<div style="background: white; padding: 10px; margin: 10px 0; border: 1px solid #ddd;">';
                echo '<strong>Pregunta ' . ($i + 1) . ':</strong> ';
                echo '<span style="color: ' . $type_color . ';">' . $type_label . '</span><br>';
                echo '<strong>Número:</strong> ' . htmlspecialchars($q['number']) . '<br>';
                echo '<strong>Texto:</strong> ' . htmlspecialchars(substr($q['question'], 0, 100)) . '...<br>';
                
                if ($q['type'] === 'multichoice') {
                    echo '<strong>Opciones:</strong><ul>';
                    foreach ($q['options'] as $letter => $option) {
                        $is_correct = ($letter === $q['correct_answer']) ? ' ✓ (correcta)' : '';
                        echo '<li>' . $letter . ') ' . htmlspecialchars(substr($option, 0, 50)) . $is_correct . '</li>';
                    }
                    echo '</ul>';
                    echo '<strong>Respuesta correcta:</strong> ' . strtoupper($q['correct_answer']) . '<br>';
                }
                
                if (!empty($q['feedback'])) {
                    echo '<strong>Retroalimentación:</strong> ' . htmlspecialchars(substr($q['feedback'], 0, 100)) . '...<br>';
                }
                
                echo '</div>';
            }
        }
        echo '</div>';
        
        // Intentar con indicadores
        echo '<div style="background: #fff3cd; padding: 15px; margin: 10px 0; border-left: 4px solid #ffc107;">';
        echo '<h4>🔍 Intentando parsear CON indicadores:</h4>';
        $result = $parser->parse_with_indicators($filepath);
        
        if (empty($result['indicators'])) {
            echo '<p style="color: orange;">⚠ No se encontraron indicadores en el PDF</p>';
        } else {
            echo '<p style="color: green;">✓ Se encontraron ' . count($result['indicators']) . ' indicadores</p>';
            
            foreach ($result['indicators'] as $indicator) {
                echo '<div style="background: white; padding: 10px; margin: 10px 0; border: 1px solid #ddd;">';
                echo '<strong>Indicador ' . $indicator['number'] . ':</strong> ' . htmlspecialchars($indicator['title']) . '<br>';
                echo '<strong>Preguntas:</strong> ' . count($indicator['questions']) . '<br>';
                
                foreach ($indicator['questions'] as $i => $q) {
                    $type_label = $q['type'] === 'multichoice' ? '✓ Opción Múltiple' : '📝 Ensayo';
                    echo '<div style="margin-left: 20px; padding: 5px; background: #f9f9f9;">';
                    echo $type_label . ' - Pregunta ' . $q['number'] . ': ' . htmlspecialchars(substr($q['question'], 0, 50)) . '...';
                    echo '</div>';
                }
                
                echo '</div>';
            }
        }
        echo '</div>';
        
        // Limpiar
        if (file_exists($filepath)) {
            unlink($filepath);
        }
        
    } catch (Exception $e) {
        echo '<div style="background: #ffebee; padding: 15px; margin: 10px 0; border-left: 4px solid #f44336;">';
        echo '<h4>❌ Error:</h4>';
        echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '</div>';
    }
    
    echo '<hr>';
    echo '<a href="' . $PAGE->url . '" class="btn btn-primary">Probar otro PDF</a>';
    
} else {
    // Mostrar formulario
    echo '<form method="post" enctype="multipart/form-data">';
    echo '<div class="form-group">';
    echo '<label>Selecciona un PDF para analizar:</label>';
    echo '<input type="file" name="testpdf" accept=".pdf" required class="form-control">';
    echo '</div>';
    echo '<button type="submit" class="btn btn-primary">Analizar PDF</button>';
    echo '</form>';
    
    echo '<div class="alert alert-info mt-3">';
    echo '<h4>ℹ️ Este test muestra:</h4>';
    echo '<ul>';
    echo '<li>El texto completo extraído del PDF</li>';
    echo '<li>Qué preguntas detectó el parser</li>';
    echo '<li>El tipo de cada pregunta (multichoice o essay)</li>';
    echo '<li>Las opciones y respuesta correcta</li>';
    echo '</ul>';
    echo '<p><strong>Usa esto para diagnosticar por qué las preguntas se importan como essay en lugar de multichoice.</strong></p>';
    echo '</div>';
}

echo '<p style="color: red; margin-top: 20px;"><strong>IMPORTANTE:</strong> Elimina este archivo (test_parser.php) en producción.</p>';

echo $OUTPUT->footer();