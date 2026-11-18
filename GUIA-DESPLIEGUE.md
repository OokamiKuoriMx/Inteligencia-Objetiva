# Inteligencia Objetiva 2.0 · Guía de despliegue e integración

Todo se instala **encima** del sitio actual. Nada reemplaza tablas ni archivos existentes, y cada fase se puede revertir en minutos. Haz las fases en orden y prueba entre una y otra.

| Fase | Qué se publica | Riesgo | Cómo se revierte |
| --- | --- | --- | --- |
| 1 | Tema: identidad, modo oscuro, banner de ofertas | Bajo (solo CSS/JS) | Quitar 3 líneas de la plantilla |
| 2 | Portada 2.0 | Bajo | Volver a la portada anterior |
| 3 | Módulo `/io/` (biblioteca, reproductor, backoffice) | Medio | Ocultar el enlace "Mi biblioteca" y borrar `/io/` |
| 4 | Conectar compras y migrar accesos vigentes | Medio | `DELETE FROM io_accesos WHERE origen='migracion'` |

**Requisitos:** PHP 7.4+ (8.x recomendado) con PDO MySQL y cURL, MySQL 5.7 o 8.0, HTTPS, acceso por SSH o terminal al servidor.

---

## 0. Antes de empezar

1. Respalda la base de datos y los archivos del sitio.
2. Prueba primero en un entorno de staging si existe. Si no, publica en horario de bajo tráfico.
3. En Vimeo, para cada video de pago:
   - **Privacidad:** "Ocultar de Vimeo".
   - **Insertar:** "Solo en dominios específicos" → `inteligenciaobjetiva.com`.
   - **Descargas:** desactivadas.
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
     <script src="/tema/io-theme.js?v=2.0.0" defer></script>
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
   - Agrega `<link rel="stylesheet" href="/portada/portada.css">` después de `io-theme.css`, y `<script src="/portada/portada.js" defer></script>` al final.
   - Agrega la clase `io2` al `<body>` de la portada.
   - Reemplaza el contenido entre el encabezado y el pie actuales por las secciones de `portada/index.html` (de `<!-- SECCIÓN: HÉROE -->` a `<!-- SECCIÓN: CIERRE -->`).
   - **No copies** el bloque `preview-nav` ni las etiquetas `<link>` de Bootstrap: el sitio ya los tiene.
   - Cambia las rutas `img/...` por `/portada/img/...`.
3. En la sección "¿Qué es…?", cambia `data-vimeo="REEMPLAZAR_ID_VIMEO"` por el ID del video de presentación en Vimeo.
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
```
Solo crean tablas nuevas con prefijo `io_`.

### 3.4 Primer usuario del backoffice
```bash
php bin/crear-admin.php tu-correo@dominio.com "Tu nombre" admin
```
La contraseña se pide en la terminal y debe tener mínimo 12 caracteres. Roles disponibles: `admin`, `editor`, `soporte` y `finanzas`.

### 3.5 Protección de carpetas internas
- **Apache:** el `.htaccess` incluido bloquea `lib/`, `sql/`, `bin/`, `tests/` y `config.php`. Requiere `mod_rewrite` y `AllowOverride All`.
- **nginx:** agrega al `server {}`:
  ```nginx
  location ~ ^/io/(lib|sql|bin|tests)/ { deny all; }
  location ~ ^/io/config(\.example)?\.php$ { deny all; }
  # Freno extra al login del backoffice
  limit_req_zone $binary_remote_addr zone=io_login:10m rate=5r/m;   # (en el bloque http)
  location = /io/backoffice/login.php { limit_req zone=io_login burst=5 nodelay; include fastcgi_params; fastcgi_pass php; }
  ```
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
Después, en **Backoffice → Catálogo y reglas**, marca como **Visualización única** los cursos elegidos y ajusta umbral, retroceso y reanudaciones.

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

## Pruebas automáticas (local o staging)
```bash
# MySQL de prueba (nunca producción)
IO_TEST_DSN="mysql:host=127.0.0.1;port=3307;charset=utf8mb4" IO_TEST_USER=root IO_TEST_PASS= php tests/prueba-flujo.php
```
Debe terminar en `0 fallidas`. Cubre permisos, latidos acelerados, retroceso y adelanto, doble dispositivo, gracia, reanudaciones, ventanas de 48 h, acciones del backoffice y reversión si falla la bitácora.

## Límite conocido
La visualización única es **control de acceso, no DRM**. La plataforma impide volver a reproducir el contenido desde el sitio, pero un usuario técnico que capture el enlace del reproductor de Vimeo durante su única reproducción podría reutilizarlo dentro del dominio permitido.

Mitigaciones incluidas:
- Restricción de dominio en Vimeo.
- Marca de agua con el correo del alumno.
- Sesión única.
- Alertas en el backoffice.

Para protección total se requiere DRM; hay que evaluarlo con Vimeo (planes Enterprise/OTT).
