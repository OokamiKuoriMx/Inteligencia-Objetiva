# Informe técnico · Inteligencia Objetiva 2.0: cambios e implementación

19 de noviembre de 2026 · Versión en línea: https://claude.ai/artifact/LY2Qhb8sQSgpdkUsit5nGW

## Resumen

Este informe documenta los cambios técnicos de la versión 2.0 de inteligenciaobjetiva.com: qué se implementó, en qué archivos y tablas, cómo se integra con el sitio actual y qué falta para ponerlo en producción. La 2.0 se monta sobre el sitio en producción (PHP, MySQL, jQuery y Bootstrap 5.1.3) sin reescribirlo: agrega tres componentes (`tema/`, `portada/` y el módulo `/io/`), diez migraciones que solo crean 24 tablas `io_*` y reglas de reescritura que se activan al final.

**Alcance implementado:** tema con modo oscuro; portada 2.0; tienda con pago en Stripe (tarjeta y OXXO), cupones y 2×1, membresías, CFDI 4.0 y devoluciones; biblioteca del alumno y reproductor de Vimeo con control de acceso (ventana de tiempo, permanente y visualización única); propuesta de backoffice (editor de contenido, miniaturas, transmisiones en vivo con detección del canal de YouTube, equipo, roles y perfiles); y acceso para buscadores y agentes de IA.

**Qué falta para salir a producción:** configurar las cuentas reales (Stripe, Facturapi, Vimeo, YouTube), adaptar dos consultas de importación a las tablas reales del sitio, levantar el backoffice que hoy usa el equipo y aceptar los recorridos de compra en un entorno de pruebas. La propuesta de equipo, roles y perfiles ya está desarrollada.

## Supuestos

Todavía no conocemos el backoffice que hoy usa el equipo, así que todo lo relativo al backoffice en este informe es una propuesta de mejora y no una comparación con lo existente. Partimos de estos supuestos, que deben confirmarse:

- El equipo administra hoy contenidos, accesos y ventas con herramientas limitadas o con pasos manuales.
- No hay roles diferenciados por función.
- La propuesta se adaptará una vez que revisemos el backoffice real.

**Recomendación:** antes de implementar, levantar el backoffice actual: un recorrido con las personas que lo usan a diario, acceso de solo lectura para revisar sus pantallas y flujos, y una lista acordada de lo que se conserva, se sustituye o se integra.

## Arquitectura e integración

La 2.0 son tres componentes que se suben junto al sitio actual: `tema/` se incluye en la plantilla común, `portada/` reemplaza el contenido central de la portada y `modulo-visualizacion/` se publica como `/io/`. El módulo reutiliza la sesión y las ventanas de inicio de sesión del sitio, lee sus tablas solo para importar y guarda todo lo nuevo en tablas `io_*` de la misma base de datos.

| Sitio actual | Relación | Componente 2.0 / servicio |
| --- | --- | --- |
| Plantillas del sitio | se incluyen en ellas | `tema/` (modo oscuro) y `portada/` |
| Sesión del cliente | la lee | Módulo `/io/`: tienda y API |
| Confirmación de pago | otorga el acceso | Módulo `/io/`: biblioteca y reproductor |
| Tablas del sitio | se importan, solo lectura | Tablas `io_*` (24) |
| — | cobra y factura | Stripe y Facturapi |
| — | reproduce | Vimeo |
| — | detecta directos | YouTube Data API |
| — | leen lo público | Buscadores y agentes de IA |

El único punto donde el código actual llama al módulo es la confirmación de pago, que otorga el acceso con `IOAccesos::otorgar`. Las URL públicas de la tienda se activan al final con las reglas de `tienda/rewrite-produccion.txt`. El backoffice es una propuesta independiente del panel que hoy usa el equipo.

## Cambios e implementación por módulo

Cada módulo con lo que se implementó, sus archivos principales y las tablas o la configuración que usa. Las rutas son relativas al módulo `/io/` salvo `tema/` y `portada/`.

| Módulo | Implementación | Archivos principales | Tablas y configuración |
| --- | --- | --- | --- |
| Tema y modo oscuro | Colores de marca en variables `--io-*`; modo oscuro con botón sol/luna y script previo en `<head>` que evita el parpadeo; banner de ofertas compacto; corrección de contraste, desplazamiento horizontal y productos sin reseñas; aviso global «En vivo» | `tema/io-theme.css`, `tema/io-theme.js`, `tema/head-snippet.html` | Preferencia en el navegador del usuario |
| Portada 2.0 | Secciones reutilizables; imágenes WebP en dos tamaños; video de presentación que carga al pulsarlo; animaciones que respetan «reducir movimiento»; datos estructurados | `portada/index.html`, `portada.css`, `portada.js`, `portada/img/` | — |
| Tienda | Nosotros, catálogo con filtros (categoría, tema, tipo de acceso, búsqueda y orden), ficha, carrito, confirmación, ofertas y migas de pan; mismas URL del sitio por reescritura y 301 de fichas antiguas | `tienda/*.php`, `lib/Tienda.php`, `api/tienda.php`, `tienda/rewrite-produccion.txt` | `io_productos`, `io_pedidos`, `io_pedido_items` |
| Pago con Stripe | Tarjeta y OXXO; el pedido solo se confirma por webhook firmado; precio leído en el servidor y validado contra el monto cobrado; cada evento se procesa una vez; la ficha OXXO se recupera en el servidor | `api/stripe-webhook.php`, `lib/Tienda.php` | `io_webhooks`; `tienda.stripe_*` |
| Cupones y 2×1 | Porcentaje, monto fijo y 2×1 con fechas, productos y cupo; reserva de cupo; importes en centavos; alta en ventana | `lib/Descuentos.php`, `backoffice/descuentos.php` | `io_cupones`, `io_descuentos_pedidos` |
| Campañas | Banner con fechas absolutas y productos aprobados; solo anuncia rebajas reales del catálogo | `lib/Promociones.php`, `tienda/ofertas.php` | `tienda.promocion` |
| Membresías | Stripe Checkout y Billing, portal del cliente, renovación y cancelación al final del periodo | `lib/Membresias.php`, `api/membresias.php`, `tienda/membresias.php` | `io_membresias`, `io_membresia_facturas`, `io_membresia_accesos`; `membresias` |
| CFDI 4.0 | Solicitud desde Mis pedidos, emisión, descarga PDF/XML y cancelación con Facturapi | `lib/Facturacion.php`, `api/facturacion.php`, `tienda/facturacion.php` | `io_facturas`; `facturacion` |
| Pedidos y devoluciones | Devolución completa, conciliación de devoluciones hechas en Stripe y retiro del acceso al confirmarse | `lib/Pedidos.php`, `backoffice/pedidos.php` | `io_reembolsos`; `pedidos.reembolsos_habilitados` |
| Biblioteca y reproductor | Biblioteca que se puede incrustar en el perfil; reproductor de Vimeo con latidos validados en el servidor, sesión única, periodo de gracia, reanudaciones y marca de agua | `public/mi-biblioteca.php`, `public/ver.php`, `public/io-player.js`, `api/reproduccion.php`, `lib/IO.php` | `io_politicas`, `io_accesos`, `io_sesiones` |
| Reglas de acceso | Ventana en horas desde la compra o la primera reproducción, acceso permanente o visualización única con umbral, retroceso y reanudaciones | `lib/IO.php`, `backoffice/reglas.php` | `io_politicas` |
| Importación | Productos y compras vigentes con simulación e idempotencia; carga masiva CSV que crea borradores | `bin/importar.php`, `bin/_importacion.php`, `lib/CargaContenidos.php` | `importar.*` |
| Editor de contenido (propuesta) | Pestañas Detalles, Precio y venta y Acceso; el enlace de Vimeo llena título, duración y miniatura; motivo sugerido; control de versión contra sobrescrituras | `backoffice/contenido.php`, `contenido-editor.js`, `lib/Contenidos.php`, `lib/Vimeo.php` | `vimeo_token` |
| Miniaturas (propuesta) | Recorte 16:9 en el navegador a 1280×720; el servidor acepta solo JPG, PNG o WebP reales de hasta 4 MB, con nombre aleatorio; límite de 60 por hora; limpieza diaria | `backoffice/miniatura.php`, `lib/Miniaturas.php`, `bin/limpiar-miniaturas.php` | `subidas/miniaturas/` |
| Accesos y cortesías (propuesta) | Otorgar por búsqueda de alumno y contenido; extender, reiniciar y revocar con motivo | `backoffice/accesos.php`, `otorgar.js`, `lib/Alumnos.php` | `buscar_clientes` (opcional) |
| Transmisiones en vivo (propuesta) | YouTube, Facebook, Vimeo y Zoom; acceso público, con cuenta, compradores o miembros; inicio y cierre automáticos; sala de espera; recordatorio `.ics`; grabación enlazada | `backoffice/transmisiones.php`, `lib/Transmisiones.php`, `api/transmisiones.php`, `tienda/en-vivo.php` | `io_transmisiones` |
| Canal de YouTube (propuesta) | Detección de directos públicos con YouTube Data API v3; caché de 60 s; 2 unidades de cuota por revisión | `backoffice/youtube.php`, `lib/YouTube.php` | `io_ajustes`; `youtube_api_key` |
| Equipo, roles y perfiles (propuesta) | Página Equipo; 5 roles base y 9 permisos configurables; perfil propio; siempre queda un administrador total; desactivar a alguien cierra sus sesiones | `backoffice/equipo.php`, `backoffice/perfil.php`, `lib/Equipo.php`, `bin/crear-admin.php` | `io_roles`, `io_roles_permisos`, `io_equipo`, `io_equipo_permisos` |
| Bitácora y reportes (propuesta) | Motivo obligatorio; el registro se guarda en la misma transacción que el cambio | `backoffice/bitacora.php`, `backoffice/reportes.php` | `io_bitacora` |
| Buscadores y agentes de IA | `robots.txt`, `sitemap.xml`, `llms.txt`, `llms-full.txt` y datos estructurados | `lib/Agentes.php`, `tienda/agentes.php` | `agentes.robots_extra` |

Todo lo relativo al backoffice (editor de contenido, miniaturas, cupones en ventana, transmisiones, canal de YouTube, equipo, roles y perfiles) es una propuesta de mejora: ya está desarrollada, pero se adaptará al backoffice real del equipo después del levantamiento.

## Base de datos

Diez migraciones en `sql/` crean 24 tablas, todas con prefijo `io_` y con «crear si no existe», así que se pueden volver a ejecutar sin daño. Ninguna altera, borra o enlaza por llave foránea las tablas del sitio. Son compatibles con MySQL 5.7 y 8.0: no usan `VALUES()` en `ON DUPLICATE KEY UPDATE` ni `;` dentro de comentarios.

| Migración | Tablas que crea | Uso |
| --- | --- | --- |
| `001_io_visualizacion.sql` | `io_politicas`, `io_accesos`, `io_sesiones`, `io_alertas`, `io_admins`, `io_bitacora` | Reglas de acceso, accesos de alumnos, sesiones de reproducción, usuarios del panel y bitácora. `io_alertas` conserva registros internos aunque la sección de alertas se retiró del panel |
| `002_io_login_intentos.sql` | `io_login_intentos` | Freno persistente a intentos de inicio de sesión |
| `003_io_tienda.sql` | `io_productos`, `io_pedidos`, `io_pedido_items`, `io_webhooks` | Catálogo, pedidos y eventos de Stripe procesados |
| `004_io_membresias.sql` | `io_membresias`, `io_membresia_facturas`, `io_membresia_accesos` | Suscripciones y sus cobros y accesos |
| `005_io_descuentos.sql` | `io_cupones`, `io_descuentos_pedidos` | Cupones, 2×1 y su aplicación por pedido |
| `006_io_facturacion.sql` | `io_facturas` | CFDI emitidos y cancelados |
| `007_io_reembolsos.sql` | `io_reembolsos` | Solicitudes y conciliación de devoluciones |
| `008_io_transmisiones.sql` | `io_transmisiones` | Transmisiones en vivo y sus reglas de acceso |
| `009_io_ajustes.sql` | `io_ajustes` | Ajustes del panel, como el canal de YouTube conectado |
| `010_io_roles.sql` | `io_roles`, `io_roles_permisos`, `io_equipo`, `io_equipo_permisos` | Roles, permisos y equipo; asigna a cada usuario existente el rol equivalente y conserva sus permisos |

## Configuración e integraciones externas

Toda la configuración vive en `config.php`, que se crea en el servidor a partir de `config.example.php` y nunca se versiona. Los módulos externos vienen desactivados hasta configurarlos.

| Clave | Para qué sirve |
| --- | --- |
| `db` | Conexión a la misma base de datos MySQL del sitio |
| `dominio`, `zona_horaria`, `dev` | URL HTTPS real, hora del centro de México y modo de desarrollo (`false` en producción) |
| `cliente_actual` | Adaptador que lee de la sesión actual del sitio el identificador, correo y nombre del cliente |
| `buscar_clientes` | Opcional: búsqueda de alumnos en la tabla real de clientes para otorgar cortesías |
| `importar` | Consultas a las tablas reales de catálogo y compras, y regla de acceso por categoría |
| `tienda` | Llaves de Stripe (pública, secreta y secreto del webhook), métodos (`card`, `oxxo`) y campaña |
| `membresias` | Planes con su precio recurrente de Stripe, importe en centavos, periodicidad y contenidos incluidos |
| `facturacion` | Cuenta de Facturapi, modo de prueba o producción y claves SAT por defecto o por producto |
| `pedidos.reembolsos_habilitados` | Activa las devoluciones desde el panel |
| `vimeo_token` | Opcional: lee duración y miniatura de cada video |
| `youtube_api_key` | Detección de directos del canal de YouTube |
| `agentes.robots_extra` | Líneas propias que deben conservarse en `robots.txt` |

**Integraciones externas**

- **Stripe:** webhook `/io/api/stripe-webhook.php` con versión de API 2024-06-20 y doce eventos: cuatro de pagos individuales, cinco de membresías y tres de devoluciones (lista completa en `GUIA-DESPLIEGUE.md`).
- **Vimeo:** cada video de pago oculto en Vimeo, con inserción solo en el dominio del sitio y sin descargas; el token es opcional.
- **YouTube Data API v3:** llave gratuita de Google Cloud; cada revisión consume 2 de las 10 000 unidades diarias y se guarda en caché 60 segundos.
- **Facturapi:** cuenta y CSD del emisor, con claves SAT aprobadas por el contador.

## Seguridad y control de acceso

- **Panel:** token CSRF en cada acción, verificación de origen, revalidación del rol y los permisos en cada petición, freno persistente a intentos de inicio de sesión y contraseñas de al menos 12 caracteres.
- **Cambios de acceso:** transacciones con bloqueo de fila; todo cambio exige motivo y se registra en `io_bitacora` dentro de la misma transacción; si la bitácora falla, el cambio se revierte.
- **Reproducción:** la API exige sesión y que el token pertenezca al alumno; el avance se acredita por tiempo real (latido mínimo de 10 s, velocidad máxima de 1.25×, cierre a los 60 s sin latido).
- **Pagos:** solo el webhook firmado confirma pedidos; el monto y la moneda se validan contra el pedido y cada evento se procesa una sola vez.
- **Archivos:** `.htaccess` bloquea `lib/`, `sql/`, `bin/`, `tests/` y `config.php` (con reglas equivalentes para nginx); la carpeta de miniaturas solo sirve imágenes con nombre aleatorio y nunca ejecuta código.
- **Privacidad ante buscadores:** backoffice y biblioteca envían `noindex`; `robots.txt` excluye carrito, biblioteca, backoffice y API.

**Límite conocido.** La visualización única es control de acceso: el iframe de Vimeo no puede revocarse del lado del navegador. Las mitigaciones son dominio restringido en Vimeo, marca de agua con el correo del alumno, latidos y sesión única. Por decisión del negocio (18 de noviembre de 2026) no se agregan capas de protección adicionales.

## Pruebas

`tests/` tiene 21 suites `prueba-*` más tres auxiliares para la demo local. Corren contra una base temporal de MySQL en el puerto 3307, nunca contra producción, y cada una debe terminar en «0 fallidas».

- **Núcleo:** `prueba-flujo.php` cubre permisos, latidos acelerados, retroceso y adelanto, doble dispositivo, gracia, reanudaciones, ventanas de 48 h, acciones del panel y reversión si falla la bitácora.
- **Comercio:** tienda y webhooks de Stripe, descuentos, promociones, membresías, facturación, pedidos y confirmación, con proveedores simulados.
- **Panel:** contenidos, carga masiva, importación, otorgar acceso, miniaturas, Vimeo, transmisiones, YouTube y roles.
- **Agentes de IA:** `prueba-agentes.php` verifica que `robots.txt`, `sitemap.xml`, `llms.txt` y los datos estructurados no expongan rutas privadas ni transmisiones de pago.
- **Navegador:** `prueba-retorno.js` comprueba los destinos de regreso tras iniciar sesión.

Al integrar roles y perfiles se reportó la batería completa en verde (20 suites, 658 pruebas) con la revisión de seguridad aplicada; la suite de agentes se agregó después. Estas cifras no se volvieron a ejecutar para este informe, y las pruebas locales no sustituyen la aceptación con las cuentas reales de Stripe y Facturapi.

## Compatibilidad con lo existente y migración

La 2.0 se monta sobre el sitio actual: no reemplaza sus páginas, no modifica sus tablas y cada fase se apaga quitando unas líneas. Lo comprobamos en el código, no solo en la guía.

**Qué verificamos**

- **Base de datos.** Las diez migraciones (001 a 010) solo crean 24 tablas nuevas, todas con prefijo `io_`, y ninguna altera, borra o vincula por llave foránea las tablas del sitio. Todas usan «crear si no existe», así que repetir una migración no daña nada.
- **Datos del sitio.** El módulo solo **lee** las tablas actuales (catálogo, compras y clientes) mediante consultas configurables; nunca escribe en ellas.
- **Cuentas de alumnos.** No se duplican: el módulo reconoce al alumno con la misma sesión de inicio del sitio actual y reutiliza sus ventanas de inicio de sesión y registro.
- **Servidor.** Funciona con PHP 7.4 en adelante (se recomienda 8.x) y con MySQL 5.7 y 8.0; el código evita a propósito instrucciones que solo existen en una de las dos versiones. Requiere HTTPS y acceso por terminal.
- **Direcciones web.** La tienda nueva responde en las mismas direcciones (`/nosotros`, `/producto/...`, `/carrito`) y las fichas antiguas redirigen de forma permanente a las nuevas.
- **Cambios al sitio actual.** Son mínimos y puntuales: tres líneas en la plantilla para el tema, el contenido central de la portada, una llamada en el punto donde hoy se confirma un pago, el enlace «Mi contenido» y, al final, un bloque de reglas en `.htaccess`.

**Backoffice actual.** La integración con el backoffice que hoy usa el equipo se confirmará después del levantamiento. Mientras tanto, el panel propuesto en `/io/` se plantea como complemento independiente, con sus propios usuarios, y no reemplaza ni modifica ninguna herramienta existente.

**Plan de migración de la información**

1. **Respaldo completo.** Base de datos y archivos del sitio, más la carpeta de miniaturas en adelante. Sin respaldo verificado no se avanza.
2. **Entorno de pruebas.** Instalar todo primero en un staging con HTTPS y llaves de prueba de Stripe; si no existe, publicar en horario de bajo tráfico.
3. **Preparar Vimeo.** Cada video de pago en «Ocultar de Vimeo», inserción solo en inteligenciaobjetiva.com y descargas desactivadas.
4. **Crear las tablas nuevas.** Ejecutar `sql/001` a `sql/010` en orden. Solo agregan tablas `io_*`.
5. **Conectar la sesión.** Ajustar en la configuración cómo se lee al cliente con sesión (identificador, correo y nombre) y probarlo con una cuenta real.
6. **Crear el primer administrador total del panel** con `bin/crear-admin.php`.
7. **Importar cursos y sus videos.** `bin/importar.php productos --simular` muestra lo que haría sin escribir; después se ejecuta en firme. Toma de la tabla actual el título, el video de Vimeo, la categoría, el precio con IVA, la descripción y la imagen, y asigna la regla de acceso por categoría. No duplica si se repite.
8. **Importar compras vigentes.** `bin/importar.php compras --simular` y luego en firme. Crea el acceso de cada compra pagada y vigente, respeta la fecha original de compra y no duplica si se repite. Los alumnos no se copian: se vinculan por su identificador actual.
9. **Conectar los pagos nuevos.** En el punto donde hoy se confirma un pago, agregar la llamada que otorga el acceso; si falla, el pago no se interrumpe y el error queda registrado.
10. **Verificar.** `bin/diagnostico.php` revisa versión de PHP, extensiones, configuración, tablas 001–010, que exista un administrador total activo y llaves sin mostrarlas ni crear cargos; los puntos marcados «MANUAL» se revisan a mano. Después, una prueba de punta a punta con una cuenta interna y tres compras de prueba (tarjeta aprobada, rechazada y OXXO).
11. **Ventana de cambio.** Activar las direcciones públicas de la tienda en horario de bajo tráfico, con el equipo disponible y las llaves de Stripe en vivo ya probadas.
12. **Plan de regreso.** Cada fase se revierte por separado:

| Fase | Qué se publica | Cómo se regresa |
| --- | --- | --- |
| 1 | Tema y modo oscuro | Quitar tres líneas de la plantilla |
| 2 | Portada 2.0 | Volver a la portada anterior |
| 3 | Biblioteca, reproductor y panel en `/io/` | Ocultar el enlace «Mi biblioteca» y retirar `/io/` |
| 4 | Compras conectadas y accesos migrados | Borrar solo los accesos con origen «migración» |
| 5 | Tienda nueva y pago con Stripe | Comentar el bloque de reglas en `.htaccess` |

**Huecos y datos que no migran solos**

- **Consultas de importación por adaptar.** Las de ejemplo suponen tablas llamadas cursos, compras y clientes; el técnico debe ajustarlas a los nombres reales antes de importar.
- **Historial de reproducción.** El importador trae compras, no reproducciones, así que en accesos que cuentan «desde la primera reproducción» o de visualización única el alumno migrado empieza de cero. Decisión pendiente: aceptarlo o asignar a esos alumnos acceso permanente.
- **Compras vencidas** no se importan; solo las pagadas y vigentes.
- **Pedidos anteriores** no aparecen en «Mis pedidos», por lo que la factura de autoservicio solo aplica a compras hechas en la tienda nueva.
- **Membresías, cupones y facturas previas**, si existen fuera del sitio, no se importan; las membresías nuevas se crean en Stripe.
- **Redirecciones antiguas.** La ficha nueva se identifica con el título más el número del producto; una dirección antigua solo llega a su ficha si el sitio actual la forma igual. Hay que probar la lista completa de direcciones antes de activar la fase 5.
- **Categorías.** «Introspecciones» y «Documentales» comparten la misma página de catálogo.
- **Videos nuevos.** La carga masiva conecta videos que ya están en Vimeo; no sube archivos de video.
- **Documentos desfasados.** La entrega al cliente pedía las migraciones 001–007 y la guía mencionaba alertas en el panel; ambos documentos ya se corrigieron (001–010, sin alertas).

## Acceso de agentes de IA y disponibilidad del contenido

Cada vez más personas preguntan a ChatGPT, Claude, Perplexity o Gemini sobre los temas que trabaja Martín Aparicio. Para que esos asistentes encuentren, lean y citen a Inteligencia Objetiva, cada tema y cada contenido público necesita una página propia que se lea sin iniciar sesión ni ejecutar código. Esto aplica, entre otros, de manera enunciativa y no limitativa, a todos sus cursos, conferencias, documentales, introspecciones, audiolibros, transmisiones, libros y conceptos. La versión 2.0 ya incluye este acceso para buscadores y agentes de IA; falta activarlo en producción.

**Temas y contenidos que deben quedar al alcance de los agentes** (lista enunciativa y no limitativa, tomada del catálogo del repositorio y de las páginas públicas del sitio revisadas el 19 de noviembre de 2026):

- **Categorías del catálogo:** conferencias en línea (20 páginas de listado), cursos en línea (8 páginas), documentales, introspecciones, audiolibro y conferencias especiales en vivo vía Zoom.
- **Temas del catálogo:** relación de pareja, economía, educación a los hijos, entorno laboral, desarrollo personal y superación.
- **Programas:** diplomados en Comunicación humana; Conciencia, cuerpo y nutrición; Sistema diagnóstico aplicado; y Neodisciplina. Programa de Entrenamiento y Desarrollo Mental (niveles 1 y 2) y su Propedéutico, Curso de Autoconocimiento y Desarrollo Mental, y MECA.
- **Documentales e introspecciones:** adicciones, arrepentimiento, autoestima, calidad de vida, conciencia, creencias, depresión, felicidad, presupuesto de vida y superación de sí.
- **Audiolibro:** «Conciencia de sí».
- **Conceptos y disciplinas:** autoconocimiento, conciencia de sí, filosofía (por ejemplo, «Filosofía viva»), psicología (por ejemplo, «Psicología de la autodestrucción», neuropsicología del miedo y psicología financiera), frecuencia y fortaleza mental, ansiedad, miedo, emociones, toma de decisiones, espiritualidad y tradiciones de sabiduría (Buda, Sócrates y Cristo; budismo y Dzogchen en «Nosotros»).
- **Eneagrama y ley de siete:** conceptos del método señalados por la dirección. No aparecen en las páginas públicas revisadas, así que hoy un agente no puede encontrarlos ni citarlos.
- **Transmisiones y medios:** conferencias en vivo del calendario, Televisión Objetiva y los canales de YouTube @martinaparicio-io e inteligenciaobjetiva.
- **Libros:** el sitio no tiene una sección de libros. Si existen, cada uno necesita su ficha pública.

La portada habla de más de 250 temas en línea. De cada listado solo se revisó la primera página, así que el inventario completo debe salir del catálogo al importarlo.

**Qué está hecho y qué se recomienda**

| Medida | Estado | Qué implica |
| --- | --- | --- |
| `/llms.txt` y `/llms-full.txt` | Hecho | Resumen en Markdown para asistentes de IA: quién es Martín Aparicio, los temas «entre otros, de manera enunciativa y no limitativa» y todos los contenidos por categoría. La versión completa agrega la descripción, lo que vas a comprender y el precio de cada uno |
| `/robots.txt` | Hecho | Da la bienvenida explícita a los rastreadores de ChatGPT, Claude, Perplexity, Gemini, Apple, Meta, Bing, DuckDuckGo, Amazon y Mistral, entre otros, y excluye carrito, confirmación de compra, biblioteca, backoffice y API |
| `/sitemap.xml` | Hecho | Portada, Nosotros, catálogo, cada categoría, cada tema, cada ficha y cada transmisión pública |
| Datos estructurados (schema.org) | Hecho | Organización, fundador y búsqueda del sitio en todas las páginas; curso (`Course`) en cada ficha; evento (`Event`) en las transmisiones públicas |
| Dirección canónica y Open Graph | Hecho | Cada página de la tienda indica su dirección oficial y cómo verse al compartirse en redes |
| Exclusión de áreas privadas | Hecho | Backoffice y biblioteca piden a los buscadores no indexarlos; las transmisiones para miembros o compradores no se publican |
| Ficha pública de cada contenido del catálogo, legible sin JavaScript | Hecho | Título, resumen, descripción, «aprenderás» y precio llegan escritos desde el servidor |
| Páginas de los temas del catálogo | Hecho | Los seis temas del catálogo tienen dirección propia y aparecen en el mapa del sitio y en `llms.txt` |
| Descripción de la portada y redirecciones de fichas antiguas | Hecho | Resumen del sitio para buscadores y asistentes, y enlaces antiguos que siguen funcionando |
| Página del autor | Parcial | Martín Aparicio ya figura como fundador en los datos estructurados y en `llms.txt`, enlazado a Nosotros; falta una página propia con su obra y enlaces oficiales |
| Página propia por concepto y glosario del método | Recomendado | Para lo que no es categoría del catálogo (eneagrama, ley de siete y conciencia de sí, entre otros): explicación, preguntas frecuentes y contenidos relacionados |
| Fichas de diplomados, programas y libros | Recomendado | Hoy quedan fuera del catálogo 2.0; cada uno necesita su ficha con texto propio |
| Página permanente por grabación pública | Recomendado | Que cada directo abierto conserve su página después de transmitirse |
| `VideoObject`, `Book` y `FAQPage` | Recomendado | Videos públicos y tráilers, libros y audiolibros, y preguntas frecuentes por tema; nunca para el contenido de pago |
| Rendimiento y disponibilidad | Parcial | Ya hay imágenes ligeras y video que carga al pulsarlo. Falta un monitor de disponibilidad, caché de las páginas públicas y revisar que el cortafuegos del hosting no bloquee a estos rastreadores |

Los agentes solo ven lo público: portada, Nosotros, catálogo, temas, fichas y transmisiones públicas. El contenido de pago sigue detrás del inicio de sesión y del control de acceso.

**Pendiente para activarlo**

- Activar en producción las reglas de reescritura que publican `robots.txt`, `sitemap.xml`, `llms.txt` y `llms-full.txt`.
- Revisar antes el `robots.txt` y el `sitemap.xml` que ya tiene el sitio, porque los nuevos los reemplazan; las líneas propias que deban conservarse se agregan en la configuración.
- Dar de alta el mapa del sitio en Google Search Console y en Bing Webmaster Tools.

## SEO

De forma enunciativa, la 2.0 aporta o deja listo:

- Redirecciones permanentes de las fichas antiguas para conservar el posicionamiento.
- Descripción propia en cada ficha y en la portada.
- Datos estructurados de organización, fundador, búsqueda, cursos, transmisiones públicas y migas de pan.
- Imágenes WebP ligeras y video bajo demanda, que mejoran la velocidad de carga.
- Sin desplazamiento horizontal en celular.
- Mapa del sitio nuevo, dirección canónica y etiquetas para compartir en redes (Open Graph); pendiente darlo de alta en Google Search Console y Bing.

## Riesgos, próximos pasos y cronograma

El mayor riesgo no es técnico sino de configuración: cuentas, consultas de importación y direcciones antiguas deben probarse con datos reales antes de abrir la tienda, y la propuesta de backoffice debe validarse contra el backoffice real del equipo.

| Riesgo | Impacto | Mitigación |
| --- | --- | --- |
| Consultas de importación mal adaptadas | Alumnos sin acceso a lo que compraron | Simular primero, revisar el conteo contra el sistema actual y probar con cuentas reales |
| Direcciones antiguas que no coinciden | Enlaces rotos y pérdida de posicionamiento | Probar la lista completa antes de la fase 5 |
| Pagos sin confirmación por configuración de Stripe | Pedidos pagados sin acceso | Tres compras de aceptación en modo prueba y luego en vivo |
| Alumnos migrados con ventana nueva | Acceso más amplio que el original | Definir la regla para migrados antes de importar |
| Enlace del reproductor reutilizado durante una visualización única | Una reproducción extra dentro del dominio permitido | Es un límite conocido: dominio restringido en Vimeo, marca de agua con el correo, latidos y sesión única. Por decisión del negocio no se agregan más capas |
| Hosting sin las reglas de protección de carpetas | Archivos internos expuestos | Comprobar que las rutas internas respondan «prohibido» al desplegar |
| Usuarios del panel nuevo al aplicar roles | Alguien con más o menos permisos de los que necesita | La migración 010 conserva los permisos de cada usuario; revisar la página Equipo y ajustar roles con motivo |

**Próximos pasos**

- [ ] Aprobar las reglas de acceso por categoría y la regla para alumnos migrados.
- [ ] Abrir o confirmar las cuentas de Stripe, Facturapi, Vimeo (token) y la llave de YouTube.
- [ ] Levantar el backoffice actual con el equipo (recorrido y acceso de solo lectura) y designar al técnico que adapte la sesión y las consultas de importación.
- [ ] Preparar el entorno de pruebas con HTTPS.
- [ ] Inventariar todos los temas, conceptos y contenidos del catálogo completo.
- [ ] Revisar en la página Equipo el rol y los permisos de cada persona tras la migración.
- [ ] Activar `robots.txt`, `sitemap.xml` y `llms.txt` en producción (tras revisar los actuales), darlos de alta en Google y Bing, y publicar la página del autor y las páginas por concepto.

**Cronograma sugerido**

Siete semanas a partir de la aprobación, con una revisión entre fases; las semanas son estimaciones a validar con el técnico del sitio. La semana 1 incluye el levantamiento del backoffice actual, que puede ajustar la propuesta y el calendario.

| Semana | Actividad |
| --- | --- |
| 1 | Preparación: levantamiento del backoffice actual, respaldo, entorno de pruebas y cuentas |
| 2 | Fases 1 y 2: tema y portada |
| 3 | Fase 3: biblioteca, reproductor y panel en `/io/`, con equipo, roles y perfiles |
| 4 | Fase 4: importar cursos y compras |
| 5–6 | Fase 5: tienda y Stripe en modo prueba |
| Cierre de la 6 | Cambio a pago en vivo |
| 7 | Membresías, facturas, devoluciones y acceso de agentes de IA |

El pago en vivo se activa solo después de que el diagnóstico pase y las tres compras de aceptación funcionen en modo prueba; equipo, roles y perfiles se instala con la fase 3.

## Referencias

- `GUIA-DESPLIEGUE.md`: despliegue por fases, configuración del servidor, Stripe, transmisiones, miniaturas, buscadores y agentes de IA.
- `ENTREGA-CLIENTE.md`: configuración a cargo del técnico del cliente y pasos de aceptación.
- `MEMBRESIAS.md`, `DESCUENTOS.md`, `FACTURACION.md`, `PEDIDOS.md` y `VIMEO.md`: detalle de cada módulo comercial e integración.
- `CLAUDE.md`: convenciones del proyecto y límite conocido del control de acceso al video.
- Historial de cambios: rama `feat/io-2.0`.
