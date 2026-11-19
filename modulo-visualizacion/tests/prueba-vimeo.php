<?php
/**
 * Vínculo con Vimeo sin red: formatos de enlace, estado de privacidad y errores con respuestas simuladas de la API.
 *   php tests/prueba-vimeo.php   (usa el MySQL de prueba en 3307 solo para la bitácora; crea y borra su base)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/Vimeo.php';

$ok = 0; $fallos = 0;
function v(string $nombre, bool $cond): void { global $ok, $fallos; if ($cond) { $ok++; echo "  ✔ $nombre\n"; } else { $fallos++; echo "  ✘ $nombre\n"; } }
function vError(string $nombre, string $codigo, callable $fn): void
{
    try { $fn(); v("$nombre (debía fallar con $codigo)", false); }
    catch (IOError $e) { v("$nombre → $codigo", $e->codigo === $codigo); if ($e->codigo !== $codigo) { echo "      obtuvo {$e->codigo}: {$e->getMessage()}\n"; } }
}

/** Vimeo simulado: rutas → [código, cuerpo]. Registra cada llamada. */
$llamadas = [];
function simular(array $rutas): void
{
    global $llamadas; $llamadas = [];
    IOVimeoCliente::reiniciar();
    IOVimeoCliente::$transporte = static function (string $metodo, string $url, array $cab, ?string $cuerpo) use ($rutas) {
        global $llamadas;
        $ruta = preg_replace('#^https://api\.vimeo\.com#', '', $url);
        $ruta = preg_replace('/\?.*$/', '', $ruta);
        $llamadas[] = [$metodo, $ruta, $cuerpo, $cab];
        foreach ($rutas as $patron => $respuesta) {
            [$m, $r] = explode(' ', $patron, 2);
            if ($m === $metodo && ($r === $ruta || ($r === 'oembed' && strpos($url, IOVimeoCliente::OEMBED) === 0))) {
                $res = is_callable($respuesta) ? $respuesta($cuerpo) : $respuesta;
                return [$res[0], is_array($res[1]) ? json_encode($res[1]) : (string) $res[1]];
            }
        }
        return [404, json_encode(['error' => 'no simulado'])];
    };
}

$pdo = new PDO(getenv('IO_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3307;charset=utf8mb4', getenv('IO_TEST_USER') ?: 'root', getenv('IO_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$bd = 'io_prueba_vimeo_' . bin2hex(random_bytes(4));
$pdo->exec("CREATE DATABASE `$bd` CHARACTER SET utf8mb4"); $pdo->exec("USE `$bd`");
$config = ['zona_horaria' => 'America/Mexico_City', 'vimeo_token' => '', 'dominio' => 'https://www.inteligenciaobjetiva.com'];
IO::usarConexion($pdo, $config);

try {
    foreach (glob(__DIR__ . '/../sql/*.sql') as $archivo) {
        foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents($archivo))))) as $sql) { $pdo->exec($sql); }
    }
    foreach ([1 => 'admin', 2 => 'editor', 3 => 'soporte'] as $id => $rol) {
        IO::ejecutar('INSERT INTO io_admins (id,email,nombre,hash,rol) VALUES (?,?,?,?,?)', [$id, $rol . '@ejemplo.test', $rol, 'hash-ficticio', $rol]);
    }
    $admin = ['id' => 1, 'email' => 'admin@ejemplo.test'];

    echo "Enlaces\n";
    $casos = [
        'https://vimeo.com/76979871' => ['76979871', null],
        'vimeo.com/76979871' => ['76979871', null],
        'www.vimeo.com/76979871?share=copy' => ['76979871', null],
        'https://vimeo.com/123456789/a1b2c3d4e5' => ['123456789', 'a1b2c3d4e5'],
        'https://player.vimeo.com/video/123456789?h=a1b2c3d4e5&badge=0' => ['123456789', 'a1b2c3d4e5'],
        '//player.vimeo.com/video/123456789' => ['123456789', null],
        'https://vimeo.com/manage/videos/123456789' => ['123456789', null],
        'https://vimeo.com/manage/videos/123456789/a1b2c3d4e5' => ['123456789', 'a1b2c3d4e5'],
        'https://vimeo.com/manage/videos/123456789/settings' => ['123456789', null],
        'https://vimeo.com/channels/staffpicks/123456789' => ['123456789', null],
        'https://vimeo.com/showcase/11111/video/123456789' => ['123456789', null],
        '<iframe src="https://player.vimeo.com/video/123456789?h=a1b2c3d4e5&amp;app_id=1" frameborder="0"></iframe>' => ['123456789', 'a1b2c3d4e5'],
        '  123456789  ' => ['123456789', null],
    ];
    foreach ($casos as $entrada => [$id, $hash]) {
        $r = IOVimeoCliente::analizarEnlace($entrada);
        v('reconoce ' . trim(substr($entrada, 0, 70)), $r !== null && $r['id'] === $id && $r['hash'] === $hash);
    }
    foreach (['', '0000', 'https://youtube.com/watch?v=123', 'https://vimeo.com/usuario', 'https://evil.com/vimeo.com/123', 'javascript:alert(1)', 'ftp://vimeo.com/123', 'https://vimeo.com.evil.com/123'] as $malo) {
        v('rechaza «' . $malo . '»', IOVimeoCliente::analizarEnlace($malo) === null);
    }

    echo "Configuración\n";
    v('sin token no está configurado', !IOVimeoCliente::configurado());
    v('el dominio se normaliza sin www', IOVimeoCliente::dominio() === 'inteligenciaobjetiva.com');
    foreach (['http://127.0.0.1:8090', 'http://localhost', 'https://io.test'] as $local) {
        IO::usarConexion($pdo, ['dominio' => $local] + $config);
        v('un dominio de prueba (' . $local . ') no se envía a Vimeo', IOVimeoCliente::dominio() === null);
    }
    IO::usarConexion($pdo, ['vimeo_token' => 'abc...'] + $config);
    v('un token de ejemplo con "..." cuenta como ausente', !IOVimeoCliente::configurado());

    echo "Sin token (consulta pública)\n";
    IO::usarConexion($pdo, $config);
    simular(['GET oembed' => [200, ['title' => 'Conferencia', 'duration' => 3723, 'thumbnail_url' => 'https://i.vimeocdn.com/video/1-d_295x166', 'html' => '<iframe src="https://player.vimeo.com/video/5?h=ab12cd34ef"></iframe>']]]);
    $r = IOVimeoCliente::consultar('5');
    v('llena título y duración', $r['titulo'] === 'Conferencia' && $r['duracion_seg'] === 3723 && $r['fuente'] === 'publica');
    v('pide la miniatura a 640 px', $r['miniatura'] === 'https://i.vimeocdn.com/video/1-d_640x360');
    v('detecta el hash en el código de inserción', $r['hash'] === 'ab12cd34ef');
    v('sin token no informa privacidad', $r['privacidad'] === null);
    v('sin token no llama a la API privada', count(array_filter($llamadas, static fn($l) => strpos($l[1], '/videos') === 0)) === 0);
    v('envía el dominio del sitio como referer', in_array('Referer: https://www.inteligenciaobjetiva.com/', $llamadas[0][3], true));
    simular(['GET oembed' => [404, '']]);
    vError('video privado o inexistente sin token', 'vimeo_no_existe', fn() => IOVimeoCliente::consultar('5'));
    simular(['GET oembed' => [0, '']]);
    vError('sin conexión', 'vimeo_red', fn() => IOVimeoCliente::consultar('5'));
    vError('ID no numérico', 'enlace', fn() => IOVimeoCliente::consultar('abc'));
    $c = IOVimeoCliente::cuenta(true);
    v('la cuenta sin token aparece como no configurada', !$c['configurado'] && !$c['vinculada'] && $c['faltan'] === ['public', 'private', 'edit']);
    vError('aplicar sin token', 'vimeo_config', fn() => IOVimeoCliente::aplicarRecomendada($admin, '5', 10, 'Prueba'));

    echo "Con token\n";
    IO::usarConexion($pdo, ['vimeo_token' => 'token-de-prueba'] + $config);
    $me = [200, ['name' => 'Inteligencia Objetiva', 'link' => 'https://vimeo.com/io', 'account' => 'plus']];
    $video = static fn(array $privacidad) => [200, ['name' => 'Curso', 'duration' => 600, 'link' => 'https://vimeo.com/5/ab12cd34ef', 'privacy' => $privacidad,
        'pictures' => ['base_link' => 'https://i.vimeocdn.com/video/1', 'sizes' => [['width' => 295, 'link' => 'https://i.vimeocdn.com/video/1-d_295'], ['width' => 640, 'link' => 'https://i.vimeocdn.com/video/1-d_640'], ['width' => 1920, 'link' => 'https://i.vimeocdn.com/video/1-d_1920']]]]];
    $recomendada = ['view' => 'disable', 'embed' => 'whitelist', 'download' => false];
    simular(['GET /me' => $me, 'GET /oauth/verify' => [200, ['scope' => 'public private edit']],
        'GET /videos/5' => $video($recomendada), 'GET /videos/5/privacy/domains' => [200, ['data' => [['domain' => 'inteligenciaobjetiva.com']]]]]);
    $c = IOVimeoCliente::cuenta(true);
    v('lee la cuenta vinculada', $c['vinculada'] && $c['nombre'] === 'Inteligencia Objetiva' && $c['plan'] === 'plus');
    v('lee los permisos del token', $c['permisos'] === ['public', 'private', 'edit'] && $c['faltan'] === [] && $c['puede_editar']);
    v('envía el token como bearer', in_array('Authorization: bearer token-de-prueba', $llamadas[0][3], true));
    $r = IOVimeoCliente::consultar('5');
    v('usa la API y toma el hash del enlace', $r['fuente'] === 'api' && $r['hash'] === 'ab12cd34ef' && $r['duracion_seg'] === 600);
    v('elige la miniatura de 640 px', $r['miniatura'] === 'https://i.vimeocdn.com/video/1-d_640');
    v('privacidad recomendada → todo en verde', $r['privacidad']['listo'] === true
        && array_column($r['privacidad']['indicadores'], 'nivel') === ['ok', 'ok', 'ok']);

    simular(['GET /videos/5' => $video(['view' => 'anybody', 'embed' => 'public', 'download' => true])]);
    $p = IOVimeoCliente::consultar('5')['privacidad'];
    v('video público, insertable en cualquier sitio y descargable → tres alertas', !$p['listo'] && array_column($p['indicadores'], 'nivel') === ['falla', 'falla', 'falla']);
    v('si la inserción no es por lista, no pide dominios', count($llamadas) === 1);

    simular(['GET /videos/5' => $video(['view' => 'unlisted', 'embed' => 'whitelist', 'download' => false]), 'GET /videos/5/privacy/domains' => [200, ['data' => [['domain' => 'otrositio.com']]]]]);
    $p = IOVimeoCliente::consultar('5')['privacidad'];
    v('no listado → aviso', $p['indicadores']['oculto']['nivel'] === 'aviso');
    v('dominio del sitio ausente de la lista → falla', $p['indicadores']['dominio']['nivel'] === 'falla' && strpos($p['indicadores']['dominio']['texto'], 'inteligenciaobjetiva.com') !== false);
    v('descargas desactivadas → correcto', $p['indicadores']['descargas']['nivel'] === 'ok');
    v('Vimeo incluye subdominios', IOVimeoCliente::dominioIncluido('cursos.inteligenciaobjetiva.com', ['inteligenciaobjetiva.com']) && !IOVimeoCliente::dominioIncluido('inteligenciaobjetiva.com.mx', ['inteligenciaobjetiva.com']));

    simular(['GET /videos/5' => $video(['view' => 'disable', 'embed' => 'private', 'download' => false])]);
    v('inserción privada → no se reproduciría en el sitio', IOVimeoCliente::consultar('5')['privacidad']['indicadores']['dominio']['nivel'] === 'falla');

    echo "Errores de la API\n";
    simular(['GET /videos/5' => [404, ['error' => 'not found']]]);
    vError('video inexistente', 'vimeo_no_existe', fn() => IOVimeoCliente::consultar('5'));
    simular(['GET /videos/5' => [404, []], 'GET /videos/5:ab12cd34ef' => $video($recomendada)]);
    v('video no listado ajeno: reintenta con id:hash', IOVimeoCliente::consultar('5', 'ab12cd34ef')['titulo'] === 'Curso' && $llamadas[1][1] === '/videos/5:ab12cd34ef');
    simular(['GET /videos/5' => [401, ['error' => 'invalid token']]]);
    vError('token revocado', 'vimeo_token', fn() => IOVimeoCliente::consultar('5'));
    simular(['GET /videos/5' => [403, []]]);
    vError('sin permiso para ver el video', 'vimeo_permiso', fn() => IOVimeoCliente::consultar('5'));
    simular(['GET /videos/5' => [429, []]]);
    vError('límite de consultas', 'vimeo_limite', fn() => IOVimeoCliente::consultar('5'));
    simular(['GET /me' => [401, []]]);
    $c = IOVimeoCliente::cuenta(true);
    v('token rechazado en la pantalla de cuenta: configurado pero no vinculado, con mensaje', $c['configurado'] && !$c['vinculada'] && strpos((string) $c['error'], 'token') !== false);

    echo "Aplicar configuración recomendada\n";
    simular(['GET /me' => $me, 'GET /oauth/verify' => [200, ['scope' => 'public private']]]);
    vError('token sin permiso Edit', 'vimeo_sin_edicion', fn() => IOVimeoCliente::aplicarRecomendada($admin, '5', 10, 'Prueba'));
    v('sin permiso Edit no intenta cambiar nada', count(array_filter($llamadas, static fn($l) => $l[0] !== 'GET')) === 0);
    vError('rol soporte no puede aplicar', 'sin_permiso', fn() => IOVimeoCliente::aplicarRecomendada(['id' => 3], '5', 10, 'Prueba'));
    vError('exige motivo', 'motivo', fn() => IOVimeoCliente::aplicarRecomendada($admin, '5', 10, '  '));
    IO::usarConexion($pdo, ['vimeo_token' => 'token-de-prueba', 'dominio' => 'http://127.0.0.1:8090'] + $config);
    simular(['GET /me' => $me, 'GET /oauth/verify' => [200, ['scope' => 'public private edit']]]);
    vError('con dominio de prueba no aplica', 'vimeo_dominio', fn() => IOVimeoCliente::aplicarRecomendada($admin, '5', 10, 'Prueba'));
    IO::usarConexion($pdo, ['vimeo_token' => 'token-de-prueba'] + $config);

    $estadoVideo = ['view' => 'anybody', 'embed' => 'public', 'download' => true]; $dominios = [];
    $rutasEditables = static function () use (&$estadoVideo, &$dominios, $me, $video) {
        return ['GET /me' => $me, 'GET /oauth/verify' => [200, ['scope' => 'public private edit']],
            'GET /videos/5' => static function () use (&$estadoVideo, $video) { return $video($estadoVideo); },
            'GET /videos/5/privacy/domains' => static function () use (&$dominios) { return [200, ['data' => array_map(static fn($d) => ['domain' => $d], $dominios)]]; },
            'PATCH /videos/5' => static function ($cuerpo) use (&$estadoVideo) { $estadoVideo = array_replace($estadoVideo, json_decode((string) $cuerpo, true)['privacy']); return [200, []]; },
            'PUT /videos/5/privacy/domains/inteligenciaobjetiva.com' => static function () use (&$dominios) { $dominios[] = 'inteligenciaobjetiva.com'; return [204, '']; }];
    };
    simular($rutasEditables());
    $r = IOVimeoCliente::aplicarRecomendada(['id' => 2], '5', 10, 'Video nuevo del curso');
    $patch = array_values(array_filter($llamadas, static fn($l) => $l[0] === 'PATCH'))[0] ?? null;
    v('envía PATCH con oculto, solo dominios autorizados y sin descargas', $patch && json_decode($patch[2], true) === ['privacy' => ['view' => 'disable', 'embed' => 'whitelist', 'download' => false]]);
    $orden = array_map(static fn($l) => $l[0], array_values(array_filter($llamadas, static fn($l) => $l[0] !== 'GET')));
    v('primero cambia la privacidad y luego agrega el dominio', $orden === ['PATCH', 'PUT']);
    v('al final todo queda en verde', $r['privacidad']['listo'] === true);
    $b = IO::uno('SELECT * FROM io_bitacora WHERE accion = "vimeo_privacidad" ORDER BY id DESC LIMIT 1');
    $d = json_decode((string) ($b['detalle'] ?? ''), true);
    v('queda en la bitácora con quién, producto y motivo', $b && (int) $b['admin_id'] === 2 && (int) $b['producto_id'] === 10 && $b['motivo'] === 'Video nuevo del curso');
    v('la bitácora guarda antes y después', ($d['antes']['view'] ?? '') === 'anybody' && ($d['despues']['view'] ?? '') === 'disable' && $d['dominio_agregado'] === true);
    v('la bitácora no guarda el token', strpos((string) $b['detalle'], 'token-de-prueba') === false);

    $estadoVideo = ['view' => 'anybody', 'embed' => 'public', 'download' => true]; $dominios = [];
    simular(array_replace($rutasEditables(), ['PATCH /videos/5' => [403, ['error' => 'plan']]]));
    $antes = (int) IO::uno('SELECT COUNT(*) n FROM io_bitacora')['n'];
    vError('Vimeo rechaza el cambio (plan gratuito o video ajeno)', 'vimeo_permiso', fn() => IOVimeoCliente::aplicarRecomendada($admin, '5', 10, 'Prueba'));
    v('si Vimeo no cambió nada, no se registra en la bitácora', (int) IO::uno('SELECT COUNT(*) n FROM io_bitacora')['n'] === $antes);

    $estadoVideo = ['view' => 'anybody', 'embed' => 'public', 'download' => true]; $dominios = [];
    simular(array_replace($rutasEditables(), ['PUT /videos/5/privacy/domains/inteligenciaobjetiva.com' => [400, []]]));
    vError('privacidad cambiada pero dominio rechazado', 'vimeo_dominio_alta', fn() => IOVimeoCliente::aplicarRecomendada($admin, '5', null, 'Prueba'));
    $b = IO::uno('SELECT * FROM io_bitacora WHERE accion = "vimeo_privacidad" ORDER BY id DESC LIMIT 1');
    v('el cambio parcial sí queda en la bitácora', $b && json_decode((string) $b['detalle'], true)['dominio_agregado'] === false && $b['producto_id'] === null);

    echo "Compatibilidad\n";
    simular(['GET /videos/5' => $video($recomendada), 'GET /videos/5/privacy/domains' => [200, ['data' => []]]]);
    $info = IOVimeo::info('5');
    v('IOVimeo::info delega y conserva su formato', $info === ['titulo' => 'Curso', 'duracion_seg' => 600, 'miniatura' => 'https://i.vimeocdn.com/video/1-d_640']);
    simular(['GET /videos/5' => [500, '']]);
    v('IOVimeo::info devuelve null si Vimeo falla', IOVimeo::info('5') === null);
} finally {
    IOVimeoCliente::$transporte = null;
    $pdo->exec("DROP DATABASE IF EXISTS `$bd`");
}
echo "\n$ok correctas, $fallos fallidas\n";
exit($fallos ? 1 : 0);
