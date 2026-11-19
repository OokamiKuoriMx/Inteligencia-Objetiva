<?php
/**
 * Borra miniaturas subidas que ningún contenido ni transmisión usa y que tienen más de 24 h.
 * Uso:  php bin/limpiar-miniaturas.php            (muestra qué borraría)
 *       php bin/limpiar-miniaturas.php --borrar   (las borra)
 * Sugerido en cron una vez al día.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/Miniaturas.php';

$borrar = in_array('--borrar', $argv, true);
$dir = IOMiniaturas::directorio(); $base = IOMiniaturas::urlBase();
if (!is_dir($dir)) { echo "No hay carpeta de miniaturas ($dir).\n"; exit(0); }
$usadas = [];
foreach (['SELECT imagen FROM io_productos WHERE imagen LIKE ?', 'SELECT imagen FROM io_transmisiones WHERE imagen LIKE ?'] as $sql) {
    try { foreach (IO::todos($sql, [$base . '%']) as $f) { $usadas[basename((string) $f['imagen'])] = true; } }
    catch (PDOException $e) { fwrite(STDERR, "Aviso: no se pudo leer una tabla; no se borra nada.\n"); exit(1); }
}
$n = 0; $limite = time() - 86400;
foreach (glob($dir . '/*') ?: [] as $f) {
    $nombre = basename($f);
    if (!preg_match('/^[a-f0-9]{24}\.(webp|jpg|png)$/', $nombre) || isset($usadas[$nombre]) || filemtime($f) > $limite) { continue; }
    $n++;
    echo ($borrar ? 'Borrada: ' : 'Sin uso: ') . $nombre . "\n";
    if ($borrar) { @unlink($f); }
}
echo $n ? ($borrar ? "$n miniaturas borradas.\n" : "$n miniaturas sin uso. Ejecuta con --borrar para eliminarlas.\n") : "No hay miniaturas sin uso.\n";
