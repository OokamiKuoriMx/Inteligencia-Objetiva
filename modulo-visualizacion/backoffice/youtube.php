<?php
/** Canal de YouTube: conectar el canal para que sus directos públicos aparezcan solos en /en-vivo. */
declare(strict_types=1);
require __DIR__ . '/_inc.php';
require_once __DIR__ . '/../lib/YouTube.php';
$admin = exigir('politicas');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_ok();
    try {
        $accion = (string) ($_POST['accion'] ?? '');
        if ($accion === 'guardar') { $c = IOYouTube::guardarCanal($admin, (string) ($_POST['canal'] ?? '')); IOYouTube::estado(true); flash('Canal conectado: ' . $c['titulo'] . '.'); }
        elseif ($accion === 'quitar') { IOYouTube::quitarCanal($admin); flash('Se desconectó el canal. Los directos ya no se detectarán solos.'); }
        elseif ($accion === 'revisar') { IOYouTube::estado(true); flash('Canal revisado.'); }
        else { throw new IOError('accion', 'Acción no válida.'); }
    } catch (IOError $e) { flash($e->getMessage(), 'error'); }
    header('Location: youtube.php'); exit;
}

$canal = IOYouTube::canal();
$llave = IOYouTube::configurado();
$estado = $canal && $llave ? IOYouTube::estado() : null;

pagina('Canal de YouTube', function () use ($canal, $llave, $estado) {
    $chip = !$llave ? ['Falta la llave de la API', 'neutro'] : (!$canal ? ['Sin canal', 'neutro'] : (!empty($estado['error']) ? ['Con errores', 'aviso'] : (!empty($estado['en_vivo']) ? ['En vivo ahora', 'peligro'] : ['Conectado', 'ok']))); ?>
<link rel="stylesheet" href="/io/backoffice/contenido-editor.css?v=2.0.6"><link rel="stylesheet" href="transmisiones.css?v=2.0.2">
<p class="bo-nota"><a href="transmisiones.php">← Volver a Transmisiones</a></p>
<section class="bo-card bo-vimeo">
  <div class="bo-vimeo-cabecera">
    <div><h2>Canal de YouTube</h2><p class="bo-nota mb-0">Cuando el canal transmite en vivo, el sitio lo muestra solo en <a href="/en-vivo" target="_blank" rel="noopener">/en-vivo</a> y enciende el aviso “En vivo” en todas las páginas.</p></div>
    <span class="bo-chip <?= $chip[1] ?>"><?= h($chip[0]) ?></span>
  </div>

  <?php if (!$llave): ?>
    <div class="bo-vimeo-aviso"><p class="mb-0"><b>Falta la llave de la API de YouTube.</b> Es gratuita y se configura una sola vez; sigue los pasos de abajo. Mientras tanto, puedes publicar cada directo pegando su enlace en <a href="transmisiones.php">Transmisiones</a>.</p></div>
  <?php elseif (!empty($estado['error'])): ?>
    <div class="bo-vimeo-aviso peligro" role="alert"><p class="mb-0"><?= h($estado['error']) ?></p></div>
  <?php endif; ?>

  <?php if ($canal): ?>
    <div class="yt-canal">
      <?php if ($canal['miniatura']): ?><img src="<?= h($canal['miniatura']) ?>" alt="" width="56" height="56"><?php endif; ?>
      <div><b><?= h($canal['titulo']) ?></b><span class="bo-nota"><?= h($canal['handle'] ?: $canal['id']) ?> · <a href="https://www.youtube.com/channel/<?= h($canal['id']) ?>" target="_blank" rel="noopener">Abrir en YouTube ↗</a></span></div>
      <form method="post" class="ms-auto d-flex gap-2"><?= csrf_input() ?>
        <button class="bo-btn sec mini" name="accion" value="revisar">Revisar ahora</button>
        <button class="bo-btn sec mini" name="accion" value="quitar" onclick="return confirm('¿Desconectar el canal? Los directos dejarán de aparecer solos en el sitio.')">Desconectar</button>
      </form>
    </div>
    <?php if ($llave): $v = $estado['en_vivo'] ?? null; $p = $estado['proximo'] ?? null; ?>
    <div class="yt-ahora <?= $v ? 'vivo' : '' ?>">
      <?php if ($v): ?>
        <?php if ($v['miniatura']): ?><img src="<?= h($v['miniatura']) ?>" alt=""><?php endif; ?>
        <div><span class="yt-insignia"><span class="tx-punto"></span>En vivo ahora</span><b><?= h($v['titulo']) ?></b>
          <span class="bo-nota">Se ve en <a href="/en-vivo" target="_blank" rel="noopener">/en-vivo</a> con el chat de YouTube. · <a href="https://www.youtube.com/watch?v=<?= h($v['video_id']) ?>" target="_blank" rel="noopener">Ver en YouTube ↗</a></span></div>
      <?php else: ?>
        <div><b>El canal no está transmitiendo en este momento.</b>
          <span class="bo-nota"><?= $p ? 'Próximo directo programado: «' . h($p['titulo']) . '», ' . h(IO::fechaLarga(date('Y-m-d H:i:s', (int) $p['inicio']))) . '.' : 'Cuando empieces un directo público, aparecerá en el sitio en menos de un minuto.' ?></span></div>
      <?php endif; ?>
    </div>
    <p class="bo-nota mb-0">Última revisión: <?= !empty($estado['revisado']) ? h(hace(date('Y-m-d H:i:s', (int) $estado['revisado']))) : 'pendiente' ?>. El sitio revisa el canal cada minuto mientras alguien lo visita.</p>
    <?php endif; ?>
  <?php endif; ?>

  <form method="post" class="yt-form"><?= csrf_input() ?><input type="hidden" name="accion" value="guardar">
    <div class="st-campo"><label for="yt-canal"><?= $canal ? 'Cambiar de canal' : 'Enlace del canal' ?></label><input id="yt-canal" name="canal" required maxlength="300" placeholder="https://www.youtube.com/@MartinAparicio o el ID UC…" autocomplete="off" spellcheck="false" <?= $llave ? '' : 'disabled' ?>></div>
    <button class="bo-btn" <?= $llave ? '' : 'disabled' ?>>Conectar canal</button>
  </form>
  <p class="bo-nota">Copia la dirección de la página principal del canal (empieza con youtube.com/@…) o pega su ID de canal.</p>
</section>

<section class="bo-card bo-vimeo">
  <h2>Cómo transmitir con la cámara de la computadora</h2>
  <ol class="bo-vimeo-pasos">
    <li>En <a href="https://studio.youtube.com" target="_blank" rel="noopener">YouTube Studio</a>, pulsa <b>Crear → Emitir en directo</b>. La primera vez, YouTube puede tardar hasta 24 horas en activar las transmisiones del canal.</li>
    <li>Elige <b>Cámara web</b>, escribe el título y selecciona la cámara y el micrófono.</li>
    <li>En <b>Visibilidad</b> elige <b>Pública</b> para que el sitio la detecte sola. Activa el chat si quieres que aparezca junto al video.</li>
    <li>Pulsa <b>Emitir en directo</b>. En menos de un minuto aparece en <a href="/en-vivo" target="_blank" rel="noopener">/en-vivo</a> y en el aviso “En vivo” del sitio.</li>
  </ol>
  <p class="bo-vimeo-aviso mb-0"><b>¿Transmisión solo para miembros o compradores?</b> Márcala en YouTube como <b>No listada</b> (no se detecta sola, a propósito) y regístrala en <a href="transmisiones.php">Transmisiones</a> con su enlace y el acceso que corresponda. Si registras un directo público con su enlace, el sitio usa ese registro y sus reglas de acceso.</p>
</section>

<section class="bo-card bo-vimeo">
  <h2>Cómo obtener la llave de la API (una sola vez)</h2>
  <p class="bo-nota">Es gratuita. Toma unos diez minutos y la hace quien administra el sitio.</p>
  <ol class="bo-vimeo-pasos">
    <li><b>Entra a Google Cloud Console</b> con una cuenta de Google del equipo y crea un proyecto llamado <i>Inteligencia Objetiva</i>. <a href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener">Crear proyecto</a></li>
    <li><b>Activa la API.</b> En la biblioteca de APIs busca <i>YouTube Data API v3</i> y pulsa <b>Habilitar</b>. <a href="https://console.cloud.google.com/apis/library/youtube.googleapis.com" target="_blank" rel="noopener">Abrir YouTube Data API v3</a></li>
    <li><b>Crea la llave.</b> En <i>APIs y servicios → Credenciales</i>, pulsa <b>Crear credenciales → Clave de API</b> y cópiala. <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Abrir Credenciales</a></li>
    <li><b>Restríngela.</b> Edita la llave: en <i>Restricciones de API</i> elige solo <b>YouTube Data API v3</b>; en <i>Restricciones de aplicación</i> elige <b>Direcciones IP</b> y escribe la IP del servidor del sitio.</li>
    <li><b>Pásala al servidor.</b> En <code>/io/config.php</code> agrega o cambia la línea:
      <pre class="bo-vimeo-codigo">'youtube_api_key' => 'aquí-va-la-llave',</pre></li>
    <li><b>Recarga esta página</b> y conecta el canal con su enlace.</li>
  </ol>
  <p class="bo-vimeo-aviso mb-0">La llave solo lee datos públicos de YouTube; aun así, no la compartas por correo ni chat. Cada revisión usa 2 unidades de la cuota gratuita diaria de 10 000 (unas 2 900 al día como máximo).</p>
</section>
<?php }, 'transmisiones.php');
