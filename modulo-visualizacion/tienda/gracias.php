<?php
declare(strict_types=1);
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/_migas.php';

header('Cache-Control: no-store');

$cliente = IO::clienteActual();
$p = $cliente ? IOTienda::pedidoPorFolio((string) ($_GET['folio'] ?? ''), (int) $cliente['id']) : null;
$reembolso = $p ? IO::uno('SELECT MAX(monto_centavos) monto FROM io_reembolsos WHERE pedido_id = ? AND estado = "completo"', [$p['id']]) : null;
$ficha = null; $errorFicha = false;
if ($p && in_array($p['estado'], ['pendiente', 'fallido'], true) && $p['pasarela'] === 'stripe' && $p['pasarela_ref']) {
    try {
        $ficha = IOTienda::fichaOxxo($p, IOStripe::consultarIntento($p['pasarela_ref']));
        $p = IOTienda::pedidoPorFolio($p['folio'], (int) $cliente['id']);
    } catch (Throwable $e) {
        error_log('[IO ficha OXXO] ' . $e->getMessage());
        $errorFicha = true;
    }
}
if ($p && $p['estado'] === 'pagado' && ($_SESSION['io_checkout_folio'] ?? '') === $p['folio']) {
    // El retorno de una compra antigua no debe borrar productos añadidos después.
    foreach (IO::todos('SELECT producto_id FROM io_pedido_items WHERE pedido_id = ?', [$p['id']]) as $comprado) { IOTienda::quitar((int) $comprado['producto_id']); }
    unset($_SESSION['io_cupon'], $_SESSION['io_checkout_folio']);
}
$items = $p ? IO::todos('SELECT i.titulo, i.precio_mxn, i.acceso_id, a.estado, a.vence, a.limite_inicio, pol.tipo, pol.horas_ventana
    FROM io_pedido_items i LEFT JOIN io_accesos a ON a.id = i.acceso_id LEFT JOIN io_politicas pol ON pol.producto_id = i.producto_id WHERE i.pedido_id = ?', [$p['id']]) : [];

encabezado('Confirmación de compra', '');
?>
<section class="container iot-carrito-pag" style="max-width:820px">
  <?= migas([['texto' => 'Inicio', 'url' => ruta('inicio')], ['texto' => 'Carrito', 'url' => ruta('carrito')], ['texto' => 'Confirmación']]) ?>
  <div class="iot-pasos mt-5"><span><i>1</i>Resumen</span><span><i>2</i>Pago</span><span class="on"><i>3</i>Confirmación</span></div>
  <?php if (!$p): ?>
    <div class="iot-vacio">No encontramos este pedido en tu cuenta.</div>
  <?php elseif ($reembolso && (int) $reembolso['monto'] > 0): ?>
    <div class="iot-cabecera pt-2"><h1>Tu compra fue reembolsada</h1><p>Pedido <b><?= e($p['folio']) ?></b> · Devolución confirmada de <?= e(IOTienda::dinero((int) $reembolso['monto'] / 100)) ?> MXN.</p><p>Los accesos de esta compra se desactivaron. Tus otras compras y membresías conservan sus propias condiciones.</p></div>
    <a class="iot-btn sec" href="/io/tienda/mis-pedidos.php">Ver mis pedidos</a>
  <?php elseif ($p['estado'] === 'cancelado'): ?>
    <div class="iot-cabecera pt-2"><h1>Este pedido fue cancelado</h1><p>Pedido <b><?= e($p['folio']) ?></b>. Este pedido ya no está disponible para completar el pago y no activa contenido.</p></div>
    <a class="iot-btn" href="<?= e(ruta('carrito')) ?>">Volver al carrito</a>
  <?php elseif ($p['estado'] === 'pagado'): ?>
    <div class="iot-cabecera pt-2"><h1>Gracias, ya es tuyo</h1><p>Pedido <b><?= e($p['folio']) ?></b> · <?= e(IOTienda::dinero((float) $p['total'])) ?> MXN. Tu contenido está disponible en tu biblioteca.</p></div>
    <?php foreach ($items as $i): ?><div class="iot-linea"><div class="info"><b><?= e($i['titulo']) ?></b><small><?php if ($i['tipo'] === 'permanente'): ?>Acceso permanente, listo en tu biblioteca.<?php elseif ($i['vence']): ?>Vence el <?= IO::htmlFecha($i['vence']) ?><?php else: ?><?= $i['tipo'] === 'unica' ? 'Visualización única.' : 'Al dar play tendrás ' . (int) $i['horas_ventana'] . ' h para verlo.' ?> Empieza antes del <?= IO::htmlFecha($i['limite_inicio']) ?><?php endif; ?></small></div><span><?= e(IOTienda::dinero((float) $i['precio_mxn'])) ?></span></div><?php endforeach; ?>
    <div class="d-flex gap-2 flex-wrap mt-4"><a class="iot-btn" href="<?= e(ruta('biblioteca')) ?>">Ir a mi biblioteca</a><a class="iot-btn sec" href="<?= e(ruta('catalogo')) ?>">Seguir explorando</a></div>
  <?php elseif ($p['estado'] === 'pendiente' && ($ficha || $p['metodo'] === 'oxxo')): ?>
    <div class="iot-cabecera pt-2"><h1>Tu ficha de pago OXXO</h1><p>Tu contenido se activa cuando recibamos la confirmación del pago. La confirmación puede tardar hasta dos días hábiles.</p></div>
    <div class="iot-ficha-oxxo">
      <div class="d-flex justify-content-between"><span>Monto a pagar</span><b><?= e(IOTienda::dinero((float) $p['total'])) ?> MXN</b></div>
      <?php if ($ficha && $ficha['vence'] > time()): ?>
        <div class="ref"><?= e($ficha['numero']) ?></div>
        <p>Válida hasta <?= IO::htmlFecha(date('Y-m-d H:i:s', $ficha['vence'])) ?>.</p>
        <a class="iot-btn" href="<?= e($ficha['url']) ?>" target="_blank" rel="noopener noreferrer">Abrir ficha y código de barras</a>
      <?php elseif ($ficha): ?>
        <p>Esta ficha venció. Si ya pagaste, espera la confirmación. Si no pagaste, vuelve al carrito para generar una nueva.</p>
      <?php elseif ($p['pasarela'] === 'demo'): ?>
        <p>Demostración local: esta ficha no sirve para realizar pagos.</p>
      <?php else: ?>
        <p>No pudimos recuperar tu ficha. Actualiza esta página para intentarlo de nuevo.</p>
      <?php endif; ?>
    </div>
    <a class="iot-btn sec mt-3" href="?folio=<?= e(rawurlencode($p['folio'])) ?>">Actualizar estado del pago</a>
    <?php if ($p['pasarela'] === 'demo'): ?>
      <form class="mt-3" onsubmit="event.preventDefault();fetch('/io/api/tienda.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({accion:'pagar_demo',metodo:'oxxo_pagado',folio:<?= e(json_encode($p['folio'])) ?>,csrf:<?= e(json_encode(IOTienda::csrf())) ?>})}).then(function(){location.reload()})">
        <button class="iot-btn sec">Simular que ya pagué en OXXO (demo)</button>
      </form>
    <?php endif; ?>
  <?php elseif ($p['estado'] === 'fallido'): ?>
    <div class="iot-cabecera pt-2"><h1>El pago no se completó</h1><p>Tu banco rechazó el cargo del pedido <?= e($p['folio']) ?>. No se te cobró nada.</p></div>
    <a class="iot-btn" href="<?= e(ruta('carrito')) ?>">Intentar de nuevo</a>
  <?php else: ?>
    <div class="iot-cabecera pt-2"><h1>Estamos confirmando tu pago</h1><p>Pedido <?= e($p['folio']) ?>. Esta página se actualiza sola.</p></div>
    <?php if ($errorFicha): ?><p>No pudimos consultar la información del pago. Volveremos a intentarlo.</p><?php endif; ?>
    <script>setTimeout(function(){location.reload()},15000)</script>
  <?php endif; ?>
</section>
<?php pie();
