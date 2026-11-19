-- 2026-11-19 · Roles y perfiles del equipo del backoffice.
-- Propósito: los permisos dejan de estar fijos en el código por rol (admin, editor, soporte, finanzas).
-- Cada rol tiene un conjunto de permisos y cada miembro del equipo tiene un rol y, si se necesita, permisos extra.
-- No modifica io_admins: la asignación de rol, el último acceso y la versión de sesión viven en io_equipo.
-- Un admin sin fila en io_equipo usa el rol cuya clave coincide con io_admins.rol (mismo comportamiento que antes).
-- Compatible con MySQL 5.7 y 8.0. Solo crea tablas io_. Idempotente: se puede ejecutar más de una vez.
-- Revertir: DROP TABLE io_equipo_permisos, io_equipo, io_roles_permisos, io_roles (en ese orden).
-- Antes de revertir, revisa io_admins.rol de quien tenga un rol creado por el equipo o Solo lectura (se guardan como soporte).

CREATE TABLE IF NOT EXISTS io_roles (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clave       VARCHAR(40) NOT NULL,
  nombre      VARCHAR(80) NOT NULL,
  descripcion VARCHAR(255) NOT NULL DEFAULT '',
  total       TINYINT(1) NOT NULL DEFAULT 0,
  sistema     TINYINT(1) NOT NULL DEFAULT 0,
  creado      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_clave (clave),
  UNIQUE KEY uq_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permisos de cada rol. Un rol total tiene todos los permisos aunque no aparezcan aquí.
CREATE TABLE IF NOT EXISTS io_roles_permisos (
  rol_id  INT UNSIGNED NOT NULL,
  permiso VARCHAR(32) NOT NULL,
  PRIMARY KEY (rol_id, permiso),
  CONSTRAINT fk_roles_permisos_rol FOREIGN KEY (rol_id) REFERENCES io_roles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ficha de equipo de cada admin: rol asignado, último acceso y versión de sesión (al subirla se cierran sus sesiones).
CREATE TABLE IF NOT EXISTS io_equipo (
  admin_id       INT UNSIGNED NOT NULL PRIMARY KEY,
  rol_id         INT UNSIGNED NULL,
  ultimo_acceso  DATETIME NULL,
  sesion_version INT UNSIGNED NOT NULL DEFAULT 0,
  actualizado    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_rol (rol_id),
  CONSTRAINT fk_equipo_admin FOREIGN KEY (admin_id) REFERENCES io_admins (id) ON DELETE CASCADE,
  CONSTRAINT fk_equipo_rol FOREIGN KEY (rol_id) REFERENCES io_roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permisos extra de un miembro, además de los de su rol.
CREATE TABLE IF NOT EXISTS io_equipo_permisos (
  admin_id INT UNSIGNED NOT NULL,
  permiso  VARCHAR(32) NOT NULL,
  PRIMARY KEY (admin_id, permiso),
  CONSTRAINT fk_equipo_permisos_admin FOREIGN KEY (admin_id) REFERENCES io_admins (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Roles base. Las claves admin, editor, soporte y finanzas coinciden con io_admins.rol para conservar los permisos actuales.
INSERT IGNORE INTO io_roles (clave, nombre, descripcion, total, sistema) VALUES
  ('admin', 'Dirección (administrador total)', 'Todo el backoffice, incluidos el equipo, los roles y la cancelación de facturas.', 1, 1),
  ('editor', 'Contenido', 'Publica y edita contenidos, transmisiones y cupones. Consulta reportes.', 0, 1),
  ('soporte', 'Atención a alumnos', 'Resuelve casos de alumnos: otorga, extiende y revoca accesos, y consulta pedidos.', 0, 1),
  ('finanzas', 'Ventas y finanzas', 'Pedidos, devoluciones, reportes y bitácora.', 0, 1),
  ('lectura', 'Solo lectura', 'Consulta el tablero, los accesos y los contenidos sin cambiar nada.', 0, 1);

INSERT IGNORE INTO io_roles_permisos (rol_id, permiso)
  SELECT r.id, p.permiso FROM io_roles r
  JOIN (
    SELECT 'editor' AS clave, 'ver' AS permiso UNION ALL SELECT 'editor', 'politicas' UNION ALL SELECT 'editor', 'reportes'
    UNION ALL SELECT 'soporte', 'ver' UNION ALL SELECT 'soporte', 'accesos' UNION ALL SELECT 'soporte', 'pedidos'
    UNION ALL SELECT 'finanzas', 'ver' UNION ALL SELECT 'finanzas', 'reportes' UNION ALL SELECT 'finanzas', 'bitacora'
    UNION ALL SELECT 'finanzas', 'pedidos' UNION ALL SELECT 'finanzas', 'reembolsos'
    UNION ALL SELECT 'lectura', 'ver'
  ) p ON p.clave = r.clave
  WHERE r.sistema = 1 AND NOT EXISTS (SELECT 1 FROM io_roles_permisos x WHERE x.rol_id = r.id);

-- Cada admin existente recibe el rol equivalente a su io_admins.rol.
INSERT IGNORE INTO io_equipo (admin_id, rol_id)
  SELECT a.id, r.id FROM io_admins a JOIN io_roles r ON r.clave = a.rol;
