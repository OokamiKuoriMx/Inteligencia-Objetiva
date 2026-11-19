<?php
/** Validaciones del importador, sin base de datos ni credenciales. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../bin/_importacion.php';
$ok = 0; $fallos = 0;
function comprobar(string $n, bool $c): void { global $ok, $fallos; if ($c) { $ok++; echo "  ✔ $n\n"; } else { $fallos++; echo "  ✘ $n\n"; } }
function rechazar(string $n, callable $fn): void { try { $fn(); comprobar($n, false); } catch (InvalidArgumentException $e) { comprobar($n, true); } }
$fila = ['producto_id' => 410, 'titulo' => 'Conferencia de prueba', 'vimeo_id' => '123456789', 'precio_mxn' => '579.00', 'duracion_seg' => 3600];
$regla = ['tipo' => 'ventana', 'horas_ventana' => 48];
$nuevo = io_preparar_producto($fila, $regla, null);
comprobar('precio numérico se interpreta en MXN', $nuevo['precio'] === 579.0);
comprobar('contenido faltante queda reportado', count($nuevo['avisos']) === 5);
$sinPrecio = $fila; unset($sinPrecio['precio_mxn']);
$r = io_preparar_producto($sinPrecio, $regla, null);
comprobar('sin precio no se crea una ficha comercial ficticia', $r['precio'] === null && count($r['avisos']) === 6);
foreach (['-1', 'texto', '0', null, '0.001', '100000000'] as $monto) {
    rechazar('rechaza precio inválido: ' . var_export($monto, true), fn() => io_preparar_producto(['precio_mxn' => $monto] + $fila, $regla, null));
}
rechazar('un enlace arbitrario no se transforma en ID de Vimeo', fn() => io_preparar_producto(['vimeo_id' => 'https://otro.test/123'] + $fila, $regla, null));
rechazar('no admite un ID de producto vacío', fn() => io_preparar_producto(['producto_id' => 0] + $fila, $regla, null));
rechazar('visualización única requiere duración', fn() => io_preparar_producto(['duracion_seg' => 0] + $fila, ['tipo' => 'unica'], null));
$actual = $nuevo['politica'];
$actual['tipo'] = 'unica'; $actual['dias_para_empezar'] = 7; $actual['gracia_min'] = 25; $actual['vimeo_hash'] = 'hash_existente';
$r = io_preparar_producto(['duracion_seg' => 0] + $fila, $regla, $actual);
comprobar('reimportar conserva reglas configuradas', $r['politica']['tipo'] === 'unica' && $r['politica']['dias_para_empezar'] === 7 && $r['politica']['gracia_min'] === 25);
comprobar('el mismo video conserva duración y hash si la fuente los omite', $r['politica']['duracion_seg'] === 3600 && $r['politica']['vimeo_hash'] === 'hash_existente');
$r = io_preparar_producto($fila, $regla, $actual, true);
comprobar('actualizar reglas requiere opción explícita', $r['politica']['tipo'] === 'ventana' && $r['politica']['dias_para_empezar'] === 30);
$r = io_preparar_producto(['vimeo_id' => '987654321', 'duracion_seg' => 1200] + $fila, $regla, $actual);
comprobar('un video diferente no hereda el hash anterior', $r['politica']['vimeo_hash'] === null);
echo "Resultado: $ok correctas, $fallos fallidas\n";
exit($fallos ? 1 : 0);
