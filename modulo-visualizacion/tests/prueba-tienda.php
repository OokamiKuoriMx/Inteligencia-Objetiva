<?php
/**
 * Pruebas de la tienda: catálogo, totales, pedidos, pago demo, webhooks de Stripe (firma, idempotencia, montos).
 *   php tests/prueba-tienda.php      (usa el MySQL de prueba del puerto 3307; crea y borra io_prueba_tienda)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/Tienda.php';

$dsn = getenv('IO_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3307;charset=utf8mb4';
$pdo = new PDO($dsn, getenv('IO_TEST_USER') ?: 'root', getenv('IO_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->exec('DROP DATABASE IF EXISTS io_prueba_tienda');
$pdo->exec('CREATE DATABASE io_prueba_tienda CHARACTER SET utf8mb4');
$pdo->exec('USE io_prueba_tienda');
foreach (glob(__DIR__ . '/../sql/*.sql') as $f) { foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents($f))))) as $sql) { $pdo->exec($sql); } }
date_default_timezone_set('America/Mexico_City');
$pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
$SECRETO = 'whsec_prueba_' . bin2hex(random_bytes(8));
$cfgLocal = ['zona_horaria' => 'America/Mexico_City', 'dominio' => 'http://127.0.0.1:8090', 'dev' => true, 'tienda' => ['stripe_webhook' => $SECRETO]];
IO::usarConexion($pdo, $cfgLocal);

$ok = 0; $fallos = 0;
function verificar(string $n, bool $c): void { global $ok, $fallos; if ($c) { $ok++; echo "  ✔ $n\n"; } else { $fallos++; echo "  ✘ $n\n"; } }
function esperarError(string $n, string $codigo, callable $fn): void { try { $fn(); verificar("$n (debía fallar)", false); } catch (IOError $e) { verificar("$n → $codigo", $e->codigo === $codigo); } }
function firmar(string $payload, string $secreto, ?int $t = null): string { $t = $t ?? time(); return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $payload, $secreto); }

$adm = ['id' => 1, 'email' => 'admin@prueba.test'];
$base = ['vimeo_hash' => null, 'horas_ventana' => 48, 'cuenta_desde' => 'primera_reproduccion', 'dias_para_empezar' => 30, 'umbral_pct' => 95,
         'retroceso_seg' => 30, 'permitir_adelantar' => 0, 'pausa_max_min' => 60, 'reanudaciones_max' => 2, 'gracia_min' => 120];
$prods = [
    [1, 'pareja-1', 'Pareja uno', 'conferencias', 'pareja', 579.00, null, 'ventana', 10],
    [2, 'economia-1', 'Economía uno', 'conferencias', 'economia', 579.00, null, 'ventana', 50],
    [3, 'curso-unico', 'Curso único', 'cursos', 'desarrollo', 1855.00, null, 'unica', 30],
    [4, 'docu-1', 'Documental uno', 'documentales', 'desarrollo', 464.00, 928.00, 'permanente', 5],
    [5, 'intro-1', 'Introspección uno', 'introspecciones', 'desarrollo', 464.00, 928.00, 'permanente', 1],
];
foreach ($prods as [$id, $slug, $t, $cat, $tema, $precio, $antes, $tipo, $ventas]) {
    IOAdmin::guardarPolitica($adm, $base + ['producto_id' => $id, 'titulo' => $t, 'vimeo_id' => '76979871', 'duracion_seg' => 600, 'tipo' => $tipo], 'Configuración');
    IO::ejecutar('INSERT INTO io_productos (producto_id, slug, titulo, categoria, tema, resumen, precio_mxn, precio_anterior, ventas) VALUES (?,?,?,?,?,?,?,?,?)',
        [$id, $slug, $t, $cat, $tema, "Resumen de $t", $precio, $antes, $ventas]);
}
IO::ejecutar('INSERT INTO io_productos (producto_id, slug, titulo, categoria, precio_mxn, activo) VALUES (9, "inactivo", "Inactivo", "conferencias", 1, 0)');
$ana = ['id' => 101, 'email' => 'ana@prueba.test', 'nombre' => 'Ana'];

echo "\n1. Catálogo\n";
verificar('todo el catálogo activo: 5 productos', IOTienda::catalogo([])['total'] === 5);
verificar('filtro conferencias: 2', IOTienda::catalogo(['categoria' => 'conferencias'])['total'] === 2);
verificar('"documentales" incluye introspecciones: 2', IOTienda::catalogo(['categoria' => 'documentales'])['total'] === 2);
verificar('búsqueda "pareja": 1', IOTienda::catalogo(['q' => 'pareja'])['total'] === 1);
verificar('filtro por acceso único: 1', IOTienda::catalogo(['acceso' => 'unica'])['total'] === 1);
verificar('orden más vendidos primero', IOTienda::catalogo(['orden' => 'populares'])['productos'][0]['producto_id'] == 2);
verificar('orden inválido no rompe (usa populares)', IOTienda::catalogo(['orden' => "1; DROP TABLE io_productos"])['total'] === 5);
verificar('paginación: 2 por página → 3 páginas', IOTienda::catalogo([], 1, 2)['paginas'] === 3);
verificar('producto inactivo no tiene ficha', IOTienda::producto('inactivo') === null);
verificar('texto de acceso único', IOTienda::textoAcceso(IOTienda::producto('curso-unico'))[0] === 'unica');

echo "\n2. Totales calculados en el servidor\n";
$r = IOTienda::resumen([1, 4, 9, 999]);
verificar('ignora productos inactivos o inexistentes', count($r['items']) === 2);
verificar('total con IVA = 579 + 464 = 1,043', $r['total'] === 1043.0);
verificar('subtotal + IVA = total', abs($r['subtotal'] + $r['iva'] - $r['total']) < 0.001);
verificar('ahorro mostrado: 464', $r['ahorro'] === 464.0);

echo "\n3. Pasarela según entorno\n";
verificar('localhost sin llaves → demo', IOTienda::pasarela() === 'demo');
IO::usarConexion($pdo, ['dev' => false] + $cfgLocal);
esperarError('dominio localhost con dev false no habilita pagos demo', 'pasarela', fn() => IOTienda::pasarela());
IO::usarConexion($pdo, $cfgLocal);
IO::usarConexion($pdo, ['dominio' => 'https://inteligenciaobjetiva.com'] + $cfgLocal);
esperarError('producción sin llaves → no hay pago simulado', 'pasarela', fn() => IOTienda::pasarela());
IO::usarConexion($pdo, ['dominio' => 'https://inteligenciaobjetiva.com', 'tienda' => ['stripe_secreta' => 'sk_test_x', 'stripe_webhook' => $SECRETO]] + $cfgLocal);
verificar('producción con llaves → stripe', IOTienda::pasarela() === 'stripe');
IO::usarConexion($pdo, $cfgLocal);

echo "\n4. Pedido y pago demo\n";
esperarError('carrito vacío no crea pedido', 'carrito', fn() => IOTienda::crearPedido($ana, [], 'demo'));
$p = IOTienda::crearPedido($ana, [1, 3], 'demo');
verificar('pedido pendiente con total 2,434', $p['estado'] === 'pendiente' && (float) $p['total'] === 2434.0);
$p = IOTienda::confirmarPago((int) $p['id'], 'demo_1', 'card');
verificar('pagado y 2 accesos creados', $p['estado'] === 'pagado' && (int) IO::uno('SELECT COUNT(*) n FROM io_accesos WHERE pedido_ref = ?', [$p['folio']])['n'] === 2);
IOTienda::confirmarPago((int) $p['id'], 'demo_1', 'card');
verificar('confirmar dos veces no duplica accesos', (int) IO::uno('SELECT COUNT(*) n FROM io_accesos WHERE pedido_ref = ?', [$p['folio']])['n'] === 2);
verificar('el acceso de visualización única queda por empezar', IO::uno('SELECT estado FROM io_accesos WHERE pedido_ref = ? AND producto_id = 3', [$p['folio']])['estado'] === 'por_empezar');
verificar('ventas del producto se incrementan', (int) IO::uno('SELECT ventas FROM io_productos WHERE producto_id = 1')['ventas'] === 11);
verificar('Ana solo ve su pedido', IOTienda::pedidoPorFolio($p['folio'], 101) !== null && IOTienda::pedidoPorFolio($p['folio'], 999) === null);

echo "\n5. Webhook de Stripe\n";
$p2 = IOTienda::crearPedido($ana, [2, 5], 'stripe');
IO::ejecutar('UPDATE io_pedidos SET pasarela_ref = "pi_123" WHERE id = ?', [$p2['id']]);
$evento = fn(string $id, string $tipo, int $monto) => json_encode(['id' => $id, 'type' => $tipo, 'data' => ['object' => ['id' => 'pi_123', 'currency' => 'mxn', 'amount' => $monto, 'amount_received' => $monto, 'payment_method_types' => ['card']]]]);
[$h] = io_procesar_webhook_stripe($evento('evt_x', 'payment_intent.succeeded', 104300), firmar($evento('evt_x', 'payment_intent.succeeded', 104300), 'whsec_otro'));
verificar('firma con otro secreto → 400', $h === 400);
$pl = $evento('evt_viejo', 'payment_intent.succeeded', 104300);
[$h] = io_procesar_webhook_stripe($pl, firmar($pl, $SECRETO, time() - 3600));
verificar('firma vencida (1 h) → 400', $h === 400);
$pl = $evento('evt_monto', 'payment_intent.succeeded', 100);
[$h] = io_procesar_webhook_stripe($pl, firmar($pl, $SECRETO));
verificar('monto distinto al pedido → 500 y no se paga', $h === 500 && IO::uno('SELECT estado FROM io_pedidos WHERE id = ?', [$p2['id']])['estado'] === 'pendiente');
verificar('el evento fallido se puede reintentar', IO::uno('SELECT 1 FROM io_webhooks WHERE evento_id = "evt_monto"') === null);
$pl = $evento('evt_ok', 'payment_intent.succeeded', 104300);
[$h, $resp] = io_procesar_webhook_stripe($pl, firmar($pl, $SECRETO));
verificar('pago correcto → pedido pagado', $h === 200 && IO::uno('SELECT estado FROM io_pedidos WHERE id = ?', [$p2['id']])['estado'] === 'pagado');
verificar('accesos creados por el webhook: 2', (int) IO::uno('SELECT COUNT(*) n FROM io_accesos WHERE pedido_ref = ?', [$p2['folio']])['n'] === 2);
[$h, $resp] = io_procesar_webhook_stripe($pl, firmar($pl, $SECRETO));
verificar('evento repetido → marcado como duplicado', !empty($resp['duplicado']));
$p3 = IOTienda::crearPedido($ana, [4], 'stripe');
IO::ejecutar('UPDATE io_pedidos SET pasarela_ref = "pi_456" WHERE id = ?', [$p3['id']]);
$pl = json_encode(['id' => 'evt_falla', 'type' => 'payment_intent.payment_failed', 'data' => ['object' => ['id' => 'pi_456', 'currency' => 'mxn', 'amount' => 46400]]]);
io_procesar_webhook_stripe($pl, firmar($pl, $SECRETO));
verificar('pago rechazado → pedido fallido y sin accesos', IO::uno('SELECT estado FROM io_pedidos WHERE id = ?', [$p3['id']])['estado'] === 'fallido'
    && (int) IO::uno('SELECT COUNT(*) n FROM io_accesos WHERE pedido_ref = ?', [$p3['folio']])['n'] === 0);

echo "\n6. Ficha OXXO real y autorización del intento\n";
$p4 = IOTienda::crearPedido($ana, [4], 'stripe');
IO::ejecutar('UPDATE io_pedidos SET pasarela_ref = "pi_oxxo" WHERE id = ?', [$p4['id']]);
$p4 = IOTienda::pedidoPorFolio($p4['folio'], 101);
$pi = ['id' => 'pi_oxxo', 'currency' => 'mxn', 'amount' => 46400, 'status' => 'requires_action',
    'payment_method_types' => ['card', 'oxxo'], 'next_action' => ['type' => 'oxxo_display_details', 'oxxo_display_details' => [
        'number' => '12345678901234', 'expires_after' => time() + 86400, 'hosted_voucher_url' => 'https://payments.stripe.com/oxxo/test']]];
$ficha = IOTienda::fichaOxxo($p4, $pi);
verificar('se conserva la referencia y la URL emitidas por Stripe', $ficha['numero'] === '12345678901234' && $ficha['url'] === $pi['next_action']['oxxo_display_details']['hosted_voucher_url']);
verificar('OXXO pendiente conserva el método sin otorgar acceso', IO::uno('SELECT metodo FROM io_pedidos WHERE id = ?', [$p4['id']])['metodo'] === 'oxxo'
    && (int) IO::uno('SELECT COUNT(*) n FROM io_accesos WHERE pedido_ref = ?', [$p4['folio']])['n'] === 0);
esperarError('no acepta un intento de otra compra', 'intento', fn() => IOTienda::fichaOxxo($p4, ['id' => 'pi_otro'] + $pi));
esperarError('no acepta moneda distinta', 'intento', fn() => IOTienda::fichaOxxo($p4, ['currency' => 'usd'] + $pi));
esperarError('no acepta monto distinto', 'intento', fn() => IOTienda::fichaOxxo($p4, ['amount' => 1] + $pi));
$mala = $pi; $mala['next_action']['oxxo_display_details']['hosted_voucher_url'] = 'https://stripe.com.ejemplo.org/ficha';
esperarError('rechaza enlaces externos que imitan Stripe', 'ficha', fn() => IOTienda::fichaOxxo($p4, $mala));
verificar('un pago completado no devuelve ficha pendiente', IOTienda::fichaOxxo($p4, ['status' => 'succeeded'] + $pi) === null);
$pl = json_encode(['id' => 'evt_oxxo_pendiente', 'type' => 'payment_intent.requires_action', 'data' => ['object' => $pi]]);
[$h] = io_procesar_webhook_stripe($pl, firmar($pl, $SECRETO));
verificar('webhook de ficha pendiente se procesa sin pagar el pedido', $h === 200 && IO::uno('SELECT estado FROM io_pedidos WHERE id = ?', [$p4['id']])['estado'] === 'pendiente');
$pl = json_encode(['id' => 'evt_oxxo_pagado', 'type' => 'payment_intent.succeeded', 'data' => ['object' => ['status' => 'succeeded', 'amount_received' => 46400] + $pi]]);
[$h] = io_procesar_webhook_stripe($pl, firmar($pl, $SECRETO));
verificar('OXXO pagado conserva oxxo aunque card sea el primer método permitido', $h === 200 && IO::uno('SELECT metodo FROM io_pedidos WHERE id = ?', [$p4['id']])['metodo'] === 'oxxo');
$reintento = IOTienda::crearPedido($ana, [4], 'stripe');
IO::ejecutar('UPDATE io_pedidos SET pasarela_ref = "pi_reintento_oxxo", estado = "fallido" WHERE id = ?', [$reintento['id']]);
$piReintento = ['id' => 'pi_reintento_oxxo'] + $pi;
$pl = json_encode(['id' => 'evt_reintento_oxxo', 'type' => 'payment_intent.requires_action', 'data' => ['object' => $piReintento]]);
[$h] = io_procesar_webhook_stripe($pl, firmar($pl, $SECRETO));
$reintento = IOTienda::pedidoPorFolio($reintento['folio'], 101);
verificar('tras rechazo de tarjeta, el intento OXXO vuelve a pendiente', $h === 200 && $reintento['estado'] === 'pendiente' && $reintento['metodo'] === 'oxxo');
verificar('el cambio de método recupera la ficha sin otorgar acceso', IOTienda::fichaOxxo($reintento, $piReintento)['numero'] === $pi['next_action']['oxxo_display_details']['number']
    && (int) IO::uno('SELECT COUNT(*) n FROM io_accesos WHERE pedido_ref = ?', [$reintento['folio']])['n'] === 0);
$pagado = IOTienda::pedidoPorFolio($p4['folio'], 101);
verificar('un evento de ficha retrasado no reabre un pedido pagado', IOTienda::fichaOxxo($pagado, $pi) === null && IOTienda::pedidoPorFolio($p4['folio'], 101)['estado'] === 'pagado');
IO::ejecutar('UPDATE io_pedidos SET estado = "cancelado" WHERE id = ?', [$reintento['id']]);
$cancelado = IOTienda::pedidoPorFolio($reintento['folio'], 101);
verificar('un evento de ficha retrasado no reabre un pedido cancelado', IOTienda::fichaOxxo($cancelado, $piReintento) === null && IOTienda::pedidoPorFolio($reintento['folio'], 101)['estado'] === 'cancelado');

echo "\n7. Reversión completa y reintentos del webhook\n";
$p5 = IOTienda::crearPedido($ana, [1, 2], 'stripe');
IO::ejecutar('UPDATE io_pedidos SET pasarela_ref = "pi_atomico" WHERE id = ?', [$p5['id']]);
$pl = json_encode(['id' => 'evt_atomico', 'type' => 'payment_intent.succeeded', 'data' => ['object' => [
    'id' => 'pi_atomico', 'currency' => 'mxn', 'amount' => 115800, 'amount_received' => 115800, 'payment_method_types' => ['card']]]]);
$ventasAntes = IO::uno('SELECT ventas FROM io_productos WHERE producto_id = 1')['ventas'];
// Fuerza un fallo después de otorgar el primer acceso, al intentar crear el segundo.
$pdo->exec("CREATE TRIGGER io_fallo_prueba BEFORE INSERT ON io_accesos FOR EACH ROW BEGIN IF NEW.producto_id = 2 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fallo intencional de prueba'; END IF; END");
[$h] = io_procesar_webhook_stripe($pl, firmar($pl, $SECRETO));
$pdo->exec('DROP TRIGGER io_fallo_prueba');
verificar('fallo a mitad del procesamiento devuelve 500', $h === 500);
verificar('la marca de evento también se revierte', IO::uno('SELECT 1 FROM io_webhooks WHERE evento_id = "evt_atomico"') === null);
verificar('se revierten el pago, los accesos y las ventas parciales', IO::uno('SELECT estado FROM io_pedidos WHERE id = ?', [$p5['id']])['estado'] === 'pendiente'
    && (int) IO::uno('SELECT COUNT(*) n FROM io_accesos WHERE pedido_ref = ?', [$p5['folio']])['n'] === 0
    && IO::uno('SELECT ventas FROM io_productos WHERE producto_id = 1')['ventas'] === $ventasAntes);
[$h] = io_procesar_webhook_stripe($pl, firmar($pl, $SECRETO));
verificar('el mismo evento se puede reintentar y completa ambos accesos', $h === 200 && (int) IO::uno('SELECT COUNT(*) n FROM io_accesos WHERE pedido_ref = ?', [$p5['folio']])['n'] === 2);
$p6 = IOTienda::crearPedido($ana, [1], 'stripe');
$pl = json_encode(['id' => 'evt_antes_vinculo', 'type' => 'payment_intent.succeeded', 'data' => ['object' => [
    'id' => 'pi_sin_vincular', 'currency' => 'mxn', 'amount' => 57900, 'amount_received' => 57900, 'payment_method_types' => ['card'],
    'metadata' => ['pedido_id' => (string) $p6['id'], 'folio' => $p6['folio']]]]]);
[$h] = io_procesar_webhook_stripe($pl, firmar($pl, $SECRETO));
verificar('un evento adelantado no queda marcado como procesado', $h === 500 && IO::uno('SELECT 1 FROM io_webhooks WHERE evento_id = "evt_antes_vinculo"') === null);
IO::ejecutar('UPDATE io_pedidos SET pasarela_ref = "pi_sin_vincular" WHERE id = ?', [$p6['id']]);
[$h] = io_procesar_webhook_stripe($pl, firmar($pl, $SECRETO));
verificar('tras vincular la referencia se procesa el reintento', $h === 200 && IO::uno('SELECT estado FROM io_pedidos WHERE id = ?', [$p6['id']])['estado'] === 'pagado');
$pl = json_encode(['id' => 'evt_otro_sitio', 'type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_otro_sitio']]]);
[$h, $resp] = io_procesar_webhook_stripe($pl, firmar($pl, $SECRETO));
verificar('pagos ajenos a la tienda se ignoran sin provocar reintentos', $h === 200 && ($resp['ignorado'] ?? '') === 'sin pedido');

echo "\n8. Validación del carrito antes de iniciar un cobro\n";
$pedidosAntes = IO::uno('SELECT COUNT(*) n FROM io_pedidos')['n'];
IO::ejecutar('INSERT INTO io_productos (producto_id, slug, titulo, categoria, precio_mxn) VALUES (10, "sin-politica", "Sin política", "cursos", 100)');
esperarError('un producto sin política bloquea todo el pedido', 'sin_politica', fn() => IOTienda::crearPedido($ana, [1, 10], 'stripe'));
esperarError('un producto inactivo no se elimina silenciosamente del pedido', 'producto', fn() => IOTienda::crearPedido($ana, [1, 9], 'stripe'));
esperarError('un producto inexistente no se elimina silenciosamente del pedido', 'producto', fn() => IOTienda::crearPedido($ana, [1, 999], 'stripe'));
IO::ejecutar('UPDATE io_politicas SET duracion_seg = 0 WHERE producto_id = 3');
esperarError('visualización única sin duración no se puede cobrar', 'politica', fn() => IOTienda::crearPedido($ana, [1, 3], 'stripe'));
IO::ejecutar('UPDATE io_politicas SET duracion_seg = 600 WHERE producto_id = 3');
IO::ejecutar('UPDATE io_productos SET precio_mxn = 0 WHERE producto_id = 4');
esperarError('un producto sin precio válido bloquea todo el pedido', 'precio', fn() => IOTienda::crearPedido($ana, [1, 4], 'stripe'));
IO::ejecutar('UPDATE io_productos SET precio_mxn = 464 WHERE producto_id = 4');
verificar('los carritos inválidos no crean pedidos parciales', IO::uno('SELECT COUNT(*) n FROM io_pedidos')['n'] === $pedidosAntes);
IO::ejecutar('UPDATE io_politicas SET duracion_seg = 0 WHERE producto_id = 1');
verificar('una ventana sin duración de video permite crear pedido', IOTienda::crearPedido($ana, [1], 'stripe')['estado'] === 'pendiente');
IO::ejecutar('UPDATE io_politicas SET duracion_seg = 600 WHERE producto_id = 1');

echo "\n9. Redirecciones de fichas antiguas\n";
$reglas = file_get_contents(__DIR__ . '/../tienda/rewrite-produccion.txt');
preg_match('~^RewriteRule (\S+) (/producto/\$2) \[R=301,L\]$~m', $reglas, $regla);
foreach (['conferencias-en-linea', 'cursos-en-linea', 'documentales', 'introspecciones', 'audiolibro'] as $cat) {
    $destino = isset($regla[1]) ? preg_replace('~' . $regla[1] . '~', $regla[2], $cat . '/pareja-1') : '';
    verificar("$cat conserva el ID y resuelve un producto real", $destino === '/producto/pareja-1' && IOTienda::producto(substr($destino, 10)) !== null);
}

$pdo->exec('DROP DATABASE io_prueba_tienda');
echo "\nResultado: $ok correctas, $fallos fallidas\n";
exit($fallos ? 1 : 0);
