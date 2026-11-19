# Inteligencia Objetiva 2.0 · Guía de despliegue e integración

Todo se instala **encima** del sitio actual. Nada reemplaza tablas ni archivos existentes, y cada fase se puede revertir en minutos. Haz las fases en orden y prueba entre una y otra.

| Fase | Qué se publica | Riesgo | Cómo se revierte |
| --- | --- | --- | --- |
| 1 | Tema: identidad, modo oscuro, banner de ofertas | Bajo (solo CSS/JS) | Quitar 3 líneas de la plantilla |
| 2 | Portada 2.0 | Bajo | Volver a la portada anterior |
| 3 | Módulo `/io/` (biblioteca, reproductor, backoffice) | Medio | Ocultar el enlace "Mi biblioteca" y borrar `/io/` |
| 4 | Conectar compras y migrar accesos vigentes | Medio | `DELETE FROM io_accesos WHERE origen='migracion'` |
| 5 | Tienda 2.0: Nosotros, catálogo, ficha, carrito y pago con Stripe | Medio | Comentar el bloque de reglas de `tienda/rewrite-produccion.txt` |

**Requisitos:** PHP 7.4+ (8.x recomendado) con PDO MySQL y cURL, MySQL 5.7 o 8.0, HTTPS, acceso por SSH o terminal al servidor.

---

## 0. Antes de empezar

1. Respalda la base de datos y los archivos del sitio.
2. Prueba primero en un entorno de staging si existe. Si no, publica en horario de bajo tráfico.
3. En Vimeo, para cada video de pago:
   - **Privacidad:** "Ocultar de Vimeo".
   - **Insertar:** "Solo en dominios específicos" → `inteligenciaobjetiva.com`.
   - **Descargas:** desactivadas (opción "Permitir descargas" apagada).
4. Opcional: crea un token de la API de Vimeo (Settings → Apps → Generate token, permisos *public* y *private*) para que el backoffice lea la duración de cada video.

---

## Fase 1 · Tema (identidad, modo oscuro y banner de ofertas)

1. Sube la carpeta `tema/` a la raíz del sitio (`/tema/`).
2. En la plantilla común (el archivo que tiene `<head>` en todas las páginas):
   - **Primero dentro de `<head>`**, antes de los CSS, el script de `tema/head-snippet.html` que evita el parpadeo.
   - **Después de los CSS actuales** (`style.css`, `styleluis.css`, swiper):
     ```html
     <link rel="stylesheet" href="/tema/io-theme.css?v=2.0.0">
     ```
   - **Al final del `<body>`**, después de los scripts actuales:
     ```html
     <script src="/tema/io-theme.js?v=2.0.2" defer></script>
     ```
3. Comprueba:
   - [ ] Aparece el botón sol/luna junto a "Ayuda" y cambia el tema.
   - [ ] El banner de ofertas se ve compacto, con contador en recuadros y una × que lo oculta por el resto del día.
   - [ ] Ya no hay scroll horizontal.
   - [ ] Los productos con (0) reseñas dicen "Sin reseñas aún".
   - [ ] Modales de inicio de sesión, carrito y formularios se leen en modo oscuro.

`tema/demo-banner.html` es una réplica del banner para revisar cambios en local; no se publica.

---

## Fase 2 · Portada 2.0

1. Sube `portada/portada.css`, `portada/portada.js` y `portada/img/*.webp`. Los `.png` originales no se suben.
2. En la plantilla de la portada actual:
   - Agrega `<link rel="stylesheet" href="/portada/portada.css">` después de `io-theme.css`, y `<script src="/portada/portada.js?v=2.0.1" defer></script>` al final.
   - Agrega la clase `io2` al `<body>` de la portada.
   - Reemplaza el contenido entre el encabezado y el pie actuales por las secciones de `portada/index.html` (de `<!-- SECCIÓN: HÉROE -->` a `<!-- SECCIÓN: CIERRE -->`).
   - **No copies** el bloque `preview-nav` ni las etiquetas `<link>` de Bootstrap: el sitio ya los tiene.
   - Cambia las rutas `img/...` por `/portada/img/...`.
3. La sección "¿Qué es…?" ya usa el video público de presentación del sitio (`data-youtube="o2PTnU-NHS4"`), con carga bajo demanda en `youtube-nocookie.com`. Si el propietario cambia el video, actualiza ese ID y conserva el enlace alternativo.
4. Comprueba en celular y escritorio, en modo claro y oscuro.

---

## Fase 3 · Módulo `/io/`

### 3.1 Archivos
Sube `modulo-visualizacion/` como `/io/` (por ejemplo `public_html/io/`), **sin** `config.php` ni `tests/`.

### 3.2 Configuración
```bash
cd /ruta/al/sitio/io
cp config.example.php config.php
chmod 640 config.php
```
Edita `config.php`:
- `db`: los mismos datos de conexión que usa hoy el sitio.
- `vimeo_token`: opcional.
- `cliente_actual`: **ajusta las claves de `$_SESSION`** a las que usa el sitio para el cliente con sesión (id, correo y nombre). Para encontrarlas, revisa el código de inicio de sesión actual (búsqueda: `$_SESSION[`).
- `dev`: `false`.

### 3.3 Base de datos
```bash
mysql -u USUARIO -p NOMBRE_BD < sql/001_io_visualizacion.sql
mysql -u USUARIO -p NOMBRE_BD < sql/002_io_login_intentos.sql
mysql -u USUARIO -p NOMBRE_BD < sql/003_io_tienda.sql
mysql -u USUARIO -p NOMBRE_BD < sql/004_io_membresias.sql
mysql -u USUARIO -p NOMBRE_BD < sql/005_io_descuentos.sql
mysql -u USUARIO -p NOMBRE_BD < sql/006_io_facturacion.sql
mysql -u USUARIO -p NOMBRE_BD < sql/007_io_reembolsos.sql
mysql -u USUARIO -p NOMBRE_BD < sql/008_io_transmisiones.sql
```
Solo crean tablas nuevas con prefijo `io_`.

### 3.4 Primer usuario del backoffice
```bash
php bin/crear-admin.php tu-correo@dominio.com "Tu nombre" admin
```
La contraseña se pide en la terminal y debe tener mínimo 12 caracteres. Roles disponibles: `admin`, `editor`, `soporte` y `finanzas`.

### 3.5 Protección de carpetas internas
- **Apache:** el `.htaccess` incluido bloquea `lib/`, `sql/`, `bin/`, `tests/` y `config.php`. Requiere `mod_rewrite` y `AllowOverride All`.
- **nginx:** en el bloque `http {}` declara la zona de límite (una sola vez):
  ```nginx
  limit_req_zone $binary_remote_addr zone=io_login:10m rate=5r/m;
  ```
  En `server {}` bloquea las rutas internas:
  ```nginx
  location ~ ^/io/(lib|sql|bin|tests)(/|$) { deny all; }
  location ~ ^/io/config(\.example)?\.php$ { deny all; }
  ```
  Para limitar el login, configura una ubicación PHP exacta para `/io/backoffice/login.php` con `limit_req zone=io_login burst=5 nodelay;`, conservando los parámetros FastCGI, `SCRIPT_FILENAME` y el upstream/socket PHP-FPM reales del sitio. No sustituyas ese procesamiento por una ubicación que sirva el archivo PHP como texto. Ejecuta `nginx -t` antes de recargar el servicio.
- Verifica: `https://inteligenciaobjetiva.com/io/config.php` y `/io/lib/IO.php` deben responder **403**.
- En `php.ini`: `display_errors = Off` y `log_errors = On`.

### 3.6 Comprobación
- [ ] `https://inteligenciaobjetiva.com/io/backoffice/` → inicio de sesión → tablero.
- [ ] Desde una cuenta de cliente con sesión: `/io/public/mi-biblioteca.php` carga, aunque todavía esté vacía.

---

## Fase 4 · Conectar compras y migrar

### 4.1 Cargar productos y reglas
Ajusta `importar.sql_productos` y `regla_por_categoria` en `config.php` a las tablas reales y prueba sin escribir:
```bash
php bin/importar.php productos --simular
php bin/importar.php productos
```
Después, en **Backoffice → Contenido**, abre **Editar** para revisar la ficha en el editor modal. Sus secciones Detalles, Video, Acceso y Publicación permiten ajustar metadatos, Vimeo, precio y visibilidad. En Acceso marca como **Visualización única** los cursos elegidos y ajusta umbral, retroceso y reanudaciones. Cada guardado requiere motivo y registra ficha y reglas en una transacción; si otro editor modificó la ficha, se rechaza la versión anterior para evitar sobrescrituras.

Para altas desde una hoja de cálculo, entra a **Contenido → Carga masiva** y descarga la plantilla CSV UTF-8. Completa un ID nuevo y título por fila, junto con los campos opcionales disponibles, guarda como CSV y genera la vista previa. Se admiten coma o punto y coma, hasta 200 contenidos y 2 MB por archivo. Corrige todas las incidencias antes de confirmar: la carga es atómica, solo crea borradores nuevos y nunca reemplaza IDs existentes. Revisa después cada borrador en el editor y publícalo cuando tenga video y precio aprobados. La carga conecta videos existentes en Vimeo; no sube archivos de video o imagen.

Las siguientes importaciones conservan esas reglas y los campos comerciales omitidos por la consulta. Para reaplicar deliberadamente las reglas por categoría usa `php bin/importar.php productos --actualizar-reglas`; revisa antes `--simular`. El simulador informa precios MXN con IVA y contenido faltante. La salida `2` indica filas inválidas omitidas: corrígelas y repite antes de publicar.

### 4.2 Crear el acceso al confirmarse un pago
En el punto del código actual donde un pago queda **confirmado** (webhook de la pasarela, confirmación de OXXO/SPEI o validación manual), agrega por cada producto pagado:
```php
require_once $_SERVER['DOCUMENT_ROOT'] . '/io/lib/IO.php';
try {
    IOAccesos::otorgar($idCliente, $correoCliente, $nombreCliente, $idProducto, 'compra', $folioPedido);
} catch (Throwable $e) {
    error_log('[IO] no se pudo otorgar acceso: ' . $e->getMessage()); // el pago no se interrumpe
}
```
Para membresías usa `'membresia'` como origen y para regalos `'regalo'`.

### 4.3 Migrar compras vigentes
```bash
php bin/importar.php compras --simular
php bin/importar.php compras
```
Es idempotente: si se corre dos veces, no duplica.

### 4.4 Enlazar la biblioteca
- Cambia el enlace "Mi contenido" del perfil por `/io/public/mi-biblioteca.php`.
- O incrústala en la plantilla del perfil:
  ```php
  <?php define('IO_EMBEBIDO', true); include $_SERVER['DOCUMENT_ROOT'] . '/io/public/mi-biblioteca.php'; ?>
  ```

### 4.5 Prueba de punta a punta (en producción, con una cuenta interna)
1. Otorga una cortesía de un curso de visualización única desde **Backoffice → Accesos → Otorgar acceso**.
2. Con esa cuenta abre la biblioteca, acepta la regla y reproduce.
3. En **Backoffice → En vivo** debe aparecer la reproducción.
4. Cierra la pestaña: el acceso pasa a "En gracia" y al volver continúa donde quedó.
5. Reinicia la visualización desde el backoffice con motivo y revisa la **Bitácora**.

---

## Fase 5 · Tienda 2.0 y pasarela de pago (Stripe)

La tienda nueva usa las mismas URL del sitio (`/nosotros`, `/conferencias-en-linea`, `/producto/...`, `/carrito`). Primero se publica en `/io/tienda/` para probarla, y solo al aprobarla se activan las URL públicas.

### 5.1 Base de datos y catálogo
```bash
mysql -u USUARIO -p NOMBRE_BD < sql/003_io_tienda.sql
php bin/importar.php productos --simular
php bin/importar.php productos
```
Para que el catálogo se llene, la consulta `importar.sql_productos` debe devolver también `precio_mxn` (con IVA), `resumen`, `descripcion`, `imagen` y `tema`; el ejemplo está en `config.example.php`. También puede devolver `aprenderas` (una idea por línea), `trailer_vimeo` e `incluye`; si no los devuelve, el importador conserva el contenido ya cargado en la base.

### 5.2 Stripe (primero en modo de prueba)
1. Crea o abre la cuenta en dashboard.stripe.com y activa **tarjeta** y **OXXO** en *Settings → Payment methods*.
2. Copia las llaves de **prueba** (`pk_test_…`, `sk_test_…`) a `config.php → tienda`.
3. En *Developers → Webhooks* agrega el endpoint `https://inteligenciaobjetiva.com/io/api/stripe-webhook.php` con versión de API **2024-06-20**. Para compras individuales: `payment_intent.succeeded`, `payment_intent.payment_failed`, `payment_intent.canceled`, `payment_intent.requires_action`. Para membresías: `checkout.session.completed`, `invoice.paid`, `invoice.payment_failed`, `customer.subscription.updated`, `customer.subscription.deleted`. Para devoluciones: `charge.refunded`, `refund.updated`, `refund.failed`. Copia el *Signing secret* (`whsec_…`) a `tienda.stripe_webhook`.
4. Prueba en `/io/tienda/carrito.php`:
   - [ ] Tarjeta de prueba `4242 4242 4242 4242` (cualquier fecha futura y CVC): el pedido queda pagado y el acceso aparece en la biblioteca.
   - [ ] Tarjeta `4000 0000 0000 0002`: pago rechazado, sin acceso.
   - [ ] OXXO: se muestra la ficha; al simular el pago desde el panel de Stripe, el webhook activa el acceso.
   - [ ] Abre nuevamente la confirmación del pedido: la referencia OXXO y el enlace al código de barras deben ser los emitidos por Stripe, con su fecha de vencimiento. Antes de recibir el webhook de pago, no debe existir acceso al contenido.
   - [ ] Sin sesión, pulsa **Iniciar sesión** y **Crear cuenta** desde el carrito: deben abrir las ventanas existentes de la portada. Después de iniciar sesión, usa **Volver a mi carrito** para continuar con los productos conservados.
5. Cuando todo funcione, cambia a las llaves **en vivo** (`pk_live_…`, `sk_live_…`) y crea el webhook equivalente en modo en vivo.

Los pagos solo se confirman por el webhook firmado: el navegador nunca marca un pedido como pagado. Los precios se leen de la base en el servidor, se validan contra el monto cobrado y cada evento se procesa una sola vez. Sin llaves configuradas, en producción no se puede pagar. La simulación exige `dev=true` y ejecución CLI o servidor PHP de desarrollo con cliente y servidor en loopback; configurar el dominio como localhost por sí solo no la habilita.

La ficha OXXO se recupera desde el servidor con la llave secreta, después de verificar que el pedido pertenece al cliente. Esa consulta no confirma el pago ni otorga accesos. El registro del evento y la activación de los accesos se guardan en una misma transacción; si fallan, Stripe puede reintentar el evento completo.

Los botones de acceso reutilizan los modales `#login` y `#registro` del sitio actual. Publica también `tema/io-theme.js?v=2.0.2` en la portada para que se abran al llegar desde el carrito, membresías, biblioteca o pedidos y se conserve el destino correcto tras la recarga del inicio de sesión. Los destinos se validan contra una lista interna, sin aceptar redirecciones externas.

### 5.3 Activar las URL públicas
Cuando la tienda esté aprobada, agrega el bloque de `tienda/rewrite-produccion.txt` al `.htaccess` de la raíz, antes de las reglas actuales (en nginx están las equivalentes en el mismo archivo). Las fichas antiguas (`/conferencias-en-linea/titulo-410`) redirigen con 301 a la ficha nueva para no perder posicionamiento. Para volver atrás, comenta ese bloque.

### 5.4 Módulos comerciales configurables
- **Membresías recurrentes:** `membresias` en `config.php`, precios fijos MXN con IVA y productos incluidos; `/io/tienda/membresias.php`. Activar solo después de probar renovación, rechazo y cancelación. Configurar el portal para método de pago y cancelación al final, sin cambio de plan ni prorrateos. Ver `MEMBRESIAS.md`.
- **Cupones y 2×1:** Backoffice → Cupones y 2×1. No hay promociones activas por defecto. Definir fechas, productos, cupos y motivo. El servidor reserva el cupo y guarda importes finales por concepto; una reserva solo se libera tras cancelación definitiva. Ver `DESCUENTOS.md`.
- **CFDI 4.0 autoservicio:** `facturacion` en `config.php`, proveedor Facturapi, cuenta/CSD del emisor y claves SAT aprobadas por su contador. El comprador solicita el comprobante desde Mis pedidos y facturas, proporciona datos fiscales, y el servidor lo emite y permite descargar PDF/XML sin duplicarlo tras timeout. Ver `FACTURACION.md`. Las facturas de Stripe Billing no equivalen a un CFDI: esta emisión integrada corresponde a pedidos individuales nacionales pagados, con IVA al 16%; otros tratamientos fiscales deben gestionarse con el contador.
- **Pedidos y devoluciones:** Backoffice → Pedidos y devoluciones. Habilitar `pedidos.reembolsos_habilitados` después de probar Stripe. La interfaz devuelve el total pagado, concilia avisos externos y revoca el acceso al confirmar la devolución. Ver `PEDIDOS.md` para cancelación fiscal y devoluciones parciales externas.

### 5.5 Campañas con fechas y rebajas reales
`config.example.php → tienda.promocion` viene desactivado. El responsable comercial debe completar `inicio` y `fin` con fechas absolutas ISO y zona horaria (formato `AAAA-MM-DDTHH:MM:SS-06:00`), además de los IDs aprobados en `productos`. El precio efectivo y el precio anterior se cargan en `io_productos` desde la fuente real, en MXN con IVA. La campaña no modifica los importes del carrito: anuncia únicamente rebajas ya presentes en el catálogo, calcula el porcentaje desde ambos precios y dirige a `/io/tienda/ofertas.php`. Sin fechas vigentes o productos realmente rebajados, el banner permanece oculto. Las fechas no se renuevan al recargar.

### 5.6 Diagnóstico antes de publicar
Desde `/io/` ejecuta `php bin/diagnostico.php`. Solo lee configuración, esquema y catálogo; comprueba las tablas de las migraciones 001–008, no imprime llaves, no escribe datos ni crea cargos. Devuelve `1` si encuentra pendientes automáticos y `0` si pasan esas comprobaciones. Los módulos externos vienen desactivados y se informan por separado. Los puntos marcados `MANUAL` siempre deben revisarse en el sitio instalado.

Con llaves de prueba configuradas puedes ejecutar `php bin/diagnostico.php --stripe-prueba`: consulta la conectividad de Stripe sin crear pagos. Rechaza esta consulta con llaves en vivo. Esta comprobación no reemplaza las tres compras de aceptación del apartado 5.2.

### 5.7 Transmisiones en vivo (YouTube, Facebook, Vimeo y Zoom)
Requiere la migración `008_io_transmisiones.sql` y la regla `/en-vivo` y `/calendario` de `tienda/rewrite-produccion.txt`. Se administra en **Backoffice → Transmisiones en vivo** (permiso de políticas); cada alta, cambio, inicio o cierre queda en la bitácora.

1. **Nueva transmisión:** pega el enlace del directo, la fecha y hora (centro de México) y la duración. El sistema reconoce la plataforma: YouTube (`youtube.com/live/…`, `youtu.be/…`, `watch?v=…`), Facebook (`facebook.com/…/videos/…`, `fb.watch/…`), Vimeo (video o `vimeo.com/event/…`) y Zoom (`zoom.us/j/…`).
2. **¿Quién puede verla?** Pública, con cuenta, compradores de un contenido o miembros con membresía activa. Para una transmisión exclusiva en YouTube, márcala como **No listada** en YouTube Studio; el sitio solo entrega el reproductor a quien tiene acceso. Facebook solo admite transmisiones **públicas**: el sistema rechaza otra opción. En Zoom el enlace para unirse solo se muestra a quien tiene acceso.
3. **Al aire:** entra al aire sola a su hora y sale al terminar su duración. También puedes usar **Iniciar ahora** o **Terminar** desde el backoffice. La página abre 15 minutos antes con cuenta regresiva y se recarga sola al comenzar.
4. **Aviso en todo el sitio:** mientras hay una transmisión al aire, `tema/io-theme.js` muestra una barra "En vivo · Ver ahora" (consulta `/io/api/transmisiones.php?accion=ahora`, con caché de 30 s; no expone el enlace del video). Para desactivarla en una página, define `window.IO_EN_VIVO_API = false` antes del script.
5. **Calendario:** cada transmisión próxima ofrece "Recordarme" (archivo `.ics` con alarma 15 min antes) para Google Calendar, Outlook o Apple.
6. **Grabación:** al terminar, elige en "Grabación en el catálogo" el producto donde quedó la grabación; la página muestra "Ver grabación".

El chat de YouTube se inserta junto al video cuando el directo lo tiene activo. Si la transmisión es de un canal con restricciones de inserción, actívala en YouTube Studio → Configuración del directo → Permitir inserción.

### 5.8 Editor de contenido y miniaturas
Backoffice → Contenido abre el editor de cada contenido en tres pestañas (Detalles, Precio y venta, Acceso), con el video de Vimeo y la visibilidad siempre a la derecha. Pegar o arrastrar el enlace de Vimeo sobre el video llena título, duración y miniatura. Las categorías y temas se eligen con un clic; el precio muestra el desglose de IVA, la oferta calcula el descuento y se ve la tarjeta tal como saldrá en el catálogo. Al guardar se propone un motivo con los campos que cambiaron (editable) y queda en la bitácora.

**Miniaturas con arrastrar y soltar:** el navegador recorta la imagen a 16:9 y la reduce a 1280 × 720 antes de subirla. El servidor acepta solo JPG, PNG o WebP reales de hasta 4 MB, los guarda con nombre aleatorio en `/io/subidas/miniaturas/` y, si PHP tiene GD, los vuelve a codificar. Requisitos al desplegar:
- La carpeta `io/subidas/miniaturas` debe poder escribirla PHP (`chmod 755` y propietario del usuario de PHP). Si cambias `miniaturas_dir` y `miniaturas_url` en `config.php`, la carpeta debe quedar **dentro de** `io/subidas/` para conservar su protección.
- PHP: `upload_max_filesize` y `post_max_size` de al menos `4M`. Si el servidor tiene GD, la imagen se vuelve a codificar y se reduce a 1280 px; las de más de 4096 px por lado se rechazan.
- Apache usa el `io/subidas/.htaccess` incluido: solo sirve imágenes con nombre aleatorio y nunca ejecuta código. Requiere `AllowOverride All` (o al menos `AuthConfig FileInfo Options`) en esa ruta; si el hosting lo ignora, la protección no aplica.
- En nginx, dentro del `server` y **antes** de la regla de PHP:
  ```nginx
  location ^~ /io/subidas/ {
    location ~ "^/io/subidas/miniaturas/[a-f0-9]{24}\.(webp|jpg|png)$" {
      add_header X-Content-Type-Options nosniff always;
      add_header Content-Security-Policy "default-src 'none'" always;
      # Repite aquí los add_header globales del server (por ejemplo HSTS): nginx no los hereda si la location define los suyos.
    }
    return 403;
  }
  ```
  Comprueba que `mime.types` incluya `image/webp`.
- **Comprobación tras desplegar:** `curl -I https://SITIO/io/subidas/prueba.php` debe responder 403, y una miniatura subida debe llegar con `Content-Type: image/webp` y la cabecera `Content-Security-Policy`.
- Las imágenes que se suben y no se usan se borran con `php bin/limpiar-miniaturas.php --borrar` (solo las de más de 24 h sin contenido ni transmisión que las use; sin `--borrar` solo las lista). Sugerido en cron una vez al día. Cada sesión puede subir hasta 60 imágenes por hora.
- Respalda esa carpeta junto con la base de datos.

## Demo local para revisión sin accesos del cliente
Desde el repositorio, en Windows, ejecuta `./modulo-visualizacion/bin/iniciar-demo.ps1`. Requiere PHP con PDO MySQL, cURL y mbstring, y una instancia **de pruebas** MySQL en `127.0.0.1:3307` con usuario `root` sin contraseña. Si no existe, el instalador local debe preparar esa instancia separada: el script no instala ni modifica el MySQL del sistema.

La demo usa exclusivamente `io_demo`, no modifica `config.php` y guarda sesiones en `%LOCALAPPDATA%\Codex\Temp\io-demo\sesiones` tras verificar la ruta. Abre la URL que muestra la terminal y entra como alumna demo para probar tarjeta, rechazo y OXXO simulados. El backoffice local usa `demo-admin@ejemplo.test` / `demo-local-12345`. Esos datos son públicos y ficticios; no deben usarse en una instalación real.

Una demo existente se conserva. `-ReiniciarDatos` borra y recrea **solo** `io_demo`; úsalo únicamente si quieres perder los datos ficticios de las pruebas. Detén el servidor con Ctrl+C. El router y el lanzador local no se incluyen en el ZIP de producción.

## Pruebas automáticas (local o staging)
```bash
# MySQL de prueba (nunca producción)
IO_TEST_DSN="mysql:host=127.0.0.1;port=3307;charset=utf8mb4" IO_TEST_USER=root IO_TEST_PASS= php tests/prueba-flujo.php
```
Debe terminar en `0 fallidas`. Lo mismo para la tienda: `php tests/prueba-tienda.php` (catálogo, totales, pedidos y webhooks de Stripe). La prueba del módulo cubre permisos, latidos acelerados, retroceso y adelanto, doble dispositivo, gracia, reanudaciones, ventanas de 48 h, acciones del backoffice y reversión si falla la bitácora.

En el repositorio también están `prueba-condiciones.php`, `prueba-promociones.php`, `prueba-importacion.php` y `prueba-importacion-bd.php`. Las tres primeras no requieren base de datos. La última usa una base aleatoria `io_prueba_importacion_<id>` en la instancia local 3307 y la elimina al terminar. No ejecutes ninguna suite con una conexión de producción.

Las suites `prueba-descuentos.php`, `prueba-membresias.php`, `prueba-facturacion.php` y `prueba-pedidos.php` utilizan bases exclusivas y proveedores simulados; cubren concurrencia, descuentos en centavos, renovaciones, eventos fuera de orden, facturas, conciliación, permisos e idempotencia. La validación local no sustituye la aceptación de las cuentas Stripe/Facturapi del cliente.

`php tests/prueba-miniaturas.php` comprueba que solo se acepten imágenes reales (rechaza texto, PHP disfrazado, SVG, archivos vacíos o mayores de 4 MB). `php tests/prueba-transmisiones.php` cubre el reconocimiento de enlaces, reproductores, permisos por tipo de acceso, horarios, calendario y bitácora. Para la demo, `php tests/sembrar-transmisiones.php` agrega cinco transmisiones de ejemplo a `io_demo`.

`php tests/prueba-confirmacion.php` verifica las pantallas terminales de pedidos cancelados/reembolsados, la privacidad y la conservación del carrito. `node tests/prueba-retorno.js` comprueba los destinos del acceso, las restricciones de redirección y el comportamiento con almacenamiento bloqueado, sin dependencias adicionales.

## Límite conocido
La visualización única es **control de acceso**. La plataforma impide volver a reproducir el contenido desde el sitio, pero un usuario técnico que capture el enlace del reproductor de Vimeo durante su única reproducción podría reutilizarlo dentro del dominio permitido.

Mitigaciones incluidas:
- Restricción de dominio en Vimeo.
- Marca de agua con el correo del alumno.
- Sesión única.
- Alertas en el backoffice.
