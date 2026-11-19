<?php
declare(strict_types=1);
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/_migas.php';
require_once __DIR__ . '/../lib/Descuentos.php';
header('Cache-Control: no-store');

$r = IOTienda::resumen(IOTienda::carritoIds());
$cliente = IO::clienteActual();
$codigoCupon = (string) ($_SESSION['io_cupon'] ?? ''); $errorCupon = ''; $descuentoCupon = 0;
if ($codigoCupon !== '') {
    try {
        $conCupon = IODescuentos::calcular($r['items'], $codigoCupon, (int) ($cliente['id'] ?? 0));
        $descuentoCupon = $conCupon['descuento_centavos'] / 100;
        foreach (['items', 'subtotal', 'iva', 'total'] as $campo) { $r[$campo] = $conCupon[$campo]; }
    } catch (IOError $e) { $errorCupon = $e->getMessage(); }
}
try { $pasarela = IOTienda::pasarela(); } catch (IOError $e) { $pasarela = null; }
$csrf = IOTienda::csrf();

encabezado('Carrito', '');
?>
<section class="container iot-carrito-pag">
  <?= migas([['texto' => 'Inicio', 'url' => ruta('inicio')], ['texto' => 'Carrito']]) ?>
  <div class="iot-cabecera pb-3"><h1>Tu carrito</h1></div>
  <div class="iot-pasos"><span class="on"><i>1</i>Resumen</span><span id="paso2"><i>2</i>Pago</span><span><i>3</i>Confirmación</span></div>

  <?php if (!$r['items']): ?>
    <div class="iot-vacio">Tu carrito está vacío. <a href="<?= e(ruta('catalogo')) ?>">Explora el catálogo</a></div>
  <?php else: ?>
  <div class="row g-5">
    <div class="col-lg-7">
      <?php foreach ($r['items'] as $i): [, $acc, $condiciones] = IOTienda::textoAcceso($i); ?>
      <div class="iot-linea">
        <img src="<?= e($i['imagen'] ?: '/portada/img/conferencias-800.webp') ?>" alt="">
        <div class="info">
          <a href="<?= e(rproducto($i)) ?>"><?= e($i['titulo']) ?></a>
          <small><?= e($acc) ?></small>
          <small><?= e($condiciones) ?></small>
          <form method="post" action="/io/api/tienda.php" class="d-inline"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="accion" value="quitar"><input type="hidden" name="producto_id" value="<?= (int) $i['producto_id'] ?>"><input type="hidden" name="volver" value="<?= e(ruta('carrito')) ?>"><button class="quitar">Quitar</button></form>
        </div>
        <div class="text-end"><?= precio($i) ?></div>
      </div>
      <?php endforeach; ?>
      <p class="text-muted small mt-3">Revisa las condiciones de acceso de cada producto antes de pagar.</p>
    </div>

    <div class="col-lg-5">
      <div class="iot-total" id="caja">
        <div class="fila"><span>Subtotal</span><span><?= e(IOTienda::dinero($r['subtotal'])) ?></span></div>
        <div class="fila"><span>IVA (16%)</span><span><?= e(IOTienda::dinero($r['iva'])) ?></span></div>
        <?php if ($r['ahorro'] > 0): ?><div class="fila ahorro"><span>Ahorras</span><span><?= e(IOTienda::dinero($r['ahorro'])) ?></span></div><?php endif; ?>
        <?php if ($descuentoCupon > 0): ?><div class="fila ahorro"><span>Cupón <?= e($codigoCupon) ?></span><span>−<?= e(IOTienda::dinero($descuentoCupon)) ?></span></div><?php endif; ?>
        <div class="fila grande"><span>Total</span><span><?= e(IOTienda::dinero($r['total'])) ?> <small class="fw-normal fs-6">MXN</small></span></div>

        <?php if ($cliente): ?>
          <form method="post" action="/io/api/tienda.php" class="mt-3">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="volver" value="<?= e(ruta('carrito')) ?>">
            <label for="codigo-cupon" class="form-label">Código de descuento</label>
            <div class="d-flex gap-2"><input id="codigo-cupon" name="codigo" class="form-control" maxlength="40" value="<?= e($codigoCupon) ?>" autocomplete="off"><button class="iot-btn sec" name="accion" value="cupon">Aplicar</button></div>
            <?php if ($codigoCupon !== ''): ?><button class="quitar mt-2" name="accion" value="quitar_cupon">Quitar cupón</button><?php endif; ?>
          </form>
          <?php if ($errorCupon !== ''): ?><div class="iot-aviso error mt-2" role="alert"><?= e($errorCupon) ?> Quita o cambia el cupón antes de pagar.</div><?php endif; ?>
        <?php endif; ?>

        <?php if (!$cliente): ?>
          <div class="iot-aviso mt-3">Inicia sesión o crea tu cuenta para pagar.</div>
          <div class="d-flex gap-2 flex-wrap mt-3">
            <a class="iot-btn" href="/?io_acceso=login">Iniciar sesión</a>
            <a class="iot-btn sec" href="/?io_acceso=registro">Crear cuenta</a>
          </div>
        <?php elseif (!$pasarela): ?>
          <div class="iot-aviso error mt-3">Los pagos no están disponibles en este momento.</div>
        <?php else: ?>
          <div id="pago-inicio"><button class="iot-btn grande mt-3" id="btnPagar">Continuar al pago</button></div>
          <div id="pago-form" hidden>
            <?php if ($pasarela === 'stripe'): ?>
              <div id="stripe-element" class="mt-3"></div>
              <button class="iot-btn grande" id="btnConfirmar">Pagar <?= e(IOTienda::dinero($r['total'])) ?></button>
            <?php else: ?>
              <div class="iot-metodos" role="radiogroup" aria-label="Método de pago">
                <label class="iot-metodo"><input type="radio" name="metodo" value="card" checked><div><b>Tarjeta de crédito o débito</b><small>Visa, Mastercard, American Express</small></div></label>
                <label class="iot-metodo"><input type="radio" name="metodo" value="oxxo"><div><b>Efectivo en OXXO</b><small>Recibes una ficha; tu acceso se activa al pagar</small></div></label>
                <label class="iot-metodo"><input type="radio" name="metodo" value="rechazada"><div><b>Tarjeta rechazada</b><small>Para probar un pago fallido</small></div></label>
              </div>
              <button class="iot-btn grande" id="btnConfirmar">Pagar <?= e(IOTienda::dinero($r['total'])) ?></button>
              <div class="iot-demo">Modo demo local: no se hace ningún cargo. Con llaves de Stripe en <code>config.php</code> aquí aparece el formulario seguro de Stripe.</div>
            <?php endif; ?>
          </div>
          <div id="pago-error" class="iot-aviso error mt-3" hidden></div>
        <?php endif; ?>
        <p class="nota text-center text-muted small mt-3 mb-0">Pago 100% seguro. Precios con IVA incluido.</p>
      </div>
    </div>
  </div>
  <?php endif; ?>
</section>

<?php if ($r['items'] && $cliente && $pasarela): ?>
<?php if ($pasarela === 'stripe'): ?><script src="https://js.stripe.com/v3/"></script><?php endif; ?>
<script>
(function () {
  var csrf = <?= json_encode($csrf) ?>, pasarela = <?= json_encode($pasarela) ?>, pedido = null, stripe = null, elements = null;
  var err = document.getElementById('pago-error');
  function api(datos) {
    datos.csrf = csrf;
    return fetch('/io/api/tienda.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(datos) })
      .then(function (r) { return r.json(); });
  }
  function fallo(m) { err.textContent = m || 'No se pudo completar el pago.'; err.hidden = false; }
  document.getElementById('btnPagar').addEventListener('click', function () {
    var b = this; b.disabled = true; b.textContent = 'Preparando pago…'; err.hidden = true;
    api({ accion: 'pagar' }).then(function (j) {
      if (!j.ok) { b.disabled = false; b.textContent = 'Continuar al pago'; return fallo(j.mensaje); }
      pedido = j; document.getElementById('pago-inicio').hidden = true; document.getElementById('pago-form').hidden = false;
      document.getElementById('btnConfirmar').textContent = 'Pagar ' + Number(j.total).toLocaleString('es-MX', { style: 'currency', currency: 'MXN' }) + ' MXN';
      document.getElementById('paso2').classList.add('on');
      if (pasarela === 'stripe') {
        stripe = Stripe(j.publica);
        var oscuro = document.documentElement.getAttribute('data-io-theme') === 'dark';
        elements = stripe.elements({ clientSecret: j.client_secret, locale: 'es-419', appearance: { theme: oscuro ? 'night' : 'stripe', variables: { colorPrimary: '#cf1717', borderRadius: '12px', fontFamily: 'Roboto, system-ui, sans-serif' } } });
        elements.create('payment', { layout: 'tabs' }).mount('#stripe-element');
      }
    }).catch(function () { b.disabled = false; b.textContent = 'Continuar al pago'; fallo('Sin conexión. Intenta de nuevo.'); });
  });
  document.getElementById('btnConfirmar').addEventListener('click', function () {
    var b = this; b.disabled = true; var txt = b.textContent; b.textContent = 'Procesando…'; err.hidden = true;
    var gracias = location.origin + '/io/tienda/gracias.php?folio=' + encodeURIComponent(pedido.folio);
    if (pasarela === 'stripe') {
      stripe.confirmPayment({ elements: elements, confirmParams: { return_url: gracias } }).then(function (res) {
        if (res.error) { b.disabled = false; b.textContent = txt; fallo(res.error.message); }
      });
      return;
    }
    var metodo = (document.querySelector('input[name=metodo]:checked') || {}).value || 'card';
    api({ accion: 'pagar_demo', folio: pedido.folio, metodo: metodo }).then(function (j) {
      if (!j.ok) { b.disabled = false; b.textContent = txt; return fallo(j.mensaje); }
      location.href = gracias;
    });
  });
})();
</script>
<?php endif; ?>
<?php pie();
