<?php
declare(strict_types=1);
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/_migas.php';
header('Cache-Control: no-store');
$promocion = IOPromociones::actual();
encabezado('Productos seleccionados en oferta', 'catalogo');
?>
<section class="container">
  <?= migas([['texto' => 'Inicio', 'url' => ruta('inicio')], ['texto' => 'Catálogo', 'url' => ruta('catalogo')], ['texto' => 'Ofertas']]) ?>
  <div class="iot-cabecera"><h1>Productos seleccionados en oferta</h1>
    <?php if ($promocion): ?><p>Consulta el precio y las condiciones de cada producto. Selección vigente hasta <?= IO::htmlFecha(date('Y-m-d H:i:s', $promocion['fin'])) ?>.</p><?php endif; ?>
  </div>
  <?php if (!$promocion): ?>
    <div class="iot-vacio">No hay una campaña vigente en este momento. <a href="<?= e(ruta('catalogo')) ?>">Ver catálogo</a></div>
  <?php else: ?>
  <div class="iot-grid mb-5">
    <?php foreach ($promocion['productos'] as $p): ?>
    <article class="iot-card">
      <div class="img"><img src="<?= e($p['imagen'] ?: '/portada/img/conferencias-800.webp') ?>" alt="" loading="lazy"><?= insignia($p) ?></div>
      <div class="cuerpo">
        <span class="cat"><?= e(IOTienda::CATEGORIAS[$p['categoria']] ?? '') ?> · <?= (int) $p['descuento_pct'] ?>% de descuento</span>
        <h3><a href="<?= e(rproducto($p)) ?>"><?= e($p['titulo']) ?></a></h3>
        <?= estrellas($p) ?><p><?= e($p['resumen']) ?></p>
        <div class="pie"><div><?= precio($p) ?></div><?= form_agregar($p, 'Agregar', 'iot-btn sec', '/io/tienda/ofertas.php') ?></div>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
<?php pie();
