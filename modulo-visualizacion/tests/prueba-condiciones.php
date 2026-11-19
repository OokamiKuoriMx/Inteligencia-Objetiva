<?php
/** Pruebas de las condiciones publicadas antes del pago. No requieren base de datos ni configuración. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/Tienda.php';

$correctas = 0; $fallidas = 0;
function comprobar(string $caso, bool $ok): void
{
    global $correctas, $fallidas;
    if ($ok) { $correctas++; } else { $fallidas++; }
    echo ($ok ? '  ✔ ' : '  ✘ ') . $caso . PHP_EOL;
}

$ventana = ['acceso_tipo' => 'ventana', 'horas_ventana' => 48, 'dias_para_empezar' => 7];
$compra = IOTienda::textoAcceso(['cuenta_desde' => 'compra'] + $ventana);
comprobar('la vigencia desde compra no promete un plazo extra para empezar', strpos($compra[2], 'desde la confirmación de tu compra') !== false && strpos($compra[2], 'para empezar') === false);
$play = IOTienda::textoAcceso(['cuenta_desde' => 'primera_reproduccion'] + $ventana);
comprobar('la vigencia desde play muestra el plazo de siete días configurado', strpos($play[2], 'desde tu primera reproducción') !== false && strpos($play[2], '7 días desde la compra') !== false && strpos($play[2], '30 días') === false);
$unDia = IOTienda::textoAcceso(['dias_para_empezar' => 1] + $ventana);
comprobar('un día para empezar se comunica en singular', strpos($unDia[2], '1 día desde la compra') !== false);
$curso = IOTienda::textoAcceso(['horas_ventana' => 240] + $ventana);
comprobar('un curso de 240 horas conserva sus diez días de reproducción', $curso[1] === '10 días de acceso' && strpos($curso[2], 'Disponible 10 días') !== false);
$permanente = IOTienda::textoAcceso(['acceso_tipo' => 'permanente', 'dias_para_empezar' => 7]);
comprobar('el acceso permanente no anuncia vencimiento ni límite de inicio', $permanente[1] === 'Acceso permanente' && strpos($permanente[2], 'para empezar') === false && strpos($permanente[2], '7 días') === false);
$unica = ['acceso_tipo' => 'unica', 'dias_para_empezar' => 14, 'reanudaciones_max' => 1, 'gracia_min' => 45];
$retomar = IOTienda::textoAcceso($unica);
comprobar('la visualización única comunica sus límites reales de reanudación y gracia', strpos($retomar[2], 'hasta 1 vez') !== false && strpos($retomar[2], '45 min') !== false && strpos($retomar[2], '14 días') !== false);
$sinRetomar = IOTienda::textoAcceso(['reanudaciones_max' => 0] + $unica);
comprobar('cero reanudaciones no promete continuar tras un corte', strpos($sinRetomar[2], 'No permite reanudaciones.') !== false && strpos($sinRetomar[2], 'Puedes reanudar') === false);
$sinGracia = IOTienda::textoAcceso(['gracia_min' => 0] + $unica);
comprobar('sin período de gracia tampoco promete reanudaciones', strpos($sinGracia[2], 'No permite reanudaciones.') !== false);
$sinPlazo = IOTienda::textoAcceso(['dias_para_empezar' => 0] + $ventana);
comprobar('un plazo de cero días no se sustituye por treinta días', strpos($sinPlazo[2], 'vence al confirmarse la compra') !== false && strpos($sinPlazo[2], '30 días') === false);
comprobar('un producto sin política no se anuncia como acceso de 48 horas', IOTienda::textoAcceso(null)[0] === 'pendiente' && IOTienda::textoAcceso(['acceso_tipo' => null])[1] === 'Acceso por confirmar');

echo "Resultado: $correctas correctas, $fallidas fallidas" . PHP_EOL;
exit($fallidas ? 1 : 0);
