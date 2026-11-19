<?php
/**
 * Canal de YouTube: detecta si el canal está transmitiendo en vivo para mostrarlo solo en /en-vivo.
 * Usa la YouTube Data API v3 con una llave de API (solo lectura de datos públicos), con caché de 60 s.
 * Costo de cuota por revisión: 2 unidades (lista de subidas + detalles); la cuota diaria gratuita es de 10 000.
 */
declare(strict_types=1);
require_once __DIR__ . '/IO.php';

final class IOYouTube
{
    private const API = 'https://www.googleapis.com/youtube/v3/';
    public const CACHE_SEG = 60;
    /** @var null|callable(string $url, array $cabeceras): array{0:int,1:string} Solo pruebas. */
    public static $transporte = null;

    public static function llave(): string
    {
        $k = trim((string) (IO::config()['youtube_api_key'] ?? ''));
        return preg_match('/^[A-Za-z0-9_\-]{20,80}$/D', $k) ? $k : '';
    }
    public static function configurado(): bool { return self::llave() !== ''; }

    /* ───────────── Ajustes ───────────── */

    private static function ajuste(string $clave): ?array
    {
        try { $f = IO::uno('SELECT valor FROM io_ajustes WHERE clave = ?', [$clave]); }
        catch (PDOException $e) { return null; }
        $v = $f ? json_decode((string) $f['valor'], true) : null;
        return is_array($v) ? $v : null;
    }
    private static function fijarAjuste(string $clave, ?array $valor): void
    {
        if ($valor === null) { IO::ejecutar('DELETE FROM io_ajustes WHERE clave = ?', [$clave]); return; }
        $json = json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        IO::ejecutar('INSERT INTO io_ajustes (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = ?', [$clave, $json, $json]);
    }

    /** @return null|array{id:string,titulo:string,handle:?string,miniatura:?string} */
    public static function canal(): ?array
    {
        $c = self::ajuste('youtube_canal');
        return $c && isset($c['id']) && self::esIdCanal((string) $c['id']) ? $c : null;
    }

    public static function esIdCanal(string $id): bool { return (bool) preg_match('/^UC[A-Za-z0-9_\-]{22}$/D', $id); }

    /**
     * Lee lo que el equipo pega: ID (UC…), @usuario o un enlace del canal.
     * @return array{tipo:'id'|'handle',valor:string}
     */
    public static function analizarCanal(string $entrada): array
    {
        $t = trim($entrada);
        if ($t === '' || strlen($t) > 300) { throw new IOError('canal', 'Pega el enlace del canal, su @usuario o su ID.'); }
        if (self::esIdCanal($t)) { return ['tipo' => 'id', 'valor' => $t]; }
        if (preg_match('/^@[A-Za-z0-9._\-]{3,30}$/D', $t)) { return ['tipo' => 'handle', 'valor' => $t]; }
        if (!preg_match('#^[a-z][a-z0-9+.\-]*://#i', $t)) { $t = 'https://' . $t; }
        $u = parse_url($t);
        $host = strtolower(preg_replace('/^(www|m)\./', '', (string) ($u['host'] ?? '')));
        if ($host !== 'youtube.com') { throw new IOError('canal', 'No es un enlace de YouTube. Copia la dirección de la página del canal.'); }
        $seg = array_values(array_filter(explode('/', (string) ($u['path'] ?? ''))));
        if (($seg[0] ?? '') === 'channel' && self::esIdCanal($seg[1] ?? '')) { return ['tipo' => 'id', 'valor' => $seg[1]]; }
        if (isset($seg[0]) && preg_match('/^@[A-Za-z0-9._\-]{3,30}$/D', rawurldecode($seg[0]))) { return ['tipo' => 'handle', 'valor' => rawurldecode($seg[0])]; }
        throw new IOError('canal', 'Ese enlace no identifica un canal. Usa la dirección que empieza con youtube.com/@ o youtube.com/channel/.');
    }

    public static function guardarCanal(array $admin, string $entrada): array
    {
        if (!self::configurado()) { throw new IOError('llave', 'Primero agrega la llave de la API de YouTube en config.php (pasos abajo).'); }
        $a = self::analizarCanal($entrada);
        [$code, $j] = self::api('channels', ['part' => 'snippet', $a['tipo'] === 'id' ? 'id' : 'forHandle' => $a['valor']]);
        if ($code !== 200) { throw self::error($code, $j); }
        $item = $j['items'][0] ?? null;
        if (!$item || !self::esIdCanal((string) ($item['id'] ?? ''))) { throw new IOError('canal', 'YouTube no encontró ese canal. Revisa el enlace.'); }
        $s = $item['snippet'] ?? [];
        $canal = ['id' => $item['id'], 'titulo' => mb_substr((string) ($s['title'] ?? 'Canal de YouTube'), 0, 120),
            'handle' => isset($s['customUrl']) ? mb_substr((string) $s['customUrl'], 0, 60) : null,
            'miniatura' => self::https($s['thumbnails']['default']['url'] ?? null)];
        IO::transaccion(function () use ($admin, $canal) {
            $antes = self::canal();
            self::fijarAjuste('youtube_canal', $canal);
            self::fijarAjuste('youtube_estado', null);
            IO::ejecutar('INSERT INTO io_bitacora (admin_id, admin_email, accion, motivo, detalle) VALUES (?,?,?,?,?)',
                [$admin['id'], $admin['email'], 'youtube_canal', 'Canal de YouTube para detectar directos',
                 json_encode(['antes' => $antes['id'] ?? null, 'despues' => $canal['id'], 'titulo' => $canal['titulo']], JSON_UNESCAPED_UNICODE)]);
        });
        return $canal;
    }

    public static function quitarCanal(array $admin): void
    {
        IO::transaccion(function () use ($admin) {
            $antes = self::canal();
            self::fijarAjuste('youtube_canal', null); self::fijarAjuste('youtube_estado', null);
            IO::ejecutar('INSERT INTO io_bitacora (admin_id, admin_email, accion, motivo, detalle) VALUES (?,?,?,?,?)',
                [$admin['id'], $admin['email'], 'youtube_canal', 'Se desconectó el canal de YouTube', json_encode(['antes' => $antes['id'] ?? null])]);
        });
    }

    /* ───────────── Detección ───────────── */

    /**
     * Estado del canal (con caché). en_vivo: directo público en curso; proximo: directo programado más cercano.
     * @return array{en_vivo:?array,proximo:?array,revisado:?int,error:?string}
     */
    public static function estado(bool $refrescar = false): array
    {
        $vacio = ['en_vivo' => null, 'proximo' => null, 'revisado' => null, 'error' => null];
        $canal = self::canal();
        if (!$canal || !self::configurado()) { return $vacio; }
        $cache = self::ajuste('youtube_estado');
        if (!$refrescar && $cache && ($cache['canal'] ?? '') === $canal['id'] && time() - (int) ($cache['revisado'] ?? 0) < self::CACHE_SEG) { return array_replace($vacio, $cache); }
        // Una sola revisión a la vez: los demás visitantes usan la caché anterior mientras tanto.
        $lock = 'io_youtube_' . substr($canal['id'], 2, 12);
        try { $tengo = (int) (IO::uno('SELECT GET_LOCK(?, 0) l', [$lock])['l'] ?? 0) === 1; } catch (PDOException $e) { $tengo = true; }
        if (!$tengo) { return $cache ? array_replace($vacio, $cache) : $vacio; }
        try {
            $nuevo = array_replace($vacio, self::revisar($canal['id']), ['canal' => $canal['id'], 'revisado' => time()]);
            // Si YouTube falla, se conserva el último resultado bueno un rato para no apagar un directo por un error pasajero.
            if ($nuevo['error'] && $cache && empty($cache['error']) && time() - (int) ($cache['revisado'] ?? 0) < 300) {
                $nuevo = array_replace($cache, ['error' => $nuevo['error']]);
            }
            self::fijarAjuste('youtube_estado', $nuevo);
            return $nuevo;
        } finally {
            try { IO::uno('SELECT RELEASE_LOCK(?)', [$lock]); } catch (PDOException $e) { /* sin bloqueo */ }
        }
    }

    /** @return array{en_vivo:?array,proximo:?array,error:?string} */
    private static function revisar(string $canalId): array
    {
        // La lista de "subidas" del canal (UU…) incluye los directos en curso y programados: 1 unidad de cuota.
        [$code, $j] = self::api('playlistItems', ['part' => 'contentDetails', 'playlistId' => 'UU' . substr($canalId, 2), 'maxResults' => '10']);
        if ($code !== 200) { return ['en_vivo' => null, 'proximo' => null, 'error' => self::error($code, $j)->getMessage()]; }
        $ids = [];
        foreach ($j['items'] ?? [] as $it) { $v = (string) ($it['contentDetails']['videoId'] ?? ''); if (preg_match('/^[A-Za-z0-9_\-]{11}$/D', $v)) { $ids[] = $v; } }
        if (!$ids) { return ['en_vivo' => null, 'proximo' => null, 'error' => null]; }
        [$code, $j] = self::api('videos', ['part' => 'snippet,liveStreamingDetails,status', 'id' => implode(',', $ids)]);
        if ($code !== 200) { return ['en_vivo' => null, 'proximo' => null, 'error' => self::error($code, $j)->getMessage()]; }
        $vivo = null; $proximo = null;
        foreach ($j['items'] ?? [] as $v) {
            $s = $v['snippet'] ?? []; $l = $v['liveStreamingDetails'] ?? []; $tipo = (string) ($s['liveBroadcastContent'] ?? 'none');
            // Solo públicos: los "no listados" se publican desde Transmisiones con su propio acceso.
            if (($v['status']['privacyStatus'] ?? '') !== 'public' || ($v['status']['embeddable'] ?? true) === false) { continue; }
            $dato = ['video_id' => (string) $v['id'], 'titulo' => mb_substr((string) ($s['title'] ?? 'Transmisión en vivo'), 0, 200),
                'descripcion' => mb_substr((string) ($s['description'] ?? ''), 0, 600),
                'miniatura' => self::https($s['thumbnails']['maxres']['url'] ?? $s['thumbnails']['high']['url'] ?? null),
                'inicio' => isset($l['actualStartTime']) ? strtotime((string) $l['actualStartTime']) : (isset($l['scheduledStartTime']) ? strtotime((string) $l['scheduledStartTime']) : null)];
            if ($tipo === 'live' && empty($l['actualEndTime'])) { if (!$vivo || ($dato['inicio'] ?? 0) > ($vivo['inicio'] ?? 0)) { $vivo = $dato; } }
            elseif ($tipo === 'upcoming' && $dato['inicio'] && $dato['inicio'] > time() - 3600) { if (!$proximo || $dato['inicio'] < $proximo['inicio']) { $proximo = $dato; } }
        }
        return ['en_vivo' => $vivo, 'proximo' => $proximo, 'error' => null];
    }

    /**
     * Transmisión "virtual" para /en-vivo cuando el canal está al aire y no hay una registrada en el backoffice.
     * Tiene la misma forma que una fila de io_transmisiones (acceso público, chat de YouTube).
     */
    public static function transmisionAlAire(): ?array
    {
        try { $e = self::estado(); } catch (Throwable $ex) { error_log('[IO youtube] ' . $ex->getMessage()); return null; }
        $v = $e['en_vivo'] ?? null;
        if (!$v) { return null; }
        return ['id' => 0, 'slug' => 'canal', 'titulo' => $v['titulo'], 'descripcion' => $v['descripcion'] ?: null, 'imagen' => $v['miniatura'],
            'plataforma' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=' . $v['video_id'], 'video_id' => $v['video_id'], 'es_evento_vimeo' => 0,
            'inicio' => date('Y-m-d H:i:s', (int) ($v['inicio'] ?: time())), 'duracion_min' => 600, 'estado' => 'en_vivo', 'acceso' => 'publico',
            'producto_id' => null, 'grabacion_producto_id' => null, 'mostrar_chat' => 1, 'automatica' => true];
    }

    /* ───────────── HTTP ───────────── */

    /** @return array{0:int,1:?array} */
    private static function api(string $recurso, array $params): array
    {
        // La llave va en cabecera (no en la URL) para que no quede en registros de acceso ni de errores.
        $url = self::API . $recurso . '?' . http_build_query($params);
        $cab = ['X-Goog-Api-Key: ' . self::llave(), 'Accept: application/json'];
        [$code, $r] = self::$transporte ? (self::$transporte)($url, $cab) : self::http($url, $cab);
        $j = $r !== '' ? json_decode($r, true) : null;
        return [$code, is_array($j) ? $j : null];
    }

    /** @return array{0:int,1:string} */
    private static function http(string $url, array $cab): array
    {
        if (!function_exists('curl_init')) { return [0, '']; }
        $ch = curl_init($url);
        $op = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_HTTPHEADER => $cab,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS];
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA') && !ini_get('curl.cainfo') && !ini_get('openssl.cafile')) { $op[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA; }
        curl_setopt_array($ch, $op);
        $r = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return [$r === false ? 0 : $code, $r === false ? '' : (string) $r];
    }

    public static function error(int $code, ?array $j = null): IOError
    {
        $razon = (string) ($j['error']['errors'][0]['reason'] ?? $j['error']['status'] ?? '');
        if ($code === 0) { return new IOError('youtube', 'No se pudo conectar con YouTube. Se volverá a intentar en un minuto.', 502); }
        if (in_array($razon, ['quotaExceeded', 'dailyLimitExceeded', 'rateLimitExceeded'], true)) { return new IOError('cuota', 'Se agotó la cuota diaria de la API de YouTube. Se reanuda a medianoche, hora del Pacífico.', 503); }
        if (($code === 400 && in_array($razon, ['keyInvalid', 'badRequest', 'INVALID_ARGUMENT'], true)) || $razon === 'API_KEY_INVALID') { return new IOError('llave', 'YouTube no aceptó la llave de la API. Revisa que esté completa en config.php.', 502); }
        if ($code === 403) { return new IOError('llave', 'La llave no tiene permiso para la YouTube Data API v3, o está restringida a otra IP o sitio. Revisa los pasos 2 y 4.', 502); }
        if ($code === 404) { return new IOError('canal', 'YouTube no encontró el canal o su lista de videos.', 404); }
        return new IOError('youtube', 'YouTube respondió con un error (' . $code . '). Se volverá a intentar en un minuto.', 502);
    }

    private static function https($url): ?string
    {
        $u = is_string($url) ? trim($url) : '';
        return preg_match('#^https://[A-Za-z0-9.\-]+\.(?:ytimg|ggpht|googleusercontent)\.com/[^\s"\'<>()\\\\]+$#D', $u) && strlen($u) <= 500 ? $u : null;
    }
}
