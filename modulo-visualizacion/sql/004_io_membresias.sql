-- 2026-11-18. Membresías recurrentes y períodos efectivamente pagados.
-- Reversión: quitar las rutas de membresías y eliminar solo estas tablas io_membresia_*
CREATE TABLE IF NOT EXISTS io_membresias (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  cliente_id INT UNSIGNED NOT NULL,
  email VARCHAR(190) NOT NULL,
  nombre VARCHAR(190) NOT NULL,
  plan VARCHAR(60) NOT NULL,
  precio_ref VARCHAR(120) NOT NULL,
  importe_centavos INT UNSIGNED NOT NULL,
  moneda CHAR(3) NOT NULL,
  intervalo VARCHAR(10) NOT NULL,
  productos TEXT NOT NULL,
  clave_activa VARCHAR(100) NULL UNIQUE,
  enlace_token CHAR(48) NOT NULL,
  checkout_ref VARCHAR(120) NULL UNIQUE,
  checkout_vence DATETIME NULL,
  suscripcion_ref VARCHAR(120) NULL UNIQUE,
  cliente_stripe VARCHAR(120) NULL,
  estado VARCHAR(24) NOT NULL DEFAULT 'pendiente',
  pagado_hasta DATETIME NULL,
  cancelar_al_final TINYINT(1) NOT NULL DEFAULT 0,
  cancelada_en DATETIME NULL,
  evento_estado BIGINT UNSIGNED NOT NULL DEFAULT 0,
  evento_pago BIGINT UNSIGNED NOT NULL DEFAULT 0,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cliente (cliente_id, creado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS io_membresia_facturas (
  factura_ref VARCHAR(120) NOT NULL PRIMARY KEY,
  membresia_id BIGINT UNSIGNED NOT NULL,
  periodo_inicio DATETIME NOT NULL,
  periodo_fin DATETIME NOT NULL,
  importe_centavos INT UNSIGNED NOT NULL,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_membresia (membresia_id, periodo_fin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS io_membresia_accesos (
  membresia_id BIGINT UNSIGNED NOT NULL,
  producto_id INT UNSIGNED NOT NULL,
  acceso_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (membresia_id, producto_id),
  UNIQUE KEY idx_acceso (acceso_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
