-- Inteligencia Objetiva 2.0 — 002: intentos de inicio de sesión del backoffice (freno a fuerza bruta).
-- Persiste por IP y por correo, así que borrar cookies no reinicia el contador.
-- Revertir: DROP TABLE io_login_intentos

CREATE TABLE IF NOT EXISTS io_login_intentos (
  id      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ip      VARCHAR(45)  NOT NULL,
  email   VARCHAR(190) NOT NULL,
  exito   TINYINT(1)   NOT NULL DEFAULT 0,
  creado  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ip (ip, creado),
  KEY idx_email (email, creado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
