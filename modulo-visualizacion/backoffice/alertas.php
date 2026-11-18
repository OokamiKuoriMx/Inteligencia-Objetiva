<?php
declare(strict_types=1);
require __DIR__ . '/_inc.php';
exigir('ver');

$ver = ($_GET['ver'] ?? '') === 'todas';
$alertas = IO::todos("SELECT al.*, a.cliente_email, a.cliente_nombre, p.titulo FROM io_alertas al
  LEFT JOIN io_accesos a ON a.id = al.acceso_id LEFT JOIN io_politicas p ON p.producto_id = a.producto_id
  " . ($ver ? '' : 'WHERE al.revisada = 0') . " ORDER BY al.creada DESC LIMIT 200");

const TIPO_ALERTA = [
    'doble_dispositivo' => 'Abierto en otro dispositivo', 'retroceso_bloqueado' => 'Retroceso bloqueado',
    'adelanto_bloqueado' => 'Intento de adelantar', 'reanudaciones_agotadas' => 'Reanudaciones agotadas', 'ip_compartida' => 'IP compartida',
];

pagina('Alertas', function () use ($alertas, $ver) { ?>
  <div class="bo-filtros"><a class="bo-btn <?= $ver ? 'sec' : '' ?> mini" href="alertas.php">Pendientes</a><a class="bo-btn <?= $ver ? '' : 'sec' ?> mini" href="alertas.php?ver=todas">Todas</a></div>
  <div class="bo-card">
    <?php if (!$alertas): ?><div class="bo-vacio">Sin alertas.</div><?php else: ?>
    <div class="bo-scroll"><table class="bo-tabla"><thead><tr><th>Alerta</th><th>Alumno</th><th>Contenido</th><th>Detalle</th><th>Cuándo</th><th></th></tr></thead><tbody>
    <?php foreach ($alertas as $al): ?>
      <tr>
        <td><span class="bo-chip <?= $al['tipo'] === 'doble_dispositivo' || $al['tipo'] === 'adelanto_bloqueado' ? 'peligro' : 'aviso' ?>"><?= h(TIPO_ALERTA[$al['tipo']] ?? $al['tipo']) ?></span></td>
        <td><?php if ($al['cliente_id']): ?><a href="accesos.php?cliente=<?= (int) $al['cliente_id'] ?>"><?= h($al['cliente_nombre'] ?: $al['cliente_email']) ?></a><?php endif; ?><span class="sub"><?= h($al['cliente_email'] ?? '') ?></span></td>
        <td><?= h($al['titulo'] ?? '—') ?></td>
        <td class="bo-nota"><?= h($al['detalle'] ?? '') ?></td>
        <td><?= hace($al['creada']) ?></td>
        <td><?php if (!(int) $al['revisada']): ?>
          <form method="post" action="accion.php" class="m-0"><?= csrf_input() ?><input type="hidden" name="accion" value="alerta_revisada"><input type="hidden" name="alerta_id" value="<?= (int) $al['id'] ?>"><input type="hidden" name="volver" value="alertas.php"><button class="bo-btn sec mini">Marcar revisada</button></form>
        <?php else: ?><span class="bo-nota">Revisada</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?>
  </div>
<?php }, 'alertas.php');
