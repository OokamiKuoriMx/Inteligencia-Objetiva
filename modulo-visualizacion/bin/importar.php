<?php
/**
 * Importa al módulo los productos (con su video de Vimeo) y las compras vigentes del sitio actual.
 * Se ejecuta una vez, desde la terminal del servidor:
 *
 *   php bin/importar.php productos --simular     (muestra lo que haría, no escribe)
 *   php bin/importar.php productos
 *   php bin/importar.php compras --simular
 *   php bin/importar.php compras
 *
 * Antes de usarlo, ajusta las dos consultas de config.php → 'importar' para que lean las tablas reales del sitio
 * (ver config.example.php). Es idempotente: no duplica productos ni compras ya importadas.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/IO.php';

$que = $argv[1] ?? '';
$simular = in_array('--simular', $argv, true);
$cfg = IO::config()['importar'] ?? null;
if (!in_array($que, ['productos', 'compras'], true) || !$cfg) {
    fwrite(STDERR, "Uso: php bin/importar.php productos|compras [--simular]\nDefine 'importar' en config.php (ver config.example.php).\n");
    exit(1);
}
$admin = ['id' => null, 'email' => 'importador@cli'];

if ($que === 'productos') {
    // Columnas esperadas: producto_id, titulo, vimeo_id, categoria  (vimeo_hash y duracion_seg opcionales)
    $filas = IO::todos($cfg['sql_productos']);
    $reglaPorCategoria = $cfg['regla_por_categoria'] ?? [];
    $n = 0; $omitidos = 0;
    foreach ($filas as $f) {
        $vimeo = preg_replace('/\D/', '', (string) ($f['vimeo_id'] ?? ''));
        if ($vimeo === '') { $omitidos++; echo "  · sin Vimeo: #{$f['producto_id']} {$f['titulo']}\n"; continue; }
        $regla = $reglaPorCategoria[$f['categoria'] ?? ''] ?? ($reglaPorCategoria['*'] ?? ['tipo' => 'ventana', 'horas_ventana' => 48]);
        $dur = (int) ($f['duracion_seg'] ?? 0);
        if (!$dur && ($info = IOVimeo::info($vimeo))) { $dur = $info['duracion_seg']; }
        if (($regla['tipo'] ?? '') === 'unica' && !$dur) { $omitidos++; echo "  · única sin duración (configura vimeo_token): #{$f['producto_id']}\n"; continue; }
        $d = [
            'producto_id' => (int) $f['producto_id'], 'titulo' => mb_substr((string) $f['titulo'], 0, 255), 'vimeo_id' => $vimeo,
            'vimeo_hash' => $f['vimeo_hash'] ?? null, 'duracion_seg' => $dur,
            'tipo' => $regla['tipo'] ?? 'ventana', 'horas_ventana' => (int) ($regla['horas_ventana'] ?? 48),
            'cuenta_desde' => $regla['cuenta_desde'] ?? 'primera_reproduccion', 'dias_para_empezar' => (int) ($regla['dias_para_empezar'] ?? 30),
            'umbral_pct' => 95, 'retroceso_seg' => 30, 'permitir_adelantar' => 0, 'pausa_max_min' => 60, 'reanudaciones_max' => 2, 'gracia_min' => 120,
        ];
        $n++;
        echo "  ✓ #{$d['producto_id']} {$d['titulo']} → {$d['tipo']}" . ($dur ? ' (' . gmdate('H:i:s', $dur) . ')' : '') . "\n";
        if (!$simular) { IOAdmin::guardarPolitica($admin, $d, 'Migración inicial'); }
    }
    echo ($simular ? "[SIMULACIÓN] " : '') . "$n productos listos, $omitidos omitidos.\n";
    exit(0);
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
