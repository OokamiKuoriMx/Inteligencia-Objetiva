<?php
/** Pruebas puras de robots.txt, sitemap.xml, llms.txt y datos estructurados; no requieren base de datos. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/Agentes.php';
$ok = 0; $fallos = 0;
function comprobar(string $nombre, bool $valor): void {
    global $ok, $fallos;
    if ($valor) { $ok++; echo "  ✔ $nombre\n"; }
    else { $fallos++; echo "  ✘ $nombre\n"; }
}
$d = 'https://inteligenciaobjetiva.com';
$productos = [
    ['producto_id' => 1, 'slug' => 'pareja-1', 'titulo' => 'La pareja [en vivo] & tú', 'categoria' => 'conferencias', 'tema' => 'pareja',
        'resumen' => 'Cómo dejar de reaccionar.', 'descripcion' => "Primera línea.\r\nSegunda línea.", 'aprenderas' => "Idea uno\n\nIdea dos",
        'imagen' => '/portada/img/conferencias.webp', 'precio_mxn' => '350.00', 'incluye' => 'Cuaderno', 'creado' => '2026-10-01 10:00:00', 'rating' => 4.5, 'resenas' => 3],
    ['producto_id' => 2, 'slug' => 'economia-2', 'titulo' => 'Economía consciente', 'categoria' => 'cursos', 'tema' => 'economia',
        'resumen' => '', 'descripcion' => '', 'aprenderas' => '', 'imagen' => '', 'precio_mxn' => '0', 'incluye' => '', 'creado' => null, 'rating' => 0, 'resenas' => 0],
];
$tx = [
    ['slug' => 'directo-1', 'titulo' => 'Encuentro abierto', 'descripcion' => 'Preguntas y respuestas', 'imagen' => '', 'inicio' => '2030-01-10 19:00:00',
        'duracion_min' => 60, 'estado' => 'programada', 'acceso' => 'publico', 'actualizado' => '2026-11-01 09:00:00'],
];

echo "1. robots.txt\n";
$r = IOAgentes::robots($d, ["Disallow: /viejo/\nUser-agent: malo", '  ']);
foreach (['GPTBot', 'ClaudeBot', 'PerplexityBot', 'Google-Extended', 'OAI-SearchBot'] as $bot) { comprobar("da la bienvenida a $bot", strpos($r, "User-agent: $bot\n") !== false); }
comprobar('permite todo lo público', strpos($r, "Allow: /\n") !== false);
comprobar('excluye backoffice, API y biblioteca del alumno', strpos($r, 'Disallow: /io/backoffice/') !== false && strpos($r, 'Disallow: /io/api/') !== false && strpos($r, 'Disallow: /io/public/') !== false);
comprobar('excluye carrito y confirmación', strpos($r, "Disallow: /carrito\n") !== false && strpos($r, "Disallow: /gracias\n") !== false);
comprobar('no bloquea las fichas ni el catálogo', strpos($r, 'Disallow: /producto') === false && strpos($r, 'Disallow: /catalogo') === false);
comprobar('anuncia el sitemap con URL absoluta', strpos($r, "Sitemap: $d/sitemap.xml") !== false);
comprobar('una línea extra no puede inyectar renglones', strpos($r, "Disallow: /viejo/User-agent: malo\n") !== false && substr_count($r, 'User-agent: malo') === 1 && strpos($r, "\nUser-agent: malo") === false);
comprobar('ignora líneas extra vacías', strpos($r, "\n  \n") === false);

echo "\n2. sitemap.xml\n";
$s = IOAgentes::sitemap($d, $productos, $tx);
$xml = @simplexml_load_string($s);
comprobar('es XML válido', $xml !== false);
$locs = $xml ? array_map('strval', iterator_to_array($xml->url->children() ? (function () use ($xml) { foreach ($xml->url as $u) { yield (string) $u->loc; } })() : [], false)) : [];
comprobar('incluye portada, Nosotros y En vivo', in_array("$d/", $locs, true) && in_array("$d/nosotros", $locs, true) && in_array("$d/en-vivo", $locs, true));
comprobar('incluye cada ficha del catálogo', in_array("$d/producto/pareja-1", $locs, true) && in_array("$d/producto/economia-2", $locs, true));
comprobar('incluye cada tema', count(array_filter($locs, fn($l) => strpos($l, '/catalogo?tema=') !== false)) === count(IOTienda::TEMAS));
comprobar('incluye las transmisiones públicas', in_array("$d/en-vivo?t=directo-1", $locs, true));
comprobar('no repite URL', count($locs) === count(array_unique($locs)));
comprobar('pone fecha de modificación cuando existe', strpos($s, '<lastmod>2026-10-01</lastmod>') !== false);
comprobar('no inventa fecha si no la hay', substr_count($s, '<lastmod>') === 2);
comprobar('no expone rutas privadas', strpos($s, '/io/backoffice') === false && strpos($s, '/io/public') === false);

echo "\n3. llms.txt\n";
$l = IOAgentes::llms($d, $productos, $tx);
comprobar('empieza con el título del sitio', strpos($l, "# Inteligencia Objetiva\n") === 0);
comprobar('presenta a Martín Aparicio', strpos($l, 'Martín Aparicio') !== false && strpos($l, "$d/nosotros") !== false);
comprobar('los temas son enunciativos y no limitativos', strpos($l, 'de manera enunciativa y no limitativa') !== false && strpos($l, 'espiritualidad') !== false);
comprobar('lista cada tema con su enlace', strpos($l, "[Relación de pareja]($d/catalogo?tema=pareja)") !== false);
comprobar('agrupa por categoría', strpos($l, '## Conferencias en línea') !== false && strpos($l, '## Cursos en línea') !== false);
comprobar('los corchetes del título no rompen el enlace', strpos($l, "[La pareja (en vivo) & tú]($d/producto/pareja-1): Cómo dejar de reaccionar.") !== false);
comprobar('un resumen vacío no deja dos puntos sueltos', strpos($l, "[Economía consciente]($d/producto/economia-2)\n") !== false);
comprobar('incluye las transmisiones públicas', strpos($l, '[Encuentro abierto]') !== false);
comprobar('enlaza la versión completa', strpos($l, "$d/llms-full.txt") !== false);
comprobar('es Markdown sin HTML', strpos($l, '<') === false);
$c = IOAgentes::llms($d, $productos, $tx, true);
comprobar('la versión completa trae la descripción', strpos($c, "Primera línea.\nSegunda línea.") !== false);
comprobar('la versión completa trae lo que vas a comprender', strpos($c, "- Idea uno\n- Idea dos") !== false);
comprobar('la versión completa trae precio con IVA', strpos($c, '$350.00 MXN con IVA') !== false);
comprobar('no muestra precio cero', strpos($c, '$0.00') === false);
comprobar('la versión completa no se enlaza a sí misma', strpos($c, 'llms-full.txt') === false);

echo "\n4. Datos estructurados\n";
$g = IOAgentes::grafoSitio($d);
$tipos = array_column($g['@graph'], '@type');
comprobar('declara organización, persona y sitio', $tipos === ['Organization', 'Person', 'WebSite']);
comprobar('la organización tiene como fundador a Martín', $g['@graph'][0]['founder']['@id'] === $g['@graph'][1]['@id']);
comprobar('el sitio ofrece búsqueda en el catálogo', strpos($g['@graph'][2]['potentialAction']['target'], '/catalogo?q={search_term_string}') !== false);
$cu = IOAgentes::curso($d, $productos[0]);
comprobar('el curso tiene URL, idioma y autor', $cu['url'] === "$d/producto/pareja-1" && $cu['inLanguage'] === 'es-MX' && $cu['creator']['name'] === 'Martín Aparicio');
comprobar('el curso declara lo que enseña', $cu['teaches'] === ['Idea uno', 'Idea dos']);
comprobar('el curso usa imagen absoluta', $cu['image'] === "$d/portada/img/conferencias.webp");
comprobar('el curso conserva precio y calificación', $cu['offers']['price'] === '350.00' && $cu['aggregateRating']['reviewCount'] === 3);
comprobar('sin reseñas no se inventa calificación', !isset(IOAgentes::curso($d, $productos[1])['aggregateRating']));
$ev = IOAgentes::evento($d, $tx[0]);
comprobar('la transmisión pública es un evento en línea', $ev && $ev['eventAttendanceMode'] === 'https://schema.org/OnlineEventAttendanceMode' && $ev['location']['@type'] === 'VirtualLocation');
comprobar('el evento termina según su duración', $ev && strtotime($ev['endDate']) - strtotime($ev['startDate']) === 3600);
comprobar('una transmisión para miembros no se publica', IOAgentes::evento($d, ['acceso' => 'miembros'] + $tx[0]) === null);
comprobar('una transmisión cancelada no se publica', IOAgentes::evento($d, ['estado' => 'cancelada'] + $tx[0]) === null);
$j = IOAgentes::jsonLd(['name' => '</script><script>alert(1)</script> & más']);
comprobar('el JSON-LD no puede cerrar la etiqueta script', substr_count($j, '</script>') === 1 && strpos($j, '</script') !== false);
comprobar('el JSON-LD conserva acentos legibles', strpos($j, 'más') !== false);

echo "\n5. Página de archivos\n";
$correr = function (string $archivo): string {
    $base = tempnam(sys_get_temp_dir(), 'ioag'); $tmp = $base . '.php';
    file_put_contents($tmp, '<?php $_GET = ["archivo" => ' . var_export($archivo, true) . ']; include ' . var_export(__DIR__ . '/../tienda/agentes.php', true) . ';');
    $salida = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp));
    @unlink($tmp); @unlink($base);
    return $salida;
};
comprobar('robots.txt responde aunque no haya base de datos', strpos($correr('robots'), 'User-agent: ClaudeBot') !== false);
comprobar('un archivo desconocido no muestra nada interno', trim($correr('../config')) === 'No encontrado');

echo "\nResultado: $ok correctas, $fallos fallidas\n";
exit($fallos ? 1 : 0);
