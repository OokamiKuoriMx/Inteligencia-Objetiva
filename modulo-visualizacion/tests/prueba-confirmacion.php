<?php
/** Renderiza confirmaciones reales contra una base exclusiva. No usa sesiones de disco ni cuentas externas. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/IO.php';
$pdo = new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$bd = 'io_prueba_confirmacion_' . bin2hex(random_bytes(5));
$pdo->exec("CREATE DATABASE `$bd` CHARACTER SET utf8mb4");
$ok = 0; $fallos = 0;
function comprobarConfirmacion(string $n, bool $c): void { global $ok, $fallos; if ($c) { $ok++; echo "  ✔ $n\n"; } else { $fallos++; echo "  ✘ $n\n"; } }
function renderizarConfirmacion(string $bd, string $folio, bool $actual = false): string
{
    $sesion = ['io_carrito' => [1, 2]];
    if ($actual) { $sesion['io_checkout_folio'] = $folio; }
    $codigo = 'require ' . var_export(__DIR__ . '/../lib/IO.php', true) . ';'
        . '$pdo=new PDO("mysql:host=127.0.0.1;port=3307;charset=utf8mb4","root","",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);'
        . '$pdo->exec(' . var_export("USE `$bd`", true) . ');'
        . 'IO::usarConexion($pdo,["dominio"=>"http://127.0.0.1:8090","dev"=>true,"cliente_actual"=>function(){return ["id"=>101,"email"=>"alumna@prueba.test","nombre"=>"Alumna"]; }]);'
        . 'session_set_save_handler(function(){return true;},function(){return true;},function(){return "";},function(){return true;},function(){return true;},function(){return true;});session_start();'
        . '$_SESSION=' . var_export($sesion, true) . ';$_GET=["folio"=>' . var_export($folio, true) . '];'
        . '$_SERVER["REQUEST_URI"]="/io/tienda/gracias.php";'
        . 'require ' . var_export(__DIR__ . '/../tienda/gracias.php', true) . ';'
        . 'echo "<!--carrito:".count(IOTienda::carritoIds())."-->";';
    $proceso = proc_open([PHP_BINARY, '-r', $codigo], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    if (!is_resource($proceso)) { throw new RuntimeException('No se pudo renderizar la confirmación.'); }
    fclose($pipes[0]); $html = stream_get_contents($pipes[1]); fclose($pipes[1]); $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($proceso) !== 0) { throw new RuntimeException('Falló el renderizado: ' . $error); }
    return $html;
}
try {
    $pdo->exec("USE `$bd`");
    foreach (glob(__DIR__ . '/../sql/*.sql') as $f) { foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents($f))))) as $sql) { $pdo->exec($sql); } }
    $pdo->exec('INSERT INTO io_politicas (producto_id,titulo,vimeo_id,tipo) VALUES (1,"Contenido de prueba","123","permanente")');
    foreach ([[1,'CANCELADO','cancelado',101], [2,'DEVUELTO','pagado',101], [3,'PENDIENTE','pendiente',101], [4,'PAGADO','pagado',101], [5,'AJENO','pagado',202]] as [$id,$folio,$estado,$cliente]) {
        $st = $pdo->prepare('INSERT INTO io_pedidos (id,folio,cliente_id,email,nombre,subtotal,iva,total,pasarela,estado) VALUES (?,?,?,"alumna@prueba.test","Alumna",100,16,116,"demo",?)');
        $st->execute([$id,$folio,$cliente,$estado]);
        $pdo->prepare('INSERT INTO io_pedido_items (pedido_id,producto_id,titulo,precio_mxn) VALUES (?,1,"Contenido de prueba",116)')->execute([$id]);
    }
    $pdo->exec('INSERT INTO io_reembolsos (pedido_id,clave_solicitud,estado,monto_centavos,moneda,motivo) VALUES (2,"ref-prueba","completo",11600,"MXN","Prueba")');
    $html = renderizarConfirmacion($bd, 'CANCELADO');
    comprobarConfirmacion('pedido cancelado muestra estado terminal sin recargar indefinidamente', strpos($html, 'Este pedido fue cancelado') !== false && strpos($html, 'setTimeout') === false);
    $html = renderizarConfirmacion($bd, 'DEVUELTO');
    comprobarConfirmacion('pedido reembolsado no promete contenido disponible', strpos($html, 'Tu compra fue reembolsada') !== false && strpos($html, 'Tu contenido está disponible') === false && strpos($html, 'setTimeout') === false);
    $html = renderizarConfirmacion($bd, 'PENDIENTE');
    comprobarConfirmacion('pago pendiente conserva la comprobación de confirmación', strpos($html, 'Estamos confirmando tu pago') !== false && strpos($html, 'setTimeout') !== false);
    $html = renderizarConfirmacion($bd, 'PAGADO');
    comprobarConfirmacion('consultar una compra antigua no borra el carrito actual', strpos($html, '<!--carrito:2-->') !== false);
    $html = renderizarConfirmacion($bd, 'PAGADO', true);
    comprobarConfirmacion('el retorno de la compra actual retira solo los productos comprados', strpos($html, '<!--carrito:1-->') !== false);
    $html = renderizarConfirmacion($bd, 'AJENO');
    comprobarConfirmacion('no muestra la confirmación de otro cliente', strpos($html, 'No encontramos este pedido en tu cuenta') !== false && strpos($html, 'Gracias, ya es tuyo') === false);
} finally { $pdo->exec("DROP DATABASE `$bd`"); }
echo "Resultado: $ok correctas, $fallos fallidas\n";
exit($fallos ? 1 : 0);
