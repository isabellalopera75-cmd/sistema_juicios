<?php
/**
 * api_fases.php
 * API REST para el módulo de fases del proyecto formativo.
 * Uso: api_fases.php?action=NOMBRE&param=valor
 *
 * Acciones GET (lectura):
 *   fases_ficha        → lista fases de una ficha con su avance
 *   resultados_ficha   → competencias y resultados disponibles para asignar
 *   detalle_fase       → aprendices aprobados/pendientes en una fase
 *
 * Acciones POST (escritura):
 *   crear_fase         → crea una nueva fase
 *   editar_fase        → edita nombre, descripción y fechas de una fase
 *   eliminar_fase      → elimina una fase (y sus relaciones)
 *   asignar_resultado  → vincula un resultado a una fase
 *   quitar_resultado   → desvincula un resultado de una fase
 *   reordenar_fases    → actualiza el campo `orden` de varias fases
 */

require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/json; charset=utf-8');

$pdo    = getDB();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Leer body JSON si viene así
$body = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw) $body = json_decode($raw, true) ?? [];
    $body = array_merge($_POST, $body);
}

// ── Funciones auxiliares ────────────────────────────────────────────────────
function requirePost(array $fields, array $data): void {
    foreach ($fields as $f) {
        if (!isset($data[$f]) || $data[$f] === '') {
            jsonResponse(['error' => "Campo requerido: $f"], 400);
        }
    }
}

// ══════════════════════════════════════════════════════════════════════════════
switch ($action) {

    // ── Listar fases de una ficha con avance ──────────────────────────────
    case 'fases_ficha':
        $idFicha = (int)($_GET['id_ficha'] ?? 0);
        if (!$idFicha) jsonResponse(['error' => 'id_ficha requerido'], 400);

        $stmt = $pdo->prepare(
            "SELECT
               fp.id_fase, fp.nombre, fp.descripcion, fp.orden,
               fp.fecha_inicio, fp.fecha_fin,
               COUNT(DISTINCT fc.id_resultado) AS total_resultados,
               COALESCE(av.pct_cumplimiento, 0) AS pct_cumplimiento,
               COALESCE(av.aprendices_aprobados, 0) AS aprendices_aprobados,
               COALESCE(av.total_aprendices, 0) AS total_aprendices,
               COALESCE(av.aprobaciones, 0) AS aprobaciones,
               COALESCE(av.total_posibles, 0) AS total_posibles
             FROM fase_proyecto fp
             LEFT JOIN fase_competencia fc ON fp.id_fase = fc.id_fase
             LEFT JOIN v_avance_fase av    ON fp.id_fase = av.id_fase
             WHERE fp.id_ficha = ?
             GROUP BY fp.id_fase
             ORDER BY fp.orden ASC, fp.id_fase ASC"
        );
        $stmt->execute([$idFicha]);
        jsonResponse($stmt->fetchAll());

    // ── Competencias y resultados disponibles para asignar ────────────────
    case 'resultados_ficha':
        $idFicha = (int)($_GET['id_ficha'] ?? 0);
        if (!$idFicha) jsonResponse(['error' => 'id_ficha requerido'], 400);

        // Trae solo competencias/resultados que existen en esa ficha
        $stmt = $pdo->prepare(
            "SELECT
               c.id_competencia, c.nombre AS competencia,
               r.id_resultado, r.descripcion AS resultado,
               CASE WHEN fc.id_fase IS NOT NULL THEN fc.id_fase ELSE NULL END AS id_fase_asignada
             FROM competencia c
             JOIN resultado r ON c.id_competencia = r.id_competencia
             JOIN juicio_evaluativo j ON r.id_resultado = j.id_resultado AND j.id_ficha = ?
             LEFT JOIN fase_competencia fc ON r.id_resultado = fc.id_resultado
             GROUP BY c.id_competencia, c.nombre, r.id_resultado, r.descripcion, fc.id_fase
             ORDER BY c.nombre, r.descripcion"
        );
        $stmt->execute([$idFicha]);
        $rows = $stmt->fetchAll();

        // Agrupar por competencia
        $comps = [];
        foreach ($rows as $row) {
            $cid = $row['id_competencia'];
            if (!isset($comps[$cid])) {
                $comps[$cid] = [
                    'id_competencia'  => $cid,
                    'competencia'     => $row['competencia'],
                    'resultados'      => []
                ];
            }
            $comps[$cid]['resultados'][] = [
                'id_resultado'     => $row['id_resultado'],
                'resultado'        => $row['resultado'],
                'id_fase_asignada' => $row['id_fase_asignada'],
            ];
        }
        jsonResponse(array_values($comps));

    // ── Detalle de una fase: aprendices y su estado ───────────────────────
    case 'detalle_fase':
        $idFase  = (int)($_GET['id_fase']  ?? 0);
        $idFicha = (int)($_GET['id_ficha'] ?? 0);
        if (!$idFase || !$idFicha) jsonResponse(['error' => 'id_fase e id_ficha requeridos'], 400);

        // Resultados de esta fase
        $stmtR = $pdo->prepare(
            "SELECT fc.id_resultado, r.descripcion AS resultado, c.nombre AS competencia
             FROM fase_competencia fc
             JOIN resultado r   ON fc.id_resultado   = r.id_resultado
             JOIN competencia c ON r.id_competencia  = c.id_competencia
             WHERE fc.id_fase = ?
             ORDER BY c.nombre, r.descripcion"
        );
        $stmtR->execute([$idFase]);
        $resultados = $stmtR->fetchAll();

        // Aprendices con avance en esta fase
        $stmtA = $pdo->prepare(
            "SELECT
               a.id_aprendiz,
               CONCAT(a.nombre,' ',a.apellidos) AS aprendiz,
               a.documento, a.estado,
               COUNT(j.id_juicio) AS total,
               SUM(j.estado='APROBADO') AS aprobados,
               SUM(j.estado='POR EVALUAR') AS pendientes,
               ROUND(SUM(j.estado='APROBADO')*100.0/NULLIF(COUNT(j.id_juicio),0),1) AS pct_fase
             FROM aprendiz a
             JOIN juicio_evaluativo j ON a.id_aprendiz = j.id_aprendiz
             JOIN fase_competencia fc ON j.id_resultado = fc.id_resultado AND fc.id_fase = ?
             WHERE a.id_ficha = ?
             GROUP BY a.id_aprendiz, a.nombre, a.apellidos, a.documento, a.estado
             ORDER BY pct_fase DESC, a.apellidos"
        );
        $stmtA->execute([$idFase, $idFicha]);
        $aprendices = $stmtA->fetchAll();

        jsonResponse([
            'resultados' => $resultados,
            'aprendices' => $aprendices,
        ]);

    // ── Crear fase ────────────────────────────────────────────────────────
    case 'crear_fase':
        if ($method !== 'POST') jsonResponse(['error' => 'Método no permitido'], 405);
        requirePost(['id_ficha', 'nombre'], $body);

        $idFicha     = (int)$body['id_ficha'];
        $nombre      = trim($body['nombre']);
        $descripcion = trim($body['descripcion'] ?? '');
        $fechaInicio = $body['fecha_inicio'] ?? null;
        $fechaFin    = $body['fecha_fin']    ?? null;

        // Obtener siguiente orden
        $maxOrden = $pdo->prepare("SELECT COALESCE(MAX(orden),0)+1 FROM fase_proyecto WHERE id_ficha=?");
        $maxOrden->execute([$idFicha]);
        $orden = (int)$maxOrden->fetchColumn();

        $stmt = $pdo->prepare(
            "INSERT INTO fase_proyecto (id_ficha, nombre, descripcion, orden, fecha_inicio, fecha_fin)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$idFicha, $nombre, $descripcion, $orden, $fechaInicio ?: null, $fechaFin ?: null]);
        jsonResponse(['ok' => true, 'id_fase' => $pdo->lastInsertId(), 'orden' => $orden]);

    // ── Autogenerar las 4 Fases Estándar del SENA ─────────────────────────
    case 'generar_fases_sena':
        if ($method !== 'POST') jsonResponse(['error' => 'Método no permitido'], 405);
        requirePost(['id_ficha'], $body);
        $idFicha = (int)$body['id_ficha'];

        // Verificar si ya tiene fases
        $chk = $pdo->prepare("SELECT COUNT(*) FROM fase_proyecto WHERE id_ficha=?");
        $chk->execute([$idFicha]);
        if ($chk->fetchColumn() > 0) {
            jsonResponse(['error' => 'La ficha ya tiene fases creadas.'], 400);
        }

        $fases = [
            ['Análisis', 'Identificación de requerimientos, planteamiento del problema y recolección de información.', 1],
            ['Planeación', 'Estructuración tecnológica, diseño de arquitectura, cronograma y planeación de actividades.', 2],
            ['Ejecución', 'Desarrollo, implementación y ejecución práctica del proyecto formativo.', 3],
            ['Evaluación', 'Verificación de cumplimiento de objetivos, pruebas de calidad y entrega final del proyecto.', 4]
        ];

        $stmt = $pdo->prepare("INSERT INTO fase_proyecto (id_ficha, nombre, descripcion, orden) VALUES (?, ?, ?, ?)");
        foreach ($fases as $f) {
            $stmt->execute([$idFicha, $f[0], $f[1], $f[2]]);
        }
        jsonResponse(['ok' => true]);

    // ── Editar fase ───────────────────────────────────────────────────────
    case 'editar_fase':
        if ($method !== 'POST') jsonResponse(['error' => 'Método no permitido'], 405);
        requirePost(['id_fase', 'nombre'], $body);

        $stmt = $pdo->prepare(
            "UPDATE fase_proyecto
             SET nombre=?, descripcion=?, fecha_inicio=?, fecha_fin=?
             WHERE id_fase=?"
        );
        $stmt->execute([
            trim($body['nombre']),
            trim($body['descripcion'] ?? ''),
            $body['fecha_inicio'] ?: null,
            $body['fecha_fin']    ?: null,
            (int)$body['id_fase']
        ]);
        jsonResponse(['ok' => true, 'afectadas' => $stmt->rowCount()]);

    // ── Eliminar fase ─────────────────────────────────────────────────────
    case 'eliminar_fase':
        if ($method !== 'POST') jsonResponse(['error' => 'Método no permitido'], 405);
        requirePost(['id_fase'], $body);

        $stmt = $pdo->prepare("DELETE FROM fase_proyecto WHERE id_fase=?");
        $stmt->execute([(int)$body['id_fase']]);
        jsonResponse(['ok' => true]);

    // ── Asignar resultado a fase ──────────────────────────────────────────
    case 'asignar_resultado':
        if ($method !== 'POST') jsonResponse(['error' => 'Método no permitido'], 405);
        requirePost(['id_fase', 'id_resultado', 'id_competencia'], $body);

        // Verificar que el resultado no esté ya en otra fase de la misma ficha
        $chk = $pdo->prepare(
            "SELECT fc.id_fase FROM fase_competencia fc
             JOIN fase_proyecto fp ON fc.id_fase = fp.id_fase
             WHERE fc.id_resultado = ? AND fp.id_ficha = (SELECT id_ficha FROM fase_proyecto WHERE id_fase=?)"
        );
        $chk->execute([(int)$body['id_resultado'], (int)$body['id_fase']]);
        $existing = $chk->fetchColumn();
        if ($existing && $existing != (int)$body['id_fase']) {
            jsonResponse(['error' => 'Este resultado ya está asignado a otra fase. Quítalo primero.'], 409);
        }

        $stmt = $pdo->prepare(
            "INSERT INTO fase_competencia (id_fase, id_competencia, id_resultado)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE id_competencia=VALUES(id_competencia)"
        );
        $stmt->execute([(int)$body['id_fase'], (int)$body['id_competencia'], (int)$body['id_resultado']]);
        jsonResponse(['ok' => true]);

    // ── Quitar resultado de fase ──────────────────────────────────────────
    case 'quitar_resultado':
        if ($method !== 'POST') jsonResponse(['error' => 'Método no permitido'], 405);
        requirePost(['id_fase', 'id_resultado'], $body);

        $stmt = $pdo->prepare(
            "DELETE FROM fase_competencia WHERE id_fase=? AND id_resultado=?"
        );
        $stmt->execute([(int)$body['id_fase'], (int)$body['id_resultado']]);
        jsonResponse(['ok' => true]);

    // ── Reordenar fases ───────────────────────────────────────────────────
    case 'reordenar_fases':
        if ($method !== 'POST') jsonResponse(['error' => 'Método no permitido'], 405);
        if (empty($body['orden']) || !is_array($body['orden'])) {
            jsonResponse(['error' => 'Se requiere array orden: [{id_fase, orden}]'], 400);
        }
        $stmt = $pdo->prepare("UPDATE fase_proyecto SET orden=? WHERE id_fase=?");
        foreach ($body['orden'] as $item) {
            $stmt->execute([(int)$item['orden'], (int)$item['id_fase']]);
        }
        jsonResponse(['ok' => true]);

    default:
        jsonResponse(['error' => "Acción '$action' no reconocida"], 400);
}
