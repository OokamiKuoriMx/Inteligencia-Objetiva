<?php
/** CFDI 4.0 para pedidos pagados. Facturapi v2, sin envío de correos. */
declare(strict_types=1);
require_once __DIR__ . '/Tienda.php';

final class IOFacturacion
{
    private static $transporte = null;
    private static $pago = null;

    /** Inyección exclusiva de procesos CLI de prueba, nunca configurable desde una petición. */
    public static function usarTransporteParaPruebas(?callable $fn, ?callable $pago = null): void
    {
        if (PHP_SAPI !== 'cli') { throw new LogicException('Solo pruebas CLI.'); }
        self::$transporte = $fn; self::$pago = $pago;
    }

    public static function cfg(): array
    {
        return (IO::config()['facturacion'] ?? []) + ['habilitada' => false, 'modo' => 'prueba', 'api_key' => '', 'producto_sat' => '', 'unidad_sat' => '', 'productos' => []];
    }

    public static function habilitada(): bool
    {
        $c = self::cfg();
        $prefijo = $c['modo'] === 'produccion' ? 'sk_live_' : 'sk_test_';
        return !empty($c['habilitada']) && in_array($c['modo'], ['prueba', 'produccion'], true) &&
            strpos((string) $c['api_key'], $prefijo) === 0 && substr((string) $c['api_key'], -3) !== '...';
    }

    private static function exigirConfiguracion(): void
    {
        if (!self::habilitada() && !self::$transporte) { throw new IOError('facturacion_config', 'La facturación todavía no está configurada.', 503); }
    }

    private static function pedidoPropio(array $cliente, int $pedidoId, bool $bloquear = false): array
    {
        $p = IO::uno('SELECT * FROM io_pedidos WHERE id = ? AND cliente_id = ?' . ($bloquear ? ' FOR UPDATE' : ''), [$pedidoId, (int) ($cliente['id'] ?? 0)]);
        if (!$p) { throw new IOError('pedido', 'Pedido no encontrado.', 404); }
        return $p;
    }

    /** No expone datos fiscales en listados ajenos ni devuelve secretos de proveedor. */
    public static function dePedido(array $cliente, int $pedidoId): ?array
    {
        self::pedidoPropio($cliente, $pedidoId);
        return IO::uno('SELECT pedido_id, uuid, estado, total_centavos, moneda, error_codigo, actualizado FROM io_facturas WHERE pedido_id = ?', [$pedidoId]);
    }

    public static function validarReceptor(array $d): array
    {
        $rfc = mb_strtoupper(trim((string) ($d['rfc'] ?? '')), 'UTF-8');
        $nombre = trim((string) ($d['razon_social'] ?? ''));
        $regimen = (string) ($d['regimen'] ?? ''); $cp = (string) ($d['codigo_postal'] ?? ''); $uso = (string) ($d['uso_cfdi'] ?? '');
        if (!preg_match('/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/uD', $rfc) || in_array($rfc, ['XAXX010101000', 'XEXX010101000'], true)) {
            throw new IOError('rfc', 'Escribe tu RFC nacional, tal como aparece en tu constancia fiscal.');
        }
        if (mb_strlen($nombre) < 3 || mb_strlen($nombre) > 254 || preg_match('/[\x00-\x1F]/u', $nombre)) { throw new IOError('razon_social', 'Revisa la razón social de tu constancia fiscal.'); }
        $regimenes = ['601','603','605','606','607','608','610','611','612','614','615','616','620','621','622','623','624','625','626'];
        $usos = ['G01','G02','G03','I01','I02','I03','I04','I05','I06','I07','I08','D01','D02','D03','D04','D05','D06','D07','D08','D09','D10','S01','CP01','CN01'];
        if (!in_array($regimen, $regimenes, true) || !preg_match('/^\d{5}$/D', $cp) || !in_array($uso, $usos, true)) {
            throw new IOError('datos_fiscales', 'Revisa régimen fiscal, código postal y uso del CFDI.');
        }
        return ['legal_name' => $nombre, 'tax_id' => $rfc, 'tax_system' => $regimen, 'address' => ['zip' => $cp, 'country' => 'MEX'], 'default_invoice_use' => $uso];
    }

    private static function formaPago(array $p): string
    {
        if ($p['metodo'] === 'oxxo') { return '01'; }
        if ($p['metodo'] !== 'card' || $p['pasarela'] !== 'stripe' || !preg_match('/^pi_[A-Za-z0-9_]+$/D', (string) $p['pasarela_ref'])) {
            throw new IOError('forma_pago', 'No se pudo determinar la forma de pago fiscal del pedido. Solicita ayuda al equipo.');
        }
        $pi = self::$pago ? (self::$pago)($p['pasarela_ref']) : IOStripe::solicitar('GET', '/v1/payment_intents/' . rawurlencode($p['pasarela_ref']), ['expand' => ['latest_charge']]);
        if (($pi['id'] ?? '') !== $p['pasarela_ref'] || ($pi['status'] ?? '') !== 'succeeded' ||
            (int) ($pi['amount_received'] ?? -1) !== (int) round((float) $p['total'] * 100)) {
            throw new IOError('forma_pago', 'No se pudo verificar el pago para emitir la factura.');
        }
        $tipo = $pi['latest_charge']['payment_method_details']['card']['funding'] ?? '';
        if (!in_array($tipo, ['credit', 'debit'], true)) { throw new IOError('forma_pago', 'La tarjeta requiere revisión de su forma de pago fiscal. Solicita ayuda al equipo.'); }
        return $tipo === 'credit' ? '04' : '28';
    }

    private static function sinReembolso(int $pedidoId): void
    {
        // Permite aplicar 006 antes de 007 sin depender del orden de despliegue de módulos.
        if (!IO::uno("SHOW TABLES LIKE 'io_reembolsos'")) { return; }
        if (IO::uno('SELECT id FROM io_reembolsos WHERE pedido_id = ? AND estado IN ("solicitado","pendiente","parcial","completo") LIMIT 1', [$pedidoId])) {
            throw new IOError('reembolso', 'El pedido tiene una devolución en proceso o completada. El equipo debe revisar su comprobante fiscal.');
        }
    }

    public static function solicitar(array $cliente, int $pedidoId, array $datos): array
    {
        self::exigirConfiguracion();
        $factura = IO::transaccion(function () use ($cliente, $pedidoId, $datos) {
            $p = self::pedidoPropio($cliente, $pedidoId, true);
            if ($p['estado'] !== 'pagado') { throw new IOError('sin_pago', 'La factura solo se solicita después de confirmar el pago.'); }
            self::sinReembolso($pedidoId);
            $existente = IO::uno('SELECT * FROM io_facturas WHERE pedido_id = ? FOR UPDATE', [$pedidoId]);
            if ($existente) {
                // Un rechazo HTTP 400 es definitivo, pero se concilia antes de corregir el receptor.
                // Se conserva la misma clave remota incluso al corregir datos, evitando dobles CFDI.
                if ($existente['estado'] === 'error' && !$existente['proveedor_id']) {
                    $lista = self::llamar('GET', '/invoices', ['external_id' => $existente['external_id'], 'limit' => 2]);
                    if (empty($lista['data'])) {
                        $payload = json_decode($existente['datos_json'], true, 512, JSON_THROW_ON_ERROR);
                        $payload['customer'] = self::validarReceptor($datos); $payload['use'] = $datos['uso_cfdi'];
                        IO::ejecutar('UPDATE io_facturas SET datos_json = ?, estado = "reservada", error_codigo = NULL WHERE pedido_id = ?',
                            [json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $pedidoId]);
                        return IO::uno('SELECT * FROM io_facturas WHERE pedido_id = ?', [$pedidoId]);
                    }
                }
                return $existente;
            }
            if ($p['pasarela'] === 'demo' && (self::cfg()['modo'] !== 'prueba' || !IO::dev())) { throw new IOError('demo', 'Un pedido de demostración no puede facturarse en producción.'); }
            $receptor = self::validarReceptor($datos); $c = self::cfg();
            $items = []; $suma = 0;
            foreach (IO::todos('SELECT * FROM io_pedido_items WHERE pedido_id = ? ORDER BY producto_id', [$pedidoId]) as $i) {
                $sat = ($c['productos'][(int) $i['producto_id']] ?? []) + ['producto_sat' => $c['producto_sat'], 'unidad_sat' => $c['unidad_sat']];
                if (!preg_match('/^\d{8}$/D', (string) $sat['producto_sat']) || !preg_match('/^[A-Z0-9]{2,3}$/D', (string) $sat['unidad_sat'])) {
                    throw new IOError('clasificacion_sat', 'El equipo debe configurar las claves SAT de este producto antes de facturarlo.', 503);
                }
                $centavos = (int) round((float) $i['precio_mxn'] * 100);
                if ($centavos < 0) { throw new IOError('importe', 'El pedido contiene un importe no válido.'); }
                $suma += $centavos;
                if ($centavos === 0) { continue; }
                $items[] = ['quantity' => 1, 'product' => ['description' => (string) $i['titulo'], 'product_key' => $sat['producto_sat'],
                    'unit_key' => $sat['unidad_sat'], 'price' => $centavos / 100, 'tax_included' => true, 'taxability' => '02', 'taxes' => [['type' => 'IVA', 'rate' => 0.16]]]];
            }
            $total = (int) round((float) $p['total'] * 100);
            if (!$items || $suma !== $total || strtoupper($p['moneda']) !== 'MXN') { throw new IOError('importe', 'El importe de los conceptos no coincide con el pedido. Solicita revisión al equipo.'); }
            $clave = 'io-' . bin2hex(random_bytes(20));
            $payload = ['customer' => $receptor, 'items' => $items, 'payment_form' => self::formaPago($p), 'payment_method' => 'PUE',
                'use' => $datos['uso_cfdi'], 'currency' => 'MXN', 'type' => 'I', 'external_id' => $clave, 'idempotency_key' => $clave];
            IO::ejecutar('INSERT INTO io_facturas (pedido_id,external_id,idempotencia,estado,total_centavos,moneda,datos_json) VALUES (?,?,?,"reservada",?,"MXN",?)',
                [$pedidoId, $clave, $clave, $total, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            return IO::uno('SELECT * FROM io_facturas WHERE pedido_id = ?', [$pedidoId]);
        });
        if (in_array($factura['estado'], ['cancelada', 'cancelacion_pendiente'], true)) { return self::dePedido($cliente, $pedidoId); }
        return self::sincronizar($cliente, $factura, true);
    }

    public static function consultar(array $cliente, int $pedidoId): ?array
    {
        self::exigirConfiguracion(); self::pedidoPropio($cliente, $pedidoId);
        $f = IO::uno('SELECT * FROM io_facturas WHERE pedido_id = ?', [$pedidoId]);
        return $f ? self::sincronizar($cliente, $f, false) : null;
    }

    private static function sincronizar(array $cliente, array $f, bool $crear): array
    {
        try {
            if ($f['proveedor_id']) { $remota = self::llamar('GET', '/invoices/' . rawurlencode($f['proveedor_id'])); }
            else {
                $lista = self::llamar('GET', '/invoices', ['external_id' => $f['external_id'], 'limit' => 2]);
                $encontradas = $lista['data'] ?? [];
                if (count($encontradas) > 1) { throw new IOError('factura_duplicada', 'El equipo debe revisar comprobantes duplicados.', 409); }
                $remota = $encontradas[0] ?? null;
                if (!$remota && $crear) { $remota = self::llamar('POST', '/invoices', json_decode($f['datos_json'], true, 512, JSON_THROW_ON_ERROR)); }
                if (!$remota) { return self::dePedido($cliente, (int) $f['pedido_id']); }
            }
            self::guardarRemota($f, $remota);
        } catch (IOError $e) {
            IO::ejecutar('UPDATE io_facturas SET estado = IF(proveedor_id IS NULL,?,estado), error_codigo = ? WHERE pedido_id = ?',
                [$e->codigo === 'factura_datos' ? 'error' : 'pendiente', $e->codigo, $f['pedido_id']]);
            throw $e;
        }
        return self::dePedido($cliente, (int) $f['pedido_id']);
    }

    private static function guardarRemota(array $f, array $r): void
    {
        $payload = json_decode($f['datos_json'], true);
        if (empty($r['id']) || ($r['external_id'] ?? '') !== $f['external_id'] || strtoupper((string) ($r['currency'] ?? '')) !== $f['moneda'] ||
            (int) round((float) ($r['total'] ?? -1) * 100) !== (int) $f['total_centavos'] ||
            ($r['customer']['tax_id'] ?? '') !== $payload['customer']['tax_id'] ||
            (bool) ($r['livemode'] ?? true) !== (self::cfg()['modo'] === 'produccion')) {
            throw new IOError('factura_respuesta', 'El comprobante requiere revisión de identidad, importe o ambiente.', 502);
        }
        $estado = ($r['status'] ?? '') === 'canceled' ? 'cancelada' :
            (($r['cancellation_status'] ?? '') === 'pending' ? 'cancelacion_pendiente' : (($r['status'] ?? '') === 'valid' ? 'emitida' : 'pendiente'));
        IO::ejecutar('UPDATE io_facturas SET proveedor_id = ?, uuid = ?, estado = IF(estado = "cancelada","cancelada",?), error_codigo = NULL WHERE pedido_id = ?',
            [(string) $r['id'], $r['uuid'] ?? null, $estado, $f['pedido_id']]);
    }

    public static function descargar(array $cliente, int $pedidoId, string $formato): string
    {
        self::exigirConfiguracion();
        if (!in_array($formato, ['pdf', 'xml'], true)) { throw new IOError('formato', 'Formato no permitido.'); }
        self::pedidoPropio($cliente, $pedidoId);
        $f = IO::uno('SELECT * FROM io_facturas WHERE pedido_id = ?', [$pedidoId]);
        if (!$f || !$f['proveedor_id'] || !in_array($f['estado'], ['emitida', 'cancelacion_pendiente', 'cancelada'], true)) { throw new IOError('factura_pendiente', 'El comprobante todavía no está disponible.', 409); }
        $binario = self::llamar('GET', '/invoices/' . rawurlencode($f['proveedor_id']) . '/' . $formato, [], true);
        if (!is_string($binario) || $binario === '' || strlen($binario) > 20000000) { throw new IOError('archivo', 'No se pudo descargar el comprobante.', 502); }
        return $binario;
    }

    public static function cancelar(int $pedidoId, string $motivoSAT, ?string $sustitucion = null, array $admin = [], string $motivoHumano = ''): array
    {
        self::exigirConfiguracion();
        $a = IOEquipo::autorizar($admin, 'facturas', 'Necesitas el permiso “Cancelar facturas” para solicitar la cancelación fiscal.');
        if (trim($motivoHumano) === '' || mb_strlen($motivoHumano) > 120) { throw new IOError('motivo', 'Indica un motivo de hasta 120 caracteres.'); }
        if (!in_array($motivoSAT, ['01','02','03','04'], true) || (in_array($motivoSAT, ['01','04'], true) && !preg_match('/^[a-fA-F0-9-]{36}$/D', (string) $sustitucion))) {
            throw new IOError('motivo_sat', 'Revisa el motivo SAT y el UUID de sustitución cuando corresponda.');
        }
        $f = IO::transaccion(function () use ($pedidoId, $a, $motivoHumano, $motivoSAT, $sustitucion) {
            $p = IO::uno('SELECT id FROM io_pedidos WHERE id = ? FOR UPDATE', [$pedidoId]);
            $f = IO::uno('SELECT * FROM io_facturas WHERE pedido_id = ? FOR UPDATE', [$pedidoId]);
            if (!$p || !$f || !$f['proveedor_id']) { throw new IOError('factura', 'Concilia primero el comprobante antes de cancelarlo.', 409); }
            if ($f['estado'] === 'cancelada') { return $f; }
            IO::ejecutar('UPDATE io_facturas SET estado = "cancelacion_pendiente" WHERE pedido_id = ?', [$pedidoId]);
            IO::ejecutar('INSERT INTO io_bitacora (admin_id,admin_email,accion,motivo,detalle) VALUES (?,?,?,?,?)',
                [$a['id'], $a['email'], 'cancelar_cfdi', $motivoHumano, json_encode(['pedido_id' => $pedidoId, 'motivo_sat' => $motivoSAT, 'sustitucion' => $sustitucion])]);
            return $f;
        });
        if ($f['estado'] === 'cancelada') { return ['pedido_id' => $pedidoId, 'estado' => 'cancelada']; }
        $r = self::llamar('GET', '/invoices/' . rawurlencode($f['proveedor_id']));
        if (($r['status'] ?? '') !== 'canceled' && ($r['cancellation_status'] ?? '') !== 'pending') {
            $query = ['motive' => $motivoSAT]; if ($sustitucion) { $query['substitution'] = $sustitucion; }
            $r = self::llamar('DELETE', '/invoices/' . rawurlencode($f['proveedor_id']), $query);
        }
        IO::transaccion(function () use ($f, $r, $a, $motivoHumano, $pedidoId) {
            self::guardarRemota($f, $r);
            IO::ejecutar('INSERT INTO io_bitacora (admin_id,admin_email,accion,motivo,detalle) VALUES (?,?,?,?,?)',
                [$a['id'], $a['email'], 'resultado_cfdi', $motivoHumano, json_encode(['pedido_id' => $pedidoId, 'estado' => $r['status'] ?? '', 'cancelacion' => $r['cancellation_status'] ?? ''])]);
        });
        return IO::uno('SELECT pedido_id,estado,uuid FROM io_facturas WHERE pedido_id = ?', [$pedidoId]);
    }

    /** URL fija: la aplicación nunca sigue una URL de descarga recibida del navegador. */
    private static function llamar(string $metodo, string $ruta, array $datos = [], bool $binario = false)
    {
        if (self::$transporte) { return (self::$transporte)($metodo, $ruta, $datos, $binario); }
        $url = 'https://www.facturapi.io/v2' . $ruta;
        if ($metodo !== 'POST' && $datos) { $url .= '?' . http_build_query($datos); }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . self::cfg()['api_key'], 'Content-Type: application/json']]);
        if ($metodo === 'POST') { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); }
        $respuesta = curl_exec($ch); $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($http < 200 || $http >= 300 || $respuesta === false) {
            $codigo = $http === 400 ? 'factura_datos' : 'factura_pendiente';
            throw new IOError($codigo, $http === 400 ? 'El proveedor rechazó los datos fiscales. Solicita revisión al equipo.' : 'No se pudo confirmar el comprobante. Actualiza su estado antes de volver a solicitarlo.', $http === 400 ? 400 : 502);
        }
        if ($binario) { return $respuesta; }
        $json = json_decode($respuesta, true);
        if (!is_array($json)) { throw new IOError('factura_pendiente', 'El proveedor no devolvió un comprobante verificable.', 502); }
        return $json;
    }
}
