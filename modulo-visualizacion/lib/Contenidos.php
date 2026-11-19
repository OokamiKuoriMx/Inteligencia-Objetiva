<?php
/** Edición de contenidos y publicación comercial, con control de concurrencia y bitácora atómica. */
declare(strict_types=1);
require_once __DIR__ . '/Tienda.php';
require_once __DIR__ . '/Miniaturas.php';

final class IOContenidos
{
    private const POLITICA = ['producto_id', 'titulo', 'vimeo_id', 'vimeo_hash', 'duracion_seg', 'tipo', 'horas_ventana', 'cuenta_desde',
        'dias_para_empezar', 'umbral_pct', 'retroceso_seg', 'permitir_adelantar', 'pausa_max_min', 'reanudaciones_max', 'gracia_min'];
    private const COMERCIAL = ['producto_id', 'slug', 'titulo', 'categoria', 'tema', 'resumen', 'descripcion', 'aprenderas', 'imagen',
        'precio_mxn', 'precio_anterior', 'incluye', 'trailer_vimeo', 'destacado', 'activo'];

    public static function nuevo(): array
    {
        return ['producto_id' => '', 'producto_id_original' => '', 'version' => '', 'titulo' => '', 'titulo_comercial' => '',
            'vimeo_id' => '', 'vimeo_hash' => '', 'duracion_seg' => 0, 'tipo' => 'ventana', 'horas_ventana' => 48,
            'cuenta_desde' => 'primera_reproduccion', 'dias_para_empezar' => 30, 'umbral_pct' => 95, 'retroceso_seg' => 30,
            'permitir_adelantar' => 0, 'pausa_max_min' => 60, 'reanudaciones_max' => 2, 'gracia_min' => 120,
            'categoria' => 'conferencias', 'tema' => '', 'resumen' => '', 'descripcion' => '', 'aprenderas' => '', 'imagen' => '',
            'precio_mxn' => '0.00', 'precio_anterior' => '', 'incluye' => '', 'trailer_vimeo' => '', 'activo' => 0, 'destacado' => 0,
            'slug' => '', 'existe_politica' => false, 'existe_producto' => false, 'snapshot' => ['politica' => null, 'producto' => null]];
    }

    private static function autorizado(array $admin): array
    {
        return IOEquipo::autorizar($admin, 'politicas', 'Sin permiso para editar contenidos.');
    }

    private static function filas(int $id, bool $bloquear = false): array
    {
        $sufijo = $bloquear ? ' FOR UPDATE' : '';
        return [IO::uno('SELECT * FROM io_politicas WHERE producto_id = ?' . $sufijo, [$id]),
            IO::uno('SELECT * FROM io_productos WHERE producto_id = ?' . $sufijo, [$id])];
    }

    private static function version(?array $politica, ?array $producto): string
    {
        // Contadores de ventas/reseñas y timestamps no se editan aquí ni invalidan un formulario abierto.
        $p = $politica ? array_intersect_key($politica, array_flip(self::POLITICA)) : null;
        $c = $producto ? array_intersect_key($producto, array_flip(self::COMERCIAL)) : null;
        if ($p) { ksort($p); } if ($c) { ksort($c); }
        return hash('sha256', json_encode([$p, $c], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    public static function obtener(int $id): array
    {
        if ($id < 1 || $id > 4294967295) { throw new IOError('producto', 'Contenido no encontrado.', 404); }
        [$p, $c] = IO::transaccion(static fn() => self::filas($id));
        if (!$p && !$c) { throw new IOError('producto', 'Contenido no encontrado.', 404); }
        $datos = array_replace(self::nuevo(), $c ?: [], $p ?: []);
        $datos['producto_id_original'] = $id;
        $datos['titulo_comercial'] = $c['titulo'] ?? '';
        $datos['version'] = self::version($p, $c);
        $datos['existe_politica'] = (bool) $p; $datos['existe_producto'] = (bool) $c;
        $datos['snapshot'] = ['politica' => $p, 'producto' => $c];
        return $datos;
    }

    /** Validación pura para la vista previa de nuevos contenidos; no consulta ni escribe la base. */
    public static function validarNuevo(array $datos): array
    {
        $datos['producto_id_original'] = ''; $datos['version'] = '';
        [$politica, $comercial] = self::preparar($datos, null, null);
        return array_replace(self::nuevo(), $politica, $comercial, ['producto_id_original' => '', 'version' => '']);
    }

    private static function cadena($valor, string $campo): string
    {
        if (!is_scalar($valor) && $valor !== null) { throw new IOError('datos', 'El campo ' . $campo . ' no es válido.'); }
        return trim((string) $valor);
    }

    private static function texto($valor, string $campo, int $maximo, bool $multilinea = false): string
    {
        $texto = self::cadena($valor, $campo);
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        if (!mb_check_encoding($texto, 'UTF-8') || mb_strlen($texto, 'UTF-8') > $maximo || strlen($texto) > 60000 ||
            preg_match($multilinea ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/', $texto) || preg_match('/<\/?[a-z][^>]*>/i', $texto)) {
            throw new IOError('datos', 'Revisa ' . $campo . ': usa texto plano dentro del límite indicado.');
        }
        return $texto;
    }

    private static function entero($valor, string $campo, int $minimo, int $maximo): int
    {
        $s = self::cadena($valor, $campo);
        if (!preg_match('/^\d+$/D', $s) || strlen($s) > 10 || (float) $s < $minimo || (float) $s > $maximo) {
            throw new IOError('datos', 'Revisa ' . $campo . ': debe estar entre ' . $minimo . ' y ' . $maximo . '.');
        }
        return (int) $s;
    }

    private static function dinero($valor, string $campo, bool $nullable = false): ?string
    {
        $s = self::cadena($valor, $campo);
        if ($nullable && $s === '') { return null; }
        if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $s)) { throw new IOError('precio', 'Revisa ' . $campo . ': usa un importe MXN con hasta dos decimales.'); }
        $partes = explode('.', $s); $centavos = (int) $partes[0] * 100 + (int) str_pad($partes[1] ?? '', 2, '0');
        return intdiv($centavos, 100) . '.' . str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function video($valor, string $campo, bool $opcional): ?string
    {
        $v = self::cadena($valor, $campo);
        if ($v === '' && $opcional) { return null; }
        if (!preg_match('/^\d{1,32}$/D', $v) || trim($v, '0') === '') { throw new IOError('video', 'Escribe únicamente el ID numérico válido de ' . $campo . '.'); }
        return $v;
    }

    private static function imagen($valor): ?string
    {
        $v = self::cadena($valor, 'imagen');
        if ($v === '') { return null; }
        $decodificada = rawurldecode($v);
        if (mb_strlen($v, 'UTF-8') > 255 || preg_match('/[\x00-\x20\x7F<>"\'\\\\]/', $v) ||
            preg_match('/[\x00-\x20\x7F\\\\]/', $decodificada) || preg_match('#(?:^|/)\.\.?(?:/|$)#', $decodificada)) {
            throw new IOError('imagen', 'Usa una ruta local absoluta o una URL HTTPS válida para la imagen.');
        }
        if ($v[0] === '/' && substr($decodificada, 0, 2) !== '//') {
            if (!IOMiniaturas::rutaLocalValida($v)) { throw new IOError('imagen', 'La ruta local debe apuntar a una imagen (.webp, .jpg, .png, .gif o .avif).'); }
            return $v;
        }
        $p = parse_url($v);
        if (!filter_var($v, FILTER_VALIDATE_URL) || strtolower((string) ($p['scheme'] ?? '')) !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
            throw new IOError('imagen', 'La imagen debe usar una ruta local absoluta o HTTPS, sin credenciales.');
        }
        return $v;
    }

    private static function booleano(array $datos, string $campo): int
    {
        if (!array_key_exists($campo, $datos)) { return 0; }
        $valor = self::cadena($datos[$campo], $campo);
        if (!in_array($valor, ['0', '1', 'on', ''], true)) { throw new IOError('datos', 'El campo ' . $campo . ' no es válido.'); }
        return in_array($valor, ['1', 'on'], true) ? 1 : 0;
    }

    private static function preparar(array $d, ?array $p, ?array $c): array
    {
        $base = array_replace(self::nuevo(), $c ?: [], $p ?: [], $d);
        $titulo = self::texto($base['titulo'], 'título', 255);
        if ($titulo === '') { throw new IOError('titulo', 'El título es obligatorio.'); }
        $activo = self::booleano($d, 'activo');
        $tipo = self::cadena($base['tipo'], 'tipo');
        $desde = self::cadena($base['cuenta_desde'], 'cuenta_desde');
        if (!in_array($tipo, ['permanente', 'ventana', 'unica'], true) || !in_array($desde, ['compra', 'primera_reproduccion'], true)) { throw new IOError('politica', 'Revisa el tipo de acceso y cuándo comienza.'); }
        $hash = self::cadena($base['vimeo_hash'], 'hash de Vimeo');
        if ($hash !== '' && !preg_match('/^[A-Za-z0-9]{1,32}$/D', $hash)) { throw new IOError('video', 'El hash de Vimeo debe contener hasta 32 letras o números.'); }
        $video = self::video($base['vimeo_id'], 'Vimeo', !$activo);
        $politica = ['producto_id' => self::entero($base['producto_id'], 'ID del producto', 1, 4294967295), 'titulo' => $titulo,
            'vimeo_id' => $video ?? '', 'vimeo_hash' => $hash ?: null,
            'duracion_seg' => self::entero($base['duracion_seg'], 'duración', 0, 4294967295), 'tipo' => $tipo,
            'horas_ventana' => self::entero($base['horas_ventana'], 'horas de acceso', 1, 876000), 'cuenta_desde' => $desde,
            'dias_para_empezar' => self::entero($base['dias_para_empezar'], 'días para empezar', 0, 36500),
            'umbral_pct' => self::entero($base['umbral_pct'], 'porcentaje para completar', 50, 100),
            'retroceso_seg' => self::entero($base['retroceso_seg'], 'retroceso', 0, 999999),
            'permitir_adelantar' => self::booleano($d, 'permitir_adelantar'),
            'pausa_max_min' => self::entero($base['pausa_max_min'], 'pausa máxima', 1, 525600),
            'reanudaciones_max' => self::entero($base['reanudaciones_max'], 'reanudaciones', 0, 5),
            'gracia_min' => self::entero($base['gracia_min'], 'minutos de gracia', 5, 525600)];
        if ($tipo === 'unica' && $politica['duracion_seg'] < 1) { throw new IOError('duracion', 'La visualización única necesita una duración mayor que cero.'); }
        $categoria = self::cadena($base['categoria'], 'categoría'); $tema = self::cadena($base['tema'], 'tema');
        if (!array_key_exists($categoria, IOTienda::CATEGORIAS) || ($tema !== '' && !array_key_exists($tema, IOTienda::TEMAS))) { throw new IOError('categoria', 'Selecciona una categoría y un tema válidos.'); }
        $precio = self::dinero($base['precio_mxn'], 'precio de venta');
        $anterior = self::dinero($base['precio_anterior'], 'precio anterior', true);
        if ($activo && (float) $precio <= 0) { throw new IOError('precio', 'Define un precio mayor que cero antes de publicar.'); }
        $aprenderas = self::texto($base['aprenderas'], 'lo que vas a comprender', 30000, true);
        $aprenderas = implode("\n", array_filter(array_map('trim', explode("\n", $aprenderas)), static fn($v) => $v !== ''));
        $comercial = ['producto_id' => $politica['producto_id'], 'titulo' => $titulo, 'categoria' => $categoria, 'tema' => $tema ?: null,
            'resumen' => self::texto($base['resumen'], 'resumen', 400, true), 'descripcion' => self::texto($base['descripcion'], 'descripción', 30000, true),
            'aprenderas' => $aprenderas, 'imagen' => self::imagen($base['imagen']), 'precio_mxn' => $precio, 'precio_anterior' => $anterior,
            'incluye' => self::texto($base['incluye'], 'material incluido', 255), 'trailer_vimeo' => self::video($base['trailer_vimeo'], 'la muestra de Vimeo', true),
            'destacado' => self::booleano($d, 'destacado'), 'activo' => $activo];
        $plano = strtr(mb_strtolower($titulo, 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $plano), '-') ?: 'contenido';
        $comercial['slug'] = $c['slug'] ?? (rtrim(substr($slug, 0, 140), '-') . '-' . $politica['producto_id']);
        return [$politica, $comercial];
    }

    public static function guardar(array $admin, array $datos, string $motivo): int
    {
        $a = self::autorizado($admin); $motivo = self::texto($motivo, 'motivo', 120);
        if ($motivo === '') { throw new IOError('motivo', 'Escribe un motivo para registrar el cambio.'); }
        $id = self::entero($datos['producto_id'] ?? '', 'ID del producto', 1, 4294967295);
        $original = self::cadena($datos['producto_id_original'] ?? '', 'ID original');
        if ($original !== '' && $original !== '0' && self::entero($original, 'ID original', 1, 4294967295) !== $id) { throw new IOError('producto_fijo', 'El ID del producto no puede cambiar al editar.', 409); }
        $version = self::cadena($datos['version'] ?? '', 'versión');
        return IO::transaccion(function () use ($a, $datos, $motivo, $id, $version, $original) {
            [$p, $c] = self::filas($id, true);
            if ($p || $c) {
                if ($original === '' || $original === '0' || !preg_match('/^[a-f0-9]{64}$/D', $version) || !hash_equals(self::version($p, $c), $version)) {
                    throw new IOError('conflicto', 'El contenido cambió o el ID ya existe. Recarga su versión antes de guardar.', 409);
                }
            } elseif ($version !== '' || ($original !== '' && $original !== '0')) { throw new IOError('conflicto', 'El contenido original ya no existe. Recarga el catálogo.', 409); }
            [$politica, $comercial] = self::preparar($datos, $p, $c);
            IOAdmin::guardarPolitica($a, $politica, $motivo);
            $campos = array_keys($comercial); $valores = array_values($comercial);
            if ($c) {
                $actualizar = array_values(array_diff($campos, ['producto_id', 'slug']));
                IO::ejecutar('UPDATE io_productos SET ' . implode(', ', array_map(static fn($v) => $v . ' = ?', $actualizar)) . ' WHERE producto_id = ?',
                    array_merge(array_map(static fn($v) => $comercial[$v], $actualizar), [$id]));
            } else {
                IO::ejecutar('INSERT INTO io_productos (' . implode(',', $campos) . ') VALUES (' . implode(',', array_fill(0, count($campos), '?')) . ')', $valores);
            }
            $cambios = [];
            foreach ($comercial as $campo => $valor) {
                if (($c[$campo] ?? null) === $valor) { continue; }
                $resumir = static function ($v) { return is_string($v) && mb_strlen($v) > 500 ? ['caracteres' => mb_strlen($v), 'sha256' => hash('sha256', $v)] : $v; };
                $cambios[$campo] = ['antes' => $resumir($c[$campo] ?? null), 'despues' => $resumir($valor)];
            }
            IO::ejecutar('INSERT INTO io_bitacora (admin_id,admin_email,accion,producto_id,motivo,detalle) VALUES (?,?,?,?,?,?)',
                [$a['id'], $a['email'], 'contenido_metadatos', $id, $motivo, json_encode(['nuevo' => !$p && !$c, 'cambios' => $cambios], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            return $id;
        });
    }

    public static function consultarVideo(array $admin, string $id): array
    {
        self::autorizado($admin); $id = self::video($id, 'Vimeo', false);
        $token = (string) (IO::config()['vimeo_token'] ?? '');
        if ($token === '' || substr($token, -3) === '...') { throw new IOError('vimeo_config', 'La consulta de Vimeo no está configurada. Puedes escribir la duración manualmente.', 503); }
        $video = IOVimeo::info($id);
        if (!$video) { throw new IOError('vimeo', 'No se pudo consultar Vimeo. Puedes escribir la duración manualmente.', 502); }
        return ['duracion_seg' => (int) $video['duracion_seg'], 'titulo' => (string) $video['titulo'], 'miniatura' => $video['miniatura']];
    }
}
