---
name: nueva-migracion-io
description: Crea una migración SQL numerada para el módulo IO (tablas io_*), compatible con MySQL 5.7 y 8.0, y la prueba contra la base temporal.
disable-model-invocation: true
---

# Nueva migración IO

Argumento: descripción corta del cambio (por ejemplo "agregar columna pais a io_accesos").

1. Siguiente número: revisa `modulo-visualizacion/sql/` y usa `00N_<descripcion_snake>.sql`.
2. Reglas:
   - Solo tablas con prefijo `io_`. Nunca `ALTER`/`DROP` sobre tablas del sitio original.
   - Idempotente cuando sea posible (`CREATE TABLE IF NOT EXISTS`; para columnas, comprobar en `information_schema` con un procedimiento o documentar que se ejecuta una sola vez).
   - `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`.
   - Sin `;` dentro de comentarios ni de `COMMENT '...'`. Sin `VALUES()` en upserts.
   - Encabezado con fecha, propósito y cómo revertir.
3. Probar: aplicar 001…00N en una base nueva del MySQL temporal (puerto 3307) y correr `tests/prueba-flujo.php`.
4. Si el cambio afecta código, actualizar `lib/IO.php` y agregar un caso en `tests/prueba-flujo.php`.
