<?php
declare(strict_types=1);
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/_migas.php';

$p = IOTienda::producto((string) ($_GET['p'] ?? ''));
if (!$p) {
    http_response_code(404);
    encabezado('Tema no encontrado', 'catalogo');
    echo '<section class="container py-5"><div class="iot-vacio">Este tema no existe o ya no está disponible. <a href="' . e(ruta('catalogo')) . '">Volver al catálogo</a></div></section>';
    pie(); exit;
}
[$tipo, $accesoTitulo, $accesoTexto] = IOTienda::textoAcceso($p);
$enCarrito = in_array((int) $p['producto_id'], IOTienda::carritoIds(), true);
$relacionados = IOTienda::catalogo(['categoria' => $p['categoria'], 'orden' => 'populares'], 1, 4)['productos'];
$relacionados = array_slice(array_filter($relacionados, fn($x) => (int) $x['producto_id'] !== (int) $p['producto_id']), 0, 3);

encabezado($p['titulo'], 'catalogo', (string) $p['resumen'], rproducto($p), (string) ($p['imagen'] ?: '/portada/img/conferencias.webp'));
?>
<?= IOAgentes::jsonLd(IOAgentes::curso(IOAgentes::dominio(), $p)) ?>
<section class="container iot-ficha">
  <?= migas([
      ['texto' => 'Inicio', 'url' => ruta('inicio')],
      ['texto' => 'Catálogo', 'url' => ruta('catalogo')],
      ['texto' => IOTienda::CATEGORIAS[$p['categoria']] ?? '', 'url' => ruta('catalogo') . '?categoria=' . rawurlencode((string) $p['categoria'])],
      ['texto' => $p['titulo']],
  ]) ?>
  <div class="row g-5">
    <div class="col-lg-7">
      <div class="iot-video" <?= $p['trailer_vimeo'] ? 'data-vimeo="' . e($p['trailer_vimeo']) . '" role="button" tabindex="0" aria-label="Ver muestra"' : '' ?>>
        <img src="<?= e($p['imagen'] ?: '/portada/img/conferencias.webp') ?>" alt="">
        <?php if ($p['trailer_vimeo']): ?><span class="play"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg></span><span class="muestra">Ver muestra</span><?php endif; ?>
      </div>
      <h1><?= e($p['titulo']) ?></h1>
      <div class="iot-meta">
        <?= estrellas($p) ?>
        <?php if ((int) $p['duracion_seg'] > 0): ?><span><?= e(gmdate((int) $p['duracion_seg'] >= 3600 ? 'G \h i \m\i\n' : 'i \m\i\n', (int) $p['duracion_seg'])) ?></span><?php endif; ?>
        <?php if ($p['incluye']): ?><span>Incluye: <?= e($p['incluye']) ?></span><?php endif; ?>
      </div>
      <p class="lead-io"><?= nl2br(e($p['descripcion'] ?: $p['resumen'])) ?></p>
      <?php $ideas = array_filter(array_map('trim', explode("\n", (string) $p['aprenderas']))); if ($ideas): ?>
        <h2>Lo que vas a comprender</h2>
        <ul class="iot-aprender"><?php foreach ($ideas as $i): ?><li><?= e($i) ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
    </div>
    <div class="col-lg-5">
      <aside class="iot-compra">
        <?= precio($p) ?>
        <div class="text-muted small">IVA incluido</div>
        <div class="iot-acceso <?= e($tipo) ?>">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
          <div><b><?= e($accesoTitulo) ?></b><span><?= e($accesoTexto) ?></span></div>
        </div>
        <?php if ($enCarrito): ?>
          <a class="iot-btn grande" href="<?= e(ruta('carrito')) ?>">Ir a pagar</a>
        <?php else: ?>
          <?= form_agregar($p, 'Comprar ahora', 'iot-btn grande', ruta('carrito')) ?>
          <div class="mt-2"><?= form_agregar($p, 'Agregar al carrito', 'iot-btn sec grande', '/producto/' . rawurlencode($p['slug'])) ?></div>
        <?php endif; ?>
        <p class="nota">Pago seguro con tarjeta u OXXO · Factura disponible</p>
      </aside>
    </div>
  </div>
  <?php if ($relacionados): ?>
    <h2>También te puede interesar</h2>
    <div class="iot-grid">
      <?php foreach ($relacionados as $x): ?>
      <article class="iot-card">
        <div class="img"><img src="<?= e($x['imagen'] ?: '/portada/img/conferencias-800.webp') ?>" alt="" loading="lazy"><?= insignia($x) ?></div>
        <div class="cuerpo"><h3><a href="<?= e(rproducto($x)) ?>"><?= e($x['titulo']) ?></a></h3><div class="pie"><div><?= precio($x) ?></div></div></div>
      </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<script>
document.querySelectorAll('.iot-video[data-vimeo]').forEach(function (b) {
  function cargar() { if (b.querySelector('iframe')) return; var f = document.createElement('iframe');
    f.src = 'https://player.vimeo.com/video/' + encodeURIComponent(b.dataset.vimeo) + '?autoplay=1&title=0&byline=0&portrait=0&dnt=1';
    f.allow = 'autoplay; fullscreen; picture-in-picture'; f.title = 'Muestra'; b.appendChild(f); }
  b.addEventListener('click', cargar); b.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); cargar(); } });
});
</script>
<?php pie();
