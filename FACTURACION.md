# Configuración de CFDI 4.0

El módulo emite CFDI de ingreso de pedidos únicos pagados mediante Facturapi v2. La emisión ocurre cuando el titular del pedido captura y confirma sus datos fiscales. No se envían correos automáticamente. PDF y XML se descargan a través del servidor y exigen la sesión del dueño del pedido.

## Configuración del cliente

Aplicar la migración `io/sql/006_io_facturacion.sql` después de 001–005 y completar `config.php`:

```php
'facturacion' => [
    'habilitada' => false,
    'modo' => 'prueba', // 'produccion' solo después de aceptar la instalación
    'api_key' => '',   // llave secreta de la organización Facturapi del mismo ambiente
    'producto_sat' => '', // clasificación aprobada por el responsable fiscal, ocho dígitos
    'unidad_sat' => '',   // clave de unidad SAT aprobada por el responsable fiscal
    'productos' => [
        // ID => ['producto_sat' => '...', 'unidad_sat' => '...'],
    ],
],
```

Las claves no se rellenan con una clasificación supuesta. Si los productos necesitan categorías SAT diferentes, deben configurarse por ID. Los importes del catálogo y pedido ya incluyen IVA del 16%, como el resto de la tienda. Esta integración usa esas mismas condiciones y no sustituye una revisión de la clasificación y tratamiento fiscal de los productos por el responsable del cliente.

El cliente necesita una organización Facturapi, sus datos fiscales y un CSD válido para emitir en producción. Debe cargar esos datos en el proveedor. La llave de prueba empieza con `sk_test_`, la de producción con `sk_live_`. Mientras no haya configuración válida, la interfaz explica que la facturación aún no está habilitada y no intenta timbrar.

## Uso y controles

La página `/io/tienda/facturacion.php?pedido=ID` permite solicitar, consultar y descargar el comprobante de una compra propia. Pide RFC nacional, razón social, régimen fiscal, código postal y uso del CFDI. Los RFC genéricos no se aceptan en este flujo porque requieren un tratamiento distinto de factura global o cliente extranjero. Facturapi realiza las validaciones fiscales definitivas ante el proveedor/SAT.

La forma fiscal se verifica desde el pago: OXXO se representa como efectivo `01`. Para tarjetas, el servidor consulta el `funding` del cargo real de Stripe: crédito `04` o débito `28`. No asume que todas las tarjetas sean de crédito. Tarjetas de clasificación desconocida requieren revisión antes de emitir. El método es PUE porque el comprobante solo se solicita una vez confirmado el pago íntegro. Los productos gratuitos de un 2×1 se excluyen de los conceptos; los importes finales de los conceptos cobrados deben sumar exactamente el total pagado.

Un pedido con reembolso solicitado, pendiente, parcial o completo no puede abrir una nueva solicitud de factura. La solicitud guarda el receptor y el payload fiscal en `io_facturas`, con acceso limitado a su dueño y a los administradores autorizados. Los respaldos de esta tabla contienen datos personales y requieren los mismos controles de acceso y conservación que los datos de clientes del sitio.

## Reintentos sin duplicados

Cada pedido conserva una fila y una clave aleatoria única. Se envía como `idempotency_key` y `external_id` de Facturapi. Un reintento primero busca por `external_id`; si el proveedor ya creó el CFDI antes de un timeout, lo recupera sin crear otro. Una respuesta de conflicto o un resultado incierto conserva el estado pendiente y la misma clave para conciliar. Una solicitud en proceso no se presenta como factura emitida.

Si el proveedor devuelve un rechazo HTTP 400 de datos, la solicitud queda en `error`. El titular puede corregir el receptor; antes de hacerlo, el servidor comprueba que no exista un comprobante remoto. Conserva la misma clave de idempotencia. Una factura ya emitida no permite cambiar sus datos mediante este formulario.

## Cancelación fiscal y reembolsos

El servicio interno es:

```php
IOFacturacion::cancelar($pedidoId, $motivoSAT, $uuidSustitucion, $admin, $motivoHumano);
```

Exige un administrador activo y un motivo humano de hasta 120 caracteres, además del motivo SAT `01`, `02`, `03` o `04`. Los motivos `01` y `04` requieren UUID de sustitución. La cancelación se solicita explícitamente y se registra en la bitácora. Si el SAT requiere aceptación del receptor, el estado local es `cancelacion_pendiente`; no se da por cancelada ni se habilita un reembolso como si estuviera concluida. La consulta permite conciliar posteriormente la aceptación o el rechazo del SAT. No se cancela un CFDI automáticamente al pulsar un botón de devolución sin esta decisión fiscal explícita.

## Aceptación del instalador

1. Configurar organización y llave de prueba de Facturapi y claves SAT aprobadas. Probar los datos de receptor admitidos en el ambiente de prueba.
2. Completar una compra de prueba, solicitar CFDI y comprobar UUID, importe, IVA, forma de pago y receptor.
3. Descargar PDF y XML con la cuenta titular. Verificar que otra cuenta no los pueda consultar ni descargar.
4. Repetir la solicitud y actualizar el estado: debe existir un solo comprobante en Facturapi.
5. Revisar el flujo de corrección de receptor rechazado y cancelación fiscal con el responsable del cliente.
6. Solo después de aceptar esas pruebas, cargar CSD y datos de la organización para producción, cambiar al modo y llave correspondientes y habilitar el servicio.

Las pruebas automáticas `php modulo-visualizacion/tests/prueba-facturacion.php` usan una base aleatoria `io_prueba_cfdi_<id>` en MySQL local de pruebas y un transporte simulado. Cubren propiedad, datos fiscales, importes, formas de pago, emisión, timeout, conciliación, descargas, corrección y cancelación. No equivalen a un timbrado contra una cuenta real ni se ejecutan sobre una base de producción.

## Contratos oficiales consultados

- [Facturapi, crear factura, idempotencia, consulta por external_id, cancelación y descarga](https://docs.facturapi.io/api/)
- [SDK oficial: contratos de facturas](https://github.com/FacturAPI/facturapi-node/blob/main/src/resources/invoices.ts)
- [Configuración de organización y CSD](https://docs.facturapi.io/docs/getting-started/organization-onboarding)

La API documentada usa `https://www.facturapi.io/v2`. La aplicación fija ese origen, no sigue redirecciones y no acepta URLs de descarga proporcionadas por el navegador.
