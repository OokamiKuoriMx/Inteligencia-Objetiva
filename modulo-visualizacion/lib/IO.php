<?php
/**
 * Inteligencia Objetiva 2.0 — Núcleo del módulo de visualización.
 * PHP 7.4+ / 8.x, PDO MySQL 5.7 / 8.0. Sin frameworks ni Composer, para integrarse al sitio actual.
 *
 * Toda transición de estado de un acceso ocurre en una transacción que bloquea primero la fila
 * del acceso (SELECT ... FOR UPDATE) y después la de la sesión, para evitar condiciones de carrera.
 */
declare(strict_types=1);

final class IO
{
    private static ?array $config = null;
    private static ?PDO $pdo = null;

    public static function config(): array
    {
        if (self::$config === null) {
            $archivo = __DIR__ . '/../config.php';
            if (!is_file($archivo)) {
                throw new RuntimeException('Falta modulo-visualizacion/config.php (copia config.example.php).');
            }
            self::$config = require $archivo;
            date_default_timezone_set(self::$config['zona_horaria'] ?? 'America/Mexico_City');
        }
        return self::$config;
    }

    public static function db(): PDO
    {
        if (self::$pdo === null) {
            $c = self::config()['db'];
            self::$pdo = new PDO($c['dsn'], $c['usuario'], $c['clave'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            // Misma zona horaria en PHP y MySQL (NOW(), DATE_ADD...).
            self::$pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
        }
        return self::$pdo;
    }

    /** Para pruebas: inyectar una conexión. */
    public static function usarConexion(PDO $pdo, array $config): void
    {
        self::$pdo = $pdo;
        self::$config = $config;
    }

    public static function dev(): bool { return !empty(self::config()['dev']); }

    public static function clienteActual(): ?array
    {
        $fn = self::config()['cliente_actual'] ?? null;
        return is_callable($fn) ? $fn() : null;
    }

    public static function ahora(): string { return date('Y-m-d H:i:s'); }

    public static function uno(string $sql, array $p = []): ?array
    {
        $st = self::db()->prepare($sql);
        $st->execute($p);
        $r = $st->fetch();
        return $r === false ? null : $r;
    }

    public static function todos(string $sql, array $p = []): array
    {
        $st = self::db()->prepare($sql);
        $st->execute($p);
        return $st->fetchAll();
    }

    public static function ejecutar(string $sql, array $p = []): int
    {
        $st = self::db()->prepare($sql);
        $st->execute($p);
        return $st->rowCount();
    }

    /**
     * Ejecuta $fn en una transacción (o dentro de la actual si ya hay una).
     * Un IOError con confirmar=true guarda los cambios hechos antes de lanzarse (p. ej. marcar "visto").
     */
    public static function transaccion(callable $fn)
    {
        $db = self::db();
        if ($db->inTransaction()) { return $fn(); }
        $db->beginTransaction();
        try {
            $r = $fn();
            $db->commit();
            return $r;
        } catch (IOError $e) {
            $e->confirmar ? $db->commit() : $db->rollBack();
            throw $e;
        } catch (Throwable $e) {
            if ($db->inTransaction()) { $db->rollBack(); }
            throw $e;
        }
    }

    /** UPDATE con columnas de una lista blanca fija. */
    public static function actualizar(string $tabla, array $set, string $where, array $params): void
    {
        static $permitidas = [
            'io_accesos' => ['estado', 'inicio', 'vence', 'max_seg', 'seg_vistos', 'reanudaciones', 'gracia_hasta', 'visto_en', 'limite_inicio'],
        ];
        if (!$set) { return; }
        foreach (array_keys($set) as $c) {
            if (!in_array($c, $permitidas[$tabla] ?? [], true)) { throw new LogicException("Columna no permitida: $tabla.$c"); }
        }
        $cols = implode(', ', array_map(fn($k) => "$k = ?", array_keys($set)));
        self::ejecutar("UPDATE $tabla SET $cols WHERE $where", array_merge(array_values($set), $params));
    }
}

final class IOError extends RuntimeException
{
    public string $codigo;
    public bool $confirmar;
    public function __construct(string $codigo, string $mensaje, int $http = 400, bool $confirmar = false)
    {
        parent::__construct($mensaje, $http);
        $this->codigo = $codigo;
        $this->confirmar = $confirmar;
    }
}

/**
 * Reglas de acceso y de visualización única.
 * Estados: por_empezar → activo ⇄ en_gracia → visto  |  por_empezar → vencido  |  cualquiera → revocado
 */
final class IOAccesos
{
    /** Segundos sin latido para considerar que la reproducción se cortó. */
    public const SIN_LATIDO_SEG = 60;
    /** Intervalo mínimo entre latidos que acreditan avance (frena el envío masivo de latidos). */
    public const LATIDO_MIN_SEG = 10;
    /** Avance máximo acreditado por segundo real (permite reproducir hasta 1.25x). */
    public const VELOCIDAD_MAX = 1.25;
    /** Holgura fija por latido para desfases de red entre la posición del cliente y el reloj del servidor. */
    public const HOLGURA_SEG = 2;

    public static function politica(int $productoId): array
    {
        $p = IO::uno('SELECT * FROM io_politicas WHERE producto_id = ?', [$productoId]);
        if (!$p) {
            throw new IOError('sin_politica', 'Este producto aún no tiene política de acceso configurada.', 404);
        }
        return $p;
    }

    /**
     * Crear el acceso cuando se confirma un pago. Llamar desde el flujo de compra actual:
     *   IOAccesos::otorgar($idCliente, $correo, $nombre, $idProducto, 'compra', $folioPedido);
     */
    public static function otorgar(int $clienteId, string $email, string $nombre, int $productoId, string $origen = 'compra', ?string $pedidoRef = null): int
    {
        $p = self::politica($productoId);
        $ahora = new DateTimeImmutable();
        $inicio = null; $vence = null; $estado = 'por_empezar';
        $limite = $ahora->modify('+' . (int) $p['dias_para_empezar'] . ' days');

        if ($p['tipo'] === 'permanente') {
            $estado = 'activo'; $inicio = $ahora; $limite = null;
        } elseif ($p['tipo'] === 'ventana' && $p['cuenta_desde'] === 'compra') {
            $estado = 'activo'; $inicio = $ahora; $limite = null;
            $vence = $ahora->modify('+' . (int) $p['horas_ventana'] . ' hours');
        }

        IO::ejecutar(
            'INSERT INTO io_accesos (cliente_id, cliente_email, cliente_nombre, producto_id, pedido_ref, origen, estado, limite_inicio, inicio, vence)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$clienteId, $email, $nombre, $productoId, $pedidoRef, $origen, $estado,
             $limite ? $limite->format('Y-m-d H:i:s') : null,
             $inicio ? $inicio->format('Y-m-d H:i:s') : null,
             $vence ? $vence->format('Y-m-d H:i:s') : null]
        );
        return (int) IO::db()->lastInsertId();
    }

    public static function obtener(int $accesoId): array
    {
        $a = IO::uno('SELECT * FROM io_accesos WHERE id = ?', [$accesoId]);
        if (!$a) { throw new IOError('no_existe', 'Acceso no encontrado.', 404); }
        return $a;
    }

    /** Lee el acceso con bloqueo de fila. Debe llamarse dentro de IO::transaccion(). */
    public static function bloquear(int $accesoId): array
    {
        $a = IO::uno('SELECT * FROM io_accesos WHERE id = ? FOR UPDATE', [$accesoId]);
        if (!$a) { throw new IOError('no_existe', 'Acceso no encontrado.', 404); }
        return $a;
    }

    /**
     * Recalcula el estado según el tiempo transcurrido (vencimientos, cortes, gracia).
     * Bloquea y relee la fila para no pisar cambios concurrentes.
     */
    public static function refrescar(array $a): array
    {
        return IO::transaccion(function () use ($a) {
            $a = self::bloquear((int) $a['id']);
            $p = self::politica((int) $a['producto_id']);
            $ahora = IO::ahora();
            $nuevo = $a['estado'];
            $set = [];

            if ($a['estado'] === 'por_empezar' && $a['limite_inicio'] && $a['limite_inicio'] < $ahora) {
                $nuevo = 'vencido';
            }
            if ($p['tipo'] === 'ventana' && in_array($a['estado'], ['activo', 'en_gracia'], true) && $a['vence'] && $a['vence'] <= $ahora) {
                $nuevo = 'vencido';
                IO::ejecutar('UPDATE io_sesiones SET activa = 0, cerrada_por = ? WHERE acceso_id = ? AND activa = 1', ['vencido', $a['id']]);
            }
            if ($p['tipo'] === 'unica') {
                if ($a['estado'] === 'activo') {
                    $ses = IO::uno('SELECT ultimo_latido FROM io_sesiones WHERE acceso_id = ? AND activa = 1 ORDER BY ultimo_latido DESC LIMIT 1', [$a['id']]);
                    $ultimo = $ses['ultimo_latido'] ?? $a['actualizado'];
                    if (strtotime($ultimo) < time() - self::SIN_LATIDO_SEG) {
                        IO::ejecutar('UPDATE io_sesiones SET activa = 0, cerrada_por = ? WHERE acceso_id = ? AND activa = 1', ['sin_latido', $a['id']]);
                        $nuevo = 'en_gracia';
                        $set['gracia_hasta'] = date('Y-m-d H:i:s', strtotime($ultimo) + 60 * (int) $p['gracia_min']);
                    }
                }
                $gracia = $set['gracia_hasta'] ?? $a['gracia_hasta'];
                if ($nuevo === 'en_gracia' && $gracia && $gracia <= $ahora) {
                    $nuevo = 'visto';
                    $set['visto_en'] = $ahora;
                }
            }

            if ($nuevo !== $a['estado'] || $set) {
                $set['estado'] = $nuevo;
                IO::actualizar('io_accesos', $set, 'id = ?', [$a['id']]);
                $a = array_merge($a, $set);
            }
            return $a;
        });
    }

    /** Accesos del cliente para "Mi biblioteca". */
    public static function delCliente(int $clienteId): array
    {
        $filas = IO::todos(
            'SELECT a.*, p.titulo, p.tipo, p.duracion_seg, p.horas_ventana, p.cuenta_desde, p.umbral_pct
             FROM io_accesos a JOIN io_politicas p ON p.producto_id = a.producto_id
             WHERE a.cliente_id = ? AND a.estado <> "revocado" ORDER BY a.actualizado DESC',
            [$clienteId]
        );
        return array_map(fn($a) => array_merge($a, self::refrescar($a)), $filas);
    }

    /**
     * Emitir el pase de reproducción. Solo después de esto el navegador recibe el ID de Vimeo.
     */
    public static function iniciar(int $accesoId, array $cliente, string $dispositivo, string $ip): array
    {
        return IO::transaccion(function () use ($accesoId, $cliente, $dispositivo, $ip) {
            $a = IO::uno('SELECT * FROM io_accesos WHERE id = ? FOR UPDATE', [$accesoId]);
            if (!$a || (int) $a['cliente_id'] !== (int) $cliente['id']) {
                throw new IOError('sin_permiso', 'Este contenido no pertenece a tu cuenta.', 403);
            }
            $a = self::refrescar($a);
            $p = self::politica((int) $a['producto_id']);
            $unica = $p['tipo'] === 'unica';

            switch ($a['estado']) {
                case 'visto':    throw new IOError('visto', 'Ya reproduciste este contenido. Su regla es de visualización única.', 410, true);
                case 'vencido':  throw new IOError('vencido', 'Tu acceso a este contenido venció.', 410, true);
                case 'revocado': throw new IOError('revocado', 'Este acceso fue revocado. Escríbenos si crees que es un error.', 410);
            }
            if ($unica && (int) $p['duracion_seg'] <= 0) {
                throw new IOError('sin_duracion', 'Este contenido aún no está listo para reproducirse.', 503);
            }

            $ahora = new DateTimeImmutable();
            $set = [];

            // Un solo dispositivo a la vez: se cierran las sesiones abiertas.
            $otras = IO::todos('SELECT token FROM io_sesiones WHERE acceso_id = ? AND activa = 1 FOR UPDATE', [$accesoId]);
            if ($otras) {
                IO::ejecutar('UPDATE io_sesiones SET activa = 0, cerrada_por = ? WHERE acceso_id = ? AND activa = 1', ['otro_dispositivo', $accesoId]);
                if ($unica) {
                    self::alerta($accesoId, (int) $cliente['id'], 'doble_dispositivo', 'Se abrió en: ' . mb_substr($dispositivo, 0, 180));
                }
            }

            if ($a['estado'] === 'por_empezar') {
                $set['estado'] = 'activo';
                $set['inicio'] = $ahora->format('Y-m-d H:i:s');
                if ($p['tipo'] === 'ventana') {
                    $set['vence'] = $ahora->modify('+' . (int) $p['horas_ventana'] . ' hours')->format('Y-m-d H:i:s');
                }
            } elseif ($unica) {
                // Cualquier reinicio de una visualización única ya empezada (activa o en gracia) cuenta como reanudación.
                if ((int) $a['reanudaciones'] >= (int) $p['reanudaciones_max']) {
                    IO::actualizar('io_accesos', ['estado' => 'visto', 'visto_en' => IO::ahora()], 'id = ?', [$accesoId]);
                    self::alerta($accesoId, (int) $cliente['id'], 'reanudaciones_agotadas', null);
                    throw new IOError('visto', 'Agotaste las reanudaciones permitidas para este contenido.', 410, true);
                }
                $set['estado'] = 'activo';
                $set['reanudaciones'] = (int) $a['reanudaciones'] + 1;
                $set['gracia_hasta'] = null;
            }

            if ($set) {
                IO::actualizar('io_accesos', $set, 'id = ?', [$accesoId]);
                $a = array_merge($a, $set);
            }

            $token = bin2hex(random_bytes(32));
            IO::ejecutar(
                'INSERT INTO io_sesiones (token, acceso_id, cliente_id, dispositivo, ip, posicion_seg) VALUES (?,?,?,?,?,?)',
                [$token, $accesoId, $cliente['id'], mb_substr($dispositivo, 0, 255), $ip, (int) $a['max_seg']]
            );

            return [
                'token'      => $token,
                'vimeo_id'   => $p['vimeo_id'],
                'vimeo_hash' => $p['vimeo_hash'],
                'titulo'     => $p['titulo'],
                'tipo'       => $p['tipo'],
                'estado'     => $a['estado'],
                'empezar_en' => (int) $a['max_seg'],
                'vence'      => $a['vence'],
                'marca_agua' => $cliente['email'],
                'reglas'     => $unica ? [
                    'umbral_pct'         => (int) $p['umbral_pct'],
                    'retroceso_seg'      => (int) $p['retroceso_seg'],
                    'permitir_adelantar' => (bool) $p['permitir_adelantar'],
                    'reanudaciones_rest' => max(0, (int) $p['reanudaciones_max'] - (int) $a['reanudaciones']),
                    'duracion_seg'       => (int) $p['duracion_seg'],
                ] : null,
                'latido_seg' => 15,
            ];
        });
    }

    /**
     * Bloquea acceso y sesión de un token y valida dueño, vigencia y frescura.
     * @return array{0: array, 1: array, 2: array} [acceso, sesión, política]
     */
    private static function contexto(string $token, array $cliente, bool $exigirFresca = true): array
    {
        $ref = IO::uno('SELECT acceso_id FROM io_sesiones WHERE token = ?', [$token]);
        if (!$ref) { throw new IOError('sesion_cerrada', 'La sesión de reproducción no existe.', 409); }
        $a = self::bloquear((int) $ref['acceso_id']);                          // 1) acceso
        $s = IO::uno('SELECT * FROM io_sesiones WHERE token = ? FOR UPDATE', [$token]); // 2) sesión
        if ((int) $s['cliente_id'] !== (int) $cliente['id'] || (int) $a['cliente_id'] !== (int) $cliente['id']) {
            throw new IOError('sin_permiso', 'Sin permiso.', 403);
        }
        if (!(int) $s['activa']) {
            throw new IOError('sesion_cerrada', $s['cerrada_por'] === 'otro_dispositivo'
                ? 'Este contenido se abrió en otro dispositivo.'
                : 'La sesión de reproducción terminó.', 409);
        }
        $p = self::politica((int) $a['producto_id']);
        $transcurrido = time() - strtotime($s['ultimo_latido']);
        $vencido = $a['vence'] && $a['vence'] <= IO::ahora();
        if ($exigirFresca && ($transcurrido > self::SIN_LATIDO_SEG || $a['estado'] !== 'activo' || $vencido)) {
            // Sesión vieja o acceso ya no vigente: se cierra y se recalcula el estado.
            IO::ejecutar('UPDATE io_sesiones SET activa = 0, cerrada_por = ? WHERE token = ?', ['expirada', $token]);
            if ($p['tipo'] === 'unica' && $a['estado'] === 'activo' && !$vencido) {
                IO::actualizar('io_accesos', ['estado' => 'en_gracia',
                    'gracia_hasta' => date('Y-m-d H:i:s', strtotime($s['ultimo_latido']) + 60 * (int) $p['gracia_min'])], 'id = ?', [$a['id']]);
            }
            self::refrescar($a);
            throw new IOError('sesion_cerrada', 'La reproducción se interrumpió. Vuelve a abrir el contenido para continuar.', 409, true);
        }
        return [$a, $s, $p];
    }

    /**
     * Latido cada 15 s. Solo acredita avance plausible según el tiempo real transcurrido.
     */
    public static function latido(string $token, array $cliente, int $posicion, bool $reproduciendo): array
    {
        return IO::transaccion(function () use ($token, $cliente, $posicion, $reproduciendo) {
            [$a, $s, $p] = self::contexto($token, $cliente);
            $transcurrido = max(0, time() - strtotime($s['ultimo_latido']));
            $max = (int) $a['max_seg'];
            $resp = ['ok' => true, 'estado' => $a['estado'], 'max_seg' => $max];

            // Latidos demasiado seguidos no acreditan avance ni renuevan el reloj.
            if ($transcurrido < self::LATIDO_MIN_SEG) { return $resp; }

            $pausaDesde = $reproduciendo ? null : ($s['pausa_desde'] ?: IO::ahora());
            if ($p['tipo'] === 'unica' && $pausaDesde && strtotime($pausaDesde) < time() - 60 * (int) $p['pausa_max_min']) {
                IO::ejecutar('UPDATE io_sesiones SET activa = 0, cerrada_por = ? WHERE token = ?', ['pausa_larga', $token]);
                IO::actualizar('io_accesos', ['estado' => 'en_gracia',
                    'gracia_hasta' => date('Y-m-d H:i:s', time() + 60 * (int) $p['gracia_min'])], 'id = ?', [$a['id']]);
                throw new IOError('pausa_larga', 'La pausa superó el tiempo permitido. Podrás reanudar si te quedan reanudaciones.', 409, true);
            }

            $credito = $reproduciendo ? (int) floor(min($transcurrido, self::SIN_LATIDO_SEG) * self::VELOCIDAD_MAX) + self::HOLGURA_SEG : 0;
            $limiteAvance = $max + $credito;
            $vistos = $reproduciendo ? min($transcurrido, self::SIN_LATIDO_SEG) : 0;

            if ($p['tipo'] !== 'unica') {
                $nuevoMax = max($max, min($posicion, $limiteAvance));
                IO::ejecutar('UPDATE io_sesiones SET ultimo_latido = NOW(), posicion_seg = ?, reproduciendo = ?, pausa_desde = ? WHERE token = ?',
                    [$posicion, (int) $reproduciendo, $pausaDesde, $token]);
                IO::ejecutar('UPDATE io_accesos SET max_seg = GREATEST(max_seg, ?), seg_vistos = seg_vistos + ? WHERE id = ?', [$nuevoMax, $vistos, $a['id']]);
                $resp['max_seg'] = $nuevoMax;
                return $resp;
            }

            if ($posicion > $limiteAvance && !(int) $p['permitir_adelantar']) {
                self::alerta((int) $a['id'], (int) $a['cliente_id'], 'adelanto_bloqueado', "posición $posicion, máximo permitido $limiteAvance");
                $posicion = min($posicion, $limiteAvance);
                $resp['corregir_a'] = $posicion;
            } elseif ((int) $p['retroceso_seg'] < 999999 && $posicion < $max - (int) $p['retroceso_seg']) {
                $posicion = $max - (int) $p['retroceso_seg'];
                $resp['corregir_a'] = $posicion;
            }

            $nuevoMax = max($max, min($posicion, $limiteAvance));
            IO::ejecutar('UPDATE io_sesiones SET ultimo_latido = NOW(), posicion_seg = ?, reproduciendo = ?, pausa_desde = ? WHERE token = ?',
                [$posicion, (int) $reproduciendo, $pausaDesde, $token]);
            IO::ejecutar('UPDATE io_accesos SET max_seg = GREATEST(max_seg, ?), seg_vistos = seg_vistos + ? WHERE id = ?', [$nuevoMax, $vistos, $a['id']]);
            $resp['max_seg'] = $nuevoMax;

            if ($nuevoMax >= (int) $p['duracion_seg'] * ((int) $p['umbral_pct'] / 100)) {
                IO::actualizar('io_accesos', ['estado' => 'visto', 'visto_en' => IO::ahora()], 'id = ?', [$a['id']]);
                IO::ejecutar('UPDATE io_sesiones SET activa = 0, cerrada_por = ? WHERE token = ?', ['visto', $token]);
                $resp['estado'] = 'visto';
                $resp['fin'] = true;
            }
            return $resp;
        });
    }

    /** El reproductor avisa que el video terminó (evento ended). Solo cuenta si el servidor ya acreditó el umbral. */
    public static function finalizado(string $token, array $cliente): array
    {
        return IO::transaccion(function () use ($token, $cliente) {
            [$a, , $p] = self::contexto($token, $cliente);
            if ($p['tipo'] === 'unica' && (int) $a['max_seg'] >= (int) $p['duracion_seg'] * ((int) $p['umbral_pct'] / 100)) {
                IO::actualizar('io_accesos', ['estado' => 'visto', 'visto_en' => IO::ahora()], 'id = ?', [$a['id']]);
                IO::ejecutar('UPDATE io_sesiones SET activa = 0, cerrada_por = ? WHERE token = ?', ['visto', $token]);
                return ['ok' => true, 'estado' => 'visto', 'fin' => true];
            }
            return ['ok' => true, 'estado' => $a['estado']];
        });
    }

    /** Cierre por pestaña cerrada (sendBeacon): la visualización única pasa a periodo de gracia. */
    public static function cerrar(string $token, array $cliente, string $motivo = 'pestana_cerrada'): void
    {
        IO::transaccion(function () use ($token, $cliente, $motivo) {
            try { [$a, $s, $p] = self::contexto($token, $cliente, false); }
            catch (IOError $e) { if ($e->codigo === 'sesion_cerrada') { return; } throw $e; }
            self::cerrarBloqueada($a, $p, $token, $motivo);
        });
    }

    /** Cierre por el sistema o el backoffice (sin cliente). Debe llamarse dentro de una transacción con el acceso bloqueado. */
    public static function cerrarBloqueada(array $a, array $p, string $token, string $motivo): void
    {
        IO::ejecutar('UPDATE io_sesiones SET activa = 0, cerrada_por = ? WHERE token = ? AND activa = 1', [$motivo, $token]);
        if ($p['tipo'] === 'unica' && $a['estado'] === 'activo') {
            IO::actualizar('io_accesos', ['estado' => 'en_gracia',
                'gracia_hasta' => date('Y-m-d H:i:s', time() + 60 * (int) $p['gracia_min'])], 'id = ?', [$a['id']]);
        }
    }

    public static function alerta(?int $accesoId, ?int $clienteId, string $tipo, ?string $detalle): void
    {
        // Evita duplicar la misma alerta en 10 minutos.
        $existe = IO::uno('SELECT id FROM io_alertas WHERE acceso_id <=> ? AND tipo = ? AND creada > DATE_SUB(NOW(), INTERVAL 10 MINUTE)', [$accesoId, $tipo]);
        if ($existe) { return; }
        IO::ejecutar('INSERT INTO io_alertas (acceso_id, cliente_id, tipo, detalle) VALUES (?,?,?,?)', [$accesoId, $clienteId, $tipo, $detalle]);
    }
}

/**
 * Acciones del backoffice. Cada una: motivo obligatorio (≤ 120), transacción con el acceso bloqueado
 * y registro en la bitácora dentro de la misma transacción (si la bitácora falla, no se aplica el cambio).
 */
final class IOAdmin
{
    public const MOTIVOS = ['Falla técnica', 'Cortesía comercial', 'Error de compra', 'Solicitud del alumno', 'Abuso o fraude', 'Otro'];

    private static function registrar(array $admin, string $accion, ?int $accesoId, ?int $productoId, string $motivo, $detalle = null): void
    {
        IO::ejecutar('INSERT INTO io_bitacora (admin_id, admin_email, accion, acceso_id, producto_id, motivo, detalle) VALUES (?,?,?,?,?,?,?)',
            [$admin['id'] ?? null, $admin['email'] ?? null, $accion, $accesoId, $productoId, $motivo,
             $detalle === null ? null : (is_string($detalle) ? $detalle : json_encode($detalle, JSON_UNESCAPED_UNICODE))]);
    }

    private static function exigirMotivo(string $motivo): void
    {
        if (trim($motivo) === '') { throw new IOError('motivo', 'El motivo es obligatorio.'); }
        if (mb_strlen($motivo, 'UTF-8') > 120) { throw new IOError('motivo', 'El motivo admite máximo 120 caracteres.'); }
    }

    public static function extender(array $admin, int $accesoId, int $horas, string $motivo): void
    {
        self::exigirMotivo($motivo);
        IO::transaccion(function () use ($admin, $accesoId, $horas, $motivo) {
            $a = IOAccesos::bloquear($accesoId);
            $p = IOAccesos::politica((int) $a['producto_id']);
            if ($p['tipo'] !== 'ventana') { throw new IOError('tipo', 'Solo los accesos por tiempo se pueden extender.'); }
            if ($a['estado'] === 'revocado') { throw new IOError('revocado', 'El acceso está revocado.'); }
            $base = ($a['vence'] && $a['vence'] > IO::ahora()) ? $a['vence'] : IO::ahora();
            $vence = date('Y-m-d H:i:s', strtotime($base) + $horas * 3600);
            $set = ['vence' => $vence];
            if ($a['estado'] === 'vencido') { $set['estado'] = $a['inicio'] ? 'activo' : 'por_empezar'; }
            if ($a['limite_inicio'] && $a['limite_inicio'] < $vence) { $set['limite_inicio'] = $vence; }
            if (!$a['inicio']) { unset($set['vence']); }
            IO::actualizar('io_accesos', $set, 'id = ?', [$accesoId]);
            self::registrar($admin, 'extender', $accesoId, (int) $a['producto_id'], $motivo, ['horas' => $horas, 'vence' => $vence]);
        });
    }

    public static function reiniciar(array $admin, int $accesoId, string $motivo): void
    {
        self::exigirMotivo($motivo);
        IO::transaccion(function () use ($admin, $accesoId, $motivo) {
            $a = IOAccesos::bloquear($accesoId);
            $p = IOAccesos::politica((int) $a['producto_id']);
            IO::ejecutar('UPDATE io_sesiones SET activa = 0, cerrada_por = "reinicio_admin" WHERE acceso_id = ? AND activa = 1', [$accesoId]);
            IO::actualizar('io_accesos', ['estado' => 'por_empezar', 'inicio' => null, 'vence' => null, 'max_seg' => 0, 'seg_vistos' => 0,
                'reanudaciones' => 0, 'gracia_hasta' => null, 'visto_en' => null,
                'limite_inicio' => date('Y-m-d H:i:s', time() + 86400 * (int) $p['dias_para_empezar'])], 'id = ?', [$accesoId]);
            self::registrar($admin, 'reiniciar', $accesoId, (int) $a['producto_id'], $motivo, ['estado_anterior' => $a['estado'], 'max_seg' => (int) $a['max_seg']]);
        });
    }

    public static function revocar(array $admin, int $accesoId, string $motivo): void
    {
        self::exigirMotivo($motivo);
        IO::transaccion(function () use ($admin, $accesoId, $motivo) {
            $a = IOAccesos::bloquear($accesoId);
            IO::ejecutar('UPDATE io_sesiones SET activa = 0, cerrada_por = "revocado" WHERE acceso_id = ? AND activa = 1', [$accesoId]);
            IO::actualizar('io_accesos', ['estado' => 'revocado'], 'id = ?', [$accesoId]);
            self::registrar($admin, 'revocar', $accesoId, (int) $a['producto_id'], $motivo, ['estado_anterior' => $a['estado']]);
        });
    }

    public static function cerrarSesiones(array $admin, int $accesoId, string $motivo): void
    {
        self::exigirMotivo($motivo);
        IO::transaccion(function () use ($admin, $accesoId, $motivo) {
            $a = IOAccesos::bloquear($accesoId);
            $p = IOAccesos::politica((int) $a['producto_id']);
            foreach (IO::todos('SELECT token FROM io_sesiones WHERE acceso_id = ? AND activa = 1 FOR UPDATE', [$accesoId]) as $s) {
                IOAccesos::cerrarBloqueada($a, $p, $s['token'], 'admin');
            }
            self::registrar($admin, 'cerrar_sesion', $accesoId, (int) $a['producto_id'], $motivo);
        });
    }

    public static function cortesia(array $admin, int $clienteId, string $email, string $nombre, int $productoId, string $motivo): int
    {
        self::exigirMotivo($motivo);
        return IO::transaccion(function () use ($admin, $clienteId, $email, $nombre, $productoId, $motivo) {
            $id = IOAccesos::otorgar($clienteId, $email, $nombre, $productoId, 'cortesia', null);
            self::registrar($admin, 'otorgar', $id, $productoId, $motivo, ['cliente' => $email]);
            return $id;
        });
    }

    public static function guardarPolitica(array $admin, array $d, string $motivo): void
    {
        self::exigirMotivo($motivo);
        $campos = ['titulo', 'vimeo_id', 'vimeo_hash', 'duracion_seg', 'tipo', 'horas_ventana', 'cuenta_desde', 'dias_para_empezar',
                   'umbral_pct', 'retroceso_seg', 'permitir_adelantar', 'pausa_max_min', 'reanudaciones_max', 'gracia_min'];
        $valores = [];
        foreach ($campos as $c) { $valores[] = $d[$c] ?? null; }
        // Sin VALUES() (deprecado desde MySQL 8.0.20): los valores se vuelven a enlazar en el UPDATE.
        $sql = 'INSERT INTO io_politicas (producto_id, ' . implode(', ', $campos) . ') VALUES (' . implode(',', array_fill(0, count($campos) + 1, '?')) . ')'
             . ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($c) => "$c = ?", $campos));
        IO::transaccion(function () use ($admin, $d, $motivo, $sql, $valores, $campos) {
            IO::ejecutar($sql, array_merge([(int) $d['producto_id']], $valores, $valores));
            self::registrar($admin, 'politica', null, (int) $d['producto_id'], $motivo, array_intersect_key($d, array_flip($campos)));
        });
    }
}

/** Lectura de metadatos desde la API de Vimeo (solo backoffice). */
final class IOVimeo
{
    public static function info(string $vimeoId): ?array
    {
        $token = IO::config()['vimeo_token'] ?? '';
        if ($token === '' || !function_exists('curl_init')) { return null; }
        $ch = curl_init('https://api.vimeo.com/videos/' . rawurlencode($vimeoId) . '?fields=name,duration,pictures.base_link,privacy');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: bearer ' . $token, 'Accept: application/vnd.vimeo.*+json;version=3.4'],
        ]);
        $r = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200 || !$r) { return null; }
        $j = json_decode($r, true);
        return ['titulo' => $j['name'] ?? '', 'duracion_seg' => (int) ($j['duration'] ?? 0), 'miniatura' => $j['pictures']['base_link'] ?? null];
    }
}
