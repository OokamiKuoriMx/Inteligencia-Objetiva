<?php
/**
 * Pruebas de "Otorgar acceso": búsqueda de alumnos (io_accesos + io_pedidos + gancho buscar_clientes) y verificación en servidor.
 *   php tests/prueba-otorgar.php      (usa el MySQL de prueba del puerto 3307; crea y borra io_prueba_otorgar)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/Alumnos.php';

$dsn = getenv('IO_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3307;charset=utf8mb4';
$pdo = new PDO($dsn, getenv('IO_TEST_USER') ?: 'root', getenv('IO_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->exec('DROP DATABASE IF EXISTS io_prueba_otorgar');
$pdo->exec('CREATE DATABASE io_prueba_otorgar CHARACTER SET utf8mb4');
$pdo->exec('USE io_prueba_otorgar');
foreach (glob(__DIR__ . '/../sql/*.sql') as $f) { foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents($f))))) as $sql) { $pdo->exec($sql); } }
date_default_timezone_set('America/Mexico_City');
$pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
$cfg = ['zona_horaria' => 'America/Mexico_City', 'dominio' => 'http://127.0.0.1:8090', 'dev' => true];
IO::usarConexion($pdo, $cfg);

$ok = 0; $fallos = 0;
function verificar(string $n, bool $c): void { global $ok, $fallos; if ($c) { $ok++; echo "  ✔ $n\n"; } else { $fallos++; echo "  ✘ $n\n"; } }
function ids(array $r): array { return array_map(fn($a) => $a['id'], $r); }

$adm = ['id' => 1, 'email' => 'admin@prueba.test'];
$base = ['vimeo_hash' => null, 'horas_ventana' => 48, 'cuenta_desde' => 'primera_reproduccion', 'dias_para_empezar' => 30, 'umbral_pct' => 95,
         'retroceso_seg' => 30, 'permitir_adelantar' => 0, 'pausa_max_min' => 60, 'reanudaciones_max' => 2, 'gracia_min' => 120];
IOAdmin::guardarPolitica($adm, $base + ['producto_id' => 1, 'titulo' => '3 errores básicos en una relación de pareja', 'vimeo_id' => '76979871', 'duracion_seg' => 600, 'tipo' => 'ventana'], 'Configuración');
IOAdmin::guardarPolitica($adm, $base + ['producto_id' => 2, 'titulo' => 'Documental', 'vimeo_id' => '76979871', 'duracion_seg' => 600, 'tipo' => 'permanente'], 'Configuración');

// Laura: dos accesos (uno revocado). Jorge: solo un pedido (sin accesos). Laura también tiene pedido (no debe duplicarse).
$a1 = IOAccesos::otorgar(1000, 'laura@ejemplo.test', 'Laura Méndez', 1, 'compra', 'PED-1');
$a2 = IOAccesos::otorgar(1000, 'laura@ejemplo.test', 'Laura Méndez', 2, 'compra', 'PED-1');
IO::ejecutar("UPDATE io_accesos SET estado = 'revocado' WHERE id = ?", [$a2]);
$ped = 'INSERT INTO io_pedidos (folio, cliente_id, email, nombre, subtotal, iva, total, pasarela) VALUES (?,?,?,?,100,16,116,?)';
IO::ejecutar($ped, ['F-1', 1000, 'laura@ejemplo.test', 'Laura Méndez', 'demo']);
IO::ejecutar($ped, ['F-2', 2000, 'jorge@ejemplo.test', 'Jorge Ruiz', 'demo']);
IO::ejecutar($ped, ['F-3', 3000, 'laura.otra@ejemplo.test', 'Laurana 50%_off', 'demo']);
for ($i = 1; $i <= 14; $i++) { IOAccesos::otorgar(5000 + $i, "masivo$i@ejemplo.test", "Masivo $i", 1, 'compra', null); }

echo "Búsqueda local\n";
verificar('menos de 2 caracteres → sin resultados', IOAlumnos::buscar('l') === [] && IOAlumnos::buscar('  ') === []);
$r = IOAlumnos::buscar('lau');
verificar('"lau" encuentra a Laura Méndez', in_array(1000, ids($r), true));
verificar('Laura aparece una sola vez (accesos + pedidos, distinta por cliente_id)', count(array_filter($r, fn($a) => $a['id'] === 1000)) === 1);
$laura = array_values(array_filter($r, fn($a) => $a['id'] === 1000))[0] ?? [];
verificar('trae correo y nombre', ($laura['email'] ?? '') === 'laura@ejemplo.test' && ($laura['nombre'] ?? '') === 'Laura Méndez');
verificar('cuenta sus accesos (2)', ($laura['accesos'] ?? -1) === 2);
verificar('vigentes excluye el revocado', ($laura['vigentes'] ?? null) === [1]);
verificar('sin acentos: "mendez" encuentra a Laura', in_array(1000, ids(IOAlumnos::buscar('mendez')), true));
verificar('por correo: "jorge@" encuentra a Jorge (solo pedido)', ids(IOAlumnos::buscar('jorge@')) === [2000]);
verificar('Jorge sin accesos → 0', (IOAlumnos::buscar('jorge')[0]['accesos'] ?? -1) === 0);
verificar('comodines escapados: "0%" solo encuentra el texto literal', ids(IOAlumnos::buscar('0%')) === [3000]);
verificar('"__" no actúa como comodín', IOAlumnos::buscar('__') === []);
verificar('máximo 10 resultados', count(IOAlumnos::buscar('masivo')) === 10);
verificar('sin coincidencias → []', IOAlumnos::buscar('zzzz-nadie') === []);

echo "Verificación en servidor (no confiar en campos ocultos)\n";
verificar('ID + correo válidos → datos canónicos', IOAlumnos::verificar(1000, 'laura@ejemplo.test') === ['id' => 1000, 'email' => 'laura@ejemplo.test', 'nombre' => 'Laura Méndez']);
verificar('alumno solo con pedido también es válido', (IOAlumnos::verificar(2000, 'jorge@ejemplo.test')['id'] ?? 0) === 2000);
verificar('ID inexistente → null', IOAlumnos::verificar(999999, 'laura@ejemplo.test') === null);
verificar('ID de otro alumno con el correo de Laura → null', IOAlumnos::verificar(2000, 'laura@ejemplo.test') === null);
verificar('ID 0 → null', IOAlumnos::verificar(0, 'laura@ejemplo.test') === null);

echo "Gancho buscar_clientes\n";
verificar('sin gancho configurado', !IOAlumnos::hayGancho());
$llamadas = [];
IO::usarConexion($pdo, $cfg + ['buscar_clientes' => function (string $t) use (&$llamadas): array {
    $llamadas[] = $t;
    $tabla = [[7000, 'lucia@sitio.test', 'Lucía Nava'], ['id' => 1000, 'email' => 'laura@ejemplo.test', 'nombre' => 'Laura Méndez'], [0, 'malo@sitio.test', 'ID cero'], [7001, 'no-es-correo', 'Correo malo']];
    return array_values(array_filter($tabla, fn($c) => stripos(implode(' ', $c), $t) !== false));
}]);
verificar('gancho detectado', IOAlumnos::hayGancho());
$r = IOAlumnos::buscar('lucia@');
verificar('el gancho aporta alumnos sin compras (Lucía)', ids($r) === [7000] && $r[0]['origen'] === 'sitio' && $r[0]['accesos'] === 0);
verificar('el gancho recibe el texto', end($llamadas) === 'lucia@');
$r = IOAlumnos::buscar('laura');
verificar('sin duplicar al alumno del gancho que ya existe localmente', count(array_filter($r, fn($a) => $a['id'] === 1000)) === 1);
verificar('descarta filas del gancho con ID 0 o correo inválido', !in_array(0, ids(IOAlumnos::buscar('malo')), true) && IOAlumnos::buscar('Correo malo') === []);
verificar('verificar() acepta al alumno que solo existe en el gancho', IOAlumnos::verificar(7000, 'lucia@sitio.test') === ['id' => 7000, 'email' => 'lucia@sitio.test', 'nombre' => 'Lucía Nava']);
verificar('verificar() rechaza ID inventado con correo del gancho', IOAlumnos::verificar(7999, 'lucia@sitio.test') === null);

IO::usarConexion($pdo, $cfg + ['buscar_clientes' => function (): array { throw new RuntimeException('tabla no existe'); }]);
$prev = ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
verificar('si el gancho falla, la búsqueda local sigue funcionando', in_array(1000, ids(IOAlumnos::buscar('lau')), true));
ini_set('error_log', (string) $prev);

echo "Otorgar con datos verificados\n";
IO::usarConexion($pdo, $cfg);
$al = IOAlumnos::verificar(1000, 'laura@ejemplo.test');
$id = IOAdmin::cortesia($adm, $al['id'], $al['email'], $al['nombre'], 1, 'Cortesía comercial: ganadora de la rifa');
$b = IO::uno('SELECT * FROM io_bitacora WHERE acceso_id = ?', [$id]);
verificar('se crea el acceso de cortesía', (IO::uno('SELECT origen FROM io_accesos WHERE id = ?', [$id])['origen'] ?? '') === 'cortesia');
verificar('motivo + nota quedan en la bitácora', ($b['motivo'] ?? '') === 'Cortesía comercial: ganadora de la rifa' && $b['accion'] === 'otorgar');
verificar('el conteo de accesos de Laura sube a 3', (array_values(array_filter(IOAlumnos::buscar('laura@'), fn($a) => $a['id'] === 1000))[0]['accesos'] ?? 0) === 3);

$pdo->exec('DROP DATABASE io_prueba_otorgar');
echo "\n$ok correctas, $fallos fallidas\n";
exit($fallos ? 1 : 0);
