# Inteligencia Objetiva 2.0 · Entrega para configuración

Paquete de producción: `dist/io2-completo-configurable-20261118.zip`. Esta entrega contiene el código y las instrucciones de integración. No incluye credenciales, conexión al servidor del cliente ni una publicación en producción. Los pagos, membresías, cupones, facturas y reembolsos se verificaron localmente con proveedores simulados; el técnico del cliente debe aceptar después los recorridos con sus cuentas Stripe y Facturapi de prueba.

## Qué incluye

- Tema, portada, catálogo, fichas, carrito y confirmación de compra.
- Biblioteca del alumno, reproductor y reglas de acceso por compra, primera reproducción, ventana, acceso permanente o visualización única.
- Backoffice con identidad IO, editor modal de contenidos con vista previa, accesos y reglas, bitácora y reportes.
- Plantilla CSV compatible con Excel y carga masiva de nuevos contenidos: revisión de filas antes de confirmar, guardado atómico como borradores y protección contra IDs duplicados o existentes.
- Integración configurable de tarjeta y OXXO con Stripe: confirmación por webhook firmado, comprobación de monto y moneda, reintentos sin duplicar accesos y recuperación de la ficha OXXO real.
- Campañas con fechas absolutas y productos seleccionados; el banner se oculta si no hay campaña vigente o descuentos comprobables.
- Importadores, migraciones `io_*`, diagnóstico de instalación y guía de despliegue.
- Membresías recurrentes con Stripe Checkout/Billing, portal, renovación y cancelación al final del período pagado.
- Cupones de porcentaje, importe fijo y 2×1 administrables, con reservas de cupo y cálculos en centavos.
- CFDI 4.0 autoservicio para compras individuales nacionales, consulta, descarga PDF/XML y cancelación fiscal mediante Facturapi.
- Gestión de pedidos, devoluciones completas, conciliación y revocación de los accesos devueltos.

## Configuración a cargo del técnico del cliente

Instalar `modulo-visualizacion/` como `/io/`. Copiar `io/config.example.php` a `io/config.php` en el servidor y completar lo siguiente sin enviar secretos por correo ni incorporarlos al repositorio:

| Configuración | Información necesaria |
| --- | --- |
| `db` | DSN, usuario y contraseña de MySQL del sitio. Aplicar las migraciones 001–009 en orden. |
| `dominio` | URL HTTPS real donde se insertarán los videos. |
| `cliente_actual` | Adaptador a la sesión existente que devuelva ID, correo y nombre del cliente autenticado, o `null`. Debe probarse con una cuenta real. |
| `dev` | `false` fuera de desarrollo local. |
| `vimeo_token` | Opcional para consultar duración y miniatura. Configurar los videos de pago para permitir inserción solo en el dominio del sitio. |
| `tienda.stripe_publica`, `stripe_secreta`, `stripe_webhook` | Llaves pública y secreta del mismo modo, primero `pk_test_`/`sk_test_`, y secreto de firma del endpoint de ese mismo modo. |
| `importar` | Consultas adaptadas a las tablas reales de catálogo y compras, precios finales con IVA y reglas de acceso aprobadas. |
| `membresias` | Habilitar solo después de configurar Price IDs recurrentes, importes finales en centavos MXN, periodicidad y contenidos por plan. |
| `facturacion` | Cuenta Facturapi y CSD del cliente, modo prueba/produccion y su llave, códigos SAT por defecto o por producto aprobados por el contador. |
| `pedidos.reembolsos_habilitados` | Activar devoluciones completas desde backoffice después de aceptar las pruebas de Stripe. |

Configurar en Stripe el endpoint `https://DOMINIO/io/api/stripe-webhook.php` con versión **2024-06-20** y estos eventos:

- `payment_intent.succeeded`
- `payment_intent.payment_failed`
- `payment_intent.canceled`
- `payment_intent.requires_action`
- `checkout.session.completed`
- `invoice.paid`
- `invoice.payment_failed`
- `customer.subscription.updated`
- `customer.subscription.deleted`
- `charge.refunded`
- `refund.updated`
- `refund.failed`

Crear el primer administrador con `php io/bin/crear-admin.php correo@dominio.com "Nombre" admin`; la contraseña se pide en la terminal. Integrar el tema y las rutas indicadas en `GUIA-DESPLIEGUE.md`, conservar los modales `#login` y `#registro` del sitio e incluir el JS de tema actualizado. El video de presentación ya usa la fuente pública del sitio; confirmar su vigencia y completar imágenes, resúmenes y trailers con contenido aprobado por el cliente.

## Instalación y aceptación

1. Respaldar archivos y base de datos. Instalar primero en staging con HTTPS y llaves Stripe de prueba.
2. Ajustar el adaptador de sesión y las consultas de importación. Ejecutar los importadores con `--simular`, revisar el resultado y después importar. Crear el administrador y comprobar las restricciones de acceso a configuración, librerías y SQL.
3. Ejecutar desde la raíz del sitio:

   ```sh
   php io/bin/diagnostico.php
   php io/bin/diagnostico.php --stripe-prueba
   ```

   El diagnóstico no muestra credenciales ni confirma pagos. La opción Stripe solo consulta conectividad en modo de prueba, sin crear cargos. Los puntos `MANUAL` también deben revisarse; un diagnóstico automático correcto no sustituye la aceptación funcional.

4. Aceptar con una cuenta de cliente: añadir productos, iniciar sesión o registrarse desde el carrito y volver a la compra; probar tarjeta aprobada y rechazada; generar OXXO, recuperar su ficha y simular su pago con las herramientas de prueba de Stripe. Confirmar que no se otorga acceso antes del webhook y que reenviar el evento no duplica accesos.
5. Probar membresía mensual/anual, renovación, rechazo y cancelación con Stripe Test Clocks. Configurar el portal de Stripe para actualizar tarjeta y cancelar al final, sin cambios de plan ni prorrateos. Ver `MEMBRESIAS.md`.
6. Crear un cupón y un 2×1 desde el backoffice. Confirmar importes finales, cupos y cancelación definitiva de un pedido abandonado. Ver `DESCUENTOS.md`.
7. Configurar Facturapi de prueba y claves SAT aprobadas. Solicitar CFDI de una compra propia pagada, consultar, descargar PDF/XML, repetir tras un fallo simulado y probar cancelación fiscal. La factura se emite cuando el comprador proporciona sus datos y confirma la solicitud. Ver `FACTURACION.md`.
8. Probar devolución completa de un pedido, conciliación de devolución pendiente y revocación del acceso solo al confirmarse el importe devuelto. Si existe CFDI, confirmar antes su cancelación fiscal. Ver `PEDIDOS.md`.
9. Revisar biblioteca, reproducción, vigencias, móvil y escritorio. Después de aprobar staging, configurar las cuentas en vivo y sus webhooks equivalentes; publicar con el respaldo y el plan de reversión descritos en la guía.

## Campañas

`tienda.promocion` está desactivada por defecto. El cliente debe aprobar `inicio`, `fin` y la lista de IDs `productos`. Las fechas deben tener formato ISO 8601 con zona horaria: `AAAA-MM-DDTHH:MM:SS-06:00`. La lista se publica en `/io/tienda/ofertas.php` y solo admite productos activos cuyo `precio_anterior` sea mayor que `precio_mxn`, con precio de venta positivo. El porcentaje del banner se calcula desde esos precios. La configuración de campaña no modifica precios ni aplica cupones.

## Demo local para revisión

La demo se ejecuta desde el repositorio; sus scripts, usuarios y datos de ejemplo no se incluyen en el ZIP de producción. Requiere PHP con `pdo_mysql`, `curl` y `mbstring`, y MySQL local disponible en `127.0.0.1:3307`, usuario `root` sin contraseña, exclusivo para pruebas.

```powershell
./modulo-visualizacion/bin/iniciar-demo.ps1
```

Abrir `http://127.0.0.1:8090/` y usar **Entrar como alumna de demostración** para revisar carrito y biblioteca. Backoffice: `demo-admin@ejemplo.test`, contraseña `demo-local-12345`. Son credenciales públicas de demostración, nunca de producción. Los pagos locales son simulados y no realizan cargos. Detener con `Ctrl+C`.

El launcher conserva la base `io_demo`; solo reinicia sus datos cuando se solicita explícitamente:

```powershell
./modulo-visualizacion/bin/iniciar-demo.ps1 -ReiniciarDatos
```

## Condiciones de operación

Los módulos externos están desactivados hasta configurarlos; no incluyen precios, promociones ni certificados fiscales inventados. Las membresías usan precios recurrentes fijos MXN con IVA, sin cambios de plan ni prorrateos. La emisión CFDI integrada corresponde a pedidos individuales nacionales con IVA al 16%; las facturas de cobro de Stripe Billing no son CFDI y el contador debe gestionar su tratamiento fiscal. Las devoluciones parciales iniciadas desde Stripe se concilian, mientras que la interfaz solicita devoluciones completas de pedidos individuales.

El paquete contiene `VERIFICACION.txt` con los resultados locales y `MANIFIESTO-SHA256.txt` para comprobar sus archivos. La entrega no acredita aceptación con cuentas reales ni publicación en producción.
