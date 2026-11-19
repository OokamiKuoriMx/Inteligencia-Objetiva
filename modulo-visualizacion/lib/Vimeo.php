<?php
/**
 * Vínculo con Vimeo: lee enlaces, consulta la API con el token de la cuenta y aplica la privacidad recomendada.
 *
 * Vimeo controla dónde y cómo se puede reproducir el video (privacidad, dominio, descargas).
 * La plataforma controla quién puede verlo y cuándo (accesos, vigencias, visualización única).
 *
 * Referencia: especificación OpenAPI oficial de la API 3.4 (github.com/vimeo/openapi, api.yaml)
 *   GET   /me                                   cuenta del token
 *   GET   /oauth/verify                         token y sus permisos ("scope")
 *   GET   /videos/{id}                          name, duration, pictures, link, privacy
 *   PATCH /videos/{id}                          privacy.view / privacy.embed / privacy.download  (permiso "edit")
 *   GET   /videos/{id}/privacy/domains          dominios donde se puede insertar
 *   PUT   /videos/{id}/privacy/domains/{dom}    agrega un dominio  (permiso "edit")
 */
declare(strict_types=1);
require_once __DIR__ . '/IO.php';

final class IOVimeoCliente
{
    public const API = 'https://api.vimeo.com';
    public const OEMBED = 'https://vimeo.com/api/oembed.json';
    /** Permisos mínimos del token: leer (public, private) y aplicar la configuración recomendada (edit). */
    public const PERMISOS = ['public' => 'Ver datos públicos', 'private' => 'Ver tus videos privados', 'edit' => 'Editar la privacidad de tus videos'];
    /** Privacidad recomendada: oculto en vimeo.com, insertable solo en dominios autorizados y sin descargas de Vimeo. */
    public const RECOMENDADA = ['view' => 'disable', 'embed' => 'whitelist', 'download' => false];

    /**
     * Transporte HTTP reemplazable en pruebas (sin red).
     * fn(string $metodo, string $url, array $cabeceras, ?string $cuerpo): array{0:int,1:string}  — código 0 = sin conexión.
     * @var callable|null
     */
    public static $transporte = null;
    private static ?array $cuentaCache = null;

    public static function reiniciar(): void { self::$cuentaCache = null; }

    /* ───────────── Enlaces ───────────── */

    /**
     * Acepta cualquier forma de enlace de Vimeo (o el código de inserción) y devuelve el ID y el hash de no listado.
     * vimeo.com/123 · vimeo.com/123/abc · player.vimeo.com/video/123?h=abc · vimeo.com/manage/videos/123 · 123
     * @return array{id:string,hash:?string}|null
     */
    public static function analizarEnlace(string $entrada): ?array
    {
        $t = trim($entrada);
        if ($t === '' || strlen($t) > 2000) { return null; }
        if (preg_match('/^\d{1,32}$/D', $t)) { return trim($t, '0') === '' ? null : ['id' => $t, 'hash' => null]; }
        if (preg_match('/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $t, $m)) { $t = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
        if (strpos($t, '//') === 0) { $t = 'https:' . $t; }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $t)) { $t = 'https://' . $t; }
        $p = parse_url($t);
        if (!$p || empty($p['host']) || !in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true)) { return null; }
        $host = strtolower(preg_replace('/^www\./i', '', $p['host']));
        if ($host !== 'vimeo.com' && $host !== 'player.vimeo.com') { return null; }
        $seg = array_values(array_filter(explode('/', $p['path'] ?? ''), static fn($s) => $s !== ''));
        parse_str($p['query'] ?? '', $q);
        $id = null; $pos = -1;
        if ($host === 'player.vimeo.com') {
            if (($seg[0] ?? '') === 'video' && preg_match('/^\d{1,32}$/D', $seg[1] ?? '')) { $id = $seg[1]; $pos = 1; }
        } else {
            // Prioriza el número que sigue a "video"/"videos" (manage, showcase, groups); si no, el primer segmento numérico.
            foreach ($seg as $i => $s) {
                if (in_array(strtolower($s), ['video', 'videos'], true) && preg_match('/^\d{1,32}$/D', $seg[$i + 1] ?? '')) { $id = $seg[$i + 1]; $pos = $i + 1; break; }
            }
            if ($id === null) {
                foreach ($seg as $i => $s) { if (preg_match('/^\d{1,32}$/D', $s)) { $id = $s; $pos = $i; break; } }
            }
        }
        if ($id === null || trim($id, '0') === '') { return null; }
        $hash = null;
        if (isset($q['h']) && is_string($q['h']) && preg_match('/^[A-Za-z0-9]{1,32}$/D', $q['h'])) { $hash = $q['h']; }
        elseif (isset($seg[$pos + 1]) && preg_match('/^[0-9a-f]{6,32}$/Di', $seg[$pos + 1])) { $hash = $seg[$pos + 1]; }
        return ['id' => $id, 'hash' => $hash];
    }

    /* ───────────── Configuración ───────────── */

    public static function token(): string
    {
        $t = trim((string) (IO::config()['vimeo_token'] ?? ''));
        return ($t === '' || substr($t, -3) === '...' || stripos($t, 'TU_TOKEN') !== false) ? '' : $t;
    }

    public static function configurado(): bool { return self::token() !== ''; }

    /** Dominio de producción para la lista de Vimeo; null si el configurado es local (demo). */
    public static function dominio(): ?string
    {
        $h = self::dominioConfigurado();
        if ($h === null || self::esLocal($h)) { return null; }
        return $h;
    }

    public static function dominioConfigurado(): ?string
    {
        $d = trim((string) (IO::config()['dominio'] ?? ''));
        if ($d === '') { return null; }
        $h = parse_url(preg_match('#^[a-z]+://#i', $d) ? $d : 'https://' . $d, PHP_URL_HOST);
        return $h ? strtolower(preg_replace('/^www\./i', '', $h)) : null;
    }

    public static function esLocal(string $host): bool
    {
        // Una IP o un nombre de desarrollo no sirven como dominio autorizado en Vimeo.
        return $host === 'localhost' || (bool) preg_match('/\.(localhost|test|local)$/D', $host)
            || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false;
    }

    /* ───────────── HTTP ───────────── */

    /** @return array{0:int,1:string} */
    private static function http(string $metodo, string $url, array $cabeceras, ?string $cuerpo = null): array
    {
        if (self::$transporte) { return (self::$transporte)($metodo, $url, $cabeceras, $cuerpo); }
        if (!function_exists('curl_init')) { return [0, '']; }
        $ch = curl_init($url);
        $op = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_HTTPHEADER => $cabeceras, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS];
        if ($cuerpo !== null) { $op[CURLOPT_POSTFIELDS] = $cuerpo; }
        // En Windows sin paquete de certificados en php.ini, usa el almacén del sistema (la verificación TLS sigue activa).
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA') && !ini_get('curl.cainfo') && !ini_get('openssl.cafile')) {
            $op[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        }
        curl_setopt_array($ch, $op);
        $r = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$r === false ? 0 : $code, $r === false ? '' : (string) $r];
    }

    /** @return array{0:int,1:?array} */
    private static function api(string $metodo, string $ruta, ?array $json = null): array
    {
        $cab = ['Authorization: bearer ' . self::token(), 'Accept: application/vnd.vimeo.*+json;version=3.4'];
        $cuerpo = null;
        if ($json !== null) { $cab[] = 'Content-Type: application/json'; $cuerpo = json_encode($json, JSON_UNESCAPED_SLASHES); }
        [$code, $r] = self::http($metodo, self::API . $ruta, $cab, $cuerpo);
        $j = $r !== '' ? json_decode($r, true) : null;
        return [$code, is_array($j) ? $j : null];
    }

    /** Convierte una respuesta fallida de Vimeo en un mensaje claro para el equipo. */
    public static function error(int $code, string $contexto = 'video'): IOError
    {
        switch (true) {
            case $code === 0:
                return new IOError('vimeo_red', 'No se pudo conectar con Vimeo. Revisa la conexión del servidor e inténtalo de nuevo.', 502);
            case $code === 401:
                return new IOError('vimeo_token', 'Vimeo rechazó el token de la cuenta: puede estar mal copiado o haber sido revocado. Revisa la pantalla “Vincular Vimeo”.', 502);
            case $code === 403:
                return $contexto === 'editar'
                    ? new IOError('vimeo_permiso', 'Vimeo no permitió cambiar este video. Revisa que pertenezca a la cuenta vinculada y que el plan permita ocultar videos de Vimeo (el plan gratuito no lo permite).', 502)
                    : new IOError('vimeo_permiso', 'La cuenta vinculada no tiene permiso para ver este video en Vimeo.', 502);
            case $code === 404:
                return new IOError('vimeo_no_existe', 'Vimeo no encontró ese video. Revisa el enlace; si es privado, debe pertenecer a la cuenta vinculada.', 404);
            case $code === 429:
                return new IOError('vimeo_limite', 'Vimeo pidió esperar unos minutos antes de más consultas.', 502);
            default:
                return new IOError('vimeo', 'Vimeo no respondió como se esperaba (código ' . $code . '). Inténtalo de nuevo en unos minutos.', 502);
        }
    }

    /* ───────────── Cuenta ───────────── */

    /** Estado del vínculo con Vimeo para la pantalla del backoffice. Nunca lanza: los errores van en 'error'. */
    public static function cuenta(bool $refrescar = false): array
    {
        if (self::$cuentaCache !== null && !$refrescar) { return self::$cuentaCache; }
        $c = ['configurado' => self::configurado(), 'vinculada' => false, 'nombre' => null, 'enlace' => null, 'plan' => null,
            'permisos' => [], 'faltan' => array_keys(self::PERMISOS), 'puede_editar' => false, 'error' => null,
            'dominio' => self::dominio(), 'dominio_configurado' => self::dominioConfigurado()];
        if (!$c['configurado']) { return self::$cuentaCache = $c; }
        [$code, $me] = self::api('GET', '/me?fields=name,link,account');
        if ($code !== 200 || !$me) { $c['error'] = self::error($code, 'cuenta')->getMessage(); return self::$cuentaCache = $c; }
        $c['vinculada'] = true;
        $c['nombre'] = (string) ($me['name'] ?? '');
        $c['enlace'] = isset($me['link']) && is_string($me['link']) && strpos($me['link'], 'https://vimeo.com/') === 0 ? $me['link'] : null;
        $c['plan'] = isset($me['account']) && is_string($me['account']) ? $me['account'] : null;
        [$code, $v] = self::api('GET', '/oauth/verify');
        if ($code === 200 && $v && isset($v['scope']) && is_string($v['scope'])) {
            $c['permisos'] = array_values(array_filter(preg_split('/[\s,]+/', $v['scope']) ?: []));
        } else {
            $c['error'] = 'No se pudieron leer los permisos del token.';
        }
        $c['faltan'] = array_values(array_diff(array_keys(self::PERMISOS), $c['permisos']));
        $c['puede_editar'] = in_array('edit', $c['permisos'], true);
        return self::$cuentaCache = $c;
    }

    /* ───────────── Videos ───────────── */

    /**
     * Datos del video para el editor. Con token usa la API (incluye privacidad); sin token usa la consulta pública de Vimeo (oEmbed).
     * @return array{id:string,hash:?string,titulo:string,duracion_seg:int,miniatura:?string,fuente:string,privacidad:?array}
     */
    public static function consultar(string $id, ?string $hash = null): array
    {
        if (!preg_match('/^\d{1,32}$/D', $id)) { throw new IOError('enlace', 'No se reconoció un video de Vimeo en ese enlace.'); }
        if ($hash !== null && !preg_match('/^[A-Za-z0-9]{1,32}$/D', $hash)) { $hash = null; }
        return self::configurado() ? self::consultarApi($id, $hash) : self::consultarPublico($id, $hash);
    }

    private static function consultarApi(string $id, ?string $hash): array
    {
        $campos = '?fields=name,duration,link,player_embed_url,pictures.sizes,pictures.base_link,privacy';
        [$code, $v] = self::api('GET', '/videos/' . $id . $campos);
        if ($code === 404 && $hash) { [$code, $v] = self::api('GET', '/videos/' . $id . ':' . $hash . $campos); }
        if ($code !== 200 || !$v) { throw self::error($code); }
        $hash = $hash ?: self::hashDe((string) ($v['player_embed_url'] ?? '')) ?: self::hashDe((string) ($v['link'] ?? ''));
        $privacidad = is_array($v['privacy'] ?? null) ? $v['privacy'] : [];
        $dominios = null;
        if (($privacidad['embed'] ?? null) === 'whitelist') { $dominios = self::dominios($id); }
        return ['id' => $id, 'hash' => $hash, 'titulo' => (string) ($v['name'] ?? ''), 'duracion_seg' => max(0, (int) ($v['duration'] ?? 0)),
            'miniatura' => self::miniaturaApi($v['pictures'] ?? null), 'fuente' => 'api',
            'privacidad' => self::estado($privacidad, $dominios)];
    }

    /** Sin token: la consulta pública de Vimeo solo da título, duración y miniatura; no revela la privacidad. */
    private static function consultarPublico(string $id, ?string $hash): array
    {
        $url = 'https://vimeo.com/' . $id . ($hash ? '/' . $hash : '');
        $referer = (string) (IO::config()['dominio'] ?? '');
        $cab = ['Accept: application/json'];
        if (preg_match('#^https?://#i', $referer)) { $cab[] = 'Referer: ' . rtrim($referer, '/') . '/'; }
        [$code, $r] = self::http('GET', self::OEMBED . '?url=' . rawurlencode($url) . '&width=640', $cab);
        $v = $r !== '' ? json_decode($r, true) : null;
        if ($code === 404 || $code === 403) {
            throw new IOError('vimeo_no_existe', 'Vimeo no muestra ese video sin vincular la cuenta: puede ser privado o el enlace estar incompleto. Vincula la cuenta de Vimeo o pega el enlace completo.', 404);
        }
        if ($code !== 200 || !is_array($v)) { throw self::error($code); }
        $mini = isset($v['thumbnail_url']) && is_string($v['thumbnail_url']) ? $v['thumbnail_url'] : null;
        if ($mini) { $mini = preg_replace('/_\d+x\d+(?=$|[?.])/', '_640x360', $mini); }
        return ['id' => $id, 'hash' => $hash ?: self::hashDe((string) ($v['html'] ?? '')), 'titulo' => (string) ($v['title'] ?? ''),
            'duracion_seg' => max(0, (int) ($v['duration'] ?? 0)), 'miniatura' => self::https($mini), 'fuente' => 'publica', 'privacidad' => null];
    }

    /** @return string[]|null */
    public static function dominios(string $id): ?array
    {
        [$code, $d] = self::api('GET', '/videos/' . $id . '/privacy/domains?per_page=100');
        if ($code === 204) { return []; }
        if ($code !== 200 || !$d) { return null; }
        $lista = [];
        foreach ((array) ($d['data'] ?? []) as $fila) {
            if (is_array($fila) && isset($fila['domain']) && is_string($fila['domain'])) { $lista[] = strtolower(preg_replace('/^www\./i', '', $fila['domain'])); }
        }
        return $lista;
    }

    private static function hashDe(string $texto): ?string
    {
        if (preg_match('/[?&](?:amp;)?h=([A-Za-z0-9]{1,32})/', $texto, $m)) { return $m[1]; }
        if (preg_match('#vimeo\.com/\d+/([0-9a-f]{6,32})(?:[/?\#]|$)#i', $texto, $m)) { return $m[1]; }
        return null;
    }

    private static function miniaturaApi($pictures): ?string
    {
        if (!is_array($pictures)) { return null; }
        $mejor = null;
        foreach ((array) ($pictures['sizes'] ?? []) as $s) {
            if (!is_array($s) || empty($s['link'])) { continue; }
            $w = (int) ($s['width'] ?? 0);
            if ($mejor === null || ($w >= 640 && ($mejor[0] < 640 || $w < $mejor[0])) || ($mejor[0] < 640 && $w > $mejor[0])) { $mejor = [$w, (string) $s['link']]; }
        }
        return self::https($mejor[1] ?? (isset($pictures['base_link']) ? (string) $pictures['base_link'] : null));
    }

    private static function https(?string $url): ?string
    {
        if ($url === null || strlen($url) > 255 || !filter_var($url, FILTER_VALIDATE_URL) || stripos($url, 'https://') !== 0 || preg_match('/[\s<>"\'\\\\]/', $url)) { return null; }
        return $url;
    }

    /* ───────────── Privacidad ───────────── */

    /**
     * Semáforo de privacidad para el editor. Cada indicador: nivel ok | aviso | falla y un texto sin jerga.
     * @param string[]|null $dominios null = no se pudo leer la lista
     */
    public static function estado(array $privacidad, ?array $dominios): array
    {
        $vista = (string) ($privacidad['view'] ?? '');
        $insercion = (string) ($privacidad['embed'] ?? '');
        $descarga = $privacidad['download'] ?? null;
        $dominio = self::dominio(); $configurado = self::dominioConfigurado();

        $oculto = [
            'disable' => ['ok', 'Oculto en Vimeo: solo se puede ver dentro del sitio.'],
            'unlisted' => ['aviso', 'No listado: no aparece en búsquedas, pero quien tenga el enlace puede verlo en vimeo.com.'],
            'anybody' => ['falla', 'Público: cualquiera puede verlo gratis en vimeo.com.'],
            'nobody' => ['falla', 'Solo para ti en Vimeo: puede no reproducirse en el sitio.'],
            'password' => ['falla', 'Protegido con contraseña: el alumno tendría que escribirla.'],
            'contacts' => ['falla', 'Solo para contactos de Vimeo.'],
            'users' => ['falla', 'Solo para personas con cuenta en Vimeo.'],
        ][$vista] ?? ['aviso', 'Vimeo no informó la privacidad de este video.'];

        if ($insercion === 'whitelist') {
            if ($dominios === null) { $sitio = ['aviso', 'Solo en sitios autorizados, pero no se pudo leer la lista.']; }
            elseif ($dominio === null) { $sitio = ['aviso', 'Solo en sitios autorizados (' . (implode(', ', $dominios) ?: 'ninguno') . '). El dominio configurado es de prueba (' . ($configurado ?? 'sin dominio') . ').']; }
            elseif (self::dominioIncluido($dominio, $dominios)) { $sitio = ['ok', 'Solo se puede insertar en ' . $dominio . (count($dominios) > 1 ? ' y ' . (count($dominios) - 1) . ' sitio(s) más' : '') . '.']; }
            else { $sitio = ['falla', 'Falta autorizar ' . $dominio . ': el video no se reproducirá en el sitio.']; }
        } elseif ($insercion === 'public') {
            $sitio = ['falla', 'Se puede insertar en cualquier sitio de internet.'];
        } elseif ($insercion === 'private') {
            $sitio = ['falla', 'No se puede insertar en ningún sitio: no se reproducirá en la plataforma.'];
        } else {
            $sitio = ['aviso', 'Vimeo no informó dónde se puede insertar.'];
        }

        $descargas = $descarga === false ? ['ok', 'Descargas de Vimeo desactivadas.']
            : ($descarga === true ? ['falla', 'Vimeo ofrece el botón de descarga del archivo.'] : ['aviso', 'Vimeo no informó si permite descargas.']);

        $ind = ['oculto' => $oculto, 'dominio' => $sitio, 'descargas' => $descargas];
        $salida = ['listo' => true, 'indicadores' => [], 'actual' => ['view' => $vista ?: null, 'embed' => $insercion ?: null, 'download' => is_bool($descarga) ? $descarga : null, 'dominios' => $dominios]];
        foreach ($ind as $k => [$nivel, $texto]) {
            $salida['indicadores'][$k] = ['nivel' => $nivel, 'texto' => $texto];
            if ($nivel !== 'ok') { $salida['listo'] = false; }
        }
        return $salida;
    }

    /** Vimeo incluye los subdominios de un dominio autorizado. */
    public static function dominioIncluido(string $dominio, array $lista): bool
    {
        foreach ($lista as $d) {
            if ($dominio === $d || substr($dominio, -strlen('.' . $d)) === '.' . $d) { return true; }
        }
        return false;
    }

    /**
     * Aplica la privacidad recomendada al video y autoriza el dominio del sitio. Requiere permiso "edit" en el token
     * y rol con permiso "politicas". Registra el cambio en io_bitacora.
     */
    public static function aplicarRecomendada(array $admin, string $id, ?int $productoId, string $motivo): array
    {
        $a = IO::uno('SELECT id,email,rol FROM io_admins WHERE id = ? AND activo = 1', [(int) ($admin['id'] ?? 0)]);
        if (!$a || !in_array($a['rol'], ['admin', 'editor'], true)) { throw new IOError('sin_permiso', 'Sin permiso para cambiar la privacidad en Vimeo.', 403); }
        $motivo = trim($motivo);
        if ($motivo === '') { throw new IOError('motivo', 'Escribe el motivo del cambio.'); }
        if (mb_strlen($motivo, 'UTF-8') > 120) { throw new IOError('motivo', 'El motivo admite máximo 120 caracteres.'); }
        if (!preg_match('/^\d{1,32}$/D', $id) || trim($id, '0') === '') { throw new IOError('enlace', 'Primero conecta un video de Vimeo.'); }
        if (!self::configurado()) { throw new IOError('vimeo_config', 'Primero vincula la cuenta de Vimeo (pantalla “Vincular Vimeo”).', 503); }
        $cuenta = self::cuenta(true);
        if (!$cuenta['vinculada']) { throw new IOError('vimeo_token', $cuenta['error'] ?: 'Vimeo rechazó el token de la cuenta.', 502); }
        if (!$cuenta['puede_editar']) {
            throw new IOError('vimeo_sin_edicion', 'El token de Vimeo solo puede leer. Para aplicar la configuración desde aquí, genera un token nuevo con el permiso “Edit” (Editar) y reemplázalo en config.php.', 403);
        }
        $dominio = self::dominio();
        if ($dominio === null) {
            throw new IOError('vimeo_dominio', 'El dominio configurado (' . (self::dominioConfigurado() ?? 'vacío') . ') es de prueba. En el servidor del sitio, “dominio” en config.php debe ser el dominio real.', 409);
        }
        $antes = self::consultarApi($id, null);
        [$code] = self::api('PATCH', '/videos/' . $id, ['privacy' => self::RECOMENDADA]);
        if ($code < 200 || $code > 299) { throw self::error($code, 'editar'); }
        [$codeDom] = self::api('PUT', '/videos/' . $id . '/privacy/domains/' . rawurlencode($dominio));
        $domOk = $codeDom >= 200 && $codeDom <= 299;
        $despues = self::consultarApi($id, null);
        IO::ejecutar('INSERT INTO io_bitacora (admin_id,admin_email,accion,producto_id,motivo,detalle) VALUES (?,?,?,?,?,?)', [
            $a['id'], $a['email'], 'vimeo_privacidad', $productoId && $productoId > 0 ? $productoId : null, $motivo,
            json_encode(['vimeo_id' => $id, 'dominio' => $dominio, 'dominio_agregado' => $domOk,
                'antes' => $antes['privacidad']['actual'] ?? null, 'despues' => $despues['privacidad']['actual'] ?? null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        if (!$domOk) {
            throw new IOError('vimeo_dominio_alta', 'La privacidad se actualizó, pero Vimeo no aceptó el dominio ' . $dominio . '. Agrégalo a mano en Vimeo: video › Compartir › Insertar › Dominios permitidos.', 502);
        }
        return $despues;
    }
}
