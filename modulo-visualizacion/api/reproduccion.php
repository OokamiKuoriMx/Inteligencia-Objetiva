<?php
/**
 * API de reproducción (JSON). Ruta: /io/api/reproduccion.php
 * Todas las acciones exigen la sesión del cliente en el sitio y que el token/acceso le pertenezca.
 *   POST accion=iniciar     acceso_id
 *   POST accion=latido      token, posicion, reproduciendo
 *   POST accion=finalizado  token
 *   POST accion=cerrar      token            (sendBeacon al cerrar la pestaña)
 *   GET  accion=biblioteca                   (accesos del cliente)
 */
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
require_once __DIR__ . '/../lib/IO.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function responder(array $data, int $http = 200): void
{
    http_response_code($http);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Protección CSRF para una API JSON: la petición debe venir del propio sitio.
 * Se acepta Sec-Fetch-Site: same-origin, o un Origin igual al dominio configurado.
 */
function mismo_origen(): bool
{
    $sitio = rtrim((string) (IO::config()['dominio'] ?? ''), '/');
    $sfs = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    $origen = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($sfs !== '' && $sfs !== 'same-origin') { return false; }
    if ($origen !== '') {
        if ($origen === $sitio) { return true; }
        return IO::dev() && (bool) preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $origen);
    }
    return $sfs === 'same-origin';
}

try {
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $entrada = [];
    if ($metodo === 'POST') {
        $raw = (string) file_get_contents('php://input');
        $json = $raw !== '' ? json_decode($raw, true) : null;
        $entrada = is_array($json) ? $json : $_POST;
    }
    $accion = (string) ($entrada['accion'] ?? ($_GET['accion'] ?? ''));
    $mutaciones = ['iniciar', 'latido', 'finalizado', 'cerrar'];

    if (in_array($accion, $mutaciones, true) && $metodo !== 'POST') {
        responder(['ok' => false, 'codigo' => 'metodo', 'mensaje' => 'Método no permitido.'], 405);
    }
    if (!mismo_origen()) {
        responder(['ok' => false, 'codigo' => 'origen', 'mensaje' => 'Origen no permitido.'], 403);
    }

    $cliente = IO::clienteActual();
    if (!$cliente) {
        responder(['ok' => false, 'codigo' => 'sin_sesion', 'mensaje' => 'Inicia sesión para ver tu contenido.'], 401);
    }
    $token = (string) ($entrada['token'] ?? '');
    if (in_array($accion, ['latido', 'finalizado', 'cerrar'], true) && !preg_match('/^[a-f0-9]{64}$/', $token)) {
        responder(['ok' => false, 'codigo' => 'token', 'mensaje' => 'Sesión de reproducción no válida.'], 400);
    }

    switch ($accion) {
        case 'iniciar':
            $pase = IOAccesos::iniciar(
                (int) ($entrada['acceso_id'] ?? 0),
                $cliente,
                (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                (string) ($_SERVER['REMOTE_ADDR'] ?? '')
            );
            responder(['ok' => true] + $pase);

        case 'latido':
            responder(IOAccesos::latido(
                $token,
                $cliente,
                max(0, (int) ($entrada['posicion'] ?? 0)),
                filter_var($entrada['reproduciendo'] ?? false, FILTER_VALIDATE_BOOLEAN)
            ));

        case 'finalizado':
            responder(IOAccesos::finalizado($token, $cliente));

        case 'cerrar':
            IOAccesos::cerrar($token, $cliente);
            responder(['ok' => true]);

        case 'biblioteca':
            $lista = array_map(function ($a) {
                return [
                    'acceso_id' => (int) $a['id'], 'producto_id' => (int) $a['producto_id'], 'titulo' => $a['titulo'],
                    'tipo' => $a['tipo'], 'estado' => $a['estado'], 'vence' => $a['vence'], 'limite_inicio' => $a['limite_inicio'],
                    'progreso_pct' => $a['duracion_seg'] > 0 ? min(100, (int) round($a['max_seg'] * 100 / $a['duracion_seg'])) : 0,
                    'reanudaciones' => (int) $a['reanudaciones'],
                ];
            }, IOAccesos::delCliente((int) $cliente['id']));
            responder(['ok' => true, 'accesos' => $lista]);

        default:
            responder(['ok' => false, 'codigo' => 'accion', 'mensaje' => 'Acción no válida.'], 400);
    }
} catch (IOError $e) {
    responder(['ok' => false, 'codigo' => $e->codigo, 'mensaje' => $e->getMessage()], $e->getCode() ?: 400);
} catch (Throwable $e) {
    error_log('[IO reproduccion] ' . $e->getMessage());
    responder(['ok' => false, 'codigo' => 'error', 'mensaje' => 'Ocurrió un error. Intenta de nuevo.'], 500);
}
