# Inventario de integraciones de Inteligencia Objetiva 2.0

Este inventario cubre los puntos de conexión que ofrece el repositorio. Una integración puede tener código y pruebas locales y todavía requerir configuración, mapeo o aceptación en el sitio original. Los nombres de tablas y claves de sesión que aparecen en `config.example.php` son ejemplos. Los secretos, datos personales y certificados se configuran en el entorno autorizado.

Las rutas con prefijo `io/` en esta guía representan el directorio fuente `modulo-visualizacion/`, instalado como `/io/`. Las pruebas mencionadas se encuentran en [`modulo-visualizacion/tests/`](../modulo-visualizacion/tests/). Consulta [la guía de despliegue](../GUIA-DESPLIEGUE.md) para comandos y prerrequisitos.

## Identidad y administración

### 01. Inicio de sesión y registro de alumnos

- **Qué conecta.** El gancho `cliente_actual` de [`io/config.example.php`](../modulo-visualizacion/config.example.php) permite a [`IO::clienteActual()`](../modulo-visualizacion/lib/IO.php) leer la identidad autenticada del sitio. Devuelve `id`, `email` y `nombre`, o `null`. El ingreso y registro siguen en los modales del sitio original. El regreso posterior al carrito pasa por [`tema/io-theme.js`](../tema/io-theme.js).
- **Adaptación.** Localiza el controlador de autenticación, las claves efectivas de sesión y la ruta de retorno. Comprueba que las cookies y la configuración de sesión compartan el alcance necesario para `/io/`. Conserva el identificador original y la validación original de credenciales.
- **Aceptación y reversión.** Prueba sesión anónima, inicio, alta, expiración, cierre y regreso al carrito. Usa `prueba-retorno.js` para el comportamiento del destino. Para volver atrás, restaura los enlaces y el script de entrada anterior, y retira el consumo del adaptador por los nuevos recorridos.

### 02. Perfil del alumno, biblioteca y portal de pedidos

- **Qué conecta.** El perfil original puede enlazar [`io/public/mi-biblioteca.php`](../modulo-visualizacion/public/mi-biblioteca.php) o incluirlo con `IO_EMBEBIDO`. [`io/tienda/mis-pedidos.php`](../modulo-visualizacion/tienda/mis-pedidos.php) presenta pedidos individuales. El portal de membresía tiene su propio punto de entrada.
- **Adaptación.** Preserva el formulario y la edición de datos del perfil original. Agrega los enlaces de consulta y conserva el ID que se usa para comprobar propiedad de compras, accesos y facturas.
- **Aceptación y reversión.** Prueba dos cuentas distintas, la cuenta sin compras, cierre de sesión y conservación de la navegación. Restaura el enlace anterior si falla la presentación. Conserva una vía operativa de consulta para compras 2.0 ya registradas.

### 03. Búsqueda de alumnos y cortesías

- **Qué conecta.** `buscar_clientes` es un adaptador de lectura opcional. [`IOAlumnos::buscar()` y `verificar()`](../modulo-visualizacion/lib/Alumnos.php) lo usan desde [Accesos](../modulo-visualizacion/backoffice/accesos.php). Permite encontrar alumnos sin compras en `io_*` y vuelve a verificar por correo para otorgar una cortesía.
- **Adaptación.** Usa el directorio autorizado y consultas preparadas. Devuelve hasta diez registros con identidad estable. Conserva motivo, permisos y bitácora para el alta.
- **Aceptación y reversión.** Usa `prueba-otorgar.php` y valida búsqueda parcial, correo exacto, identidad discrepante y alta con motivo. Si se retira el gancho, queda la búsqueda sobre alumnos con datos en el módulo. Las cortesías existentes se conservan.

### 04. Acceso y perfil del equipo

- **Qué conecta.** El panel propuesto tiene [inicio de sesión](../modulo-visualizacion/backoffice/login.php), [perfil personal](../modulo-visualizacion/backoffice/perfil.php), sesiones propias y recuperación operativa mediante [`bin/crear-admin.php`](../modulo-visualizacion/bin/crear-admin.php). Usa `io_admins` e `io_login_intentos`.
- **Adaptación.** Levanta primero el backoffice vigente y decide si los dos paneles coexistirán. Mantén separadas las sesiones de alumno y equipo. No presupongas inicio de sesión único ni importación de contraseñas del sitio original.
- **Aceptación y reversión.** Comprueba acceso, bloqueo por intentos, edición de perfil, cambio de contraseña y cierre de sesiones. Si se deshabilita el nuevo acceso, conserva el panel original y los registros operativos del módulo.

### 05. Roles, permisos, bajas y bitácora

- **Qué conecta.** [`io/backoffice/equipo.php`](../modulo-visualizacion/backoffice/equipo.php), [`lib/Equipo.php`](../modulo-visualizacion/lib/Equipo.php) y la [migración 010](../modulo-visualizacion/sql/010_io_roles.sql) asignan roles y permisos efectivos sin cambiar el esquema del panel original. `io_bitacora` guarda las operaciones auditadas.
- **Adaptación.** Construye una correspondencia entre puestos, permisos vigentes y las acciones que realmente necesitan. Conserva la verificación en el servidor, el último administrador total activo y la invalidación de sesiones tras desactivar o restablecer una cuenta.
- **Aceptación y reversión.** Usa `prueba-roles.php` con usuarios existentes, roles y permisos extra. Prueba dos perfiles con atribuciones distintas y el límite del último administrador. Antes de retirar la interfaz nueva, confirma un administrador y un flujo de atención disponibles. Conserva el historial y las tablas de roles.

## Contenido, datos y video

### 06. Servidor, conexión y migraciones

- **Qué conecta.** Instala `modulo-visualizacion/` como `/io/`. El ejemplo de configuración define `db`, dominio, zona horaria, `dev` y adaptadores. Las [migraciones 001–010](../modulo-visualizacion/sql/) crean el esquema `io_*`.
- **Adaptación.** Identifica document root, Apache o nginx, PHP y dependencias, conexión MySQL, permisos, archivos de configuración y directorios internos. Aplica cambios compatibles con el motor real sin modificar tablas del sitio original.
- **Aceptación y reversión.** Usa [`bin/diagnostico.php`](../modulo-visualizacion/bin/diagnostico.php), revisa los puntos manuales y verifica que configuración y rutas internas no se sirvan públicamente. Si se revierte el código y la configuración del servidor, conserva las tablas y respaldos de `io_*`.

### 07. Catálogo histórico y precios

- **Qué conecta.** `importar.sql_productos` alimenta [`bin/importar.php`](../modulo-visualizacion/bin/importar.php) y [`bin/_importacion.php`](../modulo-visualizacion/bin/_importacion.php). La consulta devuelve ID, título, Vimeo y categoría, con campos comerciales opcionales. `io_productos` y `io_politicas` sostienen ficha y acceso.
- **Adaptación.** Mapea productos, categorías, temas, precios finales con IVA, miniaturas, Vimeo y visibilidad. Usa el ID estable del original. La consulta del ejemplo no fija la regla fiscal del cliente.
- **Aceptación y reversión.** Ejecuta `productos --simular`, compara filas y precios de muestra, revisa rechazos y repite la importación para comprobar que conserva los campos y reglas acordados. Usa `prueba-importacion.php` y `prueba-importacion-bd.php`. Para revertir la publicación del catálogo, restaura las rutas anteriores. Concilia cambios de datos por lote sin borrar pedidos.

### 08. Compras históricas y vigencia

- **Qué conecta.** `importar.sql_compras` devuelve `cliente_id`, `email`, `nombre`, `producto_id`, `pedido_ref` y `fecha_compra` para incorporar compras vigentes a la biblioteca.
- **Adaptación.** Define con el negocio qué se considera vigente, cómo se distinguen compras y cortesías y qué tratamiento reciben las reproducciones del sitio anterior. El importador trae compras, no historial de reproducción.
- **Aceptación y reversión.** Ejecuta `compras --simular`. Contrasta folios, vigencias, clientes y productos. Repite con el mismo conjunto para verificar que no duplica accesos. Usa `prueba-importacion-bd.php` y `prueba-flujo.php`. Aísla y concilia el lote si aparece una discrepancia, preservando compras nuevas.

### 09. Edición de contenido y carga masiva CSV

- **Qué conecta.** [`backoffice/reglas.php`](../modulo-visualizacion/backoffice/reglas.php), [`backoffice/contenido.php`](../modulo-visualizacion/backoffice/contenido.php) y [`lib/Contenidos.php`](../modulo-visualizacion/lib/Contenidos.php) editan fichas y políticas. [`backoffice/carga-contenidos.php`](../modulo-visualizacion/backoffice/carga-contenidos.php) y [`lib/CargaContenidos.php`](../modulo-visualizacion/lib/CargaContenidos.php) crean borradores desde CSV.
- **Adaptación.** Define quién edita, quién publica y cuál será la fuente autorizada de cada campo. Preserva los IDs, los borradores, el control de edición concurrente y la publicación explícita.
- **Aceptación y reversión.** Usa `prueba-contenidos.php` y `prueba-carga-contenidos.php`. Verifica conflictos de edición, IDs duplicados, vista previa, guardado atómico y publicación. Si se retira el editor, conserva borradores y restaura el flujo editorial acordado.

### 10. Miniaturas y almacenamiento

- **Qué conecta.** [`backoffice/miniatura.php`](../modulo-visualizacion/backoffice/miniatura.php), [`lib/Miniaturas.php`](../modulo-visualizacion/lib/Miniaturas.php) y [`bin/limpiar-miniaturas.php`](../modulo-visualizacion/bin/limpiar-miniaturas.php) reciben imágenes, recortan su alcance al directorio previsto y permiten limpiar archivos huérfanos.
- **Adaptación.** Revisa `miniaturas_dir`, `miniaturas_url`, permisos de escritura, formatos, límites y respaldo de archivos. Las reglas del servidor deben impedir que el directorio de imágenes ejecute PHP.
- **Aceptación y reversión.** Usa `prueba-miniaturas.php`. Comprueba carga válida y rechazos, y verifica que las fichas y transmisiones conservan sus imágenes. Para retirar las cargas nuevas, conserva los archivos referenciados y sus URLs.

### 11. Vimeo y metadatos

- **Qué conecta.** [`lib/Vimeo.php`](../modulo-visualizacion/lib/Vimeo.php), [`backoffice/vimeo.php`](../modulo-visualizacion/backoffice/vimeo.php) y [`backoffice/vimeo-api.php`](../modulo-visualizacion/backoffice/vimeo-api.php) integran identificadores, vista previa y consulta de metadatos para el contenido alojado en Vimeo.
- **Adaptación.** Conserva ID y hash cuando corresponda. Revisa token, cuenta autorizada, dominio de inserción y reglas de privacidad. Consulta [`VIMEO.md`](../VIMEO.md).
- **Aceptación y reversión.** Usa `prueba-vimeo.php` y valida un video público y uno permitido solo en el dominio autorizado. Si se retira la consulta de metadatos, preserva enlaces y configuración de inserción que sigan usando las fichas y el reproductor.

### 12. Biblioteca, reglas de acceso y sesiones de reproducción

- **Qué conecta.** [`public/ver.php`](../modulo-visualizacion/public/ver.php), [`public/io-player.js`](../modulo-visualizacion/public/io-player.js) y [`api/reproduccion.php`](../modulo-visualizacion/api/reproduccion.php) aplican reglas de `io_politicas`, `io_accesos` e `io_sesiones`. El panel gestiona cortesías, extensión, reinicio y revocación.
- **Adaptación.** Alinea con el negocio acceso permanente, plazo desde compra o primera reproducción y visualización única. Conserva identidad, vigencia, sesión activa, auditoría y reanudación. La visualización única es un control de acceso del sitio.
- **Aceptación y reversión.** Usa `prueba-flujo.php`, `prueba-condiciones.php` y `prueba-otorgar.php`. Prueba vigencia, reanudación, doble dispositivo y revocación. Mantén un camino para los accesos ya concedidos si se retiran enlaces o se vuelve a la interfaz anterior.

## Comercio, pasarela y conciliación

### 13. Catálogo público, carrito, confirmación y pedidos

- **Qué conecta.** [`tienda/catalogo.php`](../modulo-visualizacion/tienda/catalogo.php), [`tienda/producto.php`](../modulo-visualizacion/tienda/producto.php), [`tienda/carrito.php`](../modulo-visualizacion/tienda/carrito.php), [`tienda/gracias.php`](../modulo-visualizacion/tienda/gracias.php), [`api/tienda.php`](../modulo-visualizacion/api/tienda.php) y [`lib/Tienda.php`](../modulo-visualizacion/lib/Tienda.php) presentan productos y pedidos sobre `io_productos`, `io_pedidos` e `io_pedido_items`.
- **Adaptación.** Mapea URLs, búsqueda, filtros, categorías, sesión del carrito, precios del servidor y retorno tras login. Conserva folios y verificación de propiedad de cada pedido.
- **Aceptación y reversión.** Usa `prueba-tienda.php`, `prueba-confirmacion.php` y `prueba-retorno.js`. Verifica carrito, cotización y estado de cada pedido con dos cuentas. Si se retira el checkout nuevo, restaura las rutas anteriores y conserva confirmaciones de órdenes 2.0 existentes.

### 14. Pasarela original y otorgamiento tras compra

- **Qué conecta.** [`IOAccesos::otorgar()`](../modulo-visualizacion/lib/IO.php) es el punto de incorporación para compras que sigue cobrando el sitio original. Recibe ID, email, nombre, ID del producto, origen y referencia del pedido.
- **Adaptación.** Localiza dónde la pasarela original confirma de forma definitiva y determina quién otorga el acceso para cada pedido. Incluye tarjeta, OXXO, SPEI o confirmación manual solo si existen en la implementación anterior. Verifica folio estable y reintento o conciliación cuando el otorgamiento falle después de un pago confirmado.
- **Aceptación y reversión.** Prueba que un pago pendiente no otorga acceso y que repetir el mismo folio no duplica accesos. Comprueba compra con varios productos y coexistencia con checkout 2.0. Desconecta el adaptador cuando exista un flujo alternativo para otorgar o conciliar los accesos faltantes.

### 15. Stripe, tarjeta y webhook firmado

- **Qué conecta.** [`lib/Tienda.php`](../modulo-visualizacion/lib/Tienda.php) crea y confirma pedidos. [`api/stripe-webhook.php`](../modulo-visualizacion/api/stripe-webhook.php) verifica el evento y despacha a pagos, membresías y reembolsos. El perfil de integración documentado usa API `2024-06-20`.
- **Adaptación.** Configura primero llaves y secreto de prueba, métodos habilitados, HTTPS y los eventos de la [guía de despliegue](../GUIA-DESPLIEGUE.md#52-stripe-primero-en-modo-de-prueba). Preserva monto, moneda, referencia y deduplicación de `io_webhooks`. El regreso del navegador no confirma el pago.
- **Aceptación y reversión.** Usa `prueba-tienda.php` y `prueba-confirmacion.php`. En Stripe de prueba valida tarjeta aprobada, rechazada, eventos repetidos y montos inesperados. Si se detienen nuevos cobros, deja operativo el procesamiento de eventos pendientes hasta conciliarlos.

### 16. OXXO, ficha y pagos pendientes

- **Qué conecta.** El mismo checkout registra el PaymentIntent pendiente. [`IOTienda::fichaOxxo()`](../modulo-visualizacion/lib/Tienda.php) recupera la ficha real y [`tienda/gracias.php`](../modulo-visualizacion/tienda/gracias.php) muestra su estado.
- **Adaptación.** Habilita OXXO en la cuenta correspondiente. Trata la generación de la ficha, su reconsulta y el cobro confirmado como estados distintos. Conserva el cupón reservado hasta cancelación definitiva en la pasarela.
- **Aceptación y reversión.** Genera ficha en Stripe de prueba, valida que siga disponible al volver y confirma que el acceso y el consumo de cupón ocurran al acreditarse el pago. Usa `prueba-tienda.php`, `prueba-confirmacion.php` y `prueba-descuentos.php`. Retira OXXO para nuevas compras cuando proceda y concilia las fichas ya emitidas antes de retirar su seguimiento.

### 17. Cupones, 2×1 y campañas

- **Qué conecta.** [`lib/Descuentos.php`](../modulo-visualizacion/lib/Descuentos.php) calcula y reserva descuentos. [`backoffice/descuentos.php`](../modulo-visualizacion/backoffice/descuentos.php) los administra. [`lib/Promociones.php`](../modulo-visualizacion/lib/Promociones.php) y [`tienda/ofertas.php`](../modulo-visualizacion/tienda/ofertas.php) publican campañas configuradas.
- **Adaptación.** Distingue el precio de campaña del cupón aplicado al pedido. Conserva importes en centavos, restricciones, fechas con zona horaria, cupo y descuentos ya cotizados. Consulta [`DESCUENTOS.md`](../DESCUENTOS.md).
- **Aceptación y reversión.** Usa `prueba-descuentos.php` y `prueba-promociones.php`. Prueba cupo simultáneo, pedidos pendientes, cancelación y pago tardío. Detén nuevas ofertas con sus controles y deja resolver las reservas y pedidos existentes según sus condiciones.

### 18. Membresías, Stripe Billing y portal del cliente

- **Qué conecta.** [`tienda/membresias.php`](../modulo-visualizacion/tienda/membresias.php), [`api/membresias.php`](../modulo-visualizacion/api/membresias.php) y [`lib/Membresias.php`](../modulo-visualizacion/lib/Membresias.php) gestionan Checkout, portal y períodos facturados. `invoice.paid` concede o renueva acceso. Completar Checkout vincula la suscripción.
- **Adaptación.** Mapea planes, Price IDs, precios fijos finales en MXN, intervalos y productos. Configura portal y eventos según [`MEMBRESIAS.md`](../MEMBRESIAS.md). Revisa por separado las suscripciones que ya cobre el sitio original.
- **Aceptación y reversión.** Usa `prueba-membresias.php` y Stripe Test Clocks para alta, renovación, rechazo, cancelación y eventos fuera de orden. Si se detienen altas nuevas, conserva el soporte y la conciliación de suscripciones vigentes hasta acordar su transición.

### 19. Pedidos, cancelaciones y devoluciones

- **Qué conecta.** [`backoffice/pedidos.php`](../modulo-visualizacion/backoffice/pedidos.php), [`lib/Pedidos.php`](../modulo-visualizacion/lib/Pedidos.php) y el webhook de Stripe gestionan devoluciones completas y concilian devoluciones externas. Usan `io_reembolsos`, el pedido y la bitácora.
- **Adaptación.** Preserva los permisos de consulta y operación, el motivo auditado y el importe final del servidor. Cancela definitivamente el PaymentIntent antes de liberar una reserva. Un reembolso parcial se concilia por separado y un CFDI pendiente de cancelación requiere seguimiento. Consulta [`PEDIDOS.md`](../PEDIDOS.md).
- **Aceptación y reversión.** Usa `prueba-pedidos.php`. Prueba respuesta incierta, reintento, devolución total y parcial. Confirma que la total revoca solo los accesos del pedido. Para retirar la operación desde el panel, conserva conciliación y webhooks mientras haya operaciones pendientes.

### 20. CFDI 4.0 y Facturapi

- **Qué conecta.** [`tienda/facturacion.php`](../modulo-visualizacion/tienda/facturacion.php), [`api/facturacion.php`](../modulo-visualizacion/api/facturacion.php) y [`lib/Facturacion.php`](../modulo-visualizacion/lib/Facturacion.php) gestionan solicitudes de compras individuales elegibles, consulta, PDF, XML y cancelación.
- **Adaptación.** Configura Facturapi primero en prueba. El titular de la cuenta y su contador confirman certificados, datos del emisor y códigos SAT. Protege receptor, folio fiscal y propiedad del pedido. Conserva la clave idempotente y concilia resultados inciertos según [`FACTURACION.md`](../FACTURACION.md).
- **Aceptación y reversión.** Usa `prueba-facturacion.php`. Verifica rechazo, reintento, duplicados, descarga del titular y cancelación fiscal previa a devolución cuando corresponda. Si se desactiva emisión nueva, conserva acceso operativo a los documentos y cancelaciones pendientes.

## Presentación, alcance y operación

### 21. Tema, portada, navegación y modales

- **Qué conecta.** [`tema/head-snippet.html`](../tema/head-snippet.html), [`tema/io-theme.css`](../tema/io-theme.css), [`tema/io-theme.js`](../tema/io-theme.js) y [`portada/index.html`](../portada/index.html) incorporan marca, modo oscuro, portada y navegación.
- **Adaptación.** Respeta el orden de CSS, Bootstrap, encabezado, pie, formularios y modales de acceso existentes. Confirma rutas de recursos y comportamiento móvil. Integra primero la capa visual como cambio independiente.
- **Aceptación y reversión.** Revisa escritorio, móvil, teclado, modo claro y oscuro, y formularios de acceso. Quita los includes nuevos o restaura la plantilla anterior sin tocar pedidos ni perfiles.

### 22. Transmisiones y acceso por audiencia

- **Qué conecta.** [`backoffice/transmisiones.php`](../modulo-visualizacion/backoffice/transmisiones.php), [`lib/Transmisiones.php`](../modulo-visualizacion/lib/Transmisiones.php), [`api/transmisiones.php`](../modulo-visualizacion/api/transmisiones.php) y [`tienda/en-vivo.php`](../modulo-visualizacion/tienda/en-vivo.php) usan `io_transmisiones` para agenda, sala, calendario, permisos y grabación.
- **Adaptación.** Mapea audiencias públicas, clientes, compradores o miembros. Confirma huso horario, enlaces de YouTube, Facebook, Vimeo o Zoom y condiciones de inserción de cada proveedor.
- **Aceptación y reversión.** Usa `prueba-transmisiones.php`. Prueba antes, durante y después, y con una cuenta fuera de cada audiencia. Al retirar el enlace nuevo, conserva agenda y URLs de transmisiones ya anunciadas por un canal autorizado.

### 23. Detección automática de YouTube

- **Qué conecta.** [`backoffice/youtube.php`](../modulo-visualizacion/backoffice/youtube.php), [`lib/YouTube.php`](../modulo-visualizacion/lib/YouTube.php) y `io_ajustes` enlazan un canal y detectan directos públicos. La agenda manual cubre las emisiones privadas o no listadas según el acceso configurado.
- **Adaptación.** Confirma el canal, la clave, los límites de cuota, el comportamiento de caché y las reglas de precedencia para directos registrados manualmente.
- **Aceptación y reversión.** Usa `prueba-youtube.php`. Prueba emisión pública, no listada, fallo de cuota y coincidencia con agenda. Quita la vinculación automática si procede y conserva la agenda manual.

### 24. URLs, redirecciones, SEO y agentes de IA

- **Qué conecta.** [`tienda/rewrite-produccion.txt`](../modulo-visualizacion/tienda/rewrite-produccion.txt) define rutas públicas. [`tienda/agentes.php`](../modulo-visualizacion/tienda/agentes.php) y [`lib/Agentes.php`](../modulo-visualizacion/lib/Agentes.php) generan `robots.txt`, sitemap, `llms.txt`, `llms-full.txt` y datos estructurados de recursos públicos.
- **Adaptación.** Levanta redirects, slugs antiguos, URLs de categoría, canónicas, sitemap y robots actuales. Preserva restricciones y rutas privadas. Revisa el efecto de una redirección 301 ya almacenada antes del cambio.
- **Aceptación y reversión.** Usa `prueba-agentes.php` y comprueba respuestas, enlaces históricos y ausencia de datos privados. Restaura el bloque de reglas y archivos públicos anteriores si es necesario. Atiende las URL nuevas que ya pudieron quedar indexadas.

### 25. Monitoreo operativo, reportes y aceptación

- **Qué conecta.** [`backoffice/index.php`](../modulo-visualizacion/backoffice/index.php), [`backoffice/en-vivo.php`](../modulo-visualizacion/backoffice/en-vivo.php), [`backoffice/reportes.php`](../modulo-visualizacion/backoffice/reportes.php) y [`backoffice/bitacora.php`](../modulo-visualizacion/backoffice/bitacora.php) muestran actividad, incidencias y cambios registrados en el módulo.
- **Adaptación.** Asigna responsables de validar accesos, pagos, facturas, devoluciones y transmisiones. Verifica los permisos de quienes consultan datos y los límites del diagnóstico automático.
- **Aceptación y reversión.** Contrasta el tablero con pedidos, accesos y sesiones de ensayo. Si se vuelve al panel anterior, conserva una vía de consulta y conciliación de registros 2.0 para el equipo autorizado.

## Orden para entregar el plan al implementador

1. **Levantamiento.** Identifica rutas, versión de PHP/MySQL, sesión, panel, esquema y pasarela originales, así como copias recuperables de archivos y datos. Distingue cada comprobación realizada de una suposición.
2. **Staging.** Prepara esquema, configuración y diagnóstico. Valida tema, portada, sesión, perfiles y roles antes de habilitar acciones que crean pedidos o accesos.
3. **Datos y accesos.** Simula importación de productos y compras, concilia una muestra y valida biblioteca, reproducción, cortesías y permisos. Define el tratamiento del historial anterior con el responsable.
4. **Comercio.** Define si se mantiene la pasarela anterior, se activa Stripe 2.0 o conviven. Prueba primero la confirmación y el acceso, después OXXO, cupones, suscripciones, devoluciones y CFDI, siguiendo sus dependencias.
5. **Rutas y operación.** Valida redirecciones, menús, recursos públicos y transmisiones. Retira la fase defectuosa sin desatender pedidos, suscripciones, facturas, devoluciones ni accesos ya creados.
6. **Acta de aceptación.** Registra por integración el archivo o ruta, dependencia, prueba, resultado observado, responsable y reversión. Cuando falte evidencia del sitio original, identifica qué debe aportar el técnico. Una app móvil y cualquier inicio de sesión único o proveedor nuevo requieren diseño aparte.
