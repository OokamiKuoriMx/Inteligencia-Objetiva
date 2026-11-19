<?php
/** Reembolsos bajo transporte simulado y base exclusiva de prueba. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/Pedidos.php';
$pdo = new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$bd = 'io_prueba_pedidos_' . bin2hex(random_bytes(5));
$pdo->exec("CREATE DATABASE `$bd` CHARACTER SET utf8mb4");
$ok = 0; $fallos = 0;
function probar(string $n, bool $c): void { global $ok, $fallos; if ($c) { $ok++; echo "  ✔ $n\n"; } else { $fallos++; echo "  ✘ $n\n"; } }
function falla(string $n, string $codigo, callable $fn): void { try { $fn(); probar($n, false); } catch (IOError $e) { probar($n, $e->codigo === $codigo); } }
try {
    $pdo->exec("USE `$bd`");
    foreach (glob(__DIR__ . '/../sql/*.sql') as $f) {
        foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents($f))))) as $sql) { $pdo->exec($sql); }
    }
    date_default_timezone_set('America/Mexico_City');
    $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    $cfg = ['dominio' => 'http://127.0.0.1:8090', 'dev' => true, 'pedidos' => ['reembolsos_habilitados' => true], 'tienda' => ['stripe_webhook' => 'whsec_prueba']];
    IO::usarConexion($pdo, $cfg);
    IO::ejecutar('INSERT INTO io_admins (id,email,nombre,hash,rol) VALUES (1,"admin@prueba.test","Admin","no-login","admin"),(2,"soporte@prueba.test","Soporte","no-login","soporte")');
    $admin = ['id' => 1, 'email' => 'admin@prueba.test'];
    IOAdmin::guardarPolitica($admin, ['producto_id' => 1, 'titulo' => 'Producto prueba', 'vimeo_id' => '123', 'duracion_seg' => 600, 'tipo' => 'permanente', 'horas_ventana' => 48, 'cuenta_desde' => 'compra', 'dias_para_empezar' => 30, 'umbral_pct' => 95, 'retroceso_seg' => 30, 'permitir_adelantar' => 0, 'pausa_max_min' => 60, 'reanudaciones_max' => 2, 'gracia_min' => 120], 'Prueba');
    IO::ejecutar('INSERT INTO io_productos (producto_id,slug,titulo,precio_mxn) VALUES (1,"producto-1","Producto prueba",116)');
    $cliente = ['id' => 101, 'email' => 'cliente@prueba.test', 'nombre' => 'Cliente'];
    $p = IOTienda::crearPedido($cliente, [1], 'stripe');
    IOTienda::confirmarPago((int) $p['id'], 'pi_refund_1', 'card', 11600);
    falla('soporte no puede devolver dinero', 'sin_permiso', fn() => IOPedidos::reembolsar(['id' => 2], (int) $p['id'], 'Solicitud del cliente'));
    falla('motivo obligatorio', 'motivo', fn() => IOPedidos::reembolsar($admin, (int) $p['id'], ''));
    IO::usarConexion($pdo, ['pedidos' => ['reembolsos_habilitados' => false]] + $cfg);
    falla('sin habilitación no solicita devolución', 'configuracion', fn() => IOPedidos::reembolsar($admin, (int) $p['id'], 'Error de compra'));
    IO::usarConexion($pdo, $cfg);
    $llamadas = 0; $claves = []; $timeout = true;
    IOStripe::usarTransporteParaPruebas(function ($metodo, $ruta, $datos, $clave) use (&$llamadas, &$claves, &$timeout) {
        $llamadas++; $claves[] = $clave;
        if ($timeout) { throw new IOError('stripe', 'Timeout simulado', 502); }
        return ['id' => 're_prueba_1', 'payment_intent' => 'pi_refund_1', 'status' => 'succeeded', 'amount' => 11600, 'currency' => 'mxn'];
    });
    falla('un timeout conserva solicitud para conciliar', 'stripe', fn() => IOPedidos::reembolsar($admin, (int) $p['id'], 'Error de compra'));
    $r = IO::uno('SELECT * FROM io_reembolsos WHERE pedido_id = ?', [$p['id']]);
    probar('sin confirmación no revoca acceso', $r['estado'] === 'solicitado' && IO::uno('SELECT a.estado FROM io_accesos a JOIN io_pedido_items i ON i.acceso_id=a.id WHERE i.pedido_id=?', [$p['id']])['estado'] === 'activo');
    $timeout = false;
    $r = IOPedidos::reembolsar($admin, (int) $p['id'], 'Error de compra');
    probar('el reintento usa exactamente la misma clave idempotente', $claves[0] === $claves[1]);
    probar('devolución confirmada revoca acceso', $r['estado'] === 'completo' && IO::uno('SELECT a.estado FROM io_accesos a JOIN io_pedido_items i ON i.acceso_id=a.id WHERE i.pedido_id=?', [$p['id']])['estado'] === 'revocado');
    IOPedidos::reembolsar($admin, (int) $p['id'], 'Solicitud repetida');
    probar('repetir una devolución completa no crea otro cargo ni llamada', $llamadas === 2 && (int) IO::uno('SELECT COUNT(*) n FROM io_reembolsos WHERE pedido_id=?', [$p['id']])['n'] === 1);
    $p2 = IOTienda::crearPedido($cliente, [1], 'stripe');
    IOTienda::confirmarPago((int) $p2['id'], 'pi_refund_2', 'card', 11600);
    $evt = ['type' => 'charge.refunded', 'data' => ['object' => ['id' => 'ch_2', 'payment_intent' => 'pi_refund_2', 'amount' => 11600, 'amount_refunded' => 100, 'currency' => 'mxn']]];
    IO::transaccion(fn() => IOPedidos::procesarEvento($evt));
    probar('devolución parcial externa no revoca el acceso completo', IO::uno('SELECT a.estado FROM io_accesos a JOIN io_pedido_items i ON i.acceso_id=a.id WHERE i.pedido_id=?', [$p2['id']])['estado'] === 'activo');
    $evt['data']['object']['amount_refunded'] = 11600;
    IO::transaccion(fn() => IOPedidos::procesarEvento($evt));
    probar('acumulado externo completo revoca el acceso', IO::uno('SELECT a.estado FROM io_accesos a JOIN io_pedido_items i ON i.acceso_id=a.id WHERE i.pedido_id=?', [$p2['id']])['estado'] === 'revocado');
    $evt['data']['object']['amount_refunded'] = 100;
    IO::transaccion(fn() => IOPedidos::procesarEvento($evt));
    probar('evento antiguo no reduce el importe ya confirmado', (int) IO::uno('SELECT monto_centavos FROM io_reembolsos WHERE clave_solicitud="charge-ch_2"')['monto_centavos'] === 11600);
    $p3 = IOTienda::crearPedido($cliente, [1], 'stripe');
    IOTienda::confirmarPago((int) $p3['id'], 'pi_refund_3', 'card', 11600);
    IO::ejecutar('INSERT INTO io_reembolsos(pedido_id,clave_solicitud,estado,monto_centavos,moneda,motivo) VALUES (?,"r3","solicitado",11600,"MXN","Prueba de reversión")', [$p3['id']]);
    $r3 = (int) $pdo->lastInsertId();
    $remote = ['id' => 're_3', 'payment_intent' => 'pi_refund_3', 'status' => 'succeeded', 'amount' => 11600, 'currency' => 'mxn'];
    falla('no aplica una devolución de otro pago', 'reembolso', fn() => IOPedidos::aplicar($r3, ['payment_intent' => 'pi_ajeno'] + $remote));
    $pdo->exec("CREATE TRIGGER io_fallo_auditoria BEFORE INSERT ON io_bitacora FOR EACH ROW BEGIN IF NEW.accion = 'revocar' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fallo intencional'; END IF; END");
    try { IOPedidos::aplicar($r3, $remote); probar('reversión ante fallo de auditoría', false); }
    catch (PDOException $e) { probar('reversión ante fallo de auditoría', IO::uno('SELECT estado FROM io_reembolsos WHERE id=?', [$r3])['estado'] === 'solicitado' && IO::uno('SELECT a.estado FROM io_accesos a JOIN io_pedido_items i ON i.acceso_id=a.id WHERE i.pedido_id=?', [$p3['id']])['estado'] === 'activo'); }
    $pdo->exec('DROP TRIGGER io_fallo_auditoria');
    IOPedidos::aplicar($r3, $remote);
    probar('la conciliación posterior completa la devolución', IO::uno('SELECT estado FROM io_reembolsos WHERE id=?', [$r3])['estado'] === 'completo');
    $p4 = IOTienda::crearPedido($cliente, [1], 'stripe');
    IO::ejecutar('UPDATE io_pedidos SET pasarela_ref="pi_anticipado" WHERE id=?', [$p4['id']]);
    $evento = json_encode(['id' => 'evt_refund_anticipado', 'type' => 'charge.refunded', 'data' => ['object' => ['id' => 'ch_anticipado', 'payment_intent' => 'pi_anticipado', 'amount' => 11600, 'amount_refunded' => 11600, 'currency' => 'mxn']]]);
    $fecha = time(); $firma = 't=' . $fecha . ',v1=' . hash_hmac('sha256', $fecha . '.' . $evento, 'whsec_prueba');
    [$http] = io_procesar_webhook_stripe($evento, $firma);
    probar('devolución firmada se enruta por el webhook compartido', $http === 200 && IO::uno('SELECT evento_id FROM io_webhooks WHERE evento_id="evt_refund_anticipado"') !== null);
    [$http, $respuesta] = io_procesar_webhook_stripe($evento, $firma);
    probar('reenviar devolución firmada es idempotente', $http === 200 && !empty($respuesta['duplicado']));
    IOTienda::confirmarPago((int) $p4['id'], 'pi_anticipado', 'card', 11600);
    probar('refund antes de pago no concede accesos al llegar el pago tardío', (int) IO::uno('SELECT COUNT(*) n FROM io_accesos WHERE pedido_ref=?', [$p4['folio']])['n'] === 0);
} finally { IOStripe::usarTransporteParaPruebas(null); $pdo->exec("DROP DATABASE `$bd`"); }
echo "Resultado: $ok correctas, $fallos fallidas\n";
exit($fallos ? 1 : 0);
