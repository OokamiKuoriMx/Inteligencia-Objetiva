<?php
declare(strict_types=1);
ini_set('display_errors', '0');
require_once __DIR__ . '/../lib/Membresias.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { throw new IOError('metodo', 'Método no permitido.', 405); }
    if (!IOTienda::csrfOk((string) ($_POST['csrf'] ?? ''))) { throw new IOError('csrf', 'La sesión expiró. Recarga la página.', 403); }
    $cliente = IO::clienteActual();
    if (!$cliente) { throw new IOError('sin_sesion', 'Inicia sesión para administrar tu membresía.', 401); }
    switch ($_POST['accion'] ?? '') {
        case 'checkout': $url = IOMembresias::checkout($cliente, (string) ($_POST['plan'] ?? '')); break;
        case 'portal': $url = IOMembresias::portal((int) ($_POST['id'] ?? 0), (int) $cliente['id']); break;
        case 'cancelar':
            IOMembresias::cancelar((int) ($_POST['id'] ?? 0), (int) $cliente['id']);
            $url = '/io/tienda/membresias.php?resultado=cancelacion'; break;
        default: throw new IOError('accion', 'Acción no válida.');
    }
    header('Location: ' . $url, true, 303); exit;
} catch (IOError $e) {
    http_response_code($e->getCode() ?: 400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p><a href="/io/tienda/membresias.php">Volver a membresías</a>';
} catch (Throwable $e) {
    error_log('[IO membresías] ' . $e->getMessage());
    http_response_code(500); echo 'No pudimos completar la solicitud. Intenta de nuevo.';
}
