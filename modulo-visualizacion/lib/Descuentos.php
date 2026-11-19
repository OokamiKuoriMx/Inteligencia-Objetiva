<?php
/** Cupones en el servidor. Las reservas no caducan mientras el cobro todavía puede completarse. */
declare(strict_types=1);
require_once __DIR__ . '/IO.php';

final class IODescuentos
{
    public static function codigo(string $codigo): string
    {
        $codigo = strtoupper(trim($codigo));
        if ($codigo !== '' && !preg_match('/^[A-Z0-9][A-Z0-9_-]{2,39}$/D', $codigo)) {
            throw new IOError('cupon', 'El código del cupón no es válido.');
        }
        return $codigo;
    }

    /** Vista previa sin escrituras. Si hay transacción, bloquea el cupón para reservar sin carreras. */
    public static function calcular(array $items, string $codigo, int $clienteId): array
    {
        $codigo = self::codigo($codigo); $total = 0; $vistos = [];
        foreach ($items as &$item) {
            $id = (int) $item['producto_id'];
            if ($id <= 0 || isset($vistos[$id])) { throw new IOError('carrito', 'El carrito contiene productos duplicados o inválidos.'); }
            $vistos[$id] = true;
            $centavos = (int) round((float) $item['precio_mxn'] * 100);
            if ($centavos <= 0) { throw new IOError('precio', 'El carrito contiene un precio no válido.'); }
            $item['precio_original_mxn'] = $centavos / 100;
            $item['importe_original_centavos'] = $centavos;
            $item['descuento_centavos'] = 0;
            $total += $centavos;
        }
        unset($item);
        if ($total > 9999999999) { throw new IOError('monto', 'El carrito supera el importe máximo permitido.'); }
        if ($codigo === '') { return self::resultado($items, null); }
        if ($clienteId <= 0) { throw new IOError('sin_sesion', 'Inicia sesión para aplicar un cupón.', 401); }
        $c = IO::uno('SELECT * FROM io_cupones WHERE codigo = ?' . (IO::db()->inTransaction() ? ' FOR UPDATE' : ''), [$codigo]);
        if (!$c || !(int) $c['activo'] || $c['inicio'] > IO::ahora() || $c['fin'] <= IO::ahora()) {
            throw new IOError('cupon', 'El cupón no está disponible o ya venció.');
        }
        if ($total < (int) round((float) $c['minimo_mxn'] * 100)) { throw new IOError('cupon_minimo', 'El carrito no alcanza el mínimo del cupón.'); }
        // Lectura actual con bloqueo: el pedido pudo fijar un snapshot REPEATABLE READ antes de esperar por el cupón.
        $usos = IO::todos('SELECT cliente_id FROM io_descuentos_pedidos WHERE cupon_id = ? AND estado IN ("reservado","consumido")' .
            (IO::db()->inTransaction() ? ' FOR UPDATE' : ''), [$c['id']]);
        $delCliente = count(array_filter($usos, static fn($uso) => (int) $uso['cliente_id'] === $clienteId));
        if (($c['max_usos'] !== null && count($usos) >= (int) $c['max_usos']) || $delCliente >= (int) $c['max_por_cliente']) {
            throw new IOError('cupon_limite', 'El cupón alcanzó su límite de uso o ya está reservado en otro pedido.');
        }
        $permitidos = json_decode((string) $c['productos'], true) ?: [];
        $elegibles = [];
        foreach ($items as $k => $item) {
            if ($permitidos && !in_array((int) $item['producto_id'], array_map('intval', $permitidos), true)) { continue; }
            if (!(int) $c['incluir_rebajados'] && (float) ($item['precio_anterior'] ?? 0) > (float) $item['precio_mxn']) { continue; }
            $elegibles[$k] = $item['importe_original_centavos'];
        }
        if (!$elegibles) { throw new IOError('cupon_productos', 'El cupón no aplica a los productos del carrito.'); }
        if ($c['tipo'] === 'porcentaje') {
            $puntos = (int) round((float) $c['valor'] * 100);
            foreach ($elegibles as $k => $importe) { $items[$k]['descuento_centavos'] = intdiv($importe * $puntos, 10000); }
        } elseif ($c['tipo'] === 'importe') {
            $suma = array_sum($elegibles); $importe = min($suma, (int) round((float) $c['valor'] * 100));
            $asignado = 0; $restos = [];
            foreach ($elegibles as $k => $base) {
                [$parte, $resto] = self::proporcion($base, $importe, $suma);
                $items[$k]['descuento_centavos'] = $parte;
                $asignado += $items[$k]['descuento_centavos'];
                $restos[$k] = $resto;
            }
            arsort($restos, SORT_NUMERIC);
            foreach ($restos as $k => $_) { if ($asignado >= $importe) { break; } $items[$k]['descuento_centavos']++; $asignado++; }
        } elseif ($c['tipo'] === '2x1') {
            if (count($elegibles) < 2) { throw new IOError('cupon_productos', 'El 2×1 requiere dos productos distintos elegibles.'); }
            arsort($elegibles, SORT_NUMERIC); $posicion = 0;
            foreach ($elegibles as $k => $importe) { if (++$posicion % 2 === 0) { $items[$k]['descuento_centavos'] = $importe; } }
        } else { throw new IOError('cupon', 'El cupón no tiene una regla válida.'); }
        foreach ($items as &$item) { $item['precio_mxn'] = ($item['importe_original_centavos'] - $item['descuento_centavos']) / 100; }
        unset($item);
        $r = self::resultado($items, $c);
        if ($r['descuento_centavos'] <= 0) { throw new IOError('cupon_productos', 'El cupón no produce un descuento en este carrito.'); }
        if ($r['total_centavos'] < 1000) { throw new IOError('cupon_minimo', 'Después del descuento el total debe ser de al menos $10 MXN.'); }
        return $r;
    }

    /** Multiplicación/división entera sin desbordar al repartir importes DECIMAL(10,2). */
    private static function proporcion(int $base, int $importe, int $suma): array
    {
        $q = 0; $r = 0;
        foreach (str_split((string) $importe) as $digito) {
            $n = $r * 10 + $base * (int) $digito;
            $q = $q * 10 + intdiv($n, $suma); $r = $n % $suma;
        }
        return [$q, $r];
    }

    private static function resultado(array $items, ?array $cupon): array
    {
        $bruto = array_sum(array_column($items, 'importe_original_centavos'));
        $descuento = array_sum(array_column($items, 'descuento_centavos'));
        $total = $bruto - $descuento; $subtotal = (int) round($total / 1.16);
        return ['items' => $items, 'cupon' => $cupon ? ['id' => (int) $cupon['id'], 'codigo' => $cupon['codigo'], 'tipo' => $cupon['tipo']] : null,
            'original_centavos' => $bruto, 'descuento_centavos' => $descuento, 'total_centavos' => $total,
            'total' => $total / 100, 'subtotal' => $subtotal / 100, 'iva' => ($total - $subtotal) / 100];
    }

    /** Dentro de crearPedido. Guarda el precio final por línea y la reserva en la misma transacción. */
    public static function reservar(int $pedidoId, array $items, string $codigo, int $clienteId): array
    {
        if (!IO::db()->inTransaction()) { throw new LogicException('La reserva requiere la transacción del pedido.'); }
        $p = IO::uno('SELECT * FROM io_pedidos WHERE id = ? FOR UPDATE', [$pedidoId]);
        if (!$p || (int) $p['cliente_id'] !== $clienteId || $p['estado'] !== 'pendiente') { throw new IOError('pedido', 'No se puede reservar el cupón en este pedido.'); }
        $anterior = IO::uno('SELECT * FROM io_descuentos_pedidos WHERE pedido_id = ?', [$pedidoId]);
        if ($anterior) { throw new IOError('cupon', 'El pedido ya tiene una reserva de descuento.'); }
        $r = self::calcular($items, $codigo, $clienteId);
        if (!$r['cupon']) { return $r; }
        foreach ($r['items'] as $item) {
            if (IO::ejecutar('UPDATE io_pedido_items SET precio_mxn = ? WHERE pedido_id = ? AND producto_id = ?', [$item['precio_mxn'], $pedidoId, $item['producto_id']]) === 0) {
                // MySQL puede informar cero cuando el importe no cambia. Comprueba existencia, no rowCount.
                if (!IO::uno('SELECT 1 FROM io_pedido_items WHERE pedido_id = ? AND producto_id = ?', [$pedidoId, $item['producto_id']])) { throw new IOError('pedido', 'Falta un producto en el pedido.'); }
            }
        }
        IO::ejecutar('UPDATE io_pedidos SET subtotal = ?, iva = ?, total = ? WHERE id = ?', [$r['subtotal'], $r['iva'], $r['total'], $pedidoId]);
        IO::ejecutar('INSERT INTO io_descuentos_pedidos (pedido_id,cupon_id,cliente_id,codigo,descuento_centavos,detalle) VALUES (?,?,?,?,?,?)',
            [$pedidoId, $r['cupon']['id'], $clienteId, $r['cupon']['codigo'], $r['descuento_centavos'], json_encode($r['items'], JSON_UNESCAPED_UNICODE)]);
        return $r;
    }

    public static function consumir(int $pedidoId): void
    {
        if (!IO::db()->inTransaction()) { throw new LogicException('El consumo requiere la transacción del pago.'); }
        $p = IO::uno('SELECT estado FROM io_pedidos WHERE id = ? FOR UPDATE', [$pedidoId]);
        $r = IO::uno('SELECT * FROM io_descuentos_pedidos WHERE pedido_id = ? FOR UPDATE', [$pedidoId]);
        if (!$r) { return; }
        if (!$p || $p['estado'] !== 'pagado') { throw new IOError('cupon_pago', 'El cupón solo se consume al confirmar el pago.'); }
        if ($r['estado'] === 'liberado') { throw new IOError('cupon_liberado', 'El descuento fue liberado; el pago requiere revisión.', 409); }
        IO::ejecutar('UPDATE io_descuentos_pedidos SET estado = "consumido", consumido = COALESCE(consumido,NOW()) WHERE pedido_id = ?', [$pedidoId]);
    }

    /** Solo después de confirmar cancelación definitiva con la pasarela. Un rechazo no libera. */
    public static function liberar(int $pedidoId): void
    {
        if (!IO::db()->inTransaction()) { throw new LogicException('La liberación requiere la transacción de cancelación.'); }
        $p = IO::uno('SELECT estado FROM io_pedidos WHERE id = ? FOR UPDATE', [$pedidoId]);
        if (!$p || $p['estado'] !== 'cancelado') { throw new IOError('cupon_cancelacion', 'Primero debe confirmarse la cancelación definitiva del pedido.'); }
        IO::ejecutar('UPDATE io_descuentos_pedidos SET estado = "liberado" WHERE pedido_id = ? AND estado = "reservado"', [$pedidoId]);
    }

    public static function guardar(array $admin, array $datos, string $motivo): int
    {
        IOEquipo::autorizar($admin, 'politicas', 'Sin permiso para administrar descuentos.');
        $motivo = trim($motivo);
        if ($motivo === '' || mb_strlen($motivo) > 120) { throw new IOError('motivo', 'Escribe un motivo de hasta 120 caracteres.'); }
        $codigo = self::codigo((string) ($datos['codigo'] ?? ''));
        if ($codigo === '') { throw new IOError('cupon', 'Escribe el código del cupón.'); }
        $tipo = (string) ($datos['tipo'] ?? ''); $valor = (float) ($datos['valor'] ?? 0);
        if (!in_array($tipo, ['porcentaje', 'importe', '2x1'], true) || !is_finite($valor) ||
            ($tipo === 'porcentaje' && ($valor <= 0 || $valor > 100)) || ($tipo === 'importe' && ($valor <= 0 || $valor > 99999999.99))) {
            throw new IOError('cupon', 'Revisa el tipo y valor del descuento.');
        }
        $inicio = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', (string) ($datos['inicio'] ?? ''));
        $fin = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', (string) ($datos['fin'] ?? ''));
        if (!$inicio || !$fin || $inicio->format('Y-m-d\TH:i') !== $datos['inicio'] || $fin->format('Y-m-d\TH:i') !== $datos['fin'] || $fin <= $inicio) {
            throw new IOError('cupon', 'Define un inicio y fin válidos para el cupón.');
        }
        if (!is_numeric($datos['minimo_mxn'] ?? 0)) { throw new IOError('cupon', 'La compra mínima debe ser un importe numérico.'); }
        $minimo = (float) ($datos['minimo_mxn'] ?? 0);
        $max = ($datos['max_usos'] ?? '') === '' ? null : filter_var($datos['max_usos'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $porCliente = filter_var($datos['max_por_cliente'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_finite($minimo) || $minimo < 0 || $minimo > 99999999.99 || $max === false || $porCliente === false) { throw new IOError('cupon', 'Revisa los límites del cupón.'); }
        $ids = array_filter(array_map('trim', explode(',', (string) ($datos['productos'] ?? ''))));
        foreach ($ids as $id) { if (!ctype_digit($id) || (int) $id < 1) { throw new IOError('cupon', 'Los productos deben ser IDs positivos separados por comas.'); } }
        $campos = [$codigo, $tipo, $tipo === '2x1' ? 0 : round($valor, 2), $inicio->format('Y-m-d H:i:s'), $fin->format('Y-m-d H:i:s'),
            round($minimo, 2), $max, $porCliente, json_encode(array_values(array_unique(array_map('intval', $ids)))), !empty($datos['incluir_rebajados']) ? 1 : 0, !empty($datos['activo']) ? 1 : 0];
        return IO::transaccion(function () use ($admin, $datos, $motivo, $campos) {
            $id = (int) ($datos['id'] ?? 0);
            if ($id) {
                if (!IO::uno('SELECT id FROM io_cupones WHERE id = ? FOR UPDATE', [$id])) { throw new IOError('cupon', 'Cupón no encontrado.', 404); }
                IO::ejecutar('UPDATE io_cupones SET codigo=?,tipo=?,valor=?,inicio=?,fin=?,minimo_mxn=?,max_usos=?,max_por_cliente=?,productos=?,incluir_rebajados=?,activo=? WHERE id=?', array_merge($campos, [$id]));
            } else {
                IO::ejecutar('INSERT INTO io_cupones (codigo,tipo,valor,inicio,fin,minimo_mxn,max_usos,max_por_cliente,productos,incluir_rebajados,activo) VALUES (?,?,?,?,?,?,?,?,?,?,?)', $campos);
                $id = (int) IO::db()->lastInsertId();
            }
            IO::ejecutar('INSERT INTO io_bitacora (admin_id,admin_email,accion,motivo,detalle) VALUES (?,?,?,?,?)',
                [$admin['id'], $admin['email'] ?? '', 'cupon', $motivo, json_encode(['cupon_id' => $id, 'codigo' => $campos[0]], JSON_UNESCAPED_UNICODE)]);
            return $id;
        });
    }
}
