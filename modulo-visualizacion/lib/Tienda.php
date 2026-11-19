<?php
/**
 * Inteligencia Objetiva 2.0 — Tienda: catálogo, carrito, pedidos y pago (Stripe o modo demo local).
 * Los precios SIEMPRE se leen de la base en el servidor; el navegador solo envía IDs de producto.
 */
declare(strict_types=1);
require_once __DIR__ . '/IO.php';
require_once __DIR__ . '/Descuentos.php';

final class IOTienda
{
    public const IVA = 0.16;
    public const CATEGORIAS = [
        'conferencias' => 'Conferencias en línea', 'cursos' => 'Cursos en línea', 'documentales' => 'Documentales',
        'introspecciones' => 'Introspecciones', 'audiolibro' => 'Audiolibro', 'zoom' => 'En vivo vía Zoom',
    ];
    public const TEMAS = [
        'pareja' => 'Relación de pareja', 'economia' => 'Economía', 'hijos' => 'Educación a los hijos',
        'laboral' => 'Entorno laboral', 'desarrollo' => 'Desarrollo personal', 'superacion' => 'Superación',
    ];
    private const ORDENES = [
        'populares' => 'p.ventas DESC, p.titulo', 'valorados' => 'p.rating DESC, p.resenas DESC', 'nuevos' => 'p.creado DESC, p.producto_id DESC',
        'precio_asc' => 'p.precio_mxn ASC, p.titulo', 'precio_desc' => 'p.precio_mxn DESC, p.titulo', 'az' => 'p.titulo',
    ];

    // ---------- Configuración de la pasarela ----------
    public static function cfg(): array
    {
        $c = IO::config()['tienda'] ?? [];
        // Los valores de ejemplo de config.example.php ('sk_test_...') no cuentan como llaves.
        foreach (['stripe_publica', 'stripe_secreta', 'stripe_webhook'] as $k) { if (isset($c[$k]) && substr((string) $c[$k], -3) === '...') { $c[$k] = ''; } }
        return $c + ['pasarela' => null, 'stripe_publica' => '', 'stripe_secreta' => '', 'stripe_webhook' => '', 'metodos' => ['card', 'oxxo']];
    }

    /** La demo exige dev explícito y CLI o servidor PHP de desarrollo con conexión local. */
    public static function pasarela(): string
    {
        $c = self::cfg();
        if ($c['pasarela'] === 'stripe' || ($c['pasarela'] === null && $c['stripe_secreta'] !== '')) { return 'stripe'; }
        $host = trim((string) parse_url((string) (IO::config()['dominio'] ?? ''), PHP_URL_HOST), '[]');
        $locales = ['localhost', '127.0.0.1', '::1'];
        $servidor = (string) ($_SERVER['SERVER_ADDR'] ?? $_SERVER['SERVER_NAME'] ?? '');
        $peticionLocal = PHP_SAPI === 'cli-server' && in_array($servidor, $locales, true) &&
            in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
        if (IO::dev() && in_array($host, $locales, true) && (PHP_SAPI === 'cli' || $peticionLocal)) { return 'demo'; }
        throw new IOError('pasarela', 'La pasarela de pago no está configurada.', 503);
    }

    // ---------- Catálogo ----------
    public static function catalogo(array $f, int $pagina = 1, int $por = 12): array
    {
        $w = ['p.activo = 1']; $p = [];
        if (isset(self::CATEGORIAS[$f['categoria'] ?? ''])) {
            if ($f['categoria'] === 'documentales') { $w[] = "p.categoria IN ('documentales','introspecciones')"; }
            else { $w[] = 'p.categoria = ?'; $p[] = $f['categoria']; }
        }
        if (isset(self::TEMAS[$f['tema'] ?? ''])) { $w[] = 'p.tema = ?'; $p[] = $f['tema']; }
        if (($f['acceso'] ?? '') !== '' && in_array($f['acceso'], ['ventana', 'permanente', 'unica'], true)) { $w[] = 'pol.tipo = ?'; $p[] = $f['acceso']; }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') { $w[] = '(p.titulo LIKE ? OR p.resumen LIKE ?)'; $like = '%' . $q . '%'; array_push($p, $like, $like); }
        $orden = self::ORDENES[$f['orden'] ?? ''] ?? self::ORDENES['populares'];
        $where = implode(' AND ', $w);
        $total = (int) IO::uno("SELECT COUNT(*) n FROM io_productos p LEFT JOIN io_politicas pol ON pol.producto_id = p.producto_id WHERE $where", $p)['n'];
        $pagina = max(1, $pagina); $por = max(1, min(48, $por));
        $filas = IO::todos("SELECT p.*, pol.tipo AS acceso_tipo, pol.horas_ventana, pol.duracion_seg, pol.cuenta_desde, pol.dias_para_empezar, pol.reanudaciones_max, pol.gracia_min
            FROM io_productos p LEFT JOIN io_politicas pol ON pol.producto_id = p.producto_id
            WHERE $where ORDER BY $orden LIMIT " . (int) $por . ' OFFSET ' . (int) (($pagina - 1) * $por), $p);
        return ['total' => $total, 'pagina' => $pagina, 'paginas' => (int) max(1, ceil($total / $por)), 'productos' => $filas];
    }

    public static function producto(string $slug): ?array
    {
        return IO::uno('SELECT p.*, pol.tipo AS acceso_tipo, pol.horas_ventana, pol.cuenta_desde, pol.dias_para_empezar, pol.duracion_seg, pol.umbral_pct, pol.reanudaciones_max, pol.gracia_min
            FROM io_productos p LEFT JOIN io_politicas pol ON pol.producto_id = p.producto_id WHERE p.slug = ? AND p.activo = 1', [$slug]);
    }

    /** Texto de acceso que se muestra junto al precio. */
    public static function textoAcceso(?array $p): array
    {
        $t = $p['acceso_tipo'] ?? '';
        if (!in_array($t, ['unica', 'permanente', 'ventana'], true)) {
            return ['pendiente', 'Acceso por confirmar', 'Consulta las condiciones de acceso de este producto antes de comprar.'];
        }
        if ($t === 'permanente') { return ['permanente', 'Acceso permanente', 'Lo ves las veces que quieras, cuando quieras.']; }
        $dias = (int) ($p['dias_para_empezar'] ?? 30);
        $inicio = $dias > 0
            ? 'Tienes ' . $dias . ($dias === 1 ? ' día' : ' días') . ' desde la compra para empezar.'
            : 'El plazo para empezar vence al confirmarse la compra.';
        if ($t === 'unica') {
            $veces = (int) ($p['reanudaciones_max'] ?? 2);
            $gracia = (int) ($p['gracia_min'] ?? 120);
            $retomar = $veces > 0 && $gracia > 0
                ? 'Puedes reanudar hasta ' . $veces . ($veces === 1 ? ' vez' : ' veces') . ' dentro de los ' . $gracia . ' min posteriores a una interrupción.'
                : 'No permite reanudaciones.';
            return ['unica', 'Visualización única', 'Se reproduce una sola vez. ' . $retomar . ' ' . $inicio];
        }
        $h = (int) ($p['horas_ventana'] ?? 48);
        $dur = $h % 24 === 0 && $h > 48 ? ($h / 24) . ' días' : $h . ' h';
        if (($p['cuenta_desde'] ?? 'primera_reproduccion') === 'compra') {
            return ['ventana', "$dur de acceso", "Disponible $dur desde la confirmación de tu compra."];
        }
        return ['ventana', "$dur de acceso", "Disponible $dur desde tu primera reproducción. $inicio"];
    }

    // ---------- Carrito (sesión) ----------
    private static function sesion(): void { if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); } }

    public static function carritoIds(): array { self::sesion(); return array_values(array_unique(array_map('intval', $_SESSION['io_carrito'] ?? []))); }

    public static function agregar(int $productoId): void
    {
        self::sesion();
        if (!IO::uno('SELECT 1 FROM io_productos WHERE producto_id = ? AND activo = 1', [$productoId])) { throw new IOError('producto', 'Producto no disponible.', 404); }
        $ids = self::carritoIds();
        if (!in_array($productoId, $ids, true)) { $ids[] = $productoId; }
        $_SESSION['io_carrito'] = array_slice($ids, 0, 30);
    }

    public static function quitar(int $productoId): void
    {
        self::sesion();
        $_SESSION['io_carrito'] = array_values(array_filter(self::carritoIds(), fn($id) => $id !== $productoId));
    }

    public static function vaciar(): void { self::sesion(); $_SESSION['io_carrito'] = []; unset($_SESSION['io_cupon']); }

    /** Productos del carrito con totales calculados en el servidor. */
    public static function resumen(array $ids): array
    {
        $items = [];
        if ($ids) {
            $marcas = implode(',', array_fill(0, count($ids), '?'));
            $items = IO::todos("SELECT p.producto_id, p.slug, p.titulo, p.imagen, p.precio_mxn, p.precio_anterior, p.categoria, pol.tipo AS acceso_tipo, pol.horas_ventana, pol.cuenta_desde, pol.dias_para_empezar, pol.reanudaciones_max, pol.gracia_min, pol.duracion_seg
                FROM io_productos p LEFT JOIN io_politicas pol ON pol.producto_id = p.producto_id
                WHERE p.activo = 1 AND p.producto_id IN ($marcas)", $ids);
        }
        $total = round(array_sum(array_map(fn($i) => (float) $i['precio_mxn'], $items)), 2);
        $subtotal = round($total / (1 + self::IVA), 2);
        $ahorro = round(array_sum(array_map(fn($i) => max(0, (float) $i['precio_anterior'] - (float) $i['precio_mxn']), $items)), 2);
        return ['items' => $items, 'subtotal' => $subtotal, 'iva' => round($total - $subtotal, 2), 'total' => $total, 'ahorro' => $ahorro];
    }

    // ---------- Pedidos ----------
    public static function crearPedido(array $cliente, array $ids, string $pasarela, string $codigo = ''): array
    {
        if (!$ids) { throw new IOError('carrito', 'Tu carrito está vacío.'); }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (min($ids) < 1) { throw new IOError('producto', 'El carrito contiene un producto no válido.'); }
        $r = self::resumen($ids);
        if (count($r['items']) !== count($ids)) { throw new IOError('producto', 'Uno de los productos del carrito ya no está disponible. Revísalo antes de pagar.'); }
        foreach ($r['items'] as $i) {
            if (!in_array($i['acceso_tipo'], ['ventana', 'unica', 'permanente'], true)) {
                throw new IOError('sin_politica', 'Uno de los productos todavía no tiene acceso configurado. No se realizó ningún cobro.');
            }
            if (($i['acceso_tipo'] === 'unica' && (int) $i['duracion_seg'] <= 0) ||
                ($i['acceso_tipo'] === 'ventana' && (int) $i['horas_ventana'] <= 0)) {
                throw new IOError('politica', 'Las condiciones de acceso de uno de los productos están incompletas. No se realizó ningún cobro.');
            }
            if (!is_numeric($i['precio_mxn']) || !is_finite((float) $i['precio_mxn']) || (float) $i['precio_mxn'] <= 0) {
                throw new IOError('precio', 'Uno de los productos no tiene un precio válido. No se realizó ningún cobro.');
            }
        }
        if ($r['total'] < 10) { throw new IOError('monto', 'El total mínimo para pagar es de $10 MXN.'); }
        return IO::transaccion(function () use ($cliente, $r, $pasarela, $codigo) {
            $folio = 'IO-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            IO::ejecutar('INSERT INTO io_pedidos (folio, cliente_id, email, nombre, subtotal, iva, total, pasarela) VALUES (?,?,?,?,?,?,?,?)',
                [$folio, (int) $cliente['id'], $cliente['email'], $cliente['nombre'] ?? null, $r['subtotal'], $r['iva'], $r['total'], $pasarela]);
            $id = (int) IO::db()->lastInsertId();
            foreach ($r['items'] as $i) {
                IO::ejecutar('INSERT INTO io_pedido_items (pedido_id, producto_id, titulo, precio_mxn) VALUES (?,?,?,?)', [$id, (int) $i['producto_id'], $i['titulo'], $i['precio_mxn']]);
            }
            if (trim($codigo) !== '') { IODescuentos::reservar($id, $r['items'], $codigo, (int) $cliente['id']); }
            return IO::uno('SELECT * FROM io_pedidos WHERE id = ?', [$id]);
        });
    }

    public static function pedidoPorFolio(string $folio, int $clienteId): ?array
    {
        return IO::uno('SELECT * FROM io_pedidos WHERE folio = ? AND cliente_id = ?', [$folio, $clienteId]);
    }

    /** Solo acepta datos consultados al servidor de Stripe o recibidos en un webhook firmado. */
    public static function validarIntento(array $pedido, array $pi): void
    {
        if ($pedido['pasarela'] !== 'stripe' || ($pi['id'] ?? '') !== $pedido['pasarela_ref'] ||
            strtolower((string) ($pi['currency'] ?? '')) !== strtolower($pedido['moneda']) ||
            (int) ($pi['amount'] ?? -1) !== (int) round((float) $pedido['total'] * 100)) {
            throw new IOError('intento', 'El pago no corresponde al pedido.', 409);
        }
    }

    /** Recupera la ficha real sin confirmar el pago: los accesos siguen dependiendo del webhook. */
    public static function fichaOxxo(array $pedido, array $pi): ?array
    {
        self::validarIntento($pedido, $pi);
        if (!in_array($pedido['estado'], ['pendiente', 'fallido'], true) || ($pi['status'] ?? '') !== 'requires_action' ||
            ($pi['next_action']['type'] ?? '') !== 'oxxo_display_details') { return null; }
        $f = $pi['next_action']['oxxo_display_details'] ?? [];
        $url = (string) ($f['hosted_voucher_url'] ?? '');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' ||
            !($host === 'stripe.com' || substr($host, -11) === '.stripe.com') ||
            empty($f['number']) || empty($f['expires_after'])) {
            throw new IOError('ficha', 'No se pudo recuperar la ficha OXXO.', 502);
        }
        IO::ejecutar('UPDATE io_pedidos SET metodo = "oxxo", estado = "pendiente" WHERE id = ? AND estado IN ("pendiente", "fallido")', [$pedido['id']]);
        return ['numero' => (string) $f['number'], 'url' => $url, 'vence' => (int) $f['expires_after']];
    }

    /**
     * Confirma un pago y crea los accesos. Idempotente: si el pedido ya está pagado no hace nada.
     * Lo llaman el webhook de Stripe (fuente de verdad) o la pasarela demo local.
     */
    public static function confirmarPago(int $pedidoId, ?string $ref, ?string $metodo, ?int $montoCentavos = null): array
    {
        return IO::transaccion(function () use ($pedidoId, $ref, $metodo, $montoCentavos) {
            $p = IO::uno('SELECT * FROM io_pedidos WHERE id = ? FOR UPDATE', [$pedidoId]);
            if (!$p) { throw new IOError('pedido', 'Pedido no encontrado.', 404); }
            if ($p['estado'] === 'pagado') { return $p; }
            if ($p['estado'] === 'cancelado') { throw new IOError('cancelado', 'El pedido fue cancelado y requiere conciliación.', 409); }
            if ($montoCentavos !== null && $montoCentavos !== (int) round((float) $p['total'] * 100)) {
                throw new IOError('monto', 'El monto cobrado no coincide con el pedido.', 409);
            }
            IO::ejecutar('UPDATE io_pedidos SET estado = "pagado", pagado_en = NOW(), pasarela_ref = COALESCE(?, pasarela_ref), metodo = ? WHERE id = ?', [$ref, $metodo, $pedidoId]);
            IODescuentos::consumir($pedidoId);
            // Un reembolso puede notificarse antes que el evento de pago. Registrar el pago
            // no debe crear nuevos accesos cuando el importe ya fue devuelto por completo.
            if (IO::uno('SELECT id FROM io_reembolsos WHERE pedido_id = ? AND estado = "completo" LIMIT 1', [$pedidoId])) {
                return IO::uno('SELECT * FROM io_pedidos WHERE id = ?', [$pedidoId]);
            }
            foreach (IO::todos('SELECT * FROM io_pedido_items WHERE pedido_id = ? FOR UPDATE', [$pedidoId]) as $it) {
                if ($it['acceso_id']) { continue; }
                $acceso = IOAccesos::otorgar((int) $p['cliente_id'], $p['email'], (string) $p['nombre'], (int) $it['producto_id'], 'compra', $p['folio']);
                IO::ejecutar('UPDATE io_pedido_items SET acceso_id = ? WHERE pedido_id = ? AND producto_id = ?', [$acceso, $pedidoId, $it['producto_id']]);
                IO::ejecutar('UPDATE io_productos SET ventas = ventas + 1 WHERE producto_id = ?', [$it['producto_id']]);
            }
            return IO::uno('SELECT * FROM io_pedidos WHERE id = ?', [$pedidoId]);
        });
    }

    public static function marcarFallido(int $pedidoId): void
    {
        IO::ejecutar('UPDATE io_pedidos SET estado = "fallido" WHERE id = ? AND estado = "pendiente"', [$pedidoId]);
    }

    // ---------- CSRF para formularios del alumno ----------
    public static function csrf(): string { self::sesion(); if (empty($_SESSION['io_csrf'])) { $_SESSION['io_csrf'] = bin2hex(random_bytes(16)); } return $_SESSION['io_csrf']; }
    public static function csrfOk(string $t): bool { self::sesion(); $s = (string) ($_SESSION['io_csrf'] ?? ''); return $s !== '' && $t !== '' && hash_equals($s, $t); }

    public static function dinero(float $v): string { return '$' . number_format($v, 2, '.', ','); }
}

/** Procesa un evento de webhook de Stripe ya recibido. Devuelve [código HTTP, respuesta]. */
function io_procesar_webhook_stripe(string $payload, string $firma): array
{
    try {
        $evento = IOStripe::verificarEvento($payload, $firma, (string) IOTienda::cfg()['stripe_webhook']);
        require_once __DIR__ . '/Membresias.php';
        require_once __DIR__ . '/Pedidos.php';
        $tiposMembresia = IOMembresias::tiposEventos();
        $tiposPedido = IOPedidos::tiposEventos();
        $tipos = array_merge(['payment_intent.succeeded', 'payment_intent.payment_failed', 'payment_intent.canceled', 'payment_intent.requires_action'], $tiposMembresia, $tiposPedido);
        if (!in_array($evento['type'], $tipos, true)) {
            return [200, ['ok' => true, 'ignorado' => 'tipo no utilizado']];
        }
        return IO::transaccion(function () use ($evento, $tiposMembresia, $tiposPedido) {
            // Idempotencia: Stripe puede reenviar el mismo evento.
            if (IO::ejecutar('INSERT IGNORE INTO io_webhooks (evento_id, tipo) VALUES (?,?)', [$evento['id'], (string) $evento['type']]) === 0) {
                return [200, ['ok' => true, 'duplicado' => true]];
            }
            if (in_array($evento['type'], $tiposMembresia, true)) { IOMembresias::procesarEvento($evento); return [200, ['ok' => true]]; }
            if (in_array($evento['type'], $tiposPedido, true)) { IOPedidos::procesarEvento($evento); return [200, ['ok' => true]]; }
            $pi = $evento['data']['object'];
            $pedido = IO::uno('SELECT * FROM io_pedidos WHERE pasarela_ref = ?', [(string) ($pi['id'] ?? '')]);
            if (!$pedido) {
                // Los otros pagos de la cuenta Stripe no pertenecen a esta tienda.
                $propio = IO::uno('SELECT id FROM io_pedidos WHERE id = ? AND folio = ? AND pasarela = "stripe" AND pasarela_ref IS NULL',
                    [(int) ($pi['metadata']['pedido_id'] ?? 0), (string) ($pi['metadata']['folio'] ?? '')]);
                if (!$propio) { return [200, ['ok' => true, 'ignorado' => 'sin pedido']]; }
                // Si el evento se adelantó a la vinculación, revierte su marca y permite reintentar.
                throw new IOError('pedido', 'Pedido aún no disponible.', 503);
            }
            IOTienda::validarIntento($pedido, $pi);
            switch ($evento['type']) {
                case 'payment_intent.succeeded':
                    $metodo = $pi['latest_charge']['payment_method_details']['type'] ?? $pedido['metodo'];
                    if (!$metodo && count($pi['payment_method_types'] ?? []) === 1) { $metodo = $pi['payment_method_types'][0]; }
                    IOTienda::confirmarPago((int) $pedido['id'], (string) $pi['id'], $metodo, (int) ($pi['amount_received'] ?? 0));
                    break;
                case 'payment_intent.requires_action':
                    IOTienda::fichaOxxo($pedido, $pi);
                    break;
                case 'payment_intent.payment_failed':
                    IOTienda::marcarFallido((int) $pedido['id']);
                    break;
                case 'payment_intent.canceled':
                    if ($pedido['estado'] !== 'pagado') {
                        IO::ejecutar('UPDATE io_pedidos SET estado = "cancelado" WHERE id = ?', [$pedido['id']]);
                        IODescuentos::liberar((int) $pedido['id']);
                    }
                    break;
            }
            return [200, ['ok' => true]];
        });
    } catch (IOError $e) {
        // La marca del evento y todos sus efectos se revierten juntos.
        return [in_array($e->codigo, ['firma', 'evento'], true) ? 400 : 500, ['ok' => false, 'error' => $e->codigo]];
    } catch (Throwable $e) {
        error_log('[IO webhook] ' . $e->getMessage());
        return [500, ['ok' => false]];
    }
}

/** Cliente mínimo de la API de Stripe (sin SDK) para PaymentIntents y verificación de webhooks. */
final class IOStripe
{
    private static $transportePrueba = null;

    /** Sustituye únicamente el transporte HTTP durante pruebas CLI. */
    public static function usarTransporteParaPruebas(?callable $fn): void
    {
        if (PHP_SAPI !== 'cli') { throw new IOError('pruebas', 'No disponible.', 403); }
        self::$transportePrueba = $fn;
    }

    public static function solicitar(string $metodo, string $ruta, array $datos = [], ?string $idempotencia = null): array
    {
        return self::llamar($metodo, $ruta, $datos, $idempotencia);
    }

    public static function consultarIntento(string $id): array
    {
        if (!preg_match('/^pi_[a-zA-Z0-9_]+$/D', $id)) { throw new IOError('intento', 'Referencia de pago inválida.'); }
        return self::llamar('GET', '/v1/payment_intents/' . rawurlencode($id), []);
    }

    public static function crearIntento(array $pedido): array
    {
        $c = IOTienda::cfg();
        $datos = [
            'amount' => (int) round((float) $pedido['total'] * 100),
            'currency' => 'mxn',
            'description' => 'Inteligencia Objetiva · pedido ' . $pedido['folio'],
            'receipt_email' => $pedido['email'],
            'metadata' => ['folio' => $pedido['folio'], 'pedido_id' => (string) $pedido['id'], 'cliente_id' => (string) $pedido['cliente_id']],
            'payment_method_types' => array_values($c['metodos']),
        ];
        $r = self::llamar('POST', '/v1/payment_intents', $datos, 'io-' . $pedido['folio']);
        IO::ejecutar('UPDATE io_pedidos SET pasarela_ref = ? WHERE id = ?', [$r['id'], $pedido['id']]);
        return $r;
    }

    private static function llamar(string $metodo, string $ruta, array $datos, ?string $idempotencia = null): array
    {
        if (self::$transportePrueba !== null && PHP_SAPI === 'cli') {
            return (self::$transportePrueba)($metodo, $ruta, $datos, $idempotencia);
        }
        if (!in_array($metodo, ['GET', 'POST', 'DELETE'], true) || !preg_match('#^/v1/[a-zA-Z0-9_/?=&\[\].%-]+$#D', $ruta)) {
            throw new IOError('stripe', 'Operación de pago no válida.');
        }
        $c = IOTienda::cfg();
        if ($c['stripe_secreta'] === '') { throw new IOError('pasarela', 'Stripe no está configurado.', 503); }
        if ($metodo === 'GET' && $datos) { $ruta .= (strpos($ruta, '?') === false ? '?' : '&') . http_build_query($datos); }
        $ch = curl_init('https://api.stripe.com' . $ruta);
        $h = ['Authorization: Bearer ' . $c['stripe_secreta'], 'Stripe-Version: 2024-06-20'];
        if ($idempotencia) { $h[] = 'Idempotency-Key: ' . $idempotencia; }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $h,
            CURLOPT_CUSTOMREQUEST => $metodo]);
        if ($metodo !== 'GET') { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($datos)); }
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $j = json_decode((string) $res, true);
        if ($code < 200 || $code >= 300 || !is_array($j)) {
            error_log('[IO stripe] HTTP ' . $code);
            throw new IOError('stripe', 'No se pudo iniciar el pago. Intenta de nuevo.', 502);
        }
        return $j;
    }

    /** Verifica la cabecera Stripe-Signature (HMAC-SHA256, tolerancia de 5 min). Devuelve el evento o lanza error. */
    public static function verificarEvento(string $payload, string $cabecera, string $secreto, int $tolerancia = 300, ?int $ahora = null): array
    {
        $partes = [];
        foreach (explode(',', $cabecera) as $kv) { [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, ''); $partes[$k][] = $v; }
        $t = (int) ($partes['t'][0] ?? 0);
        if ($t <= 0 || $secreto === '') { throw new IOError('firma', 'Firma ausente.', 400); }
        if (abs(($ahora ?? time()) - $t) > $tolerancia) { throw new IOError('firma', 'Firma vencida.', 400); }
        $esperada = hash_hmac('sha256', $t . '.' . $payload, $secreto);
        $valida = false;
        foreach ($partes['v1'] ?? [] as $firma) { if (hash_equals($esperada, $firma)) { $valida = true; } }
        if (!$valida) { throw new IOError('firma', 'Firma inválida.', 400); }
        $evento = json_decode($payload, true);
        if (!is_array($evento) || empty($evento['id']) || !is_string($evento['id']) ||
            !is_string($evento['type'] ?? null) || !is_array($evento['data']['object'] ?? null)) {
            throw new IOError('evento', 'Evento inválido.', 400);
        }
        return $evento;
    }
}
