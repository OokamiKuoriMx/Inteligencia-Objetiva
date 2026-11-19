-- 2026-11-18 — CFDI 4.0, datos del receptor y conciliación idempotente con Facturapi.
-- Reversión, únicamente tras respaldar los comprobantes: DROP TABLE io_facturas
CREATE TABLE IF NOT EXISTS io_facturas (
  pedido_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  proveedor_id VARCHAR(120) NULL UNIQUE,
  uuid VARCHAR(40) NULL,
  external_id VARCHAR(100) NOT NULL UNIQUE,
  idempotencia VARCHAR(100) NOT NULL UNIQUE,
  estado VARCHAR(30) NOT NULL DEFAULT 'reservada',
  total_centavos BIGINT UNSIGNED NOT NULL,
  moneda CHAR(3) NOT NULL DEFAULT 'MXN',
  datos_json LONGTEXT NOT NULL,
  error_codigo VARCHAR(40) NULL,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_estado (estado, actualizado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
