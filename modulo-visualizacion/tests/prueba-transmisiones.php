<?php
/** Pruebas de transmisiones en vivo. php tests/prueba-transmisiones.php (MySQL de prueba, puerto 3307; crea y borra io_prueba_tx). */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/Transmisiones.php';

$pdo = new PDO(getenv('IO_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3307;charset=utf8mb4', getenv('IO_TEST_USER') ?: 'root', getenv('IO_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->exec('DROP DATABASE IF EXISTS io_prueba_tx'); $pdo->exec('CREATE DATABASE io_prueba_tx CHARACTER SET utf8mb4'); $pdo->exec('USE io_prueba_tx');
foreach (glob(__DIR__ . '/../sql/*.sql') as $f) { foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents($f))))) as $sql) { $pdo->exec($sql); } }
date_default_timezone_set('America/Mexico_City');
$pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
IO::usarConexion($pdo, ['zona_horaria' => 'America/Mexico_City', 'dominio' => 'https://inteligenciaobjetiva.com']);

$ok = 0; $fallos = 0;
function verificar(string $n, bool $c): void { global $ok, $fallos; if ($c) { $ok++; echo "  ✔ $n\n"; } else { $fallos++; echo "  ✘ $n\n"; } }
function esperarError(string $n, string $codigo, callable $fn): void { try { $fn(); verificar("$n (debía fallar)", false); } catch (IOError $e) { verificar("$n → $codigo", $e->codigo === $codigo); } }
$adm = ['id' => 1, 'email' => 'admin@prueba.test'];

echo "\n1. Reconocer enlaces\n";
$casos = [
  ['https://www.youtube.com/watch?v=aqz-KE-bpKQ&t=30', 'youtube', 'aqz-KE-bpKQ'], ['https://youtu.be/aqz-KE-bpKQ?si=x', 'youtube', 'aqz-KE-bpKQ'],
  ['youtube.com/live/aqz-KE-bpKQ?feature=share', 'youtube', 'aqz-KE-bpKQ'], ['https://m.youtube.com/embed/aqz-KE-bpKQ', 'youtube', 'aqz-KE-bpKQ'],
  ['https://vimeo.com/76979871', 'vimeo', '76979871'], ['https://vimeo.com/event/4567890', 'vimeo', '4567890'],
  ['https://player.vimeo.com/video/22439234?h=abc', 'vimeo', '22439234'],
  ['https://www.facebook.com/MartinAparicio.i0/videos/1000000000000001/', 'facebook', null], ['https://fb.watch/abcDEF123/', 'facebook', null],
  ['https://www.facebook.com/watch/?v=1000000000000001', 'facebook', null], ['https://us02web.zoom.us/j/81234567890?pwd=x', 'zoom', null],
];
foreach ($casos as [$url, $plat, $id]) {
    $r = IOTransmisiones::analizarEnlace($url);
    verificar("$url → $plat", $r['plataforma'] === $plat && ($id === null || $r['video_id'] === $id));
}
verificar('evento de Vimeo marcado como evento', IOTransmisiones::analizarEnlace('https://vimeo.com/event/4567890')['es_evento_vimeo'] === true);
esperarError('canal de YouTube sin video', 'enlace', fn() => IOTransmisiones::analizarEnlace('https://www.youtube.com/@BlenderOfficial'));
esperarError('página de Facebook que no es video', 'enlace', fn() => IOTransmisiones::analizarEnlace('https://www.facebook.com/MartinAparicio.i0'));
esperarError('sitio desconocido', 'enlace', fn() => IOTransmisiones::analizarEnlace('https://ejemplo.com/video/1'));
esperarError('Zoom sin reunión', 'enlace', fn() => IOTransmisiones::analizarEnlace('https://zoom.us/pricing'));

echo "\n2. Reproductores\n";
$yt = ['plataforma' => 'youtube', 'video_id' => 'aqz-KE-bpKQ', 'es_evento_vimeo' => 0, 'mostrar_chat' => 1];
verificar('YouTube sin cookies de seguimiento', strpos(IOTransmisiones::urlReproductor($yt, ''), 'youtube-nocookie.com/embed/aqz-KE-bpKQ') !== false);
verificar('chat de YouTube con el dominio del sitio', strpos((string) IOTransmisiones::urlChat($yt, 'https://inteligenciaobjetiva.com'), 'embed_domain=inteligenciaobjetiva.com') !== false);
verificar('sin chat si se desactiva', IOTransmisiones::urlChat(['mostrar_chat' => 0] + $yt, '') === null);
$fb = ['plataforma' => 'facebook', 'video_id' => 'https://www.facebook.com/x/videos/1/', 'es_evento_vimeo' => 0, 'mostrar_chat' => 1];
verificar('Facebook usa su reproductor oficial', strpos(IOTransmisiones::urlReproductor($fb, ''), 'https://www.facebook.com/plugins/video.php?href=') === 0 && IOTransmisiones::urlChat($fb, '') === null);
verificar('evento de Vimeo', IOTransmisiones::urlReproductor(['plataforma' => 'vimeo', 'video_id' => '9', 'es_evento_vimeo' => 1], '') === 'https://vimeo.com/event/9/embed');
verificar('Zoom no se inserta', IOTransmisiones::urlReproductor(['plataforma' => 'zoom', 'video_id' => 'x', 'es_evento_vimeo' => 0], '') === null);

echo "\n3. Guardar y validar\n";
$base = ['titulo' => 'Prueba', 'url' => 'https://youtu.be/aqz-KE-bpKQ', 'inicio' => date('Y-m-d H:i', strtotime('+1 day')), 'duracion_min' => 60, 'acceso' => 'publico'];
$id1 = IOTransmisiones::guardar($adm, $base);
$id2 = IOTransmisiones::guardar($adm, $base);
verificar('slug único con títulos repetidos', IO::uno('SELECT slug FROM io_transmisiones WHERE id = ?', [$id2])['slug'] === 'prueba-2');
esperarError('Facebook exclusivo no se permite', 'acceso', fn() => IOTransmisiones::guardar($adm, ['url' => 'https://fb.watch/abc/', 'acceso' => 'miembros'] + $base));
esperarError('compradores sin contenido', 'producto', fn() => IOTransmisiones::guardar($adm, ['acceso' => 'compradores'] + $base));
esperarError('sin título', 'titulo', fn() => IOTransmisiones::guardar($adm, ['titulo' => ' '] + $base));
esperarError('duración absurda', 'duracion', fn() => IOTransmisiones::guardar($adm, ['duracion_min' => 5] + $base));
esperarError('imagen con javascript:', 'imagen', fn() => IOTransmisiones::guardar($adm, ['imagen' => 'javascript:alert(1)'] + $base));
verificar('cada cambio queda en la bitácora', (int) IO::uno("SELECT COUNT(*) n FROM io_bitacora WHERE accion LIKE 'transmision_%'")['n'] === 2);

echo "\n4. Al aire y horarios\n";
$t = IO::uno('SELECT * FROM io_transmisiones WHERE id = ?', [$id1]);
verificar('programada a futuro: no está al aire', !IOTransmisiones::alAire($t) && IOTransmisiones::ahora() === null);
verificar('sala abierta 15 min antes', IOTransmisiones::abierta($t, strtotime($t['inicio']) - 600) && !IOTransmisiones::abierta($t, strtotime($t['inicio']) - 1800));
verificar('al aire dentro de su horario', IOTransmisiones::alAire($t, strtotime($t['inicio']) + 60) && !IOTransmisiones::alAire($t, strtotime($t['inicio']) + 3700));
IOTransmisiones::cambiarEstado($adm, $id1, 'iniciar');
verificar('"Iniciar ahora" la pone al aire aunque sea antes de la hora', IOTransmisiones::ahora()['id'] == $id1);
IOTransmisiones::cambiarEstado($adm, $id1, 'terminar');
verificar('"Terminar" la saca del aire', IOTransmisiones::ahora() === null);
IO::ejecutar('UPDATE io_transmisiones SET inicio = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE id = ?', [$id2]);
verificar('una programada entra al aire sola a su hora', (int) (IOTransmisiones::ahora()['id'] ?? 0) === $id2);
esperarError('acción de estado inválida', 'accion', fn() => IOTransmisiones::cambiarEstado($adm, $id2, 'borrar'));

echo "\n5. Quién puede verla\n";
$ana = ['id' => 101, 'email' => 'ana@prueba.test', 'nombre' => 'Ana'];
IOAdmin::guardarPolitica($adm, ['producto_id' => 7, 'titulo' => 'Curso', 'vimeo_id' => '1', 'vimeo_hash' => null, 'duracion_seg' => 60, 'tipo' => 'ventana', 'horas_ventana' => 48,
    'cuenta_desde' => 'primera_reproduccion', 'dias_para_empezar' => 30, 'umbral_pct' => 95, 'retroceso_seg' => 30, 'permitir_adelantar' => 0, 'pausa_max_min' => 60,
    'reanudaciones_max' => 2, 'gracia_min' => 120], 'Configuración');
$tx = fn(string $acc, ?int $prod = null) => ['acceso' => $acc, 'producto_id' => $prod];
verificar('pública: cualquiera', IOTransmisiones::puedeVer($tx('publico'), null)[0]);
verificar('con cuenta: visitante no', !IOTransmisiones::puedeVer($tx('registrados'), null)[0] && IOTransmisiones::puedeVer($tx('registrados'), $ana)[0]);
verificar('compradores: Ana sin compra no', !IOTransmisiones::puedeVer($tx('compradores', 7), $ana)[0]);
IOAccesos::otorgar(101, 'ana@prueba.test', 'Ana', 7);
verificar('compradores: Ana con compra sí', IOTransmisiones::puedeVer($tx('compradores', 7), $ana)[0]);
verificar('miembros: sin membresía no', !IOTransmisiones::puedeVer($tx('miembros'), $ana)[0]);
$cols = array_column(IO::todos('SHOW COLUMNS FROM io_membresias'), 'Field');
$fila = ['cliente_id' => 101, 'moneda' => 'MXN', 'estado' => 'activa', 'pagado_hasta' => date('Y-m-d H:i:s', strtotime('+20 days'))];
foreach (IO::todos('SHOW COLUMNS FROM io_membresias') as $c) {
    if ($c['Null'] === 'NO' && $c['Default'] === null && $c['Extra'] === '' && !isset($fila[$c['Field']])) {
        $fila[$c['Field']] = preg_match('/int/', $c['Type']) ? 1 : (preg_match('/date/', $c['Type']) ? date('Y-m-d H:i:s') : 'x' . bin2hex(random_bytes(3)));
    }
}
IO::ejecutar('INSERT INTO io_membresias (' . implode(',', array_keys($fila)) . ') VALUES (' . implode(',', array_fill(0, count($fila), '?')) . ')', array_values($fila));
verificar('miembros: con membresía activa sí', IOTransmisiones::puedeVer($tx('miembros'), $ana)[0]);
IO::ejecutar('UPDATE io_membresias SET pagado_hasta = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE cliente_id = 101');
verificar('miembros: membresía vencida no', !IOTransmisiones::puedeVer($tx('miembros'), $ana)[0]);

echo "\n6. Calendario\n";
$t = IO::uno('SELECT * FROM io_transmisiones WHERE id = ?', [$id1]);
$ics = IOTransmisiones::ics(['titulo' => 'Uno; dos, tres'] + $t, 'https://inteligenciaobjetiva.com');
verificar('formato iCalendar con alarma', strpos($ics, "BEGIN:VCALENDAR\r\n") === 0 && strpos($ics, 'BEGIN:VALARM') !== false && substr($ics, -2) === "\r\n");
verificar('hora en UTC', (bool) preg_match('/DTSTART:\d{8}T\d{6}Z/', $ics));
verificar('texto escapado', strpos($ics, 'SUMMARY:En vivo: Uno\; dos\, tres') !== false);

$pdo->exec('DROP DATABASE io_prueba_tx');
echo "\nResultado: $ok correctas, $fallos fallidas\n";
exit($fallos ? 1 : 0);
