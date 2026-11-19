<?php
/** Sube una miniatura de contenido. Responde JSON; el cambio queda en la bitácora al guardar el contenido. */
declare(strict_types=1);
require __DIR__ . '/_inc.php';
require_once __DIR__ . '/../lib/Miniaturas.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
function miniatura_responder(array $r, int $codigo = 200): void
{
    http_response_code($codigo);
    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}
try {
    $a = admin();
    if (!$a) { throw new IOError('sin_sesion', 'Inicia sesión de nuevo para subir imágenes.', 401); }
    if (!puede('politicas')) { throw new IOError('sin_permiso', 'Sin permiso para editar contenidos.', 403); }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { throw new IOError('metodo', 'Método no permitido.', 405); }
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $token)) {
        // Si el archivo excede post_max_size, PHP vacía $_POST: se informa como tamaño.
        if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) { throw new IOError('tamano', 'La imagen pesa demasiado. Usa una de hasta 4 MB.', 413); }
        throw new IOError('csrf', 'La sesión expiró. Recarga la página.', 403);
    }
    if (!isset($_FILES['archivo']) || !is_array($_FILES['archivo']) || is_array($_FILES['archivo']['name'] ?? null)) { throw new IOError('archivo', 'Elige una imagen.'); }
    IOMiniaturas::registrarIntento($_SESSION);
    $r = IOMiniaturas::guardar($_FILES['archivo']);
    error_log('[IO miniatura] Subida por admin #' . (int) $a['id'] . ': ' . basename($r['url']));
    miniatura_responder(['ok' => true] + $r);
} catch (IOError $e) {
    miniatura_responder(['ok' => false, 'codigo' => $e->codigo, 'mensaje' => $e->getMessage()], $e->getCode() ?: 400);
} catch (Throwable $e) {
    error_log('[IO miniatura] Error al guardar la imagen.');
    miniatura_responder(['ok' => false, 'codigo' => 'interno', 'mensaje' => 'No se pudo subir la imagen.'], 500);
}
