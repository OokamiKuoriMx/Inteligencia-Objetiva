# Pedidos, devoluciones y conciliación

Aplicar todas las migraciones 001–007 y abrir `/io/backoffice/pedidos.php`. Administradores, finanzas y soporte pueden consultar pedidos; solo administradores y finanzas pueden solicitar devoluciones o cancelar pagos pendientes. Cada operación revalida el usuario activo y su rol. Las mutaciones usan POST, CSRF y motivo de hasta 120 caracteres. El historial de acceso y las operaciones quedan auditados.

La bandera `pedidos.reembolsos_habilitados` viene en `false`. El cliente debe habilitarla después de aceptar el flujo en su cuenta de Stripe de prueba. La interfaz solicita **devoluciones completas** por el importe final pagado, después de los descuentos. No devuelve importes enviados por el navegador. Las devoluciones parciales iniciadas directamente en Stripe se registran; no revocan por sí solas todo el acceso. Para completar una devolución parcial iniciada fuera del sitio, el equipo debe gestionar el saldo restante en Stripe.

La solicitud se guarda antes de contactar Stripe y conserva una clave idempotente. Un timeout no equivale a una devolución fallida: se mantiene pendiente para consultar o reintentar la misma solicitud sin duplicar dinero. Después de 23 horas no se vuelve a crear una solicitud desconocida; se consulta Stripe y se exige revisión si no se encuentra el resultado. Los reembolsos que Stripe confirme como fallidos permiten una solicitud nueva.

El acceso se revoca únicamente al confirmarse la devolución completa, y la revocación se guarda en la misma transacción que la bitácora y el estado local. Si falla la base, se debe conciliar la devolución ya realizada, sin crear otra. Los avisos atrasados no vuelven a conceder contenidos devueltos. Los cupones consumidos no se restituyen automáticamente.

Configurar en el webhook `charge.refunded`, `refund.updated` y `refund.failed`. El primero concilia también devoluciones iniciadas desde Stripe. Una devolución total cierra las reproducciones activas de los accesos de ese pedido, sin afectar otras compras o membresías del cliente. No se debe eliminar este endpoint ni sus datos mientras haya devoluciones pendientes.

Si el pedido tiene CFDI, el portal bloquea la devolución hasta que su cancelación fiscal esté confirmada. Un administrador puede solicitarla indicando el motivo SAT, un UUID de sustitución cuando corresponda y el motivo humano auditado. Una solicitud de cancelación pendiente ante el SAT no se trata como una factura cancelada. El contador debe decidir el tratamiento fiscal correcto; la aplicación no elige un motivo SAT por suposición. Las devoluciones que se ejecuten directamente en Stripe pueden requerir tratamiento fiscal posterior por el equipo.

La cancelación de un pedido pendiente primero confirma el estado cancelado del PaymentIntent en Stripe y después libera la reserva del cupón. Un pago rechazado o una ficha OXXO que todavía puede pagarse no liberan reservas. Si falta la referencia del pago, se exige conciliación antes de liberarla.

El alumno consulta solo sus pedidos en `/io/tienda/mis-pedidos.php`, con enlaces a confirmación y facturación. Las suscripciones se administran en la página de membresías y el portal de Stripe. El portal de pedidos corresponde a compras individuales; las facturas de cobro de Stripe Billing no son, por sí mismas, CFDI mexicanos.

La suite `php modulo-visualizacion/tests/prueba-pedidos.php` usa una base aleatoria en MySQL local 3307 y un transporte Stripe simulado. Cubre permisos, configuración desactivada, timeout, clave idempotente, revocación, devoluciones externas parciales/totales, eventos atrasados y reversión ante fallo de auditoría. No hace transferencias reales.
