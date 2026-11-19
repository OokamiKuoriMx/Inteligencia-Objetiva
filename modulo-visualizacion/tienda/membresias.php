<?php
declare(strict_types=1);
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/../lib/Membresias.php';
header('Cache-Control: no-store');
$cliente = IO::clienteActual();
$planes = IOMembresias::planes();
$disponible = false;
try { $disponible = IOTienda::pasarela() === 'stripe' && parse_url((string) (IO::config()['dominio'] ?? ''), PHP_URL_SCHEME) === 'https'; } catch (IOError $e) {}
$mias = $cliente ? IOMembresias::delCliente((int) $cliente['id']) : [];
$csrf = IOTienda::csrf();
encabezado('Membresías', 'membresias');
?>
<section class="container py-5">
  <h1>Membresías</h1>
  <p>Suscríbete al contenido incluido en tu plan. El cobro se renueva con la periodicidad indicada hasta que canceles.</p>
  <?php if (($_GET['resultado'] ?? '') === 'confirmando'): ?>
    <div class="iot-aviso" role="status">Estamos esperando la confirmación del primer pago. El regreso de Stripe no activa el contenido por sí solo. Actualiza esta página en unos momentos.</div>
  <?php elseif (($_GET['resultado'] ?? '') === 'cancelacion'): ?>
    <div class="iot-aviso" role="status">La cancelación quedó programada. Conservas el acceso hasta el final del período ya pagado.</div>
  <?php endif; ?>
  <?php if (!$planes): ?><p>Las nuevas suscripciones no están disponibles por el momento.</p><?php endif; ?>
  <div class="row g-4 mt-2">
  <?php foreach ($planes as $clave => $p): ?>
    <article class="col-md-6"><div class="iot-total h-100">
      <h2><?= e($p['nombre']) ?></h2>
      <p><?= e(IOTienda::dinero((int) $p['importe_centavos'] / 100)) ?> MXN por <?= $p['intervalo'] === 'year' ? 'año' : 'mes' ?>, IVA incluido.</p>
      <p>Incluye <?= count($p['productos']) ?> contenidos. Sus reglas de reproducción y las condiciones del plan se muestran antes del pago.</p>
      <ul><?php foreach ($p['productos'] as $productoId): $politica = IOAccesos::politica($productoId); ?>
        <li><?= e($politica['titulo']) ?> · <?= e(IOTienda::textoAcceso(['acceso_tipo' => $politica['tipo']] + $politica)[1]) ?></li>
      <?php endforeach; ?></ul>
      <p class="small">El acceso incluido dura únicamente mientras esté pagado el período. Las reglas de visualización única, si las hay, se conservan al renovar.</p>
      <?php if (!$disponible): ?>
        <p>Este plan está en configuración. Las nuevas suscripciones todavía no están habilitadas.</p>
      <?php elseif ($cliente): ?>
        <form method="post" action="/io/api/membresias.php">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="accion" value="checkout"><input type="hidden" name="plan" value="<?= e($clave) ?>">
          <button class="iot-btn">Suscribirme a <?= e($p['nombre']) ?></button>
        </form>
      <?php else: ?><a class="iot-btn" href="/?io_acceso=login&amp;io_destino=membresias">Iniciar sesión para suscribirme</a><?php endif; ?>
    </div></article>
  <?php endforeach; ?>
  </div>
  <?php if ($mias): ?>
    <h2 class="mt-5">Mis suscripciones</h2>
    <?php foreach ($mias as $m): ?>
      <article class="iot-total mb-3"><h3><?= e($planes[$m['plan']]['nombre'] ?? $m['plan']) ?></h3>
      <p>Estado: <?= e(['pendiente' => 'Esperando el primer pago', 'activa' => 'Activa', 'morosa' => 'Pago pendiente', 'cancelada' => 'Cancelada', 'expirada' => 'Checkout vencido'][$m['estado']] ?? $m['estado']) ?>.
      <?php if ($m['pagado_hasta']): ?> Período pagado hasta <?= IO::htmlFecha($m['pagado_hasta']) ?>.<?php endif; ?>
      <?php if ($m['cancelar_al_final'] && $m['estado'] !== 'cancelada'): ?> No se renovará al terminar este período.<?php endif; ?></p>
      <?php if ($m['suscripcion_ref']): ?>
        <div class="d-flex gap-2 flex-wrap">
        <form method="post" action="/io/api/membresias.php"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="accion" value="portal"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><button class="iot-btn sec">Administrar método de pago</button></form>
        <?php if (!$m['cancelar_al_final'] && $m['estado'] !== 'cancelada'): ?>
        <form method="post" action="/io/api/membresias.php"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="accion" value="cancelar"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><button class="iot-btn sec">Cancelar al terminar el período pagado</button></form>
        <?php endif; ?></div>
      <?php endif; ?></article>
    <?php endforeach; ?>
  <?php endif; ?>
</section>
<?php pie();
