<?php
/** Router de demo. Nunca se incluye en el paquete de producción. Solo loopback y MySQL 3307/io_demo. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) ||
    !in_array($_SERVER['SERVER_ADDR'] ?? '127.0.0.1', ['127.0.0.1', '::1'], true)) { http_response_code(403); exit; }
$repo = dirname(__DIR__, 2);
$ruta = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (strpos($ruta, "\0") !== false || strpos($ruta, '\\') !== false || preg_match('#(?:^|/)\.\.?(?:/|$)#', $ruta)) { http_response_code(404); exit; }
$rutas = ['/nosotros' => 'nosotros.php', '/catalogo' => 'catalogo.php', '/conferencias-en-linea' => 'catalogo.php',
    '/cursos-en-linea' => 'catalogo.php', '/documentales' => 'catalogo.php', '/audiolibro' => 'catalogo.php',
    '/carrito' => 'carrito.php', '/gracias' => 'gracias.php', '/membresias' => 'membresias.php', '/en-vivo' => 'en-vivo.php', '/calendario' => 'en-vivo.php'];
if (isset($rutas[$ruta])) {
    if (in_array($ruta, ['/cursos-en-linea', '/documentales', '/audiolibro'], true)) {
        $_GET['categoria'] = ['/cursos-en-linea' => 'cursos', '/documentales' => 'documentales', '/audiolibro' => 'audiolibro'][$ruta];
    }
    $archivo = dirname(__DIR__) . '/tienda/' . $rutas[$ruta];
} elseif (preg_match('#^/producto/([a-z0-9-]+)/?$#D', $ruta, $m)) {
    $_GET['p'] = $m[1]; $archivo = dirname(__DIR__) . '/tienda/producto.php';
} elseif (preg_match('#^/(tema|portada)/#', $ruta) || preg_match('#^/io/(public|tienda|api|backoffice)/#', $ruta)) {
    $relativo = strpos($ruta, '/io/') === 0 ? '/modulo-visualizacion/' . substr($ruta, 4) : $ruta;
    $archivo = realpath($repo . $relativo);
    if ($archivo && is_dir($archivo)) { $archivo = realpath($archivo . '/index.php'); }
    $prefijo = realpath($repo) . DIRECTORY_SEPARATOR;
    if (!$archivo || strpos($archivo, $prefijo) !== 0 || !is_file($archivo) ||
        !in_array(strtolower(pathinfo($archivo, PATHINFO_EXTENSION)), ['php', 'js', 'css', 'webp', 'png', 'html'], true)) {
        http_response_code(404); exit;
    }
    if (strtolower(pathinfo($archivo, PATHINFO_EXTENSION)) !== 'php') {
        $tipos = ['js' => 'application/javascript', 'css' => 'text/css', 'webp' => 'image/webp', 'png' => 'image/png', 'html' => 'text/html; charset=utf-8'];
        header('Content-Type: ' . $tipos[strtolower(pathinfo($archivo, PATHINFO_EXTENSION))]); readfile($archivo); return true;
    }
} elseif ($ruta !== '/' && $ruta !== '/demo/sesion') { http_response_code(404); exit; }

$sesiones = getenv('IO_LOCAL_TEMP');
if (!$sesiones || !is_dir($sesiones)) { http_response_code(503); exit('Inicia la demo con bin/iniciar-demo.ps1.'); }
ini_set('session.save_path', $sesiones);
require_once dirname(__DIR__) . '/lib/Tienda.php';
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3307;dbname=io_demo;charset=utf8mb4', 'root', '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    date_default_timezone_set('America/Mexico_City');
    $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    IO::usarConexion($pdo, ['zona_horaria' => 'America/Mexico_City', 'dominio' => 'http://127.0.0.1:' . (int) getenv('IO_DEMO_PORT'),
        'dev' => true, 'cliente_actual' => function () {
            if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
            return !empty($_SESSION['io_demo_alumno']) ? ['id' => 1000, 'email' => 'laura@ejemplo.test', 'nombre' => 'Laura Méndez'] : null;
        }]);
} catch (Throwable $e) { http_response_code(503); exit('Prepara MySQL de prueba y ejecuta bin/iniciar-demo.ps1.'); }
if (isset($archivo)) { require $archivo; return true; }
session_start();
if ($ruta === '/demo/sesion') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !IOTienda::csrfOk((string) ($_POST['csrf'] ?? ''))) { http_response_code(403); exit; }
    $_SESSION['io_demo_alumno'] = ($_POST['entrar'] ?? '') === '1';
    session_regenerate_id(true);
    header('Location: /carrito'); exit;
}
header('Cache-Control: no-store');
?>
<!doctype html><html lang="es-MX"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Demo local IO</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css"><link rel="stylesheet" href="/tema/io-theme.css"></head>
<body><main class="container py-5"><h1>Inteligencia Objetiva · Demo local</h1><p>Datos ficticios. No se hacen cargos ni se usan cuentas del sitio real.</p>
<p><a href="/portada/index.html">Portada</a> · <a href="/catalogo">Catálogo</a> · <a href="/carrito">Carrito</a> · <a href="/io/public/mi-biblioteca.php">Biblioteca</a> · <a href="/io/backoffice/">Backoffice</a></p>
<form method="post" action="/demo/sesion"><input type="hidden" name="csrf" value="<?= htmlspecialchars(IOTienda::csrf(), ENT_QUOTES, 'UTF-8') ?>">
<button class="btn btn-primary" name="entrar" value="1">Entrar como alumna de demostración</button> <button class="btn btn-secondary" name="entrar" value="0">Cerrar sesión de demostración</button></form>
<p class="mt-4">Backoffice: <code>demo-admin@ejemplo.test</code> · Contraseña: <code>demo-local-12345</code>. Solo para este entorno local.</p>
<p>Para revisar pagos: agrega un producto, entra como alumna demo y elige tarjeta, rechazo u OXXO. La simulación no acredita pagos reales.</p>
</main></body></html>
