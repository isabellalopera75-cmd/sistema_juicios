<?php
header('Content-Type: application/json; charset=utf-8');

$config_file = __DIR__ . '/includes/ai_config.php';
if (!file_exists($config_file)) {
    http_response_code(500);
    echo json_encode(['error' => 'No existe el archivo de configuración de IA (includes/ai_config.php).']);
    exit;
}

require_once $config_file;

if (!defined('OPENAI_API_KEY') || empty(OPENAI_API_KEY) || OPENAI_API_KEY === 'AQUI_VA_MI_API_KEY') {
    http_response_code(500);
    echo json_encode(['error' => 'API key de IA no configurada correctamente.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    $data = $_POST;
}
if (!$data && isset($_GET['action'])) {
    $data = $_GET;
}

$action = $data['action'] ?? '';

if (!in_array($action, ['analizar_aprendiz', 'resumen_ficha'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Acción no válida.']);
    exit;
}

$system_prompt = "Eres un asesor académico SENA experto. Escribe en español claro, formal y útil. No inventes datos. Básate solo en los datos proporcionados. Sé concreto, no muy largo. Usa estructura:\n- Diagnóstico\n- Riesgo (Bajo, Medio o Alto)\n- Hallazgos principales\n- Recomendaciones\n- Próximos pasos";

$user_prompt = "";
if ($action === 'analizar_aprendiz') {
    $datos = $data['datos'] ?? [];
    $user_prompt = "Genera un análisis IA para el siguiente aprendiz:\n" . json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} elseif ($action === 'resumen_ficha') {
    $datos = $data['datos'] ?? [];
    $user_prompt = "Genera un resumen IA ejecutivo para la siguiente ficha:\n" . json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

$ch = curl_init('https://api.openai.com/v1/chat/completions');
$payload = json_encode([
    'model' => 'gpt-3.5-turbo',
    'messages' => [
        ['role' => 'system', 'content' => $system_prompt],
        ['role' => 'user', 'content' => $user_prompt]
    ],
    'temperature' => 0.7
]);

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . OPENAI_API_KEY
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($http_code !== 200) {
    http_response_code(500);
    error_log("Error de OpenAI API: $response | Curl Error: $curl_error");
    echo json_encode(['error' => 'Error al comunicarse con la IA.']);
    exit;
}

$res_data = json_decode($response, true);
$ai_text = $res_data['choices'][0]['message']['content'] ?? '';

if (empty($ai_text)) {
    http_response_code(500);
    echo json_encode(['error' => 'La IA devolvió una respuesta vacía.']);
    exit;
}

echo json_encode(['resultado' => $ai_text]);
