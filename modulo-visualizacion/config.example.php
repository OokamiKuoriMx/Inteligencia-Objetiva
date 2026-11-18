<?php
/**
 * Copia este archivo como config.php y ajusta los valores.
 * config.php NO debe subirse al repositorio (contiene credenciales).
 */
return [
    // Misma base de datos que ya usa el sitio (las tablas nuevas llevan prefijo io_).
    'db' => [
        'dsn'      => 'mysql:host=127.0.0.1;dbname=NOMBRE_BD;charset=utf8mb4',
        'usuario'  => 'USUARIO_BD',
        'clave'    => 'CLAVE_BD',
    ],

    // Token personal de la API de Vimeo (Vimeo > Settings > Apps > Generate token, permiso "public" + "private").
    // Solo se usa en el backoffice para leer duración y miniatura. Opcional.
    'vimeo_token' => '',

    // Dominio donde se insertan los videos (también debe estar en la lista de dominios permitidos en Vimeo).
    'dominio' => 'https://inteligenciaobjetiva.com',

    // Zona horaria del negocio.
    'zona_horaria' => 'America/Mexico_City',

    /**
     * Cómo obtener al cliente con sesión iniciada en el sitio ACTUAL.
     * Debe devolver ['id' => int, 'email' => string, 'nombre' => string] o null si no hay sesión.
     * Ajusta las claves de $_SESSION a las que usa hoy el sitio.
     */
    'cliente_actual' => function () {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        if (empty($_SESSION['id_cliente'])) { return null; }
        return [
            'id'     => (int) $_SESSION['id_cliente'],
            'email'  => (string) ($_SESSION['correo'] ?? ''),
            'nombre' => (string) ($_SESSION['nombre'] ?? ''),
        ];
    },

    // Solo para desarrollo local: acepta peticiones desde localhost. En producción: false.
    'dev' => false,

    /**
     * Importación inicial (php bin/importar.php). Ajusta las consultas a las tablas reales del sitio.
     * Los nombres de tabla/columna de abajo son EJEMPLOS: reemplázalos.
     */
    'importar' => [
        // Debe devolver: producto_id, titulo, vimeo_id, categoria  (opcionales: vimeo_hash, duracion_seg)
        'sql_productos' => "SELECT id AS producto_id, titulo, video_vimeo AS vimeo_id, categoria FROM cursos WHERE activo = 1",
        // Regla de acceso por categoría ('*' = cualquier otra)
        'regla_por_categoria' => [
            'conferencias'    => ['tipo' => 'ventana', 'horas_ventana' => 48, 'cuenta_desde' => 'primera_reproduccion'],
            'cursos'          => ['tipo' => 'ventana', 'horas_ventana' => 240, 'cuenta_desde' => 'primera_reproduccion'],
            'documentales'    => ['tipo' => 'permanente'],
            'introspecciones' => ['tipo' => 'permanente'],
            'audiolibro'      => ['tipo' => 'permanente'],
            '*'               => ['tipo' => 'ventana', 'horas_ventana' => 48],
        ],
        // Compras vigentes. Debe devolver: cliente_id, email, nombre, producto_id, pedido_ref, fecha_compra
        'sql_compras' => "SELECT c.id_cliente AS cliente_id, cl.correo AS email, cl.nombre, c.id_curso AS producto_id,
                                 c.folio AS pedido_ref, c.fecha AS fecha_compra
                          FROM compras c JOIN clientes cl ON cl.id = c.id_cliente
                          WHERE c.estatus = 'pagado' AND (c.vigencia IS NULL OR c.vigencia > NOW())",
    ],
];
