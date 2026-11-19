<?php
declare(strict_types=1);
require __DIR__ . '/_inc.php';
require_once __DIR__ . '/../lib/Contenidos.php';
exigir('ver');
$puede = puede('politicas');
$abrir = ['producto' => max(0, (int) ($_GET['producto'] ?? 0)), 'nuevo' => isset($_GET['nuevo'])];
if (($abrir['producto'] || $abrir['nuevo']) && !$puede) { exigir('politicas'); }
$lista = IO::todos('SELECT p.*, c.imagen, c.precio_mxn, c.categoria, c.activo,
    (SELECT COUNT(*) FROM io_accesos a WHERE a.producto_id=p.producto_id AND a.estado IN ("activo","en_gracia")) AS activos,
    (SELECT COUNT(*) FROM io_accesos a WHERE a.producto_id=p.producto_id) AS total
    FROM io_politicas p LEFT JOIN io_productos c ON c.producto_id=p.producto_id ORDER BY p.actualizado DESC,p.producto_id DESC');

/** Campos del editor: nombres y atributos definidos por la plantilla, salida siempre escapada. */
function campoContenido(string $nombre, string $etiqueta, string $tipo = 'text', array $atributos = []): void
{
    $id = 'contenido-' . $nombre;
    echo '<div class="bo-editor-campo"><label for="' . h($id) . '">' . h($etiqueta) . '</label>';
    $attrs = '';
    foreach ($atributos as $k => $v) {
        if ($v === false) { continue; }
        $attrs .= ' ' . h($k) . ($v === true ? '' : '="' . h($v) . '"');
    }
    if ($tipo === 'textarea') { echo '<textarea class="form-control" id="' . h($id) . '" name="' . h($nombre) . '"' . $attrs . '></textarea>'; }
    else { echo '<input class="form-control" id="' . h($id) . '" name="' . h($nombre) . '" type="' . h($tipo) . '"' . $attrs . '>'; }
    echo '</div>';
}

pagina('Contenido', function () use ($lista, $puede, $abrir) { ?>
<link rel="stylesheet" href="/io/backoffice/contenido-editor.css?v=1.1.0"><section class="bo-card bo-contenidos">
  <div class="bo-contenidos-barra">
    <div><h2>Biblioteca de contenidos</h2><p class="bo-nota mb-0" id="conteoContenidos" aria-live="polite"><?= count($lista) ?> <?= count($lista) === 1 ? 'contenido' : 'contenidos' ?></p></div>
    <input class="form-control bo-contenidos-buscar" type="search" id="buscarContenido" aria-label="Buscar contenido por título o ID" placeholder="Buscar por título o ID">
    <?php if ($puede): ?><a class="bo-btn sec" href="carga-contenidos.php">Carga masiva</a><button type="button" class="bo-btn" id="nuevoContenido"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="3"/><path d="M12 8v8M8 12h8"/></svg>Agregar contenido</button><?php endif; ?>
  </div>
  <div class="bo-scroll"><table class="bo-tabla bo-contenidos-tabla"><thead><tr><th>Contenido</th><th>Visibilidad</th><th>Acceso</th><th>Alumnos</th><th><span class="visually-hidden">Acciones</span></th></tr></thead><tbody>
  <?php foreach ($lista as $p): ?>
    <tr data-contenido-filtro="<?= h($p['titulo'] . ' ' . $p['producto_id']) ?>">
      <td><div class="bo-contenido-ficha"><div class="bo-contenido-miniatura"><img src="<?= h($p['imagen'] ?: '/portada/img/conferencias-800.webp') ?>" alt="" loading="lazy"><span><?= h(mmss((int) $p['duracion_seg'])) ?></span></div><div>
        <?php if ($puede): ?><button type="button" class="bo-contenido-titulo" data-editar-contenido="<?= (int) $p['producto_id'] ?>"><?= h($p['titulo']) ?></button><?php else: ?><b><?= h($p['titulo']) ?></b><?php endif; ?>
        <span class="sub">#<?= (int) $p['producto_id'] ?> · Vimeo <?= h($p['vimeo_id']) ?></span>
      </div></div></td>
      <td><span class="bo-chip <?= !empty($p['activo']) ? 'ok' : 'neutro' ?>"><?= !empty($p['activo']) ? 'En catálogo' : 'Borrador' ?></span><?php if ($p['precio_mxn'] !== null): ?><span class="sub">$<?= number_format((float) $p['precio_mxn'], 2) ?> MXN</span><?php endif; ?></td>
      <td><?= chip_tipo($p['tipo']) ?><?php if ($p['tipo'] === 'ventana'): ?><span class="sub"><?= (int) $p['horas_ventana'] ?> h de acceso</span><?php endif; ?></td>
      <td><?= (int) $p['activos'] ?> activos<span class="sub"><?= (int) $p['total'] ?> en total</span></td>
      <td><?php if ($puede): ?><button type="button" class="bo-btn sec mini" data-editar-contenido="<?= (int) $p['producto_id'] ?>" aria-label="Editar <?= h($p['titulo']) ?>">Editar</button><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <div class="bo-vacio" id="contenidosVacios" <?= $lista ? 'hidden' : '' ?>>No hay contenidos para mostrar.</div>
</section>

<?php if ($puede): ?>
<dialog id="editorContenido" class="bo-editor" aria-labelledby="editorTitulo">
<form id="formContenido" method="post" action="contenido.php" novalidate>
  <?= csrf_input() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="version"><input type="hidden" name="producto_id_original">
  <div class="bo-editor-cabecera"><div><p class="bo-contexto">Inteligencia Objetiva · Contenido</p><h2 id="editorTitulo">Detalles del contenido</h2></div><span class="bo-chip neutro" id="editorEstado">Borrador</span><button type="button" class="bo-editor-cerrar" data-cerrar-editor aria-label="Cerrar editor"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button></div>
  <div class="bo-editor-error" id="editorError" role="alert" tabindex="-1" hidden></div>
  <div class="bo-editor-cuerpo">
    <div class="bo-editor-pasos" role="tablist" aria-label="Secciones del contenido" aria-orientation="vertical">
      <?php foreach (['detalles' => ['Detalles','Título y descripción'], 'video' => ['Video','Vimeo y miniatura'], 'acceso' => ['Acceso','Reglas de reproducción'], 'publicacion' => ['Publicación','Precio y visibilidad']] as $k => [$t,$s]): ?>
        <button type="button" role="tab" id="tab-<?= $k ?>" aria-controls="panel-<?= $k ?>" aria-selected="<?= $k === 'detalles' ? 'true' : 'false' ?>" tabindex="<?= $k === 'detalles' ? '0' : '-1' ?>" data-editor-tab="<?= $k ?>"><span><?= h($t) ?></span><small><?= h($s) ?></small></button>
      <?php endforeach; ?>
    </div>
    <div class="bo-editor-scroll">
      <div class="bo-editor-campos">
        <section role="tabpanel" id="panel-detalles" aria-labelledby="tab-detalles" data-editor-panel="detalles">
          <h3>Detalles</h3><p class="bo-nota">Presenta el contenido tal como lo verá el alumno.</p>
          <?php campoContenido('titulo','Título (obligatorio)','text',['required'=>true,'maxlength'=>255,'placeholder'=>'Escribe el título del contenido']);
          campoContenido('resumen','Resumen','textarea',['maxlength'=>400,'rows'=>3,'placeholder'=>'Una presentación breve para el catálogo']); ?>
          <p class="bo-nota bo-editor-contador" id="resumenCuenta">0 / 400</p>
          <?php campoContenido('descripcion','Descripción','textarea',['maxlength'=>30000,'rows'=>5,'placeholder'=>'Describe de qué trata y qué incluye']); ?>
          <div class="bo-editor-dos"><div class="bo-editor-campo"><label for="contenido-categoria">Categoría</label><select class="form-select" id="contenido-categoria" name="categoria"><?php foreach (['conferencias'=>'Conferencias','cursos'=>'Cursos','documentales'=>'Documentales','introspecciones'=>'Introspecciones','audiolibro'=>'Audiolibro','zoom'=>'En vivo vía Zoom'] as $k=>$t): ?><option value="<?= $k ?>"><?= h($t) ?></option><?php endforeach; ?></select></div><div class="bo-editor-campo"><label for="contenido-tema">Tema</label><select class="form-select" id="contenido-tema" name="tema"><option value="">Sin tema asignado</option><?php foreach (['pareja'=>'Relación de pareja','economia'=>'Economía','hijos'=>'Educación a los hijos','laboral'=>'Entorno laboral','desarrollo'=>'Desarrollo personal','superacion'=>'Superación'] as $k=>$t): ?><option value="<?= $k ?>"><?= h($t) ?></option><?php endforeach; ?></select></div></div>
          <?php campoContenido('aprenderas','Lo que vas a comprender','textarea',['maxlength'=>15000,'rows'=>4,'placeholder'=>'Escribe una idea por línea']);
          campoContenido('incluye','Material incluido','text',['maxlength'=>255,'placeholder'=>'Ejemplo: cuaderno de trabajo']); ?>
        </section>
        <section role="tabpanel" id="panel-video" aria-labelledby="tab-video" data-editor-panel="video" hidden>
          <h3>Video y miniatura</h3><p class="bo-nota">Pega el enlace del video en Vimeo: el título, la duración y la miniatura se llenan solos.</p>
          <div class="bo-editor-campo"><label for="contenido-enlace-vimeo">Enlace del video en Vimeo</label>
            <div class="bo-vimeo-enlace"><input class="form-control" id="contenido-enlace-vimeo" type="text" inputmode="url" autocomplete="off" spellcheck="false" maxlength="2000" placeholder="https://vimeo.com/123456789" aria-describedby="estadoVimeo"><button type="button" class="bo-btn sec" id="consultarVimeo">Leer de Vimeo</button></div>
          </div>
          <p class="bo-nota" id="estadoVimeo" role="status">Cópialo desde Vimeo con el botón Compartir o desde la barra del navegador. El video es obligatorio para publicar o para visualización única.</p>
          <div class="bo-editor-sugerencias" id="sugerenciasVimeo" hidden></div>
          <div class="bo-editor-dos"><div class="bo-editor-campo"><label for="contenido-duracion-texto">Duración (h:mm:ss)</label><input class="form-control" id="contenido-duracion-texto" type="text" inputmode="numeric" autocomplete="off" maxlength="12" placeholder="1:05:30" pattern="\d{1,6}(:[0-5]?\d){0,2}"><input type="hidden" name="duracion_seg" value="0"></div>
            <?php campoContenido('producto_id','ID del producto (obligatorio)','number',['min'=>1,'max'=>4294967295,'required'=>true]); ?></div>
          <p class="bo-nota">La duración se llena desde Vimeo; también puedes escribirla. Es obligatoria para visualización única. El ID del producto es el del sitio y no cambia al editar.</p>
          <div class="bo-vimeo-privacidad" id="privacidadVimeo" aria-live="polite">
            <div class="bo-vimeo-privacidad-cabecera"><h4>Privacidad en Vimeo</h4><a href="vimeo.php" class="bo-nota">Cuenta de Vimeo</a></div>
            <p class="bo-nota mb-0" id="privacidadMensaje">Conecta un video para revisar su privacidad.</p>
            <ul class="bo-vimeo-indicadores" id="privacidadIndicadores" hidden></ul>
            <div class="bo-vimeo-aplicar" id="privacidadAplicar" hidden>
              <div class="bo-editor-campo"><label for="vimeo-motivo">Motivo del cambio en Vimeo</label><input class="form-control" id="vimeo-motivo" type="text" maxlength="120" value="Configuración recomendada de Vimeo"></div>
              <button type="button" class="bo-btn" id="aplicarVimeo">Aplicar configuración recomendada</button>
              <p class="bo-nota mb-0" id="aplicarNota"></p>
            </div>
          </div>
          <details class="bo-vimeo-tecnico"><summary>Detalle técnico</summary>
            <div class="bo-editor-dos"><?php campoContenido('vimeo_id','ID del video en Vimeo','text',['readonly'=>true,'maxlength'=>32]); campoContenido('vimeo_hash','Código de video no listado','text',['readonly'=>true,'maxlength'=>32]); ?></div>
            <p class="bo-nota mb-0">Se obtienen del enlace. El código solo existe en videos no listados y el reproductor lo usa junto con el ID.</p>
          </details>
          <?php campoContenido('imagen','Miniatura','text',['maxlength'=>255,'placeholder'=>'/portada/img/mi-contenido.webp']); ?>
          <p class="bo-nota">Se llena con la miniatura de Vimeo. También acepta una ruta de imagen del sitio o una dirección HTTPS.</p>
          <?php campoContenido('trailer_vimeo','Muestra gratuita (opcional): enlace o ID de Vimeo','text',['maxlength'=>2000,'inputmode'=>'url','autocomplete'=>'off']); ?>
          <p class="bo-nota">Un video público y corto para la ficha del producto.</p>
        </section>
        <section role="tabpanel" id="panel-acceso" aria-labelledby="tab-acceso" data-editor-panel="acceso" hidden>
          <h3>Condiciones de acceso</h3><p class="bo-nota">Define cuándo puede empezar y cómo podrá reproducirlo el alumno.</p>
          <fieldset class="bo-editor-tipos"><legend class="visually-hidden">Tipo de acceso</legend>
            <?php foreach (['ventana'=>['Por tiempo','Una ventana de acceso definida.'],'permanente'=>['Permanente','Reproducciones sin vencimiento.'],'unica'=>['Visualización única','Una reproducción con reglas de reanudación.']] as $k=>[$t,$desc]): ?><label><input type="radio" name="tipo" value="<?= $k ?>" <?= $k==='ventana'?'checked':'' ?>><span><b><?= h($t) ?></b><small><?= h($desc) ?></small></span></label><?php endforeach; ?>
          </fieldset>
          <div class="bo-editor-dos" data-regla-ventana><?php campoContenido('horas_ventana','Horas de acceso','number',['min'=>1,'max'=>4294967295,'value'=>48]); ?><div class="bo-editor-campo"><label for="contenido-cuenta">La vigencia empieza</label><select class="form-select" id="contenido-cuenta" name="cuenta_desde"><option value="primera_reproduccion">Con la primera reproducción</option><option value="compra">Al confirmarse la compra</option></select></div></div>
          <div data-regla-inicio><?php campoContenido('dias_para_empezar','Días para empezar','number',['min'=>0,'max'=>4294967295,'value'=>30]); ?></div>
          <fieldset class="bo-editor-reglas" data-regla-unica hidden><legend>Visualización única</legend><div class="bo-editor-dos">
            <?php campoContenido('umbral_pct','Dar por visto al (%)','number',['min'=>50,'max'=>100,'value'=>95]);
            campoContenido('retroceso_seg','Retroceso permitido (segundos)','number',['min'=>0,'max'=>4294967295,'value'=>30]);
            campoContenido('pausa_max_min','Pausa máxima (minutos)','number',['min'=>1,'max'=>4294967295,'value'=>60]);
            campoContenido('reanudaciones_max','Reanudaciones permitidas','number',['min'=>0,'max'=>5,'value'=>2]);
            campoContenido('gracia_min','Tiempo de gracia (minutos)','number',['min'=>0,'max'=>4294967295,'value'=>120]); ?>
            <label class="bo-editor-check"><input type="checkbox" name="permitir_adelantar" value="1"><span>Permitir adelantar la reproducción</span></label>
          </div></fieldset>
          <p class="bo-editor-aviso">Cambiar estas reglas no reinicia contenidos ya vistos. Las membresías conservan el límite de su período pagado.</p>
        </section>
        <section role="tabpanel" id="panel-publicacion" aria-labelledby="tab-publicacion" data-editor-panel="publicacion" hidden>
          <h3>Publicación</h3><p class="bo-nota">Revisa los importes finales y decide si el contenido aparece en la tienda.</p>
          <div class="bo-editor-dos"><?php campoContenido('precio_mxn','Precio MXN con IVA','number',['min'=>0,'max'=>'99999999.99','step'=>'0.01','required'=>true]); campoContenido('precio_anterior','Precio anterior MXN con IVA','number',['min'=>0,'max'=>'99999999.99','step'=>'0.01']); ?></div>
          <p class="bo-nota">Para mostrarlo en catálogo se requiere un precio positivo. Usa únicamente un precio anterior real.</p>
          <label class="bo-editor-check bo-editor-publicar"><input type="checkbox" name="activo" value="1"><span><b>Mostrar en el catálogo</b><small>Al desactivarlo, permanece como borrador y deja de ofrecerse para nuevas compras.</small></span></label>
          <label class="bo-editor-check"><input type="checkbox" name="destacado" value="1"><span>Destacar este contenido</span></label>
          <?php campoContenido('motivo','Motivo del cambio (obligatorio)','text',['maxlength'=>120,'required'=>true,'placeholder'=>'Describe brevemente qué estás actualizando']); ?>
          <p class="bo-nota">El motivo quedará registrado en la bitácora del equipo.</p>
        </section>
      </div>
      <aside class="bo-editor-vista" aria-label="Vista previa del contenido"><div class="bo-editor-preview"><img id="previewMiniatura" src="/portada/img/conferencias-800.webp" alt="Miniatura del contenido"><div id="previewVideo"></div><button type="button" id="vistaPreviaVideo" class="bo-editor-play"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m8 5 11 7-11 7z"/></svg><span>Vista previa del video</span></button></div><div class="bo-editor-resumen"><p class="bo-contexto">Vista previa</p><h3 id="previewTitulo">Título del contenido</h3><p id="previewCategoria">Conferencias</p><span id="previewAcceso" class="bo-chip neutro">Por tiempo</span><p class="bo-editor-precio" id="previewPrecio">Borrador</p><p class="bo-nota" id="previewDuracion">Sin duración definida</p></div></aside>
    </div>
  </div>
  <div class="bo-editor-descartar" id="editorDescartar" role="alert" hidden><span>Hay cambios sin guardar. ¿Quieres descartarlos?</span><button type="button" class="bo-btn sec" id="seguirEditando">Seguir editando</button><button type="button" class="bo-btn" id="descartarCambios">Descartar cambios</button></div>
  <div class="bo-editor-pie"><span class="bo-nota" id="editorGuardado" role="status">Los cambios se guardarán al confirmar.</span><div><button type="button" class="bo-btn sec" data-cerrar-editor>Cerrar</button><button type="submit" class="bo-btn" id="guardarContenido">Guardar cambios</button></div></div>
</form></dialog>
<script type="application/json" id="editorConfiguracion"><?= json_encode(['nuevo'=>IOContenidos::nuevo(),'abrir'=>$abrir], JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<?php endif; ?>
<script src="/io/backoffice/contenido-editor.js?v=1.1.0" defer></script>
<?php }, 'reglas.php');
