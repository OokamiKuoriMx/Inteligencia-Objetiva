<?php
/**
 * Prueba de punta a punta del módulo contra una base de datos de PRUEBA.
 *   IO_TEST_DSN="mysql:host=127.0.0.1;port=3307;charset=utf8mb4" IO_TEST_USER=root IO_TEST_PASS= php tests/prueba-flujo.php
 * Crea y borra la base io_prueba. Nunca apuntar a producción.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/IO.php';

$dsn = getenv('IO_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3307;charset=utf8mb4';
$pdo = new PDO($dsn, getenv('IO_TEST_USER') ?: 'root', getenv('IO_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->exec('DROP DATABASE IF EXISTS io_prueba');
$pdo->exec('CREATE DATABASE io_prueba CHARACTER SET utf8mb4');
$pdo->exec('USE io_prueba');
foreach (glob(__DIR__ . '/../sql/*.sql') as $archivo) {
    foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents($archivo))))) as $sql) { $pdo->exec($sql); }
}
date_default_timezone_set('America/Mexico_City');
$pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
IO::usarConexion($pdo, ['zona_horaria' => 'America/Mexico_City', 'vimeo_token' => '']);

$ok = 0; $fallos = 0;
function verificar(string $nombre, bool $cond): void { global $ok, $fallos; if ($cond) { $ok++; echo "  ✔ $nombre\n"; } else { $fallos++; echo "  ✘ $nombre\n"; } }
function esperarError(string $nombre, string $codigo, callable $fn): void
{
    try { $fn(); verificar("$nombre (debía fallar con $codigo)", false); }
    catch (IOError $e) { verificar("$nombre → $codigo", $e->codigo === $codigo); }
}
/** Simula que pasaron $seg segundos desde el último latido. */
function retroceder(string $token, int $seg): void { IO::ejecutar('UPDATE io_sesiones SET ultimo_latido = DATE_SUB(NOW(), INTERVAL ? SECOND) WHERE token = ?', [$seg, $token]); }
function acceso(int $id): array { return IO::uno('SELECT * FROM io_accesos WHERE id = ?', [$id]); }

$admin = ['id' => 1, 'email' => 'admin@prueba.test'];
$ana = ['id' => 101, 'email' => 'ana@prueba.test', 'nombre' => 'Ana'];
$beto = ['id' => 202, 'email' => 'beto@prueba.test', 'nombre' => 'Beto'];

$base = ['vimeo_hash' => null, 'horas_ventana' => 48, 'cuenta_desde' => 'primera_reproduccion', 'dias_para_empezar' => 30, 'umbral_pct' => 95,
         'pausa_max_min' => 60, 'reanudaciones_max' => 2, 'gracia_min' => 120];
IOAdmin::guardarPolitica($admin, $base + ['producto_id' => 410, 'titulo' => 'Conferencia única', 'vimeo_id' => '692031291', 'duracion_seg' => 600,
    'tipo' => 'unica', 'retroceso_seg' => 30, 'permitir_adelantar' => 0], 'Configuración');
IOAdmin::guardarPolitica($admin, $base + ['producto_id' => 500, 'titulo' => 'Conferencia 48 h', 'vimeo_id' => '111', 'duracion_seg' => 3000,
    'tipo' => 'ventana', 'retroceso_seg' => 999999, 'permitir_adelantar' => 1], 'Configuración');

echo "\n0. Upsert de políticas sin VALUES()\n";
IOAdmin::guardarPolitica($admin, $base + ['producto_id' => 500, 'titulo' => 'Conferencia 48 h (editada)', 'vimeo_id' => '111', 'duracion_seg' => 3000,
    'tipo' => 'ventana', 'retroceso_seg' => 999999, 'permitir_adelantar' => 1], 'Configuración');
verificar('la segunda escritura actualiza el título', IO::uno('SELECT titulo FROM io_politicas WHERE producto_id = 500')['titulo'] === 'Conferencia 48 h (editada)');

echo "\n1. Compra y pase de reproducción\n";
$id = IOAccesos::otorgar($ana['id'], $ana['email'], $ana['nombre'], 410, 'compra', 'PED-1');
verificar('acceso nace "por_empezar"', acceso($id)['estado'] === 'por_empezar');
esperarError('Beto no puede iniciar el acceso de Ana', 'sin_permiso', fn() => IOAccesos::iniciar($id, $beto, 'UA', '1.1.1.1'));
$pase = IOAccesos::iniciar($id, $ana, 'Chrome Windows', '10.0.0.1');
verificar('pase entrega el ID de Vimeo', $pase['vimeo_id'] === '692031291');
verificar('acceso pasa a "activo" sin consumir reanudación', acceso($id)['estado'] === 'activo' && (int) acceso($id)['reanudaciones'] === 0);
$t = $pase['token'];

echo "\n2. Latidos: avance acreditado solo por tiempo real\n";
esperarError('Beto no puede usar el token de Ana', 'sin_permiso', fn() => IOAccesos::latido($t, $beto, 15, true));
retroceder($t, 15); $r = IOAccesos::latido($t, $ana, 15, true);
verificar('latido normal avanza a 15 s', $r['max_seg'] === 15);
$r = IOAccesos::latido($t, $ana, 40, true);
verificar('latido inmediato (< 10 s) no acredita avance', $r['max_seg'] === 15);
for ($i = 0; $i < 30; $i++) { IOAccesos::latido($t, $ana, 590, true); }
verificar('30 latidos en ráfaga no avanzan nada', (int) acceso($id)['max_seg'] === 15);
retroceder($t, 15); $r = IOAccesos::latido($t, $ana, 400, true);
verificar('salto a 400 s se limita al tiempo real (15 + 20 = 35)', ($r['corregir_a'] ?? null) === 35 && $r['max_seg'] === 35);
verificar('el salto genera alerta', (bool) IO::uno("SELECT id FROM io_alertas WHERE tipo = 'adelanto_bloqueado'"));
for ($s = 50; $s <= 125; $s += 15) { retroceder($t, 15); $r = IOAccesos::latido($t, $ana, $s, true); }
verificar('avance legítimo llega a 125 s', $r['max_seg'] === 125);
retroceder($t, 15); $r = IOAccesos::latido($t, $ana, 10, true);
verificar('retroceso de 115 s se limita a 30 s', ($r['corregir_a'] ?? null) === 95);
retroceder($t, 15); $r = IOAccesos::latido($t, $ana, 125, false);
verificar('en pausa no acredita avance', $r['max_seg'] === 125);
esperarError('"ended" sin haber visto el 95% no consume', 'x', function () use ($t, $ana) { $r = IOAccesos::finalizado($t, $ana); if (($r['estado'] ?? '') === 'visto') { return; } throw new IOError('x', 'ok'); });

echo "\n3. Segundo dispositivo cuenta como reanudación\n";
$pase2 = IOAccesos::iniciar($id, $ana, 'Safari iPhone', '10.0.0.2');
esperarError('la sesión anterior queda cerrada', 'sesion_cerrada', fn() => IOAccesos::latido($t, $ana, 140, true));
verificar('alerta de doble dispositivo', (bool) IO::uno("SELECT id FROM io_alertas WHERE tipo = 'doble_dispositivo'"));
verificar('retoma en 125 s y usa reanudación 1 de 2', $pase2['empezar_en'] === 125 && (int) acceso($id)['reanudaciones'] === 1);

echo "\n4. Corte, gracia y reanudaciones\n";
IOAccesos::cerrar($pase2['token'], $ana);
verificar('cerrar pestaña → "en_gracia"', acceso($id)['estado'] === 'en_gracia');
esperarError('el token cerrado ya no sirve', 'sesion_cerrada', fn() => IOAccesos::latido($pase2['token'], $ana, 130, true));
$p3 = IOAccesos::iniciar($id, $ana, 'Chrome', '10.0.0.1');
verificar('reanudación 2 de 2', (int) acceso($id)['reanudaciones'] === 2);
IOAccesos::cerrar($p3['token'], $ana);
esperarError('tercera reanudación se niega', 'visto', fn() => IOAccesos::iniciar($id, $ana, 'Chrome', '10.0.0.1'));
verificar('acceso queda "visto" (persistido aunque hubo error)', acceso($id)['estado'] === 'visto');

echo "\n5. Token viejo, corte sin aviso y gracia vencida\n";
$id2 = IOAccesos::otorgar($ana['id'], $ana['email'], $ana['nombre'], 410);
$p = IOAccesos::iniciar($id2, $ana, 'Chrome', '10.0.0.1');
retroceder($p['token'], 600);
esperarError('latido tras 10 min de silencio se rechaza', 'sesion_cerrada', fn() => IOAccesos::latido($p['token'], $ana, 30, true));
verificar('y el acceso pasa a "en_gracia"', acceso($id2)['estado'] === 'en_gracia');
IO::ejecutar('UPDATE io_accesos SET gracia_hasta = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?', [$id2]);
verificar('gracia vencida → "visto"', IOAccesos::refrescar(acceso($id2))['estado'] === 'visto');

echo "\n6. Terminar por umbral y por evento ended\n";
$id3 = IOAccesos::otorgar($ana['id'], $ana['email'], $ana['nombre'], 410);
$p = IOAccesos::iniciar($id3, $ana, 'Chrome', '10.0.0.1');
IO::ejecutar('UPDATE io_accesos SET max_seg = 560 WHERE id = ?', [$id3]);
retroceder($p['token'], 15); $r = IOAccesos::latido($p['token'], $ana, 575, true);
verificar('al 95% (570 s) queda "visto" y fin', !empty($r['fin']) && acceso($id3)['estado'] === 'visto');
$id3b = IOAccesos::otorgar($ana['id'], $ana['email'], $ana['nombre'], 410);
$p = IOAccesos::iniciar($id3b, $ana, 'Chrome', '10.0.0.1');
IO::ejecutar('UPDATE io_accesos SET max_seg = 580 WHERE id = ?', [$id3b]);
$r = IOAccesos::finalizado($p['token'], $ana);
verificar('"ended" con 580 s acreditados marca visto', ($r['estado'] ?? '') === 'visto');

echo "\n7. Ventana de 48 h desde la primera reproducción\n";
$id4 = IOAccesos::otorgar($beto['id'], $beto['email'], $beto['nombre'], 500);
verificar('sin fecha de vencimiento antes del play', acceso($id4)['vence'] === null);
$p4 = IOAccesos::iniciar($id4, $beto, 'Chrome', '1.1.1.1');
$h = (strtotime(acceso($id4)['vence']) - time()) / 3600;
verificar('vence ≈ 48 h después del primer play', $h > 47.9 && $h <= 48.01);
IO::ejecutar('UPDATE io_accesos SET vence = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?', [$id4]);
retroceder($p4['token'], 15);
esperarError('latido con la ventana vencida se rechaza', 'sesion_cerrada', fn() => IOAccesos::latido($p4['token'], $beto, 30, true));
esperarError('ventana vencida no reproduce', 'vencido', fn() => IOAccesos::iniciar($id4, $beto, 'Chrome', '1.1.1.1'));

echo "\n8. Acciones del backoffice\n";
esperarError('motivo obligatorio', 'motivo', fn() => IOAdmin::reiniciar($admin, $id, ''));
esperarError('motivo de más de 120 caracteres', 'motivo', fn() => IOAdmin::reiniciar($admin, $id, str_repeat('á', 121)));
esperarError('no se extiende una visualización única', 'tipo', fn() => IOAdmin::extender($admin, $id, 24, 'Cortesía comercial'));
IOAdmin::reiniciar($admin, $id, 'Falla técnica');
verificar('reiniciar deja "por_empezar" y progreso en 0', acceso($id)['estado'] === 'por_empezar' && (int) acceso($id)['max_seg'] === 0 && (int) acceso($id)['reanudaciones'] === 0);
IOAdmin::extender($admin, $id4, 24, 'Cortesía comercial');
verificar('extender reactiva un acceso vencido', acceso($id4)['estado'] === 'activo' && acceso($id4)['vence'] > IO::ahora());
IOAdmin::revocar($admin, $id4, 'Abuso o fraude');
esperarError('revocado no reproduce', 'revocado', fn() => IOAccesos::iniciar($id4, $beto, 'Chrome', '1.1.1.1'));
verificar('bitácora: 3 políticas + 3 acciones', (int) IO::uno('SELECT COUNT(*) n FROM io_bitacora')['n'] === 6);
$antes = (int) IO::uno('SELECT COUNT(*) n FROM io_bitacora')['n'];
$pdo->exec("CREATE TRIGGER io_prueba_falla BEFORE INSERT ON io_bitacora FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'bitacora caida'");
try { IOAdmin::revocar($admin, $id3, 'Falla técnica'); } catch (Throwable $e) { }
$pdo->exec('DROP TRIGGER io_prueba_falla');
verificar('si la bitácora falla, el cambio se revierte', acceso($id3)['estado'] === 'visto' && (int) IO::uno('SELECT COUNT(*) n FROM io_bitacora')['n'] === $antes);

echo "\n9. Biblioteca del alumno\n";
$lib = IOAccesos::delCliente($ana['id']);
verificar('Ana ve solo sus accesos', count($lib) === 4 && count(array_filter($lib, fn($a) => (int) $a['cliente_id'] !== 101)) === 0);

$pdo->exec('DROP DATABASE io_prueba');
echo "\nResultado: $ok correctas, $fallos fallidas\n";
exit($fallos ? 1 : 0);
