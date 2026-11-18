-- Inteligencia Objetiva 2.0 — Módulo de visualización (MySQL 5.7+ / 8.0, utf8mb4)
-- Solo CREA tablas nuevas con prefijo io_. No modifica ninguna tabla existente del sitio.
-- Ejecutar:  mysql -u USUARIO -p BASE_DE_DATOS < 001_io_visualizacion.sql

SET NAMES utf8mb4;

-- Política de acceso por producto (curso, conferencia, documental...).
-- producto_id = el ID que ya usa el sitio para ese producto.
CREATE TABLE IF NOT EXISTS io_politicas (
  producto_id        INT UNSIGNED NOT NULL PRIMARY KEY,
  titulo             VARCHAR(255) NOT NULL,
  vimeo_id           VARCHAR(32)  NOT NULL,
  vimeo_hash         VARCHAR(32)  NULL COMMENT 'parámetro h= de videos no listados',
  duracion_seg       INT UNSIGNED NOT NULL DEFAULT 0,
  tipo               ENUM('permanente','ventana','unica') NOT NULL DEFAULT 'ventana',
  horas_ventana      INT UNSIGNED NOT NULL DEFAULT 48,
  cuenta_desde       ENUM('compra','primera_reproduccion') NOT NULL DEFAULT 'primera_reproduccion',
  dias_para_empezar  INT UNSIGNED NOT NULL DEFAULT 30,
  -- Reglas de visualización única
  umbral_pct         TINYINT UNSIGNED NOT NULL DEFAULT 95,
  retroceso_seg      INT UNSIGNED NOT NULL DEFAULT 30 COMMENT '0 = bloqueado, 999999 = libre',
  permitir_adelantar TINYINT(1)   NOT NULL DEFAULT 0,
  pausa_max_min      INT UNSIGNED NOT NULL DEFAULT 60,
  reanudaciones_max  TINYINT UNSIGNED NOT NULL DEFAULT 2,
  gracia_min         INT UNSIGNED NOT NULL DEFAULT 120,
  actualizado        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Un acceso = un cliente puede ver un producto bajo su política.
CREATE TABLE IF NOT EXISTS io_accesos (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cliente_id       INT UNSIGNED NOT NULL COMMENT 'ID del cliente en la tabla actual del sitio',
  cliente_email    VARCHAR(190) NOT NULL,
  cliente_nombre   VARCHAR(190) NULL,
  producto_id      INT UNSIGNED NOT NULL,
  pedido_ref       VARCHAR(64)  NULL COMMENT 'folio del pedido/pago existente',
  origen           ENUM('compra','membresia','cortesia','regalo','migracion') NOT NULL DEFAULT 'compra',
  estado           ENUM('por_empezar','activo','en_gracia','visto','vencido','revocado') NOT NULL DEFAULT 'por_empezar',
  creado           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  limite_inicio    DATETIME NULL COMMENT 'fecha máxima para dar play por primera vez',
  inicio           DATETIME NULL COMMENT 'primera reproducción',
  vence            DATETIME NULL,
  max_seg          INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'segundo más alto visto',
  seg_vistos       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'segundos reales reproducidos',
  reanudaciones    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  gracia_hasta     DATETIME NULL,
  visto_en         DATETIME NULL,
  actualizado      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cliente (cliente_id, estado),
  KEY idx_producto (producto_id),
  KEY idx_estado_vence (estado, vence),
  KEY idx_email (cliente_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sesiones de reproducción (pase de un solo uso + latidos).
CREATE TABLE IF NOT EXISTS io_sesiones (
  token          CHAR(64) NOT NULL PRIMARY KEY,
  acceso_id      BIGINT UNSIGNED NOT NULL,
  cliente_id     INT UNSIGNED NOT NULL,
  dispositivo    VARCHAR(255) NULL,
  ip             VARCHAR(45)  NULL,
  pais           CHAR(2)      NULL,
  iniciada       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_latido  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  posicion_seg   INT UNSIGNED NOT NULL DEFAULT 0,
  reproduciendo  TINYINT(1) NOT NULL DEFAULT 0,
  pausa_desde    DATETIME NULL,
  activa         TINYINT(1) NOT NULL DEFAULT 1,
  cerrada_por    VARCHAR(32) NULL,
  KEY idx_acceso (acceso_id, activa),
  KEY idx_activas (activa, ultimo_latido)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Alertas de comportamiento sospechoso.
CREATE TABLE IF NOT EXISTS io_alertas (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  acceso_id   BIGINT UNSIGNED NULL,
  cliente_id  INT UNSIGNED NULL,
  tipo        ENUM('doble_dispositivo','retroceso_bloqueado','adelanto_bloqueado','reanudaciones_agotadas','ip_compartida') NOT NULL,
  detalle     VARCHAR(500) NULL,
  revisada    TINYINT(1) NOT NULL DEFAULT 0,
  creada      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_revisada (revisada, creada)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Usuarios del backoffice (independientes de los clientes).
CREATE TABLE IF NOT EXISTS io_admins (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email     VARCHAR(190) NOT NULL UNIQUE,
  nombre    VARCHAR(120) NOT NULL,
  hash      VARCHAR(255) NOT NULL,
  rol       ENUM('admin','editor','soporte','finanzas') NOT NULL DEFAULT 'soporte',
  activo    TINYINT(1) NOT NULL DEFAULT 1,
  creado    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bitácora: toda acción del equipo con motivo.
CREATE TABLE IF NOT EXISTS io_bitacora (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  admin_id   INT UNSIGNED NULL,
  admin_email VARCHAR(190) NULL,
  accion     VARCHAR(48) NOT NULL,
  acceso_id  BIGINT UNSIGNED NULL,
  producto_id INT UNSIGNED NULL,
  motivo     VARCHAR(120) NULL,
  detalle    TEXT NULL,
  fecha      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_fecha (fecha),
  KEY idx_acceso (acceso_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
