<?php
/** Vincular la cuenta de Vimeo: estado del token, cuenta, permisos, dominio autorizado y guía paso a paso. */
declare(strict_types=1);
require __DIR__ . '/_inc.php';
require_once __DIR__ . '/../lib/Vimeo.php';
exigir('politicas');
$c = IOVimeoCliente::cuenta();
$planes = ['basic' => 'Basic (gratuito)', 'free' => 'Gratuito', 'plus' => 'Plus', 'pro' => 'Pro', 'business' => 'Business', 'premium' => 'Premium',
    'starter' => 'Starter', 'standard' => 'Standard', 'advanced' => 'Advanced', 'enterprise' => 'Enterprise', 'producer' => 'Producer'];

pagina('Vincular Vimeo', function () use ($c, $planes) {
    $estado = $c['vinculada'] ? ($c['faltan'] ? ['Vinculada con permisos incompletos', 'aviso'] : ['Vinculada', 'ok'])
        : ($c['configurado'] ? ['El token no funciona', 'peligro'] : ['Sin vincular', 'neutro']); ?>
<link rel="stylesheet" href="/io/backoffice/contenido-editor.css?v=2.1.1">
<p class="bo-nota"><a href="reglas.php">← Volver a Contenido</a></p>
<section class="bo-card bo-vimeo">
  <div class="bo-vimeo-cabecera">
    <div><h2>Cuenta de Vimeo</h2><p class="bo-nota mb-0">La plataforma usa esta cuenta para leer los datos de cada video y revisar su privacidad.</p></div>
    <span class="bo-chip <?= $estado[1] ?>"><?= h($estado[0]) ?></span>
  </div>

  <?php if (!$c['configurado']): ?>
    <div class="bo-vimeo-aviso">
      <p><b>La cuenta de Vimeo todavía no está vinculada.</b> Hay que hacerlo una sola vez, siguiendo los pasos de abajo.</p>
      <p class="mb-0">Mientras tanto, al pegar el enlace de un video <b>público</b> se llenan el título, la duración y la miniatura. Para videos ocultos o no listados, para revisar su privacidad y para aplicar la configuración recomendada, se necesita la cuenta vinculada.</p>
    </div>
  <?php elseif (!$c['vinculada']): ?>
    <div class="bo-vimeo-aviso peligro" role="alert"><p class="mb-0"><b>Hay un token en la configuración, pero Vimeo no lo aceptó.</b> <?= h($c['error'] ?? '') ?> Genera uno nuevo con los pasos de abajo y reemplázalo.</p></div>
  <?php endif; ?>

  <dl class="bo-vimeo-datos">
    <div><dt>Cuenta</dt><dd><?php if ($c['vinculada']): ?><?= $c['enlace'] ? '<a href="' . h($c['enlace']) . '" target="_blank" rel="noopener">' . h($c['nombre']) . '</a>' : h($c['nombre']) ?><?php else: ?>—<?php endif; ?></dd></div>
    <div><dt>Plan</dt><dd><?= $c['plan'] ? h($planes[$c['plan']] ?? ucfirst($c['plan'])) : '—' ?><?php if (in_array($c['plan'], ['basic', 'free'], true)): ?><small>El plan gratuito no permite ocultar videos de Vimeo ni limitar dónde se insertan.</small><?php endif; ?></dd></div>
    <div><dt>Dominio autorizado para insertar</dt><dd><?php if ($c['dominio']): ?><?= h($c['dominio']) ?><small>Vimeo incluye automáticamente sus subdominios (por ejemplo, www).</small>
      <?php else: ?><?= h($c['dominio_configurado'] ?? 'Sin dominio') ?><small>Es un dominio de prueba. En el servidor del sitio, “dominio” en config.php debe ser https://inteligenciaobjetiva.com.</small><?php endif; ?></dd></div>
  </dl>

  <h3 class="bo-vimeo-sub">Permisos del token</h3>
  <ul class="bo-vimeo-permisos">
    <?php foreach (IOVimeoCliente::PERMISOS as $k => $t): $tiene = in_array($k, $c['permisos'], true); ?>
      <li class="<?= $c['vinculada'] ? ($tiene ? 'ok' : 'falla') : 'neutro' ?>"><span aria-hidden="true"><?= $c['vinculada'] ? ($tiene ? '✓' : '✕') : '•' ?></span><b><?= h(ucfirst($k)) ?></b> · <?= h($t) ?><?php if ($c['vinculada'] && !$tiene): ?> <em>— falta</em><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
  <?php if ($c['vinculada'] && !$c['puede_editar']): ?><p class="bo-nota">Sin el permiso <b>Edit</b>, la plataforma puede revisar la privacidad, pero los cambios se tienen que hacer a mano en Vimeo.</p><?php endif; ?>
  <?php if ($c['vinculada'] && $c['error']): ?><p class="bo-nota"><?= h($c['error']) ?></p><?php endif; ?>
</section>

<section class="bo-card bo-vimeo">
  <h2>Cómo vincular la cuenta (una sola vez)</h2>
  <p class="bo-nota">Toma unos cinco minutos. Lo hace quien administra la cuenta de Vimeo donde están los videos.</p>
  <ol class="bo-vimeo-pasos">
    <li><b>Entra a developer.vimeo.com</b> con la misma cuenta de Vimeo donde están los videos de los cursos. <a href="https://developer.vimeo.com/apps" target="_blank" rel="noopener">Abrir developer.vimeo.com/apps</a></li>
    <li><b>Crea una app</b> con el botón “Create an app”. Nombre: <i>Inteligencia Objetiva</i>. En la pregunta de si otras personas usarán la app, elige <i>No</i> (solo tú). Acepta los términos y créala.</li>
    <li><b>Genera el token.</b> Dentro de la app, en la sección <i>Generate an access token</i>, elige <i>Authenticated (you)</i> y marca solo estos permisos: <b>Public</b>, <b>Private</b> y <b>Edit</b>. Pulsa <i>Generate</i>.</li>
    <li><b>Copia el token</b> en ese momento: Vimeo lo muestra completo una sola vez.</li>
    <li><b>Pásalo al servidor.</b> Quien administra el sitio abre <code>/io/config.php</code> y cambia la línea <code>'vimeo_token' => '',</code> por:
      <pre class="bo-vimeo-codigo">'vimeo_token' => 'aquí-va-el-token-copiado',</pre>
      En la misma sección, confirma que el dominio sea el del sitio:
      <pre class="bo-vimeo-codigo">'dominio' => 'https://inteligenciaobjetiva.com',</pre></li>
    <li><b>Recarga esta página.</b> Debe decir “Vinculada”, mostrar el nombre de la cuenta y los tres permisos con ✓.</li>
  </ol>
  <p class="bo-vimeo-aviso mb-0">El token funciona como una llave de la cuenta de Vimeo: no lo envíes por correo ni por chat. Si alguien más lo llega a ver, en la misma app de developer.vimeo.com bórralo y genera otro.</p>
</section>

<section class="bo-card bo-vimeo">
  <h2>Quién controla qué</h2>
  <div class="bo-vimeo-columnas">
    <div><h3 class="bo-vimeo-sub">Vimeo: dónde y cómo se reproduce</h3><ul>
      <li>Guarda el video y lo transmite.</li>
      <li>Privacidad: <b>oculto en Vimeo</b>, para que no se pueda ver en vimeo.com.</li>
      <li>Dominio: solo se puede insertar en <b>inteligenciaobjetiva.com</b>.</li>
      <li>Descargas desde Vimeo: <b>desactivadas</b>.</li>
    </ul></div>
    <div><h3 class="bo-vimeo-sub">La plataforma: quién y cuándo</h3><ul>
      <li>Quién compró cada contenido.</li>
      <li>Vigencia de 48 h desde la primera reproducción, acceso permanente o visualización única.</li>
      <li>Una sola reproducción a la vez por alumno.</li>
      <li>Revocar o extender accesos desde <a href="accesos.php">Accesos</a>.</li>
    </ul></div>
  </div>
  <p class="bo-nota mb-0">Para conectar un video nuevo, abre un contenido, ve a la pestaña <b>Video</b> y pega el enlace de Vimeo. Ahí mismo verás si su privacidad es la correcta.</p>
</section>
<?php }, 'reglas.php');
