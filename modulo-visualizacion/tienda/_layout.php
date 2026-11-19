<?php
/**
 * Plantilla común de la tienda 2.0 (encabezado, banner de ofertas, pie).
 * Las rutas son las mismas URL que ya usa el sitio (/nosotros, /conferencias-en-linea, /carrito...);
 * en producción se conectan con las reglas de reescritura de GUIA-DESPLIEGUE.md.
 */
declare(strict_types=1);
ini_set('display_errors', '0');
require_once __DIR__ . '/../lib/Tienda.php';
require_once __DIR__ . '/../lib/Promociones.php';

const IO_RUTAS = [
    'inicio' => '/', 'nosotros' => '/nosotros', 'catalogo' => '/conferencias-en-linea', 'membresias' => '/io/tienda/membresias.php',
    'biblioteca' => '/io/public/mi-biblioteca.php', 'carrito' => '/carrito', 'ayuda' => '/ayuda.html', 'en_vivo' => '/en-vivo',
];
function ruta(string $k): string { return IO_RUTAS[$k] ?? '/'; }
function rproducto(array $p): string { return '/producto/' . rawurlencode($p['slug']); }
function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

function encabezado(string $titulo, string $activo = '', string $descripcion = ''): void
{
    $cliente = IO::clienteActual();
    $n = count(IOTienda::carritoIds());
    $promocion = IOPromociones::actual();
    $nav = ['inicio' => 'Inicio', 'nosotros' => 'Nosotros', 'catalogo' => 'Catálogo', 'en_vivo' => 'En vivo', 'membresias' => 'Membresías', 'biblioteca' => 'Mi biblioteca'];
    ?><!DOCTYPE html>
<html lang="es-MX">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> · Inteligencia Objetiva</title>
<?php if ($descripcion): ?><meta name="description" content="<?= e($descripcion) ?>"><?php endif; ?>
<script>(function(){var t=null;try{t=localStorage.getItem('io-theme')}catch(e){}if(!t)t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';document.documentElement.setAttribute('data-io-theme',t)})();</script>
<meta name="color-scheme" content="light dark">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="/tema/io-theme.css?v=2.0.0">
<link rel="stylesheet" href="/portada/portada.css?v=2.0.0">
<link rel="stylesheet" href="/io/tienda/tienda.css?v=2.0.2">
</head>
<body class="io2 iot">
<?php if ($promocion): ?>
<div id="carruselPromociones" class="promociones-carrusel-container">
  <div class="anuncio-promo bg-gradient-primary">
    <input type="hidden" class="fechaPromo" value="<?= e($promocion['fin_iso']) ?>">
    <div class="d-flex promoInline align-items-center">
      <svg class="promoImg" viewBox="0 0 24 24" stroke-width="1.5" stroke="#fff" fill="none" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="8" width="18" height="4" rx="1"/><line x1="12" y1="8" x2="12" y2="21"/><path d="M19 12v7a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0 -5a4.8 8 0 0 1 4.5 5a4.8 8 0 0 1 4.5 -5a2.5 2.5 0 0 1 0 5"/></svg>
      <div class="promo"><p class="text-white mb-0">Hasta <?= (int) $promocion['maximo_pct'] ?>% de descuento en productos seleccionados</p></div>
    </div>
    <div class="d-flex align-items-center promoInline">
      <ul class="contenedor-temporizador promoInline" data-io-cuenta>
        <li class="info"><span class="detalle promo-days">00</span>Días</li><li class="info"><span class="detalle promo-hours">00</span>Horas</li>
        <li class="info"><span class="detalle promo-minutes">00</span>Minutos</li><li class="info"><span class="detalle promo-seconds">00</span>Segundos</li>
      </ul>
      <div class="promoInline"><a href="/io/tienda/ofertas.php" class="btn btnPromo">Ver productos seleccionados</a></div>
    </div>
  </div>
</div>
<?php endif; ?>
<header class="iot-header">
  <div class="container d-flex align-items-center gap-3">
    <a class="iot-logo" href="<?= e(ruta('inicio')) ?>" aria-label="Inteligencia Objetiva, inicio">
      <img class="io-logo-img" src="/img/icons/logo.png" alt="Inteligencia Objetiva · Martín Aparicio" width="96" height="96">
    </a>
    <nav class="iot-nav ms-auto" aria-label="Principal">
      <ul>
        <?php foreach ($nav as $k => $t): ?><li><a class="nav-link<?= $activo === $k ? ' active' : '' ?>" href="<?= e(ruta($k)) ?>"><?= e($t) ?></a></li><?php endforeach; ?>
        <li><a class="nav-link iot-ayuda" href="<?= e(ruta('ayuda')) ?>">Ayuda</a></li>
      </ul>
    </nav>
    <a class="iot-carrito" href="<?= e(ruta('carrito')) ?>" aria-label="Carrito, <?= $n ?> productos">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 8H6.2"/></svg>
      <?php if ($n): ?><b><?= $n ?></b><?php endif; ?>
    </a>
    <?php if ($cliente): ?><span class="iot-usuario" title="<?= e($cliente['email']) ?>"><?= e(mb_substr($cliente['nombre'] ?: $cliente['email'], 0, 1)) ?></span><?php endif; ?>
    <button class="iot-menu" type="button" aria-label="Menú" onclick="document.body.classList.toggle('iot-menu-abierto')"><span></span><span></span></button>
  </div>
</header>
<main>
<?php if (!empty($_GET['error'])): ?><div class="container"><div class="iot-aviso error" role="alert"><?= e(mb_substr((string) $_GET['error'], 0, 160)) ?></div></div><?php endif; ?>
<?php
}

function pie(): void
{ ?>
</main>
<footer class="iot-pie">
  <div class="container">
    <div class="row g-4">
      <div class="col-md-5"><a class="iot-pie-marca" href="<?= e(ruta('inicio')) ?>"><img class="io-logo-img" src="/img/icons/logo.png" alt="Inteligencia Objetiva · Martín Aparicio" width="96" height="96" loading="lazy"></a><p>La primera escuela del pensamiento. Aprende a ver la vida tal como es.</p></div>
      <div class="col-6 col-md-2"><b>Explora</b><a href="<?= e(ruta('catalogo')) ?>">Catálogo</a><a href="<?= e(ruta('membresias')) ?>">Membresías</a><a href="<?= e(ruta('nosotros')) ?>">Nosotros</a></div>
      <div class="col-6 col-md-2"><b>Tu cuenta</b><a href="<?= e(ruta('biblioteca')) ?>">Mi biblioteca</a><a href="/io/tienda/mis-pedidos.php">Mis pedidos y facturas</a><a href="<?= e(ruta('carrito')) ?>">Carrito</a><a href="<?= e(ruta('ayuda')) ?>">Ayuda</a></div>
      <div class="col-md-3"><b>Contacto</b><span>WhatsApp +52 55 1951 4412</span><span>contacto@iobjetiva.com</span></div>
    </div>
    <p class="iot-legal">© <?= date('Y') ?> Inteligencia Objetiva · <a href="/terminos_y_condiciones.html">Términos y condiciones</a> · <a href="/aviso_de_privacidad.html">Aviso de privacidad</a></p>
  </div>
</footer>
<script>
// Contador del banner (usa la fecha del campo .fechaPromo)
(function(){var f=document.querySelector('.fechaPromo'),u=document.querySelector('[data-io-cuenta]');if(!f||!u)return;var fin=Date.parse(f.value),cont=document.getElementById('carruselPromociones');
function p(n){return String(n).padStart(2,'0')}function t(){var s=Math.floor((fin-Date.now())/1000);if(!Number.isFinite(s)||s<=0){cont.hidden=true;return}u.querySelector('.promo-days').textContent=p(Math.floor(s/86400));u.querySelector('.promo-hours').textContent=p(Math.floor(s%86400/3600));u.querySelector('.promo-minutes').textContent=p(Math.floor(s%3600/60));u.querySelector('.promo-seconds').textContent=p(s%60)}t();setInterval(t,1000)})();
</script>
<script src="/tema/io-theme.js?v=2.0.2" defer></script>
<script src="/portada/portada.js?v=2.0.1" defer></script>
</body>
</html>
<?php
}

/** Insignia del tipo de acceso. */
function insignia(?array $p): string
{
    [$c, $t] = IOTienda::textoAcceso($p);
    return '<span class="io-badge-acceso ' . ($c === 'ventana' ? '' : $c) . '">' . e($t) . '</span>';
}

function estrellas(array $p): string
{
    if ((int) $p['resenas'] === 0) { return '<span class="iot-estrellas vacio">Sin reseñas aún</span>'; }
    $r = (float) $p['rating'];
    return '<span class="iot-estrellas" aria-label="' . e(number_format($r, 1)) . ' de 5"><span style="--r:' . e($r / 5 * 100) . '%"></span>' . e(number_format($r, 1)) . ' · ' . (int) $p['resenas'] . ' reseñas</span>';
}

function precio(array $p): string
{
    $h = '<span class="iot-precio">' . e(IOTienda::dinero((float) $p['precio_mxn'])) . '<small> MXN</small></span>';
    if ($p['precio_anterior'] !== null && (float) $p['precio_anterior'] > (float) $p['precio_mxn']) {
        $h = '<s class="iot-antes">' . e(IOTienda::dinero((float) $p['precio_anterior'])) . '</s>' . $h;
    }
    return $h;
}

function form_agregar(array $p, string $texto = 'Agregar', string $clase = 'iot-btn', string $volver = ''): string
{
    return '<form method="post" action="/io/api/tienda.php" class="m-0"><input type="hidden" name="csrf" value="' . e(IOTienda::csrf()) . '">'
        . '<input type="hidden" name="accion" value="agregar"><input type="hidden" name="producto_id" value="' . (int) $p['producto_id'] . '">'
        . '<input type="hidden" name="volver" value="' . e($volver ?: ruta('carrito')) . '"><button class="' . e($clase) . '">' . e($texto) . '</button></form>';
}
