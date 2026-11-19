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
// Vista simple para el equipo: los seis estados internos se agrupan en tres. Solo presentación.
const GRUPOS_ESTADO = [
    'activos'     => ['Activos', 'Activo', 'ok', ['por_empezar', 'activo', 'en_gracia']],
    'finalizados' => ['Finalizados', 'Finalizado', 'neutro', ['visto', 'vencido']],
    'revocados'   => ['Revocados', 'Revocado', 'peligro', ['revocado']],
];
function chip_estado_simple(string $e): string
{
    foreach (GRUPOS_ESTADO as [, $t, $c, $estados]) {
        if (in_array($e, $estados, true)) { return '<span class="bo-chip ' . $c . '">' . h($t) . '</span>'; }
    }
    return chip_estado($e);
}
if (isset(GRUPOS_ESTADO[$estado])) {
    $grupo = GRUPOS_ESTADO[$estado][3];
    $where[] = 'a.estado IN (' . implode(',', array_fill(0, count($grupo), '?')) . ')'; array_push($p, ...$grupo);
} elseif (isset(ESTADOS[$estado])) { $where[] = 'a.estado = ?'; $p[] = $estado; } // enlaces exactos (p. ej. desde el Tablero)
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
$productos = puede('accesos') ? IO::todos('SELECT producto_id, titulo, tipo, horas_ventana, cuenta_desde FROM io_politicas ORDER BY titulo') : [];

pagina('Accesos', function () use ($filas, $total, $pag, $por, $q, $estado, $tipo, $productos) {
    $qs = fn(array $extra) => '?' . http_build_query(array_merge($_GET, $extra)); ?>
  <form class="bo-filtros" method="get">
    <input class="form-control" type="search" name="q" value="<?= h($q) ?>" placeholder="Alumno, correo, pedido o título">
    <link rel="stylesheet" href="/io/backoffice/accesos-simple.css?v=1.0.0">
    <select class="form-select" name="estado" onchange="this.form.submit()"><option value="">Todos</option>
      <?php foreach (GRUPOS_ESTADO as $k => [$t]): ?><option value="<?= $k ?>" <?= $estado === $k ? 'selected' : '' ?>><?= h($t) ?></option><?php endforeach; ?>
      <?php if (isset(ESTADOS[$estado])): ?><option value="<?= h($estado) ?>" selected><?= h(ESTADOS[$estado][0]) ?></option><?php endif; ?></select>
    <select class="form-select" name="tipo" onchange="this.form.submit()"><option value="">Todos los tipos</option>
      <?php foreach (TIPOS as $k => $t): ?><option value="<?= $k ?>" <?= $tipo === $k ? 'selected' : '' ?>><?= h($t) ?></option><?php endforeach; ?></select>
    <button class="bo-btn sec">Filtrar</button>
    <?php if (puede('accesos')): ?><button type="button" class="bo-btn ms-auto" data-otorgar aria-haspopup="dialog">Otorgar acceso</button><?php endif; ?>
  </form>

  <div class="bo-card">
    <h2><?= number_format($total) ?> accesos</h2>
    <?php if (!$filas): ?><div class="bo-vacio">No hay accesos con estos filtros.</div><?php else: ?>
    <div class="bo-scroll"><table class="bo-tabla">
      <thead><tr><th>Alumno</th><th>Contenido</th><th>Estado</th><th>Vence / límite</th><th>Origen</th><th>Acciones</th></tr></thead><tbody>
      <?php foreach ($filas as $a): ?>
        <tr>
          <td><a href="accesos.php?cliente=<?= (int) $a['cliente_id'] ?>"><?= h($a['cliente_nombre'] ?: $a['cliente_email']) ?></a><span class="sub"><?= h($a['cliente_email']) ?></span></td>
          <td><?= h($a['titulo']) ?><span class="sub"><?= chip_tipo($a['tipo']) ?></span></td>
          <td class="acc-estado"><?= chip_estado_simple($a['estado']) ?><?php if ($a['en_vivo']): ?><span class="acc-vivo" title="Reproduciendo ahora" role="img" aria-label="Reproduciendo ahora"></span><?php endif; ?></td>
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
  <?php /* Otorgar acceso: buscar alumno y contenido (otorgar.js / otorgar.css). */ ?>
  <link rel="stylesheet" href="/io/backoffice/otorgar.css?v=1.0.0">
  <dialog id="dlgCortesia" class="bo-dialogo ot" aria-labelledby="otTitulo" data-gancho="<?= is_callable(IO::config()['buscar_clientes'] ?? null) ? '1' : '0' ?>">
    <form method="post" action="accion.php" id="otForm" novalidate>
      <?= csrf_input() ?><input type="hidden" name="accion" value="otorgar"><input type="hidden" name="volver" value="accesos.php">
      <input type="hidden" name="cliente_id"><input type="hidden" name="email"><input type="hidden" name="nombre"><input type="hidden" name="producto_id">
      <h3 id="otTitulo">Otorgar acceso</h3>

      <div class="ot-campo" data-campo="alumno">
        <label class="form-label" for="otAlumno">Alumno</label>
        <div class="ot-combo">
          <input id="otAlumno" class="form-control" type="text" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="otAlumnoLista"
                 aria-describedby="otAlumnoAyuda otAlumnoError" autocomplete="off" spellcheck="false" placeholder="Escribe nombre o correo">
          <ul id="otAlumnoLista" class="ot-lista" role="listbox" aria-label="Alumnos encontrados" hidden></ul>
        </div>
        <div class="ot-elegido" hidden>
          <span class="ot-avatar" aria-hidden="true"></span>
          <span class="ot-elegido-txt"><b></b><span></span></span>
          <button type="button" class="bo-btn sec mini" data-cambiar>Cambiar<span class="visually-hidden"> alumno</span></button>
        </div>
        <p class="ot-ayuda" id="otAlumnoAyuda">Escribe al menos 2 letras del nombre o del correo.</p>
        <p class="ot-error" id="otAlumnoError" hidden></p>
      </div>

      <div class="ot-campo" data-campo="contenido">
        <label class="form-label" for="otContenido">Contenido</label>
        <div class="ot-combo">
          <input id="otContenido" class="form-control" type="text" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="otContenidoLista"
                 aria-describedby="otContenidoAyuda otContenidoError" autocomplete="off" placeholder="Escribe parte del título">
          <ul id="otContenidoLista" class="ot-lista" role="listbox" aria-label="Contenidos" hidden></ul>
        </div>
        <div class="ot-elegido" hidden>
          <span class="ot-elegido-txt"><b></b><span></span></span>
          <button type="button" class="bo-btn sec mini" data-cambiar>Cambiar<span class="visually-hidden"> contenido</span></button>
        </div>
        <p class="ot-ayuda" id="otContenidoAyuda"><?= count($productos) ?> contenidos. Filtra por título.</p>
        <p class="ot-error" id="otContenidoError" hidden></p>
      </div>

      <div class="ot-fila">
        <div class="ot-campo" data-campo="motivo">
          <label class="form-label" for="otMotivo">Motivo</label>
          <select id="otMotivo" name="motivo" class="form-select" aria-describedby="otMotivoError">
            <option value="">Elige un motivo</option>
            <?php foreach (IOAdmin::MOTIVOS as $m): ?><option<?= $m === 'Cortesía comercial' ? ' selected' : '' ?>><?= h($m) ?></option><?php endforeach; ?>
          </select>
          <p class="ot-error" id="otMotivoError" hidden></p>
        </div>
        <div class="ot-campo" data-campo="nota">
          <label class="form-label" for="otNota">Nota <span class="ot-opcional">(opcional)</span></label>
          <input id="otNota" name="nota" class="form-control" type="text" maxlength="90" placeholder="Ej. ganadora de la rifa" aria-describedby="otNotaAyuda otNotaError">
          <p class="ot-error" id="otNotaError" hidden></p>
        </div>
      </div>
      <p class="ot-ayuda" id="otNotaAyuda">El motivo y la nota quedan en la bitácora.</p>

      <div class="ot-resumen" id="otResumen" aria-live="polite">Elige al alumno y el contenido para ver el resumen.</div>

      <div class="d-flex gap-2 justify-content-end mt-3"><button type="button" class="bo-btn sec" data-cerrar>Cancelar</button><button class="bo-btn" id="otEnviar">Otorgar acceso</button></div>
    </form>
    <script type="application/json" id="otContenidos"><?= json_encode(array_map(fn($pr) => [
        'id' => (int) $pr['producto_id'], 'titulo' => (string) $pr['titulo'], 'tipo' => (string) $pr['tipo'],
        'horas' => (int) $pr['horas_ventana'], 'desde' => (string) $pr['cuenta_desde'],
    ], $productos), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  </dialog>
  <script src="/io/backoffice/otorgar.js?v=1.0.1" defer></script>
  <?php endif; ?>
<?php }, 'accesos.php');
