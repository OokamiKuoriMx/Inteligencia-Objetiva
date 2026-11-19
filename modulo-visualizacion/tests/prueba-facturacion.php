<?php
/** Pruebas con Facturapi simulado. Base aleatoria exclusiva de MySQL local de pruebas. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/Facturacion.php';
$pdo = new PDO(getenv('IO_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3307;charset=utf8mb4', getenv('IO_TEST_USER') ?: 'root', getenv('IO_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$base = 'io_prueba_cfdi_' . bin2hex(random_bytes(5));
$pdo->exec("CREATE DATABASE `$base` CHARACTER SET utf8mb4");
$ok = 0; $fallos = 0;
function verificarF(string $nombre, bool $cond): void { global $ok, $fallos; if ($cond) { $ok++; echo "  ✔ $nombre\n"; } else { $fallos++; echo "  ✘ $nombre\n"; } }
function errorF(string $nombre, string $codigo, callable $fn): void { try { $fn(); verificarF($nombre, false); } catch (IOError $e) { verificarF($nombre, $e->codigo === $codigo); } }
function pedidoF(int $cliente = 10, string $estado = 'pagado', string $metodo = 'oxxo'): int
{
    $folio = 'CFDI-' . bin2hex(random_bytes(5));
    IO::ejecutar('INSERT INTO io_pedidos (folio,cliente_id,email,nombre,subtotal,iva,total,pasarela,pasarela_ref,metodo,estado) VALUES (?, ?, "fiscal@ejemplo.test", "Cliente", 100,16,116,"stripe",?,?,?)',
        [$folio, $cliente, 'pi_' . bin2hex(random_bytes(5)), $metodo, $estado]);
    $id = (int) IO::db()->lastInsertId();
    IO::ejecutar('INSERT INTO io_pedido_items (pedido_id,producto_id,titulo,precio_mxn) VALUES (?,410,"Curso pagado",116)', [$id]);
    return $id;
}
$fiscales = ['rfc' => 'ABC101010AB1', 'razon_social' => 'CLIENTE DE PRUEBA', 'regimen' => '601', 'codigo_postal' => '01000', 'uso_cfdi' => 'G03'];
$cliente = ['id' => 10, 'email' => 'fiscal@ejemplo.test', 'nombre' => 'Cliente'];
$cfg = ['dev' => true, 'facturacion' => ['habilitada' => true, 'modo' => 'prueba', 'api_key' => 'sk_test_...', 'producto_sat' => '86101700', 'unidad_sat' => 'E48']];
$remotas = []; $creaciones = 0; $ultimoPayload = null; $timeout = false; $rechazar = false; $cancelacionPendiente = false;
try {
    $pdo->exec("USE `$base`");
    foreach (glob(__DIR__ . '/../sql/*.sql') as $file) {
        foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents($file))))) as $sql) { $pdo->exec($sql); }
    }
    IO::usarConexion($pdo, $cfg);
    IOFacturacion::usarTransporteParaPruebas(function ($metodo, $ruta, $datos, $binario) use (&$remotas, &$creaciones, &$ultimoPayload, &$timeout, &$rechazar, &$cancelacionPendiente) {
        if ($ruta === '/invoices' && $metodo === 'GET') { return ['data' => array_values(array_filter($remotas, fn($r) => $r['external_id'] === $datos['external_id']))]; }
        if ($ruta === '/invoices' && $metodo === 'POST') {
            $ultimoPayload = $datos;
            if ($rechazar) { $rechazar = false; throw new IOError('factura_datos', 'Datos rechazados.', 400); }
            $id = 'factura_' . ++$creaciones;
            $total = array_sum(array_map(fn($i) => $i['product']['price'] * $i['quantity'], $datos['items']));
            $remotas[$id] = ['id' => $id, 'external_id' => $datos['external_id'], 'total' => $total, 'currency' => 'MXN',
                'customer' => $datos['customer'], 'livemode' => false, 'uuid' => '11111111-2222-3333-4444-555555555555', 'status' => 'valid', 'cancellation_status' => 'none'];
            if ($timeout) { $timeout = false; throw new IOError('factura_pendiente', 'Timeout después de timbrar.', 502); }
            return $remotas[$id];
        }
        preg_match('#^/invoices/([^/]+)(?:/(pdf|xml))?$#D', $ruta, $m);
        if (!isset($m[1], $remotas[$m[1]])) { throw new IOError('factura_pendiente', 'No encontrada.', 502); }
        if ($binario) { return ($m[2] ?? '') === 'pdf' ? '%PDF-1.4 demo' : '<?xml version="1.0"?><cfdi/>'; }
        if ($metodo === 'DELETE') {
            if ($cancelacionPendiente) { $remotas[$m[1]]['cancellation_status'] = 'pending'; }
            else { $remotas[$m[1]]['status'] = 'canceled'; $remotas[$m[1]]['cancellation_status'] = 'accepted'; }
        }
        return $remotas[$m[1]];
    }, function ($id) {
        return ['id' => $id, 'status' => 'succeeded', 'amount_received' => 11600, 'latest_charge' => ['payment_method_details' => ['card' => ['funding' => 'debit']]]];
    });
    verificarF('servicio desactivado si solo contiene llaves de ejemplo', !IOFacturacion::habilitada());
    errorF('rechaza RFC genérico para evitar facturas globales incompletas', 'rfc', fn() => IOFacturacion::validarReceptor(['rfc' => 'XAXX010101000'] + $fiscales));
    errorF('requiere código postal de cinco dígitos', 'datos_fiscales', fn() => IOFacturacion::validarReceptor(['codigo_postal' => '10'] + $fiscales));
    errorF('requiere régimen del catálogo', 'datos_fiscales', fn() => IOFacturacion::validarReceptor(['regimen' => '999'] + $fiscales));
    $p = pedidoF();
    errorF('un cliente no factura un pedido ajeno', 'pedido', fn() => IOFacturacion::solicitar(['id' => 20], $p, $fiscales));
    $pendiente = pedidoF(10, 'pendiente');
    errorF('no factura un pedido sin pagar', 'sin_pago', fn() => IOFacturacion::solicitar($cliente, $pendiente, $fiscales));
    $f = IOFacturacion::solicitar($cliente, $p, $fiscales);
    verificarF('emite y guarda el UUID del comprobante', $f['estado'] === 'emitida' && $f['uuid'] !== null);
    verificarF('OXXO se factura efectivo y PUE después de pagarse', $ultimoPayload['payment_form'] === '01' && $ultimoPayload['payment_method'] === 'PUE');
    verificarF('IVA incluido y claves SAT configuradas', $ultimoPayload['items'][0]['product']['tax_included'] === true && $ultimoPayload['items'][0]['product']['product_key'] === '86101700');
    verificarF('usa clave idempotente y external_id iguales', $ultimoPayload['external_id'] === $ultimoPayload['idempotency_key']);
    $antes = $creaciones;
    IOFacturacion::solicitar($cliente, $p, $fiscales);
    verificarF('solicitar dos veces no emite dos facturas', $creaciones === $antes);
    errorF('descarga protegida por cliente', 'pedido', fn() => IOFacturacion::descargar(['id' => 20], $p, 'xml'));
    errorF('descarga no admite formato arbitrario', 'formato', fn() => IOFacturacion::descargar($cliente, $p, '../config.php'));
    verificarF('descarga PDF y XML desde el servidor', strpos(IOFacturacion::descargar($cliente, $p, 'pdf'), '%PDF') === 0 && strpos(IOFacturacion::descargar($cliente, $p, 'xml'), '<?xml') === 0);
    $p2 = pedidoF(); $timeout = true;
    errorF('timeout no se presenta como factura emitida', 'factura_pendiente', fn() => IOFacturacion::solicitar($cliente, $p2, $fiscales));
    $antes = $creaciones; $f = IOFacturacion::solicitar($cliente, $p2, $fiscales);
    verificarF('reintento concilia el CFDI remoto sin volver a emitirlo', $f['estado'] === 'emitida' && $creaciones === $antes);
    $tarjeta = pedidoF(10, 'pagado', 'card'); IOFacturacion::solicitar($cliente, $tarjeta, $fiscales);
    verificarF('forma fiscal de tarjeta se toma del funding verificado en Stripe', $ultimoPayload['payment_form'] === '28');
    $gratis = pedidoF();
    IO::ejecutar('INSERT INTO io_pedido_items (pedido_id,producto_id,titulo,precio_mxn) VALUES (?,411,"Producto incluido 2x1",0)', [$gratis]);
    IOFacturacion::solicitar($cliente, $gratis, $fiscales);
    verificarF('2x1 excluye conceptos gratuitos conservando el importe pagado', count($ultimoPayload['items']) === 1 && $ultimoPayload['items'][0]['product']['price'] === 116);
    $conDescuento = pedidoF();
    IO::ejecutar('UPDATE io_pedidos SET subtotal=50,iva=8,total=58 WHERE id=?', [$conDescuento]);
    IO::ejecutar('UPDATE io_pedido_items SET precio_mxn=58 WHERE pedido_id=?', [$conDescuento]);
    IOFacturacion::solicitar($cliente, $conDescuento, $fiscales);
    verificarF('factura el importe final después del descuento, no el precio original', (int) round($ultimoPayload['items'][0]['product']['price'] * 100) === 5800);
    $p3 = pedidoF();
    IO::ejecutar('UPDATE io_pedido_items SET precio_mxn = 100 WHERE pedido_id = ?', [$p3]);
    errorF('no factura conceptos que no suman el monto pagado', 'importe', fn() => IOFacturacion::solicitar($cliente, $p3, $fiscales));
    $p4 = pedidoF();
    IO::ejecutar('INSERT INTO io_reembolsos (pedido_id,clave_solicitud,estado,monto_centavos,motivo) VALUES (?,"reembolso-prueba","pendiente",11600,"Prueba")', [$p4]);
    errorF('una devolución en proceso bloquea nueva factura', 'reembolso', fn() => IOFacturacion::solicitar($cliente, $p4, $fiscales));
    $p5 = pedidoF(); $rechazar = true;
    errorF('rechazo definitivo conserva solicitud para corrección', 'factura_datos', fn() => IOFacturacion::solicitar($cliente, $p5, $fiscales));
    verificarF('rechazo de datos queda marcado como corregible', IOFacturacion::dePedido($cliente, $p5)['estado'] === 'error');
    $claveAntes = IO::uno('SELECT idempotencia FROM io_facturas WHERE pedido_id = ?', [$p5])['idempotencia'];
    $f = IOFacturacion::solicitar($cliente, $p5, ['razon_social' => 'NOMBRE CORREGIDO'] + $fiscales);
    verificarF('corregir receptor conserva la clave idempotente', $f['estado'] === 'emitida' && $ultimoPayload['customer']['legal_name'] === 'NOMBRE CORREGIDO' && $ultimoPayload['idempotency_key'] === $claveAntes);
    errorF('cancelación fiscal exige administrador', 'sin_permiso', fn() => IOFacturacion::cancelar($p, '03'));
    IO::ejecutar('INSERT INTO io_admins (id,email,nombre,hash,rol) VALUES (1,"admin@ejemplo.test","Admin","no-login-test","admin")');
    errorF('cancelación fiscal exige motivo humano', 'motivo', fn() => IOFacturacion::cancelar($p, '03', null, ['id' => 1]));
    errorF('sustitución requiere UUID cuando corresponde', 'motivo_sat', fn() => IOFacturacion::cancelar($p, '01', null, ['id' => 1], 'Corrección'));
    $cancelacionPendiente = true;
    $f = IOFacturacion::cancelar($p, '03', null, ['id' => 1], 'Devolución autorizada');
    verificarF('solicitud SAT pendiente no se confunde con cancelación aceptada', $f['estado'] === 'cancelacion_pendiente');
    verificarF('solicitud y resultado se registran en bitácora', (int) IO::uno('SELECT COUNT(*) n FROM io_bitacora WHERE accion IN ("cancelar_cfdi","resultado_cfdi")')['n'] === 2);
    $fid = IO::uno('SELECT proveedor_id FROM io_facturas WHERE pedido_id = ?', [$p])['proveedor_id'];
    $remotas[$fid]['status'] = 'canceled'; $remotas[$fid]['cancellation_status'] = 'accepted';
    $f = IOFacturacion::consultar($cliente, $p);
    verificarF('consulta concilia aceptación de cancelación del SAT', $f['estado'] === 'cancelada');
} finally {
    IOFacturacion::usarTransporteParaPruebas(null);
    $pdo->exec("DROP DATABASE `$base`");
}
echo "Resultado: $ok correctas, $fallos fallidas\n";
exit($fallos ? 1 : 0);
