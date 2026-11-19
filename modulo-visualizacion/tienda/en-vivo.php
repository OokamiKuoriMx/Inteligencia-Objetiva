<?php
/** /en-vivo — transmisión al aire (o la indicada con ?t=slug), próximas transmisiones y grabaciones. */
declare(strict_types=1);
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../lib/Transmisiones.php';
if (is_file(__DIR__ . '/_migas.php')) { require_once __DIR__ . '/_migas.php'; }

$dominio = (string) (IO::config()['dominio'] ?? '');
$cliente = IO::clienteActual();
$t = !empty($_GET['t']) ? IOTransmisiones::porSlug((string) $_GET['t']) : IOTransmisiones::ahora();
if ($t && $t['estado'] === 'cancelada') { $t = null; }
if (!$t) { $sig = IOTransmisiones::proximas(1); $t = $sig[0] ?? null; }
$proximas = array_values(array_filter(IOTransmisiones::proximas(8), fn($x) => !$t || (int) $x['id'] !== (int) $t['id']));
$pasadas = IOTransmisiones::pasadas(6);

[$puede, $porQue] = $t ? IOTransmisiones::puedeVer($t, $cliente) : [false, ''];
$alAire = $t && IOTransmisiones::alAire($t);
$abierta = $t && IOTransmisiones::abierta($t);
$terminada = $t && ($t['estado'] === 'finalizada' || (!$alAire && strtotime($t['inicio']) + 60 * (int) $t['duracion_min'] < time()));
$producto = $t && $t['acceso'] === 'compradores' ? IO::uno('SELECT slug, titulo FROM io_productos WHERE producto_id = ?', [(int) $t['producto_id']]) : null;
$grabacion = $t && $t['grabacion_producto_id'] ? IO::uno('SELECT slug, titulo FROM io_productos WHERE producto_id = ?', [(int) $t['grabacion_producto_id']]) : null;

encabezado($t ? $t['titulo'] . ' · En vivo' : 'En vivo', 'en_vivo', 'Transmisiones en vivo de Inteligencia Objetiva con Martín Aparicio.', $t && $t['acceso'] === 'publico' && !empty($t['slug']) && $t['slug'] !== 'canal' ? '/en-vivo?t=' . rawurlencode($t['slug']) : ruta('en_vivo'));
?>
<link rel="stylesheet" href="/io/tienda/en-vivo.css?v=1.0.0">
<?php foreach (array_filter(array_merge($t ? [$t] : [], $proximas)) as $ev) { $ld = IOAgentes::evento(IOAgentes::dominio(), $ev); if ($ld) { echo IOAgentes::jsonLd($ld), "
"; } } ?>
<section class="container iov">
  <?php if (function_exists('migas')): ?><?= migas([['texto' => 'Inicio', 'url' => ruta('inicio')], ['texto' => 'En vivo']]) ?><?php endif; ?>

  <?php if (!$t): ?>
    <div class="iot-cabecera"><h1>En vivo</h1><p>Aquí verás las conferencias y encuentros que Martín Aparicio transmite en vivo.</p></div>
    <div class="iot-vacio">No hay transmisiones programadas por ahora. Vuelve pronto o explora el <a href="<?= e(ruta('catalogo')) ?>">catálogo</a>.</div>
  <?php else: ?>
  <div class="iov-escena">
    <div class="iov-cabeza">
      <?php if ($alAire): ?><span class="iov-alaire"><i></i>En vivo ahora</span>
      <?php elseif ($terminada): ?><span class="iov-etiqueta">Transmisión finalizada</span>
      <?php else: ?><span class="iov-etiqueta">Próxima transmisión</span><?php endif; ?>
      <span class="iov-plataforma">Vía <?= e(IOTransmisiones::PLATAFORMAS[$t['plataforma']]) ?></span>
    </div>
    <h1><?= e($t['titulo']) ?></h1>
    <p class="iov-fecha"><?= IO::htmlFecha($t['inicio']) ?> · <?= (int) $t['duracion_min'] ?> min<?php if (!$alAire && !$terminada): ?> · <?= IO::htmlRestante($t['inicio']) ?><?php endif; ?></p>

    <div class="iov-zona<?= $abierta && $puede && IOTransmisiones::urlChat($t, $dominio) ? ' con-chat' : '' ?>">
      <?php if (!$puede): ?>
        <div class="iov-bloqueo">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
          <p><?= e($porQue) ?></p>
          <?php if ($producto): ?><a class="iot-btn" href="<?= e('/producto/' . rawurlencode($producto['slug'])) ?>">Ver "<?= e($producto['titulo']) ?>"</a>
          <?php elseif ($t['acceso'] === 'miembros'): ?><a class="iot-btn" href="<?= e(ruta('membresias')) ?>">Conocer las membresías</a><?php endif; ?>
        </div>
      <?php elseif ($terminada): ?>
        <div class="iov-bloqueo">
          <p>Esta transmisión ya terminó.</p>
          <?php if ($grabacion): ?><a class="iot-btn" href="<?= e('/producto/' . rawurlencode($grabacion['slug'])) ?>">Ver la grabación</a><?php endif; ?>
        </div>
      <?php elseif (!$abierta): ?>
        <div class="iov-espera" style="<?= $t['imagen'] ? '--fondo:url(&quot;' . e(addcslashes((string) $t['imagen'], '"\\')) . '&quot;)' : '' ?>">
          <span>La sala abre <?= IOTransmisiones::SALA_ESPERA_MIN ?> minutos antes</span>
          <b><?= IO::htmlRestante($t['inicio']) ?></b>
          <a class="iot-btn sec" href="/io/api/transmisiones.php?accion=ics&amp;t=<?= e(rawurlencode($t['slug'])) ?>">Agregar a mi calendario</a>
        </div>
      <?php elseif ($t['plataforma'] === 'zoom'): ?>
        <div class="iov-bloqueo">
          <p>Esta sesión es por Zoom. Se abrirá en la aplicación de Zoom.</p>
          <a class="iot-btn" href="<?= e($t['video_id']) ?>" target="_blank" rel="noopener">Entrar a la sesión de Zoom</a>
        </div>
      <?php else: ?>
        <div class="iov-video"><iframe src="<?= e(IOTransmisiones::urlReproductor($t, $dominio)) ?>" title="<?= e($t['titulo']) ?>" allow="autoplay; fullscreen; picture-in-picture; encrypted-media" allowfullscreen></iframe></div>
        <?php if ($chat = IOTransmisiones::urlChat($t, $dominio)): ?><div class="iov-chat"><iframe src="<?= e($chat) ?>" title="Chat en vivo"></iframe></div><?php endif; ?>
      <?php endif; ?>
    </div>
    <?php if ($t['descripcion']): ?><p class="lead-io iov-desc"><?= nl2br(e($t['descripcion'])) ?></p><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($proximas): ?>
  <h2 class="iov-h2">Próximas transmisiones</h2>
  <div class="iov-lista">
    <?php foreach ($proximas as $x): ?>
    <article class="iov-item">
      <div class="dia"><b><?= e(date('j', strtotime($x['inicio']))) ?></b><span><?= e(['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'][(int) date('n', strtotime($x['inicio'])) - 1]) ?></span></div>
      <div class="info">
        <a href="/en-vivo?t=<?= e(rawurlencode($x['slug'])) ?>"><?= e($x['titulo']) ?></a>
        <small><?= IO::htmlFecha($x['inicio']) ?> · <?= e(IOTransmisiones::PLATAFORMAS[$x['plataforma']]) ?><?= $x['acceso'] !== 'publico' ? ' · Exclusiva' : '' ?></small>
      </div>
      <a class="iot-btn sec" href="/io/api/transmisiones.php?accion=ics&amp;t=<?= e(rawurlencode($x['slug'])) ?>" aria-label="Agregar <?= e($x['titulo']) ?> a mi calendario">Recordarme</a>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php $pasadas = array_values(array_filter($pasadas, fn($x) => !$t || (int) $x['id'] !== (int) $t['id'])); if ($pasadas): ?>
  <h2 class="iov-h2">Transmisiones anteriores</h2>
  <div class="iov-lista">
    <?php foreach ($pasadas as $x): ?>
    <article class="iov-item pasada">
      <div class="info"><span><?= e($x['titulo']) ?></span><small><?= IO::htmlFecha($x['inicio']) ?></small></div>
      <?php if ($x['grabacion_slug']): ?><a class="iot-btn sec" href="<?= e('/producto/' . rawurlencode($x['grabacion_slug'])) ?>">Ver grabación</a><?php endif; ?>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="pb-5"></div>
</section>
<?php if ($t && !$abierta && !$terminada): ?>
<script>// Cuando llegue la hora de abrir la sala, recargar para mostrar el reproductor.
setTimeout(function(){ location.reload(); }, Math.max(15000, <?= (int) ((strtotime($t['inicio']) - 60 * IOTransmisiones::SALA_ESPERA_MIN - time()) * 1000) ?>));</script>
<?php endif; ?>
<?php pie();
