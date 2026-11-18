<?php
/** Página del reproductor: /io/public/ver.php?acceso=ID */
declare(strict_types=1);
require_once __DIR__ . '/../lib/IO.php';

$cliente = IO::clienteActual();
if (!$cliente) { header('Location: /?login=1'); exit; }
$id = (int) ($_GET['acceso'] ?? 0);
$a = IO::uno('SELECT a.id, a.estado, a.cliente_id, p.titulo, p.tipo FROM io_accesos a JOIN io_politicas p ON p.producto_id = a.producto_id WHERE a.id = ?', [$id]);
if (!$a || (int) $a['cliente_id'] !== (int) $cliente['id']) { http_response_code(404); exit('Contenido no encontrado.'); }
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="es-MX" data-io-theme="dark">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $e($a['titulo']) ?> · Inteligencia Objetiva</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500&family=Roboto:wght@300;400;500&display=swap">
<link rel="stylesheet" href="/tema/io-theme.css">
<style>
  body{margin:0;background:#07080b;color:#e5e7eb;font-family:Roboto,system-ui,sans-serif}
  .barra{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px clamp(16px,4vw,48px)}
  .barra a{color:#a1a1aa;text-decoration:none;font-size:14px}.barra a:hover{color:#fff}
  .titulo{font-family:"Cormorant Garamond",serif;font-size:clamp(22px,2.6vw,32px);font-weight:500;margin:0;color:#fff}
  .escena{max-width:1180px;margin:0 auto;padding:0 clamp(12px,3vw,32px) 40px}
  .ayuda{margin-top:18px;font-size:13.5px;color:#a1a1aa;text-align:center}
</style>
</head>
<body>
  <div class="barra">
    <a href="/usuario/">← Mi biblioteca</a>
    <h1 class="titulo"><?= $e($a['titulo']) ?></h1>
    <a href="https://wa.me/525519514412?text=<?= rawurlencode('Hola, necesito ayuda con: ' . $a['titulo']) ?>" target="_blank" rel="noopener">Ayuda</a>
  </div>
  <div class="escena">
    <div id="reproductor"></div>
    <p class="ayuda">Si se corta tu conexión, vuelve a esta página para continuar donde te quedaste.</p>
  </div>
  <script src="https://player.vimeo.com/api/player.js"></script>
  <script src="/io/public/io-player.js?v=2.0.0"></script>
  <script>
    IOPlayer.montar('#reproductor', {
      accesoId: <?= (int) $a['id'] ?>,
      titulo: <?= json_encode($a['titulo'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      tipo: <?= json_encode($a['tipo']) ?>,
      api: '/io/api/reproduccion.php',
      urlCatalogo: '/conferencias-en-linea'
    });
  </script>
</body>
</html>
