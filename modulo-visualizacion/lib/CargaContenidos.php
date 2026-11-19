<?php
/** CSV de nuevas fichas como borrador: vista previa sin escrituras y confirmación atómica. */
declare(strict_types=1);
require_once __DIR__ . '/Contenidos.php';

final class IOCargaContenidos
{
    public const MAX_BYTES = 2097152;
    public const MAX_FILAS = 200;
    public const VIGENCIA_SEGUNDOS = 1800;
    public const COLUMNAS = ['producto_id', 'titulo', 'categoria', 'tema', 'resumen', 'descripcion', 'aprenderas', 'imagen', 'precio_mxn',
        'precio_anterior', 'incluye', 'trailer_vimeo', 'vimeo_id', 'vimeo_hash', 'duracion_seg', 'tipo', 'horas_ventana', 'cuenta_desde',
        'dias_para_empezar', 'umbral_pct', 'retroceso_seg', 'permitir_adelantar', 'pausa_max_min', 'reanudaciones_max', 'gracia_min', 'destacado', 'activo'];

    private static function autorizado(array $actor): array
    {
        $a = IO::uno('SELECT id,email,rol FROM io_admins WHERE id = ? AND activo = 1', [(int) ($actor['id'] ?? 0)]);
        if (!$a || !in_array($a['rol'], ['admin', 'editor'], true)) { throw new IOError('sin_permiso', 'Sin permiso para cargar contenidos.', 403); }
        return $a;
    }

    public static function plantilla(string $separador = ';'): string
    {
        if (!in_array($separador, [',', ';'], true)) { throw new IOError('csv', 'Separador no permitido.'); }
        $memoria = fopen('php://memory', 'w+');
        fwrite($memoria, "\xEF\xBB\xBF");
        fputcsv($memoria, self::COLUMNAS, $separador, '"', '');
        rewind($memoria); $csv = (string) stream_get_contents($memoria); fclose($memoria);
        return $csv; // Solo encabezados: ninguna fila de ejemplo puede importarse por accidente.
    }

    private static function cabecera(string $csv): array
    {
        $mejor = null;
        foreach ([';', ','] as $separador) {
            $f = fopen('php://memory', 'w+'); fwrite($f, $csv); rewind($f);
            $columnas = fgetcsv($f, 0, $separador, '"', ''); fclose($f);
            if (!is_array($columnas)) { continue; }
            $columnas = array_map(static fn($v) => mb_strtolower(trim((string) $v), 'UTF-8'), $columnas);
            $puntos = count(array_intersect($columnas, self::COLUMNAS));
            if ($mejor === null || $puntos > $mejor['puntos']) { $mejor = ['separador' => $separador, 'columnas' => $columnas, 'puntos' => $puntos]; }
        }
        if (!$mejor || !in_array('producto_id', $mejor['columnas'], true) || !in_array('titulo', $mejor['columnas'], true)) {
            throw new IOError('csv_cabecera', 'La primera fila debe incluir producto_id y titulo. Usa la plantilla descargable.');
        }
        $columnas = $mejor['columnas'];
        if (count($columnas) !== count(array_unique($columnas)) || array_diff($columnas, self::COLUMNAS)) {
            throw new IOError('csv_cabecera', 'Hay encabezados repetidos o no permitidos. No incluyas slug, versiones ni columnas de edición.');
        }
        return $mejor;
    }

    private static function normalizar(array $datos): array
    {
        foreach ($datos as $campo => $valor) {
            if (!is_string($valor) || preg_match('/^[\s\x{FEFF}]*[=+\-@]/u', $valor)) { throw new IOError('csv_formula', 'No se admiten fórmulas ni celdas que comiencen con =, +, - o @. Usa texto o valores directos.'); }
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $valor)) { throw new IOError('csv', 'El archivo contiene caracteres de control no permitidos.'); }
        }
        if (isset($datos['activo']) && !in_array(trim($datos['activo']), ['', '0'], true)) { throw new IOError('csv_publicacion', 'La carga masiva solo crea borradores. Deja activo vacío o con 0.'); }
        foreach (['permitir_adelantar', 'destacado'] as $campo) {
            if (isset($datos[$campo]) && !in_array(trim($datos[$campo]), ['', '0', '1'], true)) { throw new IOError('csv_booleano', 'Usa únicamente 0 o 1 en ' . $campo . '.'); }
        }
        $d = IOContenidos::nuevo();
        foreach ($datos as $campo => $valor) {
            if (trim($valor) !== '') { $d[$campo] = $valor; }
        }
        $d['activo'] = 0; $d['producto_id_original'] = ''; $d['version'] = '';
        return IOContenidos::validarNuevo($d);
    }

    private static function validarComillas(string $csv, string $separador): void
    {
        $dentro = false; $principio = true; $cerrada = false; $longitud = strlen($csv);
        for ($i = 0; $i < $longitud; $i++) {
            $c = $csv[$i];
            if ($dentro) {
                if ($c === '"') {
                    if ($i + 1 < $longitud && $csv[$i + 1] === '"') { $i++; }
                    else { $dentro = false; $cerrada = true; }
                }
                continue;
            }
            if ($c === $separador || $c === "\r" || $c === "\n") { $principio = true; $cerrada = false; continue; }
            if ($cerrada) {
                if ($c === ' ' || $c === "\t") { continue; }
                throw new IOError('csv_comillas', 'Revisa las comillas del CSV. Después de cerrar una celda debe venir el separador o el fin de fila.');
            }
            if ($c === '"') {
                if (!$principio) { throw new IOError('csv_comillas', 'Las comillas dentro de una celda deben duplicarse y la celda completa debe ir entre comillas.'); }
                $dentro = true;
            }
            $principio = false;
        }
        if ($dentro) { throw new IOError('csv_comillas', 'El archivo termina con una celda entre comillas sin cerrar.'); }
    }

    private static function huella(array $filas): string
    {
        return hash('sha256', json_encode($filas, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    /** Devuelve todas las incidencias; ninguna fila se escribe durante esta operación. */
    public static function previsualizar(array $actor, string $csv): array
    {
        $a = self::autorizado($actor);
        if (strlen($csv) > self::MAX_BYTES) { throw new IOError('csv_tamano', 'El CSV debe pesar como máximo 2 MB.'); }
        if (substr($csv, 0, 3) === "\xEF\xBB\xBF") { $csv = substr($csv, 3); }
        if ($csv === '' || !mb_check_encoding($csv, 'UTF-8')) { throw new IOError('csv_codificacion', 'Guarda el archivo como CSV UTF-8. No se aceptan archivos XLSX o UTF-16.'); }
        $cabecera = self::cabecera($csv);
        self::validarComillas($csv, $cabecera['separador']);
        $f = fopen('php://memory', 'w+'); fwrite($f, $csv); rewind($f);
        fgetcsv($f, 0, $cabecera['separador'], '"', '');
        $filas = []; $errores = []; $vistos = []; $numero = 1; $total = 0;
        try {
            while (($valores = fgetcsv($f, 0, $cabecera['separador'], '"', '')) !== false) {
                $numero++;
                if (count(array_filter($valores, static fn($v) => trim((string) $v) !== '')) === 0) { continue; }
                if (++$total > self::MAX_FILAS) { throw new IOError('csv_filas', 'El CSV admite como máximo ' . self::MAX_FILAS . ' contenidos por carga.'); }
                try {
                    if (count($valores) !== count($cabecera['columnas'])) { throw new IOError('csv_columnas', 'La fila tiene un número de celdas diferente al encabezado. Revisa comillas y separadores.'); }
                    $datos = array_combine($cabecera['columnas'], array_map(static fn($v) => (string) $v, $valores));
                    $d = self::normalizar($datos); $id = (int) $d['producto_id'];
                    if (isset($vistos[$id])) { throw new IOError('csv_duplicado', 'El ID está repetido en este archivo (también aparece en la fila ' . $vistos[$id] . ').'); }
                    $vistos[$id] = $numero;
                    try { IOContenidos::obtener($id); throw new IOError('csv_existente', 'El ID ya existe. Edita ese contenido desde el catálogo; la carga masiva no lo reemplaza.'); }
                    catch (IOError $e) { if ($e->codigo !== 'producto' || $e->getCode() !== 404) { throw $e; } }
                    $avisos = [];
                    if ($d['vimeo_id'] === '') { $avisos[] = 'video pendiente'; }
                    if ((float) $d['precio_mxn'] === 0.0) { $avisos[] = 'precio pendiente'; }
                    if ($d['imagen'] === null || $d['imagen'] === '') { $avisos[] = 'imagen pendiente'; }
                    $filas[] = ['fila' => $numero, 'datos' => $d, 'avisos' => $avisos];
                } catch (IOError $e) { $errores[] = ['fila' => $numero, 'codigo' => $e->codigo, 'mensaje' => $e->getMessage()]; }
            }
        } finally { fclose($f); }
        if (!$total) { throw new IOError('csv_vacio', 'La plantilla contiene solo encabezados. Agrega tus contenidos antes de cargarla.'); }
        return ['version_lote' => 1, 'actor_id' => (int) $a['id'], 'creado_en' => time(), 'total' => $total,
            'separador' => $cabecera['separador'], 'filas' => $filas, 'errores' => $errores, 'huella' => self::huella($filas)];
    }

    /** Recibe exclusivamente la vista previa guardada en sesión, no filas enviadas por el navegador. */
    public static function confirmar(array $actor, array $lote, string $motivo): array
    {
        $a = self::autorizado($actor);
        if (($lote['version_lote'] ?? null) !== 1 || (int) ($lote['actor_id'] ?? 0) !== (int) $a['id'] ||
            (int) ($lote['creado_en'] ?? 0) < time() - self::VIGENCIA_SEGUNDOS || (int) ($lote['creado_en'] ?? 0) > time() + 60) {
            throw new IOError('csv_caducado', 'La vista previa venció o no pertenece a tu cuenta. Vuelve a cargar el CSV.', 409);
        }
        $filas = $lote['filas'] ?? [];
        if (!is_array($filas) || !$filas || count($filas) > self::MAX_FILAS || !empty($lote['errores']) || count($filas) !== ($lote['total'] ?? 0)) {
            throw new IOError('csv_invalido', 'Corrige todas las filas del archivo y genera otra vista previa.');
        }
        if (!is_string($lote['huella'] ?? null) || !hash_equals(self::huella($filas), $lote['huella'])) {
            throw new IOError('csv_modificado', 'La vista previa cambió. Vuelve a cargar el archivo para revisarlo.', 409);
        }
        $motivo = trim($motivo);
        if ($motivo === '' || mb_strlen($motivo, 'UTF-8') > 120) { throw new IOError('motivo', 'Indica un motivo de hasta 120 caracteres.'); }
        $ids = [];
        foreach ($filas as $fila) {
            $d = $fila['datos'];
            if (!is_array($d) || !empty($d['activo']) || !empty($d['version']) || !empty($d['producto_id_original'])) { throw new IOError('csv_invalido', 'La carga solo admite nuevos borradores.'); }
            $validado = IOContenidos::validarNuevo($d);
            if ($validado !== $d || isset($ids[(int) $d['producto_id']])) { throw new IOError('csv_modificado', 'Los datos no coinciden con la vista previa validada.', 409); }
            $ids[(int) $d['producto_id']] = $d;
        }
        ksort($ids, SORT_NUMERIC);
        return IO::transaccion(function () use ($a, $ids, $motivo, $lote) {
            $guardados = [];
            foreach ($ids as $d) { $guardados[] = IOContenidos::guardar($a, $d, $motivo); }
            IO::ejecutar('INSERT INTO io_bitacora (admin_id,admin_email,accion,motivo,detalle) VALUES (?,?,?,?,?)',
                [$a['id'], $a['email'], 'carga_contenidos', $motivo, json_encode(['producto_ids' => $guardados, 'huella' => $lote['huella']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            return $guardados;
        });
    }
}
