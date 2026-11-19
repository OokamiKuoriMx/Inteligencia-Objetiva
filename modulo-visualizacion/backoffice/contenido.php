<?php
/** Endpoint de edición: autenticación y errores JSON, sin redirecciones HTML ni credenciales. */
declare(strict_types=1);
require __DIR__ . '/_inc.php';
require_once __DIR__ . '/../lib/Contenidos.php';
header('Content-Type: application/json; charset=utf-8');
function contenido_responder(array $respuesta, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}
try {
    $a = admin();
    if (!$a) { throw new IOError('sin_sesion', 'Inicia sesión de nuevo para editar contenidos.', 401); }
    if (!in_array('politicas', IO_PERMISOS[$a['rol']] ?? [], true)) { throw new IOError('sin_permiso', 'Sin permiso para editar contenidos.', 403); }
    $metodo = $_SERVER['REQUEST_METHOD'] ?? '';
    if ($metodo === 'GET') {
        $id = filter_var($_GET['producto'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
        if ($id === false || $id === null) { throw new IOError('producto', 'Contenido no encontrado.', 404); }
        contenido_responder(['ok' => true, 'contenido' => IOContenidos::obtener((int) $id)]);
    }
    if ($metodo !== 'POST') { throw new IOError('metodo', 'Método no permitido.', 405); }
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || empty($_SESSION['csrf']) || $token === '' || !hash_equals((string) $_SESSION['csrf'], $token)) { throw new IOError('csrf', 'La sesión expiró. Recarga la página antes de guardar.', 403); }
    csrf_ok();
    $accion = $_POST['accion'] ?? 'guardar';
    if ($accion === 'consultar_video') {
        if (!is_string($_POST['vimeo_id'] ?? null)) { throw new IOError('video', 'Escribe el ID numérico de Vimeo.'); }
        contenido_responder(['ok' => true, 'video' => IOContenidos::consultarVideo($a, $_POST['vimeo_id'])]);
    }
    if ($accion !== 'guardar') { throw new IOError('accion', 'Acción no válida.'); }
    if (!is_string($_POST['motivo'] ?? null)) { throw new IOError('motivo', 'Escribe el motivo del cambio.'); }
    $id = IOContenidos::guardar($a, $_POST, $_POST['motivo']);
    flash('Contenido guardado. La ficha comercial y sus reglas se actualizaron juntas.');
    contenido_responder(['ok' => true, 'producto_id' => $id]);
} catch (IOError $e) {
    contenido_responder(['ok' => false, 'codigo' => $e->codigo, 'mensaje' => $e->getMessage()], $e->getCode() ?: 400);
} catch (PDOException $e) {
    if (in_array((string) $e->getCode(), ['23000', '40001'], true)) { contenido_responder(['ok' => false, 'codigo' => 'conflicto', 'mensaje' => 'Otro cambio coincide con este contenido. Recarga su versión y vuelve a intentarlo.'], 409); }
    error_log('[IO contenido] No se pudo guardar el contenido.');
    contenido_responder(['ok' => false, 'codigo' => 'interno', 'mensaje' => 'No se pudo guardar el contenido. Tus campos siguen disponibles para intentarlo de nuevo.'], 500);
} catch (Throwable $e) {
    error_log('[IO contenido] Error interno al procesar la solicitud.');
    contenido_responder(['ok' => false, 'codigo' => 'interno', 'mensaje' => 'No se pudo completar la solicitud.'], 500);
}
