-- Ajustes de la plataforma editables desde el backoffice (por ejemplo, el canal de YouTube) y su caché.
-- Compatible con MySQL 5.7 y 8.0. Solo crea tablas io_.

CREATE TABLE IF NOT EXISTS io_ajustes (
  clave VARCHAR(64) NOT NULL,
  valor TEXT NULL,
  actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
