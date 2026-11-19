<?php
declare(strict_types=1);
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../lib/Facturacion.php';
header('Cache-Control: no-store');
$cliente = IO::clienteActual(); $pedidoId = (int) ($_GET['pedido'] ?? 0); $factura = null; $pedido = null;
if ($cliente) { $pedido = IO::uno('SELECT id,folio,estado,total FROM io_pedidos WHERE id = ? AND cliente_id = ?', [$pedidoId, (int) $cliente['id']]); }
if ($cliente && $pedido && IOFacturacion::habilitada()) { $factura = IOFacturacion::dePedido($cliente, $pedidoId); }
if ($cliente && !$pedido) { http_response_code(404); }
encabezado('Facturación', '');
?>
<section class="container py-5" style="max-width:900px">
<h1>Factura de tu compra</h1>
<?php if (!$cliente): ?>
  <p>Inicia sesión en la cuenta con la que hiciste tu compra.</p><a class="iot-btn" href="/?io_acceso=login&amp;io_destino=pedidos">Iniciar sesión</a>
<?php elseif (!$pedido): ?>
  <p>No encontramos este pedido en tu cuenta.</p>
<?php elseif (!IOFacturacion::habilitada()): ?>
  <p>La facturación en línea todavía no está habilitada. Contacta al equipo e indica el folio <?= e($pedido['folio']) ?>.</p>
<?php elseif ($pedido['estado'] !== 'pagado'): ?>
  <p>La factura estará disponible después de confirmar el pago del pedido <?= e($pedido['folio']) ?>.</p>
<?php else: ?>
  <p>Pedido <b><?= e($pedido['folio']) ?></b> · <?= e(IOTienda::dinero((float) $pedido['total'])) ?> MXN</p>
  <?php if ($factura): ?>
    <p>Estado: <b><?= e(['reservada' => 'Solicitud registrada', 'pendiente' => 'En proceso de emisión o conciliación', 'emitida' => 'Emitida', 'cancelacion_pendiente' => 'Cancelación pendiente ante el SAT', 'cancelada' => 'Cancelada', 'error' => 'Datos fiscales rechazados'][$factura['estado']] ?? $factura['estado']) ?></b></p>
    <?php if ($factura['uuid']): ?><p>Folio fiscal: <?= e($factura['uuid']) ?></p><?php endif; ?>
    <?php if (in_array($factura['estado'], ['emitida', 'cancelacion_pendiente', 'cancelada'], true)): ?>
      <p><a class="iot-btn" href="/io/api/facturacion.php?accion=descargar&amp;pedido=<?= $pedidoId ?>&amp;formato=pdf">Descargar PDF</a>
      <a class="iot-btn sec" href="/io/api/facturacion.php?accion=descargar&amp;pedido=<?= $pedidoId ?>&amp;formato=xml">Descargar XML</a></p>
    <?php endif; ?>
    <form method="post" action="/io/api/facturacion.php"><input type="hidden" name="csrf" value="<?= e(IOTienda::csrf()) ?>"><input type="hidden" name="pedido" value="<?= $pedidoId ?>"><input type="hidden" name="accion" value="consultar"><button class="iot-btn sec">Actualizar estado</button></form>
    <?php if (!in_array($factura['estado'], ['error'], true)): ?><p class="mt-3">La solicitud conserva tus datos fiscales originales. Si necesitas corregir un comprobante emitido, contacta al equipo.</p><?php endif; ?>
  <?php endif; ?>
  <?php if (!$factura || $factura['estado'] === 'error'): ?>
  <p class="mt-4">Usa los datos exactos de tu constancia de situación fiscal. Se enviarán al proveedor de facturación para emitir el CFDI 4.0. Este formulario es para receptores nacionales con RFC propio.</p>
  <form method="post" action="/io/api/facturacion.php" class="row g-3">
    <input type="hidden" name="csrf" value="<?= e(IOTienda::csrf()) ?>"><input type="hidden" name="pedido" value="<?= $pedidoId ?>"><input type="hidden" name="accion" value="solicitar">
    <div class="col-md-6"><label class="form-label" for="rfc">RFC</label><input class="form-control" name="rfc" id="rfc" required minlength="12" maxlength="13" autocomplete="off"></div>
    <div class="col-md-6"><label class="form-label" for="cp">Código postal fiscal</label><input class="form-control" name="codigo_postal" id="cp" required pattern="[0-9]{5}" maxlength="5" inputmode="numeric"></div>
    <div class="col-12"><label class="form-label" for="razon">Nombre o razón social</label><input class="form-control" name="razon_social" id="razon" required maxlength="254"></div>
    <div class="col-md-6"><label class="form-label" for="regimen">Clave de régimen fiscal</label><input class="form-control" name="regimen" id="regimen" required pattern="[0-9]{3}" maxlength="3" inputmode="numeric" aria-describedby="ayudaRegimen"><small id="ayudaRegimen">Usa la clave de tres dígitos de tu constancia.</small></div>
    <div class="col-md-6"><label class="form-label" for="uso">Uso del CFDI</label><select class="form-select" name="uso_cfdi" id="uso" required><option value="">Selecciona el uso</option><option value="G03">G03 · Gastos en general</option><option value="G01">G01 · Adquisición de mercancías</option><option value="S01">S01 · Sin efectos fiscales</option></select></div>
    <div class="col-12"><button class="iot-btn">Solicitar factura</button></div>
  </form>
  <?php endif; ?>
<?php endif; ?>
</section>
<?php pie();
