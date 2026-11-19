<?php
declare(strict_types=1);
require __DIR__ . '/_layout.php';
header('Cache-Control: no-store');
$cliente = IO::clienteActual();
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$pedidos = $cliente ? IO::todos('SELECT p.*, (SELECT MAX(r.monto_centavos) FROM io_reembolsos r WHERE r.pedido_id = p.id AND r.estado IN ("parcial","completo")) AS devuelto
    FROM io_pedidos p WHERE p.cliente_id = ? ORDER BY p.id DESC LIMIT 20 OFFSET ' . (($pagina - 1) * 20), [(int) $cliente['id']]) : [];
encabezado('Mis pedidos y facturas');
?>
<section class="container py-5"><h1>Mis pedidos y facturas</h1>
<?php if (!$cliente): ?><p>Inicia sesión para consultar tus compras.</p><a class="iot-btn" href="/?io_acceso=login&amp;io_destino=pedidos">Iniciar sesión</a>
<?php elseif (!$pedidos): ?><p>No hay pedidos para mostrar.</p>
<?php else: foreach ($pedidos as $p): ?><article class="iot-total mb-3"><h2><?= e($p['folio']) ?></h2><p><?= e(IOTienda::dinero((float) $p['total'])) ?> MXN · <?= e($p['estado']) ?></p>
<?php if ($p['devuelto']): ?><p>Devolución confirmada: <?= e(IOTienda::dinero((int) $p['devuelto'] / 100)) ?> MXN.</p><?php endif; ?>
<a class="iot-btn sec" href="gracias.php?folio=<?= e(rawurlencode($p['folio'])) ?>">Ver pedido</a>
<?php if ($p['estado'] === 'pagado'): ?><a class="iot-btn sec" href="facturacion.php?pedido=<?= (int) $p['id'] ?>">Factura CFDI</a><?php endif; ?></article>
<?php endforeach; ?><a href="?pagina=<?= $pagina + 1 ?>">Más pedidos</a><?php endif; ?></section>
<?php pie();
