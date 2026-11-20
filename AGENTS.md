# Integración de Inteligencia Objetiva 2.0

Este repositorio contiene la actualización que se instala sobre un sitio PHP/MySQL existente. Usa [la guía de despliegue](GUIA-DESPLIEGUE.md) para el orden de integración y [la entrega](ENTREGA-CLIENTE.md) para la aceptación en staging. Si tienes acceso al código del sitio original, verifica sus contratos antes de proponer cambios. La demo pública usa datos ficticios.

Si eres el agente principal y necesitas una explicación del código o un plan de adaptación, delega en el agente [Integración de Inteligencia Objetiva](.github/agents/integracion-io.agent.md) si tu entorno admite agentes de Copilot. Si usas otro agente, usa el contenido del perfil como encargo junto con este archivo. Continúa desde sus referencias y valida el diagnóstico antes de editar.

## Inventario completo

Para un alcance de punta a punta, usa el [inventario de 25 integraciones](docs/inventario-integraciones.md). Incluye los puntos de entrada, dependencias, criterios de aceptación y reversión. Usa este mapa breve para localizar el código antes de editar.

## Mapa por responsabilidades

| Área | Archivos de inicio | Punto de integración |
| --- | --- | --- |
| Tema y portada | `tema/head-snippet.html`, `tema/io-theme.css`, `tema/io-theme.js`, `portada/index.html` | Orden de CSS, plantillas, navegación, modo oscuro y modales de inicio de sesión y registro. |
| Sesión y perfil | `modulo-visualizacion/config.example.php`, `lib/IO.php`, `public/mi-biblioteca.php` | `cliente_actual` devuelve `id`, `email` y `nombre`, o `null`. Conserva el identificador y la sesión del sitio original. Revisa la biblioteca independiente y la opción `IO_EMBEBIDO`. |
| Catálogo, carrito y compra | `tienda/catalogo.php`, `tienda/producto.php`, `tienda/carrito.php`, `tienda/gracias.php`, `lib/Tienda.php`, `api/stripe-webhook.php` | Conserva URLs, propiedad del pedido y precios del servidor. Separa el flujo de confirmación anterior del webhook firmado de Stripe 2.0. |
| Biblioteca, accesos y reproducción | `public/mi-biblioteca.php`, `public/ver.php`, `api/reproduccion.php`, `lib/IO.php` | Vincula la compra confirmada con `IOAccesos::otorgar` cuando corresponda. Conserva reglas de vigencia, expiración y reanudación. |
| Importación y esquema | `bin/importar.php`, `bin/_importacion.php`, `sql/001_io_visualizacion.sql` a `sql/010_io_roles.sql` | Mapea IDs de clientes, productos y folios. Ejecuta `productos --simular` y `compras --simular` antes de importar. La migración de compras no incluye historial de reproducción. |
| Operación y equipo | `backoffice/_inc.php`, `backoffice/reglas.php`, `backoffice/accesos.php`, `backoffice/equipo.php`, `backoffice/perfil.php`, `lib/Equipo.php` | El panel propuesto usa sus propios usuarios y permisos. Identifica primero el panel vigente. Los cambios de acceso requieren motivo y bitácora en la misma transacción. |
| Cupones, membresías, CFDI y devoluciones | `DESCUENTOS.md`, `MEMBRESIAS.md`, `FACTURACION.md`, `PEDIDOS.md` | Activa por módulo después de validar configuración, permisos e idempotencia. Prueba Stripe y Facturapi con cuentas de prueba. |
| Transmisiones y Vimeo | `lib/Transmisiones.php`, `lib/YouTube.php`, `lib/Vimeo.php`, `tienda/en-vivo.php`, `VIMEO.md` | Revisa audiencia, horarios, permisos de inserción y credenciales del proveedor. |
| Rutas públicas, buscadores y asistentes | `tienda/rewrite-produccion.txt`, `tienda/agentes.php`, `lib/Agentes.php` | Revisa las reglas Apache o nginx, redirecciones antiguas y archivos públicos existentes. Mantén las áreas privadas fuera del índice público. |

Las rutas de código que empiezan en `lib/`, `public/`, `tienda/`, `backoffice/`, `api/`, `bin/` o `sql/` son relativas a `modulo-visualizacion/`, que se instala como `/io/`.

## Adaptaciones compatibles

- Preserva las tablas y contratos del sitio original. Limita las migraciones nuevas a tablas `io_*` y comprueba compatibilidad con PHP 7.4 o posterior y MySQL 5.7 y 8.0 cuando aplique.
- Conserva la confirmación de pago del servidor, la validación de montos y la idempotencia de compras, reembolsos y webhooks. Mantén el acceso pendiente antes de la confirmación y el retiro de acceso después de la devolución confirmada.
- Usa `config.example.php` para documentar configuración. Las credenciales reales y los datos de clientes quedan fuera del repositorio, de los informes y de las pruebas. Ejecuta pruebas con bases desechables y proveedores de prueba o simulados.
- Valida por fase en staging. Comprueba tanto el flujo original como el nuevo y documenta cómo volver al anterior. Si ya hay pagos o accesos, prevé conciliarlos antes de desactivar el módulo y conserva los datos `io_*` para conciliación.
- Para pruebas existentes y diagnóstico, sigue `GUIA-DESPLIEGUE.md`. Distingue las comprobaciones ejecutadas de las que correspondan al instalador.

## Navegación local y temporales

Si existe `graphify-out/graph.json`, empieza las consultas sobre el código con `graphify query`. Usa `graphify path` y `graphify explain` para relaciones y conceptos. Usa el índice de la wiki si existe y reserva el reporte completo para revisiones de arquitectura amplias. Si el grafo no está en el checkout, inspecciona el código y la documentación directamente. Si modificas código con Graphify disponible, actualiza el grafo con `graphify update .`.

Mantén archivos temporales, cachés y datos descartables fuera de carpetas sincronizadas o redirigidas. En Windows, verifica la ruta y sus directorios padre antes de usar una ubicación local como `%LOCALAPPDATA%\Codex\Temp`. Limita los cambios de `TEMP` y `TMP` al proceso que los necesite.
