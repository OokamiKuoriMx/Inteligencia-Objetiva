<?php
/** Crea la base io_demo con datos ficticios para revisar el backoffice en local. Solo CLI. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/IO.php';

$pdo = new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('DROP DATABASE IF EXISTS io_demo; CREATE DATABASE io_demo CHARACTER SET utf8mb4; USE io_demo');
foreach (glob(__DIR__ . '/../sql/*.sql') as $f) { foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents($f))))) as $sql) { $pdo->exec($sql); } }
date_default_timezone_set('America/Mexico_City');
$pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
IO::usarConexion($pdo, ['zona_horaria' => 'America/Mexico_City']);
$adm = ['id' => 1, 'email' => 'demo-admin@ejemplo.test'];

$base = ['vimeo_hash' => null, 'horas_ventana' => 48, 'cuenta_desde' => 'primera_reproduccion', 'dias_para_empezar' => 30, 'umbral_pct' => 95, 'retroceso_seg' => 30,
         'permitir_adelantar' => 0, 'pausa_max_min' => 60, 'reanudaciones_max' => 2, 'gracia_min' => 120];
$productos = [
    [410, '3 errores básicos en una relación de pareja', 'ventana', 8520],
    [411, '5 elementos para incrementar tu economía', 'ventana', 7900],
    [412, 'Ansiedad cero: respira, suelta y avanza', 'unica', 5400],
    [413, 'El gran sistema: cómo funciona la existencia', 'unica', 6600],
    [600, 'Documental: Introspección I', 'permanente', 3600],
];
foreach ($productos as [$id, $t, $tipo, $dur]) {
    IOAdmin::guardarPolitica($adm, $base + ['producto_id' => $id, 'titulo' => $t, 'vimeo_id' => (string) (692031291 + $id), 'duracion_seg' => $dur, 'tipo' => $tipo], 'Configuración');
}
$alumnos = [['Laura Méndez', 'laura@ejemplo.test'], ['Jorge Ruiz', 'jorge@ejemplo.test'], ['Sofía Pardo', 'sofia@ejemplo.test'], ['Andrés Lima', 'andres@ejemplo.test'],
            ['Paula Ortega', 'paula@ejemplo.test'], ['Diego Salas', 'diego@ejemplo.test'], ['Marta Gil', 'marta@ejemplo.test'], ['Iván Rojas', 'ivan@ejemplo.test']];
$n = 0;
foreach ($alumnos as $i => [$nombre, $email]) {
    $c = ['id' => 1000 + $i, 'email' => $email, 'nombre' => $nombre];
    foreach ([410, 412, 600, 411, 413] as $j => $prod) {
        if (($i + $j) % 3 === 2) continue;
        $id = IOAccesos::otorgar($c['id'], $email, $nombre, $prod, $j % 4 === 3 ? 'membresia' : 'compra', 'PED-' . (2400 + ++$n));
        $estado = ($i + $j) % 5;
        if ($estado === 0) continue; // por empezar
        $pase = IOAccesos::iniciar($id, $c, $i % 2 ? 'Mozilla/5.0 (iPhone) Safari/604.1' : 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0', '189.203.' . $i . '.' . $j);
        $dur = (int) IO::uno('SELECT duracion_seg FROM io_politicas WHERE producto_id = ?', [$prod])['duracion_seg'];
        $pos = (int) ($dur * [0, .35, .62, .97, .18][$estado]);
        IO::ejecutar('UPDATE io_accesos SET max_seg = ?, seg_vistos = ? WHERE id = ?', [$pos, $pos, $id]);
        IO::ejecutar('UPDATE io_sesiones SET posicion_seg = ?, reproduciendo = 1 WHERE token = ?', [$pos, $pase['token']]);
        if ($estado === 3) { IO::ejecutar('UPDATE io_accesos SET estado = "visto", visto_en = NOW() WHERE id = ?', [$id]); IO::ejecutar('UPDATE io_sesiones SET activa = 0 WHERE token = ?', [$pase['token']]); }
        if ($estado === 4) { IOAccesos::cerrar($pase["token"], $c); }
        if ($estado === 1 && $prod === 410) { IO::ejecutar('UPDATE io_accesos SET vence = DATE_ADD(NOW(), INTERVAL 9 HOUR) WHERE id = ?', [$id]); }
    }
}
IOAccesos::alerta(3, 1001, 'doble_dispositivo', 'Se abrió en: Mozilla/5.0 (iPhone) Safari/604.1');
IOAccesos::alerta(7, 1003, 'adelanto_bloqueado', 'posición 3900, máximo 1210');
$pdo->prepare('INSERT INTO io_admins (email, nombre, hash, rol) VALUES (?,?,?,?)')->execute(['demo-admin@ejemplo.test', 'Admin Demo', password_hash('demo-local-12345', PASSWORD_DEFAULT), 'admin']);
echo "Base io_demo lista: " . IO::uno('SELECT COUNT(*) n FROM io_accesos')['n'] . " accesos. Usuario demo-admin@ejemplo.test (ver tests/README).\n";
