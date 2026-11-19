# Plan: app móvil de alumnos de Inteligencia Objetiva

Fecha del plan: 18 de noviembre de 2026. Estado: **propuesta, sin desarrollo iniciado.**

## Resumen para el dueño

- Hoy **no existe** una app de Inteligencia Objetiva. Sin embargo, la portada del sitio en producción dice «Nuestra app ya está disponible con tus temas favoritos» y muestra los botones de App Store y Google Play (consultado el 18/11/2026). **Conviene quitar o cambiar ese texto ya**: los alumnos que lo buscan no encuentran nada, y además se ve mal ante los revisores de Apple y Google.
- Proponemos una sola app para iPhone y Android, hecha con React Native y Expo. Se puede compilar desde Windows usando los servidores de Expo (EAS Build).
- **Versión 1, para ver.** El alumno inicia sesión con su cuenta del sitio, ve *Mi biblioteca* y reproduce con las mismas reglas de la web: ventana de 48 h, permanente y visualización única. Ve cuándo vence cada contenido y recibe avisos antes del vencimiento. Las compras siguen en la web, y la app **no puede mencionarlas** (lo explica la sección 5).
- **Versión 2, para comprar.** Agrega catálogo, ficha y compra con el sistema de Apple o Google. Para un negocio de este tamaño, cada tienda cobra 15 % (sección 5). El servidor valida cada compra y crea el acceso con el mismo flujo de la tienda web.
- Tiempo de desarrollo del agente: de 3.5 a 5.5 días. Tiempo en calendario: **de 1 a 2 semanas para la versión 1 y de 2 a 3 para la versión 2**, porque lo que más tarda es externo: verificar las cuentas de desarrollador, compilar, probar en teléfonos reales y pasar la revisión de las tiendas.
- Lo más urgente para el dueño: decidir **a nombre de quién** van las cuentas de las tiendas (empresa o persona) y empezar hoy el trámite del número D-U-N-S, si es empresa.

---

## 1. Alcance por versión

### Versión 1: ver contenido (las compras siguen en la web)

| Función | Detalle |
|---|---|
| Inicio de sesión | El alumno usa el correo y la contraseña de su cuenta del sitio. No se crean cuentas en la app. «¿Olvidaste tu contraseña?» abre la página de recuperación del sitio. |
| Mi biblioteca | Los mismos accesos que ve el alumno en `/io/public/mi-biblioteca.php`: título, tipo, estado (por empezar, activo, en gracia, visto, vencido), progreso y reanudaciones restantes. Se puede filtrar por estado y deslizar hacia abajo para actualizar. |
| Reproductor | Vimeo dentro de la app (sección 2.3), con las mismas reglas del sitio. La **ventana de 48 h** empieza con el primer play. El acceso **permanente** no vence. La **visualización única** manda latidos cada 15 s, tiene reanudaciones limitadas, periodo de gracia, límite de pausa y control de adelanto y retroceso. Se mantiene la marca de agua con el correo del alumno y la sesión única: abrir un contenido en el teléfono cierra la sesión abierta en la web, igual que pasa hoy entre dos navegadores. |
| Vencimientos | Fecha y hora de vencimiento en hora del centro de México, con una cuenta regresiva que se calcula contra el reloj del servidor. Para los accesos que aún no empiezan, la app también muestra la fecha límite para iniciar. |
| Avisos push | Avisos 24 h y 2 h antes de que venza un acceso, y antes de la fecha límite para iniciar. El alumno los puede apagar en *Ajustes*. |
| Modo claro y oscuro | La app sigue el modo del sistema o el que elija el alumno, y usa los mismos tokens del sitio: rojo `#cf1717`, marino `#272256`, grafito `#2c2f30`, Roboto (con Roboto 300 para los titulares grandes, como el sitio actual) y el logo iO con la firma «Martín Aparicio». |
| Cuenta | Perfil (nombre y correo), cerrar sesión, cerrar la sesión en todos los dispositivos y **eliminar la cuenta**, que exigen ambas tiendas (sección 5.5). |
| Sin compras | La app no muestra precios, carrito, cupones ni botones o enlaces a la tienda web. Si la biblioteca está vacía, muestra un texto neutro como «Aquí aparecerán tus contenidos». |

### Versión 2: comprar en la app

| Función | Detalle |
|---|---|
| Catálogo | Muestra los productos de `io_productos` que el dueño marque como «disponibles en la app», con categorías y búsqueda. **El precio viene de la tienda (Apple o Google), no de la base de datos.** |
| Ficha | Imagen, resumen, descripción, tipo de acceso explicado («Tienes 48 h desde que das play», «Visualización única») y el botón de comprar. |
| Compra | Se paga con StoreKit en iPhone y con Google Play Billing en Android. El servidor **valida el recibo** con Apple o Google, crea el pedido y otorga el acceso con el mismo `confirmarPago()` de la tienda web. Una compra repetida no duplica el acceso. |
| Restaurar compras | Lo exige Apple para las compras no consumibles. La app vuelve a validar las transacciones y relaciona los accesos con la cuenta. |
| Reembolsos | Si Apple o Google avisan que reembolsaron una compra, el servidor revoca el acceso y lo deja registrado en la bitácora. |
| Fuera de alcance | Las membresías como suscripción dentro de la app, los cupones (Apple y Google tienen sus propios códigos de oferta) y la factura CFDI de estas compras. En las compras dentro de la app, el vendedor ante el comprador es Apple o Google; el contador debe confirmar el tratamiento fiscal. |

---

## 2. Arquitectura

### 2.1 Stack recomendado

| Capa | Elección | Por qué |
|---|---|---|
| App | **React Native + Expo (SDK 58)** con TypeScript y Expo Router | Una sola base de código para iOS y Android. Expo SDK 58 salió el 15/10/2026 con React Native 0.88 [E1]. |
| Compilación | **EAS Build** (en la nube) | El equipo de desarrollo usa Windows. EAS compila la app de iOS en macOS en la nube, maneja los certificados y publica con `eas submit`. |
| Actualizaciones | EAS Update | Corrige textos y fallos de JavaScript sin una nueva revisión de tienda (no aplica a cambios nativos). |
| Video | `react-native-webview` con el Player SDK de Vimeo (sección 2.3) | Usa las mismas reglas y el mismo `io-player.js` del sitio. |
| Avisos | `expo-notifications` + Expo Push Service | Un solo servicio para APNs (Apple) y FCM (Google). |
| Almacenamiento seguro | `expo-secure-store` (Keychain en iOS, Keystore en Android) | Guarda ahí el token de renovación. El token de acceso vive solo en memoria. |
| Datos | TanStack Query sobre `fetch` | Caché, reintentos y actualización al volver a la app. |
| Compras (versión 2) | `expo-iap` o `react-native-iap` (requiere una *development build*) | StoreKit 2 y Play Billing en una sola interfaz. RevenueCat es la alternativa administrada, pero agrega otro proveedor y otra comisión. |
| Servidor | El módulo PHP actual (`/io/`), sin frameworks ni Composer | Lo que hoy existe, con endpoints nuevos (sección 3). |

### 2.2 Autenticación para la app

Hoy la web identifica al alumno con la sesión PHP del sitio (`config.php` → `cliente_actual`). Además, `api/reproduccion.php` exige que la petición venga del mismo origen (`mismo_origen()`) y la tienda usa CSRF. Una app nativa no manda cookies del sitio ni la cabecera `Origin`, así que hoy **todas sus peticiones serían rechazadas** con el código `origen`. La propuesta es esta:

1. **Login** (`POST /io/api/app.php accion=login`). La app manda el correo, la contraseña y los datos del dispositivo. El servidor verifica la contraseña con una función nueva en `config.php`, `cliente_login($correo, $clave)`, que se escribe igual que `cliente_actual`: consulta la tabla de clientes del sitio **sin modificarla** y devuelve `['id','email','nombre']` o `null`. Los intentos se frenan por IP y por correo con el mismo enfoque de `io_login_intentos`.
2. **Tokens opacos**, sin JWT, para no depender de librerías:
   - *Token de acceso*: 32 bytes aleatorios, vigencia de **15 minutos**, se manda en `Authorization: Bearer …`.
   - *Token de renovación*: vigencia de **60 días**, se **rota en cada uso**. Si alguien reutiliza un token viejo, el servidor revoca toda esa familia de tokens y la app vuelve a pedir la contraseña.
   - En la base de datos solo se guarda el **hash SHA-256** de cada token, nunca el token. Cada sesión de la app se puede revocar desde la app («cerrar sesión en todos los dispositivos») y desde el backoffice, con motivo y registro en `io_bitacora`.
3. **Sin CSRF en la app.** El CSRF protege contra peticiones que el navegador manda solo con cookies. Un token *bearer* no viaja solo, así que el CSRF no hace falta. La web sigue igual, con cookie, `mismo_origen()` y CSRF.
4. Cuando la app vuelve al primer plano, renueva el token de acceso. Si el servidor responde 401 con el código `token_vencido`, la app renueva y reintenta una sola vez.

### 2.3 Reproductor de Vimeo en el teléfono

| Opción | A favor | En contra |
|---|---|---|
| **A. WebView con una página del sitio que carga el Player SDK de Vimeo e `io-player.js` (recomendada)** | Reutiliza toda la lógica del reproductor web: latidos, corrección de adelanto, capas y marca de agua. La página vive en `inteligenciaobjetiva.com`, así que **la restricción de dominio de Vimeo sigue funcionando**. Un cambio en las reglas se publica una sola vez. | Hay que ajustar en iOS la reproducción en línea, la pantalla completa y el inicio con un toque. La WebView no reproduce con la app en segundo plano. |
| B. WebView con HTML local dentro de la app | No depende de que cargue una página del sitio. | La restricción por dominio de Vimeo depende del *referer*, que hay que simular. La lógica del reproductor queda duplicada en dos lugares. |
| C. Reproductor nativo (`expo-video`) con archivos de video de Vimeo | La interfaz es nativa y tiene audio en segundo plano. | Requiere un plan superior de Vimeo y entregar al teléfono la dirección directa del archivo de video, lo que debilita las mitigaciones actuales. Además, habría que reescribir las reglas. **Se descarta.** |

**Cómo funciona la opción A:**

```
App (React Native)                       WebView: /io/public/app-reproductor.html
-------------------                      ----------------------------------------
Toca "Reproducir" ─────────────────────▶ carga Player SDK + io-player.js
                                          io-player.js necesita llamar al API
     ◀──── postMessage {accion, datos} ──┘ (iniciar, latido, finalizado, cerrar)
POST /io/api/reproduccion.php
  Authorization: Bearer <token>
     └──── respuesta JSON ──────────────▶ io-player.js aplica la regla
                                          (corregir_a, fin, sesión cerrada…)
```

- `io-player.js` recibe una opción nueva, `transporte`: una función que hace la petición. En la web sigue usando `fetch` con la cookie. En la app, la petición pasa por `postMessage` a React Native, que la manda con el token. Así **el token nunca entra a la página web**.
- La página `app-reproductor.html` es estática y no tiene datos sensibles: el ID de Vimeo llega en la respuesta de `iniciar`, igual que hoy.
- Si la app pasa a segundo plano, se pausa el video y se manda `cerrar`. Si el sistema detiene la app antes, los latidos dejan de llegar y el servidor aplica las reglas que ya tiene: 60 s sin latido, periodo de gracia y reanudación.
- Los audiolibros en la versión 1 suenan solo con la app abierta (es una decisión pendiente; ver la sección 7).

### 2.4 Reglas de reproducción

La app **no decide nada**: el servidor sigue siendo la única fuente de verdad (`IOAccesos::iniciar`, `latido`, `finalizado` y `cerrar`). La app solo:
- manda latidos cada `latido_seg` (15 s, según el servidor) con la posición y si el video se está reproduciendo;
- obedece `corregir_a`, `fin` y los errores `sesion_cerrada` y `pausa_larga`, y le dice al alumno qué pasó;
- muestra antes de empezar la advertencia de la visualización única: cuántas reanudaciones le quedan y que no se puede adelantar.

### 2.5 Avisos (Expo Push)

- Cuando el alumno da permiso, la app obtiene su `ExpoPushToken` y lo registra en el servidor (`accion=dispositivo`). Al cerrar sesión, el token se borra.
- Un script nuevo, `bin/avisos-vencimiento.php`, corre cada 10 minutos con cron. Busca los accesos que vencen en las próximas 24 h o 2 h, y los que aún no empiezan pero ya se acerca su fecha límite para iniciar. Manda los avisos por el API de Expo Push y registra cada envío en `io_avisos`, para no repetir el mismo aviso. Si Expo responde `DeviceNotRegistered`, el dispositivo se desactiva.
- Como el cálculo se hace en el servidor, si el equipo extiende un acceso desde el backoffice, el siguiente aviso ya usa la fecha nueva.
- Si la tienda o el alumno bloquean las notificaciones, la cuenta regresiva de la biblioteca sigue funcionando.

### 2.6 Almacenamiento seguro y privacidad

- El token de renovación se guarda en `expo-secure-store`. El token de acceso solo vive en memoria. La contraseña nunca se guarda.
- La caché de la biblioteca guarda solo los títulos y los estados, y se borra al cerrar sesión.
- La app no usa SDK de publicidad ni de rastreo. Eso simplifica las etiquetas de privacidad de las tiendas (sección 5.4).

---

## 3. Cambios necesarios en el servidor

Todo se agrega **sobre** el módulo actual. Se respetan las convenciones del proyecto: las tablas nuevas llevan el prefijo `io_`, no se modifica ninguna tabla del sitio, el SQL es compatible con MySQL 5.7 y 8.0 (sin `VALUES()` en `ON DUPLICATE KEY UPDATE` y sin `;` dentro de comentarios), y toda acción que cambie un acceso pide motivo y se registra en la bitácora.

### 3.1 Versión 1

| # | Archivo | Cambio |
|---|---|---|
| S1 | `sql/008_io_app.sql` (nuevo) | Tablas nuevas: `io_app_tokens` (cliente_id, tipo `acceso`/`renovacion`, hash, familia, dispositivo, expira, revocado, creado, ultimo_uso), `io_dispositivos` (cliente_id, expo_token único, plataforma, activo, ultimo_uso), `io_avisos` (acceso_id, tipo `24h`/`2h`/`inicio`, enviado, con llave única por acceso y tipo) e `io_solicitudes_borrado` (cliente_id, solicitada, atendida, notas). |
| S2 | `lib/App.php` (nuevo, clase `IOApp`) | `login()`, `renovar()`, `salir()`, `salirDeTodo()` y `clienteBearer(): ?array`, que lee `Authorization`, busca el hash y valida que el token no haya vencido ni esté revocado. También `registrarDispositivo()` y `solicitarBorrado()`. |
| S3 | `api/app.php` (nuevo) | `POST login`, `renovar`, `salir`, `salir_todo`, `dispositivo` (registrar o quitar), `borrar_cuenta`. `GET perfil` y `vencimientos&horas=72`, que devuelve los accesos con `vence` o `limite_inicio` dentro de ese plazo para el banner «Por vencer». Todas las respuestas son JSON y siguen el formato `{ok, codigo, mensaje}` de `reproduccion.php`. |
| S4 | `api/reproduccion.php` | Determina al cliente así: `IOApp::clienteBearer()` si la petición trae `Authorization: Bearer`, y si no, `mismo_origen()` + `IO::clienteActual()` como hoy. Con token *bearer* no se exige el mismo origen. La acción `biblioteca` agrega `ahora` (la hora del servidor, para la cuenta regresiva), `vence` y `limite_inicio` en ISO 8601 con zona, `miniatura` e `inicio`. A `iniciar` se le manda `dispositivo = "App iOS 18 · iPhone 15"` para que las alertas de doble dispositivo se puedan leer. |
| S5 | `lib/IO.php` | Ninguna regla cambia. Solo se agrega un método `IO::usarCliente(array $c)` para que la API no tenga que pasar al cliente por muchos lugares, y una consulta `IOAccesos::proximosAVencer(int $clienteId, int $horas)` que reutiliza `refrescar()`. |
| S6 | `public/io-player.js` | Una opción nueva, `transporte(datos) → Promise`; por defecto es el `fetch` actual, así que la web no cambia. También se ajusta el `sendBeacon` de cierre para que use el mismo transporte. |
| S7 | `public/app-reproductor.html` (nuevo) | Página mínima que carga el Player SDK de Vimeo e `io-player.js` y conecta `transporte` con `window.ReactNativeWebView.postMessage`. |
| S8 | `bin/avisos-vencimiento.php` (nuevo) + cron | Ver la sección 2.5. El token de acceso de Expo se lee de `config.php` → `app.expo_token`, y se agrega una clave de ejemplo en `config.example.php`. |
| S9 | `config.example.php` | Agrega la función `cliente_login`, la `url_recuperar_clave` del sitio y la sección `app` (`expo_token`, vigencias de los tokens y límites de intentos). `config.php` no se versiona. |
| S10 | Backoffice | En la ficha del alumno se muestran sus sesiones de la app y sus dispositivos, con un botón para cerrar sesiones que pide motivo y lo registra en la bitácora. También se muestra la lista de solicitudes de borrado. |
| S11 | Página web de borrado de cuenta | Google exige una página pública para pedir el borrado de la cuenta sin entrar a la app (sección 5.5). Puede vivir en `/io/public/borrar-cuenta.php`, con inicio de sesión y confirmación. |

### 3.2 Versión 2 (compras en la app)

| # | Archivo | Cambio |
|---|---|---|
| T1 | `sql/009_io_app_compras.sql` (nuevo) | `io_app_productos` (producto_id, sku_apple, sku_google, tipo `consumible`/`no_consumible`, activo) e `io_app_compras` (plataforma, transaccion_id **único**, transaccion_original, producto_id, cliente_id, pedido_id, estado, entorno `sandbox`/`produccion`, datos JSON, creado). |
| T2 | `lib/CompraApp.php` (nuevo, clase `IOCompraApp`) | **Apple**: verifica la transacción firmada (JWS) de StoreKit 2 con la cadena de certificados de Apple y la confirma con el App Store Server API. **Google**: consulta `purchases.products.get` del Google Play Developer API con una cuenta de servicio, revisa `purchaseState` y luego hace *acknowledge* (Google reembolsa solo las compras que no se reconocen). Las llamadas pasan por un transporte que se puede reemplazar en las pruebas, como ya hace `IOStripe::usarTransporteParaPruebas`. |
| T3 | `lib/Tienda.php` | Método nuevo `IOTienda::crearPedidoExterno($cliente, $productoId, $pasarela, $ref, $montoCentavos)`, que crea el pedido con `pasarela = 'apple'` o `'google'` sin pasar por el carrito, el cupón ni Stripe. Después llama a **`confirmarPago()`**, que no cambia y no duplica accesos, para crear el acceso. Los pedidos de las tiendas quedan fuera del módulo de facturación. |
| T4 | `api/app.php` | Agrega `GET catalogo` y `producto&slug=…` (reutilizan `IOTienda::catalogo()` y `producto()`, pero sin el precio web) y `POST compra_validar` (plataforma, recibo o token, sku), que responde con el `acceso_id`. |
| T5 | `api/apple-notificaciones.php` (nuevo) | Recibe las App Store Server Notifications v2. Con `REFUND` o `REVOKE`, revoca el acceso con un motivo de sistema y lo registra en la bitácora. |
| T6 | `api/google-rtdn.php` (nuevo) | Recibe las Real-time Developer Notifications por Pub/Sub *push*. Las compras anuladas (*voided purchases*) revocan el acceso igual que en T5. También entrega las compras hechas fuera de la app, como las de las promociones de Google Play, que el servidor debe detectar y reconocer [G7]. |
| T7 | `api/tienda.php` | Ningún cambio en la tienda web. Solo se separa la lógica de `estado` para poder reutilizarla en la app. |
| T8 | Backoffice | Una pantalla para relacionar cada producto con su SKU de Apple y de Google, y en *Pedidos*, un filtro por pasarela (Apple o Google). |

**Tipo de producto en las tiendas** (decisión pendiente; ver la sección 7): un acceso de 48 h o de visualización única encaja como **consumible**, porque cada compra es un pase. Un acceso permanente (documentales, introspecciones, audiolibros) encaja como **no consumible** y se puede restaurar.

---

## 4. Pantallas y flujos

| Pantalla | Contenido |
|---|---|
| Arranque | Logo iO y la firma. Si hay un token de renovación guardado, la app renueva la sesión y entra directo a la biblioteca. |
| Iniciar sesión | Correo, contraseña, «¿Olvidaste tu contraseña?» (abre la página de recuperación del sitio) y los mensajes de error en español. |
| Mi biblioteca | Banner «Por vencer» con los vencimientos de las próximas 72 h y tarjetas con miniatura, título, tipo, estado, progreso y cuenta regresiva. Filtros: *Todos, Activos, Por empezar, Vistos y vencidos*. Si está vacía, muestra un texto neutro. |
| Detalle del contenido | Regla de acceso explicada, fecha y hora de vencimiento, reanudaciones restantes y el botón **Reproducir**. Si es de visualización única, muestra antes una confirmación con las condiciones. |
| Reproductor | Pantalla completa horizontal, marca de agua, avisos del servidor («Este contenido se abrió en otro dispositivo», «La pausa superó el tiempo permitido») y salida a la biblioteca. |
| Ajustes | Modo claro, oscuro o del sistema, avisos encendidos o apagados, versión de la app, aviso de privacidad, términos y soporte. |
| Cuenta | Nombre y correo, cerrar sesión, cerrar sesión en todos los dispositivos y **Eliminar mi cuenta** (con confirmación, cuánto tarda y qué datos se conservan por ley). |
| *Versión 2:* Catálogo | Categorías (conferencias, cursos, documentales, introspecciones, audiolibros), búsqueda y tarjetas con el precio de la tienda. |
| *Versión 2:* Ficha | Imagen, descripción, tipo de acceso explicado y el botón **Comprar**, que abre la hoja de pago de Apple o Google. |
| *Versión 2:* Compra en curso o confirmada | «Validando tu compra…» y después «Listo, ya está en tu biblioteca», con un botón para ver el contenido. «Restaurar compras» vive en *Ajustes*. |

```
[Arranque] ──¿hay sesión?──no──▶ [Iniciar sesión] ──ok──┐
     │ sí                                                 ▼
     └──────────────────────────────────────────▶ [Mi biblioteca] ◀── aviso push (abre el detalle)
                                                     │        │
                                         toca tarjeta│        │ pestaña
                                                     ▼        ▼
                                               [Detalle]   [Ajustes] ─▶ [Cuenta] ─▶ Eliminar cuenta
                                                     │
                                    ¿visualización única? ─sí─▶ confirmar condiciones
                                                     ▼
                                              [Reproductor] ── latidos cada 15 s ──▶ API
                                                     │ fin / vencido / otra sesión
                                                     ▼
                                               [Mi biblioteca] (estado actualizado)

Versión 2:  [Catálogo] ─▶ [Ficha] ─▶ hoja de pago Apple/Google ─▶ validación en servidor ─▶ [Mi biblioteca]
```

---

## 5. Reglas de las tiendas

Fuentes consultadas el **18 de noviembre de 2026**; las referencias están al final. Estas reglas cambian seguido, así que conviene revisarlas otra vez antes de cada envío a revisión.

### 5.1 Compras de contenido digital y comisiones

- **Apple.** Para desbloquear contenido digital dentro de la app hay que usar la compra integrada de Apple: la guía 3.1.1 lo dice expresamente, incluido el acceso a contenido premium [A1]. Con el **App Store Small Business Program**, la comisión es de **15 %** para quien ganó menos de 1 millón de dólares en el año calendario anterior y en el actual, y de 10 % para las suscripciones después de su primer año. Si se rebasa el millón, aplica la tasa estándar [A2]. Para entrar al programa hay que solicitarlo.
- **Google Play.** Para vender contenido digital hay que usar Google Play Billing (política de pagos, sección 2) [G1]. En los mercados que todavía no tienen las tarifas nuevas, como **México**, la comisión es de **15 %** sobre el primer millón de dólares al año y de 30 % después, y de 15 % para las suscripciones. En Estados Unidos, el Espacio Económico Europeo y el Reino Unido (desde el 30/06/2026), y en Australia y Japón (desde el 30/09/2026), hay otro esquema: hasta 20 % más 5 % de cuota de cobro en las transacciones de instalaciones nuevas, y 10 % más 5 % en las suscripciones. Google dice que las tarifas nuevas se van a extender al resto del mundo, sin dar fecha [G2]. **Hay que revisar si México ya cambió antes de fijar precios.**

### 5.2 Qué se puede y qué no en la versión 1 (solo ver)

- **Apple, guía 3.1.3(a): apps «lectoras».** Una app puede dar acceso a contenido **comprado antes** en video y audio sin ofrecer compras dentro de la app [A1]. La versión 1 entra en este caso.
- **Fuera de la tienda de Estados Unidos no se permiten** botones, enlaces externos ni llamados a comprar por otro medio que no sea la compra de Apple. La excepción vale solo para la tienda de EE. UU. [A1]. Para la tienda de México, eso significa **nada de «Compra en la web», precios, enlaces a la tienda o textos que inviten a comprar fuera**, ni siquiera en la biblioteca vacía.
- Las apps lectoras pueden pedir el *External Link Account Entitlement*, un permiso para poner **un** enlace informativo al sitio para crear o administrar la cuenta [A1]. No es necesario para la versión 1, pero queda como opción.
- Apple sí permite **comunicarse fuera de la app**, por correo o redes, sobre otros medios de compra [A1]. El correo de bienvenida de la web puede decir «compra en el sitio y mira en la app».
- **Google.** La política de pagos prohíbe llevar a los usuarios a pagos fuera de Google Play, ya sea con botones, promociones o WebView, salvo en los programas de enlaces externos de ciertos países [G1]. La página **no habla de forma expresa** de las apps que solo reproducen contenido comprado en otro lado. Es una práctica común, pero es un riesgo: la versión 1 debe salir sin ningún precio ni enlace de compra, igual que en iOS.
- **Funcionalidad mínima.** Apple rechaza las apps que solo envuelven un sitio web (guía 4.2). Por eso la biblioteca, el detalle, los ajustes y los avisos se hacen con pantallas nativas, y la WebView se usa solo para el video.
- **Cuenta de prueba para los revisores.** Las dos tiendas la piden. Hay que preparar una cuenta con contenido de las tres reglas y reiniciar su visualización única desde el backoffice antes de cada envío.

### 5.3 Cuentas de desarrollador

| Requisito | Apple Developer Program | Google Play Console |
|---|---|---|
| Costo | **99 USD al año** (se muestra en moneda local) [A3] | **25 USD, un solo pago** [G3] |
| Empresa | Debe ser una entidad legal; no se aceptan nombres comerciales. Se necesita el **número D-U-N-S**, un sitio web público del dominio, un correo del dominio y la autoridad legal para firmar [A3] | Para una cuenta de organización, **el D-U-N-S es obligatorio**, junto con el sitio web, el teléfono y los datos de contacto [G4] |
| D-U-N-S | Es gratis. Dun & Bradstreet tarda **hasta 5 días hábiles** en emitirlo y Apple tarda **hasta 2 días hábiles** más en recibirlo [A4] | Google dice que puede tardar **hasta 30 días** [G4] |
| Persona física | Es posible, pero la tienda muestra el nombre de la persona como vendedor | Las cuentas personales nuevas deben hacer una prueba cerrada con **al menos 12 probadores durante 14 días seguidos** antes de publicar [G5] |
| Identidad | Apple Account con verificación en dos pasos [A3] | Identificación oficial y tarjeta a nombre del titular [G3] |

**Recomendación:** abrir las dos cuentas **a nombre de la empresa** y con un correo del dominio (por ejemplo, `apps@inteligenciaobjetiva.com`), y dar acceso al desarrollador como miembro del equipo. Así las cuentas son del negocio y se evita la prueba de 14 días de Google.

### 5.4 Alta de productos, precios y privacidad

- **Precios en Apple.** Hay hasta 800 niveles de precio, más 100 niveles altos bajo solicitud. Se elige un país base y Apple ajusta los precios de las otras 174 tiendas, salvo que se fijen a mano [A5]. Hay que confirmar en App Store Connect que México se puede usar como país base.
- **Precios en Google.** Se da de alta un producto integrado por cada SKU, con su precio en MXN y conversión automática para los otros países.
- Los productos de la versión 2 se dan de alta **antes** de enviar la app a revisión, y el primer producto de Apple se revisa junto con la versión.
- **Privacidad.** Ambas tiendas piden la URL del aviso de privacidad. Apple pide además las «etiquetas de privacidad» de App Store Connect, y Google, el formulario de **Seguridad de los datos**, que incluye las preguntas sobre borrado [G6]. El aviso de privacidad del sitio debe mencionar la app, los tokens de los dispositivos y los avisos push. Conviene que lo revise un abogado a la luz de la ley mexicana de protección de datos personales.

### 5.5 Borrado de cuenta (obligatorio)

- **Apple (5.1.1(v)).** Si la app permite crear cuentas, debe permitir **iniciar el borrado dentro de la app**. Desactivar la cuenta no basta. Se puede terminar el trámite en el sitio con un enlace directo, pero no se puede pedir que el alumno llame, mande un correo o use un chat. Hay que decirle cuánto tarda y confirmarle cuando se termine, y explicar qué datos se conservan por ley [A1][A6]. La versión 1 no crea cuentas, pero **igual conviene incluir el borrado**, porque el criterio de los revisores puede ser estricto.
- **Google.** La regla aplica también cuando la cuenta se crea fuera de la app. Pide **dos caminos**: uno dentro de la app y una **página web** donde se pueda pedir el borrado sin instalarla. Esa página debe mencionar el nombre de la app o del desarrollador tal como aparece en la tienda [G6].
- **Qué se propone:** el alumno pide el borrado en la app o en `/io/public/borrar-cuenta.php`. Se registra en `io_solicitudes_borrado` y el equipo lo atiende desde el backoffice. Si la cuenta del sitio no se puede borrar automáticamente sin tocar las tablas del sitio, el trámite es manual y se informa el plazo, como Apple permite [A6]. Los pedidos y las facturas se conservan por obligación fiscal, y eso se le avisa al alumno.

---

## 6. Calendario

### 6.1 Desarrollo del agente

| Bloque | Días | Notas |
|---|---|---|
| Servidor de la versión 1 (S1–S11) | **0.5 a 1** | Se ajustó contra el 0.5 estimado al principio: hay que sumar la página del reproductor para la app, el transporte en `io-player.js`, el cron de avisos y la página de borrado. Depende de conocer cómo guarda las contraseñas el sitio para escribir `cliente_login`. |
| App de la versión 1 | **1 a 2** | Login, biblioteca, detalle, reproductor con WebView, avisos, tema claro y oscuro, cuenta y borrado. |
| Versión 2 (servidor T1–T8 + app) | **1 a 2** | Validar los recibos de Apple y Google es lo más delicado. |
| Pruebas y documentación | **0.5** | Pruebas PHP, pruebas de la app, guía de publicación y ficha de las tiendas. |
| **Total** | **3 a 5.5** | |

### 6.2 Línea de tiempo con las esperas externas

| Semana | Dueño y trámites externos | Agente | Resultado |
|---|---|---|---|
| **0 (hoy)** | Decide los puntos de la sección 7. Solicita el D-U-N-S, de hasta 5 + 2 días hábiles para Apple [A4] y hasta 30 días según Google [G4]. Abre la cuenta de Google Play (25 USD). Cambia el texto «Nuestra app ya está disponible» del sitio. | — | Trámites en curso |
| **1** | Inscribe la empresa en Apple Developer cuando llegue el D-U-N-S (99 USD). Prueba en su teléfono. | Servidor de la versión 1 + app de la versión 1 (de 1.5 a 3 días). Pruebas con Expo Go y con una *development build*. | Versión 1 probada en teléfonos reales |
| **2** | Revisa las capturas y la ficha de las tiendas. Aprueba la cuenta de prueba para los revisores. | Compilaciones de producción con EAS, TestFlight y prueba interna de Google, envío a revisión. Apple revisa al menos 50 % de los envíos en menos de 24 h y 90 % en menos de 48 h [A7]. Para Google, se reservan algunos días. | **Versión 1 publicada** (si las cuentas ya están verificadas) |
| **2 a 3** | Decide los precios en la app y los productos que se venden ahí. Da de alta los productos en las dos tiendas y se inscribe en el Small Business Program de Apple. | Versión 2 (de 1 a 2 días). Pruebas en los entornos de prueba (*sandbox*) de Apple y Google. | Versión 2 en revisión |
| **3** | — | Ajustes según las observaciones de los revisores. | **Versión 2 publicada** |

**Lo que puede alargar el calendario:** un D-U-N-S lento, sobre todo en Google (hasta 30 días). Si se elige una cuenta personal en Google Play, se suman al menos 14 días de prueba cerrada con 12 probadores [G5]. También alarga el calendario un rechazo en la revisión, normalmente por un enlace de compra o por la funcionalidad mínima.

---

## 7. Riesgos y decisiones pendientes del dueño

### Decisiones

- [ ] **Titular de las cuentas:** la empresa (recomendado; requiere el D-U-N-S) o una persona física. ¿Ya existe un D-U-N-S? Se puede buscar gratis [A4].
- [ ] **Nombre de la app en las tiendas**, el ícono y el identificador del paquete (por ejemplo, `com.inteligenciaobjetiva.app`). ¿«Inteligencia Objetiva» o «iO · Inteligencia Objetiva»?
- [ ] **Correo y responsables** de las cuentas: quién paga, quién tiene acceso de administrador y quién recibe los avisos de Apple y Google.
- [ ] **¿Conviene hacer la versión 2?** Con 15 % de comisión en cada tienda, ¿se suben los precios en la app, se dejan iguales que en la web y se absorbe la comisión, o la app queda solo para ver y se vende en la web?
- [ ] **Qué productos se venden en la app** (todos o una selección) y si en el futuro habrá **membresías** como suscripción dentro de la app.
- [ ] **Consumible o no consumible** según el tipo de acceso (sección 3.2).
- [ ] **Avisos:** ¿24 h y 2 h antes están bien? Hay que aprobar el texto de los avisos.
- [ ] **Audiolibros:** en la versión 1 suenan solo con la app abierta. ¿Es aceptable o se prioriza el audio en segundo plano para después?
- [ ] **Borrado de cuenta:** qué se borra, qué se conserva por ley (pedidos y facturas) y en cuántos días se atiende.
- [ ] **Aviso de privacidad y términos:** actualizarlos para mencionar la app.
- [ ] **Texto del sitio:** cambiar «Nuestra app ya está disponible» y quitar los botones de las tiendas hasta que la app se publique.
- [ ] **Teléfonos de prueba:** ¿quién tiene un iPhone y un Android para las pruebas reales? Además, hay que fijar las versiones mínimas de iOS y Android.
- [ ] **Datos de la ficha:** descripción, capturas, correo y URL de soporte, y categoría (Educación).

### Riesgos

| Riesgo | Mitigación |
|---|---|
| Un rechazo por mencionar compras en la versión 1 (Apple 3.1.1/3.1.3, Google, sección 4 de pagos) | No mostrar precios, enlaces ni textos de compra. Revisarlo con una lista antes de cada envío. |
| Google no aclara la regla de las apps que solo reproducen contenido comprado fuera | Hacer la versión 1 sin rastro de compras y estar listos para explicarle el caso al revisor. Si hiciera falta, adelantar la versión 2. |
| Un rechazo por «funcionalidad mínima» (4.2) | Pantallas nativas, avisos y modo oscuro. La WebView se usa solo para el video. |
| No se sabe cómo guarda el sitio las contraseñas de sus clientes | Revisar la tabla y el código de login del sitio antes de escribir `cliente_login`. **No se modifican** las tablas del sitio. |
| Pantalla completa, inicio con un toque o rotación de Vimeo dentro de la WebView en iOS | Probar pronto en un iPhone real con `allowsInlineMediaPlayback` y pantalla completa nativa. |
| La sesión única cierra la reproducción web cuando el alumno abre el mismo contenido en la app | Es el comportamiento que se busca. Se le avisa al alumno con un mensaje claro. |
| El Expo Go para SDK 58 llega a las tiendas «en unas semanas» y desde SDK 53 no recibe push en Android [E1][E2] | Usar una *development build* con EAS para los avisos y para las compras. |
| Cambian las comisiones o las reglas de enlaces (Google ya está cambiando sus tarifas por región [G2]) | Revisar las fuentes antes de fijar precios y antes de cada envío. |
| Facturación: en las compras dentro de la app, Apple y Google cobran | El contador confirma cómo se registran esos ingresos y que no lleven CFDI propio. |
| Reembolsos de las tiendas sin revocar el acceso | Notificaciones del servidor (T5 y T6) y revisión mensual en *Pedidos*. |

---

## 8. Cómo probarla

### 8.1 En el teléfono del dueño

1. **Expo Go** (gratis, en App Store y Google Play): el desarrollador comparte un código QR y la app abre al momento en el teléfono, sin pasar por las tiendas. Sirve para revisar las pantallas, la biblioteca, el reproductor y el modo oscuro. **Límites:** no recibe avisos push en Android (desde SDK 53) [E2] y no permite compras dentro de la app. Además, el Expo Go para SDK 58 se está publicando por etapas [E1].
2. **Development build** (EAS, distribución interna): es una versión propia de la app que se instala con un enlace. Hace falta para los avisos push y las compras. En iPhone requiere la cuenta de Apple Developer y registrar el teléfono antes de compilar [E3].
3. **TestFlight** (iOS) y **prueba interna o cerrada** (Google Play): es la versión casi final, la misma que se manda a revisión.

### 8.2 Emuladores

- **Android:** el emulador de Android Studio funciona en Windows. Debe tener Google Play Services para probar los avisos push [E3].
- **iOS:** el simulador solo corre en macOS, así que desde Windows se prueba con un iPhone real, con una *development build* o con TestFlight.

### 8.3 Pruebas automáticas

- **Servidor (PHP):** se amplía `tests/prueba-flujo.php` para cubrir el login con frenos, la rotación y la reutilización del token de renovación (que debe revocar la familia), los latidos con token *bearer*, que una petición sin token ni origen sea rechazada, `vencimientos`, el cron de avisos sin duplicados y, en la versión 2, la validación de recibos con un transporte simulado, la idempotencia de `confirmarPago()` y la revocación por reembolso. Se corre en la base temporal del puerto 3307 y debe terminar en `0 fallidas`.
- **App:** Jest + React Native Testing Library para el cliente del API (renovación del token, reintento único), la cuenta regresiva con el desfase del reloj del servidor y el estado de cada tarjeta.
- **De punta a punta (opcional):** Maestro en el emulador de Android, con el flujo login → biblioteca → reproducir → latido → salir.

### 8.4 Cuentas y tarjetas de prueba

- **Alumno demo:** `php modulo-visualizacion/tests/sembrar-demo.php` en el servidor local o en uno de pruebas, con accesos de 48 h, permanentes y de visualización única. El mismo alumno sirve como cuenta para los revisores.
- **Apple:** «Sandbox Testers» en App Store Connect, más pruebas locales con un archivo StoreKit Configuration. Las compras con estas cuentas no cobran [A8].
- **Google:** «Probadores con licencia» en Play Console y una versión en la pista de prueba interna. Google ofrece tarjetas de prueba que siempre aprueban o siempre rechazan [G7].
- **Avisos push:** se prueban con la herramienta de Expo y con el cron en modo de prueba, que manda el aviso solo al dispositivo del desarrollador.

---

## Fuentes (consultadas el 18 de noviembre de 2026)

- [A1] Apple, *App Store Review Guidelines* (última actualización: 16/10/2026), guías 3.1.1, 3.1.1(a), 3.1.3, 3.1.3(a), 3.1.3(b), 4.2 y 5.1.1(v): <https://developer.apple.com/app-store/review/guidelines/>
- [A2] Apple, *App Store Small Business Program*: <https://developer.apple.com/app-store/small-business-program/>
- [A3] Apple, *Enrollment* (requisitos y costo): <https://developer.apple.com/programs/enroll/>
- [A4] Apple, *D-U-N-S Number*: <https://developer.apple.com/support/D-U-N-S/>
- [A5] Apple, *Set a price* (App Store Connect): <https://developer.apple.com/help/app-store-connect/manage-app-pricing/set-a-price/>
- [A6] Apple, *Offering account deletion in your app*: <https://developer.apple.com/support/offering-account-deletion-in-your-app/>
- [A7] Apple, *App Review*: <https://developer.apple.com/distribute/app-review/>
- [A8] Apple, *Testing in-app purchases with sandbox*: <https://developer.apple.com/documentation/storekit/testing-in-app-purchases-with-sandbox>
- [G1] Google Play, *Política de pagos*: <https://support.google.com/googleplay/android-developer/answer/9858738>
- [G2] Google Play, *Service fees*: <https://support.google.com/googleplay/android-developer/answer/112622>
- [G3] Google Play, *Registro en Play Console*: <https://support.google.com/googleplay/android-developer/answer/6112435>
- [G4] Google Play, *Información requerida para cuentas de organización (D-U-N-S)*: <https://support.google.com/googleplay/android-developer/answer/13628312>
- [G5] Google Play, *Requisitos de prueba para cuentas personales nuevas*: <https://support.google.com/googleplay/android-developer/answer/14151465>
- [G6] Google Play, *Requisitos de borrado de cuenta*: <https://support.google.com/googleplay/android-developer/answer/13327111>
- [G7] Android Developers, *Test Google Play Billing*: <https://developer.android.com/google/play/billing/test>
- [E1] Expo, *Expo SDK 58* (15/10/2026): <https://expo.dev/changelog/sdk-58>
- [E2] Expo, *Notifications* (Expo Go y avisos push): <https://docs.expo.dev/versions/latest/sdk/notifications/>
- [E3] Expo, *Push notifications setup*: <https://docs.expo.dev/push-notifications/push-notifications-setup/>
