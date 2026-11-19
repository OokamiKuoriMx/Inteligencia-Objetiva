-- 2026-11-18. Solicitudes de reembolso y conciliación sin alterar tablas originales.
-- Revertir solo después de conservar el historial: DROP TABLE io_reembolsos
CREATE TABLE IF NOT EXISTS io_reembolsos (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 pedido_id BIGINT UNSIGNED NOT NULL,
 clave_solicitud VARCHAR(100) NOT NULL UNIQUE,
 stripe_ref VARCHAR(120) NULL UNIQUE,
 estado VARCHAR(30) NOT NULL,
 monto_centavos BIGINT UNSIGNED NOT NULL,
 moneda CHAR(3) NOT NULL DEFAULT 'MXN',
 motivo VARCHAR(120) NOT NULL,
 admin_id INT UNSIGNED NULL,
 creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY idx_pedido (pedido_id, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
