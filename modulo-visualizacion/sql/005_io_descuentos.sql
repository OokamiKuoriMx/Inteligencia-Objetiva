-- 2026-11-18. Cupones y reservas de uso. Solo tablas io_ nuevas.
-- Reversión: retirar los enlaces y eliminar io_descuentos_pedidos e io_cupones tras respaldarlos.
CREATE TABLE IF NOT EXISTS io_cupones (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(40) NOT NULL UNIQUE,
  tipo ENUM('porcentaje','importe','2x1') NOT NULL,
  valor DECIMAL(10,2) NOT NULL DEFAULT 0,
  inicio DATETIME NOT NULL,
  fin DATETIME NOT NULL,
  minimo_mxn DECIMAL(10,2) NOT NULL DEFAULT 0,
  max_usos INT UNSIGNED NULL,
  max_por_cliente INT UNSIGNED NOT NULL DEFAULT 1,
  productos TEXT NULL,
  incluir_rebajados TINYINT(1) NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS io_descuentos_pedidos (
  pedido_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  cupon_id BIGINT UNSIGNED NOT NULL,
  cliente_id INT UNSIGNED NOT NULL,
  codigo VARCHAR(40) NOT NULL,
  descuento_centavos BIGINT UNSIGNED NOT NULL,
  detalle TEXT NOT NULL,
  estado ENUM('reservado','consumido','liberado') NOT NULL DEFAULT 'reservado',
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  consumido DATETIME NULL,
  KEY idx_cupon (cupon_id, estado),
  KEY idx_cliente (cupon_id, cliente_id, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
