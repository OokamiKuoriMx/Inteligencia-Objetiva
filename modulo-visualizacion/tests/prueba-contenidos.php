<?php
/** Edición comercial/políticas en una base desechable, sin red, secretos ni contenido de producción. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/Contenidos.php';
$pdo = new PDO(getenv('IO_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3307;charset=utf8mb4', getenv('IO_TEST_USER') ?: 'root', getenv('IO_TEST_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$bd = 'io_prueba_contenidos_' . bin2hex(random_bytes(5));
$pdo->exec("CREATE DATABASE `$bd` CHARACTER SET utf8mb4"); $pdo->exec("USE `$bd`");
date_default_timezone_set('America/Mexico_City');
IO::usarConexion($pdo, ['zona_horaria' => 'America/Mexico_City', 'vimeo_token' => '']);
$ok = 0; $fallos = 0;
function verificarContenido(string $nombre, bool $resultado): void { global $ok, $fallos; if ($resultado) { $ok++; echo "  ✔ $nombre\n"; } else { $fallos++; echo "  ✘ $nombre\n"; } }
function errorContenido(string $nombre, string $codigo, callable $fn, int $http = 0): void { try { $fn(); verificarContenido($nombre, false); } catch (IOError $e) { verificarContenido($nombre, $e->codigo === $codigo && (!$http || $e->getCode() === $http)); } }
function datosContenido(int $id, array $cambios = []): array { return array_replace(IOContenidos::nuevo(), ['producto_id' => $id, 'titulo' => 'Contenido de prueba ' . $id, 'vimeo_id' => '123456789',
    'resumen' => 'Resumen aprobado.', 'descripcion' => "Descripción de prueba.\nSegunda línea.", 'aprenderas' => " Primera idea \r\n\r\n Segunda idea ", 'categoria' => 'conferencias', 'tema' => 'pareja',
    'imagen' => '/portada/img/conferencias.webp', 'precio_mxn' => '579.00', 'precio_anterior' => '', 'incluye' => 'Cuaderno', 'activo' => 1], $cambios); }
try {
    foreach (glob(__DIR__ . '/../sql/*.sql') as $archivo) {
        foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', file_get_contents($archivo))))) as $sql) { $pdo->exec($sql); }
    }
    foreach ([1 => 'admin', 2 => 'editor', 3 => 'soporte', 4 => 'finanzas'] as $id => $rol) {
        IO::ejecutar('INSERT INTO io_admins (id,email,nombre,hash,rol) VALUES (?,?,?,?,?)', [$id, $rol . '@ejemplo.test', $rol, 'hash-ficticio', $rol]);
    }
    $admin = ['id' => 1, 'email' => 'admin@ejemplo.test']; $editor = ['id' => 2, 'email' => 'editor@ejemplo.test'];
    verificarContenido('los nuevos contenidos empiezan como borrador sin precio inventado', IOContenidos::nuevo()['activo'] === 0 && IOContenidos::nuevo()['precio_mxn'] === '0.00' && IOContenidos::nuevo()['version'] === '');
    errorContenido('un ID inexistente devuelve 404', 'producto', fn() => IOContenidos::obtener(999), 404);
    foreach ([3, 4, 99] as $id) { errorContenido('revalida el permiso de edición para usuario ' . $id, 'sin_permiso', fn() => IOContenidos::guardar(['id' => $id, 'rol' => 'admin'], datosContenido(10), 'Prueba'), 403); }
    errorContenido('exige motivo del cambio', 'motivo', fn() => IOContenidos::guardar($admin, datosContenido(10), ''));
    $id = IOContenidos::guardar($editor, datosContenido(10), 'Alta editorial'); $d = IOContenidos::obtener($id);
    verificarContenido('el editor crea política y catálogo juntos', $id === 10 && $d['existe_politica'] && $d['existe_producto']);
    verificarContenido('el título se sincroniza sin ambigüedad', $d['titulo'] === 'Contenido de prueba 10' && $d['titulo_comercial'] === $d['titulo']);
    verificarContenido('conserva centavos MXN y transforma objetivos en líneas limpias', $d['precio_mxn'] === '579.00' && $d['aprenderas'] === "Primera idea\nSegunda idea");
    verificarContenido('entrega versión y snapshots para edición', preg_match('/^[a-f0-9]{64}$/D', $d['version']) === 1 && is_array($d['snapshot']['politica']) && is_array($d['snapshot']['producto']));
    verificarContenido('genera slug estable con el ID', $d['slug'] === 'contenido-de-prueba-10-10');
    errorContenido('crear sobre un ID existente no lo sobrescribe', 'conflicto', fn() => IOContenidos::guardar($admin, datosContenido(10, ['titulo' => 'No debe reemplazar']), 'Colisión'), 409);
    $anterior = $d;
    IOContenidos::guardar($admin, array_replace($d, ['titulo' => 'Nombre actualizado', 'precio_mxn' => '600.01', 'slug' => 'no-cambiar', 'ventas' => 999]), 'Actualizar ficha');
    $d = IOContenidos::obtener(10);
    verificarContenido('editar preserva slug y sincroniza el título comercial', $d['slug'] === $anterior['slug'] && $d['titulo_comercial'] === 'Nombre actualizado');
    verificarContenido('los contadores no son editables desde el formulario', (int) $d['ventas'] === 0);
    errorContenido('una versión vieja no pisa cambios posteriores', 'conflicto', fn() => IOContenidos::guardar($admin, array_replace($anterior, ['titulo' => 'Cambio obsoleto']), 'Formulario antiguo'), 409);
    errorContenido('el ID original no puede cambiar al editar', 'producto_fijo', fn() => IOContenidos::guardar($admin, array_replace($d, ['producto_id' => 11]), 'Cambiar ID'), 409);
    $version = $d['version'];
    IO::ejecutar('UPDATE io_productos SET ventas = ventas + 1 WHERE producto_id=10');
    verificarContenido('una nueva venta no invalida un formulario editorial', IOContenidos::obtener(10)['version'] === $version);
    IO::ejecutar('UPDATE io_politicas SET dias_para_empezar=7 WHERE producto_id=10');
    errorContenido('detecta cambios concurrentes de las reglas de acceso', 'conflicto', fn() => IOContenidos::guardar($admin, $d, 'Versión previa'), 409);
    $d = IOContenidos::obtener(10);
    foreach (['-1', '10.001', 'NaN', '1e3', '100000000.00'] as $precio) {
        errorContenido('rechaza precio no representable: ' . $precio, 'precio', fn() => IOContenidos::guardar($admin, array_replace($d, ['precio_mxn' => $precio]), 'Precio inválido'));
    }
    errorContenido('no publica precio cero', 'precio', fn() => IOContenidos::guardar($admin, datosContenido(11, ['precio_mxn' => '0.00']), 'Publicar'));
    errorContenido('no publica un contenido sin video', 'video', fn() => IOContenidos::guardar($admin, datosContenido(11, ['vimeo_id' => '']), 'Publicar'));
    IOContenidos::guardar($admin, datosContenido(11, ['activo' => 0, 'precio_mxn' => '0.00', 'vimeo_id' => '']), 'Preparar borrador');
    verificarContenido('un borrador puede conservar video y precio pendientes', IOContenidos::obtener(11)['activo'] === 0 && IOContenidos::obtener(11)['vimeo_id'] === '');
    errorContenido('única exige duración incluso antes de vender', 'duracion', fn() => IOContenidos::guardar($admin, datosContenido(12, ['tipo' => 'unica', 'duracion_seg' => 0]), 'Regla inválida'));
    IOContenidos::guardar($admin, datosContenido(12, ['tipo' => 'unica', 'duracion_seg' => 1800, 'reanudaciones_max' => 0, 'dias_para_empezar' => 0, 'vimeo_hash' => 'ab12CD34']), 'Regla completa');
    verificarContenido('conserva reglas únicas explícitas y hash válido', IOContenidos::obtener(12)['reanudaciones_max'] === 0 && IOContenidos::obtener(12)['vimeo_hash'] === 'ab12CD34');
    foreach (['javascript:alert(1)', '//externo.test/imagen.webp', 'http://externo.test/imagen.webp', '/portada/../config.php', '/img/%2e%2e/config.php', 'https://usuario:clave@externo.test/a.webp'] as $url) {
        errorContenido('rechaza ruta de imagen insegura: ' . $url, 'imagen', fn() => IOContenidos::guardar($admin, datosContenido(13, ['imagen' => $url]), 'Imagen inválida'));
    }
    IOContenidos::guardar($admin, datosContenido(13, ['imagen' => 'https://cdn.example.test/imagen.webp']), 'Imagen HTTPS');
    verificarContenido('acepta URL HTTPS para una imagen existente', IOContenidos::obtener(13)['imagen'] === 'https://cdn.example.test/imagen.webp');
    errorContenido('los textos editoriales no almacenan HTML ejecutable', 'datos', fn() => IOContenidos::guardar($admin, datosContenido(14, ['descripcion' => '<script>alert(1)</script>']), 'HTML inválido'));
    errorContenido('limita el resumen a 400 caracteres', 'datos', fn() => IOContenidos::guardar($admin, datosContenido(14, ['resumen' => str_repeat('á', 401)]), 'Resumen largo'));
    errorContenido('la categoría debe pertenecer al catálogo permitido', 'categoria', fn() => IOContenidos::guardar($admin, datosContenido(14, ['categoria' => 'inventada']), 'Categoría inválida'));
    errorContenido('el tema debe pertenecer al catálogo permitido', 'categoria', fn() => IOContenidos::guardar($admin, datosContenido(14, ['tema' => 'inventado']), 'Tema inválido'));
    errorContenido('no acepta pegar una URL como ID de Vimeo', 'video', fn() => IOContenidos::guardar($admin, datosContenido(14, ['vimeo_id' => 'https://vimeo.com/123']), 'Video inválido'));
    errorContenido('limita la longitud del ID de Vimeo', 'video', fn() => IOContenidos::guardar($admin, datosContenido(14, ['vimeo_id' => str_repeat('1', 33)]), 'Video inválido'));
    errorContenido('valida los límites de reanudación', 'datos', fn() => IOContenidos::guardar($admin, datosContenido(14, ['reanudaciones_max' => 6]), 'Regla inválida'));
    errorContenido('sin token Vimeo ofrece duración manual sin hacer llamadas', 'vimeo_config', fn() => IOContenidos::consultarVideo($admin, '123456789'), 503);
    $antes = IOContenidos::obtener(10); $auditoria = (int) IO::uno('SELECT COUNT(*) n FROM io_bitacora')['n'];
    $pdo->exec("CREATE TRIGGER io_contenido_fallo BEFORE INSERT ON io_bitacora FOR EACH ROW BEGIN IF NEW.accion='contenido_metadatos' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fallo intencional de prueba'; END IF; END");
    $fallo = false;
    try { IOContenidos::guardar($admin, array_replace($antes, ['titulo' => 'No persistir', 'precio_mxn' => '999.99']), 'Prueba reversión'); } catch (PDOException $e) { $fallo = true; }
    $pdo->exec('DROP TRIGGER io_contenido_fallo');
    $despues = IOContenidos::obtener(10);
    verificarContenido('si falla la auditoría comercial se revierten política y producto', $fallo && $antes['version'] === $despues['version'] && $despues['titulo'] !== 'No persistir');
    verificarContenido('la reversión incluye también la primera bitácora de política', (int) IO::uno('SELECT COUNT(*) n FROM io_bitacora')['n'] === $auditoria);
    verificarContenido('las altas y cambios comerciales quedan auditados', (int) IO::uno('SELECT COUNT(*) n FROM io_bitacora WHERE accion="contenido_metadatos"')['n'] === 5);
    IO::ejecutar('UPDATE io_admins SET activo=0 WHERE id=2');
    errorContenido('desactivar al editor revoca inmediatamente su permiso', 'sin_permiso', fn() => IOContenidos::guardar($editor, IOContenidos::obtener(10), 'Sesión anterior'), 403);
} finally { if ($pdo->inTransaction()) { $pdo->rollBack(); } $pdo->exec("DROP DATABASE `$bd`"); }
echo "Resultado: $ok correctas, $fallos fallidas\n";
exit($fallos ? 1 : 0);
