<?php
/**
 * api_import.php
 * Recibe el archivo XLS de Sofia Plus y lo sincroniza contra la BD.
 * Requiere PhpSpreadsheet en /vendor/  (ver instrucciones en README).
 *
 * The import is a reconciliation, not a one-shot load: every row is compared
 * against the stored judgement so the caller learns what was created, what
 * changed, and what is in the database but missing from the file.
 * Nothing is ever deleted here — orphans are reported only.
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

// El cliente reenvía el archivo con confirmar=1 cuando ya aceptó los riesgos
// reportados por un intento anterior.
$confirmado = ($_POST['confirmar'] ?? '') === '1';

if (!in_array($ext, ['xls', 'xlsx'])) {
    jsonResponse(['error' => 'Solo se aceptan archivos .xls o .xlsx'], 400);
}

/** Normalizes a loose date value to SQL format, or null when unusable. */
function toSqlDate($value, string $format = 'Y-m-d') {
    if ($value === null || $value === '' || $value === '-') return null;
    try {
        if ($value instanceof DateTime) return $value->format($format);
        return (new DateTime((string)$value))->format($format);
    } catch (Exception $e) {
        return null;
    }
}

/** Compares two values treating null and empty string as equivalent. */
function sameValue($a, $b): bool {
    return (string)($a ?? '') === (string)($b ?? '');
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
        jsonResponse(['error' => 'No se encontró "Ficha de Caracterización:" en el archivo. ¿Es un reporte de juicios de Sofia Plus?'], 422);
    }
    if (!$codigoProg) {
        jsonResponse(['error' => 'El archivo no trae el código del programa. Sin ese dato la ficha no se puede relacionar.'], 422);
    }

    // ── Localizar la fila de encabezados en vez de asumir su posición ──
    // Sofia Plus mueve la cabecera entre versiones del reporte.
    $headerIndex = null;
    foreach ($rows as $i => $row) {
        if ($i > 30) break;
        $first = strtolower(trim((string)($row[0] ?? '')));
        if (preg_match('/tipo\s*de\s*documento/', $first)) { $headerIndex = $i; break; }
    }
    $startIndex = $headerIndex !== null ? $headerIndex + 1 : 13;

    $pdo = getDB();
    $pdo->beginTransaction();

    // ── 1. Programa ────────────────────────────────────────
    $stmt = $pdo->prepare(
        "INSERT INTO programa (codigo_prog, version, nombre)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE nombre = VALUES(nombre)"
    );
    $stmt->execute([$codigoProg, $versionProg, $nombreProg]);

    $stmt = $pdo->prepare("SELECT id_programa FROM programa WHERE codigo_prog = ? AND version = ?");
    $stmt->execute([$codigoProg, $versionProg]);
    $idPrograma = $stmt->fetchColumn();
    if (!$idPrograma) throw new Exception("No se pudo registrar el programa $codigoProg version $versionProg");

    // ── 2. Ficha ───────────────────────────────────────────
    $stmt = $pdo->prepare(
        "INSERT INTO ficha (codigo_ficha, id_programa, estado_ficha, modalidad, regional, centro, fecha_inicio, fecha_fin)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           id_programa=VALUES(id_programa),
           estado_ficha=VALUES(estado_ficha), modalidad=VALUES(modalidad),
           regional=VALUES(regional), centro=VALUES(centro),
           fecha_inicio=VALUES(fecha_inicio), fecha_fin=VALUES(fecha_fin)"
    );
    $stmt->execute([
        $codigoFicha, $idPrograma, $estadoFicha, $modalidad,
        $regional, $centro,
        toSqlDate($fechaInicio), toSqlDate($fechaFin)
    ]);

    $stmt = $pdo->prepare("SELECT id_ficha FROM ficha WHERE codigo_ficha = ?");
    $stmt->execute([$codigoFicha]);
    $idFicha = $stmt->fetchColumn();
    if (!$idFicha) throw new Exception("No se pudo registrar la ficha $codigoFicha");

    // ── 3. Estado actual en BD, para poder comparar ────────
    $stmt = $pdo->prepare(
        "SELECT id_aprendiz, id_resultado, estado, fecha, id_instructor
         FROM juicio_evaluativo WHERE id_ficha = ?"
    );
    $stmt->execute([$idFicha]);
    $juiciosBD = [];
    foreach ($stmt->fetchAll() as $j) {
        $juiciosBD[$j['id_aprendiz'] . '_' . $j['id_resultado']] = $j;
    }

    $stmt = $pdo->prepare("SELECT id_aprendiz, documento, nombre, apellidos FROM aprendiz WHERE id_ficha = ?");
    $stmt->execute([$idFicha]);
    $aprendicesBD = [];
    foreach ($stmt->fetchAll() as $a) {
        $aprendicesBD[$a['documento']] = $a;
    }

    // ── 4. Procesar filas de datos ─────────────────────────
    $nuevos           = 0;
    $actualizados     = 0;
    $sinCambios       = 0;
    $filasLeidas      = 0;
    $aprendicesNuevos = 0;
    $cambios          = [];   // muestra legible de lo que cambió
    $errores          = [];
    $degradaciones    = [];   // juicios ya APROBADOS que el archivo quiere revertir
    $totalDegradadas  = 0;

    $vistosJuicio   = [];  // claves id_aprendiz_id_resultado presentes en el archivo
    $vistosAprendiz = [];  // documentos presentes en el archivo

    // Caches para no repetir SELECT
    $cacheComp  = [];
    $cacheRes   = [];
    $cacheInst  = [];
    $cacheApren = [];

    $dataRows = array_slice($rows, $startIndex);

    foreach ($dataRows as $lineNum => $row) {
        // Columnas: 0=TipoDoc, 1=NumDoc, 2=Nombre, 3=Apellidos, 4=Estado,
        //           5=Competencia, 6=Resultado, 7=Juicio, 8=Fecha, 9=Funcionario
        $tipoDoc     = trim((string)($row[0] ?? 'CC'));
        $numDoc      = trim((string)($row[1] ?? ''));
        $nombre      = trim((string)($row[2] ?? ''));
        $apellidos   = trim((string)($row[3] ?? ''));
        $estadoAp    = strtoupper(trim((string)($row[4] ?? 'EN FORMACION')));
        $compRaw     = trim((string)($row[5] ?? ''));
        $resRaw      = trim((string)($row[6] ?? ''));
        $juicioEst   = strtoupper(trim((string)($row[7] ?? 'POR EVALUAR')));
        $fechaJuicio = $row[8] ?? null;
        $funcRaw     = trim((string)($row[9] ?? ''));

        if (!$numDoc || !$compRaw || !$resRaw) continue;
        $filasLeidas++;

        // Normalizar estado aprendiz
        $estadosValidos = ['EN FORMACION', 'RETIRO VOLUNTARIO', 'TRASLADADO', 'APLAZADO'];
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
                $stmt = $pdo->prepare("SELECT id_competencia FROM competencia WHERE codigo_comp = ?");
                $stmt->execute([$codComp]);
                $cacheComp[$compRaw] = $stmt->fetchColumn();
            }
            $idComp = $cacheComp[$compRaw];

            // ── Resultado ──────────────────────────────────
            if (!isset($cacheRes[$resRaw])) {
                $partesRes = explode(' - ', $resRaw, 2);
                $codRes    = trim($partesRes[0]);
                $descRes   = trim($partesRes[1] ?? $resRaw);
                $stmt = $pdo->prepare(
                    "INSERT INTO resultado (codigo_resultado, descripcion, id_competencia) VALUES (?,?,?)
                     ON DUPLICATE KEY UPDATE descripcion=VALUES(descripcion), id_competencia=VALUES(id_competencia)"
                );
                $stmt->execute([$codRes, $descRes, $idComp]);
                $stmt = $pdo->prepare("SELECT id_resultado FROM resultado WHERE codigo_resultado = ?");
                $stmt->execute([$codRes]);
                $cacheRes[$resRaw] = $stmt->fetchColumn();
            }
            $idRes = $cacheRes[$resRaw];

            // ── Instructor ─────────────────────────────────
            $idInst = null;
            if ($funcRaw !== '' && trim($funcRaw, " -\t") !== '') {
                if (!isset($cacheInst[$funcRaw])) {
                    // Formato: "CC 12345678 - NOMBRE APELLIDO"
                    preg_match('/^(\w+)\s+(\d+)\s+-\s+(.+)$/', $funcRaw, $m);
                    $tipoI = $m[1] ?? 'CC';
                    $docI  = $m[2] ?? $funcRaw;
                    $nomI  = trim($m[3] ?? $funcRaw);
                    $stmt = $pdo->prepare(
                        "INSERT INTO instructor (tipo_documento, documento, nombre_completo) VALUES (?,?,?)
                         ON DUPLICATE KEY UPDATE nombre_completo=VALUES(nombre_completo)"
                    );
                    $stmt->execute([$tipoI, $docI, $nomI]);
                    $stmt = $pdo->prepare("SELECT id_instructor FROM instructor WHERE documento = ?");
                    $stmt->execute([$docI]);
                    $cacheInst[$funcRaw] = $stmt->fetchColumn();
                }
                $idInst = $cacheInst[$funcRaw] ?: null;
            }

            // ── Aprendiz ───────────────────────────────────
            $vistosAprendiz[$numDoc] = true;
            if (!isset($cacheApren[$numDoc])) {
                if (!isset($aprendicesBD[$numDoc])) $aprendicesNuevos++;
                $stmt = $pdo->prepare(
                    "INSERT INTO aprendiz (tipo_documento, documento, nombre, apellidos, estado, id_ficha)
                     VALUES (?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                       tipo_documento=VALUES(tipo_documento), nombre=VALUES(nombre),
                       apellidos=VALUES(apellidos), estado=VALUES(estado)"
                );
                $stmt->execute([$tipoDoc, $numDoc, $nombre, $apellidos, $estadoAp, $idFicha]);
                $stmt = $pdo->prepare("SELECT id_aprendiz FROM aprendiz WHERE documento = ? AND id_ficha = ?");
                $stmt->execute([$numDoc, $idFicha]);
                $cacheApren[$numDoc] = $stmt->fetchColumn();
            }
            $idAprendiz = $cacheApren[$numDoc];

            $fechaSQL = toSqlDate($fechaJuicio, 'Y-m-d H:i:s');

            // ── Juicio evaluativo: comparar antes de escribir ──
            $clave = $idAprendiz . '_' . $idRes;
            $vistosJuicio[$clave] = true;
            $previo = $juiciosBD[$clave] ?? null;

            $stmt = $pdo->prepare(
                "INSERT INTO juicio_evaluativo (id_aprendiz, id_resultado, id_instructor, estado, fecha, id_ficha)
                 VALUES (?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                   id_instructor=VALUES(id_instructor),
                   estado=VALUES(estado),
                   fecha=VALUES(fecha),
                   id_ficha=VALUES(id_ficha)"
            );
            $stmt->execute([$idAprendiz, $idRes, $idInst, $juicioEst, $fechaSQL, $idFicha]);

            if ($previo === null) {
                $nuevos++;
            } elseif (!sameValue($previo['estado'], $juicioEst)
                   || !sameValue($previo['fecha'], $fechaSQL)
                   || !sameValue($previo['id_instructor'], $idInst)) {
                $actualizados++;
                if (count($cambios) < 50) {
                    $cambios[] = [
                        'documento' => $numDoc,
                        'aprendiz'  => trim("$nombre $apellidos"),
                        'resultado' => $resRaw,
                        'antes'     => $previo['estado'],
                        'ahora'     => $juicioEst,
                    ];
                }

                // Un juicio aprobado que vuelve atrás es la señal de un archivo
                // desactualizado: se registra para pedir confirmación humana.
                if ($previo['estado'] === 'APROBADO' && $juicioEst !== 'APROBADO') {
                    $totalDegradadas++;
                    if (count($degradaciones) < 50) {
                        $degradaciones[] = [
                            'documento' => $numDoc,
                            'aprendiz'  => trim("$nombre $apellidos"),
                            'resultado' => $resRaw,
                            'antes'     => $previo['estado'],
                            'ahora'     => $juicioEst,
                        ];
                    }
                }
            } else {
                $sinCambios++;
            }

        } catch (Exception $e) {
            $errores[] = "Fila " . ($lineNum + $startIndex + 1) . ": " . $e->getMessage();
        }
    }

    // ── 5. Huérfanos: están en la BD pero no vinieron en el archivo ──
    // No se borra nada: solo se informa para que la decisión sea humana.
    $juiciosHuerfanos = 0;
    foreach ($juiciosBD as $clave => $j) {
        if (!isset($vistosJuicio[$clave])) $juiciosHuerfanos++;
    }

    $aprendicesHuerfanos = [];
    foreach ($aprendicesBD as $doc => $a) {
        if (!isset($vistosAprendiz[$doc])) {
            $aprendicesHuerfanos[] = [
                'documento' => $doc,
                'aprendiz'  => trim($a['nombre'] . ' ' . $a['apellidos']),
            ];
        }
    }

    // ── 6. Puerta de confirmación ──────────────────────────
    // Revertir juicios ya aprobados es el síntoma de importar un archivo
    // viejo. Se deshace todo y se devuelve el diagnóstico para que la
    // decisión la tome una persona, no el archivo.
    if ($totalDegradadas > 0 && !$confirmado) {
        $pdo->rollBack();
        jsonResponse([
            'requiere_confirmacion' => true,
            'motivo'   => 'juicios_ya_aprobados_serian_revertidos',
            'ficha'    => $codigoFicha,
            'programa' => $nombreProg,
            'resumen'  => [
                'filas_leidas'      => $filasLeidas,
                'nuevos'            => $nuevos,
                'actualizados'      => $actualizados,
                'sin_cambios'       => $sinCambios,
                'aprendices_nuevos' => $aprendicesNuevos,
                'degradaciones'     => $totalDegradadas,
            ],
            'degradaciones' => $degradaciones,
            'huerfanos'     => [
                'juicios'    => $juiciosHuerfanos,
                'aprendices' => $aprendicesHuerfanos,
            ],
        ], 409);
    }

    $pdo->commit();

    jsonResponse([
        'ok'       => true,
        'ficha'    => $codigoFicha,
        'programa' => $nombreProg,
        'resumen'  => [
            'filas_leidas'      => $filasLeidas,
            'nuevos'            => $nuevos,
            'actualizados'      => $actualizados,
            'sin_cambios'       => $sinCambios,
            'aprendices_nuevos' => $aprendicesNuevos,
            'degradaciones'     => $totalDegradadas,
        ],
        'cambios'   => $cambios,
        'huerfanos' => [
            'juicios'    => $juiciosHuerfanos,
            'aprendices' => $aprendicesHuerfanos,
        ],
        'errores'   => $errores,
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(['error' => $e->getMessage()], 500);
}
