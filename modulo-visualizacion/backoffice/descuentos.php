<?php
declare(strict_types=1);
require __DIR__ . '/_inc.php';
require_once __DIR__ . '/../lib/Descuentos.php';
$a = exigir('politicas');
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_ok();
    try {
        $id = IODescuentos::guardar($a, $_POST, (string) ($_POST['motivo'] ?? ''));
        flash('Cupón guardado. Los descuentos de pedidos ya creados conservan sus condiciones.');
        header('Location: descuentos.php?id=' . $id); exit;
    } catch (IOError $e) { $error = $e->getMessage(); }
    catch (Throwable $e) { error_log('[IO cupones] no se pudo guardar el cupón'); $error = 'No se pudo guardar. Comprueba que el código no esté repetido.'; }
}
$d = isset($_GET['id']) ? IO::uno('SELECT * FROM io_cupones WHERE id = ?', [(int) $_GET['id']]) : null;
$d = $d ?: ['id' => 0, 'codigo' => '', 'tipo' => 'porcentaje', 'valor' => '', 'inicio' => '', 'fin' => '', 'minimo_mxn' => 0,
    'max_usos' => '', 'max_por_cliente' => 1, 'productos' => '[]', 'incluir_rebajados' => 0, 'activo' => 0];
if ($error !== '') { $d = $_POST + $d; $d['activo'] = !empty($_POST['activo']); $d['incluir_rebajados'] = !empty($_POST['incluir_rebajados']); }
$productos = $error !== '' ? (string) ($_POST['productos'] ?? '') : implode(',', json_decode((string) $d['productos'], true) ?: []);
$lista = IO::todos('SELECT c.*, (SELECT COUNT(*) FROM io_descuentos_pedidos r WHERE r.cupon_id=c.id AND r.estado="reservado") reservados,
    (SELECT COUNT(*) FROM io_descuentos_pedidos r WHERE r.cupon_id=c.id AND r.estado="consumido") consumidos FROM io_cupones c ORDER BY c.creado DESC, c.id DESC LIMIT 200');
pagina('Cupones y promociones 2×1', function () use ($d, $error, $productos, $lista) {
    $fecha = static function ($v) { return $v ? date('Y-m-d\TH:i', strtotime((string) $v)) : ''; };
?>
<div class="bo-card">
  <h2><?= (int) $d['id'] ? 'Editar cupón' : 'Nuevo cupón' ?></h2>
  <p>Los descuentos se validan en el servidor. Una reserva cuenta contra el cupo hasta pagarse o cancelarse definitivamente el pedido en la pasarela.</p>
  <?php if ($error !== ''): ?><div class="bo-flash error" role="alert"><?= h($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_input() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
    <div class="row g-3">
      <div class="col-md-4"><label class="form-label">Código</label><input class="form-control" name="codigo" maxlength="40" pattern="[A-Za-z0-9][A-Za-z0-9_-]{2,39}" required value="<?= h($d['codigo']) ?>"></div>
      <div class="col-md-4"><label class="form-label">Tipo</label><select class="form-select" name="tipo"><?php foreach (['porcentaje' => 'Porcentaje', 'importe' => 'Importe MXN con IVA', '2x1' => '2×1: gratis el más barato de cada pareja'] as $k => $t): ?><option value="<?= h($k) ?>" <?= $d['tipo'] === $k ? 'selected' : '' ?>><?= h($t) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-4"><label class="form-label">Valor (% o MXN; 0 para 2×1)</label><input class="form-control" name="valor" type="number" min="0" step="0.01" value="<?= h($d['valor']) ?>"></div>
      <div class="col-md-4"><label class="form-label">Inicio (hora de Ciudad de México)</label><input class="form-control" name="inicio" type="datetime-local" required value="<?= h($fecha($d['inicio'])) ?>"></div>
      <div class="col-md-4"><label class="form-label">Fin (hora de Ciudad de México)</label><input class="form-control" name="fin" type="datetime-local" required value="<?= h($fecha($d['fin'])) ?>"></div>
      <div class="col-md-4"><label class="form-label">Compra mínima MXN con IVA</label><input class="form-control" name="minimo_mxn" type="number" min="0" step="0.01" value="<?= h($d['minimo_mxn']) ?>"></div>
      <div class="col-md-4"><label class="form-label">Cupo global (vacío = sin límite)</label><input class="form-control" name="max_usos" type="number" min="1" value="<?= h($d['max_usos']) ?>"></div>
      <div class="col-md-4"><label class="form-label">Máximo por cliente</label><input class="form-control" name="max_por_cliente" type="number" min="1" required value="<?= h($d['max_por_cliente']) ?>"></div>
      <div class="col-md-4"><label class="form-label">IDs de productos, separados por coma (vacío = todos)</label><input class="form-control" name="productos" value="<?= h($productos) ?>"></div>
      <div class="col-md-6"><label><input type="checkbox" name="activo" value="1" <?= !empty($d['activo']) ? 'checked' : '' ?>> Activo</label></div>
      <div class="col-md-6"><label><input type="checkbox" name="incluir_rebajados" value="1" <?= !empty($d['incluir_rebajados']) ? 'checked' : '' ?>> Permitir combinar con precios ya rebajados</label></div>
      <div class="col-12"><label class="form-label">Motivo del cambio (queda en bitácora)</label><input class="form-control" name="motivo" maxlength="120" required></div>
    </div>
    <button class="bo-btn mt-3">Guardar cupón</button> <a class="bo-btn sec mt-3" href="descuentos.php">Nuevo</a>
  </form>
</div>
<div class="bo-card"><h2>Cupones configurados</h2><div class="table-responsive"><table class="table"><thead><tr><th>Código</th><th>Regla</th><th>Vigencia</th><th>Reservados</th><th>Consumidos</th><th>Activo</th></tr></thead><tbody>
<?php foreach ($lista as $c): ?><tr><td><a href="?id=<?= (int) $c['id'] ?>"><?= h($c['codigo']) ?></a></td><td><?= h($c['tipo']) ?> <?= $c['tipo'] !== '2x1' ? h($c['valor']) : '' ?></td><td><?= h(fecha($c['inicio'])) ?> — <?= h(fecha($c['fin'])) ?></td><td><?= (int) $c['reservados'] ?></td><td><?= (int) $c['consumidos'] ?></td><td><?= $c['activo'] ? 'Sí' : 'No' ?></td></tr><?php endforeach; ?>
<?php if (!$lista): ?><tr><td colspan="6">Todavía no hay cupones. No se publica ninguna promoción automáticamente.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php }, 'descuentos.php');
