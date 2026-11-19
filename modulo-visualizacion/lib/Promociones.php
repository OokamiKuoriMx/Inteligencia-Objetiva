<?php
/** Campañas informativas: los precios efectivos siempre proceden del catálogo. */
declare(strict_types=1);
require_once __DIR__ . '/IO.php';

final class IOPromociones
{
    /** Fechas absolutas con zona horaria; una configuración incompleta no publica una campaña. */
    public static function campana(array $config, ?int $ahora = null): ?array
    {
        $fechas = [];
        foreach (['inicio', 'fin'] as $clave) {
            $valor = (string) ($config[$clave] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', $valor)) { return null; }
            $fecha = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $valor);
            if (!$fecha || $fecha->format(DateTimeInterface::ATOM) !== $valor) { return null; }
            $fechas[$clave] = $fecha->getTimestamp();
        }
        $ahora = $ahora ?? time();
        if ($fechas['inicio'] >= $fechas['fin'] || $ahora < $fechas['inicio'] || $ahora >= $fechas['fin']) { return null; }
        if (!is_array($config['productos'] ?? null)) { return null; }
        $ids = [];
        foreach (($config['productos'] ?? []) as $id) {
            if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) { $ids[] = (int) $id; }
        }
        $ids = array_values(array_unique($ids));
        if (!$ids) { return null; }
        return $fechas + ['productos' => $ids, 'fin_iso' => (string) $config['fin']];
    }

    /** Solo anuncia rebajas positivas y comprobables; nunca redondea el descuento hacia arriba. */
    public static function resolver(array $config, array $productos, ?int $ahora = null): ?array
    {
        $campana = self::campana($config, $ahora);
        if (!$campana) { return null; }
        $elegidos = []; $maximo = 0;
        foreach ($productos as $p) {
            if (empty($p['activo']) || !in_array((int) $p['producto_id'], $campana['productos'], true)) { continue; }
            $precio = (int) round((float) $p['precio_mxn'] * 100);
            $antes = (int) round((float) ($p['precio_anterior'] ?? 0) * 100);
            if ($precio <= 0 || $antes <= $precio) { continue; }
            $pct = (int) floor(($antes - $precio) * 100 / $antes);
            if ($pct < 1) { continue; }
            $p['descuento_pct'] = $pct;
            $elegidos[] = $p;
            $maximo = max($maximo, $pct);
        }
        if (!$elegidos) { return null; }
        return ['fin_iso' => $campana['fin_iso'], 'fin' => $campana['fin'], 'maximo_pct' => $maximo, 'productos' => $elegidos];
    }

    public static function actual(): ?array
    {
        $config = IO::config()['tienda']['promocion'] ?? [];
        $campana = self::campana($config);
        if (!$campana) { return null; }
        $marcas = implode(',', array_fill(0, count($campana['productos']), '?'));
        $productos = IO::todos("SELECT p.*, pol.tipo AS acceso_tipo, pol.horas_ventana, pol.cuenta_desde, pol.dias_para_empezar
            FROM io_productos p LEFT JOIN io_politicas pol ON pol.producto_id = p.producto_id
            WHERE p.activo = 1 AND p.precio_mxn > 0 AND p.precio_anterior > p.precio_mxn AND p.producto_id IN ($marcas)
            ORDER BY p.titulo", $campana['productos']);
        return self::resolver($config, $productos);
    }
}
