<?php
/**
 * api_import.php
 * Recibe el archivo XLS de Sofia Plus y lo procesa hacia la BD.
 * Requiere PhpSpreadsheet en /vendor/  (ver instrucciones en README).
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/vendor/autoload.php';   // PhpSpreadsheet

use PhpOffice\PhpSpreadsheet\IOFactory;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Método no permitido'], 405);
}

if (empty($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(['error' => 'No se recibió archivo o hubo un error al subir'], 400);
}

$tmpPath  = $_FILES['archivo']['tmp_name'];
$origName = $_FILES['archivo']['name'];
$ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

if (!in_array($ext, ['xls', 'xlsx'])) {
    jsonResponse(['error' => 'Solo se aceptan archivos .xls o .xlsx'], 400);
}

try {
    // ── Leer hoja ──────────────────────────────────────────
    $reader      = IOFactory::createReaderForFile($tmpPath);
    $reader->setReadDataOnly(true);
    $spreadsheet = $reader->load($tmpPath);
    $sheet       = $spreadsheet->getActiveSheet();
    $rows        = $sheet->toArray(null, true, true, false);

    // ── Extraer metadatos de cabecera (filas 0-11) ─────────
    $meta = [];
    foreach ($rows as $i => $row) {
        if ($i > 11) break;
        $key = trim((string)($row[0] ?? ''));
        $val = trim((string)($row[2] ?? ''));
        if ($key !== '') $meta[$key] = $val;
    }

    $codigoFicha = $meta['Ficha de Caracterización:'] ?? '';
    $codigoProg  = $meta['Cógigo:']                   ?? ($meta['Código:'] ?? '');
    $versionProg = (int)($meta['Versión:']            ?? 1);
    $nombreProg  = $meta['Denominación:']             ?? 'Sin nombre';
    $estadoFicha = $meta['Estado de la Ficha de Caracterización:'] ?? 'EN EJECUCION';
    $modalidad   = $meta['Modalidad de Formación:']   ?? '';
    $regional    = $meta['Regional:']                 ?? '';
    $centro      = $meta['Centro de Formación:']      ?? '';
    $fechaInicio = $meta['Fecha Inicio:']             ?? null;
    $fechaFin    = $meta['Fecha Fin:']                ?? null;

    if (!$codigoFicha) {
        jsonResponse(['error' => 'No se encontró "Ficha de Caracterización:" en el archivo'], 422);
    }

    // Normalizar fechas
    $parseFecha = function($v) {
        if (!$v) return null;
        try { return (new DateTime($v))->format('Y-m-d'); } catch (Exception $e) { return null; }
    };

    $pdo = getDB();
    $pdo->beginTransaction();

    // ── 1. Programa ────────────────────────────────────────
    $stmt = $pdo->prepare(
        "INSERT INTO programa (codigo_prog, version, nombre)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE nombre = VALUES(nombre)"
    );
    $stmt->execute([$codigoProg, $versionProg, $nombreProg]);
    $idPrograma = $pdo->query(
        "SELECT id_programa FROM programa WHERE codigo_prog='$codigoProg' AND version=$versionProg"
    )->fetchColumn();

    // ── 2. Ficha ───────────────────────────────────────────
    $stmt = $pdo->prepare(
        "INSERT INTO ficha (codigo_ficha, id_programa, estado_ficha, modalidad, regional, centro, fecha_inicio, fecha_fin)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           estado_ficha=VALUES(estado_ficha), modalidad=VALUES(modalidad),
           regional=VALUES(regional), centro=VALUES(centro),
           fecha_inicio=VALUES(fecha_inicio), fecha_fin=VALUES(fecha_fin)"
    );
    $stmt->execute([
        $codigoFicha, $idPrograma, $estadoFicha, $modalidad,
        $regional, $centro,
        $parseFecha($fechaInicio), $parseFecha($fechaFin)
    ]);
    $idFicha = $pdo->query(
        "SELECT id_ficha FROM ficha WHERE codigo_ficha='$codigoFicha'"
    )->fetchColumn();

    // ── 3. Procesar filas de datos (desde fila 13, índice 12) ─
    $insertados  = 0;
    $duplicados  = 0;
    $errores     = [];

    // Caches para no repetir SELECT
    $cacheComp  = [];
    $cacheRes   = [];
    $cacheInst  = [];
    $cacheApren = [];

    $dataRows = array_slice($rows, 13); // saltar cabecera y encabezados de columna

    foreach ($dataRows as $lineNum => $row) {
        // Columnas: 0=TipoDoc, 1=NumDoc, 2=Nombre, 3=Apellidos, 4=Estado,
        //           5=Competencia, 6=Resultado, 7=Juicio, 8=Fecha, 9=Funcionario
        $tipoDoc    = trim((string)($row[0] ?? 'CC'));
        $numDoc     = trim((string)($row[1] ?? ''));
        $nombre     = trim((string)($row[2] ?? ''));
        $apellidos  = trim((string)($row[3] ?? ''));
        $estadoAp   = strtoupper(trim((string)($row[4] ?? 'EN FORMACION')));
        $compRaw    = trim((string)($row[5] ?? ''));
        $resRaw     = trim((string)($row[6] ?? ''));
        $juicioEst  = strtoupper(trim((string)($row[7] ?? 'POR EVALUAR')));
        $fechaJuicio= $row[8] ?? null;
        $funcRaw    = trim((string)($row[9] ?? ''));

        if (!$numDoc || !$compRaw || !$resRaw) continue;

        // Normalizar estado aprendiz
        $estadosValidos = ['EN FORMACION','RETIRO VOLUNTARIO','TRASLADADO','APLAZADO'];
        if (!in_array($estadoAp, $estadosValidos)) $estadoAp = 'EN FORMACION';

        try {
            // ── Competencia ────────────────────────────────
            if (!isset($cacheComp[$compRaw])) {
                // Formato: "CODIGO - Nombre"
                $partesComp = explode(' - ', $compRaw, 2);
                $codComp    = trim($partesComp[0]);
                $nomComp    = trim($partesComp[1] ?? $compRaw);
                $stmt = $pdo->prepare(
                    "INSERT INTO competencia (codigo_comp, nombre) VALUES (?,?)
                     ON DUPLICATE KEY UPDATE nombre=VALUES(nombre)"
                );
                $stmt->execute([$codComp, $nomComp]);
                $cacheComp[$compRaw] = $pdo->query(
                    "SELECT id_competencia FROM competencia WHERE codigo_comp='$codComp'"
                )->fetchColumn();
            }
            $idComp = $cacheComp[$compRaw];

            // ── Resultado ──────────────────────────────────
            if (!isset($cacheRes[$resRaw])) {
                $partesRes = explode(' - ', $resRaw, 2);
                $codRes    = trim($partesRes[0]);
                $descRes   = trim($partesRes[1] ?? $resRaw);
                $stmt = $pdo->prepare(
                    "INSERT INTO resultado (codigo_resultado, descripcion, id_competencia) VALUES (?,?,?)
                     ON DUPLICATE KEY UPDATE descripcion=VALUES(descripcion)"
                );
                $stmt->execute([$codRes, $descRes, $idComp]);
                $cacheRes[$resRaw] = $pdo->query(
                    "SELECT id_resultado FROM resultado WHERE codigo_resultado='$codRes'"
                )->fetchColumn();
            }
            $idRes = $cacheRes[$resRaw];

            // ── Instructor ─────────────────────────────────
            $idInst = null;
            if ($funcRaw && $funcRaw !== '-' && $funcRaw !== '  -   ') {
                if (!isset($cacheInst[$funcRaw])) {
                    // Formato: "CC 12345678 - NOMBRE APELLIDO"
                    preg_match('/^(\w+)\s+(\d+)\s+-\s+(.+)$/', $funcRaw, $m);
                    $tipoI  = $m[1] ?? 'CC';
                    $docI   = $m[2] ?? $funcRaw;
                    $nomI   = trim($m[3] ?? $funcRaw);
                    $stmt = $pdo->prepare(
                        "INSERT INTO instructor (tipo_documento, documento, nombre_completo) VALUES (?,?,?)
                         ON DUPLICATE KEY UPDATE nombre_completo=VALUES(nombre_completo)"
                    );
                    $stmt->execute([$tipoI, $docI, $nomI]);
                    $cacheInst[$funcRaw] = $pdo->query(
                        "SELECT id_instructor FROM instructor WHERE documento='$docI'"
                    )->fetchColumn();
                }
                $idInst = $cacheInst[$funcRaw];
            }

            // ── Aprendiz ───────────────────────────────────
            $keyAp = $numDoc . '_' . $idFicha;
            if (!isset($cacheApren[$keyAp])) {
                $stmt = $pdo->prepare(
                    "INSERT INTO aprendiz (tipo_documento, documento, nombre, apellidos, estado, id_ficha)
                     VALUES (?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE estado=VALUES(estado)"
                );
                $stmt->execute([$tipoDoc, $numDoc, $nombre, $apellidos, $estadoAp, $idFicha]);
                $cacheApren[$keyAp] = $pdo->query(
                    "SELECT id_aprendiz FROM aprendiz WHERE documento='$numDoc' AND id_ficha=$idFicha"
                )->fetchColumn();
            }
            $idAprendiz = $cacheApren[$keyAp];

            // Normalizar fecha juicio
            $fechaSQL = null;
            if ($fechaJuicio && $fechaJuicio !== '') {
                try {
                    if ($fechaJuicio instanceof DateTime) {
                        $fechaSQL = $fechaJuicio->format('Y-m-d H:i:s');
                    } else {
                        $fechaSQL = (new DateTime((string)$fechaJuicio))->format('Y-m-d H:i:s');
                    }
                } catch (Exception $e) { $fechaSQL = null; }
            }

            // ── Juicio Evaluativo ──────────────────────────
            $stmt = $pdo->prepare(
                "INSERT INTO juicio_evaluativo (id_aprendiz, id_resultado, id_instructor, estado, fecha, id_ficha)
                 VALUES (?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                   id_instructor=VALUES(id_instructor),
                   estado=VALUES(estado),
                   fecha=VALUES(fecha)"
            );
            $stmt->execute([$idAprendiz, $idRes, $idInst, $juicioEst, $fechaSQL, $idFicha]);
            $insertados++;

        } catch (Exception $e) {
            $errores[] = "Fila ".($lineNum+14).": ".$e->getMessage();
        }
    }

    $pdo->commit();

    jsonResponse([
        'ok'         => true,
        'ficha'      => $codigoFicha,
        'programa'   => $nombreProg,
        'insertados' => $insertados,
        'duplicados' => $duplicados,
        'errores'    => $errores,
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(['error' => $e->getMessage()], 500);
}
