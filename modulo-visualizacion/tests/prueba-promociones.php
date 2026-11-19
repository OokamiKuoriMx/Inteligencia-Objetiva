<?php
/** Pruebas puras de vigencia y descuentos; no requieren base de datos ni configuración privada. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/Promociones.php';
$ok = 0; $fallos = 0;
function comprobar(string $nombre, bool $valor): void {
    global $ok, $fallos;
    if ($valor) { $ok++; echo "  ✔ $nombre\n"; }
    else { $fallos++; echo "  ✘ $nombre\n"; }
}
$inicio = '2030-06-01T00:00:00-06:00'; $fin = '2030-06-03T23:59:00-06:00';
$ahora = (new DateTimeImmutable('2030-06-02T12:00:00-06:00'))->getTimestamp();
$config = ['inicio' => $inicio, 'fin' => $fin, 'productos' => [1, 2, 3, 4, 5, 6]];
$productos = [
    ['producto_id' => 1, 'activo' => 1, 'precio_mxn' => '50.00', 'precio_anterior' => '100.00'],
    ['producto_id' => 2, 'activo' => 1, 'precio_mxn' => '75.00', 'precio_anterior' => '100.00'],
    ['producto_id' => 3, 'activo' => 0, 'precio_mxn' => '1.00', 'precio_anterior' => '100.00'],
    ['producto_id' => 4, 'activo' => 1, 'precio_mxn' => '100.00', 'precio_anterior' => '100.00'],
    ['producto_id' => 5, 'activo' => 1, 'precio_mxn' => '100.00', 'precio_anterior' => null],
    ['producto_id' => 6, 'activo' => 1, 'precio_mxn' => '120.00', 'precio_anterior' => '100.00'],
    ['producto_id' => 7, 'activo' => 1, 'precio_mxn' => '1.00', 'precio_anterior' => '100.00'],
];
comprobar('sin configuración no hay campaña', IOPromociones::resolver([], $productos, $ahora) === null);
comprobar('sin productos seleccionados no hay campaña', IOPromociones::resolver(['productos' => []] + $config, $productos, $ahora) === null);
comprobar('no se aceptan fechas relativas', IOPromociones::resolver(['fin' => '+2 days'] + $config, $productos, $ahora) === null);
comprobar('se exige zona horaria explícita', IOPromociones::resolver(['fin' => '2030-06-03T23:59:00'] + $config, $productos, $ahora) === null);
comprobar('rechaza una fecha imposible', IOPromociones::resolver(['fin' => '2030-06-31T23:59:00-06:00'] + $config, $productos, $ahora) === null);
comprobar('la campaña no empieza antes de su inicio', IOPromociones::resolver($config, $productos, strtotime($inicio) - 1) === null);
comprobar('la campaña deja de mostrarse al vencer', IOPromociones::resolver($config, $productos, strtotime($fin)) === null);
$r = IOPromociones::resolver($config, $productos, $ahora);
comprobar('solo incluye productos seleccionados, activos y realmente rebajados', array_column($r['productos'], 'producto_id') === [1, 2]);
comprobar('el porcentaje anunciado sale del precio efectivo del catálogo', $r['maximo_pct'] === 50 && $r['productos'][1]['descuento_pct'] === 25);
comprobar('la fecha final permanece absoluta y no se renueva al consultar', $r['fin_iso'] === $fin && IOPromociones::resolver($config, $productos, $ahora + 10)['fin_iso'] === $fin);
$preciso = [['producto_id' => 1, 'activo' => 1, 'precio_mxn' => '66.67', 'precio_anterior' => '100.00']];
comprobar('no redondea el descuento a favor del anuncio', IOPromociones::resolver($config, $preciso, $ahora)['maximo_pct'] === 33);
comprobar('sin descuentos reales no publica el banner', IOPromociones::resolver($config, array_slice($productos, 2, 4), $ahora) === null);
comprobar('no altera los precios del producto', $r['productos'][0]['precio_mxn'] === '50.00' && $r['productos'][0]['precio_anterior'] === '100.00');
echo "\nResultado: $ok correctas, $fallos fallidas\n";
exit($fallos ? 1 : 0);
