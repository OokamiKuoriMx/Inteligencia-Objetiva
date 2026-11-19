<?php
/**
 * Vimeo para el editor de contenido (JSON). Requiere sesión, permiso "politicas" y CSRF.
 *   accion=consultar  enlace=…              → ID, hash, título, duración, miniatura y estado de privacidad
 *   accion=aplicar    vimeo_id=… motivo=…   → aplica la privacidad recomendada y la registra en io_bitacora
 */
declare(strict_types=1);
require __DIR__ . '/_inc.php';
require_once __DIR__ . '/../lib/Vimeo.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function vimeo_responder(array $respuesta, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}
try {
    $a = admin();
    if (!$a) { throw new IOError('sin_sesion', 'Inicia sesión de nuevo.', 401); }
    if (!in_array('politicas', IO_PERMISOS[$a['rol']] ?? [], true)) { throw new IOError('sin_permiso', 'Sin permiso para editar contenidos.', 403); }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { throw new IOError('metodo', 'Método no permitido.', 405); }
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || $token === '' || empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $token)) {
        throw new IOError('csrf', 'La sesión expiró. Recarga la página.', 403);
    }
    $accion = is_string($_POST['accion'] ?? null) ? $_POST['accion'] : '';
    $cuenta = static function (): array {
        $c = IOVimeoCliente::cuenta();
        return ['configurado' => $c['configurado'], 'vinculada' => $c['vinculada'], 'puede_editar' => $c['puede_editar'], 'dominio' => $c['dominio']];
    };
    if ($accion === 'consultar') {
        $enlace = is_string($_POST['enlace'] ?? null) ? $_POST['enlace'] : '';
        $v = IOVimeoCliente::analizarEnlace($enlace);
        if (!$v) { throw new IOError('enlace', 'No se reconoció un video de Vimeo. Pega el enlace tal como aparece en Vimeo, por ejemplo https://vimeo.com/123456789.'); }
        vimeo_responder(['ok' => true, 'video' => IOVimeoCliente::consultar($v['id'], $v['hash']), 'cuenta' => $cuenta()]);
    }
    if ($accion === 'aplicar') {
        $id = is_string($_POST['vimeo_id'] ?? null) ? $_POST['vimeo_id'] : '';
        $motivo = is_string($_POST['motivo'] ?? null) ? $_POST['motivo'] : '';
        $producto = filter_var($_POST['producto_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
        $estado = IOVimeoCliente::aplicarRecomendada($a, $id, $producto === false ? null : (int) $producto, $motivo);
        vimeo_responder(['ok' => true, 'video' => $estado, 'cuenta' => $cuenta()]);
    }
    throw new IOError('accion', 'Acción no válida.');
} catch (IOError $e) {
    vimeo_responder(['ok' => false, 'codigo' => $e->codigo, 'mensaje' => $e->getMessage()], $e->getCode() ?: 400);
} catch (Throwable $e) {
    error_log('[IO vimeo] Error interno al consultar Vimeo.');
    vimeo_responder(['ok' => false, 'codigo' => 'interno', 'mensaje' => 'No se pudo completar la consulta a Vimeo.'], 500);
}
