<?php
declare(strict_types=1);
ini_set('display_errors', '0');
require_once __DIR__ . '/../lib/Facturacion.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$cliente = IO::clienteActual();
try {
    if (!$cliente) { throw new IOError('sin_sesion', 'Inicia sesión para consultar tu factura.', 401); }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['accion'] ?? '') === 'descargar') {
        $pedido = (int) ($_GET['pedido'] ?? 0); $formato = (string) ($_GET['formato'] ?? '');
        $archivo = IOFacturacion::descargar($cliente, $pedido, $formato);
        header('Content-Type: ' . ($formato === 'pdf' ? 'application/pdf' : 'application/xml'));
        header('Content-Disposition: attachment; filename="factura-' . $pedido . '.' . $formato . '"');
        echo $archivo; exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { throw new IOError('metodo', 'Método no permitido.', 405); }
    $json = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
    $entrada = $json ? json_decode((string) file_get_contents('php://input'), true) : $_POST;
    if (!is_array($entrada) || !IOTienda::csrfOk((string) ($entrada['csrf'] ?? ''))) { throw new IOError('csrf', 'La sesión expiró. Recarga la página.', 403); }
    $pedido = (int) ($entrada['pedido'] ?? 0); $accion = (string) ($entrada['accion'] ?? '');
    if ($accion === 'solicitar') { $factura = IOFacturacion::solicitar($cliente, $pedido, $entrada); }
    elseif ($accion === 'consultar') { $factura = IOFacturacion::consultar($cliente, $pedido); }
    else { throw new IOError('accion', 'Acción no válida.'); }
    if (!$json) { header('Location: /io/tienda/facturacion.php?pedido=' . $pedido); exit; }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'factura' => $factura], JSON_UNESCAPED_UNICODE);
} catch (IOError $e) {
    if (empty($json) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($pedido)) {
        header('Location: /io/tienda/facturacion.php?pedido=' . $pedido . '&error=' . rawurlencode($e->getMessage())); exit;
    }
    http_response_code($e->getCode() ?: 400); header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'codigo' => $e->codigo, 'mensaje' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[IO facturacion] Error interno al procesar comprobante');
    http_response_code(500); header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'mensaje' => 'No se pudo procesar la factura. Solicita ayuda al equipo.']);
}
