<?php
/** Importación real contra una base desechable y exclusiva en MySQL de pruebas (3307 por defecto). */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/IO.php';
$pdo = new PDO(getenv('IO_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3307;charset=utf8mb4', getenv('IO_TEST_USER') ?: 'root', getenv('IO_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$base = 'io_prueba_importacion_' . bin2hex(random_bytes(5));
$pdo->exec("CREATE DATABASE `$base` CHARACTER SET utf8mb4");
$ok = 0; $fallos = 0;
function comprobarImportacion(string $n, bool $c): void { global $ok, $fallos; if ($c) { $ok++; echo "  ✔ $n\n"; } else { $fallos++; echo "  ✘ $n\n"; } }
function importarPrueba(string $base, array $flags = []): array
{
    $cfg = ['vimeo_token' => '', 'importar' => ['sql_productos' => 'SELECT * FROM io_fuente', 'regla_por_categoria' => ['*' => ['tipo' => 'ventana', 'horas_ventana' => 48]]]];
    $codigo = 'require ' . var_export(__DIR__ . '/../lib/IO.php', true) . ';'
        . '$pdo = new PDO(getenv("IO_TEST_DSN") ?: "mysql:host=127.0.0.1;port=3307;charset=utf8mb4", getenv("IO_TEST_USER") ?: "root", getenv("IO_TEST_PASS") ?: "", [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);'
        . '$pdo->exec(' . var_export("USE `$base`", true) . '); IO::usarConexion($pdo, ' . var_export($cfg, true) . ');'
        . '$argv = ' . var_export(array_merge(['importar.php', 'productos'], $flags), true) . '; require ' . var_export(__DIR__ . '/../bin/importar.php', true) . ';';
    $p = proc_open([PHP_BINARY, '-r', $codigo], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { throw new RuntimeException('No se pudo iniciar el importador de prueba.'); }
    fclose($pipes[0]);
    $salida = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($p), $salida, $error];
}
try {
    $pdo->exec("USE `$base`");
    foreach (glob(__DIR__ . '/../sql/*.sql') as $archivo) {
        foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents($archivo))))) as $sql) { $pdo->exec($sql); }
    }
    $pdo->exec('CREATE TABLE io_fuente (producto_id INT, titulo VARCHAR(255), vimeo_id VARCHAR(32), categoria VARCHAR(30), duracion_seg INT, precio_mxn DECIMAL(10,2), aprenderas TEXT, trailer_vimeo VARCHAR(32), tema VARCHAR(60))');
    $pdo->exec("INSERT INTO io_fuente VALUES (410, 'Título importado', '123456789', 'conferencias', 1200, 579.00, 'Objetivo real', '987654321', 'pareja')");
    [$codigo, $salida] = importarPrueba($base, ['--simular']);
    comprobarImportacion('simulación informa precio con IVA y campos incompletos', $codigo === 0 && strpos($salida, '579.00 MXN (IVA incluido)') !== false && strpos($salida, 'falta resumen') !== false);
    comprobarImportacion('simulación no escribe productos ni políticas', (int) $pdo->query('SELECT COUNT(*) FROM io_politicas')->fetchColumn() === 0 && (int) $pdo->query('SELECT COUNT(*) FROM io_productos')->fetchColumn() === 0);
    [$codigo] = importarPrueba($base);
    $p = $pdo->query('SELECT * FROM io_productos WHERE producto_id = 410')->fetch();
    comprobarImportacion('importa el producto, contenido y tema', $codigo === 0 && $p && $p['aprenderas'] === 'Objetivo real' && $p['trailer_vimeo'] === '987654321' && $p['tema'] === 'pareja');
    $pdo->exec("UPDATE io_politicas SET tipo = 'unica', dias_para_empezar = 7 WHERE producto_id = 410");
    $pdo->exec("UPDATE io_productos SET resumen = 'Contenido editorial', precio_anterior = 700 WHERE producto_id = 410");
    [$codigo] = importarPrueba($base);
    $pol = $pdo->query('SELECT * FROM io_politicas WHERE producto_id = 410')->fetch();
    $p = $pdo->query('SELECT * FROM io_productos WHERE producto_id = 410')->fetch();
    comprobarImportacion('reimportación conserva la política comercial decidida', $codigo === 0 && $pol['tipo'] === 'unica' && (int) $pol['dias_para_empezar'] === 7);
    comprobarImportacion('reimportación conserva contenidos y precio anterior omitidos en la fuente', $p['resumen'] === 'Contenido editorial' && (float) $p['precio_anterior'] === 700.0);
    comprobarImportacion('reimportación no duplica el catálogo', (int) $pdo->query('SELECT COUNT(*) FROM io_productos')->fetchColumn() === 1);
    [$codigo] = importarPrueba($base, ['--actualizar-reglas']);
    comprobarImportacion('opción explícita aplica la regla de categoría', $codigo === 0 && $pdo->query('SELECT tipo FROM io_politicas WHERE producto_id = 410')->fetchColumn() === 'ventana');
    $pdo->exec("UPDATE io_fuente SET titulo = 'No debe persistir'");
    $pdo->exec("CREATE TRIGGER io_fallo_importacion BEFORE UPDATE ON io_productos FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fallo intencional de prueba'");
    [$codigo] = importarPrueba($base);
    comprobarImportacion('si falla el catálogo se revierte también la política', $codigo !== 0 && $pdo->query('SELECT titulo FROM io_politicas WHERE producto_id = 410')->fetchColumn() === 'Título importado');
    $pdo->exec('DROP TRIGGER io_fallo_importacion');
    $pdo->exec('UPDATE io_fuente SET precio_mxn = -10');
    [$codigo] = importarPrueba($base, ['--simular']);
    comprobarImportacion('filas inválidas devuelven código 2 para impedir dar la migración por completa', $codigo === 2);
} finally { $pdo->exec("DROP DATABASE `$base`"); }
echo "Resultado: $ok correctas, $fallos fallidas\n";
exit($fallos ? 1 : 0);
