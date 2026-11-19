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

    // Token de la cuenta de Vimeo (developer.vimeo.com/apps > tu app > Generate an access token >
    // "Authenticated (you)" con permisos Public, Private y Edit). Guía completa: VIMEO.md o backoffice > Vincular Vimeo.
    // Solo lo usa el backoffice: lee título, duración, miniatura y privacidad, y aplica la configuración recomendada.
    // Sin token, el editor solo puede leer videos públicos. Nunca lo compartas ni lo subas al repositorio.
    'vimeo_token' => '',

    // Llave de la YouTube Data API v3 (gratuita): detecta cuándo el canal transmite en vivo y lo muestra solo en /en-vivo.
    // Cómo obtenerla: backoffice > Transmisiones > Canal de YouTube. Restríngela a la YouTube Data API v3 y a la IP del servidor.
    'youtube_api_key' => '',

    // Buscadores y agentes de IA (/robots.txt, /llms.txt, /sitemap.xml). Líneas extra para robots.txt,
    // por ejemplo las reglas que ya tenía el sitio: ['Disallow: /pruebas/'].
    'agentes' => ['robots_extra' => []],

    // Miniaturas subidas desde el editor de contenido (arrastrar y soltar). La carpeta debe poder escribirla PHP.
    // Por defecto: /io/subidas/miniaturas (incluye un .htaccess que solo sirve imágenes).
    // 'miniaturas_dir' => __DIR__ . '/subidas/miniaturas',
    // 'miniaturas_url' => '/io/subidas/miniaturas',

    // Dominio donde se insertan los videos. El backoffice lo agrega a los dominios permitidos del video en Vimeo.
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

    /**
     * Opcional. Búsqueda de alumnos en la tabla REAL de clientes del sitio (diálogo "Otorgar acceso" del backoffice).
     * Recibe el texto que escribe el equipo (nombre o correo, mínimo 2 caracteres) y devuelve hasta 10 clientes como
     * [['id' => int, 'email' => string, 'nombre' => string], ...] (también se acepta [id, email, nombre]).
     * El servidor la vuelve a llamar con el correo exacto para confirmar al alumno antes de otorgar, así que debe
     * encontrar por correo. Sin este gancho solo aparecen alumnos que ya tienen pedidos o accesos en io_*.
     * Usa consultas preparadas. Los nombres de tabla/columna de abajo son EJEMPLOS: reemplázalos.
     */
    // 'buscar_clientes' => function (string $texto): array {
    //     $like = '%' . strtr($texto, ['\\' => '\\\\', '%' => '\%', '_' => '\_']) . '%';
    //     return IO::todos('SELECT id, correo AS email, nombre FROM clientes WHERE correo LIKE ? OR nombre LIKE ? ORDER BY nombre LIMIT 10', [$like, $like]);
    // },

    // Solo para desarrollo local: acepta peticiones desde localhost. En producción: false.
    'dev' => false,

    /**
     * Tienda y pasarela de pago (Stripe). Llaves en dashboard.stripe.com → Developers → API keys.
     * Usa primero las llaves de PRUEBA (pk_test_ / sk_test_). Sin llaves, en producción no se puede pagar.
     */
    'tienda' => [
        'stripe_publica' => 'pk_test_...',
        'stripe_secreta' => 'sk_test_...',
        'stripe_webhook' => 'whsec_...',       // "Signing secret" del webhook /io/api/stripe-webhook.php
        'metodos'        => ['card', 'oxxo'],  // métodos habilitados en tu cuenta de Stripe
        // Banner oculto mientras falten fechas o productos realmente rebajados.
        // Usa fechas aprobadas absolutas ISO 8601 con zona, p. ej. AAAA-MM-DDTHH:MM:SS-06:00.
        // No modifica precios: precio_mxn y precio_anterior se administran en io_productos.
        'promocion' => [
            'inicio' => '',
            'fin' => '',
            'productos' => [], // IDs del catálogo incluidos en la campaña.
        ],
    ],

    // Stripe Billing. Configura precios recurrentes en Stripe y sus importes finales con IVA.
    // Los ejemplos están desactivados: no representan una oferta comercial aprobada.
    'membresias' => [
        'habilitadas' => false,
        'planes' => [
            'premium' => [
                'nombre' => 'Premium', 'stripe_price' => '', 'importe_centavos' => 0,
                'moneda' => 'mxn', 'intervalo' => 'month', 'productos' => [],
            ],
            'platinum' => [
                'nombre' => 'Platinum', 'stripe_price' => '', 'importe_centavos' => 0,
                'moneda' => 'mxn', 'intervalo' => 'year', 'productos' => [],
            ],
        ],
    ],

    // CFDI 4.0 mediante Facturapi. El emisor configura su cuenta y sus certificados con el proveedor.
    // Los códigos SAT debe confirmarlos el contador del cliente, no se asignan por suposición.
    'facturacion' => [
        'habilitada' => false, 'modo' => 'prueba', 'api_key' => '', 'producto_sat' => '', 'unidad_sat' => '',
        'productos' => [], // Overrides por ID: ['producto_sat' => '...', 'unidad_sat' => '...'].
    ],

    // Devoluciones completas desde backoffice: habilitar tras las pruebas Stripe de aceptación.
    'pedidos' => ['reembolsos_habilitados' => false],

    /**
     * Importación inicial (php bin/importar.php). Ajusta las consultas a las tablas reales del sitio.
     * Los nombres de tabla/columna de abajo son EJEMPLOS: reemplázalos.
     */
    'importar' => [
        // Debe devolver: producto_id, titulo, vimeo_id, categoria. Opcionales: vimeo_hash, duracion_seg y, para la tienda,
        // precio_mxn (con IVA), precio_anterior, resumen, descripcion, imagen, tema
        'sql_productos' => "SELECT id AS producto_id, titulo, video_vimeo AS vimeo_id, categoria, ROUND(precio * 1.16, 2) AS precio_mxn,
                                   descripcion AS resumen, descripcion, CONCAT('/admin/img/cursos/', imagen) AS imagen FROM cursos WHERE activo = 1",
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
