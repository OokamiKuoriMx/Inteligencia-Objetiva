-- Inteligencia Objetiva 2.0 — 008: transmisiones en vivo (YouTube, Facebook, Vimeo y Zoom).
-- Solo crea la tabla io_transmisiones. Revertir: DROP TABLE io_transmisiones

CREATE TABLE IF NOT EXISTS io_transmisiones (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug                  VARCHAR(160) NOT NULL UNIQUE,
  titulo                VARCHAR(200) NOT NULL,
  descripcion           TEXT NULL,
  imagen                VARCHAR(255) NULL,
  plataforma            ENUM('youtube','facebook','vimeo','zoom') NOT NULL,
  url                   VARCHAR(500) NOT NULL COMMENT 'enlace tal como lo pegó el equipo',
  video_id              VARCHAR(160) NOT NULL COMMENT 'ID detectado (YouTube, Vimeo, Zoom) o URL canónica (Facebook)',
  es_evento_vimeo       TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'vimeo.com/event/ID',
  inicio                DATETIME NOT NULL,
  duracion_min          SMALLINT UNSIGNED NOT NULL DEFAULT 90,
  estado                ENUM('programada','en_vivo','finalizada','cancelada') NOT NULL DEFAULT 'programada',
  acceso                ENUM('publico','registrados','compradores','miembros') NOT NULL DEFAULT 'publico',
  producto_id           INT UNSIGNED NULL COMMENT 'requerido cuando acceso = compradores',
  grabacion_producto_id INT UNSIGNED NULL COMMENT 'producto del catálogo con la grabación',
  mostrar_chat          TINYINT(1) NOT NULL DEFAULT 1,
  iniciada_en           DATETIME NULL,
  finalizada_en         DATETIME NULL,
  creado                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_estado_inicio (estado, inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
