# Inteligencia Objetiva 2.0

Mejoras para el sitio en producción inteligenciaobjetiva.com (PHP + MySQL + jQuery + Bootstrap 5.1.3). Todo se integra **sobre** el sitio actual, sin reescribirlo.

## Estructura
- `tema/` — tema drop-in (tokens de marca + modo oscuro). Se sirve en `/tema/`. `io-theme.css` va DESPUÉS de los CSS actuales; `head-snippet.html` evita el parpadeo.
- `portada/` — nueva portada 2.0 (secciones reutilizables, imágenes en `portada/img/*.webp`).
- `modulo-visualizacion/` — se despliega en `/io/`:
  - `lib/IO.php` núcleo (IO, IOAccesos, IOAdmin, IOVimeo). `api/reproduccion.php` API JSON.
  - `backoffice/` panel del equipo. `public/` biblioteca del alumno, reproductor y `io-player.js`.
  - `sql/` migraciones (solo crean tablas `io_*`), `bin/` scripts CLI, `tests/` pruebas.

## Convenciones
- Tablas nuevas con prefijo `io_`. Nunca modificar tablas existentes del sitio.
- Compatibilidad: PHP 7.4+/8.x y **MySQL 5.7 y 8.0**. No usar `VALUES()` en `ON DUPLICATE KEY UPDATE`, ni `;` dentro de comentarios SQL.
- Toda acción del backoffice que cambie un acceso exige motivo y se registra en `io_bitacora` dentro de la misma transacción.
- Marca: rojo `#cf1717`, marino `#272256`, grafito `#2c2f30`, Roboto (UI) + Cormorant Garamond (titulares). Colores siempre vía tokens `--io-*`.
- `modulo-visualizacion/config.php` tiene credenciales: no se edita ni se versiona (hook lo bloquea). Usar `config.example.php`.
- Textos de interfaz en español de México con acentos correctos.

## Pruebas
- Base temporal: `mysqld` en el puerto 3307 con datadir en el scratchpad (no tocar el MySQL del sistema).
- `php modulo-visualizacion/tests/prueba-flujo.php` → debe terminar en `0 fallidas`.
- `php modulo-visualizacion/tests/sembrar-demo.php` + `php -S 127.0.0.1:8090 router.php` para revisar el backoffice.

## Límite conocido
La visualización única es control de acceso, no DRM: el iframe de Vimeo no puede revocarse del lado del navegador. Mitigaciones: dominio restringido en Vimeo, marca de agua, latidos y sesión única.
