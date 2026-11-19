# Propuesta de mejora · Inteligencia Objetiva 2.0

Propuesta de mejora de IO 2.0, con la identidad gráfica de **MC Proyectos Estratégicos**.

- **Presentación en línea (privada, Slides):** https://claude.ai/artifact/TpEaXyBvPNf7XJgJup7DiZ
- **Respaldo en PDF:** `presentacion-io-2.0.pdf` (17 diapositivas, 16:9).

## Contenido

| Carpeta | Qué hay |
| --- | --- |
| `capturas/` | Capturas reales del demo local (datos ficticios), modo claro/oscuro, escritorio y móvil. `antes-*` son del sitio público actual. |
| `marca/` | Logotipos de MC Proyectos Estratégicos (recortados del archivo de identidad gráfica). |
| `deck/project/` | Fuente de la presentación: `deck.json`, `deck.css` y una diapositiva por archivo en `slides/`. |
| `herramientas/` | Scripts para repetir capturas (`capturar.js`, `antes.js`) y regenerar la fuente (`generar_deck.py`). |

## Identidad aplicada (Manual MCPE-MIG-001, Rev. 0.1)

- Azul institucional `#01203D`, azul de apoyo `#1A396B`, oro `#C68F11` / `#D4AF37` como acento, gris técnico `#6B7785`, neutro `#F9F9F9`.
- Tipografía Segoe UI con respaldo Arial (fallback documentado en el manual).
- Barra bicolor oro + azul al pie, retícula 16:9 con zona segura, una idea por diapositiva.

## Cómo se tomaron las capturas

1. MySQL de prueba en `127.0.0.1:3307` con la base `io_demo` (`php modulo-visualizacion/tests/sembrar-demo.php`).
2. Servidor de demo en un puerto libre: `php -S 127.0.0.1:8097 -t . modulo-visualizacion/tests/servidor-local.php` con `IO_LOCAL_TEMP` e `IO_DEMO_PORT` definidos.
3. `node herramientas/capturar.js <carpeta-salida> [nombres] <raíz-del-repo>` con `playwright-core` y Chromium local (cuenta `demo-admin@ejemplo.test`, alumna demo).
