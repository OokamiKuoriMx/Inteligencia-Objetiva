<?php
/** Carga CSV en base desechable: preview solo lectura, confirmación y rollback completos. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/CargaContenidos.php';
$pdo = new PDO(getenv('IO_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3307;charset=utf8mb4', getenv('IO_TEST_USER') ?: 'root', getenv('IO_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$bd = 'io_prueba_carga_' . bin2hex(random_bytes(5));
$pdo->exec("CREATE DATABASE `$bd` CHARACTER SET utf8mb4"); $pdo->exec("USE `$bd`");
date_default_timezone_set('America/Mexico_City');
IO::usarConexion($pdo, ['zona_horaria' => 'America/Mexico_City', 'vimeo_token' => '']);
$ok = 0; $fallos = 0;
function verificarCarga(string $nombre, bool $resultado): void { global $ok, $fallos; if ($resultado) { $ok++; echo "  ✔ $nombre\n"; } else { $fallos++; echo "  ✘ $nombre\n"; } }
function errorCarga(string $nombre, string $codigo, callable $fn, int $http = 0): void { try { $fn(); verificarCarga($nombre, false); } catch (IOError $e) { verificarCarga($nombre, $e->codigo === $codigo && (!$http || $e->getCode() === $http)); } }
function csvCarga(array $columnas, array $filas, string $separador = ';', bool $bom = true): string
{
    $f = fopen('php://memory', 'w+'); if ($bom) { fwrite($f, "\xEF\xBB\xBF"); }
    fputcsv($f, $columnas, $separador, '"', '');
    foreach ($filas as $fila) { fputcsv($f, $fila, $separador, '"', ''); }
    rewind($f); $texto = stream_get_contents($f); fclose($f); return $texto;
}
function numeroCarga(int $id): int { return (int) IO::uno('SELECT COUNT(*) n FROM io_productos WHERE producto_id = ?', [$id])['n']; }
try {
    foreach (glob(__DIR__ . '/../sql/*.sql') as $archivo) {
        foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents($archivo))))) as $sql) { $pdo->exec($sql); }
    }
    foreach ([1 => 'admin', 2 => 'editor', 3 => 'soporte'] as $id => $rol) {
        IO::ejecutar('INSERT INTO io_admins (id,email,nombre,hash,rol) VALUES (?,?,?,?,?)', [$id, $rol . '@ejemplo.test', $rol, 'hash-ficticio', $rol]);
    }
    $admin = ['id' => 1]; $editor = ['id' => 2];
    $plantilla = IOCargaContenidos::plantilla();
    verificarCarga('la plantilla lleva BOM UTF-8 para Excel', substr($plantilla, 0, 3) === "\xEF\xBB\xBF");
    verificarCarga('solo incluye encabezados y ninguna fila de ejemplo importable', count(array_filter(explode("\n", $plantilla), static fn($l) => trim($l) !== '')) === 1);
    verificarCarga('incluye las columnas del editor sin slug ni versión', strpos($plantilla, 'producto_id;titulo;categoria') !== false && strpos($plantilla, 'vimeo_hash') !== false && strpos($plantilla, 'version') === false);
    verificarCarga('también permite descargar encabezados separados por comas', strpos(IOCargaContenidos::plantilla(','), 'producto_id,titulo,categoria') !== false);
    errorCarga('una plantilla vacía no crea contenido por accidente', 'csv_vacio', fn() => IOCargaContenidos::previsualizar($admin, $plantilla));
    $columnas = ['producto_id', 'titulo', 'categoria', 'resumen', 'descripcion', 'aprenderas', 'imagen', 'precio_mxn', 'vimeo_id', 'tipo', 'duracion_seg', 'activo'];
    $filas = [[10, '¿Qué es "pensar"?', 'conferencias', 'Resumen con acentos', "Una descripción.\nDos líneas.", "Primera idea\nSegunda idea", '/portada/img/conferencias.webp', '579.01', '12345', 'ventana', '1200', '0'],
        [11, 'Segundo contenido', 'cursos', '', '', '', '', '', '', '', '', '']];
    $csv = csvCarga($columnas, $filas); $lote = IOCargaContenidos::previsualizar($editor, $csv);
    verificarCarga('detecta punto y coma y admite comillas y saltos de línea en celdas', $lote['separador'] === ';' && $lote['total'] === 2 && !$lote['errores'] && $lote['filas'][0]['datos']['titulo'] === '¿Qué es "pensar"?');
    verificarCarga('preserva los centavos y el texto UTF-8 sin fórmulas', $lote['filas'][0]['datos']['precio_mxn'] === '579.01' && $lote['filas'][0]['datos']['resumen'] === 'Resumen con acentos');
    verificarCarga('las celdas vacías opcionales usan valores de borrador coherentes', $lote['filas'][1]['datos']['tipo'] === 'ventana' && $lote['filas'][1]['datos']['horas_ventana'] === 48 && $lote['filas'][1]['datos']['precio_mxn'] === '0.00');
    verificarCarga('preview no escribe productos, políticas ni bitácora', (int) IO::uno('SELECT COUNT(*) n FROM io_productos')['n'] === 0 && (int) IO::uno('SELECT COUNT(*) n FROM io_politicas')['n'] === 0 && (int) IO::uno('SELECT COUNT(*) n FROM io_bitacora')['n'] === 0);
    $coma = IOCargaContenidos::previsualizar($admin, csvCarga(['producto_id', 'titulo'], [[12, 'Título, con coma']], ',', false));
    verificarCarga('detecta comas sin confundir una coma dentro del título', $coma['separador'] === ',' && !$coma['errores'] && $coma['filas'][0]['datos']['titulo'] === 'Título, con coma');
    errorCarga('rechaza columnas de reemplazo o versiones enviadas en CSV', 'csv_cabecera', fn() => IOCargaContenidos::previsualizar($admin, csvCarga(['producto_id', 'titulo', 'version'], [[12, 'No reemplazar', 'abc']])));
    errorCarga('rechaza encabezados duplicados', 'csv_cabecera', fn() => IOCargaContenidos::previsualizar($admin, csvCarga(['producto_id', 'titulo', 'titulo'], [[12, 'Uno', 'Dos']])));
    errorCarga('rechaza archivos con codificación UTF-16', 'csv_codificacion', fn() => IOCargaContenidos::previsualizar($admin, "\xFF\xFEp\0r\0o\0d\0u\0c\0t\0o\0"));
    errorCarga('limita el tamaño del archivo antes de analizarlo', 'csv_tamano', fn() => IOCargaContenidos::previsualizar($admin, str_repeat('a', IOCargaContenidos::MAX_BYTES + 1)));
    $muchas = []; for ($i = 0; $i <= IOCargaContenidos::MAX_FILAS; $i++) { $muchas[] = [1000 + $i, 'Contenido ' . $i]; }
    errorCarga('limita a 200 contenidos por operación', 'csv_filas', fn() => IOCargaContenidos::previsualizar($admin, csvCarga(['producto_id', 'titulo'], $muchas)));
    errorCarga('no acepta celdas entre comillas sin cerrar', 'csv_comillas', fn() => IOCargaContenidos::previsualizar($admin, "producto_id;titulo\n12;\"Sin cierre\n"));
    $dup = IOCargaContenidos::previsualizar($admin, csvCarga(['producto_id', 'titulo'], [[12, 'Uno'], [12, 'Dos']]));
    verificarCarga('los IDs duplicados se muestran como errores antes de guardar', count($dup['errores']) === 1 && $dup['errores'][0]['codigo'] === 'csv_duplicado');
    errorCarga('ninguna fila se guarda si la vista previa contiene errores', 'csv_invalido', fn() => IOCargaContenidos::confirmar($admin, $dup, 'Carga inválida'));
    foreach (['=HYPERLINK("https://externo.test")', '+SUM(1,2)', '-2+3', '@SUM(1,2)'] as $formula) {
        $r = IOCargaContenidos::previsualizar($admin, csvCarga(['producto_id', 'titulo'], [[12, $formula]]));
        verificarCarga('rechaza fórmulas o prefijo de fórmula ' . $formula[0], count($r['errores']) === 1 && $r['errores'][0]['codigo'] === 'csv_formula');
    }
    $r = IOCargaContenidos::previsualizar($admin, csvCarga(['producto_id', 'titulo', 'descripcion'], [[12, 'Título', '<script>alert(1)</script>']]));
    verificarCarga('la vista previa reutiliza el rechazo de HTML del editor', $r['errores'][0]['codigo'] === 'datos');
    $r = IOCargaContenidos::previsualizar($admin, csvCarga(['producto_id', 'titulo', 'imagen'], [[12, 'Título', 'javascript:alert(1)']]));
    verificarCarga('la vista previa reutiliza la validación de URLs del editor', $r['errores'][0]['codigo'] === 'imagen');
    $r = IOCargaContenidos::previsualizar($admin, csvCarga(['producto_id', 'titulo', 'precio_mxn'], [[12, 'Título', '10,50']]));
    verificarCarga('un decimal con coma se informa como error de precio', $r['errores'][0]['codigo'] === 'precio');
    $r = IOCargaContenidos::previsualizar($admin, csvCarga(['producto_id', 'titulo', 'activo'], [[12, 'Título', '1']]));
    verificarCarga('la carga no publica productos por accidente', $r['errores'][0]['codigo'] === 'csv_publicacion');
    $r = IOCargaContenidos::previsualizar($admin, "producto_id;titulo\n12;Título;Extra\n");
    verificarCarga('detecta filas con distinto número de columnas', $r['errores'][0]['codigo'] === 'csv_columnas');
    $alterado = $lote; $alterado['filas'][0]['datos']['titulo'] = 'Modificado después de revisar';
    errorCarga('no confirma datos diferentes de la vista previa validada', 'csv_modificado', fn() => IOCargaContenidos::confirmar($editor, $alterado, 'Carga'), 409);
    $vencido = $lote; $vencido['creado_en'] = time() - IOCargaContenidos::VIGENCIA_SEGUNDOS - 1;
    errorCarga('una vista previa caducada exige revisión nueva', 'csv_caducado', fn() => IOCargaContenidos::confirmar($editor, $vencido, 'Carga'), 409);
    errorCarga('la vista previa no puede confirmarse desde otra cuenta', 'csv_caducado', fn() => IOCargaContenidos::confirmar($admin, $lote, 'Carga'), 409);
    errorCarga('el motivo del lote es obligatorio', 'motivo', fn() => IOCargaContenidos::confirmar($editor, $lote, ''));
    errorCarga('el rol se revalida al previsualizar', 'sin_permiso', fn() => IOCargaContenidos::previsualizar(['id' => 3, 'rol' => 'admin'], $csv), 403);
    $ids = IOCargaContenidos::confirmar($editor, $lote, 'Carga inicial de contenidos');
    verificarCarga('confirmar guarda exactamente todos los nuevos contenidos', $ids === [10, 11] && numeroCarga(10) === 1 && numeroCarga(11) === 1);
    verificarCarga('todos los registros confirmados permanecen ocultos como borradores', (int) IO::uno('SELECT COUNT(*) n FROM io_productos WHERE activo<>0')['n'] === 0);
    verificarCarga('la confirmación guarda política y comercial en la misma operación', (int) IO::uno('SELECT COUNT(*) n FROM io_politicas')['n'] === 2 && IOContenidos::obtener(10)['titulo_comercial'] === '¿Qué es "pensar"?');
    verificarCarga('registra auditorías por contenido y resumen del lote', (int) IO::uno('SELECT COUNT(*) n FROM io_bitacora')['n'] === 5 && (int) IO::uno('SELECT COUNT(*) n FROM io_bitacora WHERE accion="carga_contenidos"')['n'] === 1);
    errorCarga('repetir una confirmación no reemplaza ni duplica contenidos', 'conflicto', fn() => IOCargaContenidos::confirmar($editor, $lote, 'Reintento'), 409);
    $r = IOCargaContenidos::previsualizar($admin, csvCarga(['producto_id', 'titulo'], [[10, 'No debe reemplazar']]));
    verificarCarga('un ID existente se señala sin ofrecer sobrescritura', $r['errores'][0]['codigo'] === 'csv_existente' && IOContenidos::obtener(10)['titulo'] === '¿Qué es "pensar"?');
    $concurrente = IOCargaContenidos::previsualizar($admin, csvCarga(['producto_id', 'titulo'], [[30, 'Primer borrador'], [31, 'Segundo borrador']]));
    IOContenidos::guardar($admin, array_replace(IOContenidos::nuevo(), ['producto_id' => 31, 'titulo' => 'Creado desde otro editor']), 'Cambio concurrente');
    $antes = (int) IO::uno('SELECT COUNT(*) n FROM io_bitacora')['n'];
    errorCarga('un ID creado durante la revisión cancela la operación completa', 'conflicto', fn() => IOCargaContenidos::confirmar($admin, $concurrente, 'Confirmar lote viejo'), 409);
    verificarCarga('la colisión no deja el primer contenido parcialmente guardado', numeroCarga(30) === 0 && IOContenidos::obtener(31)['titulo'] === 'Creado desde otro editor' && (int) IO::uno('SELECT COUNT(*) n FROM io_bitacora')['n'] === $antes);
    $reversion = IOCargaContenidos::previsualizar($admin, csvCarga(['producto_id', 'titulo'], [[40, 'Primero'], [41, 'Segundo']]));
    $pdo->exec("CREATE TRIGGER io_carga_fallo BEFORE INSERT ON io_bitacora FOR EACH ROW BEGIN IF NEW.accion='carga_contenidos' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fallo intencional de prueba'; END IF; END");
    $fallo = false;
    try { IOCargaContenidos::confirmar($admin, $reversion, 'Probar atomicidad'); } catch (PDOException $e) { $fallo = true; }
    $pdo->exec('DROP TRIGGER io_carga_fallo');
    verificarCarga('si falla el resumen de bitácora se revierten todos los contenidos', $fallo && numeroCarga(40) === 0 && numeroCarga(41) === 0 && (int) IO::uno('SELECT COUNT(*) n FROM io_bitacora')['n'] === $antes);
    IO::ejecutar('UPDATE io_admins SET activo=0 WHERE id=2');
    errorCarga('desactivar al editor impide confirmar una vista previa anterior', 'sin_permiso', fn() => IOCargaContenidos::confirmar($editor, $lote, 'Editor desactivado'), 403);
} finally { if ($pdo->inTransaction()) { $pdo->rollBack(); } $pdo->exec("DROP DATABASE `$bd`"); }
echo "Resultado: $ok correctas, $fallos fallidas\n";
exit($fallos ? 1 : 0);
