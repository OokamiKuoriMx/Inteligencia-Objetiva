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

pagina('Contenido', function () use ($lista, $puede, $abrir) { ?>
<link rel="stylesheet" href="/io/backoffice/contenido-editor.css?v=2.0.6"><section class="bo-card bo-contenidos">
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
  <?= csrf_input() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="version"><input type="hidden" name="producto_id_original"><input type="hidden" name="motivo">
  <div class="bo-editor-cabecera">
    <div><p class="bo-contexto">Inteligencia Objetiva · Contenido</p><h2 id="editorTitulo">Detalles del contenido</h2></div>
    <span class="bo-chip neutro" id="editorEstado">Borrador</span>
    <button type="button" class="bo-btn sec st-deshacer" id="deshacerCambios" disabled><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-3"/></svg><span>Deshacer cambios</span></button>
    <button type="submit" class="bo-btn" id="guardarContenido">Guardar</button>
    <button type="button" class="bo-editor-cerrar" data-cerrar-editor aria-label="Cerrar editor"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
  </div>
  <div class="st-pestanas" role="tablist" aria-label="Secciones del contenido">
    <?php foreach (['detalles' => 'Detalles', 'venta' => 'Precio y venta', 'acceso' => 'Acceso'] as $k => $t): ?>
      <button type="button" role="tab" id="tab-<?= $k ?>" aria-controls="panel-<?= $k ?>" aria-selected="<?= $k === 'detalles' ? 'true' : 'false' ?>" tabindex="<?= $k === 'detalles' ? '0' : '-1' ?>" data-editor-tab="<?= $k ?>"><?= h($t) ?></button>
    <?php endforeach; ?>
  </div>
  <div class="bo-editor-error" id="editorError" role="alert" tabindex="-1" hidden></div>
  <div class="bo-editor-scroll">
    <div class="bo-editor-campos">
      <!-- DETALLES -->
      <section role="tabpanel" id="panel-detalles" aria-labelledby="tab-detalles" data-editor-panel="detalles">
        <div class="st-campo"><label for="contenido-titulo">Título (obligatorio)</label><input id="contenido-titulo" name="titulo" type="text" maxlength="255" required placeholder="Agrega un título que describa el contenido" data-contador="255"><span class="st-cuenta" aria-hidden="true"></span></div>
        <div class="st-campo"><label for="contenido-resumen">Resumen</label><textarea id="contenido-resumen" name="resumen" rows="2" maxlength="400" placeholder="Una frase breve que aparece en el catálogo" data-contador="400"></textarea><span class="st-cuenta" aria-hidden="true"></span></div>
        <div class="st-campo"><label for="contenido-descripcion">Descripción</label><textarea id="contenido-descripcion" name="descripcion" rows="6" maxlength="30000" placeholder="Cuéntales a los alumnos de qué trata y qué van a encontrar" data-contador="30000"></textarea><span class="st-cuenta" aria-hidden="true"></span></div>

        <div class="st-bloque">
          <h3>Miniatura</h3>
          <p class="bo-nota">Sube o elige una imagen que muestre de qué trata. Arrastra una imagen JPG, PNG o WebP; se recorta a 16:9 (1280 × 720).</p>
          <div class="st-miniaturas" id="miniaturas" role="radiogroup" aria-label="Miniatura del contenido">
            <label class="st-subir" id="zonaSubir" tabindex="0">
              <input type="file" id="archivoMiniatura" accept="image/jpeg,image/png,image/webp" class="visually-hidden">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-9 9"/><path d="M17 2v5M14.5 4.5h5"/></svg>
              <span>Subir miniatura</span><small>o arrástrala aquí</small>
              <span class="st-progreso" hidden><span></span></span>
            </label>
          </div>
          <p class="bo-nota" id="estadoMiniatura" role="status"></p>
          <details class="st-avanzado"><summary>Usar la dirección de una imagen</summary>
            <div class="st-campo"><label for="contenido-imagen">Ruta del sitio o dirección HTTPS</label><input id="contenido-imagen" name="imagen" type="text" maxlength="255" placeholder="/portada/img/mi-contenido.webp" spellcheck="false"></div>
          </details>
        </div>

        <div class="st-bloque">
          <h3>Categoría</h3>
          <p class="bo-nota">Dónde aparece en la tienda.</p>
          <div class="st-chips" role="radiogroup" aria-label="Categoría">
            <?php foreach (IOTienda::CATEGORIAS as $k => $t): ?><label class="st-chip"><input type="radio" name="categoria" value="<?= h($k) ?>"><span><?= h($t) ?></span></label><?php endforeach; ?>
          </div>
        </div>
        <div class="st-bloque">
          <h3>Tema</h3>
          <p class="bo-nota">Ayuda a encontrarlo con los filtros del catálogo.</p>
          <div class="st-chips" role="radiogroup" aria-label="Tema">
            <label class="st-chip"><input type="radio" name="tema" value=""><span>Sin tema</span></label>
            <?php foreach (IOTienda::TEMAS as $k => $t): ?><label class="st-chip"><input type="radio" name="tema" value="<?= h($k) ?>"><span><?= h($t) ?></span></label><?php endforeach; ?>
          </div>
        </div>

        <div class="st-bloque">
          <h3>Lo que vas a comprender</h3>
          <p class="bo-nota">Una idea por renglón. Aparecen como lista en la ficha del producto.</p>
          <ol class="st-lista" id="listaAprenderas"></ol>
          <button type="button" class="bo-btn sec mini" id="agregarIdea">+ Agregar idea</button>
          <textarea name="aprenderas" id="contenido-aprenderas" hidden></textarea>
        </div>

        <details class="st-avanzado st-mas"><summary>Mostrar más</summary>
          <div class="st-campo"><label for="contenido-incluye">Material incluido</label><input id="contenido-incluye" name="incluye" type="text" maxlength="255" placeholder="Ejemplo: cuaderno de trabajo en PDF"></div>
          <div class="st-campo"><label for="contenido-trailer_vimeo">Muestra gratuita (enlace o ID de Vimeo)</label><input id="contenido-trailer_vimeo" name="trailer_vimeo" type="text" maxlength="2000" inputmode="url" autocomplete="off" placeholder="https://vimeo.com/…"></div>
          <p class="bo-nota">Un video público y corto que se muestra en la ficha del producto.</p>
        </details>
      </section>

      <!-- PRECIO Y VENTA -->
      <section role="tabpanel" id="panel-venta" aria-labelledby="tab-venta" data-editor-panel="venta" hidden>
        <div class="st-bloque">
          <h3>Precio</h3>
          <p class="bo-nota">Precio final en pesos mexicanos, con IVA incluido.</p>
          <div class="st-precio"><span aria-hidden="true">$</span><input id="contenido-precio_mxn" name="precio_mxn" type="number" min="0" max="99999999.99" step="0.01" required inputmode="decimal" aria-describedby="desglosePrecio"><span aria-hidden="true">MXN</span></div>
          <p class="bo-nota" id="desglosePrecio"></p>
        </div>
        <div class="st-bloque">
          <label class="st-interruptor"><input type="checkbox" id="mostrarOferta"><span class="st-riel" aria-hidden="true"></span><span><b>Mostrar como oferta</b><small>Tacha un precio anterior real y muestra el descuento.</small></span></label>
          <div class="st-oferta" id="bloqueOferta" hidden>
            <div class="st-campo"><label for="contenido-precio_anterior">Precio anterior (MXN con IVA)</label><input id="contenido-precio_anterior" name="precio_anterior" type="number" min="0" max="99999999.99" step="0.01" inputmode="decimal"></div>
            <span class="st-descuento" id="porcentajeOferta"></span>
          </div>
        </div>
        <div class="st-bloque">
          <h3>Así se verá en el catálogo</h3>
          <div class="st-tarjeta"><img id="tarjetaImagen" src="/portada/img/conferencias-800.webp" alt=""><div><small id="tarjetaCategoria"></small><b id="tarjetaTitulo"></b><p><span id="tarjetaPrecio"></span> <s id="tarjetaAnterior"></s> <em id="tarjetaDescuento"></em></p></div></div>
        </div>
      </section>

      <!-- ACCESO -->
      <section role="tabpanel" id="panel-acceso" aria-labelledby="tab-acceso" data-editor-panel="acceso" hidden>
        <div class="st-bloque"><h3>Tipo de acceso</h3><p class="bo-nota">Cómo podrá reproducirlo el alumno después de comprarlo.</p>
        <fieldset class="bo-editor-tipos"><legend class="visually-hidden">Tipo de acceso</legend>
          <?php foreach (['ventana'=>['Por tiempo','Puede verlo las veces que quiera durante un periodo.'],'permanente'=>['Permanente','Lo conserva en su biblioteca sin vencimiento.'],'unica'=>['Visualización única','Una sola reproducción completa.']] as $k=>[$t,$desc]): ?><label><input type="radio" name="tipo" value="<?= $k ?>" <?= $k==='ventana'?'checked':'' ?>><span><b><?= h($t) ?></b><small><?= h($desc) ?></small></span></label><?php endforeach; ?>
        </fieldset></div>
        <div class="bo-editor-dos" data-regla-ventana><div class="st-campo"><label for="contenido-horas_ventana">Horas de acceso</label><input id="contenido-horas_ventana" name="horas_ventana" type="number" min="1" max="4294967295" value="48"></div><div class="st-campo"><label for="contenido-cuenta">La vigencia empieza</label><select id="contenido-cuenta" name="cuenta_desde"><option value="primera_reproduccion">Con la primera reproducción</option><option value="compra">Al confirmarse la compra</option></select></div></div>
        <div data-regla-inicio><div class="st-campo"><label for="contenido-dias_para_empezar">Días para empezar</label><input id="contenido-dias_para_empezar" name="dias_para_empezar" type="number" min="0" max="4294967295" value="30"></div></div>
        <fieldset class="bo-editor-reglas" data-regla-unica hidden><legend>Visualización única</legend><div class="bo-editor-dos">
          <?php foreach ([['umbral_pct','Dar por visto al (%)',50,100,95],['retroceso_seg','Retroceso permitido (segundos)',0,4294967295,30],['pausa_max_min','Pausa máxima (minutos)',1,4294967295,60],['reanudaciones_max','Reanudaciones permitidas',0,5,2],['gracia_min','Tiempo de gracia (minutos)',0,4294967295,120]] as [$n,$l,$mi,$ma,$v]): ?>
            <div class="st-campo"><label for="contenido-<?= $n ?>"><?= h($l) ?></label><input id="contenido-<?= $n ?>" name="<?= $n ?>" type="number" min="<?= $mi ?>" max="<?= $ma ?>" value="<?= $v ?>"></div>
          <?php endforeach; ?>
          <label class="bo-editor-check"><input type="checkbox" name="permitir_adelantar" value="1"><span>Permitir adelantar la reproducción</span></label>
        </div></fieldset>
        <p class="bo-editor-aviso">Cambiar estas reglas no reinicia contenidos ya vistos. Las membresías conservan el límite de su período pagado.</p>
      </section>
    </div>

    <!-- COLUMNA DERECHA: video y visibilidad -->
    <aside class="st-lateral" aria-label="Video y visibilidad">
      <div class="st-video">
        <div class="bo-editor-preview" id="videoVista">
          <img id="previewMiniatura" src="/portada/img/conferencias-800.webp" alt="">
          <div id="previewVideo"></div>
          <button type="button" id="vistaPreviaVideo" class="bo-editor-play" aria-label="Reproducir vista previa"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m8 5 11 7-11 7z"/></svg></button>
          <span class="st-duracion" id="previewDuracion" hidden></span>
          <div class="st-sin-video" id="sinVideo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="2" y="5" width="15" height="14" rx="2"/><path d="m17 10 5-3v10l-5-3"/></svg><b>Vincula el video</b><small>Pega abajo el enlace de Vimeo</small></div>
        </div>
        <div class="st-video-datos">
          <div class="st-dato">
            <label for="contenido-enlace-vimeo">Enlace del video en Vimeo</label>
            <div class="st-enlace"><input id="contenido-enlace-vimeo" type="text" inputmode="url" autocomplete="off" spellcheck="false" maxlength="2000" placeholder="https://vimeo.com/123456789" aria-describedby="estadoVimeo">
              <button type="button" class="st-icono" id="copiarEnlace" aria-label="Copiar enlace" title="Copiar enlace"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></svg></button>
              <button type="button" class="st-icono" id="consultarVimeo" aria-label="Volver a leer de Vimeo" title="Volver a leer de Vimeo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 4v7h-7"/></svg></button>
            </div>
            <p class="st-estado" id="estadoVimeo" role="status">Copia el enlace desde Vimeo (botón Compartir) y pégalo aquí: título, duración y miniatura se llenan solos.</p>
          </div>
          <div class="st-dato st-dos">
            <div><label for="contenido-duracion-texto">Duración</label><input id="contenido-duracion-texto" type="text" inputmode="numeric" autocomplete="off" maxlength="12" placeholder="1:05:30" pattern="\d{1,6}(:[0-5]?\d){0,2}"><input type="hidden" name="duracion_seg" value="0"></div>
            <div><label for="contenido-producto_id">ID en la tienda</label><input id="contenido-producto_id" name="producto_id" type="number" min="1" max="4294967295" required></div>
          </div>
          <div class="st-dato" id="enlaceTiendaDato" hidden><span class="st-etiqueta">Página en la tienda</span><a id="enlaceTienda" href="#" target="_blank" rel="noopener"></a></div>
          <div class="bo-vimeo-privacidad" id="privacidadVimeo" aria-live="polite">
            <div class="bo-vimeo-privacidad-cabecera"><h4>Privacidad en Vimeo</h4><a href="vimeo.php" class="bo-nota">Cuenta de Vimeo</a></div>
            <p class="bo-nota mb-0" id="privacidadMensaje">Conecta un video para revisar su privacidad.</p>
            <ul class="bo-vimeo-indicadores" id="privacidadIndicadores" hidden></ul>
            <div class="bo-vimeo-aplicar" id="privacidadAplicar" hidden>
              <div class="st-campo"><label for="vimeo-motivo">Motivo del cambio en Vimeo</label><input id="vimeo-motivo" type="text" maxlength="120" value="Configuración recomendada de Vimeo"></div>
              <button type="button" class="bo-btn mini" id="aplicarVimeo">Aplicar configuración recomendada</button>
              <p class="bo-nota mb-0" id="aplicarNota"></p>
            </div>
          </div>
          <details class="bo-vimeo-tecnico"><summary>Detalle técnico</summary>
            <div class="st-dos"><div class="st-campo"><label for="contenido-vimeo_id">ID en Vimeo</label><input id="contenido-vimeo_id" name="vimeo_id" type="text" readonly maxlength="32"></div><div class="st-campo"><label for="contenido-vimeo_hash">Código no listado</label><input id="contenido-vimeo_hash" name="vimeo_hash" type="text" readonly maxlength="32"></div></div>
          </details>
        </div>
      </div>

      <div class="st-visibilidad">
        <h3>Visibilidad</h3>
        <label class="st-opcion"><input type="radio" name="activo" value="1"><span><b>En catálogo</b><small>Se puede comprar en la tienda.</small></span></label>
        <label class="st-opcion"><input type="radio" name="activo" value="0" checked><span><b>Borrador</b><small>Oculto; quien ya lo compró lo conserva.</small></span></label>
        <label class="st-interruptor"><input type="checkbox" name="destacado" value="1"><span class="st-riel" aria-hidden="true"></span><span><b>Destacar</b><small>Aparece primero en la portada y el catálogo.</small></span></label>
        <button type="button" class="st-resumen-precio" data-ir-tab="venta"><span>Precio</span><b id="previewPrecio">Sin precio</b><small id="previewAcceso"></small></button>
      </div>
    </aside>
  </div>

  <div class="bo-editor-descartar" id="editorConfirmar" hidden>
    <div class="st-campo st-motivo"><label for="motivoCambio">Motivo del cambio (queda en la bitácora)</label><input id="motivoCambio" type="text" maxlength="120"></div>
    <button type="button" class="bo-btn sec" id="volverEditar">Volver</button><button type="button" class="bo-btn" id="confirmarGuardar">Guardar cambios</button>
  </div>
  <div class="bo-editor-descartar" id="editorDescartar" role="alert" hidden><span>Hay cambios sin guardar. ¿Quieres descartarlos?</span><button type="button" class="bo-btn sec" id="seguirEditando">Seguir editando</button><button type="button" class="bo-btn" id="descartarCambios">Descartar cambios</button></div>
  <div class="st-pie"><span class="bo-nota" id="editorGuardado" role="status">Los cambios se guardarán al confirmar.</span></div>
</form></dialog>
<script type="application/json" id="editorConfiguracion"><?= json_encode(['nuevo'=>IOContenidos::nuevo(),'abrir'=>$abrir,'categorias'=>IOTienda::CATEGORIAS], JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<?php endif; ?>
<script src="/io/backoffice/contenido-editor.js?v=2.0.6" defer></script>
<?php }, 'reglas.php');
