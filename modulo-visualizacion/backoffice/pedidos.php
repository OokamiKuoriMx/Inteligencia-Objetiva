<?php
declare(strict_types=1);
require __DIR__ . '/_inc.php';
require_once __DIR__ . '/../lib/Pedidos.php';
require_once __DIR__ . '/../lib/Facturacion.php';
$actor = exigir('pedidos');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigir('reembolsos'); csrf_ok();
    $id = (int) ($_POST['pedido_id'] ?? 0);
    try {
        $accion = (string) ($_POST['accion'] ?? '');
        $motivo = trim((string) ($_POST['motivo'] ?? ''));
        if ($accion === 'reembolsar') { $r = IOPedidos::reembolsar($actor, $id, $motivo); flash('Estado de la devolución: ' . $r['estado'] . '.'); }
        elseif ($accion === 'conciliar') { $r = IOPedidos::conciliar($actor, (int) ($_POST['reembolso_id'] ?? 0)); flash('Estado consultado: ' . $r['estado'] . '.'); }
        elseif ($accion === 'cancelar_pedido') { IOPedidos::cancelarPendiente($actor, $id, $motivo); flash('Pedido cancelado y reserva de cupón liberada.'); }
        elseif ($accion === 'cancelar_factura') {
            if ($actor['rol'] !== 'admin') { throw new IOError('sin_permiso', 'Solo un administrador puede solicitar la cancelación fiscal.', 403); }
            $f = IOFacturacion::cancelar($id, (string) ($_POST['motivo_sat'] ?? ''), trim((string) ($_POST['sustitucion'] ?? '')) ?: null, $actor, $motivo);
            flash('Estado de la factura: ' . $f['estado'] . '.');
        } else { throw new IOError('accion', 'Acción no válida.'); }
    } catch (IOError $e) { flash($e->getMessage(), 'error'); }
    catch (Throwable $e) { error_log('[IO pedidos] Operación no completada: ' . get_class($e)); flash('No se confirmó la operación. Consulta su estado antes de repetirla.', 'error'); }
    header('Location: pedidos.php?id=' . $id); exit;
}
$id = (int) ($_GET['id'] ?? 0);
$pedido = $id ? IO::uno('SELECT * FROM io_pedidos WHERE id = ?', [$id]) : null;
$q = trim((string) ($_GET['q'] ?? ''));
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$pedidos = IO::todos('SELECT * FROM io_pedidos WHERE folio LIKE ? OR email LIKE ? ORDER BY id DESC LIMIT 30 OFFSET ' . (($pagina - 1) * 30), ['%' . $q . '%', '%' . $q . '%']);
pagina('Pedidos y devoluciones', function () use ($pedidos, $pedido, $actor, $pagina, $q) {
?>
<div class="bo-card p-4"><form method="get" class="d-flex gap-2"><input class="form-control" name="q" placeholder="Buscar por folio o correo" value="<?= h($q) ?>"><button class="bo-btn">Buscar</button></form>
<div class="table-responsive mt-3"><table class="table"><thead><tr><th>Pedido</th><th>Cliente</th><th>Total</th><th>Estado</th><th>Fecha</th></tr></thead><tbody>
<?php foreach ($pedidos as $p): ?><tr><td><a href="?id=<?= (int) $p['id'] ?>"><?= h($p['folio']) ?></a></td><td><?= h($p['email']) ?></td><td><?= h(IOTienda::dinero((float) $p['total'])) ?> MXN</td><td><?= h($p['estado']) ?></td><td><?= h(fecha($p['creado'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div><a href="?pagina=<?= $pagina + 1 ?>&amp;q=<?= h(rawurlencode($q)) ?>">Siguiente página</a></div>
<?php if (!$pedido) { return; }
$items = IO::todos('SELECT * FROM io_pedido_items WHERE pedido_id = ?', [$pedido['id']]);
$devoluciones = IO::todos('SELECT * FROM io_reembolsos WHERE pedido_id = ? ORDER BY id DESC', [$pedido['id']]);
$factura = IO::uno('SELECT estado,uuid FROM io_facturas WHERE pedido_id = ?', [$pedido['id']]);
?>
<section class="bo-card p-4 mt-4"><h2><?= h($pedido['folio']) ?></h2><p><?= h($pedido['email']) ?> · <?= h($pedido['estado']) ?></p>
<?php foreach ($items as $item): ?><p><?= h($item['titulo']) ?> — <?= h(IOTienda::dinero((float) $item['precio_mxn'])) ?></p><?php endforeach; ?>
<?php if ($factura): ?><p>CFDI: <?= h($factura['estado']) ?> · <?= h($factura['uuid'] ?? '') ?></p><?php endif; ?>
<?php foreach ($devoluciones as $r): ?><p>Devolución #<?= (int) $r['id'] ?>: <?= h($r['estado']) ?> · <?= h(IOTienda::dinero((int) $r['monto_centavos'] / 100)) ?> · <?= h($r['motivo']) ?></p>
<?php if (puede('reembolsos') && in_array($r['estado'], ['solicitado','pendiente'], true)): ?><form method="post" class="mb-3"><?= csrf_input() ?><input type="hidden" name="accion" value="conciliar"><input type="hidden" name="pedido_id" value="<?= (int) $pedido['id'] ?>"><input type="hidden" name="reembolso_id" value="<?= (int) $r['id'] ?>"><button class="bo-btn sec">Consultar devolución en Stripe</button></form><?php endif; endforeach; ?>
<?php if (puede('reembolsos')): ?>
<form method="post" class="mt-4"><?= csrf_input() ?><input type="hidden" name="pedido_id" value="<?= (int) $pedido['id'] ?>">
<label class="form-label">Motivo de la operación<input class="form-control" name="motivo" required maxlength="120"></label>
<?php if ($pedido['estado'] === 'pagado'): ?><p>La devolución es por el total pagado. El acceso se revoca al confirmarse la devolución. Si existe CFDI, primero debe confirmarse su cancelación fiscal.</p><button name="accion" value="reembolsar" class="bo-btn">Solicitar devolución completa</button>
<?php elseif (in_array($pedido['estado'], ['pendiente','fallido'], true)): ?><p>Cancela primero el intento en Stripe y después libera la reserva del cupón.</p><button name="accion" value="cancelar_pedido" class="bo-btn">Cancelar pedido pendiente</button><?php endif; ?>
<?php if ($factura && $factura['estado'] !== 'cancelada' && $actor['rol'] === 'admin'): ?><hr><label class="form-label">Motivo SAT<select name="motivo_sat" class="form-select"><option value="">Seleccionar</option><option value="01">01 · Error con relación</option><option value="02">02 · Error sin relación</option><option value="03">03 · No se llevó a cabo la operación</option><option value="04">04 · Operación nominativa relacionada en factura global</option></select></label><label class="form-label">UUID de sustitución, cuando proceda<input class="form-control" name="sustitucion" maxlength="40"></label><button name="accion" value="cancelar_factura" class="bo-btn sec">Solicitar cancelación del CFDI</button><?php endif; ?>
</form><?php endif; ?></section>
<?php }, 'pedidos.php');
