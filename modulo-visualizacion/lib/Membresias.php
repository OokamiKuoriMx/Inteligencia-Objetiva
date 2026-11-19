<?php
/** Stripe Billing: solo los períodos pagados habilitan contenido. */
declare(strict_types=1);
require_once __DIR__ . '/Tienda.php';

final class IOMembresias
{
    public static function tiposEventos(): array
    {
        return ['checkout.session.completed', 'invoice.paid', 'invoice.payment_failed',
            'customer.subscription.updated', 'customer.subscription.deleted'];
    }

    public static function planes(): array
    {
        $cfg = IO::config()['membresias'] ?? [];
        if (empty($cfg['habilitadas'])) { return []; }
        $planes = [];
        foreach (($cfg['planes'] ?? []) as $clave => $p) {
            if (!preg_match('/^[a-z0-9_-]{1,60}$/D', (string) $clave) || !is_array($p) ||
                !preg_match('/^price_[A-Za-z0-9]+$/D', (string) ($p['stripe_price'] ?? '')) ||
                filter_var($p['importe_centavos'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1000]]) === false ||
                strtolower((string) ($p['moneda'] ?? '')) !== 'mxn' || !in_array($p['intervalo'] ?? '', ['month', 'year'], true)) { continue; }
            $ids = array_values(array_unique(array_map('intval', $p['productos'] ?? [])));
            if (!$ids || count($ids) > 500 || min($ids) < 1) { continue; }
            $planes[$clave] = $p + ['nombre' => $clave];
            $planes[$clave]['productos'] = $ids;
        }
        return $planes;
    }

    private static function urlBase(): string
    {
        $base = rtrim((string) (IO::config()['dominio'] ?? ''), '/');
        if (parse_url($base, PHP_URL_SCHEME) !== 'https' || !parse_url($base, PHP_URL_HOST)) {
            throw new IOError('dominio', 'Configura el dominio HTTPS antes de activar las membresías.', 503);
        }
        return $base;
    }

    public static function checkout(array $cliente, string $plan): string
    {
        $p = self::planes()[$plan] ?? null;
        if (!$p || IOTienda::pasarela() !== 'stripe') { throw new IOError('membresia', 'Este plan todavía no está disponible.', 503); }
        if ((int) ($cliente['id'] ?? 0) < 1 || !filter_var($cliente['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new IOError('cliente', 'Inicia sesión con una cuenta válida.', 401);
        }
        $base = self::urlBase();
        $precio = IOStripe::solicitar('GET', '/v1/prices/' . rawurlencode($p['stripe_price']));
        if (empty($precio['active']) || (int) ($precio['unit_amount'] ?? -1) !== (int) $p['importe_centavos'] ||
            ($precio['currency'] ?? '') !== 'mxn' || ($precio['recurring']['interval'] ?? '') !== $p['intervalo'] ||
            (int) ($precio['recurring']['interval_count'] ?? 0) !== 1 || ($precio['type'] ?? '') !== 'recurring') {
            throw new IOError('precio_membresia', 'El precio de Stripe no coincide con las condiciones del plan.', 409);
        }
        foreach ($p['productos'] as $id) {
            $politica = IOAccesos::politica($id);
            if (($politica['tipo'] === 'unica' && (int) $politica['duracion_seg'] <= 0) || $politica['vimeo_id'] === '') {
                throw new IOError('politica', 'Un contenido del plan aún no está listo.', 409);
            }
        }
        $m = IO::transaccion(function () use ($cliente, $plan, $p) {
            $clave = (int) $cliente['id'] . ':' . $plan;
            $m = IO::uno('SELECT * FROM io_membresias WHERE clave_activa = ? FOR UPDATE', [$clave]);
            if ($m && $m['estado'] !== 'pendiente') { throw new IOError('membresia_activa', 'Ya tienes este plan. Puedes administrarlo desde tu cuenta.', 409); }
            if ($m && $m['checkout_vence'] && $m['checkout_vence'] <= IO::ahora()) {
                // Una fecha local vencida no demuestra que Checkout no se haya pagado.
                // Stripe debe confirmar expired antes de permitir otra contratación.
                if (!$m['checkout_ref']) { throw new IOError('membresia_conciliacion', 'El equipo debe conciliar la contratación anterior antes de crear otra.', 409); }
                $remota = IOStripe::solicitar('GET', '/v1/checkout/sessions/' . rawurlencode($m['checkout_ref']));
                if (($remota['id'] ?? '') !== $m['checkout_ref'] || ($remota['status'] ?? '') !== 'expired') {
                    throw new IOError('membresia_confirmando', 'La contratación anterior todavía está abierta o esperando confirmación. No se creará otro cobro.', 409);
                }
                IO::ejecutar('UPDATE io_membresias SET estado = "expirada", clave_activa = NULL WHERE id = ?', [$m['id']]); $m = null;
            }
            if ($m) { return $m; }
            IO::ejecutar('INSERT INTO io_membresias (cliente_id,email,nombre,plan,precio_ref,importe_centavos,moneda,intervalo,productos,clave_activa,enlace_token)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)', [(int) $cliente['id'], $cliente['email'], $cliente['nombre'] ?? '', $plan, $p['stripe_price'],
                (int) $p['importe_centavos'], 'mxn', $p['intervalo'], json_encode($p['productos']), $clave, bin2hex(random_bytes(24))]);
            return IO::uno('SELECT * FROM io_membresias WHERE id = ?', [(int) IO::db()->lastInsertId()]);
        });
        if ($m['checkout_ref']) {
            $existente = IOStripe::solicitar('GET', '/v1/checkout/sessions/' . rawurlencode($m['checkout_ref']));
            if (($existente['id'] ?? '') !== $m['checkout_ref'] || ($existente['status'] ?? '') !== 'open' || (int) ($existente['expires_at'] ?? 0) <= time()) {
                throw new IOError('membresia_confirmando', 'Esperamos la confirmación de tu contratación anterior. No se creará otro cobro.', 409);
            }
            return self::urlStripe((string) ($existente['url'] ?? ''), 'checkout.stripe.com');
        }
        // Un timeout pudo ocurrir después de crear la sesión remota. Stripe conserva
        // la idempotencia al menos 24 h: después de 23 h se exige conciliación manual.
        if (strtotime($m['creado']) < time() - 23 * 3600) {
            throw new IOError('membresia_conciliacion', 'La contratación anterior necesita conciliación. El equipo debe revisar Stripe antes de crear otra.', 409);
        }
        $metadata = ['io_membresia' => (string) $m['id'], 'io_enlace' => $m['enlace_token']];
        $sesion = IOStripe::solicitar('POST', '/v1/checkout/sessions', [
            'mode' => 'subscription', 'payment_method_types' => ['card'], 'line_items' => [['price' => $m['precio_ref'], 'quantity' => 1]],
            'customer_email' => $cliente['email'], 'client_reference_id' => (string) $cliente['id'],
            'metadata' => $metadata, 'subscription_data' => ['metadata' => $metadata],
            'success_url' => $base . '/io/tienda/membresias.php?resultado=confirmando',
            'cancel_url' => $base . '/io/tienda/membresias.php?resultado=cancelado'], 'io-membresia-' . $m['id']);
        $url = self::urlStripe((string) ($sesion['url'] ?? ''), 'checkout.stripe.com');
        if (!preg_match('/^cs_[A-Za-z0-9_]+$/D', (string) ($sesion['id'] ?? '')) || (int) ($sesion['expires_at'] ?? 0) <= time()) {
            throw new IOError('checkout', 'No se pudo preparar la suscripción.', 502);
        }
        IO::ejecutar('UPDATE io_membresias SET checkout_ref = ?, checkout_vence = ? WHERE id = ?',
            [$sesion['id'], date('Y-m-d H:i:s', $sesion['expires_at']), $m['id']]);
        return $url;
    }

    private static function urlStripe(string $url, string $host): string
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || strtolower((string) parse_url($url, PHP_URL_HOST)) !== $host) {
            throw new IOError('stripe_url', 'Stripe no devolvió un enlace válido.', 502);
        }
        return $url;
    }

    public static function delCliente(int $id): array
    {
        return IO::todos('SELECT id,plan,estado,pagado_hasta,cancelar_al_final,suscripcion_ref FROM io_membresias WHERE cliente_id = ? ORDER BY creado DESC', [$id]);
    }

    private static function propia(int $id, int $cliente): array
    {
        $m = IO::uno('SELECT * FROM io_membresias WHERE id = ? AND cliente_id = ?', [$id, $cliente]);
        if (!$m || !$m['suscripcion_ref'] || !$m['cliente_stripe']) { throw new IOError('membresia', 'Suscripción no encontrada.', 404); }
        return $m;
    }

    public static function portal(int $id, int $cliente): string
    {
        $m = self::propia($id, $cliente);
        $r = IOStripe::solicitar('POST', '/v1/billing_portal/sessions', ['customer' => $m['cliente_stripe'],
            'return_url' => self::urlBase() . '/io/tienda/membresias.php']);
        return self::urlStripe((string) ($r['url'] ?? ''), 'billing.stripe.com');
    }

    public static function cancelar(int $id, int $cliente): void
    {
        $m = self::propia($id, $cliente);
        if ($m['estado'] === 'cancelada') { return; }
        $r = IOStripe::solicitar('POST', '/v1/subscriptions/' . rawurlencode($m['suscripcion_ref']), ['cancel_at_period_end' => 'true'], 'io-cancelar-membresia-' . $id);
        if (($r['id'] ?? '') !== $m['suscripcion_ref'] || empty($r['cancel_at_period_end'])) { throw new IOError('cancelacion', 'No se pudo programar la cancelación.', 502); }
        IO::ejecutar('UPDATE io_membresias SET cancelar_al_final = 1 WHERE id = ?', [$id]);
    }

    /** Solo debe llamarse después de verificar la firma del evento y dentro de la transacción del webhook. */
    public static function procesarEvento(array $evento): void
    {
        if (!in_array($evento['type'] ?? '', self::tiposEventos(), true)) { return; }
        IO::transaccion(function () use ($evento) {
            $o = $evento['data']['object']; $tipo = $evento['type']; $creado = (int) ($evento['created'] ?? 0);
            if ($tipo === 'checkout.session.completed') {
                if (($o['mode'] ?? '') !== 'subscription') { return; }
                self::vincular($o['metadata'] ?? [], self::referencia($o['subscription'] ?? ''), self::referencia($o['customer'] ?? ''), (string) ($o['id'] ?? ''));
                return; // El retorno de Checkout nunca concede contenido.
            }
            $sub = strpos($tipo, 'customer.subscription.') === 0 ? (string) ($o['id'] ?? '') :
                self::referencia($o['subscription'] ?? $o['parent']['subscription_details']['subscription'] ?? '');
            if (!preg_match('/^sub_[A-Za-z0-9]+$/D', $sub)) { return; }
            $m = IO::uno('SELECT * FROM io_membresias WHERE suscripcion_ref = ? FOR UPDATE', [$sub]);
            if (!$m) {
                $remota = IOStripe::solicitar('GET', '/v1/subscriptions/' . rawurlencode($sub));
                $m = self::vincular($remota['metadata'] ?? [], $sub, self::referencia($remota['customer'] ?? ''));
                if (!$m) { return; }
            }
            if ($tipo === 'invoice.paid') { self::facturaPagada($m, $o, $creado); return; }
            if ($tipo === 'invoice.payment_failed') {
                if ($creado >= (int) $m['evento_pago'] && $m['estado'] !== 'cancelada') {
                    IO::ejecutar('UPDATE io_membresias SET estado = "morosa", evento_pago = ? WHERE id = ?', [$creado, $m['id']]);
                }
                return; // Conserva únicamente el tiempo ya pagado.
            }
            $cancelada = $tipo === 'customer.subscription.deleted' || ($o['status'] ?? '') === 'canceled';
            // Una suscripción cancelada nunca vuelve a activarse en Stripe. Su evento
            // terminal prevalece incluso si fue entregado tarde o empata el segundo.
            if (!$cancelada && ($m['estado'] === 'cancelada' || $creado <= (int) $m['evento_estado'])) { return; }
            if ($cancelada) {
                $fin = date('Y-m-d H:i:s', (int) ($o['ended_at'] ?? $creado));
                IO::ejecutar('UPDATE io_membresias SET estado = "cancelada", clave_activa = NULL, cancelada_en = ?, evento_estado = ?, cancelar_al_final = 1,
                    pagado_hasta = IF(pagado_hasta > ?, ?, pagado_hasta) WHERE id = ?', [$fin, $creado, $fin, $fin, $m['id']]);
                self::limitarAccesos((int) $m['id'], $fin);
            } else {
                IO::ejecutar('UPDATE io_membresias SET cancelar_al_final = ?, evento_estado = ? WHERE id = ?', [!empty($o['cancel_at_period_end']) ? 1 : 0, $creado, $m['id']]);
            }
        });
    }

    private static function referencia($obj): string { return is_array($obj) ? (string) ($obj['id'] ?? '') : (string) $obj; }

    private static function vincular(array $meta, string $sub, string $customer, ?string $checkout = null): ?array
    {
        if (empty($meta['io_membresia']) || empty($meta['io_enlace'])) { return null; }
        $m = IO::uno('SELECT * FROM io_membresias WHERE id = ? FOR UPDATE', [(int) $meta['io_membresia']]);
        if (!$m || !hash_equals($m['enlace_token'], (string) $meta['io_enlace'])) { throw new IOError('membresia_enlace', 'La suscripción no corresponde a esta membresía.', 409); }
        if (!preg_match('/^sub_[A-Za-z0-9]+$/D', $sub) || !preg_match('/^cus_[A-Za-z0-9]+$/D', $customer) ||
            ($m['suscripcion_ref'] && $m['suscripcion_ref'] !== $sub) || ($m['cliente_stripe'] && $m['cliente_stripe'] !== $customer) ||
            ($checkout !== null && $m['checkout_ref'] && $m['checkout_ref'] !== $checkout)) {
            throw new IOError('membresia_enlace', 'Las referencias de Stripe no coinciden.', 409);
        }
        IO::ejecutar('UPDATE io_membresias SET suscripcion_ref = ?, cliente_stripe = ? WHERE id = ?', [$sub, $customer, $m['id']]);
        return IO::uno('SELECT * FROM io_membresias WHERE id = ?', [$m['id']]);
    }

    private static function facturaPagada(array $m, array $f, int $creado): void
    {
        $ref = (string) ($f['id'] ?? '');
        if (!preg_match('/^in_[A-Za-z0-9]+$/D', $ref)) { throw new IOError('factura', 'Factura de suscripción inválida.', 409); }
        if (IO::uno('SELECT factura_ref FROM io_membresia_facturas WHERE factura_ref = ?', [$ref])) { return; }
        if (!in_array($f['billing_reason'] ?? '', ['subscription_create', 'subscription_cycle'], true)) { return; }
        $lineas = $f['lines']['data'] ?? [];
        if (count($lineas) !== 1 || !empty($f['lines']['has_more'])) { throw new IOError('factura', 'La factura contiene ajustes que requieren revisión.', 409); }
        $l = $lineas[0];
        $price = self::referencia($l['price'] ?? $l['pricing']['price_details']['price'] ?? '');
        $inicio = (int) ($l['period']['start'] ?? 0); $fin = (int) ($l['period']['end'] ?? 0);
        if (($f['status'] ?? '') !== 'paid' || ($f['currency'] ?? '') !== $m['moneda'] ||
            (int) ($f['amount_paid'] ?? -1) !== (int) $m['importe_centavos'] ||
            (int) ($l['amount'] ?? -1) !== (int) $m['importe_centavos'] || $price !== $m['precio_ref'] ||
            (int) ($l['quantity'] ?? 0) !== 1 || !empty($l['proration']) || $inicio <= 0 || $fin <= $inicio ||
            self::referencia($f['customer'] ?? '') !== $m['cliente_stripe']) {
            throw new IOError('factura_membresia', 'El pago no coincide con el plan contratado.', 409);
        }
        $desde = date('Y-m-d H:i:s', $inicio); $hasta = date('Y-m-d H:i:s', $fin);
        IO::ejecutar('INSERT INTO io_membresia_facturas (factura_ref,membresia_id,periodo_inicio,periodo_fin,importe_centavos) VALUES (?,?,?,?,?)',
            [$ref, $m['id'], $desde, $hasta, (int) $m['importe_centavos']]);
        if ($m['estado'] === 'cancelada' || ($m['pagado_hasta'] && $m['pagado_hasta'] >= $hasta)) { return; }
        if ($inicio > time() + 300) { throw new IOError('periodo', 'El período todavía no comenzó.', 409); }
        $ids = json_decode($m['productos'], true);
        foreach ($ids as $id) {
            $vinculo = IO::uno('SELECT acceso_id FROM io_membresia_accesos WHERE membresia_id = ? AND producto_id = ? FOR UPDATE', [$m['id'], $id]);
            if (!$vinculo) {
                $politica = IOAccesos::politica((int) $id);
                $acceso = IOAccesos::otorgar((int) $m['cliente_id'], $m['email'], $m['nombre'], (int) $id, 'membresia', 'MEM-' . $m['id']);
                IO::ejecutar('UPDATE io_accesos SET estado = ?, inicio = ?, vence = ?, limite_inicio = NULL WHERE id = ?',
                    [$politica['tipo'] === 'unica' ? 'por_empezar' : 'activo', $politica['tipo'] === 'unica' ? null : $desde, $hasta, $acceso]);
                IO::ejecutar('INSERT INTO io_membresia_accesos (membresia_id,producto_id,acceso_id) VALUES (?,?,?)', [$m['id'], $id, $acceso]);
            } else {
                IO::ejecutar('UPDATE io_accesos SET vence = ?, estado = IF(estado = "vencido", IF(inicio IS NULL, "por_empezar", "activo"), estado) WHERE id = ?', [$hasta, $vinculo['acceso_id']]);
            }
        }
        IO::ejecutar('UPDATE io_membresias SET pagado_hasta = ?, estado = "activa", evento_pago = GREATEST(evento_pago, ?) WHERE id = ?', [$hasta, $creado, $m['id']]);
    }

    private static function limitarAccesos(int $id, string $fin): void
    {
        $accesos = IO::todos('SELECT acceso_id FROM io_membresia_accesos WHERE membresia_id = ? ORDER BY acceso_id', [$id]);
        foreach ($accesos as $a) {
            IO::ejecutar('UPDATE io_accesos SET vence = IF(vence > ?, ?, vence) WHERE id = ?', [$fin, $fin, $a['acceso_id']]);
            if ($fin <= IO::ahora()) { IO::ejecutar('UPDATE io_sesiones SET activa = 0, cerrada_por = "membresia_cancelada" WHERE acceso_id = ? AND activa = 1', [$a['acceso_id']]); }
        }
    }
}
