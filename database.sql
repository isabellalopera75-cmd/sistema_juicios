-- ============================================================
--  SISTEMA DE GESTIÓN DE JUICIOS EVALUATIVOS
--  Base de datos para XAMPP / MySQL
--  Ejecutar en phpMyAdmin o consola MySQL
-- ============================================================

CREATE DATABASE IF NOT EXISTS juicios_evaluativos
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE juicios_evaluativos;

-- ------------------------------------------------------------
-- TABLAS SECUNDARIAS (catálogos)
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS programa (
  id_programa   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo_prog   VARCHAR(20)  NOT NULL,
  version       TINYINT      NOT NULL DEFAULT 1,
  nombre        VARCHAR(300) NOT NULL,
  UNIQUE KEY uq_prog (codigo_prog, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ficha (
  id_ficha        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo_ficha    VARCHAR(20)  NOT NULL UNIQUE,
  id_programa     INT UNSIGNED NOT NULL,
  estado_ficha    VARCHAR(50)  NOT NULL DEFAULT 'EN EJECUCION',
  modalidad       VARCHAR(50),
  regional        VARCHAR(100),
  centro          VARCHAR(200),
  fecha_inicio    DATE,
  fecha_fin       DATE,
  FOREIGN KEY (id_programa) REFERENCES programa(id_programa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS competencia (
  id_competencia  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo_comp     VARCHAR(20)  NOT NULL,
  nombre          VARCHAR(500) NOT NULL,
  UNIQUE KEY uq_comp (codigo_comp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS resultado (
  id_resultado    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo_resultado VARCHAR(20) NOT NULL,
  descripcion     TEXT        NOT NULL,
  id_competencia  INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_res (codigo_resultado),
  FOREIGN KEY (id_competencia) REFERENCES competencia(id_competencia)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS instructor (
  id_instructor   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tipo_documento  VARCHAR(10)  NOT NULL DEFAULT 'CC',
  documento       VARCHAR(20)  NOT NULL,
  nombre_completo VARCHAR(200) NOT NULL,
  UNIQUE KEY uq_inst (documento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- TABLAS PRINCIPALES
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS aprendiz (
  id_aprendiz     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tipo_documento  VARCHAR(10)  NOT NULL DEFAULT 'CC',
  documento       VARCHAR(20)  NOT NULL,
  nombre          VARCHAR(150) NOT NULL,
  apellidos       VARCHAR(150) NOT NULL,
  estado          ENUM('EN FORMACION','RETIRO VOLUNTARIO','TRASLADADO','APLAZADO') NOT NULL DEFAULT 'EN FORMACION',
  id_ficha        INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_aprendiz (documento, id_ficha),
  FOREIGN KEY (id_ficha) REFERENCES ficha(id_ficha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS juicio_evaluativo (
  id_juicio       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_aprendiz     INT UNSIGNED NOT NULL,
  id_resultado    INT UNSIGNED NOT NULL,
  id_instructor   INT UNSIGNED,
  estado          ENUM('APROBADO','POR EVALUAR') NOT NULL DEFAULT 'POR EVALUAR',
  fecha           DATETIME,
  id_ficha        INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_juicio (id_aprendiz, id_resultado),
  FOREIGN KEY (id_aprendiz)  REFERENCES aprendiz(id_aprendiz),
  FOREIGN KEY (id_resultado) REFERENCES resultado(id_resultado),
  FOREIGN KEY (id_instructor) REFERENCES instructor(id_instructor),
  FOREIGN KEY (id_ficha) REFERENCES ficha(id_ficha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- VISTAS útiles para consultas rápidas
-- ------------------------------------------------------------

CREATE OR REPLACE VIEW v_juicios_completo AS
SELECT
  j.id_juicio,
  f.codigo_ficha,
  p.nombre          AS programa,
  a.tipo_documento,
  a.documento,
  CONCAT(a.nombre,' ',a.apellidos) AS aprendiz_completo,
  a.nombre          AS nombre_aprendiz,
  a.apellidos,
  a.estado          AS estado_aprendiz,
  c.codigo_comp,
  c.nombre          AS competencia,
  r.codigo_resultado,
  r.descripcion     AS resultado,
  j.estado          AS juicio,
  j.fecha,
  COALESCE(i.nombre_completo, '—') AS instructor
FROM juicio_evaluativo j
JOIN aprendiz  a ON j.id_aprendiz   = a.id_aprendiz
JOIN ficha     f ON j.id_ficha      = f.id_ficha
JOIN programa  p ON f.id_programa   = p.id_programa
JOIN resultado r ON j.id_resultado  = r.id_resultado
JOIN competencia c ON r.id_competencia = c.id_competencia
LEFT JOIN instructor i ON j.id_instructor = i.id_instructor;

CREATE OR REPLACE VIEW v_avance_aprendiz AS
SELECT
  a.id_aprendiz,
  f.codigo_ficha,
  a.documento,
  CONCAT(a.nombre,' ',a.apellidos) AS aprendiz,
  a.estado AS estado_aprendiz,
  COUNT(j.id_juicio) AS total_resultados,
  SUM(j.estado = 'APROBADO') AS aprobados,
  SUM(j.estado = 'POR EVALUAR') AS pendientes,
  ROUND(SUM(j.estado = 'APROBADO') * 100.0 / COUNT(j.id_juicio), 1) AS avance_pct
FROM aprendiz a
JOIN ficha f ON a.id_ficha = f.id_ficha
LEFT JOIN juicio_evaluativo j ON a.id_aprendiz = j.id_aprendiz
GROUP BY a.id_aprendiz, f.codigo_ficha, a.documento, a.nombre, a.apellidos, a.estado;

CREATE OR REPLACE VIEW v_avance_competencia AS
SELECT
  f.codigo_ficha,
  c.id_competencia,
  c.nombre AS competencia,
  COUNT(j.id_juicio) AS total,
  SUM(j.estado = 'APROBADO') AS aprobados,
  ROUND(SUM(j.estado = 'APROBADO') * 100.0 / COUNT(j.id_juicio), 1) AS pct_aprobacion
FROM juicio_evaluativo j
JOIN resultado r    ON j.id_resultado  = r.id_resultado
JOIN competencia c  ON r.id_competencia = c.id_competencia
JOIN ficha f        ON j.id_ficha = f.id_ficha
GROUP BY f.codigo_ficha, c.id_competencia, c.nombre;

-- ------------------------------------------------------------
-- TABLAS Y VISTAS PARA MÓDULO DE FASES DEL PROYECTO FORMATIVO
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS fase_proyecto (
  id_fase       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_ficha      INT UNSIGNED NOT NULL,
  nombre        VARCHAR(200) NOT NULL,
  descripcion   TEXT,
  orden         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  fecha_inicio  DATE,
  fecha_fin     DATE,
  FOREIGN KEY (id_ficha) REFERENCES ficha(id_ficha) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fase_competencia (
  id_fc           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  id_fase         INT UNSIGNED NOT NULL,
  id_competencia  INT UNSIGNED NOT NULL,
  id_resultado    INT UNSIGNED,
  UNIQUE KEY uq_fase_resultado (id_fase, id_resultado),
  FOREIGN KEY (id_fase)        REFERENCES fase_proyecto(id_fase)     ON DELETE CASCADE,
  FOREIGN KEY (id_competencia) REFERENCES competencia(id_competencia),
  FOREIGN KEY (id_resultado)   REFERENCES resultado(id_resultado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE OR REPLACE VIEW v_avance_fase AS
SELECT
  fp.id_fase,
  fp.id_ficha,
  fi.codigo_ficha,
  fp.nombre        AS fase,
  fp.orden,
  fp.fecha_inicio,
  fp.fecha_fin,
  COUNT(DISTINCT fc.id_resultado)                          AS total_resultados,
  COUNT(DISTINCT CASE WHEN j.estado='APROBADO' THEN j.id_aprendiz*10000+j.id_resultado END) AS aprobaciones,
  COUNT(DISTINCT j.id_aprendiz*10000+j.id_resultado)       AS total_posibles,
  ROUND(
    COUNT(DISTINCT CASE WHEN j.estado='APROBADO' THEN j.id_aprendiz*10000+j.id_resultado END)
    * 100.0
    / NULLIF(COUNT(DISTINCT j.id_aprendiz*10000+j.id_resultado), 0)
  , 1) AS pct_cumplimiento,
  COUNT(DISTINCT CASE WHEN j.estado='APROBADO'
        THEN j.id_aprendiz END)                            AS aprendices_aprobados,
  COUNT(DISTINCT j.id_aprendiz)                            AS total_aprendices
FROM fase_proyecto fp
JOIN ficha fi         ON fp.id_ficha         = fi.id_ficha
LEFT JOIN fase_competencia fc ON fp.id_fase       = fc.id_fase
LEFT JOIN juicio_evaluativo j ON fc.id_resultado = j.id_resultado
                              AND j.id_ficha    = fp.id_ficha
GROUP BY fp.id_fase, fp.id_ficha, fi.codigo_ficha, fp.nombre, fp.orden, fp.fecha_inicio, fp.fecha_fin;
