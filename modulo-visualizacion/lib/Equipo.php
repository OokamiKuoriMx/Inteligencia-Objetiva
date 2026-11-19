<?php
/**
 * Inteligencia Objetiva 2.0 — Equipo del backoffice: roles, permisos y perfiles.
 *
 * Permisos efectivos de un miembro = permisos de su rol (todos si el rol es total) + sus permisos extra.
 * Un admin sin ficha en io_equipo usa el rol cuya clave coincide con io_admins.rol (comportamiento previo).
 * Toda acción exige motivo y se registra en io_bitacora dentro de la misma transacción.
 * Todas las operaciones que cambian al equipo bloquean primero los roles totales (FOR UPDATE) para que dos cambios
 * simultáneos no puedan dejar el sistema sin administrador total.
 */
declare(strict_types=1);
require_once __DIR__ . '/IO.php';

final class IOEquipo
{
    /** Catálogo de permisos con su explicación en lenguaje claro (orden de presentación). */
    public const PERMISOS = [
        'ver'        => ['Consultar', 'Ver el tablero, los accesos, los contenidos, las transmisiones y las reproducciones en curso.'],
        'accesos'    => ['Accesos de alumnos', 'Otorgar cortesías, extender, reiniciar y revocar accesos, y cerrar reproducciones.'],
        'politicas'  => ['Contenido y promociones', 'Crear y editar contenidos, reglas de visualización, transmisiones, cupones y la conexión con Vimeo y YouTube.'],
        'pedidos'    => ['Pedidos', 'Consultar los pedidos de la tienda y su detalle.'],
        'reembolsos' => ['Devoluciones', 'Solicitar y conciliar devoluciones de pagos en Stripe.'],
        'facturas'   => ['Cancelar facturas', 'Solicitar ante el SAT la cancelación de una factura (CFDI).'],
        'reportes'   => ['Reportes', 'Ver los reportes de ventas y de visualización.'],
        'bitacora'   => ['Bitácora', 'Consultar el registro de acciones del equipo.'],
        'equipo'     => ['Equipo y roles', 'Dar de alta, editar y desactivar miembros del equipo, y definir qué puede hacer cada rol.'],
    ];

    public const MOTIVOS = ['Alta de personal', 'Cambio de funciones', 'Baja o ausencia', 'Seguridad de la cuenta', 'Solicitud del interesado', 'Otro'];

    /** Permisos de cada rol antes de esta migración, por si la tabla io_roles aún no existe. */
    private const HEREDADOS = [
        'admin'    => null,
        'editor'   => ['ver', 'politicas', 'reportes'],
        'soporte'  => ['ver', 'accesos', 'pedidos'],
        'finanzas' => ['ver', 'reportes', 'bitacora', 'pedidos', 'reembolsos'],
    ];

    public const CLAVE_MIN = 12;

    /** Miembro activo con su rol y permisos efectivos, o null si no existe o está desactivado. */
    public static function miembro(int $adminId): ?array
    {
        if ($adminId <= 0) { return null; }
        try {
            $u = IO::uno('SELECT a.id, a.email, a.nombre, a.rol, a.activo, e.ultimo_acceso, COALESCE(e.sesion_version, 0) sesion_version,
                    r.id rol_id, r.clave rol_clave, r.nombre rol_nombre, COALESCE(r.total, 0) total
                FROM io_admins a
                LEFT JOIN io_equipo e ON e.admin_id = a.id
                LEFT JOIN io_roles r ON r.id = COALESCE(e.rol_id, (SELECT r2.id FROM io_roles r2 WHERE r2.clave = a.rol))
                WHERE a.id = ? AND a.activo = 1', [$adminId]);
        } catch (PDOException $e) {
            // Solo si faltan las tablas de la migración 010; cualquier otro error (bloqueo, conexión) se propaga.
            if (!self::faltaTabla($e)) { throw $e; }
            return self::miembroHeredado($adminId);
        }
        if (!$u) { return null; }
        $u['id'] = (int) $u['id'];
        $u['total'] = (bool) $u['total'];
        $u['sesion_version'] = (int) $u['sesion_version'];
        $u['permisos'] = self::calcular($u['total'], $u['rol_id'] ? (int) $u['rol_id'] : null, $u['id']);
        return $u;
    }

    /** Respaldo si la migración 010 aún no se aplicó: mismos permisos que antes, sin poder gestionar el equipo. */
    private static function miembroHeredado(int $adminId): ?array
    {
        $u = IO::uno('SELECT id, email, nombre, rol FROM io_admins WHERE id = ? AND activo = 1', [$adminId]);
        if (!$u) { return null; }
        $total = $u['rol'] === 'admin';
        $permisos = $total ? array_values(array_diff(array_keys(self::PERMISOS), ['equipo'])) : (self::HEREDADOS[$u['rol']] ?? ['ver']);
        return ['id' => (int) $u['id'], 'email' => $u['email'], 'nombre' => $u['nombre'], 'rol' => $u['rol'], 'activo' => 1, 'ultimo_acceso' => null,
            'sesion_version' => 0, 'rol_id' => null, 'rol_clave' => $u['rol'], 'rol_nombre' => ucfirst((string) $u['rol']), 'total' => $total, 'permisos' => $permisos];
    }

    private static function calcular(bool $total, ?int $rolId, int $adminId): array
    {
        if ($total) { return array_keys(self::PERMISOS); }
        $p = [];
        if ($rolId) { foreach (IO::todos('SELECT permiso FROM io_roles_permisos WHERE rol_id = ?', [$rolId]) as $r) { $p[] = $r['permiso']; } }
        foreach (IO::todos('SELECT permiso FROM io_equipo_permisos WHERE admin_id = ?', [$adminId]) as $r) { $p[] = $r['permiso']; }
        $p[] = 'ver';
        return array_values(array_intersect(array_keys(self::PERMISOS), $p));
    }

    public static function tiene(?array $miembro, string $permiso): bool
    {
        return $miembro !== null && in_array($permiso, $miembro['permisos'] ?? [], true);
    }

    /** Para las librerías: revalida al actor contra la base y exige un permiso. Devuelve id, email, nombre y rol. */
    public static function autorizar(array $actor, string $permiso, string $mensaje = 'Sin permiso para esta acción.'): array
    {
        $m = self::miembro((int) ($actor['id'] ?? 0));
        if (!self::tiene($m, $permiso)) { throw new IOError('sin_permiso', $mensaje, 403); }
        return $m;
    }

    /* ---------- Lecturas para la página de equipo ---------- */

    public static function lista(): array
    {
        $filas = IO::todos('SELECT a.id, a.email, a.nombre, a.activo, a.creado, e.ultimo_acceso, r.id rol_id, r.nombre rol_nombre, COALESCE(r.total,0) total
            FROM io_admins a
            LEFT JOIN io_equipo e ON e.admin_id = a.id
            LEFT JOIN io_roles r ON r.id = COALESCE(e.rol_id, (SELECT r2.id FROM io_roles r2 WHERE r2.clave = a.rol))
            ORDER BY a.activo DESC, a.nombre');
        $extras = [];
        foreach (IO::todos('SELECT admin_id, permiso FROM io_equipo_permisos') as $x) { $extras[(int) $x['admin_id']][] = $x['permiso']; }
        foreach ($filas as &$f) {
            $f['id'] = (int) $f['id'];
            $f['extras'] = array_values(array_intersect(array_keys(self::PERMISOS), $extras[$f['id']] ?? []));
        }
        return $filas;
    }

    public static function roles(): array
    {
        $roles = IO::todos('SELECT r.*, (SELECT COUNT(*) FROM io_admins a LEFT JOIN io_equipo e ON e.admin_id = a.id
                WHERE COALESCE(e.rol_id, (SELECT r2.id FROM io_roles r2 WHERE r2.clave = a.rol)) = r.id AND a.activo = 1) miembros
            FROM io_roles r ORDER BY r.total DESC, r.sistema DESC, r.nombre');
        $permisos = [];
        foreach (IO::todos('SELECT rol_id, permiso FROM io_roles_permisos') as $p) { $permisos[(int) $p['rol_id']][] = $p['permiso']; }
        foreach ($roles as &$r) {
            $r['id'] = (int) $r['id'];
            $r['total'] = (bool) $r['total'];
            $r['permisos'] = $r['total'] ? array_keys(self::PERMISOS) : array_values(array_intersect(array_keys(self::PERMISOS), array_merge(['ver'], $permisos[$r['id']] ?? [])));
        }
        return $roles;
    }

    /* ---------- Sesiones ---------- */

    /** Registra el inicio de sesión y devuelve la versión de sesión vigente para guardarla en la sesión PHP. */
    public static function registrarAcceso(int $adminId): int
    {
        try {
            IO::ejecutar('INSERT INTO io_equipo (admin_id, rol_id, ultimo_acceso)
                SELECT a.id, (SELECT r.id FROM io_roles r WHERE r.clave = a.rol), NOW() FROM io_admins a WHERE a.id = ?
                ON DUPLICATE KEY UPDATE ultimo_acceso = NOW()', [$adminId]);
            return (int) (IO::uno('SELECT sesion_version FROM io_equipo WHERE admin_id = ?', [$adminId])['sesion_version'] ?? 0);
        } catch (PDOException $e) {
            if (!self::faltaTabla($e)) { throw $e; }
            return 0;
        }
    }

    /* ---------- Acciones (todas con motivo y bitácora en la misma transacción) ---------- */

    private static function motivo(string $motivo): string
    {
        $motivo = trim($motivo);
        if ($motivo === '') { throw new IOError('motivo', 'El motivo es obligatorio.'); }
        if (mb_strlen($motivo, 'UTF-8') > 120) { throw new IOError('motivo', 'El motivo admite máximo 120 caracteres.'); }
        return $motivo;
    }

    private static function registrar(array $actor, string $accion, string $motivo, array $detalle): void
    {
        IO::ejecutar('INSERT INTO io_bitacora (admin_id, admin_email, accion, motivo, detalle) VALUES (?,?,?,?,?)',
            [$actor['id'], $actor['email'], $accion, $motivo, json_encode($detalle, JSON_UNESCAPED_UNICODE)]);
    }

    /** Bloquea los roles totales (serializa los cambios de equipo) y revalida al actor con permiso "equipo". */
    private static function gestor(array $actor): array
    {
        IO::todos('SELECT id FROM io_roles WHERE total = 1 FOR UPDATE');
        return self::autorizar($actor, 'equipo', 'Solo quien tiene el permiso “Equipo y roles” puede hacer este cambio.');
    }

    private static function nombre(string $nombre): string
    {
        $nombre = trim(preg_replace('/\s+/u', ' ', $nombre) ?? '');
        if ($nombre === '' || mb_strlen($nombre, 'UTF-8') > 120) { throw new IOError('nombre', 'Escribe un nombre de 1 a 120 caracteres.'); }
        return $nombre;
    }

    public static function validarClave(string $clave, string $email = ''): void
    {
        if (mb_strlen($clave, 'UTF-8') < self::CLAVE_MIN) { throw new IOError('clave', 'La contraseña debe tener al menos ' . self::CLAVE_MIN . ' caracteres.'); }
        // bcrypt (PASSWORD_DEFAULT) solo considera los primeros 72 bytes.
        if (strlen($clave) > 72) { throw new IOError('clave', 'La contraseña admite máximo 72 caracteres.'); }
        if ($email !== '' && stripos($clave, (string) strstr($email, '@', true)) !== false && strlen((string) strstr($email, '@', true)) >= 4) {
            throw new IOError('clave', 'La contraseña no debe contener tu correo.');
        }
    }

    private static function permisosValidos($lista): array
    {
        $lista = is_array($lista) ? array_map('strval', $lista) : [];
        return array_values(array_intersect(array_keys(self::PERMISOS), $lista));
    }

    private static function rol(int $rolId, bool $bloquear = false): array
    {
        $r = IO::uno('SELECT * FROM io_roles WHERE id = ?' . ($bloquear ? ' FOR UPDATE' : ''), [$rolId]);
        if (!$r) { throw new IOError('rol', 'El rol elegido no existe.'); }
        $r['id'] = (int) $r['id']; $r['total'] = (bool) $r['total'];
        return $r;
    }

    /** io_admins.rol se mantiene alineado para el código y los scripts que aún lo leen (menor privilegio si no hay equivalente). */
    private static function rolHeredado(array $rol): string
    {
        return in_array($rol['clave'], ['admin', 'editor', 'soporte', 'finanzas'], true) ? $rol['clave'] : 'soporte';
    }

    /** Administradores totales activos (opcionalmente sin contar a uno). */
    private static function totalesActivos(int $excepto = 0): int
    {
        return (int) IO::uno('SELECT COUNT(*) n FROM io_admins a LEFT JOIN io_equipo e ON e.admin_id = a.id
            JOIN io_roles r ON r.id = COALESCE(e.rol_id, (SELECT r2.id FROM io_roles r2 WHERE r2.clave = a.rol))
            WHERE a.activo = 1 AND r.total = 1 AND a.id <> ?', [$excepto])['n'];
    }

    private static function guardarExtras(int $adminId, array $extras): void
    {
        IO::ejecutar('DELETE FROM io_equipo_permisos WHERE admin_id = ?', [$adminId]);
        foreach ($extras as $p) { IO::ejecutar('INSERT INTO io_equipo_permisos (admin_id, permiso) VALUES (?,?)', [$adminId, $p]); }
    }

    public static function crearMiembro(array $actor, array $d, string $motivo): int
    {
        $motivo = self::motivo($motivo);
        $email = mb_strtolower(trim((string) ($d['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) { throw new IOError('email', 'Escribe un correo válido.'); }
        $nombre = self::nombre((string) ($d['nombre'] ?? ''));
        $clave = (string) ($d['clave'] ?? '');
        self::validarClave($clave, $email);
        $extras = self::permisosValidos($d['extras'] ?? []);
        return IO::transaccion(function () use ($actor, $email, $nombre, $clave, $extras, $d, $motivo) {
            $g = self::gestor($actor);
            $rol = self::rol((int) ($d['rol_id'] ?? 0));
            if ($rol['total']) { $extras = []; }
            self::limitarNoTotal($g, $rol, self::efectivos($rol, $extras), ['ver'], false);
            if (IO::uno('SELECT id FROM io_admins WHERE email = ?', [$email])) { throw new IOError('email', 'Ya existe un miembro con ese correo.'); }
            IO::ejecutar('INSERT INTO io_admins (email, nombre, hash, rol, activo) VALUES (?,?,?,?,1)',
                [$email, $nombre, password_hash($clave, PASSWORD_DEFAULT), self::rolHeredado($rol)]);
            $id = (int) IO::db()->lastInsertId();
            IO::ejecutar('INSERT INTO io_equipo (admin_id, rol_id) VALUES (?,?)', [$id, $rol['id']]);
            self::guardarExtras($id, $extras);
            self::registrar($g, 'equipo_alta', $motivo, ['miembro' => $id, 'email' => $email, 'rol' => $rol['nombre'], 'extras' => $extras]);
            return $id;
        });
    }

    /** Edita nombre, rol, permisos extra y estado de un miembro. Desactivar cierra sus sesiones. */
    public static function editarMiembro(array $actor, int $id, array $d, string $motivo): void
    {
        $motivo = self::motivo($motivo);
        $nombre = self::nombre((string) ($d['nombre'] ?? ''));
        $extras = self::permisosValidos($d['extras'] ?? []);
        $activo = !empty($d['activo']);
        IO::transaccion(function () use ($actor, $id, $d, $nombre, $extras, $activo, $motivo) {
            $g = self::gestor($actor);
            $antes = IO::uno('SELECT id, email, nombre, activo FROM io_admins WHERE id = ? FOR UPDATE', [$id]);
            if (!$antes) { throw new IOError('miembro', 'El miembro no existe.', 404); }
            $rol = self::rol((int) ($d['rol_id'] ?? 0));
            if ($rol['total']) { $extras = []; }
            $previo = self::miembroCualquiera($id);
            self::limitarNoTotal($g, $rol, self::efectivos($rol, $extras), $previo['permisos'], $previo['total']);
            $esYo = $id === (int) $g['id'];
            if ($esYo && !$activo) { throw new IOError('propio', 'No puedes desactivar tu propia cuenta.'); }
            if ($esYo && !$rol['total'] && !in_array('equipo', self::permisosDeRol($rol['id']), true) && !in_array('equipo', $extras, true)) {
                throw new IOError('propio', 'No puedes quitarte a ti mismo el permiso “Equipo y roles”.');
            }
            if ($previo['total'] && $previo['activo'] && !($rol['total'] && $activo) && self::totalesActivos($id) === 0) {
                throw new IOError('ultimo_total', 'Debe quedar al menos un administrador total activo.');
            }
            IO::ejecutar('UPDATE io_admins SET nombre = ?, rol = ?, activo = ? WHERE id = ?', [$nombre, self::rolHeredado($rol), $activo ? 1 : 0, $id]);
            $cerrar = (int) $antes['activo'] === 1 && !$activo;
            IO::ejecutar('INSERT INTO io_equipo (admin_id, rol_id, sesion_version) VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE rol_id = ?, sesion_version = sesion_version + ?', [$id, $rol['id'], $cerrar ? 1 : 0, $rol['id'], $cerrar ? 1 : 0]);
            self::guardarExtras($id, $extras);
            self::registrar($g, $cerrar ? 'equipo_desactivar' : 'equipo_editar', $motivo, [
                'miembro' => $id, 'email' => $antes['email'],
                'antes' => ['nombre' => $antes['nombre'], 'rol' => $previo['rol_nombre'], 'extras' => $previo['extras'], 'activo' => (bool) $antes['activo']],
                'despues' => ['nombre' => $nombre, 'rol' => $rol['nombre'], 'extras' => $extras, 'activo' => $activo],
            ]);
        });
    }

    /** Otro miembro: asigna una contraseña nueva y cierra sus sesiones. */
    public static function restablecerClave(array $actor, int $id, string $clave, string $motivo): void
    {
        $motivo = self::motivo($motivo);
        IO::transaccion(function () use ($actor, $id, $clave, $motivo) {
            $g = self::gestor($actor);
            if ($id === (int) $g['id']) { throw new IOError('propio', 'Cambia tu propia contraseña desde “Mi perfil”.'); }
            $u = IO::uno('SELECT id, email FROM io_admins WHERE id = ? FOR UPDATE', [$id]);
            if (!$u) { throw new IOError('miembro', 'El miembro no existe.', 404); }
            self::limitarNoTotal($g, null, [], [], self::miembroCualquiera($id)['total']);
            self::validarClave($clave, (string) $u['email']);
            IO::ejecutar('UPDATE io_admins SET hash = ? WHERE id = ?', [password_hash($clave, PASSWORD_DEFAULT), $id]);
            self::subirVersion($id);
            self::registrar($g, 'equipo_clave', $motivo, ['miembro' => $id, 'email' => $u['email']]);
        });
    }

    /** Crea o edita un rol. Los permisos de un rol total no se editan (siempre son todos). */
    public static function guardarRol(array $actor, int $id, array $d, string $motivo): int
    {
        $motivo = self::motivo($motivo);
        $nombre = trim(preg_replace('/\s+/u', ' ', (string) ($d['nombre'] ?? '')) ?? '');
        if ($nombre === '' || mb_strlen($nombre, 'UTF-8') > 80) { throw new IOError('nombre', 'Escribe un nombre de rol de 1 a 80 caracteres.'); }
        $descripcion = trim((string) ($d['descripcion'] ?? ''));
        if (mb_strlen($descripcion, 'UTF-8') > 255) { throw new IOError('descripcion', 'La descripción admite máximo 255 caracteres.'); }
        $permisos = array_values(array_unique(array_merge(['ver'], self::permisosValidos($d['permisos'] ?? []))));
        return IO::transaccion(function () use ($actor, $id, $nombre, $descripcion, $permisos, $motivo) {
            $g = self::gestor($actor);
            $otro = IO::uno('SELECT id FROM io_roles WHERE nombre = ? AND id <> ?', [$nombre, $id]);
            if ($otro) { throw new IOError('nombre', 'Ya existe un rol con ese nombre.'); }
            if ($id > 0) {
                $rol = self::rol($id, true);
                $antes = self::permisosDeRol($id);
                self::limitarNoTotal($g, $rol, $permisos, $antes, false);
                if ($rol['total']) { $permisos = array_keys(self::PERMISOS); }
                elseif (!in_array('equipo', $permisos, true) && !$g['total'] && (int) $g['rol_id'] === $id
                    && !in_array('equipo', self::extrasDe((int) $g['id']), true)) {
                    throw new IOError('propio', 'No puedes quitarle “Equipo y roles” a tu propio rol: perderías el acceso a esta sección.');
                }
                IO::ejecutar('UPDATE io_roles SET nombre = ?, descripcion = ? WHERE id = ?', [$nombre, $descripcion, $id]);
                if (!$rol['total']) {
                    IO::ejecutar('DELETE FROM io_roles_permisos WHERE rol_id = ?', [$id]);
                    foreach ($permisos as $p) { IO::ejecutar('INSERT INTO io_roles_permisos (rol_id, permiso) VALUES (?,?)', [$id, $p]); }
                }
                self::registrar($g, 'rol_editar', $motivo, ['rol' => $id, 'antes' => ['nombre' => $rol['nombre'], 'permisos' => $antes],
                    'despues' => ['nombre' => $nombre, 'permisos' => $permisos]]);
                return $id;
            }
            self::limitarNoTotal($g, null, $permisos, ['ver'], false);
            $clave = self::claveUnica($nombre);
            IO::ejecutar('INSERT INTO io_roles (clave, nombre, descripcion, total, sistema) VALUES (?,?,?,0,0)', [$clave, $nombre, $descripcion]);
            $nuevo = (int) IO::db()->lastInsertId();
            foreach ($permisos as $p) { IO::ejecutar('INSERT INTO io_roles_permisos (rol_id, permiso) VALUES (?,?)', [$nuevo, $p]); }
            self::registrar($g, 'rol_alta', $motivo, ['rol' => $nuevo, 'nombre' => $nombre, 'permisos' => $permisos]);
            return $nuevo;
        });
    }

    /** Elimina un rol creado por el equipo que ya no tenga miembros. */
    public static function eliminarRol(array $actor, int $id, string $motivo): void
    {
        $motivo = self::motivo($motivo);
        IO::transaccion(function () use ($actor, $id, $motivo) {
            $g = self::gestor($actor);
            $rol = self::rol($id, true);
            if ($rol['sistema'] || $rol['total']) { throw new IOError('rol', 'Los roles base no se eliminan; puedes cambiar sus permisos.'); }
            $n = (int) IO::uno('SELECT COUNT(*) n FROM io_equipo WHERE rol_id = ?', [$id])['n'];
            if ($n > 0) { throw new IOError('rol', 'El rol todavía tiene miembros asignados. Cámbialos de rol primero.'); }
            $permisos = self::permisosDeRol($id);
            IO::ejecutar('DELETE FROM io_roles WHERE id = ?', [$id]);
            self::registrar($g, 'rol_eliminar', $motivo, ['rol' => $id, 'nombre' => $rol['nombre'], 'permisos' => $permisos]);
        });
    }

    /* ---------- Perfil propio (cualquier miembro activo) ---------- */

    public static function cambiarNombrePropio(array $actor, string $nombre, string $motivo): void
    {
        $motivo = self::motivo($motivo);
        $nombre = self::nombre($nombre);
        IO::transaccion(function () use ($actor, $nombre, $motivo) {
            $m = self::autorizar($actor, 'ver');
            $antes = IO::uno('SELECT nombre FROM io_admins WHERE id = ? FOR UPDATE', [$m['id']]);
            IO::ejecutar('UPDATE io_admins SET nombre = ? WHERE id = ?', [$nombre, $m['id']]);
            self::registrar($m, 'perfil_nombre', $motivo, ['antes' => $antes['nombre'] ?? '', 'despues' => $nombre]);
        });
    }

    /** Cambia la propia contraseña verificando la actual. Cierra las demás sesiones y devuelve la versión nueva para esta. */
    public static function cambiarClavePropia(array $actor, string $actual, string $nueva, string $motivo): int
    {
        $motivo = self::motivo($motivo);
        return IO::transaccion(function () use ($actor, $actual, $nueva, $motivo) {
            $m = self::autorizar($actor, 'ver');
            $u = IO::uno('SELECT hash, email FROM io_admins WHERE id = ? FOR UPDATE', [$m['id']]);
            if (!$u || !password_verify($actual, (string) $u['hash'])) { throw new IOError('clave_actual', 'La contraseña actual no es correcta.'); }
            if (hash_equals($actual, $nueva)) { throw new IOError('clave', 'La contraseña nueva debe ser distinta de la actual.'); }
            self::validarClave($nueva, (string) $u['email']);
            IO::ejecutar('UPDATE io_admins SET hash = ? WHERE id = ?', [password_hash($nueva, PASSWORD_DEFAULT), $m['id']]);
            $v = self::subirVersion((int) $m['id']);
            self::registrar($m, 'perfil_clave', $motivo, ['sesiones' => 'Se cerraron las demás sesiones']);
            return $v;
        });
    }

    /* ---------- Auxiliares ---------- */

    private static function faltaTabla(PDOException $e): bool
    {
        return (string) $e->getCode() === '42S02' || (string) ($e->errorInfo[0] ?? '') === '42S02';
    }

    /**
     * Límites de quien gestiona sin ser administrador total: no toca el rol total ni a sus miembros,
     * no reparte permisos que él mismo no tiene y no otorga "Equipo y roles" a quien no lo tenía.
     */
    private static function limitarNoTotal(array $g, ?array $rol, array $nuevos, array $previos, bool $objetivoTotal): void
    {
        if ($g['total']) { return; }
        if ($objetivoTotal) { throw new IOError('no_total', 'Solo la dirección puede modificar a un administrador total.', 403); }
        if ($rol && $rol['total']) { throw new IOError('no_total', 'Solo la dirección puede asignar o editar el rol de administrador total.', 403); }
        $ajenos = array_diff($nuevos, $g['permisos']);
        if ($ajenos) {
            throw new IOError('no_total', 'No puedes otorgar permisos que tú no tienes: ' . implode(', ', array_map(fn($p) => self::PERMISOS[$p][0], $ajenos)) . '.', 403);
        }
        if (in_array('equipo', $nuevos, true) && !in_array('equipo', $previos, true)) {
            throw new IOError('no_total', 'Solo la dirección puede otorgar el permiso “Equipo y roles”.', 403);
        }
    }

    private static function efectivos(array $rol, array $extras): array
    {
        if ($rol['total']) { return array_keys(self::PERMISOS); }
        return array_values(array_intersect(array_keys(self::PERMISOS), array_merge(self::permisosDeRol($rol['id']), $extras)));
    }

    private static function subirVersion(int $id): int
    {
        IO::ejecutar('INSERT INTO io_equipo (admin_id, rol_id, sesion_version)
            SELECT a.id, (SELECT r.id FROM io_roles r WHERE r.clave = a.rol), 1 FROM io_admins a WHERE a.id = ?
            ON DUPLICATE KEY UPDATE sesion_version = sesion_version + 1', [$id]);
        return (int) IO::uno('SELECT sesion_version FROM io_equipo WHERE admin_id = ?', [$id])['sesion_version'];
    }

    private static function permisosDeRol(int $rolId): array
    {
        $r = IO::uno('SELECT total FROM io_roles WHERE id = ?', [$rolId]);
        if ($r && (int) $r['total'] === 1) { return array_keys(self::PERMISOS); }
        return array_values(array_intersect(array_keys(self::PERMISOS),
            array_merge(['ver'], array_column(IO::todos('SELECT permiso FROM io_roles_permisos WHERE rol_id = ?', [$rolId]), 'permiso'))));
    }

    private static function extrasDe(int $adminId): array
    {
        return array_column(IO::todos('SELECT permiso FROM io_equipo_permisos WHERE admin_id = ?', [$adminId]), 'permiso');
    }

    /** Estado de un miembro activo o inactivo (para comparar antes y después). */
    private static function miembroCualquiera(int $id): array
    {
        $u = IO::uno('SELECT a.activo, r.id rol_id, r.nombre rol_nombre, COALESCE(r.total,0) total FROM io_admins a LEFT JOIN io_equipo e ON e.admin_id = a.id
            LEFT JOIN io_roles r ON r.id = COALESCE(e.rol_id, (SELECT r2.id FROM io_roles r2 WHERE r2.clave = a.rol)) WHERE a.id = ?', [$id]);
        $total = (int) ($u['total'] ?? 0) === 1;
        $extras = self::extrasDe($id);
        $permisos = $total ? array_keys(self::PERMISOS) : array_values(array_intersect(array_keys(self::PERMISOS),
            array_merge(!empty($u['rol_id']) ? self::permisosDeRol((int) $u['rol_id']) : ['ver'], $extras)));
        return ['activo' => (int) ($u['activo'] ?? 0) === 1, 'total' => $total, 'rol_nombre' => $u['rol_nombre'] ?? null, 'extras' => $extras, 'permisos' => $permisos];
    }

    private static function claveUnica(string $nombre): string
    {
        $ascii = strtr(mb_strtolower($nombre, 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', $ascii), '-');
        $base = substr($base !== '' ? $base : 'rol', 0, 32);
        $clave = $base; $n = 2;
        while (IO::uno('SELECT id FROM io_roles WHERE clave = ?', [$clave])) { $clave = $base . '-' . $n++; }
        return $clave;
    }
}
