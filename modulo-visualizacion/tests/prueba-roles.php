<?php
/**
 * Pruebas de roles y perfiles del equipo (lib/Equipo.php + migración 010).
 *   php tests/prueba-roles.php      (usa el MySQL de prueba del puerto 3307; crea y borra io_prueba_roles)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/IO.php';
require_once __DIR__ . '/../lib/Contenidos.php';
require_once __DIR__ . '/../lib/Descuentos.php';
require_once __DIR__ . '/../lib/Pedidos.php';

$dsn = getenv('IO_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3307;charset=utf8mb4';
$pdo = new PDO($dsn, getenv('IO_TEST_USER') ?: 'root', getenv('IO_TEST_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->exec('DROP DATABASE IF EXISTS io_prueba_roles');
$pdo->exec('CREATE DATABASE io_prueba_roles CHARACTER SET utf8mb4');
$pdo->exec('USE io_prueba_roles');
$aplicar = function (string $f) use ($pdo): void {
    foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents($f))))) as $sql) { $pdo->exec($sql); }
};
// Primero 001–009 con admins "heredados", después 010 (como en producción).
$todas = glob(__DIR__ . '/../sql/*.sql');
foreach ($todas as $f) { if (basename($f) < '010') { $aplicar($f); } }
date_default_timezone_set('America/Mexico_City');
$pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
IO::usarConexion($pdo, ['zona_horaria' => 'America/Mexico_City', 'dominio' => 'http://127.0.0.1:8090', 'dev' => true]);

$ok = 0; $fallos = 0;
function verificar(string $n, bool $c): void { global $ok, $fallos; if ($c) { $ok++; echo "  ✔ $n\n"; } else { $fallos++; echo "  ✘ $n\n"; } }
function falla(callable $fn, string $codigo): bool { try { $fn(); return false; } catch (IOError $e) { return $e->codigo === $codigo; } }
function permisos(int $id): array { $m = IOEquipo::miembro($id); return $m ? $m['permisos'] : []; }
function bitacora(string $accion): int { return (int) IO::uno('SELECT COUNT(*) n FROM io_bitacora WHERE accion = ?', [$accion])['n']; }

foreach ([[1, 'admin'], [2, 'editor'], [3, 'soporte'], [4, 'finanzas']] as [$id, $rol]) {
    IO::ejecutar('INSERT INTO io_admins (id,email,nombre,hash,rol) VALUES (?,?,?,?,?)', [$id, "$rol@ejemplo.test", ucfirst($rol), password_hash('clave-original-123', PASSWORD_DEFAULT), $rol]);
}
$antes = [];
echo "Antes de la migración 010 (respaldo heredado)\n";
foreach ([1, 2, 3, 4] as $id) { $antes[$id] = permisos($id); }
verificar('editor heredado: ver, politicas, reportes', $antes[2] === ['ver', 'politicas', 'reportes']);
verificar('admin heredado no gestiona el equipo sin la migración', !in_array('equipo', $antes[1], true) && in_array('facturas', $antes[1], true));

echo "Migración 010\n";
foreach ($todas as $f) { if (basename($f) >= '010') { $aplicar($f); } }
$aplicar(__DIR__ . '/../sql/010_io_roles.sql');
verificar('idempotente: se aplica dos veces sin error ni duplicados', (int) IO::uno('SELECT COUNT(*) n FROM io_roles')['n'] === 5);
verificar('cada admin existente tiene ficha con su rol equivalente', (int) IO::uno('SELECT COUNT(*) n FROM io_equipo e JOIN io_roles r ON r.id = e.rol_id JOIN io_admins a ON a.id = e.admin_id WHERE r.clave = a.rol')['n'] === 4);
verificar('editor conserva exactamente sus permisos', permisos(2) === $antes[2]);
verificar('soporte conserva exactamente sus permisos', permisos(3) === ['ver', 'accesos', 'pedidos']);
verificar('finanzas conserva exactamente sus permisos', permisos(4) === ['ver', 'pedidos', 'reembolsos', 'reportes', 'bitacora']);
verificar('admin = dirección (total): todo, incluidos equipo y facturas', permisos(1) === array_keys(IOEquipo::PERMISOS));
verificar('rol "Solo lectura" sembrado solo con ver', (function () { foreach (IOEquipo::roles() as $r) { if ($r['clave'] === 'lectura') { return $r['permisos'] === ['ver']; } } return false; })());
IO::ejecutar('INSERT INTO io_admins (id,email,nombre,hash,rol) VALUES (9,"tardio@ejemplo.test","Tardío","x","editor")');
verificar('admin sin ficha (creado por fuera) usa el rol de io_admins.rol', permisos(9) === $antes[2]);

$dir = ['id' => 1, 'email' => 'admin@ejemplo.test'];
$editor = ['id' => 2, 'email' => 'editor@ejemplo.test'];
$rol = fn(string $clave) => (int) IO::uno('SELECT id FROM io_roles WHERE clave = ?', [$clave])['id'];

echo "Librerías usan el modelo nuevo\n";
verificar('contenidos: editor autorizado', is_array(IOEquipo::autorizar($editor, 'politicas')));
verificar('descuentos: soporte sin permiso', falla(fn() => IODescuentos::guardar(['id' => 3], [], 'x'), 'sin_permiso'));
verificar('reembolsos: soporte sin permiso', falla(fn() => IOPedidos::reembolsar(['id' => 3, 'email' => 'soporte@ejemplo.test'], 1, 'x'), 'sin_permiso'));

echo "Solo quien tiene el permiso equipo gestiona\n";
$alta = ['email' => 'Nueva@Ejemplo.test', 'nombre' => '  Ana   López ', 'clave' => 'una-clave-larga-1', 'rol_id' => $rol('lectura'), 'extras' => ['reportes', 'inventado']];
verificar('editor no puede dar de alta', falla(fn() => IOEquipo::crearMiembro($editor, $alta, 'Alta de personal'), 'sin_permiso'));
verificar('motivo obligatorio', falla(fn() => IOEquipo::crearMiembro($dir, $alta, '  '), 'motivo'));
verificar('contraseña corta rechazada', falla(fn() => IOEquipo::crearMiembro($dir, ['clave' => 'corta'] + $alta, 'Alta de personal'), 'clave'));
verificar('correo inválido rechazado', falla(fn() => IOEquipo::crearMiembro($dir, ['email' => 'no-es-correo'] + $alta, 'Alta de personal'), 'email'));
verificar('rol inexistente rechazado', falla(fn() => IOEquipo::crearMiembro($dir, ['rol_id' => 999] + $alta, 'Alta de personal'), 'rol'));
$b0 = bitacora('equipo_alta');
$ana = IOEquipo::crearMiembro($dir, $alta, 'Alta de personal');
$fila = IO::uno('SELECT * FROM io_admins WHERE id = ?', [$ana]);
verificar('alta: correo en minúsculas y nombre normalizado', $fila['email'] === 'nueva@ejemplo.test' && $fila['nombre'] === 'Ana López');
verificar('alta: contraseña con password_hash', password_verify('una-clave-larga-1', $fila['hash']) && strpos($fila['hash'], 'una-clave') === false);
verificar('alta: permisos = rol + extras válidos', permisos($ana) === ['ver', 'reportes']);
verificar('alta: io_admins.rol alineado al menor privilegio', $fila['rol'] === 'soporte');
verificar('alta: registrada en bitácora sin la contraseña', bitacora('equipo_alta') === $b0 + 1
    && strpos((string) IO::uno('SELECT detalle FROM io_bitacora WHERE accion = "equipo_alta" ORDER BY id DESC LIMIT 1')['detalle'], 'una-clave') === false);
verificar('correo repetido rechazado', falla(fn() => IOEquipo::crearMiembro($dir, $alta, 'Alta de personal'), 'email'));

echo "Bitácora en la misma transacción\n";
IO::ejecutar('RENAME TABLE io_bitacora TO io_bitacora_x');
$rompio = false;
try { IOEquipo::crearMiembro($dir, ['email' => 'otro@ejemplo.test'] + $alta, 'Alta de personal'); } catch (Throwable $e) { $rompio = true; }
IO::ejecutar('RENAME TABLE io_bitacora_x TO io_bitacora');
verificar('si la bitácora falla, no se crea el miembro', $rompio && !IO::uno('SELECT id FROM io_admins WHERE email = "otro@ejemplo.test"'));

echo "Roles\n";
$coord = IOEquipo::guardarRol($dir, 0, ['nombre' => 'Coordinación académica', 'descripcion' => 'Acompaña alumnos', 'permisos' => ['accesos', 'equipo', 'zzz']], 'Cambio de funciones');
$r = array_values(array_filter(IOEquipo::roles(), fn($x) => $x['id'] === $coord))[0];
verificar('rol nuevo: ver incluido siempre y permisos desconocidos descartados', $r['permisos'] === ['ver', 'accesos', 'equipo']);
verificar('rol nuevo: clave generada sin acentos', $r['clave'] === 'coordinacion-academica' && !$r['sistema']);
verificar('nombre de rol repetido rechazado', falla(fn() => IOEquipo::guardarRol($dir, 0, ['nombre' => 'Contenido', 'permisos' => []], 'Otro'), 'nombre'));
IOEquipo::guardarRol($dir, $rol('admin'), ['nombre' => 'Dirección general', 'permisos' => []], 'Otro');
verificar('rol total: se renombra pero conserva todos los permisos', permisos(1) === array_keys(IOEquipo::PERMISOS)
    && IO::uno('SELECT nombre FROM io_roles WHERE clave = "admin"')['nombre'] === 'Dirección general');
IOEquipo::guardarRol($dir, $rol('editor'), ['nombre' => 'Contenido', 'descripcion' => '', 'permisos' => ['politicas']], 'Cambio de funciones');
verificar('cambiar permisos de un rol aplica de inmediato a sus miembros', permisos(2) === ['ver', 'politicas']);
verificar('eliminar un rol base no se permite', falla(fn() => IOEquipo::eliminarRol($dir, $rol('lectura'), 'Otro'), 'rol'));

echo "Editar miembros\n";
$editar = fn(int $id, array $d, ?array $actor = null) => IOEquipo::editarMiembro($actor ?? $dir, $id, $d + ['nombre' => 'X', 'activo' => 1, 'extras' => []], 'Cambio de funciones');
$editar(3, ['nombre' => 'Soporte', 'rol_id' => $coord]);
verificar('cambio de rol aplica de inmediato', permisos(3) === ['ver', 'accesos', 'equipo']);
$sop = ['id' => 3, 'email' => 'soporte@ejemplo.test'];
verificar('quien recibe "equipo" por rol ya puede gestionar', is_array(IOEquipo::autorizar($sop, 'equipo')));
verificar('nadie se quita a sí mismo el permiso equipo (rol)', falla(fn() => $editar(3, ['nombre' => 'Soporte', 'rol_id' => $rol('lectura')], $sop), 'propio'));
verificar('nadie le quita "equipo" a su propio rol', falla(fn() => IOEquipo::guardarRol($sop, $coord, ['nombre' => 'Coordinación académica', 'permisos' => ['accesos']], 'Otro'), 'propio'));
$editar(3, ['nombre' => 'Soporte', 'rol_id' => $rol('lectura'), 'extras' => ['equipo']], $sop);
verificar('sí puede cambiarse de rol si conserva "equipo" como extra', permisos(3) === ['ver', 'equipo']);
verificar('nadie se desactiva a sí mismo', falla(fn() => $editar(3, ['nombre' => 'Soporte', 'rol_id' => $rol('lectura'), 'extras' => ['equipo'], 'activo' => 0], $sop), 'propio'));
$editar(9, ['nombre' => 'Tardío', 'rol_id' => $coord]);
verificar('rol en uso no se elimina', falla(fn() => IOEquipo::eliminarRol($dir, $coord, 'Otro'), 'rol'));

echo "Siempre queda un administrador total\n";
verificar('el único total no puede degradarse', falla(fn() => $editar(1, ['nombre' => 'Admin', 'rol_id' => $rol('editor'), 'extras' => ['equipo']]), 'ultimo_total'));
$editar(4, ['nombre' => 'Finanzas', 'rol_id' => $rol('admin')]);
$f4 = ['id' => 4, 'email' => 'finanzas@ejemplo.test'];
verificar('con dos totales, sí se puede degradar a uno', (function () use ($editar, $rol, $f4) { $editar(1, ['nombre' => 'Admin', 'rol_id' => $rol('editor'), 'extras' => ['equipo']], $f4); return !IOEquipo::miembro(1)['total']; })());
verificar('el que queda no puede degradarse', falla(fn() => $editar(4, ['nombre' => 'Finanzas', 'rol_id' => $rol('finanzas'), 'extras' => ['equipo']], $f4), 'ultimo_total'));
$l50 = ['id' => 50, 'email' => 'legado@ejemplo.test'];
verificar('un miembro sin ficha con rol admin cuenta como total', (function () use ($editar, $rol, $l50) {
    IO::ejecutar('INSERT INTO io_admins (id,email,nombre,hash,rol) VALUES (50,"legado@ejemplo.test","Legado","x","admin")');
    $editar(4, ['nombre' => 'Finanzas', 'rol_id' => $rol('finanzas')], $l50);
    return IOEquipo::miembro(50)['total'] && !IOEquipo::miembro(4)['total'];
})());
$editar(1, ['nombre' => 'Admin', 'rol_id' => $rol('admin')], $l50);
verificar('con dos totales, uno desactiva al otro y ya no puede degradarse', (function () use ($editar, $rol) {
    $editar(50, ['nombre' => 'Legado', 'rol_id' => $rol('admin'), 'activo' => 0]);
    return IOEquipo::miembro(50) === null && falla(fn() => $editar(1, ['nombre' => 'Admin', 'rol_id' => $rol('editor'), 'extras' => ['equipo']]), 'ultimo_total');
})());

echo "Quien gestiona sin ser total no escala privilegios\n";
verificar('no se asigna a sí mismo el rol total', falla(fn() => $editar(3, ['nombre' => 'Soporte', 'rol_id' => $rol('admin')], $sop), 'no_total'));
verificar('no da de alta a alguien con rol total', falla(fn() => IOEquipo::crearMiembro($sop, ['email' => 'x2@ejemplo.test', 'rol_id' => $rol('admin')] + $alta, 'Alta de personal'), 'no_total'));
verificar('no reparte permisos que no tiene (extras)', falla(fn() => $editar($ana, ['nombre' => 'Ana López', 'rol_id' => $rol('lectura'), 'extras' => ['facturas']], $sop), 'no_total'));
verificar('no se da a sí mismo permisos que no tiene', falla(fn() => $editar(3, ['nombre' => 'Soporte', 'rol_id' => $rol('lectura'), 'extras' => ['equipo', 'reembolsos']], $sop), 'no_total'));
verificar('no otorga "Equipo y roles" a quien no lo tenía', falla(fn() => $editar($ana, ['nombre' => 'Ana López', 'rol_id' => $rol('lectura'), 'extras' => ['equipo']], $sop), 'no_total'));
verificar('no asigna un rol con permisos que no tiene', falla(fn() => $editar($ana, ['nombre' => 'Ana López', 'rol_id' => $rol('finanzas')], $sop), 'no_total'));
verificar('no cambia la contraseña de un total', falla(fn() => IOEquipo::restablecerClave($sop, 1, 'una-clave-segura-77', 'Seguridad de la cuenta'), 'no_total'));
verificar('no edita a un total', falla(fn() => $editar(1, ['nombre' => 'Hackeado', 'rol_id' => $rol('admin')], $sop), 'no_total'));
verificar('no edita el rol total', falla(fn() => IOEquipo::guardarRol($sop, $rol('admin'), ['nombre' => 'Otro nombre', 'permisos' => []], 'Otro'), 'no_total'));
verificar('no crea roles con permisos que no tiene', falla(fn() => IOEquipo::guardarRol($sop, 0, ['nombre' => 'Caja', 'permisos' => ['reembolsos']], 'Otro'), 'no_total'));
verificar('sí gestiona dentro de sus propios permisos', (function () use ($editar, $rol, $ana, $sop) { $editar($ana, ['nombre' => 'Ana López', 'rol_id' => $rol('lectura')], $sop); return IOEquipo::miembro($ana)['permisos'] === ['ver']; })());
verificar('contraseña de más de 72 bytes rechazada', falla(fn() => IOEquipo::validarClave(str_repeat('a', 73)), 'clave'));

echo "Desactivar cierra sesiones\n";
$v = IOEquipo::registrarAcceso($ana);
verificar('registrarAcceso guarda el último acceso', IO::uno('SELECT ultimo_acceso FROM io_equipo WHERE admin_id = ?', [$ana])['ultimo_acceso'] !== null && $v === 0);
$d0 = bitacora('equipo_desactivar');
$editar($ana, ['nombre' => 'Ana López', 'rol_id' => $rol('lectura'), 'activo' => 0]);
verificar('desactivado: ya no es miembro activo', IOEquipo::miembro($ana) === null);
verificar('desactivado: su versión de sesión sube (la sesión previa queda inválida)', (int) IO::uno('SELECT sesion_version FROM io_equipo WHERE admin_id = ?', [$ana])['sesion_version'] === $v + 1);
verificar('desactivado: registrado como equipo_desactivar', bitacora('equipo_desactivar') === $d0 + 1);
$editar($ana, ['nombre' => 'Ana López', 'rol_id' => $rol('lectura'), 'activo' => 1]);
verificar('reactivado: la sesión anterior sigue inválida', IOEquipo::miembro($ana)['sesion_version'] !== $v);

echo "Contraseñas\n";
verificar('contraseña que contiene el correo rechazada', falla(fn() => IOEquipo::restablecerClave($dir, $ana, 'mi-correo-es-nueva-2026', 'Seguridad de la cuenta'), 'clave'));
verificar('restablecer la propia desde equipo no se permite', falla(fn() => IOEquipo::restablecerClave($dir, 1, 'cambio-seguro-larga-9', 'Seguridad de la cuenta'), 'propio'));
$v1 = IOEquipo::miembro($ana)['sesion_version'];
IOEquipo::restablecerClave($dir, $ana, 'cambio-seguro-larga-9', 'Seguridad de la cuenta');
verificar('restablecer: hash nuevo y sesiones cerradas', password_verify('cambio-seguro-larga-9', IO::uno('SELECT hash FROM io_admins WHERE id = ?', [$ana])['hash']) && IOEquipo::miembro($ana)['sesion_version'] === $v1 + 1);
$yoAna = ['id' => $ana, 'email' => 'nueva@ejemplo.test'];
verificar('perfil: contraseña actual incorrecta', falla(fn() => IOEquipo::cambiarClavePropia($yoAna, 'mala', 'otra-clave-larga-22', 'Seguridad de la cuenta'), 'clave_actual'));
verificar('perfil: nueva igual a la actual', falla(fn() => IOEquipo::cambiarClavePropia($yoAna, 'cambio-seguro-larga-9', 'cambio-seguro-larga-9', 'Seguridad de la cuenta'), 'clave'));
verificar('perfil: nueva corta', falla(fn() => IOEquipo::cambiarClavePropia($yoAna, 'cambio-seguro-larga-9', 'corta', 'Seguridad de la cuenta'), 'clave'));
$v2 = IOEquipo::cambiarClavePropia($yoAna, 'cambio-seguro-larga-9', 'otra-clave-larga-22', 'Seguridad de la cuenta');
verificar('perfil: cambia la contraseña y devuelve la versión nueva', $v2 === $v1 + 2 && password_verify('otra-clave-larga-22', IO::uno('SELECT hash FROM io_admins WHERE id = ?', [$ana])['hash']));
verificar('perfil: motivo obligatorio', falla(fn() => IOEquipo::cambiarNombrePropio($yoAna, 'Ana', ''), 'motivo'));
IOEquipo::cambiarNombrePropio($yoAna, 'Ana María López', 'Solicitud del interesado');
verificar('perfil: nombre propio cambiado y en bitácora', IOEquipo::miembro($ana)['nombre'] === 'Ana María López' && bitacora('perfil_nombre') === 1);
verificar('perfil: un miembro desactivado no puede cambiar nada', (function () use ($editar, $ana, $rol, $yoAna) {
    $editar($ana, ['nombre' => 'Ana María López', 'rol_id' => $rol('lectura'), 'activo' => 0]);
    return falla(fn() => IOEquipo::cambiarNombrePropio($yoAna, 'Hack', 'Otro'), 'sin_permiso');
})());

echo "Eliminar rol sin miembros\n";
$tmp = IOEquipo::guardarRol($dir, 0, ['nombre' => 'Temporal', 'permisos' => ['reportes']], 'Otro');
IOEquipo::eliminarRol($dir, $tmp, 'Otro');
verificar('rol propio sin miembros se elimina y queda en bitácora', !IO::uno('SELECT id FROM io_roles WHERE id = ?', [$tmp]) && bitacora('rol_eliminar') === 1);

echo "\nResultado: $ok correctas, $fallos fallidas\n";
$pdo->exec('DROP DATABASE IF EXISTS io_prueba_roles');
exit($fallos ? 1 : 0);
