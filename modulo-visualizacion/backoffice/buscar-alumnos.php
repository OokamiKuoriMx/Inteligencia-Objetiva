<?php
/** Búsqueda de alumnos para "Otorgar acceso" (GET ?q=, JSON, máximo 10 resultados, mínimo 2 caracteres). */
declare(strict_types=1);
require __DIR__ . '/_inc.php';
require_once __DIR__ . '/../lib/Alumnos.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); header('Allow: GET'); exit; }
// Sin sesión responde 401 en JSON (el fetch no debe seguir la redirección al login).
if (!admin()) { http_response_code(401); header('Content-Type: application/json; charset=utf-8'); echo '{"error":"sesion"}'; exit; }
exigir('accesos');

header('Content-Type: application/json; charset=utf-8');
$q = (string) ($_GET['q'] ?? '');
try {
    echo json_encode([
        'q' => $q,
        'minimo' => IOAlumnos::MINIMO,
        'resultados' => IOAlumnos::buscar($q),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[IO buscar-alumnos] ' . $e->getMessage());
    http_response_code(500);
    echo '{"error":"busqueda"}';
}
