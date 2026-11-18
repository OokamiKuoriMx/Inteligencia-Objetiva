<?php
/**
 * Crea un usuario del backoffice desde la terminal del servidor (no accesible por web).
 *   php bin/crear-admin.php correo@dominio.com "Nombre" admin
 * La contraseña se pide por consola y no queda en el historial.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/IO.php';

[$_, $email, $nombre, $rol] = array_pad($argv, 4, null);
$email = mb_strtolower(trim((string) $email));
$rol = $rol ?: 'admin';
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !$nombre || !in_array($rol, ['admin', 'editor', 'soporte', 'finanzas'], true)) {
    fwrite(STDERR, "Uso: php bin/crear-admin.php correo \"Nombre\" [admin|editor|soporte|finanzas]\n"); exit(1);
}
fwrite(STDOUT, 'Contraseña (mín. 12 caracteres): ');
if (stripos(PHP_OS, 'WIN') === false) { system('stty -echo'); }
$clave = trim((string) fgets(STDIN));
if (stripos(PHP_OS, 'WIN') === false) { system('stty echo'); }
fwrite(STDOUT, "\n");
if (strlen($clave) < 12) { fwrite(STDERR, "La contraseña debe tener al menos 12 caracteres.\n"); exit(1); }

IO::ejecutar('INSERT INTO io_admins (email, nombre, hash, rol) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), hash = VALUES(hash), rol = VALUES(rol), activo = 1',
    [$email, $nombre, password_hash($clave, PASSWORD_DEFAULT), $rol]);
fwrite(STDOUT, "Usuario $email listo con rol $rol.\n");
