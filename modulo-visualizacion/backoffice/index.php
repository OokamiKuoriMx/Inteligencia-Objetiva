<?php
declare(strict_types=1);
require __DIR__ . '/_inc.php';
exigir('ver');

$k = IO::uno("SELECT
  (SELECT COUNT(*) FROM io_sesiones WHERE activa = 1 AND ultimo_latido > DATE_SUB(NOW(), INTERVAL 60 SECOND)) AS en_vivo,
  (SELECT COUNT(*) FROM io_accesos WHERE estado IN ('por_empezar','activo','en_gracia')) AS activos,
  (SELECT COUNT(*) FROM io_accesos WHERE estado = 'activo' AND vence BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR)) AS por_vencer,
  (SELECT COUNT(*) FROM io_accesos WHERE (estado = 'visto' AND visto_en >= CURDATE()) OR (estado = 'vencido' AND vence >= CURDATE())) AS vistos_hoy,
  (SELECT COUNT(*) FROM io_accesos WHERE estado = 'por_empezar') AS sin_empezar,
  (SELECT COUNT(*) FROM io_alertas WHERE revisada = 0) AS alertas");

$vivo = IO::todos("SELECT s.*, a.cliente_nombre, a.cliente_email, p.titulo, p.tipo
  FROM io_sesiones s JOIN io_accesos a ON a.id = s.acceso_id JOIN io_politicas p ON p.producto_id = a.producto_id
  WHERE s.activa = 1 AND s.ultimo_latido > DATE_SUB(NOW(), INTERVAL 60 SECOND) ORDER BY s.ultimo_latido DESC LIMIT 8");

$porVencer = IO::todos("SELECT a.*, p.titulo FROM io_accesos a JOIN io_politicas p ON p.producto_id = a.producto_id
  WHERE a.estado = 'activo' AND a.vence BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR) ORDER BY a.vence LIMIT 8");

$alertas = IO::todos("SELECT al.*, a.cliente_email, p.titulo FROM io_alertas al
  LEFT JOIN io_accesos a ON a.id = al.acceso_id LEFT JOIN io_politicas p ON p.producto_id = a.producto_id
  WHERE al.revisada = 0 ORDER BY al.creada DESC LIMIT 6");

pagina('Tablero', function () use ($k, $vivo, $porVencer, $alertas) { ?>
  <div class="bo-kpis">
    <a class="bo-kpi vivo" href="en-vivo.php"><span>Viendo ahora</span><b><?= (int) $k['en_vivo'] ?></b></a>
    <a class="bo-kpi" href="accesos.php?estado=activos"><span>Accesos activos</span><b><?= (int) $k['activos'] ?></b></a>
    <a class="bo-kpi" href="accesos.php?vence=24"><span>Vencen en 24 h</span><b><?= (int) $k['por_vencer'] ?></b></a>
    <a class="bo-kpi" href="accesos.php?estado=finalizados"><span>Finalizados hoy</span><b><?= (int) $k['vistos_hoy'] ?></b></a>
    <a class="bo-kpi" href="accesos.php?estado=por_empezar"><span>Comprados sin empezar</span><b><?= (int) $k['sin_empezar'] ?></b></a>
    <a class="bo-kpi" href="alertas.php"><span>Alertas por revisar</span><b><?= (int) $k['alertas'] ?></b></a>
  </div>

  <div class="bo-grid2">
    <div class="bo-card">
      <h2>Reproducciones en vivo <a href="en-vivo.php">Ver todas</a></h2>
      <?php if (!$vivo): ?><div class="bo-vacio">Nadie está reproduciendo en este momento.</div><?php else: ?>
      <div class="bo-scroll"><table class="bo-tabla"><thead><tr><th>Alumno</th><th>Contenido</th><th>Minuto</th><th>Latido</th></tr></thead><tbody>
      <?php foreach ($vivo as $s): ?>
        <tr><td><?= h($s['cliente_nombre'] ?: $s['cliente_email']) ?><span class="sub"><?= h($s['cliente_email']) ?></span></td>
            <td><?= h($s['titulo']) ?><span class="sub"><?= chip_tipo($s['tipo']) ?></span></td>
            <td><?= mmss((int) $s['posicion_seg']) ?><?= $s['reproduciendo'] ? '' : '<span class="sub">en pausa</span>' ?></td>
            <td><?= hace($s['ultimo_latido']) ?></td></tr>
      <?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div>
    <div class="bo-card">
      <h2>Alertas <a href="alertas.php">Revisar</a></h2>
      <?php if (!$alertas): ?><div class="bo-vacio">Sin alertas pendientes.</div><?php else: ?>
      <ul class="bo-linea-tiempo"><?php foreach ($alertas as $al): ?>
        <li><b><?= h(ucfirst(str_replace('_', ' ', $al['tipo']))) ?></b><span><?= h($al['cliente_email'] ?? '') ?> · <?= h($al['titulo'] ?? '') ?> · <?= hace($al['creada']) ?></span></li>
      <?php endforeach; ?></ul><?php endif; ?>
    </div>
  </div>

  <div class="bo-card">
    <h2>Vencen en las próximas 24 h <a href="accesos.php?vence=24">Ver todos</a></h2>
    <?php if (!$porVencer): ?><div class="bo-vacio">Ningún acceso vence en las próximas 24 horas.</div><?php else: ?>
    <div class="bo-scroll"><table class="bo-tabla"><thead><tr><th>Alumno</th><th>Contenido</th><th>Vence</th><th></th></tr></thead><tbody>
    <?php foreach ($porVencer as $a): ?>
      <tr><td><?= h($a['cliente_nombre'] ?: $a['cliente_email']) ?><span class="sub"><?= h($a['cliente_email']) ?></span></td>
          <td><?= h($a['titulo']) ?></td><td><?= fecha($a['vence']) ?></td>
          <td><?php if (puede('accesos')): ?><button class="bo-btn sec mini" data-accion="extender" data-acceso="<?= (int) $a['id'] ?>" data-titulo="Extender acceso">Extender</button><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?>
  </div>
  <?php dialogo_accion('index.php'); ?>
<?php }, 'index.php');
