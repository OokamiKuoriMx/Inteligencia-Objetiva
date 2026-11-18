<?php
declare(strict_types=1);
require __DIR__ . '/_inc.php';
exigir('ver');

$q = trim((string) ($_GET['q'] ?? ''));
$estado = (string) ($_GET['estado'] ?? '');
$tipo = (string) ($_GET['tipo'] ?? '');
$vence = (int) ($_GET['vence'] ?? 0);
$cliente = (int) ($_GET['cliente'] ?? 0);
$pag = max(1, (int) ($_GET['p'] ?? 1));
$por = 30;

$where = ['1=1']; $p = [];
if ($q !== '') {
    $where[] = '(a.cliente_email LIKE ? OR a.cliente_nombre LIKE ? OR a.pedido_ref = ? OR pol.titulo LIKE ?)';
    $like = '%' . $q . '%'; array_push($p, $like, $like, $q, $like);
}
if (isset(ESTADOS[$estado])) { $where[] = 'a.estado = ?'; $p[] = $estado; }
if (isset(TIPOS[$tipo])) { $where[] = 'pol.tipo = ?'; $p[] = $tipo; }
if ($vence > 0) { $where[] = "a.estado = 'activo' AND a.vence BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL ? HOUR)"; $p[] = $vence; }
if ($cliente > 0) { $where[] = 'a.cliente_id = ?'; $p[] = $cliente; }
$w = implode(' AND ', $where);

$total = (int) IO::uno("SELECT COUNT(*) n FROM io_accesos a JOIN io_politicas pol ON pol.producto_id = a.producto_id WHERE $w", $p)['n'];
$filas = IO::todos("SELECT a.*, pol.titulo, pol.tipo, pol.duracion_seg, pol.reanudaciones_max,
    (SELECT COUNT(*) FROM io_sesiones s WHERE s.acceso_id = a.id AND s.activa = 1 AND s.ultimo_latido > DATE_SUB(NOW(), INTERVAL 60 SECOND)) AS en_vivo
  FROM io_accesos a JOIN io_politicas pol ON pol.producto_id = a.producto_id
  WHERE $w ORDER BY a.actualizado DESC LIMIT $por OFFSET " . (($pag - 1) * $por), $p);
$filas = array_map(fn($a) => array_merge($a, IOAccesos::refrescar($a)), $filas);
$productos = IO::todos('SELECT producto_id, titulo FROM io_politicas ORDER BY titulo');

pagina('Accesos', function () use ($filas, $total, $pag, $por, $q, $estado, $tipo, $productos) {
    $qs = fn(array $extra) => '?' . http_build_query(array_merge($_GET, $extra)); ?>
  <form class="bo-filtros" method="get">
    <input class="form-control" type="search" name="q" value="<?= h($q) ?>" placeholder="Alumno, correo, pedido o título">
    <select class="form-select" name="estado" onchange="this.form.submit()"><option value="">Todos los estados</option>
      <?php foreach (ESTADOS as $k => [$t]): ?><option value="<?= $k ?>" <?= $estado === $k ? 'selected' : '' ?>><?= h($t) ?></option><?php endforeach; ?></select>
    <select class="form-select" name="tipo" onchange="this.form.submit()"><option value="">Todos los tipos</option>
      <?php foreach (TIPOS as $k => $t): ?><option value="<?= $k ?>" <?= $tipo === $k ? 'selected' : '' ?>><?= h($t) ?></option><?php endforeach; ?></select>
    <button class="bo-btn sec">Filtrar</button>
    <?php if (puede('accesos')): ?><button type="button" class="bo-btn ms-auto" onclick="document.getElementById('dlgCortesia').showModal()">Otorgar acceso</button><?php endif; ?>
  </form>

  <div class="bo-card">
    <h2><?= number_format($total) ?> accesos</h2>
    <?php if (!$filas): ?><div class="bo-vacio">No hay accesos con estos filtros.</div><?php else: ?>
    <div class="bo-scroll"><table class="bo-tabla">
      <thead><tr><th>Alumno</th><th>Contenido</th><th>Estado</th><th>Progreso</th><th>Vence / límite</th><th>Origen</th><th>Acciones</th></tr></thead><tbody>
      <?php foreach ($filas as $a):
          $pct = $a['duracion_seg'] > 0 ? min(100, (int) round($a['max_seg'] * 100 / $a['duracion_seg'])) : 0; ?>
        <tr>
          <td><a href="accesos.php?cliente=<?= (int) $a['cliente_id'] ?>"><?= h($a['cliente_nombre'] ?: $a['cliente_email']) ?></a><span class="sub"><?= h($a['cliente_email']) ?></span></td>
          <td><?= h($a['titulo']) ?><span class="sub"><?= chip_tipo($a['tipo']) ?></span></td>
          <td><?= chip_estado($a['estado']) ?><?= $a['en_vivo'] ? ' <span class="bo-chip ok">● en vivo</span>' : '' ?>
              <?php if ($a['tipo'] === 'unica'): ?><span class="sub">Reanudaciones <?= (int) $a['reanudaciones'] ?>/<?= (int) $a['reanudaciones_max'] ?></span><?php endif; ?></td>
          <td><span class="bo-barra"><span style="width:<?= $pct ?>%"></span></span><?= $pct ?>%<span class="sub"><?= mmss((int) $a['max_seg']) ?> de <?= mmss((int) $a['duracion_seg']) ?></span></td>
          <td><?= $a['vence'] ? fecha($a['vence']) : ($a['estado'] === 'por_empezar' ? 'Empezar antes de ' . fecha($a['limite_inicio']) : '—') ?></td>
          <td><?= h(ucfirst($a['origen'])) ?><span class="sub"><?= h($a['pedido_ref'] ?? '') ?></span></td>
          <td><?php if (puede('accesos')): $id = (int) $a['id']; ?><div class="bo-acciones">
            <?php if ($a['tipo'] === 'ventana' && $a['estado'] !== 'revocado'): ?><button class="bo-btn sec mini" data-accion="extender" data-acceso="<?= $id ?>" data-titulo="Extender acceso">Extender</button><?php endif; ?>
            <?php if (in_array($a['estado'], ['visto', 'vencido', 'en_gracia', 'activo'], true)): ?><button class="bo-btn sec mini" data-accion="reiniciar" data-acceso="<?= $id ?>" data-titulo="Reiniciar visualización">Reiniciar</button><?php endif; ?>
            <?php if ($a['en_vivo']): ?><button class="bo-btn sec mini" data-accion="cerrar_sesion" data-acceso="<?= $id ?>" data-titulo="Cerrar reproducción en curso">Cerrar sesión</button><?php endif; ?>
            <?php if ($a['estado'] !== 'revocado'): ?><button class="bo-btn sec mini" data-accion="revocar" data-acceso="<?= $id ?>" data-titulo="Revocar acceso">Revocar</button><?php endif; ?>
          </div><?php endif; ?></td>
        </tr>
      <?php endforeach; ?></tbody></table></div>
    <?php $paginas = (int) ceil($total / $por); if ($paginas > 1): ?>
      <div class="d-flex gap-2 justify-content-end mt-3">
        <?php if ($pag > 1): ?><a class="bo-btn sec mini" href="<?= h($qs(['p' => $pag - 1])) ?>">Anterior</a><?php endif; ?>
        <span class="bo-nota align-self-center">Página <?= $pag ?> de <?= $paginas ?></span>
        <?php if ($pag < $paginas): ?><a class="bo-btn sec mini" href="<?= h($qs(['p' => $pag + 1])) ?>">Siguiente</a><?php endif; ?>
      </div>
    <?php endif; endif; ?>
  </div>

  <?php dialogo_accion('accesos.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '')); ?>
  <?php if (puede('accesos')): ?>
  <dialog id="dlgCortesia" class="bo-dialogo">
    <form method="post" action="accion.php">
      <?= csrf_input() ?><input type="hidden" name="accion" value="otorgar"><input type="hidden" name="volver" value="accesos.php">
      <h3>Otorgar acceso</h3>
      <label class="form-label">ID del cliente en el sitio</label><input class="form-control mb-2" name="cliente_id" type="number" min="1" required>
      <label class="form-label">Correo</label><input class="form-control mb-2" name="email" type="email" required>
      <label class="form-label">Nombre</label><input class="form-control mb-2" name="nombre">
      <label class="form-label">Contenido</label>
      <select class="form-select mb-2" name="producto_id" required><?php foreach ($productos as $pr): ?><option value="<?= (int) $pr['producto_id'] ?>"><?= h($pr['titulo']) ?></option><?php endforeach; ?></select>
      <label class="form-label">Motivo</label>
      <select name="motivo" class="form-select" required><option value="">Elige un motivo</option><?php foreach (IOAdmin::MOTIVOS as $m): ?><option><?= h($m) ?></option><?php endforeach; ?></select>
      <div class="d-flex gap-2 justify-content-end mt-3"><button type="button" class="bo-btn sec" onclick="this.closest('dialog').close()">Cancelar</button><button class="bo-btn">Otorgar</button></div>
    </form>
  </dialog>
  <?php endif; ?>
<?php }, 'accesos.php');
