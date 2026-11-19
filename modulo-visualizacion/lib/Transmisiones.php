<?php
/**
 * Inteligencia Objetiva 2.0 — Transmisiones en vivo.
 * El equipo pega el enlace de YouTube, Facebook, Vimeo o Zoom; el sistema detecta la plataforma,
 * decide quién puede verla y solo entrega el reproductor a quien tiene acceso.
 */
declare(strict_types=1);
require_once __DIR__ . '/IO.php';

final class IOTransmisiones
{
    public const PLATAFORMAS = ['youtube' => 'YouTube', 'facebook' => 'Facebook', 'vimeo' => 'Vimeo', 'zoom' => 'Zoom'];
    public const ACCESOS = [
        'publico' => 'Pública: cualquier visitante',
        'registrados' => 'Alumnos con cuenta',
        'compradores' => 'Solo quienes compraron un contenido',
        'miembros' => 'Miembros con membresía activa',
    ];
    public const ESTADOS = ['programada' => 'Programada', 'en_vivo' => 'En vivo', 'finalizada' => 'Finalizada', 'cancelada' => 'Cancelada'];
    /** Minutos antes del inicio en que la página ya muestra el reproductor (sala de espera). */
    public const SALA_ESPERA_MIN = 15;

    /**
     * Detecta plataforma e ID a partir del enlace pegado.
     * @return array{plataforma:string, video_id:string, es_evento_vimeo:bool}
     */
    public static function analizarEnlace(string $url): array
    {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) { $url = 'https://' . ltrim($url, '/'); }
        $p = parse_url($url);
        $host = strtolower(preg_replace('/^(www\.|m\.|web\.)/', '', (string) ($p['host'] ?? '')));
        $ruta = (string) ($p['path'] ?? '');
        parse_str((string) ($p['query'] ?? ''), $q);

        if (in_array($host, ['youtube.com', 'youtu.be', 'youtube-nocookie.com', 'music.youtube.com'], true)) {
            $id = null;
            if ($host === 'youtu.be') { $id = trim($ruta, '/'); }
            elseif (!empty($q['v'])) { $id = (string) $q['v']; }
            elseif (preg_match('#^/(?:live|embed|shorts|v)/([^/?]+)#', $ruta, $m)) { $id = $m[1]; }
            if ($id && preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) { return ['plataforma' => 'youtube', 'video_id' => $id, 'es_evento_vimeo' => false]; }
            throw new IOError('enlace', 'No encontré el ID del video de YouTube. Copia el enlace del video o del directo (por ejemplo youtube.com/live/… o youtu.be/…), no el del canal.');
        }
        if (in_array($host, ['facebook.com', 'fb.watch', 'fb.com'], true)) {
            $esVideo = $host === 'fb.watch' || preg_match('#/videos/|/watch/?$|/watch/live|/live/|/reel/#', $ruta) || !empty($q['v']);
            if (!$esVideo) { throw new IOError('enlace', 'Ese enlace de Facebook no es de un video. Abre la transmisión y copia el enlace del video.'); }
            $canonica = 'https://www.facebook.com' . ($host === 'fb.watch' ? '/watch/?v=' . rawurlencode(trim($ruta, '/')) : $ruta . (!empty($q['v']) ? '?v=' . rawurlencode((string) $q['v']) : ''));
            if ($host === 'fb.watch') { $canonica = 'https://fb.watch' . $ruta; }
            return ['plataforma' => 'facebook', 'video_id' => $canonica, 'es_evento_vimeo' => false];
        }
        if (in_array($host, ['vimeo.com', 'player.vimeo.com'], true)) {
            if (preg_match('#^/event/(\d+)#', $ruta, $m)) { return ['plataforma' => 'vimeo', 'video_id' => $m[1], 'es_evento_vimeo' => true]; }
            if (preg_match('#^/(?:video/)?(\d+)#', $ruta, $m)) { return ['plataforma' => 'vimeo', 'video_id' => $m[1], 'es_evento_vimeo' => false]; }
            throw new IOError('enlace', 'No encontré el ID del video o evento de Vimeo.');
        }
        if (preg_match('/(^|\.)zoom\.us$/', $host)) {
            if (preg_match('#/(?:j|w|wc/join)/(\d{9,12})#', $ruta, $m)) { return ['plataforma' => 'zoom', 'video_id' => $url, 'es_evento_vimeo' => false]; }
            throw new IOError('enlace', 'El enlace de Zoom debe ser el de unirse a la reunión (zoom.us/j/…).');
        }
        throw new IOError('enlace', 'Pega un enlace de YouTube, Facebook, Vimeo o Zoom.');
    }

    /** URL del reproductor insertable (null para Zoom, que se abre en su app). */
    public static function urlReproductor(array $t, string $dominio): ?string
    {
        switch ($t['plataforma']) {
            case 'youtube':
                return 'https://www.youtube-nocookie.com/embed/' . rawurlencode($t['video_id']) . '?autoplay=1&rel=0&modestbranding=1&playsinline=1';
            case 'facebook':
                return 'https://www.facebook.com/plugins/video.php?href=' . rawurlencode($t['video_id']) . '&show_text=false&autoplay=true&allowfullscreen=true';
            case 'vimeo':
                return (int) $t['es_evento_vimeo']
                    ? 'https://vimeo.com/event/' . rawurlencode($t['video_id']) . '/embed'
                    : 'https://player.vimeo.com/video/' . rawurlencode($t['video_id']) . '?autoplay=1&title=0&byline=0&portrait=0&dnt=1';
        }
        return null;
    }

    /** Chat en vivo insertable (solo YouTube lo permite). */
    public static function urlChat(array $t, string $dominio): ?string
    {
        if ($t['plataforma'] !== 'youtube' || !(int) $t['mostrar_chat']) { return null; }
        $host = parse_url($dominio, PHP_URL_HOST) ?: 'inteligenciaobjetiva.com';
        return 'https://www.youtube.com/live_chat?v=' . rawurlencode($t['video_id']) . '&embed_domain=' . rawurlencode($host);
    }

    /** ¿Está al aire ahora? Manual (en_vivo) o automático dentro de su horario programado. */
    public static function alAire(array $t, ?int $ahora = null): bool
    {
        if ($t['estado'] === 'en_vivo') { return true; }
        if ($t['estado'] !== 'programada') { return false; }
        $ahora = $ahora ?? time();
        $ini = strtotime($t['inicio']);
        return $ahora >= $ini && $ahora <= $ini + 60 * (int) $t['duracion_min'];
    }

    /** ¿Ya se puede mostrar la sala de espera / reproductor? */
    public static function abierta(array $t, ?int $ahora = null): bool
    {
        if (self::alAire($t, $ahora)) { return true; }
        return $t['estado'] === 'programada' && ($ahora ?? time()) >= strtotime($t['inicio']) - 60 * self::SALA_ESPERA_MIN
            && ($ahora ?? time()) <= strtotime($t['inicio']) + 60 * (int) $t['duracion_min'];
    }

    /** Regla de acceso. Devuelve [puede, motivo_si_no]. */
    public static function puedeVer(array $t, ?array $cliente): array
    {
        switch ($t['acceso']) {
            case 'publico':
                return [true, ''];
            case 'registrados':
                return $cliente ? [true, ''] : [false, 'Inicia sesión con tu cuenta para ver esta transmisión.'];
            case 'compradores':
                if (!$cliente) { return [false, 'Esta transmisión es para quienes compraron el contenido. Inicia sesión para verla.']; }
                $ok = IO::uno('SELECT 1 FROM io_accesos WHERE cliente_id = ? AND producto_id = ? AND estado <> "revocado" LIMIT 1', [(int) $cliente['id'], (int) $t['producto_id']]);
                return $ok ? [true, ''] : [false, 'Esta transmisión es exclusiva para quienes compraron el contenido relacionado.'];
            case 'miembros':
                if (!$cliente) { return [false, 'Esta transmisión es para miembros. Inicia sesión para verla.']; }
                $ok = IO::uno('SELECT 1 FROM io_membresias WHERE cliente_id = ? AND estado = "activa" AND (pagado_hasta IS NULL OR pagado_hasta > NOW()) LIMIT 1', [(int) $cliente['id']]);
                return $ok ? [true, ''] : [false, 'Esta transmisión es exclusiva para miembros con membresía activa.'];
        }
        return [false, 'No disponible.'];
    }

    // ---------- Consultas ----------
    public static function porSlug(string $slug): ?array
    {
        return IO::uno('SELECT * FROM io_transmisiones WHERE slug = ?', [$slug]);
    }

    /** La transmisión que está al aire (la más reciente), si hay. */
    public static function ahora(): ?array
    {
        foreach (IO::todos("SELECT * FROM io_transmisiones WHERE estado = 'en_vivo' OR (estado = 'programada' AND inicio <= DATE_ADD(NOW(), INTERVAL ? MINUTE)) ORDER BY estado = 'en_vivo' DESC, inicio DESC LIMIT 10", [self::SALA_ESPERA_MIN]) as $t) {
            if (self::alAire($t)) { return $t; }
        }
        return null;
    }

    public static function proximas(int $limite = 12): array
    {
        return IO::todos("SELECT * FROM io_transmisiones WHERE estado = 'programada' AND DATE_ADD(inicio, INTERVAL duracion_min MINUTE) >= NOW() ORDER BY inicio LIMIT " . max(1, min(50, $limite)));
    }

    public static function pasadas(int $limite = 6): array
    {
        return IO::todos("SELECT t.*, p.slug AS grabacion_slug FROM io_transmisiones t LEFT JOIN io_productos p ON p.producto_id = t.grabacion_producto_id
            WHERE t.estado = 'finalizada' OR (t.estado = 'programada' AND DATE_ADD(t.inicio, INTERVAL t.duracion_min MINUTE) < NOW())
            ORDER BY t.inicio DESC LIMIT " . max(1, min(50, $limite)));
    }

    /** Archivo de calendario (.ics) para "Agregar a mi calendario". */
    public static function ics(array $t, string $dominio): string
    {
        $f = fn(string $dt) => gmdate('Ymd\THis\Z', strtotime($dt));
        $esc = fn(string $s) => str_replace(["\\", ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $s);
        $fin = date('Y-m-d H:i:s', strtotime($t['inicio']) + 60 * (int) $t['duracion_min']);
        $url = rtrim($dominio, '/') . '/en-vivo?t=' . rawurlencode($t['slug']);
        return implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Inteligencia Objetiva//Transmisiones//ES', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'BEGIN:VEVENT', 'UID:transmision-' . (int) $t['id'] . '@' . (parse_url($dominio, PHP_URL_HOST) ?: 'inteligenciaobjetiva.com'),
            'DTSTAMP:' . gmdate('Ymd\THis\Z'), 'DTSTART:' . $f($t['inicio']), 'DTEND:' . $f($fin),
            'SUMMARY:' . $esc('En vivo: ' . $t['titulo']), 'DESCRIPTION:' . $esc(trim(($t['descripcion'] ?? '') . "\n\nVer en: " . $url)),
            'URL:' . $url, 'BEGIN:VALARM', 'TRIGGER:-PT15M', 'ACTION:DISPLAY', 'DESCRIPTION:' . $esc('Empieza en 15 minutos'), 'END:VALARM',
            'END:VEVENT', 'END:VCALENDAR', '',
        ]);
    }

    // ---------- Administración ----------
    private static function slug(string $titulo, ?int $excluir = null): string
    {
        $base = strtr(mb_strtolower($titulo, 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $base = substr(trim(preg_replace('/[^a-z0-9]+/', '-', $base), '-'), 0, 120) ?: 'transmision';
        $slug = $base; $n = 2;
        while (IO::uno('SELECT id FROM io_transmisiones WHERE slug = ? AND id <> ?', [$slug, (int) $excluir])) { $slug = $base . '-' . $n++; }
        return $slug;
    }

    private static function bitacora(array $admin, string $accion, int $id, string $detalle): void
    {
        IO::ejecutar('INSERT INTO io_bitacora (admin_id, admin_email, accion, motivo, detalle) VALUES (?,?,?,?,?)',
            [$admin['id'] ?? null, $admin['email'] ?? null, $accion, 'Transmisión #' . $id, mb_substr($detalle, 0, 2000)]);
    }

    /** Crea o actualiza una transmisión. Devuelve su id. */
    public static function guardar(array $admin, array $d, ?int $id = null): int
    {
        $titulo = trim((string) ($d['titulo'] ?? ''));
        if ($titulo === '' || mb_strlen($titulo) > 200) { throw new IOError('titulo', 'Escribe un título (máximo 200 caracteres).'); }
        $enlace = self::analizarEnlace((string) ($d['url'] ?? ''));
        $inicio = strtotime((string) ($d['inicio'] ?? ''));
        if (!$inicio) { throw new IOError('inicio', 'Indica la fecha y la hora de inicio.'); }
        $dur = (int) ($d['duracion_min'] ?? 90);
        if ($dur < 10 || $dur > 600) { throw new IOError('duracion', 'La duración debe estar entre 10 y 600 minutos.'); }
        $acceso = (string) ($d['acceso'] ?? 'publico');
        if (!isset(self::ACCESOS[$acceso])) { throw new IOError('acceso', 'Elige quién puede verla.'); }
        if ($enlace['plataforma'] === 'facebook' && $acceso !== 'publico') {
            throw new IOError('acceso', 'Facebook no permite limitar una transmisión a alumnos del sitio. Para una transmisión exclusiva usa YouTube como "No listado", Vimeo o Zoom.');
        }
        $producto = null;
        if ($acceso === 'compradores') {
            $producto = (int) ($d['producto_id'] ?? 0);
            if (!$producto || !IO::uno('SELECT 1 FROM io_politicas WHERE producto_id = ?', [$producto])) { throw new IOError('producto', 'Elige el contenido cuyos compradores podrán verla.'); }
        }
        $grab = (int) ($d['grabacion_producto_id'] ?? 0) ?: null;
        if ($grab && !IO::uno('SELECT 1 FROM io_productos WHERE producto_id = ?', [$grab])) { throw new IOError('grabacion', 'La grabación debe ser un contenido del catálogo.'); }
        $imagen = trim((string) ($d['imagen'] ?? '')) ?: null;
        if ($imagen && !preg_match('#^(/[A-Za-z0-9/_.\-]+|https://[^\s"<>]+)$#', $imagen)) { throw new IOError('imagen', 'La imagen debe ser una ruta del sitio o una URL https.'); }
        $vals = [
            'titulo' => $titulo, 'descripcion' => trim((string) ($d['descripcion'] ?? '')) ?: null, 'imagen' => $imagen,
            'plataforma' => $enlace['plataforma'], 'url' => mb_substr(trim((string) $d['url']), 0, 500), 'video_id' => $enlace['video_id'],
            'es_evento_vimeo' => $enlace['es_evento_vimeo'] ? 1 : 0, 'inicio' => date('Y-m-d H:i:s', $inicio), 'duracion_min' => $dur,
            'acceso' => $acceso, 'producto_id' => $producto, 'grabacion_producto_id' => $grab, 'mostrar_chat' => !empty($d['mostrar_chat']) ? 1 : 0,
        ];
        return IO::transaccion(function () use ($admin, $vals, $id) {
            if ($id) {
                if (!IO::uno('SELECT id FROM io_transmisiones WHERE id = ? FOR UPDATE', [$id])) { throw new IOError('no_existe', 'La transmisión no existe.', 404); }
                $cols = implode(', ', array_map(fn($k) => "$k = ?", array_keys($vals)));
                IO::ejecutar("UPDATE io_transmisiones SET $cols WHERE id = ?", array_merge(array_values($vals), [$id]));
                self::bitacora($admin, 'transmision_editar', $id, $vals['titulo']);
                return $id;
            }
            $vals['slug'] = self::slug($vals['titulo']);
            IO::ejecutar('INSERT INTO io_transmisiones (' . implode(', ', array_keys($vals)) . ') VALUES (' . implode(',', array_fill(0, count($vals), '?')) . ')', array_values($vals));
            $nuevo = (int) IO::db()->lastInsertId();
            self::bitacora($admin, 'transmision_crear', $nuevo, $vals['titulo']);
            return $nuevo;
        });
    }

    /** iniciar | terminar | cancelar | reprogramar */
    public static function cambiarEstado(array $admin, int $id, string $accion): void
    {
        $mapa = ['iniciar' => 'en_vivo', 'terminar' => 'finalizada', 'cancelar' => 'cancelada', 'reprogramar' => 'programada'];
        if (!isset($mapa[$accion])) { throw new IOError('accion', 'Acción no válida.'); }
        IO::transaccion(function () use ($admin, $id, $accion, $mapa) {
            $t = IO::uno('SELECT * FROM io_transmisiones WHERE id = ? FOR UPDATE', [$id]);
            if (!$t) { throw new IOError('no_existe', 'La transmisión no existe.', 404); }
            $extra = $accion === 'iniciar' ? ', iniciada_en = NOW()' : ($accion === 'terminar' ? ', finalizada_en = NOW()' : '');
            IO::ejecutar("UPDATE io_transmisiones SET estado = ?$extra WHERE id = ?", [$mapa[$accion], $id]);
            self::bitacora($admin, 'transmision_' . $accion, $id, $t['titulo']);
        });
    }
}
