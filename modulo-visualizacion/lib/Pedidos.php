<?php
/** Pedidos y reembolsos completos. Las operaciones externas tienen solicitud durable e idempotente. */
declare(strict_types=1);
require_once __DIR__ . '/Tienda.php';

final class IOPedidos
{
    private static function administrador(array $actor): array
    {
        return IOEquipo::autorizar($actor, 'reembolsos', 'Sin permiso para gestionar reembolsos.');
    }

    private static function bitacora(array $actor, string $accion, int $pedidoId, string $motivo, array $detalle = []): void
    {
        IO::ejecutar('INSERT INTO io_bitacora (admin_id,admin_email,accion,motivo,detalle) VALUES (?,?,?,?,?)',
            [$actor['id'] ?? null, $actor['email'] ?? 'stripe@webhook', $accion, $motivo,
                json_encode(['pedido_id' => $pedidoId] + $detalle, JSON_UNESCAPED_UNICODE)]);
    }

    public static function reembolsar(array $actor, int $pedidoId, string $motivo): array
    {
        $actor = self::administrador($actor);
        $motivo = trim($motivo);
        if ($motivo === '' || mb_strlen($motivo) > 120) { throw new IOError('motivo', 'Indica un motivo de hasta 120 caracteres.'); }
        if (empty(IO::config()['pedidos']['reembolsos_habilitados'])) { throw new IOError('configuracion', 'Los reembolsos no están habilitados en esta instalación.', 503); }
        $r = IO::transaccion(function () use ($actor, $pedidoId, $motivo) {
            $p = IO::uno('SELECT * FROM io_pedidos WHERE id = ? FOR UPDATE', [$pedidoId]);
            if (!$p || $p['estado'] !== 'pagado') { throw new IOError('pedido', 'Solo se reembolsan pedidos pagados.'); }
            // Factura y reembolso bloquean primero el mismo pedido para no cruzar operaciones.
            $factura = IO::uno('SELECT estado FROM io_facturas WHERE pedido_id = ?', [$pedidoId]);
            if ($factura && $factura['estado'] !== 'cancelada') { throw new IOError('factura', 'Primero resuelve la cancelación del CFDI y espera su confirmación.'); }
            $r = IO::uno('SELECT * FROM io_reembolsos WHERE pedido_id = ? AND estado <> "fallido" ORDER BY id DESC LIMIT 1 FOR UPDATE', [$pedidoId]);
            if ($r) { return $r; }
            IO::ejecutar('INSERT INTO io_reembolsos (pedido_id,clave_solicitud,estado,monto_centavos,moneda,motivo,admin_id) VALUES (?,?,?,?,?,?,?)',
                [$pedidoId, 'io-refund-' . bin2hex(random_bytes(16)), 'solicitado', (int) round((float) $p['total'] * 100), $p['moneda'], $motivo, $actor['id']]);
            $r = IO::uno('SELECT * FROM io_reembolsos WHERE id = ?', [(int) IO::db()->lastInsertId()]);
            self::bitacora($actor, 'reembolso_solicitado', $pedidoId, $motivo, ['reembolso_id' => $r['id']]);
            return $r;
        });
        if ($r['estado'] === 'completo' || $r['estado'] === 'parcial') { return $r; }
        $p = IO::uno('SELECT * FROM io_pedidos WHERE id = ?', [$pedidoId]);
        if ($r['stripe_ref']) { return self::conciliar($actor, (int) $r['id']); }
        // Stripe conserva las claves al menos 24 h. Nunca crear otro reembolso después de esa ventana.
        if (strtotime($r['creado']) < time() - 23 * 3600) { return self::conciliar($actor, (int) $r['id']); }
        if ($p['pasarela'] === 'demo' && IOTienda::pasarela() === 'demo') {
            $remote = ['id' => 're_demo_' . $r['id'], 'payment_intent' => $p['pasarela_ref'], 'status' => 'succeeded',
                'amount' => (int) $r['monto_centavos'], 'currency' => strtolower($p['moneda'])];
        } else {
            if ($p['pasarela'] !== 'stripe' || !preg_match('/^pi_[a-zA-Z0-9_]+$/D', (string) $p['pasarela_ref'])) { throw new IOError('pasarela', 'El pedido no tiene un pago Stripe válido.'); }
            $remote = IOStripe::solicitar('POST', '/v1/refunds', ['payment_intent' => $p['pasarela_ref'],
                'amount' => (int) $r['monto_centavos'], 'reason' => 'requested_by_customer',
                'metadata' => ['io_reembolso_id' => (string) $r['id']]], $r['clave_solicitud']);
        }
        return self::aplicar((int) $r['id'], $remote, $actor);
    }

    public static function conciliar(array $actor, int $reembolsoId): array
    {
        $actor = self::administrador($actor);
        $r = IO::uno('SELECT r.*, p.pasarela_ref FROM io_reembolsos r JOIN io_pedidos p ON p.id = r.pedido_id WHERE r.id = ?', [$reembolsoId]);
        if (!$r) { throw new IOError('reembolso', 'No se encontró el reembolso.', 404); }
        if ($r['estado'] === 'completo') { return $r; }
        if ($r['stripe_ref']) {
            return self::aplicar($reembolsoId, IOStripe::solicitar('GET', '/v1/refunds/' . rawurlencode($r['stripe_ref'])), $actor);
        }
        $lista = IOStripe::solicitar('GET', '/v1/refunds?payment_intent=' . rawurlencode($r['pasarela_ref']) . '&limit=100');
        foreach (($lista['data'] ?? []) as $remote) {
            if ((string) ($remote['metadata']['io_reembolso_id'] ?? '') === (string) $r['id']) { return self::aplicar($reembolsoId, $remote, $actor); }
        }
        throw new IOError('conciliacion', 'No se confirmó la devolución. Revisa Stripe antes de realizar otra solicitud.', 409);
    }

    public static function aplicar(int $reembolsoId, array $remote, array $actor = []): array
    {
        return IO::transaccion(function () use ($reembolsoId, $remote, $actor) {
            $ref = IO::uno('SELECT pedido_id FROM io_reembolsos WHERE id = ?', [$reembolsoId]);
            if (!$ref) { throw new IOError('reembolso', 'No se encontró el reembolso.'); }
            $p = IO::uno('SELECT * FROM io_pedidos WHERE id = ? FOR UPDATE', [$ref['pedido_id']]);
            $r = IO::uno('SELECT * FROM io_reembolsos WHERE id = ? FOR UPDATE', [$reembolsoId]);
            if (empty($remote['id']) || ($remote['payment_intent'] ?? '') !== $p['pasarela_ref'] ||
                (int) ($remote['amount'] ?? -1) !== (int) $r['monto_centavos'] ||
                strtolower((string) ($remote['currency'] ?? '')) !== strtolower($r['moneda']) ||
                ($r['stripe_ref'] && $r['stripe_ref'] !== $remote['id'])) { throw new IOError('reembolso', 'La devolución no coincide con el pedido.', 409); }
            if ($r['estado'] === 'completo') { return $r; }
            $estados = ['succeeded' => 'completo', 'pending' => 'pendiente', 'requires_action' => 'pendiente', 'failed' => 'fallido', 'canceled' => 'fallido'];
            $estado = $estados[$remote['status'] ?? ''] ?? null;
            if (!$estado) { throw new IOError('reembolso', 'Estado de devolución no reconocido.'); }
            IO::ejecutar('UPDATE io_reembolsos SET stripe_ref = ?, estado = ? WHERE id = ?', [$remote['id'], $estado, $r['id']]);
            if ($estado === 'completo') { self::revocar($p, $actor, $r['motivo']); }
            self::bitacora($actor, 'reembolso_' . $estado, (int) $p['id'], $r['motivo'], ['reembolso_id' => $r['id']]);
            return IO::uno('SELECT * FROM io_reembolsos WHERE id = ?', [$r['id']]);
        });
    }

    private static function revocar(array $p, array $actor, string $motivo): void
    {
        $actor += ['email' => 'stripe@webhook'];
        foreach (IO::todos('SELECT acceso_id FROM io_pedido_items WHERE pedido_id = ? AND acceso_id IS NOT NULL ORDER BY acceso_id', [$p['id']]) as $item) {
            $a = IOAccesos::bloquear((int) $item['acceso_id']);
            if ($a['estado'] !== 'revocado') { IOAdmin::revocar($actor, (int) $item['acceso_id'], $motivo); }
        }
    }

    public static function tiposEventos(): array { return ['charge.refunded', 'refund.updated', 'refund.failed']; }

    public static function procesarEvento(array $evento): void
    {
        $obj = $evento['data']['object'];
        if ($evento['type'] !== 'charge.refunded') {
            $r = IO::uno('SELECT id FROM io_reembolsos WHERE stripe_ref = ?', [(string) ($obj['id'] ?? '')]);
            if (!$r && isset($obj['metadata']['io_reembolso_id'])) { $r = IO::uno('SELECT id FROM io_reembolsos WHERE id = ?', [(int) $obj['metadata']['io_reembolso_id']]); }
            if ($r) { self::aplicar((int) $r['id'], $obj); }
            return;
        }
        $p = IO::uno('SELECT * FROM io_pedidos WHERE pasarela_ref = ? AND pasarela = "stripe" FOR UPDATE', [(string) ($obj['payment_intent'] ?? '')]);
        if (!$p) {
            $propio = IO::uno('SELECT id FROM io_pedidos WHERE id = ? AND folio = ? AND pasarela = "stripe" AND pasarela_ref IS NULL',
                [(int) ($obj['metadata']['pedido_id'] ?? 0), (string) ($obj['metadata']['folio'] ?? '')]);
            if ($propio) { throw new IOError('pedido', 'La referencia de pago todavía se está vinculando.', 503); }
            return;
        }
        $total = (int) round((float) $p['total'] * 100);
        $devuelto = (int) ($obj['amount_refunded'] ?? -1);
        if ((int) ($obj['amount'] ?? -1) !== $total || $devuelto < 0 || $devuelto > $total ||
            strtolower((string) ($obj['currency'] ?? '')) !== strtolower($p['moneda'])) { throw new IOError('reembolso', 'Importe de devolución no válido.'); }
        if ($devuelto === 0) { return; }
        $clave = 'charge-' . (string) ($obj['id'] ?? '');
        $r = IO::uno('SELECT * FROM io_reembolsos WHERE clave_solicitud = ? FOR UPDATE', [$clave]);
        if ($r && (int) $r['monto_centavos'] >= $devuelto) { return; }
        $estado = $devuelto === $total ? 'completo' : 'parcial';
        if (!$r) {
            IO::ejecutar('INSERT INTO io_reembolsos (pedido_id,clave_solicitud,estado,monto_centavos,moneda,motivo) VALUES (?,?,?,?,?,?)',
                [$p['id'], $clave, $estado, $devuelto, $p['moneda'], 'Devolución confirmada por Stripe']);
        } else { IO::ejecutar('UPDATE io_reembolsos SET monto_centavos = ?,estado = ? WHERE id = ?', [$devuelto, $estado, $r['id']]); }
        if ($estado === 'completo') { self::revocar($p, [], 'Devolución confirmada por Stripe'); }
        self::bitacora([], 'reembolso_externo_' . $estado, (int) $p['id'], 'Devolución confirmada por Stripe');
    }

    public static function cancelarPendiente(array $actor, int $pedidoId, string $motivo): array
    {
        $actor = self::administrador($actor);
        if (trim($motivo) === '' || mb_strlen($motivo) > 120) { throw new IOError('motivo', 'Indica un motivo de hasta 120 caracteres.'); }
        $p = IO::uno('SELECT * FROM io_pedidos WHERE id = ?', [$pedidoId]);
        if (!$p || !in_array($p['estado'], ['pendiente', 'fallido'], true)) { throw new IOError('pedido', 'Solo se cancelan pedidos pendientes.'); }
        if ($p['pasarela'] === 'stripe' && $p['pasarela_ref']) {
            $pi = IOStripe::solicitar('POST', '/v1/payment_intents/' . rawurlencode($p['pasarela_ref']) . '/cancel', [], 'io-cancel-' . $p['folio']);
            IOTienda::validarIntento($p, $pi);
            if (($pi['status'] ?? '') !== 'canceled') { throw new IOError('pago', 'Stripe no confirmó la cancelación.'); }
        } elseif ($p['pasarela'] !== 'demo' || IOTienda::pasarela() !== 'demo') {
            throw new IOError('conciliacion', 'Sin referencia de pago: concilia el intento en Stripe antes de liberar el cupón.');
        }
        return IO::transaccion(function () use ($p, $actor, $motivo) {
            $actual = IO::uno('SELECT * FROM io_pedidos WHERE id = ? FOR UPDATE', [$p['id']]);
            if (!in_array($actual['estado'], ['pendiente', 'fallido'], true)) { throw new IOError('pedido', 'El pedido cambió de estado.'); }
            IO::ejecutar('UPDATE io_pedidos SET estado = "cancelado" WHERE id = ?', [$p['id']]);
            if (class_exists('IODescuentos')) { IODescuentos::liberar((int) $p['id']); }
            self::bitacora($actor, 'pedido_cancelado', (int) $p['id'], $motivo);
            return IO::uno('SELECT * FROM io_pedidos WHERE id = ?', [$p['id']]);
        });
    }
}
