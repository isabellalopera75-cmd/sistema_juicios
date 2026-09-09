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

// ============================================================
//  Manejo global de errores: nunca devolver un 500 con cuerpo vacío
// ============================================================

// Emite un error en formato JSON sin abortar la ejecución.
function jsonErrorResponse(string $message, int $code = 500): void {
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
}

// Cualquier excepción no capturada (p. ej. PDOException) responde JSON
// y deja el detalle completo en el log del servidor.
set_exception_handler(function (Throwable $e): void {
    error_log(sprintf(
        '[%s] %s en %s:%d%s%s',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(),
        PHP_EOL, $e->getTraceAsString()
    ));
    jsonErrorResponse($e->getMessage());
    exit;
});

// Errores fatales (que no pasan por set_exception_handler) también
// devuelven JSON en lugar de una respuesta vacía.
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err === null) return;
    if (!in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;

    error_log(sprintf('[FATAL] %s en %s:%d', $err['message'], $err['file'], $err['line']));
    jsonErrorResponse($err['message']);
});
