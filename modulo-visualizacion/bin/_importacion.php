<?php
/** Preparación de filas de importación sin conexiones ni escrituras. */
declare(strict_types=1);

function io_preparar_producto(array $f, array $regla, ?array $actual, bool $actualizarReglas = false): array
{
    $id = filter_var($f['producto_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
    $titulo = trim((string) ($f['titulo'] ?? ''));
    $vimeo = trim((string) ($f['vimeo_id'] ?? ''));
    if (!$id || $titulo === '' || !preg_match('/^\d{1,32}$/D', $vimeo)) {
        throw new InvalidArgumentException('Requiere producto_id positivo, título y un ID numérico de Vimeo.');
    }
    $base = [
        'producto_id' => $id, 'titulo' => mb_substr($titulo, 0, 255), 'vimeo_id' => $vimeo,
        'vimeo_hash' => $f['vimeo_hash'] ?? null, 'duracion_seg' => (int) ($f['duracion_seg'] ?? 0),
        'tipo' => $regla['tipo'] ?? 'ventana', 'horas_ventana' => (int) ($regla['horas_ventana'] ?? 48),
        'cuenta_desde' => $regla['cuenta_desde'] ?? 'primera_reproduccion', 'dias_para_empezar' => (int) ($regla['dias_para_empezar'] ?? 30),
        'umbral_pct' => 95, 'retroceso_seg' => 30, 'permitir_adelantar' => 0, 'pausa_max_min' => 60, 'reanudaciones_max' => 2, 'gracia_min' => 120,
    ];
    // Una sincronización de catálogo no cambia las condiciones ya decididas en el backoffice.
    if ($actual && !$actualizarReglas) {
        foreach (['tipo', 'horas_ventana', 'cuenta_desde', 'dias_para_empezar', 'umbral_pct', 'retroceso_seg',
                  'permitir_adelantar', 'pausa_max_min', 'reanudaciones_max', 'gracia_min'] as $campo) {
            $base[$campo] = $actual[$campo];
        }
    }
    if ($actual) {
        if (!array_key_exists('vimeo_hash', $f) && $vimeo === $actual['vimeo_id']) { $base['vimeo_hash'] = $actual['vimeo_hash']; }
        if ($base['duracion_seg'] <= 0 && $vimeo === $actual['vimeo_id']) { $base['duracion_seg'] = (int) $actual['duracion_seg']; }
    }
    if ($base['tipo'] === 'unica' && $base['duracion_seg'] <= 0) {
        throw new InvalidArgumentException('Visualización única sin duración: completa duracion_seg o configura Vimeo.');
    }
    $avisos = [];
    $precio = null;
    if (array_key_exists('precio_mxn', $f)) {
        if (!is_numeric($f['precio_mxn']) || !is_finite((float) $f['precio_mxn']) ||
            (float) $f['precio_mxn'] <= 0 || (float) $f['precio_mxn'] > 99999999.99) {
            throw new InvalidArgumentException('precio_mxn debe ser un importe positivo en MXN con IVA incluido.');
        }
        $precio = round((float) $f['precio_mxn'], 2);
        if ($precio <= 0) { throw new InvalidArgumentException('precio_mxn redondeado debe ser mayor que cero.'); }
    } else { $avisos[] = 'sin precio: no se creará ni actualizará su ficha comercial'; }
    foreach (['resumen', 'descripcion', 'imagen', 'aprenderas', 'trailer_vimeo'] as $campo) {
        if (trim((string) ($f[$campo] ?? '')) === '') { $avisos[] = "falta $campo"; }
    }
    if (isset($f['precio_anterior']) && (!is_numeric($f['precio_anterior']) || !is_finite((float) $f['precio_anterior']) ||
        (float) $f['precio_anterior'] < 0 || (float) $f['precio_anterior'] > 99999999.99)) {
        throw new InvalidArgumentException('precio_anterior debe ser nulo o un importe no negativo.');
    }
    return ['politica' => $base, 'precio' => $precio, 'avisos' => $avisos];
}
