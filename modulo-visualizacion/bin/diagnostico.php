<?php
/** Diagnóstico de instalación, solo lectura. Nunca muestra credenciales ni confirma pagos. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/Tienda.php';

$fallos = 0;
function comprobar(string $nombre, bool $ok, string $accion = ''): void
{
    global $fallos;
    echo ($ok ? '[OK] ' : '[FALTA] ') . $nombre . (!$ok && $accion !== '' ? ': ' . $accion : '') . PHP_EOL;
    if (!$ok) { $fallos++; }
}
echo "Diagnóstico IO — solo lectura; las credenciales no se muestran.\n\n";
comprobar('PHP 7.4 o superior', PHP_VERSION_ID >= 70400);
foreach (['pdo_mysql', 'curl', 'mbstring'] as $extension) {
    comprobar('Extensión ' . $extension, extension_loaded($extension), 'habilitarla en PHP');
}
if (!is_file(__DIR__ . '/../config.php')) {
    comprobar('Configuración local', false, 'copiar config.example.php a config.php y completar los valores');
    exit(1);
}
try { $cfg = IO::config(); } catch (Throwable $e) {
    comprobar('Configuración legible', false, 'revisar la sintaxis y permisos de config.php'); exit(1);
}
$dominio = (string) ($cfg['dominio'] ?? '');
$host = strtolower((string) parse_url($dominio, PHP_URL_HOST));
$local = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
comprobar('Dominio válido', $host !== '' && ($local || parse_url($dominio, PHP_URL_SCHEME) === 'https'), 'usar la URL HTTPS real del sitio');
comprobar('Modo de desarrollo', $local || empty($cfg['dev']), 'dev debe ser false fuera de localhost');
comprobar('Adaptador de sesión definido', is_callable($cfg['cliente_actual'] ?? null), 'conectar cliente_actual al inicio de sesión existente');
echo "[MANUAL] Verificar que cliente_actual devuelve el ID, correo y nombre de una cuenta real.\n";

$tienda = IOTienda::cfg();
$modo = strpos($tienda['stripe_secreta'], 'sk_test_') === 0 ? 'prueba' :
    (strpos($tienda['stripe_secreta'], 'sk_live_') === 0 ? 'producción' : 'sin configurar');
$prefijo = $modo === 'prueba' ? 'pk_test_' : 'pk_live_';
comprobar('Llaves Stripe del mismo modo', $modo !== 'sin configurar' && strpos($tienda['stripe_publica'], $prefijo) === 0,
    'configurar la llave pública y secreta del mismo modo; empezar con prueba');
comprobar('Secreto de firma del webhook', strpos($tienda['stripe_webhook'], 'whsec_') === 0, 'copiar el secreto del endpoint, del mismo modo que las llaves');
echo '[INFO] Modo Stripe: ' . $modo . PHP_EOL;
echo "[MANUAL] Configurar los eventos de pagos, membresías y devoluciones indicados en la guía; probar tarjeta aprobada, rechazada y OXXO.\n";

try {
    $db = IO::db();
    comprobar('Conexión MySQL', true);
    $tablas = ['io_politicas', 'io_accesos', 'io_sesiones', 'io_admins', 'io_bitacora', 'io_alertas', 'io_login_intentos',
        'io_productos', 'io_pedidos', 'io_pedido_items', 'io_webhooks', 'io_membresias', 'io_membresia_accesos', 'io_membresia_facturas',
        'io_cupones', 'io_descuentos_pedidos', 'io_facturas', 'io_reembolsos', 'io_transmisiones'];
    $encontradas = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $faltantes = array_values(array_diff($tablas, $encontradas));
    comprobar('Migraciones 001–008 aplicadas', !$faltantes, implode(', ', $faltantes));
    if (!$faltantes) {
        $activos = (int) IO::uno('SELECT COUNT(*) n FROM io_productos WHERE activo = 1')['n'];
        comprobar('Catálogo importado', $activos > 0, 'ajustar las consultas de importación y ejecutar importar.php productos --simular');
        $sinPolitica = (int) IO::uno('SELECT COUNT(*) n FROM io_productos p LEFT JOIN io_politicas pol ON pol.producto_id = p.producto_id
            WHERE p.activo = 1 AND (pol.producto_id IS NULL OR pol.vimeo_id = "" OR (pol.tipo = "unica" AND pol.duracion_seg <= 0))')['n'];
        comprobar('Productos vendibles con video y política', $sinPolitica === 0, "$sinPolitica productos incompletos; revisar Catálogo y reglas");
        $sinPrecio = (int) IO::uno('SELECT COUNT(*) n FROM io_productos WHERE activo = 1 AND precio_mxn <= 0')['n'];
        comprobar('Precios positivos', $sinPrecio === 0, "$sinPrecio productos sin precio de venta positivo");
        $admin = (int) IO::uno('SELECT COUNT(*) n FROM io_admins WHERE activo = 1 AND rol = "admin"')['n'];
        comprobar('Administrador activo', $admin > 0, 'ejecutar bin/crear-admin.php');
        $incompletos = (int) IO::uno('SELECT COUNT(*) n FROM io_productos WHERE activo = 1 AND
            (COALESCE(resumen, "") = "" OR COALESCE(imagen, "") = "")')['n'];
        comprobar('Resumen e imagen de cada producto', $incompletos === 0, "$incompletos productos sin resumen o imagen");
    }
} catch (Throwable $e) {
    comprobar('Base de datos y estructura', false, 'revisar db y aplicar las migraciones en orden; no se muestran detalles de conexión');
}

foreach (['membresias' => 'habilitadas', 'facturacion' => 'habilitada', 'pedidos' => 'reembolsos_habilitados'] as $modulo => $bandera) {
    echo '[INFO] ' . $modulo . ': ' . (!empty($cfg[$modulo][$bandera]) ? 'habilitado; completar aceptación específica' : 'desactivado por configuración') . PHP_EOL;
}
if (!empty($cfg['membresias']['habilitadas'])) {
    require_once __DIR__ . '/../lib/Membresias.php';
    comprobar('Planes recurrentes válidos', count(IOMembresias::planes()) > 0, 'configurar Price IDs, importes, periodicidad y productos');
}
if (!empty($cfg['facturacion']['habilitada'])) {
    $f = $cfg['facturacion'];
    require_once __DIR__ . '/../lib/Facturacion.php';
    comprobar('Cuenta de facturación del modo configurado', IOFacturacion::habilitada(), 'completar modo prueba/produccion y su llave Facturapi');
    $clasificacion = false;
    try {
        $clasificacion = true;
        foreach (IO::todos('SELECT producto_id FROM io_productos WHERE activo=1') as $producto) {
            $sat = ($f['productos'][(int) $producto['producto_id']] ?? []) + ['producto_sat' => $f['producto_sat'] ?? '', 'unidad_sat' => $f['unidad_sat'] ?? ''];
            if (!preg_match('/^\d{8}$/D', (string) $sat['producto_sat']) || !preg_match('/^[A-Z0-9]{2,3}$/D', (string) $sat['unidad_sat'])) { $clasificacion = false; }
        }
    } catch (Throwable $e) { $clasificacion = false; }
    comprobar('Clasificación fiscal del catálogo activo', $clasificacion, 'completar claves SAT aprobadas por el contador, predeterminadas o por producto');
}

if (in_array('--stripe-prueba', $argv, true)) {
    if ($modo !== 'prueba' || !extension_loaded('curl')) {
        comprobar('Consulta de Stripe de prueba', false, 'requiere sk_test_ y cURL; no consulta cuentas en vivo');
    } else {
        $ch = curl_init('https://api.stripe.com/v1/balance');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tienda['stripe_secreta']]]);
        $respuesta = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $datos = json_decode((string) $respuesta, true);
        comprobar('Conexión a Stripe en modo de prueba (sin crear cargos)', $http === 200 && isset($datos['livemode']) && $datos['livemode'] === false,
            'revisar conectividad y llaves; HTTP ' . $http);
    }
}
echo "[MANUAL] Comprobar HTTPS, bloqueo de /io/config.php y /io/lib/, dominio Vimeo, respaldo y compra completa.\n";
echo "\nResultado: $fallos pendientes automáticos. Los puntos MANUAL requieren revisión del instalador.\n";
exit($fallos ? 1 : 0);
