<?php
declare(strict_types=1);
require __DIR__ . '/_inc.php';
exigir('ver');

$vivo = IO::todos("SELECT s.*, a.id AS acceso_id, a.cliente_nombre, a.cliente_email, a.max_seg, p.titulo, p.tipo, p.duracion_seg
  FROM io_sesiones s JOIN io_accesos a ON a.id = s.acceso_id JOIN io_politicas p ON p.producto_id = a.producto_id
  WHERE s.activa = 1 AND s.ultimo_latido > DATE_SUB(NOW(), INTERVAL 60 SECOND) ORDER BY s.iniciada DESC");

function dispositivo(?string $ua): string
{
    $ua = (string) $ua;
    $so = preg_match('/iPhone|iPad/', $ua) ? 'iOS' : (preg_match('/Android/', $ua) ? 'Android' : (preg_match('/Windows/', $ua) ? 'Windows' : (preg_match('/Mac OS/', $ua) ? 'macOS' : 'Otro')));
    $nav = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Safari\//', $ua) ? 'Safari' : (preg_match('/Firefox\//', $ua) ? 'Firefox' : '')));
    return trim("$so $nav");
}

pagina('Reproducciones en curso', function () use ($vivo) { ?>
  <div class="bo-card">
    <h2><?= count($vivo) ?> reproducciones en curso <span class="bo-nota">Se actualiza cada 15 s</span></h2>
    <?php if (!$vivo): ?><div class="bo-vacio">Nadie está reproduciendo en este momento.</div><?php else: ?>
    <div class="bo-scroll"><table class="bo-tabla">
      <thead><tr><th>Alumno</th><th>Contenido</th><th>Minuto</th><th>Dispositivo</th><th>IP</th><th>Inició</th><th></th></tr></thead><tbody>
      <?php foreach ($vivo as $s): $pct = $s['duracion_seg'] > 0 ? min(100, (int) round($s['posicion_seg'] * 100 / $s['duracion_seg'])) : 0; ?>
        <tr>
          <td><?= h($s['cliente_nombre'] ?: $s['cliente_email']) ?><span class="sub"><?= h($s['cliente_email']) ?></span></td>
          <td><?= h($s['titulo']) ?><span class="sub"><?= chip_tipo($s['tipo']) ?></span></td>
          <td><span class="bo-barra"><span style="width:<?= $pct ?>%"></span></span><?= mmss((int) $s['posicion_seg']) ?><span class="sub"><?= $s['reproduciendo'] ? 'reproduciendo' : 'en pausa' ?></span></td>
          <td><?= h(dispositivo($s['dispositivo'])) ?></td>
          <td><?= h($s['ip']) ?></td>
          <td><?= hace($s['iniciada']) ?></td>
          <td><?php if (puede('accesos')): ?><button class="bo-btn sec mini" data-accion="cerrar_sesion" data-acceso="<?= (int) $s['acceso_id'] ?>" data-titulo="Cerrar reproducción">Cerrar</button><?php endif; ?></td>
        </tr>
      <?php endforeach; ?></tbody></table></div><?php endif; ?>
  </div>
  <?php dialogo_accion('en-vivo.php'); ?>
  <script>setTimeout(function () { if (!document.querySelector('dialog[open]')) location.reload(); }, 15000);</script>
<?php }, 'en-vivo.php');
