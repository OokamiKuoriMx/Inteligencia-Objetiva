# Cupones y promociones 2×1

Aplicar `io/sql/005_io_descuentos.sql` después de las migraciones anteriores. Solo crea `io_cupones` e `io_descuentos_pedidos`; no publica ofertas ni modifica precios del catálogo automáticamente.

En **Backoffice → Cupones y promociones** (`/io/backoffice/descuentos.php`), un administrador o editor puede crear códigos con motivo obligatorio. Cada cambio se guarda en la misma transacción que su entrada de bitácora. Se revalida el rol contra la base de datos; una sesión desactivada o un rol falsificado no autoriza cambios.

La configuración admite porcentaje, importe fijo en MXN con IVA y 2×1; fechas en hora de Ciudad de México, compra mínima, cupo global, máximo por cliente y selección de productos por ID. El código acepta letras ASCII, números, guion y guion bajo, entre 3 y 40 caracteres; se normaliza a mayúsculas. Los productos ya rebajados se excluyen salvo que el responsable autorice expresamente combinarlos con el cupón. Solo puede aplicarse un cupón por pedido.

## Aplicación en el carrito

El comprador inicia sesión y aplica el código desde el carrito. El navegador envía únicamente el código: el servidor vuelve a leer las reglas, calcula el importe y valida el cupo al crear el pedido. No acepta importes ni porcentajes enviados por el navegador. Si cambió la disponibilidad del cupón, se informa el error antes de iniciar el cobro.

Los cálculos se realizan en centavos. El porcentaje se redondea hacia abajo por concepto; un descuento fijo reparte el importe entre los conceptos elegibles y asigna todos los centavos de manera determinista. En el 2×1 se ordenan los productos elegibles de mayor a menor precio y se regala el menor de cada pareja; un producto impar se cobra. El carrito usa un ID por producto: duplicar el mismo ID no produce un regalo. El total final debe ser de al menos $10 MXN.

Los importes finales se guardan en `io_pedido_items.precio_mxn` y en los totales del pedido. `io_descuentos_pedidos.detalle` conserva el precio original y el descuento de cada concepto. El cobro, el comprobante y la facturación deben usar esos importes finales; no aplicar de nuevo el descuento.

## Reservas y pagos pendientes

Al crear el pedido se bloquea la fila del cupón y se reserva su cupo en la misma transacción que las líneas y el total. La lectura de reservas también usa un bloqueo para observar los últimos cambios aunque MySQL utilice `REPEATABLE READ`. El cupo global y el límite por cliente incluyen tanto reservas como usos consumidos.

El cupón se consume solo al confirmar el pago y la operación es idempotente. El vencimiento de la campaña impide nuevas reservas; un pedido que ya reservó el cupón mantiene el precio que se ofreció al comprador.

Una reserva **no se libera por pasar el tiempo ni por una tarjeta rechazada**. Un PaymentIntent rechazado puede reintentarse y OXXO puede confirmarse después. Liberar ese cupo prematuramente permitiría aceptar más compras de las autorizadas. Para liberar un pedido abandonado, cancelar definitivamente su PaymentIntent desde la gestión de pedidos y confirmar el estado cancelado con la pasarela. La cancelación y la liberación se guardan juntas. Un pedido cuyo cupón ya se liberó no puede reutilizarlo mediante un pago tardío: se envía a conciliación en lugar de otorgar acceso silenciosamente.

Los reembolsos no restauran automáticamente el cupo de un cupón consumido. Si el negocio desea conceder un descuento sustituto debe crear uno nuevo con motivo auditado.

## Integración interna

`IODescuentos::calcular($items, $codigo, $clienteId)` calcula la vista previa. Devuelve `items` con `precio_original_mxn`, `importe_original_centavos`, `descuento_centavos` y `precio_mxn` final, además de `original_centavos`, `descuento_centavos`, `total_centavos`, `subtotal`, `iva`, `total` y `cupon`.

`reservar($pedidoId, $items, $codigo, $clienteId)` requiere la transacción de creación del pedido y guarda el descuento junto con sus totales. `consumir($pedidoId)` exige transacción y pedido pagado. `liberar($pedidoId)` exige transacción y pedido definitivamente cancelado. Estas funciones no se exponen directamente al navegador.

## Verificación

Desde el repositorio: `php modulo-visualizacion/tests/prueba-descuentos.php`. Usa una base aleatoria `io_prueba_descuentos_<id>` en MySQL local 3307 y la elimina en `finally`; no lee `config.php`, no toca producción y no crea cargos. Verifica cálculos, redondeos, restricciones, mínimo de compra, reservas, consumo idempotente, cancelación, pago tardío, permisos y reversión si falla la bitácora. Incluye dos procesos de checkout compitiendo por el último cupo.
