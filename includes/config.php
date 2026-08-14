<?php
// ============================================================
//  Configuración de conexión — ajustar si es necesario
// ============================================================
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', 0);
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');        // usuario XAMPP por defecto
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');            // contraseña XAMPP por defecto (vacía)
define('DB_NAME', getenv('DB_NAME') ?: 'juicios_evaluativos');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'Sistema de Juicios Evaluativos');
define('APP_VERSION', '1.0');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'Error de conexión: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

// Respuesta JSON estándar
function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
