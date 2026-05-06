<?php
/**
 * api_data.php
 * Endpoint unificado para todas las consultas del sistema.
 * Uso: api_data.php?action=NOMBRE&param=valor
 */
require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/json; charset=utf-8');
$pdo    = getDB();
$action = $_GET['action'] ?? '';

switch ($action) {

    // ── Listado de fichas disponibles ──────────────────────────
    case 'fichas':
        $rows = $pdo->query(
            "SELECT f.id_ficha, f.codigo_ficha, p.nombre AS programa,
                    f.estado_ficha, f.modalidad, f.regional
             FROM ficha f JOIN programa p ON f.id_programa = p.id_programa
             ORDER BY f.codigo_ficha"
        )->fetchAll();
        jsonResponse($rows);

    // ── Dashboard: indicadores globales de una ficha ──────────
    // ── Dashboard: indicadores globales de una ficha ──────────
    case 'dashboard':
        $idFicha = (int)($_GET['id_ficha'] ?? 0);

        $whereFicha = $idFicha ? "WHERE id_ficha = ?" : "WHERE 1=1";
        $paramsFicha = $idFicha ? [$idFicha] : [];

        // Conteo de aprendices únicos por estado
        $aprendices = $pdo->prepare(
            "SELECT
               COUNT(DISTINCT id_aprendiz)                           AS total_aprendices,
               COUNT(DISTINCT CASE WHEN estado='EN FORMACION'
                              THEN id_aprendiz END)                  AS en_formacion,
               COUNT(DISTINCT CASE WHEN estado='RETIRO VOLUNTARIO'
                              THEN id_aprendiz END)                  AS retirados,
               COUNT(DISTINCT CASE WHEN estado='TRASLADADO'
                              THEN id_aprendiz END)                  AS trasladados
             FROM aprendiz
             $whereFicha"
        );
        $aprendices->execute($paramsFicha);
        $a = $aprendices->fetch();

        // Conteo de juicios
        $juicios = $pdo->prepare(
            "SELECT
               COUNT(*)                              AS total_juicios,
               SUM(estado = 'APROBADO')              AS aprobados,
               SUM(estado = 'POR EVALUAR')           AS pendientes,
               ROUND(SUM(estado='APROBADO') * 100.0
                 / NULLIF(COUNT(*), 0), 1)           AS pct_global
             FROM juicio_evaluativo
             $whereFicha"
        );
        $juicios->execute($paramsFicha);
        $j = $juicios->fetch();

        jsonResponse(array_merge($a, $j));


    // ── Avance por aprendiz ────────────────────────────────────
    case 'avance_aprendices':
        $idFicha      = (int)($_GET['id_ficha']      ?? 0);
        $estado       = $_GET['estado']              ?? '';
        $busqueda     = $_GET['busqueda']            ?? '';
        $idComp       = (int)($_GET['id_competencia'] ?? 0);
        $idInst       = (int)($_GET['id_instructor']  ?? 0);
        $estadoJuicio = $_GET['estado_juicio']       ?? '';

        $where = ['1=1'];
        $params = [];
        if ($idFicha) {
            $where[] = 'a.id_ficha = ?';
            $params[] = $idFicha;
        }

        if ($estado) { $where[] = 'a.estado = ?'; $params[] = $estado; }
        if ($busqueda) {
            $where[] = "(a.nombre LIKE ? OR a.apellidos LIKE ? OR a.documento LIKE ?)";
            $b = "%$busqueda%";
            $params = array_merge($params, [$b, $b, $b]);
        }

        // Si hay filtros de juicios, aseguramos que el aprendiz tenga juicios que coincidan
        $jJoin = "LEFT JOIN juicio_evaluativo j ON a.id_aprendiz = j.id_aprendiz";
        $jWhere = [];
        if ($idComp) { 
            // Necesitamos unir con resultado para filtrar por competencia
            $jJoin .= " JOIN resultado r ON j.id_resultado = r.id_resultado";
            $where[] = "r.id_competencia = ?"; 
            $params[] = $idComp; 
        }
        if ($idInst) { $where[] = "j.id_instructor = ?"; $params[] = $idInst; }
        if ($estadoJuicio) { $where[] = "j.estado = ?"; $params[] = $estadoJuicio; }

        $sql = "SELECT 
          a.id_aprendiz, a.tipo_documento, a.documento, 
          a.nombre, a.apellidos, 
          CONCAT(a.nombre,' ',a.apellidos) AS aprendiz_completo,
          a.estado,
          COUNT(j.id_juicio)                    AS total_resultados,
          SUM(j.estado='APROBADO')              AS aprobados,
          SUM(j.estado='POR EVALUAR')           AS pendientes,
          ROUND(SUM(j.estado='APROBADO')*100.0/NULLIF(COUNT(j.id_juicio),0),1) AS avance_pct
        FROM aprendiz a
        $jJoin
        WHERE " . implode(' AND ', $where) . "
        GROUP BY a.id_aprendiz";

        // Filtro de rango de avance
        $minPct = isset($_GET['min_pct']) ? (float)$_GET['min_pct'] : null;
        $maxPct = isset($_GET['max_pct']) ? (float)$_GET['max_pct'] : null;
        
        $having = [];
        if ($minPct !== null) { $having[] = "avance_pct >= $minPct"; }
        if ($maxPct !== null) { $having[] = "avance_pct <= $maxPct"; }
        
        if ($having) {
            $sql .= " HAVING " . implode(' AND ', $having);
        }

        $sql .= " ORDER BY avance_pct DESC, a.apellidos";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        jsonResponse($stmt->fetchAll());

    // ── Detalle de juicios de un aprendiz ─────────────────────
    case 'juicios_aprendiz':
        $idAprendiz   = (int)($_GET['id_aprendiz'] ?? 0);
        $idComp       = (int)($_GET['id_competencia'] ?? 0);
        $idInst       = (int)($_GET['id_instructor'] ?? 0);
        $juicioEstado = $_GET['estado_juicio'] ?? '';

        $where = ['j.id_aprendiz = ?'];
        $params = [$idAprendiz];

        if ($idComp)       { $where[] = 'c.id_competencia = ?'; $params[] = $idComp; }
        if ($idInst)       { $where[] = 'j.id_instructor = ?';  $params[] = $idInst; }
        if ($juicioEstado) { $where[] = 'j.estado = ?';         $params[] = $juicioEstado; }

        $stmt = $pdo->prepare(
            "SELECT j.id_juicio, c.id_competencia, c.codigo_comp, c.nombre AS competencia,
                    r.codigo_resultado, r.descripcion AS resultado,
                    j.estado AS juicio, j.fecha,
                    COALESCE(i.nombre_completo,'—') AS instructor
             FROM juicio_evaluativo j
             JOIN resultado r    ON j.id_resultado   = r.id_resultado
             JOIN competencia c  ON r.id_competencia = c.id_competencia
             LEFT JOIN instructor i ON j.id_instructor = i.id_instructor
             WHERE " . implode(' AND ', $where) . "
             ORDER BY c.nombre, r.descripcion"
        );
        $stmt->execute($params);
        jsonResponse($stmt->fetchAll());

    // ── Estadísticas avanzadas de un aprendiz (incluye comparativa de pares) ──
    case 'stats_aprendiz':
        $idAprendiz = (int)($_GET['id_aprendiz'] ?? 0);
        if (!$idAprendiz) jsonResponse(['error' => 'ID requerido'], 400);

        // 1. Datos básicos y conteos
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(estado='APROBADO') as aprobados,
                SUM(estado='POR EVALUAR') as pendientes,
                ROUND(SUM(estado='APROBADO')*100.0/COUNT(*),1) as pct,
                id_ficha
            FROM juicio_evaluativo 
            WHERE id_aprendiz = ?
        ");
        $stmt->execute([$idAprendiz]);
        $stats = $stmt->fetch();
        $idFicha = $stats['id_ficha'];

        // 2. Comparativa de pares: buscar resultados pendientes que la mayoría de compañeros YA tiene aprobados
        // Definimos "mayoría" como > 70% de los compañeros con juicio
        $stmtP = $pdo->prepare("
            SELECT 
                r.descripcion AS resultado,
                c.nombre AS competencia,
                (SELECT COUNT(*) FROM juicio_evaluativo j2 WHERE j2.id_resultado = j.id_resultado AND j2.estado = 'APROBADO' AND j2.id_ficha = ?) AS otros_aprobados,
                (SELECT COUNT(*) FROM juicio_evaluativo j2 WHERE j2.id_resultado = j.id_resultado AND j2.id_ficha = ?) AS total_pares
            FROM juicio_evaluativo j
            JOIN resultado r ON j.id_resultado = r.id_resultado
            JOIN competencia c ON r.id_competencia = c.id_competencia
            WHERE j.id_aprendiz = ? AND j.estado = 'POR EVALUAR'
            HAVING (otros_aprobados * 100.0 / NULLIF(total_pares, 0)) > 70
            ORDER BY otros_aprobados DESC
        ");
        $stmtP->execute([$idFicha, $idFicha, $idAprendiz]);
        $alertas = $stmtP->fetchAll();

        jsonResponse([
            'resumen' => $stats,
            'alertas' => $alertas
        ]);

    // ── Avance por competencia ─────────────────────────────────
    case 'avance_competencias':
        $idFicha = (int)($_GET['id_ficha'] ?? 0);
        $whereFicha = $idFicha ? "WHERE j.id_ficha = ?" : "WHERE 1=1";
        $paramsFicha = $idFicha ? [$idFicha] : [];
        $stmt = $pdo->prepare(
            "SELECT c.id_competencia, c.nombre AS competencia,
                    COUNT(j.id_juicio) AS total,
                    SUM(j.estado='APROBADO') AS aprobados,
                    SUM(j.estado='POR EVALUAR') AS pendientes,
                    ROUND(SUM(j.estado='APROBADO')*100.0/NULLIF(COUNT(j.id_juicio),0),1) AS pct
             FROM competencia c
             JOIN resultado r    ON c.id_competencia = r.id_competencia
             JOIN juicio_evaluativo j ON r.id_resultado = j.id_resultado
             $whereFicha
             GROUP BY c.id_competencia, c.nombre
             ORDER BY pct DESC"
        );
        $stmt->execute($paramsFicha);
        jsonResponse($stmt->fetchAll());

    // -- Analitica academica: riesgos, pendientes y comparativas --
    case 'analitica':
        $idFicha = (int)($_GET['id_ficha'] ?? 0);
        $whereJuicio = $idFicha ? "WHERE j.id_ficha = ?" : "WHERE 1=1";
        $paramsJuicio = $idFicha ? [$idFicha] : [];
        $whereAprendiz = $idFicha ? "WHERE a.id_ficha = ?" : "WHERE 1=1";
        $paramsAprendiz = $idFicha ? [$idFicha] : [];

        $stmt = $pdo->prepare(
            "SELECT c.id_competencia, c.nombre AS competencia,
                    COUNT(j.id_juicio) AS total,
                    SUM(j.estado='APROBADO') AS aprobados,
                    SUM(j.estado='POR EVALUAR') AS pendientes,
                    ROUND(SUM(j.estado='APROBADO') * 100.0 / NULLIF(COUNT(j.id_juicio), 0), 1) AS pct_aprobacion
             FROM juicio_evaluativo j
             JOIN resultado r ON j.id_resultado = r.id_resultado
             JOIN competencia c ON r.id_competencia = c.id_competencia
             $whereJuicio
             GROUP BY c.id_competencia, c.nombre
             HAVING total > 0
             ORDER BY pct_aprobacion ASC, pendientes DESC, total DESC
             LIMIT 8"
        );
        $stmt->execute($paramsJuicio);
        $competenciasMenorAprobacion = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            "SELECT r.id_resultado, r.descripcion AS resultado,
                    c.nombre AS competencia,
                    COUNT(j.id_juicio) AS total,
                    SUM(j.estado='POR EVALUAR') AS pendientes,
                    SUM(j.estado='APROBADO') AS aprobados,
                    ROUND(SUM(j.estado='POR EVALUAR') * 100.0 / NULLIF(COUNT(j.id_juicio), 0), 1) AS pct_pendiente
             FROM juicio_evaluativo j
             JOIN resultado r ON j.id_resultado = r.id_resultado
             JOIN competencia c ON r.id_competencia = c.id_competencia
             $whereJuicio
             GROUP BY r.id_resultado, r.descripcion, c.nombre
             HAVING pendientes > 0
             ORDER BY pendientes DESC, pct_pendiente DESC, total DESC
             LIMIT 10"
        );
        $stmt->execute($paramsJuicio);
        $resultadosPendientes = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            "SELECT a.id_aprendiz, a.documento,
                    CONCAT(a.nombre, ' ', a.apellidos) AS aprendiz,
                    a.estado,
                    f.codigo_ficha,
                    av.total_resultados,
                    av.aprobados,
                    av.pendientes,
                    av.avance_pct,
                    COUNT(j.id_juicio) AS pendientes_prioritarios,
                    ROUND(AVG(rs.pct_aprobado), 1) AS pct_grupo_promedio,
                    GROUP_CONCAT(
                      CONCAT(r.descripcion, '||', c.nombre, '||', rs.pct_aprobado, '||', rs.aprobados, '/', rs.total_pares)
                      ORDER BY rs.pct_aprobado DESC, rs.aprobados DESC
                      SEPARATOR '##'
                    ) AS pendientes_clave
             FROM aprendiz a
             JOIN ficha f ON a.id_ficha = f.id_ficha
             JOIN juicio_evaluativo j ON a.id_aprendiz = j.id_aprendiz AND j.estado = 'POR EVALUAR'
             JOIN resultado r ON j.id_resultado = r.id_resultado
             JOIN competencia c ON r.id_competencia = c.id_competencia
             JOIN (
                SELECT id_ficha, id_resultado,
                       COUNT(*) AS total_pares,
                       SUM(estado='APROBADO') AS aprobados,
                       ROUND(SUM(estado='APROBADO') * 100.0 / NULLIF(COUNT(*), 0), 1) AS pct_aprobado
                FROM juicio_evaluativo
                GROUP BY id_ficha, id_resultado
             ) rs ON rs.id_ficha = j.id_ficha AND rs.id_resultado = j.id_resultado
             JOIN (
                SELECT id_aprendiz,
                       COUNT(*) AS total_resultados,
                       SUM(estado='APROBADO') AS aprobados,
                       SUM(estado='POR EVALUAR') AS pendientes,
                       ROUND(SUM(estado='APROBADO') * 100.0 / NULLIF(COUNT(*), 0), 1) AS avance_pct
                FROM juicio_evaluativo
                GROUP BY id_aprendiz
             ) av ON av.id_aprendiz = a.id_aprendiz
             $whereAprendiz AND a.estado = 'EN FORMACION'
               AND rs.total_pares > 1
               AND rs.pct_aprobado >= 70
             GROUP BY a.id_aprendiz, a.documento, a.nombre, a.apellidos, a.estado, f.codigo_ficha,
                      av.total_resultados, av.aprobados, av.pendientes, av.avance_pct
             ORDER BY pendientes_prioritarios DESC, pct_grupo_promedio DESC, av.pendientes DESC
             LIMIT 10"
        );
        $stmt->execute($paramsAprendiz);
        $aprendicesRiesgo = $stmt->fetchAll();

        if ($idFicha) {
            $comparacionFichas = [];
        } else {
            $stmt = $pdo->query(
                "SELECT f.id_ficha, f.codigo_ficha, p.nombre AS programa,
                        COUNT(DISTINCT a.id_aprendiz) AS total_aprendices,
                        COUNT(DISTINCT CASE WHEN a.estado='RETIRO VOLUNTARIO' THEN a.id_aprendiz END) AS retirados,
                        COUNT(DISTINCT CASE WHEN a.estado='TRASLADADO' THEN a.id_aprendiz END) AS trasladados,
                        COUNT(j.id_juicio) AS total_juicios,
                        SUM(j.estado='APROBADO') AS aprobados,
                        SUM(j.estado='POR EVALUAR') AS pendientes,
                        ROUND(SUM(j.estado='APROBADO') * 100.0 / NULLIF(COUNT(j.id_juicio), 0), 1) AS avance_pct
                 FROM ficha f
                 JOIN programa p ON f.id_programa = p.id_programa
                 LEFT JOIN aprendiz a ON f.id_ficha = a.id_ficha
                 LEFT JOIN juicio_evaluativo j ON a.id_aprendiz = j.id_aprendiz
                 GROUP BY f.id_ficha, f.codigo_ficha, p.nombre
                 ORDER BY pendientes DESC, retirados DESC, avance_pct ASC
                 LIMIT 10"
            );
            $comparacionFichas = $stmt->fetchAll();
        }

        $stmt = $pdo->prepare(
            "SELECT a.estado,
                    COUNT(DISTINCT a.id_aprendiz) AS aprendices,
                    ROUND(AVG(x.avance_pct), 1) AS avance_promedio,
                    SUM(x.aprobados) AS aprobados,
                    SUM(x.pendientes) AS pendientes
             FROM aprendiz a
             JOIN (
                SELECT id_aprendiz,
                       SUM(estado='APROBADO') AS aprobados,
                       SUM(estado='POR EVALUAR') AS pendientes,
                       ROUND(SUM(estado='APROBADO') * 100.0 / NULLIF(COUNT(*), 0), 1) AS avance_pct
                FROM juicio_evaluativo
                GROUP BY id_aprendiz
             ) x ON a.id_aprendiz = x.id_aprendiz
             $whereAprendiz
             GROUP BY a.estado
             ORDER BY avance_promedio ASC, pendientes DESC"
        );
        $stmt->execute($paramsAprendiz);
        $estadoVsAvance = $stmt->fetchAll();

        jsonResponse([
            'competencias_menor_aprobacion' => $competenciasMenorAprobacion,
            'resultados_mas_pendientes' => $resultadosPendientes,
            'aprendices_riesgo' => $aprendicesRiesgo,
            'comparacion_fichas' => $comparacionFichas,
            'estado_vs_avance' => $estadoVsAvance,
        ]);

    // ── Tabla general de juicios con todos los filtros ─────────
    case 'tabla_juicios':
        $idFicha     = (int)($_GET['id_ficha']     ?? 0);
        $busqueda    = $_GET['busqueda']            ?? '';
        $estadoAp    = $_GET['estado_aprendiz']     ?? '';
        $estadoJuicio= $_GET['estado_juicio']       ?? '';
        $idComp      = (int)($_GET['id_competencia']?? 0);
        $limit       = min((int)($_GET['limit'] ?? 50), 500);
        $offset      = (int)($_GET['offset'] ?? 0);

        $where  = ['1=1'];
        $params = [];

        if ($idFicha)   { $where[] = 'j.id_ficha = ?';    $params[] = $idFicha; }
        if ($estadoAp)  { $where[] = 'a.estado = ?';      $params[] = $estadoAp; }
        if ($estadoJuicio){ $where[]= 'j.estado = ?';     $params[] = $estadoJuicio; }
        if ($idComp)    { $where[] = 'c.id_competencia=?';$params[] = $idComp; }
        if (isset($_GET['id_instructor']) && $_GET['id_instructor'] !== '') {
            $where[] = 'j.id_instructor = ?'; $params[] = (int)$_GET['id_instructor'];
        }
        if ($busqueda)  {
            $where[] = "(a.nombre LIKE ? OR a.apellidos LIKE ? OR a.documento LIKE ?)";
            $b = "%$busqueda%";
            $params = array_merge($params, [$b,$b,$b]);
        }

        $cond = implode(' AND ', $where);

        // Contar total
        $total = $pdo->prepare("SELECT COUNT(*) FROM juicio_evaluativo j
            JOIN aprendiz a   ON j.id_aprendiz  = a.id_aprendiz
            JOIN resultado r  ON j.id_resultado = r.id_resultado
            JOIN competencia c ON r.id_competencia = c.id_competencia
            WHERE $cond");
        $total->execute($params);
        $totalRows = (int)$total->fetchColumn();

        $stmt = $pdo->prepare(
            "SELECT
               CONCAT(a.nombre,' ',a.apellidos) AS aprendiz,
               a.documento, a.estado AS estado_aprendiz,
               c.nombre AS competencia,
               r.descripcion AS resultado,
               j.estado AS juicio,
               j.fecha,
               COALESCE(i.nombre_completo,'—') AS instructor
             FROM juicio_evaluativo j
             JOIN aprendiz a    ON j.id_aprendiz   = a.id_aprendiz
             JOIN resultado r   ON j.id_resultado  = r.id_resultado
             JOIN competencia c ON r.id_competencia= c.id_competencia
             LEFT JOIN instructor i ON j.id_instructor = i.id_instructor
             WHERE $cond
             ORDER BY a.apellidos, c.nombre, r.descripcion
             LIMIT $limit OFFSET $offset"
        );
        $stmt->execute($params);
        jsonResponse(['total' => $totalRows, 'rows' => $stmt->fetchAll()]);

    // ── Listado de instructores de una ficha ───────────────────
    case 'instructores':
        $idFicha = (int)($_GET['id_ficha'] ?? 0);
        $whereFicha = $idFicha ? "WHERE j.id_ficha = ?" : "WHERE 1=1";
        $paramsFicha = $idFicha ? [$idFicha] : [];
        $stmt = $pdo->prepare(
            "SELECT DISTINCT i.id_instructor, i.nombre_completo
             FROM instructor i
             JOIN juicio_evaluativo j ON i.id_instructor = j.id_instructor
             $whereFicha
             ORDER BY i.nombre_completo"
        );
        $stmt->execute($paramsFicha);
        jsonResponse($stmt->fetchAll());
        break;

    case 'competencias':
        $idFicha = (int)($_GET['id_ficha'] ?? 0);
        $whereFicha = $idFicha ? "WHERE j.id_ficha = ?" : "WHERE 1=1";
        $paramsFicha = $idFicha ? [$idFicha] : [];
        $stmt = $pdo->prepare(
            "SELECT DISTINCT c.id_competencia, c.nombre
             FROM competencia c
             JOIN resultado r ON c.id_competencia = r.id_competencia
             JOIN juicio_evaluativo j ON r.id_resultado = j.id_resultado
             $whereFicha
             ORDER BY c.nombre"
        );
        $stmt->execute($paramsFicha);
        jsonResponse($stmt->fetchAll());

    default:
        jsonResponse(['error' => "Acción '$action' no reconocida"], 400);
}
