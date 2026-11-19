<?php
/**
 * API pública de transmisiones (solo lectura, JSON). /io/api/transmisiones.php
 *   GET accion=ahora    → la transmisión al aire (título y enlace a /en-vivo), sin datos del reproductor
 *   GET accion=ics&t=slug → archivo de calendario para "Agregar a mi calendario"
 * El reproductor nunca sale de aquí: lo entrega la página /en-vivo solo a quien tiene acceso.
 */
declare(strict_types=1);
ini_set('display_errors', '0');
require_once __DIR__ . '/../lib/Transmisiones.php';

header('X-Content-Type-Options: nosniff');
$accion = (string) ($_GET['accion'] ?? '');

try {
    if ($accion === 'ahora') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=30');
        $t = IOTransmisiones::ahora();
        echo json_encode($t ? ['en_vivo' => true, 'titulo' => $t['titulo'], 'url' => '/en-vivo?t=' . rawurlencode($t['slug'])]
                            : ['en_vivo' => false], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($accion === 'ics') {
        $t = IOTransmisiones::porSlug((string) ($_GET['t'] ?? ''));
        if (!$t || $t['estado'] === 'cancelada') { http_response_code(404); exit('No encontrada'); }
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="en-vivo-' . preg_replace('/[^a-z0-9-]/', '', $t['slug']) . '.ics"');
        echo IOTransmisiones::ics($t, (string) (IO::config()['dominio'] ?? ''));
        exit;
    }
    http_response_code(400);
    header('Content-Type: application/json');
    echo '{"error":"accion"}';
} catch (Throwable $e) {
    error_log('[IO transmisiones] ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo '{"error":"interno"}';
}
