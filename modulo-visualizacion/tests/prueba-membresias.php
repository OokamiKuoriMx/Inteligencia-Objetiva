<?php
/** Suite aislada de Stripe Billing: transporte simulado, sin llamadas ni cargos reales. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/Membresias.php';
$pdo = new PDO(getenv('IO_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3307;charset=utf8mb4', getenv('IO_TEST_USER') ?: 'root', getenv('IO_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$base = 'io_prueba_membresias_' . bin2hex(random_bytes(5));
$pdo->exec("CREATE DATABASE `$base` CHARACTER SET utf8mb4");
$ok = 0; $fallos = 0;
function verificarM(string $n, bool $v): void { global $ok, $fallos; if ($v) { $ok++; echo "  ✔ $n\n"; } else { $fallos++; echo "  ✘ $n\n"; } }
function errorM(string $n, string $codigo, callable $fn): void { try { $fn(); verificarM($n, false); } catch (IOError $e) { verificarM($n, $e->codigo === $codigo); } }
function eventoM(string $tipo, array $obj, int $fecha): array { return ['id' => 'evt_' . bin2hex(random_bytes(4)), 'created' => $fecha, 'type' => $tipo, 'data' => ['object' => $obj]]; }
try {
    $pdo->exec("USE `$base`");
    foreach (glob(__DIR__ . '/../sql/*.sql') as $archivo) {
        foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents($archivo))))) as $sql) { $pdo->exec($sql); }
    }
    date_default_timezone_set('America/Mexico_City');
    $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    $secretoWebhook = 'whsec_fixture_' . bin2hex(random_bytes(8));
    $cfg = ['zona_horaria' => 'America/Mexico_City', 'dominio' => 'https://prueba.example', 'tienda' => ['stripe_secreta' => 'sk_test_fixture', 'stripe_webhook' => $secretoWebhook],
        'membresias' => ['habilitadas' => true, 'planes' => ['premium' => ['nombre' => 'Premium', 'stripe_price' => 'price_premium',
        'importe_centavos' => 50000, 'moneda' => 'mxn', 'intervalo' => 'month', 'productos' => [1, 2]]]]];
    IO::usarConexion($pdo, $cfg);
    $pol = ['vimeo_id' => '12345', 'vimeo_hash' => null, 'duracion_seg' => 600, 'horas_ventana' => 48, 'cuenta_desde' => 'primera_reproduccion',
        'dias_para_empezar' => 30, 'umbral_pct' => 95, 'retroceso_seg' => 30, 'permitir_adelantar' => 0, 'pausa_max_min' => 60,
        'reanudaciones_max' => 2, 'gracia_min' => 120];
    IOAdmin::guardarPolitica(['id' => null, 'email' => 'prueba@example.test'], ['producto_id' => 1, 'titulo' => 'Permanente', 'tipo' => 'permanente'] + $pol, 'Prueba');
    IOAdmin::guardarPolitica(['id' => null, 'email' => 'prueba@example.test'], ['producto_id' => 2, 'titulo' => 'Única', 'tipo' => 'unica'] + $pol, 'Prueba');
    $cliente = ['id' => 101, 'email' => 'alumna@example.test', 'nombre' => 'Alumna'];
    $llamadas = []; $metadata = []; $maloPrecio = false; $estadoCheckout = 'open';
    IOStripe::usarTransporteParaPruebas(function ($metodo, $ruta, $datos, $key) use (&$llamadas, &$metadata, &$maloPrecio, &$estadoCheckout) {
        $llamadas[] = [$metodo, $ruta, $datos, $key];
        if ($ruta === '/v1/prices/price_premium') { return ['id' => 'price_premium', 'active' => true, 'unit_amount' => $maloPrecio ? 100 : 50000,
            'currency' => 'mxn', 'type' => 'recurring', 'recurring' => ['interval' => 'month', 'interval_count' => 1]]; }
        if ($ruta === '/v1/checkout/sessions') { $metadata = $datos['metadata']; return ['id' => 'cs_test_fixture', 'url' => 'https://checkout.stripe.com/c/pay/fixture', 'expires_at' => time() + 1800]; }
        if ($ruta === '/v1/checkout/sessions/cs_test_fixture') { return ['id' => 'cs_test_fixture', 'url' => 'https://checkout.stripe.com/c/pay/fixture', 'status' => $estadoCheckout, 'expires_at' => time() + 1800]; }
        if ($ruta === '/v1/billing_portal/sessions') { return ['url' => 'https://billing.stripe.com/p/session/fixture']; }
        if ($ruta === '/v1/subscriptions/sub_fixture') {
            return ['id' => 'sub_fixture', 'customer' => 'cus_fixture', 'metadata' => $metadata, 'cancel_at_period_end' => true];
        }
        if ($ruta === '/v1/subscriptions/sub_ajena') { return ['id' => 'sub_ajena', 'customer' => 'cus_ajena', 'metadata' => []]; }
        throw new RuntimeException('Llamada no prevista: ' . $ruta);
    });
    verificarM('plan válido disponible', count(IOMembresias::planes()) === 1);
    IO::usarConexion($pdo, ['membresias' => ['habilitadas' => false]] + $cfg);
    verificarM('sin habilitación no ofrece nuevas suscripciones', IOMembresias::planes() === []);
    IO::usarConexion($pdo, $cfg);
    $maloPrecio = true;
    errorM('el precio remoto debe coincidir antes de crear Checkout', 'precio_membresia', fn() => IOMembresias::checkout($cliente, 'premium'));
    verificarM('precio inconsistente no reserva una compra', (int) IO::uno('SELECT COUNT(*) n FROM io_membresias')['n'] === 0);
    $maloPrecio = false;
    $url = IOMembresias::checkout($cliente, 'premium');
    verificarM('genera Checkout de suscripción alojado en Stripe', $url === 'https://checkout.stripe.com/c/pay/fixture');
    $m = IO::uno('SELECT * FROM io_membresias WHERE cliente_id = 101');
    verificarM('primer Checkout no concede acceso', $m['estado'] === 'pendiente' && (int) IO::uno('SELECT COUNT(*) n FROM io_accesos')['n'] === 0);
    IOMembresias::checkout($cliente, 'premium');
    verificarM('repetir checkout recupera sesión existente sin un segundo POST', (int) IO::uno('SELECT COUNT(*) n FROM io_membresias')['n'] === 1 && end($llamadas)[0] === 'GET');
    IO::ejecutar('UPDATE io_membresias SET checkout_vence = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?', [$m['id']]);
    $estadoCheckout = 'complete';
    errorM('checkout local vencido pero completo en Stripe no crea otra suscripción', 'membresia_confirmando', fn() => IOMembresias::checkout($cliente, 'premium'));
    verificarM('esperar webhook conserva la misma reserva de contratación', (int) IO::uno('SELECT COUNT(*) n FROM io_membresias')['n'] === 1 && IO::uno('SELECT clave_activa FROM io_membresias WHERE id = ?', [$m['id']])['clave_activa'] !== null);
    IO::ejecutar('UPDATE io_membresias SET checkout_ref = NULL, checkout_vence = NULL, creado = DATE_SUB(NOW(), INTERVAL 24 HOUR) WHERE id = ?', [$m['id']]);
    $postsAntes = count(array_filter($llamadas, fn($c) => $c[0] === 'POST' && $c[1] === '/v1/checkout/sessions'));
    errorM('timeout sin referencia después de 23 h exige conciliación', 'membresia_conciliacion', fn() => IOMembresias::checkout($cliente, 'premium'));
    verificarM('no reutiliza una clave remota vencida para crear otro cobro', count(array_filter($llamadas, fn($c) => $c[0] === 'POST' && $c[1] === '/v1/checkout/sessions')) === $postsAntes);
    IO::ejecutar('UPDATE io_membresias SET checkout_ref = "cs_test_fixture", checkout_vence = DATE_ADD(NOW(), INTERVAL 30 MINUTE), creado = NOW() WHERE id = ?', [$m['id']]);
    $estadoCheckout = 'open';
    $ahora = time();
    $completa = ['id' => 'cs_test_fixture', 'mode' => 'subscription', 'metadata' => $metadata, 'subscription' => 'sub_fixture', 'customer' => 'cus_fixture'];
    IOMembresias::procesarEvento(eventoM('checkout.session.completed', $completa, $ahora));
    verificarM('checkout completo vincula referencias sin activar contenido', IO::uno('SELECT suscripcion_ref FROM io_membresias WHERE id = ?', [$m['id']])['suscripcion_ref'] === 'sub_fixture'
        && (int) IO::uno('SELECT COUNT(*) n FROM io_accesos')['n'] === 0);
    errorM('otro cliente no puede abrir el portal de esta suscripción', 'membresia', fn() => IOMembresias::portal((int) $m['id'], 999));
    verificarM('el dueño puede abrir el portal de Stripe', IOMembresias::portal((int) $m['id'], 101) === 'https://billing.stripe.com/p/session/fixture');
    $factura = ['id' => 'in_primera', 'subscription' => 'sub_fixture', 'customer' => 'cus_fixture', 'billing_reason' => 'subscription_create',
        'status' => 'paid', 'amount_paid' => 50000, 'currency' => 'mxn', 'lines' => ['has_more' => false, 'data' => [[
        'price' => ['id' => 'price_premium'], 'amount' => 50000, 'quantity' => 1, 'proration' => false,
        'period' => ['start' => $ahora - 100, 'end' => $ahora + 3600]]]]];
    foreach (['amount_paid' => 1, 'currency' => 'usd', 'customer' => 'cus_otro'] as $campo => $valor) {
        errorM('rechaza factura con ' . $campo . ' distinto', 'factura_membresia', fn() => IOMembresias::procesarEvento(eventoM('invoice.paid', [$campo => $valor] + $factura, $ahora)));
    }
    verificarM('facturas inválidas no persisten ni conceden accesos', (int) IO::uno('SELECT COUNT(*) n FROM io_membresia_facturas')['n'] === 0);
    IOMembresias::procesarEvento(eventoM('invoice.paid', $factura, $ahora));
    verificarM('primer pago habilita ambos contenidos hasta el período pagado', (int) IO::uno('SELECT COUNT(*) n FROM io_accesos WHERE cliente_id = 101 AND estado IN ("activo","por_empezar") AND vence IS NOT NULL')['n'] === 2);
    IO::ejecutar('UPDATE io_accesos SET estado = "vencido" WHERE producto_id = 2');
    $recuperacion = $factura; $recuperacion['id'] = 'in_recuperacion'; $recuperacion['billing_reason'] = 'subscription_cycle';
    $recuperacion['lines']['data'][0]['period']['end'] = $ahora + 5400;
    IOMembresias::procesarEvento(eventoM('invoice.paid', $recuperacion, $ahora + 1));
    verificarM('renovar una visualización aún no iniciada vuelve a por empezar', IO::uno('SELECT estado FROM io_accesos WHERE producto_id = 2')['estado'] === 'por_empezar');
    $unico = IO::uno('SELECT * FROM io_accesos WHERE producto_id = 2');
    $pase = IOAccesos::iniciar((int) $unico['id'], $cliente, 'Prueba', '127.0.0.1');
    verificarM('la primera reproducción única no consume una reanudación', (int) IO::uno('SELECT reanudaciones FROM io_accesos WHERE id = ?', [$unico['id']])['reanudaciones'] === 0);
    verificarM('iniciar reproducción no amplía la vigencia del período pagado', IO::uno('SELECT vence FROM io_accesos WHERE id = ?', [$unico['id']])['vence'] === date('Y-m-d H:i:s', $ahora + 5400));
    IOMembresias::procesarEvento(eventoM('invoice.paid', $factura, $ahora + 1));
    verificarM('otro evento de la misma factura no duplica accesos ni períodos', (int) IO::uno('SELECT COUNT(*) n FROM io_accesos')['n'] === 2 && (int) IO::uno('SELECT COUNT(*) n FROM io_membresia_facturas')['n'] === 2);
    errorM('no permite contratar dos veces un plan activo', 'membresia_activa', fn() => IOMembresias::checkout($cliente, 'premium'));
    $renovada = $factura; $renovada['id'] = 'in_segunda'; $renovada['billing_reason'] = 'subscription_cycle';
    $renovada['lines']['data'][0]['period']['end'] = $ahora + 7200;
    IO::ejecutar('UPDATE io_accesos SET estado = "visto" WHERE producto_id = 2');
    IOMembresias::procesarEvento(eventoM('invoice.paid', $renovada, $ahora + 20));
    $hasta = date('Y-m-d H:i:s', $ahora + 7200);
    verificarM('renovación amplía período sin duplicar el contenido', IO::uno('SELECT pagado_hasta FROM io_membresias WHERE id = ?', [$m['id']])['pagado_hasta'] === $hasta
        && (int) IO::uno('SELECT COUNT(*) n FROM io_accesos')['n'] === 2);
    verificarM('renovar no reinicia una visualización única consumida', IO::uno('SELECT estado FROM io_accesos WHERE producto_id = 2')['estado'] === 'visto');
    $vieja = $factura; $vieja['id'] = 'in_vieja';
    IOMembresias::procesarEvento(eventoM('invoice.paid', $vieja, $ahora - 20));
    verificarM('factura vieja no acorta el período renovado', IO::uno('SELECT pagado_hasta FROM io_membresias WHERE id = ?', [$m['id']])['pagado_hasta'] === $hasta);
    IOMembresias::procesarEvento(eventoM('invoice.payment_failed', $factura, $ahora - 10));
    verificarM('rechazo antiguo no cambia un período ya renovado', IO::uno('SELECT estado FROM io_membresias WHERE id = ?', [$m['id']])['estado'] === 'activa');
    IOMembresias::procesarEvento(eventoM('invoice.payment_failed', $factura, $ahora + 30));
    verificarM('rechazo nuevo conserva únicamente el tiempo pagado', IO::uno('SELECT estado FROM io_membresias WHERE id = ?', [$m['id']])['estado'] === 'morosa'
        && IO::uno('SELECT vence FROM io_accesos WHERE producto_id = 1')['vence'] === $hasta);
    IOMembresias::cancelar((int) $m['id'], 101);
    verificarM('cancelación al final no corta el período ya pagado', (int) IO::uno('SELECT cancelar_al_final FROM io_membresias WHERE id = ?', [$m['id']])['cancelar_al_final'] === 1
        && IO::uno('SELECT vence FROM io_accesos WHERE producto_id = 1')['vence'] === $hasta);
    errorM('otro cliente no puede cancelar esta membresía', 'membresia', fn() => IOMembresias::cancelar((int) $m['id'], 999));
    IOMembresias::procesarEvento(eventoM('customer.subscription.updated', ['id' => 'sub_fixture', 'status' => 'active', 'cancel_at_period_end' => false], $ahora + 50));
    IOMembresias::procesarEvento(eventoM('customer.subscription.updated', ['id' => 'sub_fixture', 'status' => 'active', 'cancel_at_period_end' => true], $ahora + 40));
    verificarM('estado antiguo no revierte una actualización reciente', (int) IO::uno('SELECT cancelar_al_final FROM io_membresias WHERE id = ?', [$m['id']])['cancelar_al_final'] === 0);
    $acceso = IO::uno('SELECT * FROM io_accesos WHERE producto_id = 1');
    IO::ejecutar('UPDATE io_accesos SET vence = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?', [$acceso['id']]);
    errorM('membresía vencida no reproduce aunque el producto sea permanente', 'vencido', fn() => IOAccesos::iniciar((int) $acceso['id'], $cliente, 'Prueba', '127.0.0.1'));
    IOMembresias::procesarEvento(eventoM('customer.subscription.deleted', ['id' => 'sub_fixture', 'status' => 'canceled', 'ended_at' => $ahora + 50], $ahora + 50));
    verificarM('cancelación terminal prevalece al empatar segundo con una actualización', IO::uno('SELECT estado FROM io_membresias WHERE id = ?', [$m['id']])['estado'] === 'cancelada');
    verificarM('terminación efectiva libera la clave de contratación', IO::uno('SELECT clave_activa FROM io_membresias WHERE id = ?', [$m['id']])['clave_activa'] === null);
    $posterior = $renovada; $posterior['id'] = 'in_atrasada'; $posterior['lines']['data'][0]['period']['end'] = $ahora + 9999;
    IOMembresias::procesarEvento(eventoM('invoice.paid', $posterior, $ahora + 70));
    verificarM('factura atrasada no reactiva membresía cancelada', IO::uno('SELECT estado FROM io_membresias WHERE id = ?', [$m['id']])['estado'] === 'cancelada');
    $antes = (int) IO::uno('SELECT COUNT(*) n FROM io_membresias')['n'];
    IOMembresias::procesarEvento(eventoM('invoice.paid', ['id' => 'in_ajena', 'subscription' => 'sub_ajena'], $ahora));
    verificarM('suscripción de otro sistema no se asigna por correo', (int) IO::uno('SELECT COUNT(*) n FROM io_membresias')['n'] === $antes);
    verificarM('cada cliente solo consulta sus membresías', count(IOMembresias::delCliente(101)) === 1 && IOMembresias::delCliente(999) === []);

    // Verifica también el dispatcher compartido: firma, enrutamiento y marca global.
    $dispatchFactura = $factura; $dispatchFactura['id'] = 'in_dispatch';
    $dispatchEvento = eventoM('invoice.paid', $dispatchFactura, time());
    $payload = json_encode($dispatchEvento);
    $t = time();
    $firma = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $payload, $secretoWebhook);
    $accesosAntes = (int) IO::uno('SELECT COUNT(*) n FROM io_accesos')['n'];
    [$http, $respuesta] = io_procesar_webhook_stripe($payload, $firma);
    verificarM('webhook firmado enruta invoice.paid a membresías', $http === 200 && !empty($respuesta['ok'])
        && IO::uno('SELECT factura_ref FROM io_membresia_facturas WHERE factura_ref = "in_dispatch"') !== null);
    verificarM('dispatcher registra el evento sin reactivar ni duplicar accesos', IO::uno('SELECT evento_id FROM io_webhooks WHERE evento_id = ?', [$dispatchEvento['id']]) !== null
        && (int) IO::uno('SELECT COUNT(*) n FROM io_accesos')['n'] === $accesosAntes
        && IO::uno('SELECT estado FROM io_membresias WHERE id = ?', [$m['id']])['estado'] === 'cancelada');
    [$http, $respuesta] = io_procesar_webhook_stripe($payload, $firma);
    verificarM('repetir el evento devuelve duplicado en la idempotencia global', $http === 200 && !empty($respuesta['duplicado']));
    $invalidaFactura = $factura; $invalidaFactura['id'] = 'in_firma_invalida';
    $invalido = eventoM('invoice.paid', $invalidaFactura, time());
    $payloadInvalido = json_encode($invalido);
    $firmaInvalida = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $payloadInvalido, 'secreto_incorrecto');
    [$http] = io_procesar_webhook_stripe($payloadInvalido, $firmaInvalida);
    verificarM('firma inválida devuelve 400 sin marca global ni factura', $http === 400
        && IO::uno('SELECT evento_id FROM io_webhooks WHERE evento_id = ?', [$invalido['id']]) === null
        && IO::uno('SELECT factura_ref FROM io_membresia_facturas WHERE factura_ref = "in_firma_invalida"') === null);
} finally {
    IOStripe::usarTransporteParaPruebas(null);
    $pdo->exec("DROP DATABASE `$base`");
}
echo "Resultado: $ok correctas, $fallos fallidas\n";
exit($fallos ? 1 : 0);
