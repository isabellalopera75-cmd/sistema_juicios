<?php
/**
 * api_fichas.php
 * Administración de fichas: listado con volumen de datos y eliminación.
 *
 * Acciones GET (lectura):
 *   listado   → fichas con conteo de aprendices, juicios y última actividad
 *
 * Acciones POST (escritura):
 *   eliminar  → borra una ficha con todos sus aprendices y juicios
 *
 * El borrado exige que el cliente repita el código de la ficha. Las tablas
 * compartidas (programa, competencia, resultado, instructor) nunca se tocan:
 * pueden estar en uso por otras fichas.
 */

require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/json; charset=utf-8');

$pdo    = getDB();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

$body = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw) $body = json_decode($raw, true) ?? [];
    $body = array_merge($_POST, $body);
}

switch ($action) {

    // ── Listado administrativo de fichas ───────────────────────────────
    case 'listado':
        $rows = $pdo->query(
            "SELECT f.id_ficha, f.codigo_ficha, f.estado_ficha, f.modalidad,
                    f.regional, f.fecha_inicio, f.fecha_fin,
                    p.nombre AS programa,
                    (SELECT COUNT(*) FROM aprendiz a
                      WHERE a.id_ficha = f.id_ficha)                       AS aprendices,
                    (SELECT COUNT(*) FROM juicio_evaluativo j
                      WHERE j.id_ficha = f.id_ficha)                       AS juicios,
                    (SELECT COUNT(*) FROM juicio_evaluativo j
                      WHERE j.id_ficha = f.id_ficha
                        AND j.estado = 'APROBADO')                         AS aprobados,
                    (SELECT MAX(j.fecha) FROM juicio_evaluativo j
                      WHERE j.id_ficha = f.id_ficha)                       AS ultimo_juicio,
                    (SELECT COUNT(*) FROM fase_proyecto fp
                      WHERE fp.id_ficha = f.id_ficha)                      AS fases
             FROM ficha f
             JOIN programa p ON p.id_programa = f.id_programa
             ORDER BY f.codigo_ficha"
        )->fetchAll();

        jsonResponse($rows);

    // ── Eliminar una ficha con todo su contenido ───────────────────────
    case 'eliminar':
        if ($method !== 'POST') jsonResponse(['error' => 'Método no permitido'], 405);

        $idFicha      = (int)($body['id_ficha'] ?? 0);
        $confirmacion = trim((string)($body['confirmacion'] ?? ''));
        if (!$idFicha) jsonResponse(['error' => 'id_ficha requerido'], 400);

        $stmt = $pdo->prepare("SELECT codigo_ficha FROM ficha WHERE id_ficha = ?");
        $stmt->execute([$idFicha]);
        $codigo = $stmt->fetchColumn();
        if (!$codigo) jsonResponse(['error' => 'La ficha no existe'], 404);

        // Repetir el código es la única llave: ningún click accidental borra
        // un año de juicios evaluativos.
        if ($confirmacion !== (string)$codigo) {
            jsonResponse([
                'error' => 'Para eliminar la ficha hay que escribir su código exacto: ' . $codigo,
            ], 422);
        }

        $pdo->beginTransaction();
        try {
            // El orden lo imponen las llaves foráneas: primero los juicios,
            // después los aprendices, al final la ficha. Las fases caen solas
            // por ON DELETE CASCADE.
            $stmt = $pdo->prepare(
                "DELETE FROM juicio_evaluativo
                  WHERE id_ficha = ?
                     OR id_aprendiz IN (SELECT id_aprendiz FROM aprendiz WHERE id_ficha = ?)"
            );
            $stmt->execute([$idFicha, $idFicha]);
            $juiciosBorrados = $stmt->rowCount();

            $stmt = $pdo->prepare("DELETE FROM aprendiz WHERE id_ficha = ?");
            $stmt->execute([$idFicha]);
            $aprendicesBorrados = $stmt->rowCount();

            $stmt = $pdo->prepare("DELETE FROM ficha WHERE id_ficha = ?");
            $stmt->execute([$idFicha]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        jsonResponse([
            'ok'      => true,
            'ficha'   => $codigo,
            'borrado' => [
                'juicios'    => $juiciosBorrados,
                'aprendices' => $aprendicesBorrados,
            ],
        ]);

    default:
        jsonResponse(['error' => 'Acción no reconocida: ' . $action], 400);
}
