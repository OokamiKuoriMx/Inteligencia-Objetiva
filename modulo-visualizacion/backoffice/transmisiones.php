<?php
/** Backoffice · Transmisiones en vivo (YouTube, Facebook, Vimeo y Zoom). */
declare(strict_types=1);
require __DIR__ . '/_inc.php';
require_once __DIR__ . '/../lib/Transmisiones.php';
require_once __DIR__ . '/../lib/YouTube.php';
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
        $_SESSION['tx_error'] = $e->getMessage();
        $_SESSION['tx_borrador'] = $_POST;
        header('Location: transmisiones.php?' . ($id ? 'editar=' . $id : 'nueva=1')); exit;
    }
}

$alAire = IOTransmisiones::ahora();
$lista = IO::todos("SELECT t.*, p.titulo AS producto_titulo FROM io_transmisiones t LEFT JOIN io_productos p ON p.producto_id = t.producto_id
  ORDER BY (t.estado IN ('programada','en_vivo')) DESC, CASE WHEN t.estado IN ('programada','en_vivo') THEN t.inicio END ASC, t.inicio DESC LIMIT 100");
$editar = isset($_GET['editar']) ? IO::uno('SELECT * FROM io_transmisiones WHERE id = ?', [(int) $_GET['editar']]) : null;
$borrador = $_SESSION['tx_borrador'] ?? null; $txError = $_SESSION['tx_error'] ?? null; unset($_SESSION['tx_borrador'], $_SESSION['tx_error']);
$form = $borrador ?: ($editar ?: null);
$mostrarForm = $puedeEditar && ($editar || isset($_GET['nueva']));
$productos = IO::todos('SELECT p.producto_id, p.titulo FROM io_politicas p ORDER BY p.titulo');
$catalogo = IO::todos('SELECT producto_id, titulo FROM io_productos ORDER BY titulo');
$dominio = rtrim((string) (IO::config()['dominio'] ?? ''), '/');
$ytCanal = IOYouTube::canal(); $ytLlave = IOYouTube::configurado();

pagina('Transmisiones en vivo', function () use ($ytCanal, $ytLlave, $txError, $alAire, $lista, $form, $editar, $mostrarForm, $puedeEditar, $productos, $catalogo, $dominio) {
    $v = fn(string $k, $def = '') => h($form[$k] ?? $def);
    $fechaLocal = $form && !empty($form['inicio']) ? date('Y-m-d\TH:i', strtotime((string) $form['inicio'])) : date('Y-m-d\TH:00', strtotime('+1 day'));
    $boton = function (int $id, string $accion, string $texto, string $clase = 'sec') { ?>
      <form method="post" class="d-inline m-0"><?= csrf_input() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="<?= h($accion) ?>"><button class="bo-btn <?= h($clase) ?> mini"><?= h($texto) ?></button></form>
    <?php }; ?>
  <link rel="stylesheet" href="contenido-editor.css?v=2.1.1"><link rel="stylesheet" href="transmisiones.css?v=2.1.3">

  <?php if ($alAire): ?>
  <div class="bo-card tx-alaire">
    <div><span class="tx-punto"></span><b>Al aire ahora:</b> <?= h($alAire['titulo']) ?> <span class="bo-nota">· <?= h(IOTransmisiones::PLATAFORMAS[$alAire['plataforma']]) ?> · <?= h(IOTransmisiones::ACCESOS[$alAire['acceso']]) ?></span></div>
    <div class="bo-acciones">
      <a class="bo-btn sec mini" href="/en-vivo?t=<?= h(rawurlencode($alAire['slug'])) ?>" target="_blank" rel="noopener">Ver en el sitio ↗</a>
      <?php if ($puedeEditar): $boton((int) $alAire['id'], 'terminar', 'Terminar transmisión', ''); endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($mostrarForm):
      $acc = (string) ($form['acceso'] ?? 'publico'); $img = (string) ($form['imagen'] ?? '');
      $portadas = ['/portada/img/conferencias.webp' => 'Conferencias', '/portada/img/cursos.webp' => 'Cursos', '/portada/img/introspecciones.webp' => 'Introspecciones', '/portada/img/membresias.webp' => 'Membresías', '/portada/img/hero.webp' => 'Portada']; ?>
  <dialog id="txEditor" class="bo-editor tx-editor" aria-labelledby="txTitulo">
  <form method="post" id="txForm" novalidate>
    <?= csrf_input() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?= (int) ($editar['id'] ?? 0) ?>">
    <div class="bo-editor-cabecera">
      <div><p class="bo-contexto">Inteligencia Objetiva · Transmisiones</p><h2 id="txTitulo"><?= $editar ? 'Editar transmisión' : 'Nueva transmisión' ?></h2></div>
      <span class="bo-chip neutro" id="txPlataformaChip" hidden></span>
      <button type="submit" class="bo-btn">Guardar transmisión</button>
      <a class="bo-editor-cerrar" href="transmisiones.php" data-cerrar-tx aria-label="Cerrar sin guardar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></a>
    </div>
    <?php if ($txError): ?><div class="bo-editor-error" id="txError" role="alert" tabindex="-1"><?= h($txError) ?></div><?php endif; ?>
    <div class="bo-editor-scroll">
      <div class="bo-editor-campos">
        <div class="st-campo"><label for="tx-titulo">Título (obligatorio)</label><input id="tx-titulo" name="titulo" maxlength="200" required value="<?= $v('titulo') ?>" placeholder="Ej. Conferencia en vivo: el miedo y la decisión" data-contador="200"><span class="st-cuenta" aria-hidden="true"></span></div>

        <div class="st-campo tx-enlace"><label for="tx-url">Enlace de la transmisión (obligatorio)</label>
          <div class="tx-enlace-fila"><span class="tx-logo" id="txLogo" aria-hidden="true"></span><input id="tx-url" name="url" required value="<?= $v('url') ?>" placeholder="Pega el enlace de YouTube, Facebook, Vimeo o Zoom" autocomplete="off" spellcheck="false" inputmode="url"></div>
        </div>
        <p class="st-estado tx-detectado" id="txDetectado" aria-live="polite">Cópialo desde el botón Compartir de la plataforma.</p>

        <div class="st-bloque">
          <h3>Cuándo</h3>
          <p class="bo-nota">Hora del centro de México. Cada alumno la ve en su hora local.</p>
          <div class="bo-editor-dos">
            <div class="st-campo"><label for="tx-inicio">Fecha y hora de inicio</label><input type="datetime-local" id="tx-inicio" name="inicio" required value="<?= h($fechaLocal) ?>"></div>
            <div class="st-campo"><label for="tx-dur">Duración estimada</label><select id="tx-dur" name="duracion_min">
              <?php foreach ([30 => '30 min', 45 => '45 min', 60 => '1 h', 90 => '1 h 30 min', 120 => '2 h', 180 => '3 h', 240 => '4 h'] as $m => $t): ?><option value="<?= $m ?>"<?= (int) ($form['duracion_min'] ?? 90) === $m ? ' selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
            </select></div>
          </div>
        </div>

        <div class="st-campo"><label for="tx-desc">Descripción (opcional)</label><textarea id="tx-desc" name="descripcion" rows="3" maxlength="2000" placeholder="De qué tratará y qué pueden preguntar en el chat" data-contador="2000"><?= $v('descripcion') ?></textarea><span class="st-cuenta" aria-hidden="true"></span></div>

        <div class="st-bloque">
          <h3>Imagen de portada</h3>
          <p class="bo-nota">Se ve en la sala de espera y en la lista de próximas transmisiones. Arrastra una imagen o elige una.</p>
          <div class="st-miniaturas" id="txPortadas" role="radiogroup" aria-label="Imagen de portada">
            <label class="st-subir" id="txSubir" tabindex="0">
              <input type="file" id="txArchivo" accept="image/jpeg,image/png,image/webp" class="visually-hidden">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-9 9"/><path d="M17 2v5M14.5 4.5h5"/></svg>
              <span>Subir imagen</span><small>o arrástrala aquí</small>
              <span class="st-progreso" hidden><span></span></span>
            </label>
            <?php if ($img !== '' && !isset($portadas[$img])): ?><button type="button" class="st-opcion-miniatura" role="radio" aria-checked="false" data-src="<?= h($img) ?>"><img src="<?= h($img) ?>" alt="" loading="lazy"><span>Actual</span></button><?php endif; ?>
            <?php foreach ($portadas as $src => $et): ?><button type="button" class="st-opcion-miniatura" role="radio" aria-checked="false" data-src="<?= h($src) ?>"><img src="<?= h(str_replace('.webp', '-800.webp', $src)) ?>" onerror="this.onerror=null;this.src='<?= h($src) ?>'" alt="" loading="lazy"><span><?= h($et) ?></span></button><?php endforeach; ?>
          </div>
          <p class="bo-nota" id="txEstadoImagen" role="status"></p>
          <input type="hidden" id="tx-img" name="imagen" value="<?= h($img) ?>">
        </div>

        <details class="st-avanzado st-mas"<?= !empty($form['grabacion_producto_id']) ? ' open' : '' ?>><summary>Después de la transmisión</summary>
          <div class="st-campo"><label for="tx-grab">Grabación en el catálogo</label><select id="tx-grab" name="grabacion_producto_id"><option value="">Sin grabación todavía</option>
            <?php foreach ($catalogo as $p): ?><option value="<?= (int) $p['producto_id'] ?>"<?= (int) ($form['grabacion_producto_id'] ?? 0) === (int) $p['producto_id'] ? ' selected' : '' ?>><?= h($p['titulo']) ?></option><?php endforeach; ?>
          </select></div>
          <p class="bo-nota">Al terminar, la página muestra el botón "Ver grabación" hacia este contenido.</p>
        </details>
      </div>

      <aside class="st-lateral" aria-label="Vista previa y acceso">
        <div class="st-video">
          <div class="bo-editor-preview"><img id="txVistaImagen" src="<?= h($img ?: '/portada/img/conferencias.webp') ?>" alt=""><span class="tx-vista-insignia" id="txVistaInsignia">Próxima transmisión</span></div>
          <div class="st-video-datos">
            <b class="tx-vista-titulo" id="txVistaTitulo">Título de la transmisión</b>
            <p class="st-estado" id="txVistaCuando"></p>
          </div>
        </div>
        <div class="st-visibilidad">
          <h3>¿Quién puede verla?</h3>
          <?php foreach (['publico' => ['Pública', 'Cualquier visitante, sin cuenta.'], 'registrados' => ['Con cuenta', 'Alumnos que iniciaron sesión.'], 'compradores' => ['Compradores', 'Quienes compraron un contenido.'], 'miembros' => ['Miembros', 'Con membresía activa.']] as $k => [$t, $d]): ?>
            <label class="st-opcion"><input type="radio" name="acceso" value="<?= $k ?>"<?= $acc === $k ? ' checked' : '' ?>><span><b><?= h($t) ?></b><small><?= h($d) ?></small></span></label>
          <?php endforeach; ?>
          <div class="st-campo tx-producto" id="txProducto"<?= $acc === 'compradores' ? '' : ' hidden' ?>><label for="tx-prod">Contenido comprado</label><select id="tx-prod" name="producto_id"><option value="">Elige un contenido</option>
            <?php foreach ($productos as $p): ?><option value="<?= (int) $p['producto_id'] ?>"<?= (int) ($form['producto_id'] ?? 0) === (int) $p['producto_id'] ? ' selected' : '' ?>><?= h($p['titulo']) ?></option><?php endforeach; ?>
          </select></div>
          <p class="tx-aviso" id="txAvisoAcceso" hidden></p>
          <label class="st-interruptor" id="txChat"><input type="checkbox" name="mostrar_chat" value="1"<?= !$form || !empty($form['mostrar_chat']) ? ' checked' : '' ?>><span class="st-riel" aria-hidden="true"></span><span><b>Chat junto al video</b><small>Solo en YouTube.</small></span></label>
        </div>
      </aside>
    </div>
    <div class="st-pie tx-pie"><span class="bo-nota">Se guarda en la bitácora. Puedes iniciarla o terminarla desde la lista.</span><div><a class="bo-btn sec" href="transmisiones.php">Cancelar</a><button type="submit" class="bo-btn">Guardar transmisión</button></div></div>
  </form>
  </dialog>
  <?php endif; ?>

  <div class="bo-card tx-canal">
    <p><b>Detección automática de YouTube:</b>
      <?= $ytCanal && $ytLlave ? 'los directos públicos de «' . h($ytCanal['titulo']) . '» aparecen solos en el sitio.' : 'conecta el canal para que sus directos públicos aparezcan solos, sin registrarlos aquí.' ?></p>
    <a class="bo-btn sec mini" href="youtube.php"><?= $ytCanal && $ytLlave ? 'Ver canal' : 'Conectar canal de YouTube' ?></a>
  </div>

  <div class="bo-card">
    <h2>Transmisiones <?php if ($puedeEditar): ?><a class="bo-btn mini" href="transmisiones.php?nueva=1">+ Nueva transmisión</a><?php endif; ?></h2>
    <?php if (!$lista): ?>
      <div class="bo-vacio">Aún no hay transmisiones. Programa la primera con "Nueva transmisión": pega el enlace de YouTube o Facebook y elige la hora.</div>
    <?php else: ?>
    <div class="bo-scroll"><table class="bo-tabla">
      <thead><tr><th>Transmisión</th><th>Inicio</th><th>Plataforma</th><th>Acceso</th><th>Estado</th><th class="tx-col-acciones"><span class="visually-hidden">Acciones</span></th></tr></thead><tbody>
      <?php foreach ($lista as $t): $al = IOTransmisiones::alAire($t); $id = (int) $t['id'];
          $estado = $al ? 'en_vivo' : ($t['estado'] === 'programada' && strtotime($t['inicio']) + 60 * (int) $t['duracion_min'] < time() ? 'finalizada' : $t['estado']); ?>
        <tr>
          <td><b class="fw-medium"><?= h($t['titulo']) ?></b><span class="sub"><a href="/en-vivo?t=<?= h(rawurlencode($t['slug'])) ?>" target="_blank" rel="noopener">/en-vivo?t=<?= h($t['slug']) ?></a></span></td>
          <td><?= IO::htmlFecha($t['inicio']) ?><span class="sub"><?= (int) $t['duracion_min'] ?> min</span></td>
          <td><?= h(IOTransmisiones::PLATAFORMAS[$t['plataforma']]) ?></td>
          <td><?= h(['publico' => 'Pública', 'registrados' => 'Con cuenta', 'compradores' => 'Compradores', 'miembros' => 'Miembros'][$t['acceso']]) ?><?php if ($t['producto_titulo']): ?><span class="sub"><?= h($t['producto_titulo']) ?></span><?php endif; ?></td>
          <td><span class="bo-chip <?= ['en_vivo' => 'peligro', 'programada' => 'info', 'finalizada' => 'apagado', 'cancelada' => 'neutro'][$estado] ?>"><?= h(IOTransmisiones::ESTADOS[$estado]) ?></span></td>
          <td class="tx-col-acciones"><?php if ($puedeEditar): ?><div class="tx-acciones">
            <?php if ($estado === 'programada'): $boton($id, 'iniciar', 'Iniciar ahora', '');
              elseif ($estado === 'en_vivo'): $boton($id, 'terminar', 'Terminar', 'peligro');
              elseif (in_array($t['estado'], ['finalizada', 'cancelada'], true)): $boton($id, 'reprogramar', 'Reactivar');
              else: ?><span class="tx-hueco" aria-hidden="true"></span><?php endif; ?>
            <a class="tx-icono" href="transmisiones.php?editar=<?= $id ?>" aria-label="Editar «<?= h($t['titulo']) ?>»" title="Editar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16z"/><path d="m13.5 6.5 4 4"/></svg></a>
            <details class="tx-menu"><summary class="tx-icono" aria-label="Más acciones de «<?= h($t['titulo']) ?>»" title="Más acciones"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="19" cy="12" r="1.8"/></svg></summary>
              <div class="tx-menu-lista">
                <a href="/en-vivo?t=<?= h(rawurlencode($t['slug'])) ?>" target="_blank" rel="noopener">Ver en el sitio ↗</a>
                <a href="/io/api/transmisiones.php?accion=ics&amp;t=<?= h(rawurlencode($t['slug'])) ?>">Descargar para calendario</a>
                <?php if ($estado === 'programada'): ?><form method="post" onsubmit="return confirm('¿Cancelar «<?= h(addslashes($t['titulo'])) ?>»? Dejará de aparecer en el sitio.')"><?= csrf_input() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="accion" value="cancelar"><button class="peligro">Cancelar transmisión</button></form><?php endif; ?>
              </div>
            </details>
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

  <?php if ($mostrarForm): ?><script src="transmisiones.js?v=2.0.0" defer></script><?php endif; ?>
  <script>document.addEventListener('click', e => document.querySelectorAll('.tx-menu[open]').forEach(m => { if (!m.contains(e.target)) m.removeAttribute('open'); })); document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.tx-menu[open]').forEach(m => { m.removeAttribute('open'); m.querySelector('summary').focus(); }); }); document.querySelectorAll('.tx-menu').forEach(m => m.addEventListener('toggle', () => { if (!m.open) return; document.querySelectorAll('.tx-menu[open]').forEach(o => { if (o !== m) o.removeAttribute('open'); }); const r = m.querySelector('summary').getBoundingClientRect(), l = m.querySelector('.tx-menu-lista'), alto = l.offsetHeight; l.style.right = (innerWidth - r.right) + 'px'; l.style.top = (r.bottom + 6 + alto > innerHeight ? r.top - 6 - alto : r.bottom + 6) + 'px'; })); addEventListener('scroll', () => document.querySelectorAll('.tx-menu[open]').forEach(m => m.removeAttribute('open')), true);</script>
<?php }, 'transmisiones.php');
