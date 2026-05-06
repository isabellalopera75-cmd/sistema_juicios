# Sistema de Juicios Evaluativos — Guía de Instalación para XAMPP

## Requisitos
- XAMPP con PHP 8.0+ y MySQL 5.7+
- Composer (https://getcomposer.org)

---

## Paso 1 — Copiar el proyecto a XAMPP

Copia la carpeta `sistema_juicios/` dentro de:

```
C:\xampp\htdocs\sistema_juicios\
```

---

## Paso 2 — Crear la base de datos

1. Abre XAMPP Control Panel → inicia **Apache** y **MySQL**.
2. Abre tu navegador y ve a: `http://localhost/phpmyadmin`
3. Haz clic en **"Nueva"** (panel izquierdo) o ve a la pestaña **SQL**.
4. Copia el contenido de `database.sql` y ejecútalo.
   - Esto crea la base de datos `juicios_evaluativos` con todas las tablas y vistas.

---

## Paso 3 — Instalar PhpSpreadsheet (para importar XLS)

Abre una terminal (CMD o PowerShell) y navega a la carpeta del proyecto:

```bash
cd C:\xampp\htdocs\sistema_juicios
composer require phpoffice/phpspreadsheet
```

Esto crea la carpeta `vendor/` con la librería necesaria para leer archivos .xls y .xlsx.

> Si no tienes Composer instalado: https://getcomposer.org/download/

---

## Paso 4 — Verificar la configuración de BD

Abre `includes/config.php` y confirma que los datos coincidan con tu XAMPP:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');   // usuario por defecto en XAMPP
define('DB_PASS', '');       // contraseña vacía por defecto en XAMPP
define('DB_NAME', 'juicios_evaluativos');
```

---

## Paso 5 — Abrir el sistema

Ve a: `http://localhost/sistema_juicios/`

---

## Paso 6 — Importar el reporte XLS

1. En el sistema, haz clic en la pestaña **"Importar XLS"**.
2. Arrastra o selecciona el archivo `Reporte_de_Juicios_Evaluativos-ADSO.xls`.
3. Haz clic en **"Importar archivo"**.
4. El sistema procesará todos los datos automáticamente.

---

## Estructura de archivos

```
sistema_juicios/
├── index.html          ← Interfaz principal (Dashboard + Filtros + Importar)
├── api_data.php        ← API de consultas con todos los filtros
├── api_import.php      ← API de importación de archivos XLS/XLSX
├── database.sql        ← Script SQL para crear la base de datos
├── includes/
│   └── config.php      ← Configuración de conexión a MySQL
├── vendor/             ← (se crea con composer) PhpSpreadsheet
└── README.md           ← Este archivo
```

---

## Funcionalidades del sistema

### Dashboard
- KPIs: total aprendices, estados (formación / retirados / trasladados)
- KPIs de juicios: aprobados, pendientes, % avance global
- Gráfica de distribución de estados
- Gráfica aprobados vs pendientes
- Barras de % aprobación por competencia

### Aprendices
- Filtrar por: nombre, apellido, documento, estado
- Ver avance individual con barra de progreso
- Ver detalle completo de juicios por aprendiz (modal)
- Exportar a CSV

### Tabla de Juicios
- Filtrar por: nombre/documento, estado aprendiz, estado juicio, competencia
- Paginación de 50 registros por página
- Ver instructor y fecha de cada juicio
- Exportar a CSV

### Importar
- Acepta archivos .xls y .xlsx de Sofia Plus
- Detecta automáticamente ficha, programa, aprendices, competencias, resultados e instructores
- Inserta o actualiza registros sin duplicar
- Reporta resultados y errores de la importación

---

## Solución de problemas

| Problema | Solución |
|---|---|
| Error "vendor/autoload.php not found" | Ejecuta `composer require phpoffice/phpspreadsheet` en la carpeta del proyecto |
| Error de conexión a BD | Verifica que MySQL esté corriendo en XAMPP y los datos en `config.php` |
| El archivo no se importa | Verifica que la carpeta `upload/` tenga permisos de escritura |
| Las gráficas no aparecen | Verifica que tengas conexión a internet (Chart.js se carga desde CDN) |
