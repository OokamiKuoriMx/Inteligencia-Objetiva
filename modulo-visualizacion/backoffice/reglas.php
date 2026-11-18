<?php
declare(strict_types=1);
require __DIR__ . '/_inc.php';
exigir('ver');

$editar = isset($_GET['producto']) ? IO::uno('SELECT * FROM io_politicas WHERE producto_id = ?', [(int) $_GET['producto']]) : null;
$nuevo = isset($_GET['nuevo']);
$lista = IO::todos("SELECT p.*,
   (SELECT COUNT(*) FROM io_accesos a WHERE a.producto_id = p.producto_id AND a.estado IN ('activo','en_gracia')) AS activos,
   (SELECT COUNT(*) FROM io_accesos a WHERE a.producto_id = p.producto_id) AS total
  FROM io_politicas p ORDER BY p.tipo = 'unica' DESC, p.titulo");

pagina('Catálogo y reglas', function () use ($lista, $editar, $nuevo) {
    $d = $editar ?: ['producto_id' => '', 'titulo' => '', 'vimeo_id' => '', 'vimeo_hash' => '', 'duracion_seg' => 0, 'tipo' => 'ventana', 'horas_ventana' => 48,
        'cuenta_desde' => 'primera_reproduccion', 'dias_para_empezar' => 30, 'umbral_pct' => 95, 'retroceso_seg' => 30, 'permitir_adelantar' => 0,
        'pausa_max_min' => 60, 'reanudaciones_max' => 2, 'gracia_min' => 120];
    $puede = puede('politicas'); ?>
  <?php if (($editar || $nuevo) && $puede): ?>
  <form class="bo-card" method="post" action="accion.php">
    <?= csrf_input() ?><input type="hidden" name="accion" value="politica"><input type="hidden" name="volver" value="reglas.php">
    <h2><?= $editar ? 'Editar reglas: ' . h($d['titulo']) : 'Nuevo contenido' ?></h2>
    <div class="row g-3">
      <div class="col-md-2"><label class="form-label">ID producto (sitio)</label><input class="form-control" name="producto_id" type="number" min="1" required value="<?= h($d['producto_id']) ?>" <?= $editar ? 'readonly' : '' ?>></div>
      <div class="col-md-5"><label class="form-label">Título</label><input class="form-control" name="titulo" required value="<?= h($d['titulo']) ?>"></div>
      <div class="col-md-2"><label class="form-label">ID de Vimeo</label><input class="form-control" name="vimeo_id" required value="<?= h($d['vimeo_id']) ?>"></div>
      <div class="col-md-3"><label class="form-label">Hash (videos no listados)</label><input class="form-control" name="vimeo_hash" value="<?= h($d['vimeo_hash']) ?>"></div>
      <div class="col-md-3"><label class="form-label">Duración (segundos)</label><input class="form-control" name="duracion_seg" type="number" min="0" value="<?= (int) $d['duracion_seg'] ?>">
        <label class="bo-nota mt-1"><input type="checkbox" name="sincronizar" value="1" checked> Leer duración desde Vimeo</label></div>
      <div class="col-md-3"><label class="form-label">Tipo de acceso</label><select class="form-select" name="tipo" id="selTipo">
        <?php foreach (TIPOS as $k => $t): ?><option value="<?= $k ?>" <?= $d['tipo'] === $k ? 'selected' : '' ?>><?= h($t) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-2"><label class="form-label">Horas de acceso</label><input class="form-control" name="horas_ventana" type="number" min="1" value="<?= (int) $d['horas_ventana'] ?>"></div>
      <div class="col-md-2"><label class="form-label">Cuenta desde</label><select class="form-select" name="cuenta_desde">
        <option value="primera_reproduccion" <?= $d['cuenta_desde'] === 'primera_reproduccion' ? 'selected' : '' ?>>1.ª reproducción</option>
        <option value="compra" <?= $d['cuenta_desde'] === 'compra' ? 'selected' : '' ?>>Compra</option></select></div>
      <div class="col-md-2"><label class="form-label">Días para empezar</label><input class="form-control" name="dias_para_empezar" type="number" min="1" value="<?= (int) $d['dias_para_empezar'] ?>"></div>
    </div>
    <fieldset class="mt-4" id="reglasUnica">
      <h2>Reglas de visualización única</h2>
      <div class="row g-3">
        <div class="col-md-2"><label class="form-label">Dar por visto al (%)</label><input class="form-control" name="umbral_pct" type="number" min="50" max="100" value="<?= (int) $d['umbral_pct'] ?>"></div>
        <div class="col-md-2"><label class="form-label">Retroceso (s)</label><input class="form-control" name="retroceso_seg" type="number" min="0" value="<?= (int) $d['retroceso_seg'] ?>"><span class="bo-nota">0 = bloqueado · 999999 = libre</span></div>
        <div class="col-md-2"><label class="form-label">Pausa máx. (min)</label><input class="form-control" name="pausa_max_min" type="number" min="1" value="<?= (int) $d['pausa_max_min'] ?>"></div>
        <div class="col-md-2"><label class="form-label">Reanudaciones</label><input class="form-control" name="reanudaciones_max" type="number" min="0" max="5" value="<?= (int) $d['reanudaciones_max'] ?>"></div>
        <div class="col-md-2"><label class="form-label">Gracia (min)</label><input class="form-control" name="gracia_min" type="number" min="5" value="<?= (int) $d['gracia_min'] ?>"></div>
        <div class="col-md-2 d-flex align-items-end"><label><input type="checkbox" name="permitir_adelantar" value="1" <?= (int) $d['permitir_adelantar'] ? 'checked' : '' ?>> Permitir adelantar</label></div>
      </div>
    </fieldset>
    <div class="row g-3 mt-2"><div class="col-md-5"><label class="form-label">Motivo del cambio</label><select name="motivo" class="form-select">
      <option>Configuración</option><?php foreach (IOAdmin::MOTIVOS as $m): ?><option><?= h($m) ?></option><?php endforeach; ?></select></div></div>
    <div class="d-flex gap-2 justify-content-end mt-3"><a class="bo-btn sec" href="reglas.php">Cancelar</a><button class="bo-btn">Guardar reglas</button></div>
    <p class="bo-nota mt-2">Los cambios aplican a nuevas reproducciones. Los accesos ya vistos no se reactivan.</p>
  </form>
  <script>(function(){var s=document.getElementById('selTipo'),f=document.getElementById('reglasUnica');function t(){f.style.display=s.value==='unica'?'block':'none'}s.addEventListener('change',t);t();})();</script>
  <?php endif; ?>

  <div class="bo-card">
    <h2><?= count($lista) ?> contenidos con reglas <?php if ($puede): ?><a href="reglas.php?nuevo=1">+ Agregar contenido</a><?php endif; ?></h2>
    <?php if (!$lista): ?><div class="bo-vacio">Aún no hay contenidos. Usa "Agregar contenido" o el script de migración.</div><?php else: ?>
    <div class="bo-scroll"><table class="bo-tabla"><thead><tr><th>Contenido</th><th>Vimeo</th><th>Duración</th><th>Acceso</th><th>Reglas únicas</th><th>Accesos</th><th></th></tr></thead><tbody>
    <?php foreach ($lista as $p): ?>
      <tr>
        <td><?= h($p['titulo']) ?><span class="sub">Producto #<?= (int) $p['producto_id'] ?></span></td>
        <td><?= h($p['vimeo_id']) ?></td>
        <td><?= mmss((int) $p['duracion_seg']) ?></td>
        <td><?= chip_tipo($p['tipo']) ?><span class="sub"><?= $p['tipo'] === 'ventana' ? (int) $p['horas_ventana'] . ' h desde ' . ($p['cuenta_desde'] === 'compra' ? 'la compra' : 'la 1.ª reproducción') : '' ?></span></td>
        <td><?= $p['tipo'] === 'unica' ? 'Visto al ' . (int) $p['umbral_pct'] . '% · ' . (int) $p['reanudaciones_max'] . ' reanudaciones · ' . ((int) $p['permitir_adelantar'] ? 'adelantar sí' : 'sin adelantar') : '—' ?></td>
        <td><?= (int) $p['activos'] ?> activos<span class="sub"><?= (int) $p['total'] ?> en total</span></td>
        <td><?php if ($puede): ?><a class="bo-btn sec mini" href="reglas.php?producto=<?= (int) $p['producto_id'] ?>">Editar</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?>
  </div>
<?php }, 'reglas.php');
