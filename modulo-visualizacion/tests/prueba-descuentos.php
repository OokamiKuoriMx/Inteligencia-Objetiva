<?php
/** Pruebas de descuentos en base exclusiva aleatoria. No usa config.php ni crea cargos. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/Tienda.php';
require_once __DIR__ . '/../lib/Descuentos.php';
$pdo = new PDO(getenv('IO_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3307;charset=utf8mb4', getenv('IO_TEST_USER') ?: 'root', getenv('IO_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$base = 'io_prueba_descuentos_' . bin2hex(random_bytes(5));
$pdo->exec("CREATE DATABASE `$base` CHARACTER SET utf8mb4"); $pdo->exec("USE `$base`");
date_default_timezone_set('America/Mexico_City');
$pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
IO::usarConexion($pdo, ['dev' => true, 'dominio' => 'http://127.0.0.1:8090', 'zona_horaria' => 'America/Mexico_City']);
$ok = 0; $fallos = 0;
function verificarDescuento(string $nombre, bool $bien): void { global $ok, $fallos; if ($bien) { $ok++; echo "  ✔ $nombre\n"; } else { $fallos++; echo "  ✘ $nombre\n"; } }
function errorDescuento(string $nombre, string $codigo, callable $fn): void { try { $fn(); verificarDescuento($nombre, false); } catch (IOError $e) { verificarDescuento($nombre, $e->codigo === $codigo); } }
function cup(string $codigo, string $tipo = 'porcentaje', float $valor = 10, array $cambios = []): int
{
    return IODescuentos::guardar(['id' => 1, 'email' => 'admin@ejemplo.test'], $cambios + ['codigo' => $codigo, 'tipo' => $tipo, 'valor' => $valor,
        'inicio' => date('Y-m-d\TH:i', time() - 3600), 'fin' => date('Y-m-d\TH:i', time() + 86400), 'minimo_mxn' => 0, 'max_usos' => '',
        'max_por_cliente' => 10, 'productos' => '', 'activo' => 1], 'Prueba automática');
}
function pedidoDescuento(array $items, string $codigo, int $cliente = 101): array
{
    return IO::transaccion(function () use ($items, $codigo, $cliente) {
        $p = IOTienda::crearPedido(['id' => $cliente, 'email' => 'cliente@ejemplo.test', 'nombre' => 'Prueba'], array_column($items, 'producto_id'), 'demo');
        IODescuentos::reservar((int) $p['id'], $items, $codigo, $cliente);
        return IO::uno('SELECT * FROM io_pedidos WHERE id = ?', [$p['id']]);
    });
}
try {
    foreach (glob(__DIR__ . '/../sql/*.sql') as $archivo) {
        foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents($archivo))))) as $sql) { $pdo->exec($sql); }
    }
    IO::ejecutar('INSERT INTO io_admins (id,email,nombre,hash,rol) VALUES (1,"admin@ejemplo.test","Admin","no-es-una-clave","admin")');
    $politica = ['titulo' => 'Prueba', 'vimeo_id' => '123', 'duracion_seg' => 100, 'tipo' => 'ventana', 'horas_ventana' => 48, 'cuenta_desde' => 'primera_reproduccion',
        'dias_para_empezar' => 30, 'umbral_pct' => 95, 'retroceso_seg' => 30, 'permitir_adelantar' => 0, 'pausa_max_min' => 60, 'reanudaciones_max' => 2, 'gracia_min' => 120];
    foreach ([1 => 100.01, 2 => 200.02, 3 => 50.03, 4 => 20.04] as $id => $precio) {
        IOAdmin::guardarPolitica(['id' => 1, 'email' => 'admin@ejemplo.test'], ['producto_id' => $id] + $politica, 'Prueba');
        IO::ejecutar('INSERT INTO io_productos (producto_id,slug,titulo,categoria,precio_mxn) VALUES (?,?,?,"conferencias",?)', [$id, 'producto-' . $id, 'Producto ' . $id, $precio]);
    }
    $items = IOTienda::resumen([1, 2])['items'];
    $r = IODescuentos::calcular($items, '', 0);
    verificarDescuento('sin cupón conserva el precio y no requiere sesión', $r['total_centavos'] === 30003 && $r['cupon'] === null);
    cup('DIEZ'); $r = IODescuentos::calcular($items, ' diez ', 101);
    verificarDescuento('normaliza código y calcula porcentaje en centavos', $r['descuento_centavos'] === 3000 && $r['total_centavos'] === 27003);
    verificarDescuento('conserva precio original y precio final por línea', $r['items'][0]['precio_original_mxn'] === 100.01 && $r['items'][0]['precio_mxn'] === 90.01);
    verificarDescuento('subtotal e IVA suman el total final', (int) round(($r['subtotal'] + $r['iva']) * 100) === $r['total_centavos']);
    cup('FIJO', 'importe', 10.01); $r = IODescuentos::calcular($items, 'FIJO', 101);
    verificarDescuento('importe fijo reparte todos los centavos sin perderlos', $r['descuento_centavos'] === 1001 && array_sum(array_column($r['items'], 'descuento_centavos')) === 1001);
    cup('PAREJAS', '2x1'); $r = IODescuentos::calcular(IOTienda::resumen([1, 2, 3])['items'], 'PAREJAS', 101);
    verificarDescuento('2×1 da gratis el menor de cada pareja y cobra el impar', $r['descuento_centavos'] === 10001 && $r['total_centavos'] === 25005);
    $r = IODescuentos::calcular(IOTienda::resumen([1, 2, 3, 4])['items'], 'PAREJAS', 101);
    verificarDescuento('cuatro productos forman dos parejas', $r['descuento_centavos'] === 12005 && $r['total_centavos'] === 25005);
    errorDescuento('2×1 exige dos productos distintos', 'cupon_productos', fn() => IODescuentos::calcular([$items[0]], 'PAREJAS', 101));
    errorDescuento('no acepta duplicar un ID para obtener regalo', 'carrito', fn() => IODescuentos::calcular([$items[0], $items[0]], 'PAREJAS', 101));
    errorDescuento('un cupón requiere cliente autenticado', 'sin_sesion', fn() => IODescuentos::calcular($items, 'DIEZ', 0));
    errorDescuento('rechaza código inexistente sin descuento silencioso', 'cupon', fn() => IODescuentos::calcular($items, 'NOEXISTE', 101));
    cup('INACTIVO', 'porcentaje', 10, ['activo' => 0]);
    errorDescuento('rechaza cupón inactivo', 'cupon', fn() => IODescuentos::calcular($items, 'INACTIVO', 101));
    cup('VENCIDO', 'porcentaje', 10, ['inicio' => date('Y-m-d\TH:i', time() - 7200), 'fin' => date('Y-m-d\TH:i', time() - 3600)]);
    errorDescuento('rechaza cupón vencido', 'cupon', fn() => IODescuentos::calcular($items, 'VENCIDO', 101));
    cup('MINIMO', 'porcentaje', 10, ['minimo_mxn' => 500]);
    errorDescuento('verifica compra mínima', 'cupon_minimo', fn() => IODescuentos::calcular($items, 'MINIMO', 101));
    cup('OTROS', 'porcentaje', 10, ['productos' => '3,4']);
    errorDescuento('restringe los productos elegibles', 'cupon_productos', fn() => IODescuentos::calcular($items, 'OTROS', 101));
    cup('TODOGRATIS', 'porcentaje', 100);
    errorDescuento('el checkout no acepta total gratis o inferior a 10 MXN', 'cupon_minimo', fn() => IODescuentos::calcular($items, 'TODOGRATIS', 101));
    $rebajados = $items; foreach ($rebajados as &$i) { $i['precio_anterior'] = 1000; } unset($i);
    errorDescuento('no acumula sobre rebajas sin autorización', 'cupon_productos', fn() => IODescuentos::calcular($rebajados, 'DIEZ', 101));
    cup('ACUMULA', 'porcentaje', 10, ['incluir_rebajados' => 1]);
    verificarDescuento('puede autorizarse la acumulación con rebajas', IODescuentos::calcular($rebajados, 'ACUMULA', 101)['descuento_centavos'] === 3000);
    cup('UNICO', 'porcentaje', 10, ['max_usos' => 1]);
    $p = pedidoDescuento($items, 'UNICO');
    verificarDescuento('reserva actualiza el total del pedido y las líneas finales', (float) $p['total'] === 270.03 && (float) IO::uno('SELECT SUM(precio_mxn) n FROM io_pedido_items WHERE pedido_id = ?', [$p['id']])['n'] === 270.03);
    errorDescuento('una reserva bloquea el cupo global para otro cliente', 'cupon_limite', fn() => IODescuentos::calcular($items, 'UNICO', 202));
    errorDescuento('un rechazo o el paso del tiempo no libera el cupo', 'cupon_cancelacion', fn() => IO::transaccion(fn() => IODescuentos::liberar((int) $p['id'])));
    errorDescuento('no consume cupón antes de confirmar el pago', 'cupon_pago', fn() => IO::transaccion(fn() => IODescuentos::consumir((int) $p['id'])));
    IO::transaccion(function () use ($p) { IO::ejecutar('UPDATE io_pedidos SET estado="cancelado" WHERE id=?', [$p['id']]); IODescuentos::liberar((int) $p['id']); });
    verificarDescuento('cancelación definitiva libera la reserva', IO::uno('SELECT estado FROM io_descuentos_pedidos WHERE pedido_id=?', [$p['id']])['estado'] === 'liberado');
    $p2 = pedidoDescuento($items, 'UNICO', 202);
    IO::transaccion(function () use ($p2) { IO::ejecutar('UPDATE io_pedidos SET estado="pagado" WHERE id=?', [$p2['id']]); IODescuentos::consumir((int) $p2['id']); IODescuentos::consumir((int) $p2['id']); });
    verificarDescuento('consumo idempotente conserva un solo uso', (int) IO::uno('SELECT COUNT(*) n FROM io_descuentos_pedidos WHERE cupon_id=(SELECT id FROM io_cupones WHERE codigo="UNICO") AND estado="consumido"')['n'] === 1);
    errorDescuento('un cupón consumido sigue agotando el cupo', 'cupon_limite', fn() => IODescuentos::calcular($items, 'UNICO', 303));
    errorDescuento('un pago tardío no reutiliza un cupón ya liberado', 'cupon_liberado', function () use ($p) {
        IO::transaccion(function () use ($p) { IO::ejecutar('UPDATE io_pedidos SET estado="pagado" WHERE id=?', [$p['id']]); IODescuentos::consumir((int) $p['id']); });
    });
    cup('CLIENTE', 'porcentaje', 10, ['max_por_cliente' => 1]); pedidoDescuento($items, 'CLIENTE');
    errorDescuento('reserva cuenta contra límite por cliente', 'cupon_limite', fn() => IODescuentos::calcular($items, 'CLIENTE', 101));
    verificarDescuento('otro cliente conserva su propio límite', IODescuentos::calcular($items, 'CLIENTE', 202)['descuento_centavos'] === 3000);
    cup('CONCURRENTE', 'porcentaje', 10, ['max_usos' => 1]);
    $pdo->beginTransaction();
    $pConcurrente = IOTienda::crearPedido(['id' => 401, 'email' => 'uno@ejemplo.test', 'nombre' => 'Uno'], [1, 2], 'demo');
    IODescuentos::reservar((int) $pConcurrente['id'], $items, 'CONCURRENTE', 401);
    $codigoHijo = 'require_once ' . var_export(__DIR__ . '/../lib/Tienda.php', true) . '; require_once ' . var_export(__DIR__ . '/../lib/Descuentos.php', true) . ';'
        . '$pdo=new PDO(getenv("IO_TEST_DSN")?:"mysql:host=127.0.0.1;port=3307;charset=utf8mb4",getenv("IO_TEST_USER")?:"root",getenv("IO_TEST_PASS")?:"",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);'
        . '$pdo->exec(' . var_export("USE `$base`", true) . '); IO::usarConexion($pdo,["dev"=>true,"dominio"=>"http://127.0.0.1"]);'
        . 'try{IO::transaccion(function(){ $p=IOTienda::crearPedido(["id"=>402,"email"=>"dos@ejemplo.test","nombre"=>"Dos"],[1,2],"demo"); IODescuentos::reservar((int)$p["id"],IOTienda::resumen([1,2])["items"],"CONCURRENTE",402);}); echo "cobro-permitido";}catch(IOError $e){echo $e->codigo;}';
    $proceso = proc_open([PHP_BINARY, '-r', $codigoHijo], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proceso)) { throw new RuntimeException('No pudo iniciarse la prueba concurrente.'); }
    fclose($pipes[0]); usleep(200000);
    verificarDescuento('otro checkout espera mientras la reserva tiene bloqueado el cupón', proc_get_status($proceso)['running']);
    $pdo->commit();
    $respuestaHijo = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errorHijo = stream_get_contents($pipes[2]); fclose($pipes[2]); proc_close($proceso);
    if ($respuestaHijo !== 'cupon_limite' || $errorHijo !== '') { echo 'Diagnóstico de proceso local: ' . $respuestaHijo . ' ' . $errorHijo . "\n"; }
    verificarDescuento('dos checkouts concurrentes no superan el último cupo', $respuestaHijo === 'cupon_limite' && $errorHijo === '' &&
        (int) IO::uno('SELECT COUNT(*) n FROM io_descuentos_pedidos WHERE cupon_id=(SELECT id FROM io_cupones WHERE codigo="CONCURRENTE") AND estado="reservado"')['n'] === 1);
    $antes = (int) IO::uno('SELECT COUNT(*) n FROM io_cupones')['n'];
    $pdo->exec("CREATE TRIGGER io_cupon_bitacora_falla BEFORE INSERT ON io_bitacora FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fallo de prueba'");
    try { cup('ROLLBACK'); } catch (Throwable $e) {}
    $pdo->exec('DROP TRIGGER io_cupon_bitacora_falla');
    verificarDescuento('si falla bitácora se revierte el cupón', (int) IO::uno('SELECT COUNT(*) n FROM io_cupones')['n'] === $antes);
    errorDescuento('no permite administrar cupón con rol falsificado', 'sin_permiso', fn() => IODescuentos::guardar(['id' => 99, 'rol' => 'admin'], [], 'Prueba'));
    verificarDescuento('los cambios de cupones quedan en bitácora', (int) IO::uno('SELECT COUNT(*) n FROM io_bitacora WHERE accion="cupon"')['n'] >= 10);
    cup('INTEGRADO', 'porcentaje', 10, ['max_por_cliente' => 1]);
    $integrado = IOTienda::crearPedido(['id' => 505, 'email' => 'integracion@ejemplo.test', 'nombre' => 'Integración'], [1, 2], 'demo', 'INTEGRADO');
    verificarDescuento('crearPedido integra la reserva y el importe final antes de cobrar', (float) $integrado['total'] === 270.03 &&
        IO::uno('SELECT estado FROM io_descuentos_pedidos WHERE pedido_id=?', [$integrado['id']])['estado'] === 'reservado');
    IOTienda::confirmarPago((int) $integrado['id'], 'demo_descuento', 'card', 27003);
    IOTienda::confirmarPago((int) $integrado['id'], 'demo_descuento', 'card', 27003);
    verificarDescuento('confirmarPago consume una vez y otorga ambos productos', IO::uno('SELECT estado FROM io_descuentos_pedidos WHERE pedido_id=?', [$integrado['id']])['estado'] === 'consumido'
        && (int) IO::uno('SELECT COUNT(*) n FROM io_accesos WHERE pedido_ref=?', [$integrado['folio']])['n'] === 2);
    $antesPedidos = (int) IO::uno('SELECT COUNT(*) n FROM io_pedidos')['n'];
    errorDescuento('el checkout rechaza un descuento que deja total inferior al mínimo', 'cupon_minimo', fn() => IOTienda::crearPedido(['id' => 606, 'email' => 'minimo@ejemplo.test', 'nombre' => 'Mínimo'], [1, 2], 'demo', 'TODOGRATIS'));
    verificarDescuento('descuento inválido revierte pedido y líneas en vez de crear compra parcial', (int) IO::uno('SELECT COUNT(*) n FROM io_pedidos')['n'] === $antesPedidos);
} finally { if ($pdo->inTransaction()) { $pdo->rollBack(); } $pdo->exec("DROP DATABASE `$base`"); }
echo "Resultado: $ok correctas, $fallos fallidas\n";
exit($fallos ? 1 : 0);
