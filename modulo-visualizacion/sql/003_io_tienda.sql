-- Inteligencia Objetiva 2.0 — 003: tienda (catálogo, pedidos y pagos).
-- Solo crea tablas io_*. El importador (bin/importar.php productos) llena io_productos desde el catálogo actual.
-- Revertir: DROP TABLE io_webhooks, io_pedido_items, io_pedidos, io_productos

CREATE TABLE IF NOT EXISTS io_productos (
  producto_id     INT UNSIGNED NOT NULL PRIMARY KEY COMMENT 'mismo ID que io_politicas y que el sitio actual',
  slug            VARCHAR(160) NOT NULL UNIQUE,
  titulo          VARCHAR(255) NOT NULL,
  categoria       ENUM('conferencias','cursos','documentales','introspecciones','audiolibro','zoom') NOT NULL DEFAULT 'conferencias',
  tema            VARCHAR(60)  NULL COMMENT 'pareja, economia, hijos, laboral, desarrollo...',
  resumen         VARCHAR(400) NULL,
  descripcion     TEXT NULL,
  aprenderas      TEXT NULL COMMENT 'una idea por línea',
  imagen          VARCHAR(255) NULL,
  precio_mxn      DECIMAL(10,2) NOT NULL COMMENT 'precio final con IVA',
  precio_anterior DECIMAL(10,2) NULL,
  incluye         VARCHAR(255) NULL,
  trailer_vimeo   VARCHAR(32)  NULL COMMENT 'video público de muestra',
  rating          DECIMAL(3,2) NOT NULL DEFAULT 0,
  resenas         INT UNSIGNED NOT NULL DEFAULT 0,
  ventas          INT UNSIGNED NOT NULL DEFAULT 0,
  destacado       TINYINT(1) NOT NULL DEFAULT 0,
  activo          TINYINT(1) NOT NULL DEFAULT 1,
  creado          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cat (categoria, activo),
  FULLTEXT KEY ft_busqueda (titulo, resumen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS io_pedidos (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  folio         VARCHAR(24)  NOT NULL UNIQUE,
  cliente_id    INT UNSIGNED NOT NULL,
  email         VARCHAR(190) NOT NULL,
  nombre        VARCHAR(190) NULL,
  subtotal      DECIMAL(10,2) NOT NULL COMMENT 'sin IVA',
  iva           DECIMAL(10,2) NOT NULL,
  total         DECIMAL(10,2) NOT NULL COMMENT 'con IVA',
  moneda        CHAR(3) NOT NULL DEFAULT 'MXN',
  pasarela      ENUM('stripe','demo') NOT NULL,
  pasarela_ref  VARCHAR(120) NULL COMMENT 'PaymentIntent de Stripe',
  metodo        VARCHAR(30)  NULL COMMENT 'card, oxxo...',
  estado        ENUM('pendiente','pagado','fallido','cancelado') NOT NULL DEFAULT 'pendiente',
  creado        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  pagado_en     DATETIME NULL,
  KEY idx_cliente (cliente_id, creado),
  KEY idx_ref (pasarela_ref),
  KEY idx_estado (estado, creado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS io_pedido_items (
  pedido_id    BIGINT UNSIGNED NOT NULL,
  producto_id  INT UNSIGNED NOT NULL,
  titulo       VARCHAR(255) NOT NULL,
  precio_mxn   DECIMAL(10,2) NOT NULL,
  acceso_id    BIGINT UNSIGNED NULL COMMENT 'acceso creado al confirmarse el pago',
  PRIMARY KEY (pedido_id, producto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotencia: cada evento de la pasarela se procesa una sola vez.
CREATE TABLE IF NOT EXISTS io_webhooks (
  evento_id   VARCHAR(120) NOT NULL PRIMARY KEY,
  tipo        VARCHAR(80)  NOT NULL,
  recibido    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
