<?php
/**
 * Búsqueda de alumnos para el backoffice ("Otorgar acceso").
 * Fuentes: alumnos que ya existen en io_accesos e io_pedidos (distintos por cliente_id) y, si está configurado,
 * el gancho config['buscar_clientes'] que consulta la tabla real de clientes del sitio.
 */
declare(strict_types=1);
require_once __DIR__ . '/IO.php';

final class IOAlumnos
{
    public const MINIMO = 2;
    public const MAXIMO = 10;
    /** Estados en los que un acceso sigue vigente (para avisar de duplicados). */
    private const VIGENTES = ['por_empezar', 'activo', 'en_gracia'];

    public static function hayGancho(): bool
    {
        return is_callable(IO::config()['buscar_clientes'] ?? null);
    }

    /**
     * Devuelve hasta MAXIMO alumnos: [id, email, nombre, accesos, vigentes[], origen].
     * Con menos de MINIMO caracteres devuelve [].
     */
    public static function buscar(string $texto): array
    {
        $texto = trim(preg_replace('/\s+/u', ' ', $texto));
        if (mb_strlen($texto, 'UTF-8') < self::MINIMO) { return []; }
        $texto = mb_substr($texto, 0, 120, 'UTF-8');

        $porId = [];
        // 1) Gancho del sitio (fuente principal cuando existe).
        foreach (self::delGancho($texto) as $c) { $porId[$c['id']] = $c + ['origen' => 'sitio']; }

        // 2) Alumnos conocidos por el módulo.
        $like = '%' . strtr($texto, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
        $filas = IO::todos(
            'SELECT u.cliente_id AS id, MAX(u.email) AS email, MAX(u.nombre) AS nombre
               FROM (SELECT cliente_id, cliente_email AS email, cliente_nombre AS nombre FROM io_accesos
                     UNION SELECT cliente_id, email, nombre FROM io_pedidos) u
              WHERE u.email LIKE ? OR u.nombre LIKE ?
              GROUP BY u.cliente_id
              ORDER BY nombre, email
              LIMIT ' . self::MAXIMO,
            [$like, $like]
        );
        foreach ($filas as $f) {
            $id = (int) $f['id'];
            if ($id <= 0 || isset($porId[$id])) { continue; }
            $porId[$id] = ['id' => $id, 'email' => (string) $f['email'], 'nombre' => (string) ($f['nombre'] ?? ''), 'origen' => 'modulo'];
        }

        $res = array_slice(array_values($porId), 0, self::MAXIMO);
        return self::conAccesos($res);
    }

    /**
     * Confirma en el servidor que el alumno existe en alguna fuente y devuelve sus datos canónicos
     * (no se confía en los campos ocultos del formulario). null si no existe.
     */
    public static function verificar(int $clienteId, string $email): ?array
    {
        $email = trim($email);
        if ($clienteId <= 0 || $email === '') { return null; }
        $f = IO::uno(
            'SELECT u.email, u.nombre FROM (SELECT cliente_id, cliente_email AS email, cliente_nombre AS nombre, actualizado AS fecha FROM io_accesos
                     UNION ALL SELECT cliente_id, email, nombre, creado AS fecha FROM io_pedidos) u
              WHERE u.cliente_id = ? AND u.email = ? ORDER BY u.fecha DESC LIMIT 1',
            [$clienteId, $email]
        );
        if ($f) { return ['id' => $clienteId, 'email' => (string) $f['email'], 'nombre' => (string) ($f['nombre'] ?? '')]; }
        foreach (self::delGancho($email) as $c) {
            if ($c['id'] === $clienteId && strcasecmp($c['email'], $email) === 0) { return $c; }
        }
        return null;
    }

    /** Llama al gancho y normaliza su respuesta. Un gancho que falla no rompe la búsqueda. */
    private static function delGancho(string $texto): array
    {
        $gancho = IO::config()['buscar_clientes'] ?? null;
        if (!is_callable($gancho)) { return []; }
        try {
            $crudo = $gancho($texto);
        } catch (Throwable $e) {
            error_log('[IO buscar_clientes] ' . $e->getMessage());
            return [];
        }
        $res = [];
        foreach (is_iterable($crudo) ? $crudo : [] as $c) {
            if (!is_array($c)) { continue; }
            $id = (int) ($c['id'] ?? $c[0] ?? 0);
            $email = trim((string) ($c['email'] ?? $c[1] ?? ''));
            $nombre = trim((string) ($c['nombre'] ?? $c[2] ?? ''));
            if ($id <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) { continue; }
            $res[] = ['id' => $id, 'email' => $email, 'nombre' => $nombre];
            if (count($res) >= self::MAXIMO) { break; }
        }
        return $res;
    }

    /** Agrega a cada alumno cuántos accesos tiene y qué contenidos tiene vigentes. */
    private static function conAccesos(array $alumnos): array
    {
        if (!$alumnos) { return []; }
        $ids = array_column($alumnos, 'id');
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $filas = IO::todos("SELECT cliente_id, producto_id, estado FROM io_accesos WHERE cliente_id IN ($marcas)", $ids);
        $info = [];
        foreach ($filas as $f) {
            $id = (int) $f['cliente_id'];
            $info[$id]['accesos'] = ($info[$id]['accesos'] ?? 0) + 1;
            if (in_array($f['estado'], self::VIGENTES, true)) { $info[$id]['vigentes'][] = (int) $f['producto_id']; }
        }
        foreach ($alumnos as &$a) {
            $a['accesos'] = $info[$a['id']]['accesos'] ?? 0;
            $a['vigentes'] = array_values(array_unique($info[$a['id']]['vigentes'] ?? []));
        }
        return $alumnos;
    }
}
