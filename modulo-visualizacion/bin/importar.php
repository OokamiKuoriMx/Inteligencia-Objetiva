<?php
/**
 * Importa al módulo los productos (con su video de Vimeo) y las compras vigentes del sitio actual.
 * Se ejecuta una vez, desde la terminal del servidor:
 *
 *   php bin/importar.php productos --simular     (muestra lo que haría, no escribe)
 *   php bin/importar.php productos
 *   php bin/importar.php productos --actualizar-reglas  (reaplica reglas de categoría de forma explícita)
 *   php bin/importar.php compras --simular
 *   php bin/importar.php compras
 *
 * Antes de usarlo, ajusta las dos consultas de config.php → 'importar' para que lean las tablas reales del sitio
 * (ver config.example.php). Es idempotente: no duplica productos ni compras ya importadas.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/Tienda.php';
require_once __DIR__ . '/_importacion.php';

$que = $argv[1] ?? '';
$simular = in_array('--simular', $argv, true);
$actualizarReglas = in_array('--actualizar-reglas', $argv, true);
$cfg = IO::config()['importar'] ?? null;
if (!in_array($que, ['productos', 'compras'], true) || !$cfg) {
    fwrite(STDERR, "Uso: php bin/importar.php productos|compras [--simular] [--actualizar-reglas]\nDefine 'importar' en config.php (ver config.example.php).\n");
    exit(1);
}
$admin = ['id' => null, 'email' => 'importador@cli'];

if ($que === 'productos') {
    // Columnas esperadas: producto_id, titulo, vimeo_id, categoria  (vimeo_hash y duracion_seg opcionales)
    $filas = IO::todos($cfg['sql_productos']);
    $reglaPorCategoria = $cfg['regla_por_categoria'] ?? [];
    $n = 0; $omitidos = 0; $conAvisos = 0;
    foreach ($filas as $f) {
        $regla = $reglaPorCategoria[$f['categoria'] ?? ''] ?? ($reglaPorCategoria['*'] ?? ['tipo' => 'ventana', 'horas_ventana' => 48]);
        $actual = IO::uno('SELECT * FROM io_politicas WHERE producto_id = ?', [(int) ($f['producto_id'] ?? 0)]);
        $vimeo = trim((string) ($f['vimeo_id'] ?? ''));
        if (preg_match('/^\d+$/D', $vimeo) && empty($f['duracion_seg']) && ($info = IOVimeo::info($vimeo))) { $f['duracion_seg'] = $info['duracion_seg']; }
        try { $listo = io_preparar_producto($f, $regla, $actual, $actualizarReglas); }
        catch (InvalidArgumentException $e) { $omitidos++; echo '  · omitido #' . (int) ($f['producto_id'] ?? 0) . ': ' . $e->getMessage() . "\n"; continue; }
        $d = $listo['politica']; $dur = $d['duracion_seg'];
        $n++;
        echo "  ✓ #{$d['producto_id']} {$d['titulo']} → {$d['tipo']}" . ($dur ? ' (' . gmdate('H:i:s', $dur) . ')' : '')
            . ($listo['precio'] !== null ? ' · $' . number_format($listo['precio'], 2) . ' MXN (IVA incluido)' : '')
            . ($actual && !$actualizarReglas ? ' · conserva reglas existentes' : '') . "\n";
        if ($listo['avisos']) { $conAvisos++; echo '    Avisos: ' . implode('; ', $listo['avisos']) . ".\n"; }
        if ($simular) { continue; }
        IO::transaccion(function () use ($admin, $f, $regla, $actualizarReglas) {
            // Relee bajo bloqueo para no pisar una edición del backoffice ocurrida durante el análisis.
            $actual = IO::uno('SELECT * FROM io_politicas WHERE producto_id = ? FOR UPDATE', [(int) $f['producto_id']]);
            $listo = io_preparar_producto($f, $regla, $actual, $actualizarReglas);
            $d = $listo['politica'];
            IOAdmin::guardarPolitica($admin, $d, 'Importación de catálogo');
            // Catálogo y política se guardan o revierten juntos.
            if ($listo['precio'] !== null) {
                $plano = strtr(mb_strtolower((string) $f['titulo'], 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
                $slug = trim(substr(preg_replace('/[^a-z0-9]+/', '-', $plano), 0, 140), '-') ?: ('producto-' . (int) $f['producto_id']);
                $cat = in_array($f['categoria'] ?? '', array_keys(IOTienda::CATEGORIAS), true) ? $f['categoria'] : 'conferencias';
                IO::ejecutar('INSERT INTO io_productos (producto_id, slug, titulo, categoria, tema, resumen, descripcion, imagen, precio_mxn, precio_anterior)
                    VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE titulo = ?, categoria = ?, resumen = COALESCE(?, resumen), descripcion = COALESCE(?, descripcion), imagen = COALESCE(?, imagen), precio_mxn = ?, tema = COALESCE(?, tema)',
                    [(int) $f['producto_id'], $slug . '-' . (int) $f['producto_id'], $d['titulo'], $cat, $f['tema'] ?? null, $f['resumen'] ?? null, $f['descripcion'] ?? null,
                     $f['imagen'] ?? null, $listo['precio'], $f['precio_anterior'] ?? null,
                     $d['titulo'], $cat, $f['resumen'] ?? null, $f['descripcion'] ?? null, $f['imagen'] ?? null, $listo['precio'], $f['tema'] ?? null]);
                foreach (['aprenderas', 'trailer_vimeo', 'incluye', 'precio_anterior'] as $campo) {
                    if (array_key_exists($campo, $f)) { IO::ejecutar("UPDATE io_productos SET $campo = ? WHERE producto_id = ?", [$f[$campo], $d['producto_id']]); }
                }
            }
        });
    }
    echo ($simular ? "[SIMULACIÓN] " : '') . "$n productos listos, $omitidos omitidos, $conAvisos con contenido o precio incompletos.\n";
    exit($omitidos > 0 ? 2 : 0);
}

// compras: columnas esperadas cliente_id, email, nombre, producto_id, pedido_ref, fecha_compra
$filas = IO::todos($cfg['sql_compras']);
$n = 0; $ya = 0; $sinPolitica = 0;
foreach ($filas as $f) {
    $ref = (string) ($f['pedido_ref'] ?? '');
    $existe = IO::uno('SELECT id FROM io_accesos WHERE cliente_id = ? AND producto_id = ? AND pedido_ref <=> ?', [(int) $f['cliente_id'], (int) $f['producto_id'], $ref ?: null]);
    if ($existe) { $ya++; continue; }
    $p = IO::uno('SELECT * FROM io_politicas WHERE producto_id = ?', [(int) $f['producto_id']]);
    if (!$p) { $sinPolitica++; continue; }
    $n++;
    if ($simular) { continue; }
    IO::transaccion(function () use ($f, $p, $ref) {
        $id = IOAccesos::otorgar((int) $f['cliente_id'], (string) $f['email'], (string) ($f['nombre'] ?? ''), (int) $f['producto_id'], 'migracion', $ref ?: null);
        // Respeta la fecha original: una ventana "desde la compra" vence según la compra real.
        if ($p['tipo'] === 'ventana' && $p['cuenta_desde'] === 'compra' && !empty($f['fecha_compra'])) {
            $vence = date('Y-m-d H:i:s', strtotime((string) $f['fecha_compra']) + 3600 * (int) $p['horas_ventana']);
            IO::actualizar('io_accesos', ['inicio' => $f['fecha_compra'], 'vence' => $vence], 'id = ?', [$id]);
        }
    });
}
echo ($simular ? "[SIMULACIÓN] " : '') . "$n compras importadas, $ya ya existían, $sinPolitica sin política (importa productos primero).\n";
