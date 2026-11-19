<?php
/**
 * API de la tienda: /io/api/tienda.php  (todas las acciones son POST con token CSRF de la sesión)
 *   agregar     producto_id [volver]   → agrega al carrito
 *   quitar      producto_id [volver]   → quita del carrito
 *   pagar                              → crea el pedido; con Stripe devuelve client_secret
 *   pagar_demo  folio, metodo          → SOLO en localhost: simula el cobro (card) o la ficha OXXO (oxxo / oxxo_pagado)
 *   estado      folio                  → estado del pedido (para OXXO / SPEI pendientes)
 */
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
require_once __DIR__ . '/../lib/Tienda.php';
require_once __DIR__ . '/../lib/Descuentos.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$esJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$entrada = $_POST;
if ($esJson) { $j = json_decode((string) file_get_contents('php://input'), true); $entrada = is_array($j) ? $j : []; }
$accion = (string) ($entrada['accion'] ?? '');

function salir(array $data, int $http = 200): void
{
    // Sin variables globales: este archivo puede incluirse desde otro script (router, framework).
    $esJson = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
    $volver = $esJson ? '' : (string) ($_POST['volver'] ?? '');
    if ($volver !== '' && preg_match('#^/[A-Za-z0-9/_\-.?=&%]*$#', $volver) && strpos($volver, '//') === false) {
        if (empty($data['ok'])) { $volver .= (strpos($volver, '?') === false ? '?' : '&') . 'error=' . rawurlencode($data['mensaje'] ?? 'Error'); }
        header('Location: ' . $volver); exit;
    }
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { salir(['ok' => false, 'mensaje' => 'Método no permitido.'], 405); }
    if (!IOTienda::csrfOk((string) ($entrada['csrf'] ?? ''))) { salir(['ok' => false, 'mensaje' => 'La sesión expiró. Recarga la página.'], 403); }

    switch ($accion) {
        case 'cupon':
            $cliente = IO::clienteActual();
            if (!$cliente) { salir(['ok' => false, 'mensaje' => 'Inicia sesión para aplicar un cupón.'], 401); }
            $codigo = IODescuentos::codigo((string) ($entrada['codigo'] ?? ''));
            if ($codigo === '') { throw new IOError('cupon', 'Escribe el código del cupón.'); }
            $r = IODescuentos::calcular(IOTienda::resumen(IOTienda::carritoIds())['items'], $codigo, (int) $cliente['id']);
            $_SESSION['io_cupon'] = $codigo;
            salir(['ok' => true, 'total' => $r['total'], 'descuento' => $r['descuento_centavos'] / 100]);

        case 'quitar_cupon':
            unset($_SESSION['io_cupon']);
            salir(['ok' => true]);

        case 'agregar':
            IOTienda::agregar((int) ($entrada['producto_id'] ?? 0));
            salir(['ok' => true, 'carrito' => count(IOTienda::carritoIds())]);

        case 'quitar':
            IOTienda::quitar((int) ($entrada['producto_id'] ?? 0));
            salir(['ok' => true, 'carrito' => count(IOTienda::carritoIds())]);

        case 'pagar':
            $cliente = IO::clienteActual();
            if (!$cliente) { salir(['ok' => false, 'codigo' => 'sin_sesion', 'mensaje' => 'Inicia sesión para pagar.'], 401); }
            $pasarela = IOTienda::pasarela();
            $pedido = IOTienda::crearPedido($cliente, IOTienda::carritoIds(), $pasarela, (string) ($_SESSION['io_cupon'] ?? ''));
            $_SESSION['io_checkout_folio'] = $pedido['folio'];
            if ($pasarela === 'stripe') {
                $intento = IOStripe::crearIntento($pedido);
                salir(['ok' => true, 'pasarela' => 'stripe', 'folio' => $pedido['folio'], 'total' => (float) $pedido['total'],
                       'client_secret' => $intento['client_secret'], 'publica' => IOTienda::cfg()['stripe_publica']]);
            }
            salir(['ok' => true, 'pasarela' => 'demo', 'folio' => $pedido['folio'], 'total' => (float) $pedido['total']]);

        case 'pagar_demo':
            if (IOTienda::pasarela() !== 'demo') { salir(['ok' => false, 'mensaje' => 'No disponible.'], 404); }
            $cliente = IO::clienteActual();
            $p = $cliente ? IOTienda::pedidoPorFolio((string) ($entrada['folio'] ?? ''), (int) $cliente['id']) : null;
            if (!$p) { salir(['ok' => false, 'mensaje' => 'Pedido no encontrado.'], 404); }
            $metodo = (string) ($entrada['metodo'] ?? 'card');
            if ($metodo === 'card' || $metodo === 'oxxo_pagado') {
                IOTienda::confirmarPago((int) $p['id'], 'demo_' . bin2hex(random_bytes(6)), $metodo === 'card' ? 'card' : 'oxxo');
                IOTienda::vaciar();
            } elseif ($metodo === 'oxxo') {
                IO::ejecutar('UPDATE io_pedidos SET metodo = "oxxo" WHERE id = ?', [$p['id']]);
                IOTienda::vaciar();
            } elseif ($metodo === 'rechazada') {
                IOTienda::marcarFallido((int) $p['id']);
            }
            salir(['ok' => true, 'folio' => $p['folio']]);

        case 'estado':
            $cliente = IO::clienteActual();
            $p = $cliente ? IOTienda::pedidoPorFolio((string) ($entrada['folio'] ?? ''), (int) $cliente['id']) : null;
            if (!$p) { salir(['ok' => false, 'mensaje' => 'Pedido no encontrado.'], 404); }
            salir(['ok' => true, 'estado' => $p['estado']]);

        default:
            salir(['ok' => false, 'mensaje' => 'Acción no válida.'], 400);
    }
} catch (IOError $e) {
    salir(['ok' => false, 'codigo' => $e->codigo, 'mensaje' => $e->getMessage()], $e->getCode() ?: 400);
} catch (Throwable $e) {
    error_log('[IO tienda] ' . $e->getMessage());
    salir(['ok' => false, 'mensaje' => 'Ocurrió un error. Intenta de nuevo.'], 500);
}
