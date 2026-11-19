<?php
/**
 * /robots.txt, /sitemap.xml, /llms.txt y /llms-full.txt generados desde el catálogo público.
 * Se conectan con las reglas de reescritura de rewrite-produccion.txt (GUIA-DESPLIEGUE.md, 5.9).
 */
declare(strict_types=1);
ini_set('display_errors', '0');
require_once __DIR__ . '/../lib/Agentes.php';

$archivo = (string) ($_GET['archivo'] ?? '');
$tipos = ['robots' => 'text/plain', 'sitemap' => 'application/xml', 'llms' => 'text/markdown', 'llms-full' => 'text/markdown'];
if (!isset($tipos[$archivo])) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo "No encontrado\n"; exit; }

header('Content-Type: ' . $tipos[$archivo] . '; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=3600');

try {
    $dominio = IOAgentes::dominio();
    if ($archivo === 'robots') {
        try { $extra = (array) (IO::config()['agentes']['robots_extra'] ?? []); } catch (Throwable $e) { $extra = []; }
        echo IOAgentes::robots($dominio, $extra);
        exit;
    }
    $productos = IOAgentes::productosPublicos();
    $transmisiones = IOAgentes::transmisionesPublicas();
    echo $archivo === 'sitemap'
        ? IOAgentes::sitemap($dominio, $productos, $transmisiones)
        : IOAgentes::llms($dominio, $productos, $transmisiones, $archivo === 'llms-full');
} catch (Throwable $e) {
    // Si la base no responde, robots.txt sigue disponible y el resto avisa con 503 para que el rastreador reintente.
    http_response_code(503);
    header('Retry-After: 600');
    echo $archivo === 'sitemap' ? '' : "Temporalmente no disponible.\n";
}
