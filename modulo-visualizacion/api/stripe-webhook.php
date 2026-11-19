<?php
/**
 * Webhook de Stripe: /io/api/stripe-webhook.php
 * En el panel de Stripe → Developers → Webhooks, apunta aquí y suscribe:
 *   payment_intent.succeeded, payment_intent.payment_failed, payment_intent.canceled, payment_intent.requires_action
 *   checkout.session.completed, invoice.paid, invoice.payment_failed,
 *   customer.subscription.updated, customer.subscription.deleted,
 *   charge.refunded, refund.updated, refund.failed
 * Versión de API del endpoint: 2024-06-20.
 * Copia el "Signing secret" (whsec_...) a config.php → tienda.stripe_webhook.
 * Es la ÚNICA vía que confirma pagos reales: el navegador nunca marca un pedido como pagado.
 */
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
require_once __DIR__ . '/../lib/Tienda.php';

[$http, $respuesta] = io_procesar_webhook_stripe((string) file_get_contents('php://input'), (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''));
http_response_code($http);
header('Content-Type: application/json');
echo json_encode($respuesta);
