<?php
/**
 * Agrega transmisiones de ejemplo a la base de demo (io_demo). Solo CLI. Ejecutar después de sembrar-demo.php:
 *   php tests/sembrar-transmisiones.php
 * Usa un video PÚBLICO de YouTube (Big Buck Bunny, Blender Foundation) para simular el directo.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/Transmisiones.php';

$pdo = new PDO('mysql:host=127.0.0.1;port=3307;dbname=io_demo;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents(__DIR__ . '/../sql/008_io_transmisiones.sql'))))) as $sql) { $pdo->exec($sql); }
date_default_timezone_set('America/Mexico_City');
$pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
IO::usarConexion($pdo, ['zona_horaria' => 'America/Mexico_City', 'dominio' => 'http://127.0.0.1:8090']);
$pdo->exec('DELETE FROM io_transmisiones');
$adm = ['id' => 1, 'email' => 'demo-admin@ejemplo.test'];
$h = fn(string $rel) => date('Y-m-d H:i:s', strtotime($rel));

$x = [
  ['Conferencia en vivo: el miedo y la decisión', 'https://www.youtube.com/watch?v=aqz-KE-bpKQ', $h('-10 minutes'), 90, 'publico', null, null,
   'Martín Aparicio responde en vivo cómo dejar de decidir desde el miedo. Escribe tus preguntas en el chat.'],
  ['Sesión de preguntas para miembros', 'https://youtu.be/aqz-KE-bpKQ', $h('tomorrow 19:00'), 60, 'miembros', null, null,
   'Una hora de preguntas y respuestas exclusiva para miembros Premium y Platinum.'],
  ['Charla abierta: el sentido de la vida', 'https://www.facebook.com/MartinAparicio.i0/videos/1000000000000001/', $h('+3 days 20:00'), 60, 'publico', null, null,
   'Transmisión abierta desde la página de Facebook de Martín Aparicio.'],
  ['Taller en vivo: 3 errores en la pareja', 'https://zoom.us/j/81234567890', $h('+5 days 18:00'), 120, 'compradores', 410, null,
   'Sesión práctica por Zoom para quienes compraron la conferencia "3 errores básicos en una relación de pareja".'],
  ['Conferencia: amor y enamoramiento', 'https://www.youtube.com/watch?v=aqz-KE-bpKQ', $h('-7 days 19:00'), 90, 'publico', null, 414,
   'Transmisión pasada. La grabación ya está en el catálogo.'],
];
foreach ($x as [$tit, $url, $ini, $dur, $acc, $prod, $grab, $desc]) {
    $id = IOTransmisiones::guardar($adm, ['titulo' => $tit, 'url' => $url, 'inicio' => $ini, 'duracion_min' => $dur, 'acceso' => $acc,
        'producto_id' => $prod, 'grabacion_producto_id' => $grab, 'descripcion' => $desc, 'mostrar_chat' => 1, 'imagen' => '/portada/img/conferencias.webp']);
    if (strtotime($ini) + 60 * $dur < time()) { IOTransmisiones::cambiarEstado($adm, $id, 'terminar'); }
}
echo "Transmisiones de demo: " . count($x) . " (una al aire ahora).\n";
