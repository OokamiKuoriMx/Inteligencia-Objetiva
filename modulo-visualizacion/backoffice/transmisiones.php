<?php
/** Backoffice · Transmisiones en vivo (YouTube, Facebook, Vimeo y Zoom). */
declare(strict_types=1);
require __DIR__ . '/_inc.php';
require_once __DIR__ . '/../lib/Transmisiones.php';
$admin = exigir('ver');
$puedeEditar = puede('politicas');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_ok();
    $admin = exigir('politicas');
    $id = (int) ($_POST['id'] ?? 0);
    try {
        switch ((string) ($_POST['accion'] ?? '')) {
            case 'guardar':
                $id = IOTransmisiones::guardar($admin, $_POST, $id ?: null);
                flash('Transmisión guardada.');
                break;
            case 'iniciar': case 'terminar': case 'cancelar': case 'reprogramar':
                IOTransmisiones::cambiarEstado($admin, $id, (string) $_POST['accion']);
                flash(['iniciar' => 'La transmisión está al aire. El sitio ya muestra el aviso "En vivo".', 'terminar' => 'Transmisión finalizada.',
                       'cancelar' => 'Transmisión cancelada.', 'reprogramar' => 'Transmisión regresada a programada.'][$_POST['accion']]);
                break;
            default:
                throw new IOError('accion', 'Acción no válida.');
        }
        header('Location: transmisiones.php'); exit;
    } catch (IOError $e) {
        flash($e->getMessage(), 'error');
        $_SESSION['tx_borrador'] = $_POST;
        header('Location: transmisiones.php?' . ($id ? 'editar=' . $id : 'nueva=1')); exit;
    }
}

$alAire = IOTransmisiones::ahora();
$lista = IO::todos("SELECT t.*, p.titulo AS producto_titulo FROM io_transmisiones t LEFT JOIN io_productos p ON p.producto_id = t.producto_id
  ORDER BY (t.estado IN ('programada','en_vivo')) DESC, CASE WHEN t.estado IN ('programada','en_vivo') THEN t.inicio END ASC, t.inicio DESC LIMIT 100");
$editar = isset($_GET['editar']) ? IO::uno('SELECT * FROM io_transmisiones WHERE id = ?', [(int) $_GET['editar']]) : null;
$borrador = $_SESSION['tx_borrador'] ?? null; unset($_SESSION['tx_borrador']);
$form = $borrador ?: ($editar ?: null);
$mostrarForm = $puedeEditar && ($editar || isset($_GET['nueva']));
$productos = IO::todos('SELECT p.producto_id, p.titulo FROM io_politicas p ORDER BY p.titulo');
$catalogo = IO::todos('SELECT producto_id, titulo FROM io_productos ORDER BY titulo');
$dominio = rtrim((string) (IO::config()['dominio'] ?? ''), '/');

pagina('Transmisiones en vivo', function () use ($alAire, $lista, $form, $editar, $mostrarForm, $puedeEditar, $productos, $catalogo, $dominio) {
    $v = fn(string $k, $def = '') => h($form[$k] ?? $def);
    $fechaLocal = $form && !empty($form['inicio']) ? date('Y-m-d\TH:i', strtotime((string) $form['inicio'])) : date('Y-m-d\TH:00', strtotime('+1 day'));
    $boton = function (int $id, string $accion, string $texto, string $clase = 'sec') { ?>
      <form method="post" class="d-inline m-0"><?= csrf_input() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="<?= h($accion) ?>"><button class="bo-btn <?= h($clase) ?> mini"><?= h($texto) ?></button></form>
    <?php }; ?>
  <link rel="stylesheet" href="transmisiones.css?v=1.0.0">

  <?php if ($alAire): ?>
  <div class="bo-card tx-alaire">
    <div><span class="tx-punto"></span><b>Al aire ahora:</b> <?= h($alAire['titulo']) ?> <span class="bo-nota">· <?= h(IOTransmisiones::PLATAFORMAS[$alAire['plataforma']]) ?> · <?= h(IOTransmisiones::ACCESOS[$alAire['acceso']]) ?></span></div>
    <div class="bo-acciones">
      <a class="bo-btn sec mini" href="/en-vivo?t=<?= h(rawurlencode($alAire['slug'])) ?>" target="_blank" rel="noopener">Ver en el sitio ↗</a>
      <?php if ($puedeEditar): $boton((int) $alAire['id'], 'terminar', 'Terminar transmisión', ''); endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($mostrarForm): ?>
  <form method="post" class="bo-card tx-form" id="txForm" novalidate>
    <?= csrf_input() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?= (int) ($editar['id'] ?? 0) ?>">
    <h2><?= $editar ? 'Editar transmisión' : 'Nueva transmisión' ?> <a href="transmisiones.php">Cancelar</a></h2>
    <div class="row g-3">
      <div class="col-lg-8">
        <label class="form-label" for="tx-titulo">Título</label>
        <input class="form-control" id="tx-titulo" name="titulo" maxlength="200" required value="<?= $v('titulo') ?>" placeholder="Ej. Conferencia en vivo: el miedo y la decisión">
        <label class="form-label mt-3" for="tx-url">Enlace de la transmisión</label>
        <input class="form-control" id="tx-url" name="url" required value="<?= $v('url') ?>" placeholder="Pega el enlace de YouTube, Facebook, Vimeo o Zoom" autocomplete="off">
        <p class="tx-detectado" id="txDetectado" aria-live="polite"></p>
        <div class="row g-3">
          <div class="col-sm-7"><label class="form-label" for="tx-inicio">Fecha y hora de inicio</label><input class="form-control" type="datetime-local" id="tx-inicio" name="inicio" required value="<?= h($fechaLocal) ?>"><span class="bo-nota">Hora del centro de México. Cada alumno la ve en su hora local.</span></div>
          <div class="col-sm-5"><label class="form-label" for="tx-dur">Duración estimada</label><select class="form-select" id="tx-dur" name="duracion_min">
            <?php foreach ([30 => '30 min', 45 => '45 min', 60 => '1 h', 90 => '1 h 30 min', 120 => '2 h', 180 => '3 h', 240 => '4 h'] as $m => $t): ?><option value="<?= $m ?>"<?= (int) ($form['duracion_min'] ?? 90) === $m ? ' selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
          </select></div>
        </div>
        <label class="form-label mt-3" for="tx-desc">Descripción <span class="bo-nota">(opcional)</span></label>
        <textarea class="form-control" id="tx-desc" name="descripcion" rows="3"><?= $v('descripcion') ?></textarea>
      </div>
      <div class="col-lg-4">
        <label class="form-label" for="tx-acceso">¿Quién puede verla?</label>
        <select class="form-select" id="tx-acceso" name="acceso">
          <?php foreach (IOTransmisiones::ACCESOS as $k => $t): ?><option value="<?= $k ?>"<?= ($form['acceso'] ?? 'publico') === $k ? ' selected' : '' ?>><?= h($t) ?></option><?php endforeach; ?>
        </select>
        <p class="bo-nota mt-1" id="txAvisoAcceso"></p>
        <div id="txProducto"<?= ($form['acceso'] ?? '') === 'compradores' ? '' : ' hidden' ?>>
          <label class="form-label" for="tx-prod">Contenido comprado</label>
          <select class="form-select" id="tx-prod" name="producto_id"><option value="">Elige un contenido</option>
            <?php foreach ($productos as $p): ?><option value="<?= (int) $p['producto_id'] ?>"<?= (int) ($form['producto_id'] ?? 0) === (int) $p['producto_id'] ? ' selected' : '' ?>><?= h($p['titulo']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <label class="form-label mt-3" for="tx-img">Imagen de portada <span class="bo-nota">(opcional)</span></label>
        <input class="form-control" id="tx-img" name="imagen" value="<?= $v('imagen') ?>" placeholder="/portada/img/conferencias.webp">
        <label class="form-label mt-3" for="tx-grab">Grabación en el catálogo <span class="bo-nota">(después)</span></label>
        <select class="form-select" id="tx-grab" name="grabacion_producto_id"><option value="">Sin grabación todavía</option>
          <?php foreach ($catalogo as $p): ?><option value="<?= (int) $p['producto_id'] ?>"<?= (int) ($form['grabacion_producto_id'] ?? 0) === (int) $p['producto_id'] ? ' selected' : '' ?>><?= h($p['titulo']) ?></option><?php endforeach; ?>
        </select>
        <label class="tx-check mt-3"><input type="checkbox" name="mostrar_chat" value="1"<?= !$form || !empty($form['mostrar_chat']) ? ' checked' : '' ?>> <span>Mostrar el chat junto al video <span class="bo-nota">(solo YouTube)</span></span></label>
      </div>
    </div>
    <div class="d-flex gap-2 justify-content-end mt-4"><a class="bo-btn sec" href="transmisiones.php">Cancelar</a><button class="bo-btn">Guardar transmisión</button></div>
  </form>
  <?php endif; ?>

  <div class="bo-card">
    <h2>Transmisiones <?php if ($puedeEditar && !$mostrarForm): ?><a class="bo-btn mini" href="transmisiones.php?nueva=1">+ Nueva transmisión</a><?php endif; ?></h2>
    <?php if (!$lista): ?>
      <div class="bo-vacio">Aún no hay transmisiones. Programa la primera con "Nueva transmisión": pega el enlace de YouTube o Facebook y elige la hora.</div>
    <?php else: ?>
    <div class="bo-scroll"><table class="bo-tabla">
      <thead><tr><th>Transmisión</th><th>Inicio</th><th>Plataforma</th><th>Acceso</th><th>Estado</th><th></th></tr></thead><tbody>
      <?php foreach ($lista as $t): $al = IOTransmisiones::alAire($t); $id = (int) $t['id'];
          $estado = $al ? 'en_vivo' : ($t['estado'] === 'programada' && strtotime($t['inicio']) + 60 * (int) $t['duracion_min'] < time() ? 'finalizada' : $t['estado']); ?>
        <tr>
          <td><b class="fw-medium"><?= h($t['titulo']) ?></b><span class="sub"><a href="/en-vivo?t=<?= h(rawurlencode($t['slug'])) ?>" target="_blank" rel="noopener">/en-vivo?t=<?= h($t['slug']) ?></a></span></td>
          <td><?= IO::htmlFecha($t['inicio']) ?><span class="sub"><?= (int) $t['duracion_min'] ?> min</span></td>
          <td><?= h(IOTransmisiones::PLATAFORMAS[$t['plataforma']]) ?></td>
          <td><?= h(['publico' => 'Pública', 'registrados' => 'Con cuenta', 'compradores' => 'Compradores', 'miembros' => 'Miembros'][$t['acceso']]) ?><?php if ($t['producto_titulo']): ?><span class="sub"><?= h($t['producto_titulo']) ?></span><?php endif; ?></td>
          <td><span class="bo-chip <?= ['en_vivo' => 'peligro', 'programada' => 'info', 'finalizada' => 'apagado', 'cancelada' => 'neutro'][$estado] ?>"><?= h(IOTransmisiones::ESTADOS[$estado]) ?></span></td>
          <td><?php if ($puedeEditar): ?><div class="bo-acciones">
            <?php if ($estado === 'programada'): $boton($id, 'iniciar', 'Iniciar ahora', ''); endif; ?>
            <?php if ($estado === 'en_vivo'): $boton($id, 'terminar', 'Terminar'); endif; ?>
            <a class="bo-btn sec mini" href="transmisiones.php?editar=<?= $id ?>">Editar</a>
            <?php if ($estado === 'programada'): $boton($id, 'cancelar', 'Cancelar'); endif; ?>
            <?php if (in_array($t['estado'], ['finalizada', 'cancelada'], true)): $boton($id, 'reprogramar', 'Reactivar'); endif; ?>
          </div><?php endif; ?></td>
        </tr>
      <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </div>

  <div class="bo-card tx-ayuda">
    <h2>Cómo funciona</h2>
    <ul>
      <li><b>YouTube:</b> pega el enlace del directo (youtube.com/live/… o youtu.be/…). Para una transmisión exclusiva, márcala en YouTube como <b>No listada</b>: el sitio solo la muestra a quien tiene acceso.</li>
      <li><b>Facebook:</b> pega el enlace del video en vivo. Facebook solo permite transmisiones públicas en el sitio.</li>
      <li><b>Vimeo:</b> acepta videos y eventos en vivo (vimeo.com/event/…). <b>Zoom:</b> pega el enlace para unirse; solo lo ve quien tiene acceso.</li>
      <li>El sitio muestra la sala de espera <?= IOTransmisiones::SALA_ESPERA_MIN ?> minutos antes y el aviso <b>En vivo</b> en todas las páginas durante el horario programado, o cuando presionas <b>Iniciar ahora</b>.</li>
    </ul>
  </div>

  <?php if ($mostrarForm): ?>
  <script>
  (function () {
    var url = document.getElementById('tx-url'), det = document.getElementById('txDetectado'),
        acc = document.getElementById('tx-acceso'), prod = document.getElementById('txProducto'), aviso = document.getElementById('txAvisoAcceso');
    function plataforma(u) {
      u = (u || '').toLowerCase();
      if (/youtu\.?be/.test(u)) return 'YouTube'; if (/facebook\.com|fb\.watch/.test(u)) return 'Facebook';
      if (/vimeo\.com/.test(u)) return 'Vimeo'; if (/zoom\.us/.test(u)) return 'Zoom'; return '';
    }
    function revisar() {
      var p = plataforma(url.value);
      det.textContent = url.value.trim() === '' ? '' : (p ? 'Detectado: ' + p + '. Se valida al guardar.' : 'No reconozco el enlace. Usa uno de YouTube, Facebook, Vimeo o Zoom.');
      det.className = 'tx-detectado' + (url.value.trim() && !p ? ' error' : '');
      prod.hidden = acc.value !== 'compradores';
      aviso.textContent = p === 'Facebook' && acc.value !== 'publico' ? 'Facebook no permite limitar la transmisión: elige "Pública" o usa YouTube no listado.' :
        (p === 'YouTube' && acc.value !== 'publico' ? 'Recuerda marcar el video en YouTube como "No listado".' : '');
    }
    url.addEventListener('input', revisar); acc.addEventListener('change', revisar); revisar();
  })();
  </script>
  <?php endif; ?>
<?php }, 'transmisiones.php');
