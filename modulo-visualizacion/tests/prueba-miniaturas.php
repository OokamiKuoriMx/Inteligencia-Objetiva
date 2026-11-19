<?php
/** Pruebas de subida de miniaturas (solo conecta a la MySQL de prueba; no crea tablas). php tests/prueba-miniaturas.php */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/Miniaturas.php';

$dir = sys_get_temp_dir() . '/io-prueba-miniaturas-' . bin2hex(random_bytes(4));
IO::usarConexion(new PDO(getenv('IO_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3307;charset=utf8mb4', getenv('IO_TEST_USER') ?: 'root', getenv('IO_TEST_PASS') ?: ''), ['miniaturas_dir' => $dir, 'miniaturas_url' => '/io/subidas/miniaturas']);
$ok = 0; $fallos = 0;
function verificar(string $n, bool $c): void { global $ok, $fallos; if ($c) { $ok++; echo "  ✔ $n\n"; } else { $fallos++; echo "  ✘ $n\n"; } }
function archivo(string $bytes): array { $t = tempnam(sys_get_temp_dir(), 'iom'); file_put_contents($t, $bytes); return ['name' => 'x', 'tmp_name' => $t, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)]; }
/** PNG mínimo válido de las medidas indicadas (getimagesize solo lee la cabecera IHDR). */
function png(int $w, int $h): string
{
    $ihdr = pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0);
    $chunk = fn(string $t, string $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', $ihdr) . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\0\0\0", $w), 1))) . $chunk('IEND', '');
}
function falla(string $n, string $codigo, array $f): void { try { IOMiniaturas::guardar($f, false); verificar("$n (debía fallar)", false); } catch (IOError $e) { verificar("$n → $codigo", $e->codigo === $codigo); } }

echo "\nMiniaturas\n";
$r = IOMiniaturas::guardar(archivo(png(1280, 720)), false);
verificar('imagen válida se guarda con nombre aleatorio', (bool) preg_match('#^/io/subidas/miniaturas/[a-f0-9]{24}\.(png|webp)$#', $r['url']) && is_file($dir . '/' . basename($r['url'])));
verificar('informa las medidas', $r['ancho'] === 1280 && $r['alto'] === 720);
$r2 = IOMiniaturas::guardar(archivo(png(1280, 720)), false);
verificar('dos subidas no se pisan', $r['url'] !== $r2['url']);
falla('texto plano', 'formato', archivo('hola, esto no es una imagen'));
falla('código PHP disfrazado', 'formato', archivo('<?php echo 1; ?>' . png(1280, 720)));
falla('SVG (puede llevar scripts)', 'formato', archivo('<svg xmlns="http://www.w3.org/2000/svg" width="1280" height="720"><script>alert(1)</script></svg>'));
falla('imagen demasiado pequeña', 'medidas', archivo(png(200, 100)));
falla('archivo vacío', 'tamano', archivo(''));
falla('error de subida', 'archivo', ['tmp_name' => '', 'error' => UPLOAD_ERR_PARTIAL]);
falla('excede el límite del servidor', 'tamano', ['tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE]);
falla('más de 4 MB', 'tamano', archivo(png(1280, 720) . str_repeat("\0", IOMiniaturas::MAX_BYTES)));
falla('más de 4096 px por lado', 'medidas', archivo(png(5000, 3000)));
$ses = ['io_subidas' => array_fill(0, IOMiniaturas::MAX_POR_HORA, time())];
try { IOMiniaturas::registrarIntento($ses); verificar('límite de subidas por hora (debía fallar)', false); } catch (IOError $e) { verificar('límite de subidas por hora → limite', $e->codigo === 'limite'); }
$ses = ['io_subidas' => array_fill(0, IOMiniaturas::MAX_POR_HORA, time() - 7200)]; IOMiniaturas::registrarIntento($ses);
verificar('las subidas de hace más de una hora no cuentan', count($ses['io_subidas']) === 1);
foreach (['/portada/img/a.webp' => true, '/img/productos/x.JPG' => true, '/io/backoffice/salir.php' => false, '/portada/img/a.webp?v=2' => true, '//externo/a.png' => false, '/a.php?x=.png' => false] as $ruta => $esperado) {
    verificar("ruta local $ruta " . ($esperado ? 'aceptada' : 'rechazada'), IOMiniaturas::rutaLocalValida($ruta) === $esperado);
}
verificar('la carpeta pública bloquea scripts', (bool) preg_match('/Require all denied/', (string) file_get_contents(__DIR__ . '/../subidas/.htaccess')));

array_map('unlink', glob($dir . '/*')); @rmdir($dir);
echo "\nResultado: $ok correctas, $fallos fallidas\n";
exit($fallos ? 1 : 0);
