<?php
/** Pruebas de la detección del canal de YouTube (API simulada). php tests/prueba-youtube.php — MySQL de prueba en 3307; crea y borra io_prueba_yt. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/Transmisiones.php';

$pdo = new PDO(getenv('IO_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3307;charset=utf8mb4', getenv('IO_TEST_USER') ?: 'root', getenv('IO_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->exec('DROP DATABASE IF EXISTS io_prueba_yt'); $pdo->exec('CREATE DATABASE io_prueba_yt CHARACTER SET utf8mb4'); $pdo->exec('USE io_prueba_yt');
foreach (glob(__DIR__ . '/../sql/*.sql') as $f) { foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents($f))))) as $sql) { $pdo->exec($sql); } }
date_default_timezone_set('America/Mexico_City');
$pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
$config = ['zona_horaria' => 'America/Mexico_City', 'dominio' => 'https://inteligenciaobjetiva.com'];
IO::usarConexion($pdo, $config);

$ok = 0; $fallos = 0;
function verificar(string $n, bool $c): void { global $ok, $fallos; if ($c) { $ok++; echo "  ✔ $n\n"; } else { $fallos++; echo "  ✘ $n\n"; } }
function esperarError(string $n, string $codigo, callable $fn): void { try { $fn(); verificar("$n (debía fallar)", false); } catch (IOError $e) { verificar("$n → $codigo", $e->codigo === $codigo); } }
$adm = ['id' => 1, 'email' => 'admin@prueba.test'];
const CANAL = 'UCabcdefghijklmnopqrstuv';

// YouTube simulado
$llamadas = []; $videos = []; $falla = null;
IOYouTube::$transporte = function (string $url, array $cab) use (&$llamadas, &$videos, &$falla) {
    $llamadas[] = $url;
    if (!in_array('X-Goog-Api-Key: AIzaPRUEBA_llave_de_prueba_123456', $cab, true) || strpos($url, 'key=') !== false) { return [400, '{"error":{"errors":[{"reason":"keyInvalid"}]}}']; }
    if ($falla) { return $falla; }
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    if (strpos($url, '/channels?') !== false) {
        $ok = ($q['id'] ?? '') === CANAL || ($q['forHandle'] ?? '') === '@MartinAparicio';
        return [200, json_encode(['items' => $ok ? [['id' => CANAL, 'snippet' => ['title' => 'Martín Aparicio', 'customUrl' => '@martinaparicio', 'thumbnails' => ['default' => ['url' => 'https://yt3.ggpht.com/foto.jpg']]]]] : []])];
    }
    if (strpos($url, '/playlistItems?') !== false) {
        return ($q['playlistId'] ?? '') === 'UU' . substr(CANAL, 2) ? [200, json_encode(['items' => array_map(fn($v) => ['contentDetails' => ['videoId' => $v['id']]], $videos)])] : [404, '{}'];
    }
    if (strpos($url, '/videos?') !== false) { return [200, json_encode(['items' => $videos])]; }
    return [404, '{}'];
};
function video(string $id, string $tipo, string $privacidad = 'public', array $extra = []): array
{
    $l = $tipo === 'live' ? ['actualStartTime' => gmdate('c', time() - 600)] : ($tipo === 'upcoming' ? ['scheduledStartTime' => gmdate('c', time() + 86400)] : ['actualStartTime' => gmdate('c', time() - 90000), 'actualEndTime' => gmdate('c', time() - 86400)]);
    return array_replace_recursive(['id' => $id, 'snippet' => ['title' => "Video $id", 'description' => 'Desc', 'liveBroadcastContent' => $tipo,
        'thumbnails' => ['high' => ['url' => "https://i.ytimg.com/vi/$id/hqdefault.jpg"]]], 'liveStreamingDetails' => $l, 'status' => ['privacyStatus' => $privacidad, 'embeddable' => true]], $extra);
}

echo "\n1. Leer el canal\n";
foreach (['https://www.youtube.com/@MartinAparicio' => ['handle', '@MartinAparicio'], 'youtube.com/@MartinAparicio/streams' => ['handle', '@MartinAparicio'],
          'https://m.youtube.com/channel/' . CANAL => ['id', CANAL], CANAL => ['id', CANAL], '@MartinAparicio' => ['handle', '@MartinAparicio']] as $entrada => [$tipo, $valor]) {
    $r = IOYouTube::analizarCanal($entrada); verificar("$entrada → $tipo", $r === ['tipo' => $tipo, 'valor' => $valor]);
}
esperarError('enlace de otro sitio', 'canal', fn() => IOYouTube::analizarCanal('https://vimeo.com/@x'));
esperarError('enlace de un video, no de un canal', 'canal', fn() => IOYouTube::analizarCanal('https://www.youtube.com/watch?v=aqz-KE-bpKQ'));

echo "\n2. Sin llave no hace nada\n";
verificar('sin llave no consulta YouTube', IOYouTube::estado()['en_vivo'] === null && !$llamadas && IOTransmisiones::ahora() === null);
esperarError('conectar canal sin llave', 'llave', fn() => IOYouTube::guardarCanal($adm, '@MartinAparicio'));

echo "\n3. Conectar el canal\n";
IO::usarConexion($pdo, $config + ['youtube_api_key' => 'AIzaPRUEBA_llave_de_prueba_123456']);
$c = IOYouTube::guardarCanal($adm, 'https://www.youtube.com/@MartinAparicio');
verificar('resuelve @usuario al ID del canal', $c['id'] === CANAL && $c['titulo'] === 'Martín Aparicio' && IOYouTube::canal()['id'] === CANAL);
verificar('la llave va en cabecera, nunca en la URL', $llamadas && strpos(implode(' ', $llamadas), 'AIza') === false);
verificar('queda en la bitácora', (int) IO::uno("SELECT COUNT(*) n FROM io_bitacora WHERE accion = 'youtube_canal'")['n'] === 1);
esperarError('canal inexistente', 'canal', fn() => IOYouTube::guardarCanal($adm, '@NoExiste123'));

echo "\n4. Detectar directos\n";
$videos = [video('AAAAAAAAAA1', 'none')];
verificar('sin directo: no hay nada al aire', IOYouTube::estado(true)['en_vivo'] === null && IOTransmisiones::ahora() === null);
$videos = [video('AAAAAAAAAA2', 'upcoming'), video('AAAAAAAAAA1', 'none')];
$e = IOYouTube::estado(true);
verificar('directo programado se informa como próximo', $e['en_vivo'] === null && ($e['proximo']['video_id'] ?? '') === 'AAAAAAAAAA2');
$videos = [video('LIVEaaaaaa1', 'live'), video('AAAAAAAAAA1', 'none')];
$e = IOYouTube::estado(true);
verificar('detecta el directo público', ($e['en_vivo']['video_id'] ?? '') === 'LIVEaaaaaa1');
$t = IOTransmisiones::ahora();
verificar('/en-vivo lo muestra como transmisión pública con chat', $t && $t['slug'] === 'canal' && $t['plataforma'] === 'youtube' && $t['acceso'] === 'publico' && (int) $t['mostrar_chat'] === 1 && IOTransmisiones::alAire($t));
verificar('se abre con /en-vivo?t=canal', (IOTransmisiones::porSlug('canal')['video_id'] ?? '') === 'LIVEaaaaaa1');
verificar('el reproductor usa youtube-nocookie', strpos((string) IOTransmisiones::urlReproductor($t, ''), 'youtube-nocookie.com/embed/LIVEaaaaaa1') !== false);
$antes = count($llamadas); IOYouTube::estado(); IOYouTube::estado(); IOTransmisiones::ahora();
verificar('usa la caché de 60 s (no gasta cuota en cada visita)', count($llamadas) === $antes);
$videos = [video('NOLISTADO01', 'live', 'unlisted')];
verificar('ignora los directos no listados', IOYouTube::estado(true)['en_vivo'] === null);
$videos = [video('PRIVADO0001', 'live', 'public', ['status' => ['embeddable' => false]])];
verificar('ignora los que no permiten insertarse', IOYouTube::estado(true)['en_vivo'] === null);

echo "\n5. Respeta lo registrado en el backoffice\n";
$id = IOTransmisiones::guardar($adm, ['titulo' => 'Sesión para miembros', 'url' => 'https://youtu.be/LIVEaaaaaa1', 'inicio' => date('Y-m-d H:i', strtotime('+3 days')), 'duracion_min' => 60, 'acceso' => 'miembros']);
$videos = [video('LIVEaaaaaa1', 'live')];
IOYouTube::estado(true);
$t = IOTransmisiones::ahora();
verificar('si el video ya está registrado, usa ese registro y su acceso', $t && (int) $t['id'] === $id && $t['acceso'] === 'miembros' && $t['estado'] === 'en_vivo');
IOTransmisiones::cambiarEstado($adm, $id, 'cancelar');
verificar('si se canceló, vuelve a la detección pública', (IOTransmisiones::ahora()['slug'] ?? '') === 'canal');

echo "\n6. Errores de YouTube\n";
$falla = [403, '{"error":{"errors":[{"reason":"quotaExceeded"}]}}'];
$e = IOYouTube::estado(true);
verificar('cuota agotada: lo informa y conserva el último directo unos minutos', strpos((string) $e['error'], 'cuota') !== false && ($e['en_vivo']['video_id'] ?? '') === 'LIVEaaaaaa1');
$falla = [0, ''];
verificar('sin conexión: mensaje claro', strpos((string) IOYouTube::estado(true)['error'], 'No se pudo conectar') !== false);
$falla = null;
IOYouTube::quitarCanal($adm);
verificar('al desconectar deja de detectar', IOYouTube::canal() === null && IOTransmisiones::ahora() === null);

$pdo->exec('DROP DATABASE io_prueba_yt');
echo "\nResultado: $ok correctas, $fallos fallidas\n";
exit($fallos ? 1 : 0);
