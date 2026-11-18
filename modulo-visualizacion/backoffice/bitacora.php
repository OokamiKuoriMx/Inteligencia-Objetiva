<?php
declare(strict_types=1);
require __DIR__ . '/_inc.php';
exigir('bitacora');

$filas = IO::todos("SELECT b.*, a.cliente_email, p.titulo FROM io_bitacora b
  LEFT JOIN io_accesos a ON a.id = b.acceso_id LEFT JOIN io_politicas p ON p.producto_id = COALESCE(b.producto_id, a.producto_id)
  ORDER BY b.fecha DESC LIMIT 300");

const ACCIONES = ['extender' => 'Extendió acceso', 'reiniciar' => 'Reinició visualización', 'revocar' => 'Revocó acceso',
    'cerrar_sesion' => 'Cerró reproducción', 'otorgar' => 'Otorgó acceso', 'politica' => 'Cambió reglas'];

pagina('Bitácora', function () use ($filas) { ?>
  <div class="bo-card">
    <h2>Últimos <?= count($filas) ?> movimientos del equipo</h2>
    <?php if (!$filas): ?><div class="bo-vacio">Sin movimientos todavía.</div><?php else: ?>
    <div class="bo-scroll"><table class="bo-tabla"><thead><tr><th>Fecha</th><th>Quién</th><th>Acción</th><th>Alumno</th><th>Contenido</th><th>Motivo</th><th>Detalle</th></tr></thead><tbody>
    <?php foreach ($filas as $b): ?>
      <tr><td><?= fecha($b['fecha']) ?></td><td><?= h($b['admin_email']) ?></td><td><?= h(ACCIONES[$b['accion']] ?? $b['accion']) ?></td>
        <td><?= h($b['cliente_email'] ?? '—') ?></td><td><?= h($b['titulo'] ?? '—') ?></td><td><?= h($b['motivo']) ?></td>
        <td class="bo-nota" style="max-width:320px;word-break:break-word"><?= h($b['detalle']) ?></td></tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?>
  </div>
<?php }, 'bitacora.php');
