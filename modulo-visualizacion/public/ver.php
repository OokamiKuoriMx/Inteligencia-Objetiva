<?php
/** Página del reproductor: /io/public/ver.php?acceso=ID */
declare(strict_types=1);
require_once __DIR__ . '/../lib/IO.php';
if (!headers_sent()) { header('X-Robots-Tag: noindex, nofollow'); }

$cliente = IO::clienteActual();
if (!$cliente) { header('Location: /?io_acceso=login&io_destino=biblioteca'); exit; }
$id = (int) ($_GET['acceso'] ?? 0);
$a = IO::uno('SELECT a.id, a.estado, a.cliente_id, a.vence, a.limite_inicio, a.gracia_hasta, a.reanudaciones, p.titulo, p.tipo, p.horas_ventana, p.reanudaciones_max FROM io_accesos a JOIN io_politicas p ON p.producto_id = a.producto_id WHERE a.id = ?', [$id]);
if ($a) { $a = array_merge($a, IOAccesos::refrescar($a)); }
if (!$a || (int) $a['cliente_id'] !== (int) $cliente['id']) { http_response_code(404); exit('Contenido no encontrado.'); }
$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="es-MX" data-io-theme="dark">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $e($a['titulo']) ?> · Inteligencia Objetiva</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500&display=swap">
<link rel="stylesheet" href="/tema/io-theme.css">
<style>
  body{margin:0;background:#07080b;color:#e5e7eb;font-family:Roboto,system-ui,sans-serif}
  .barra{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px clamp(16px,4vw,48px)}
  .barra a{color:#a1a1aa;text-decoration:none;font-size:14px}.barra a:hover{color:#fff}
  .titulo{font-family:Roboto,system-ui,sans-serif;font-size:clamp(22px,2.6vw,32px);font-weight:300;margin:0;color:#fff}
  .escena{max-width:1180px;margin:0 auto;padding:0 clamp(12px,3vw,32px) 40px}
  .ayuda{margin-top:18px;font-size:13.5px;color:#a1a1aa;text-align:center}
  .vigencia{margin:0 0 14px;padding:10px 16px;border-radius:12px;background:#121318;border:1px solid #2a2d34;font-size:14px;color:#d4d4d8;display:flex;flex-wrap:wrap;gap:4px 10px;align-items:center}
  .vigencia time{color:#fff}.vigencia .io-restante{color:#a1a1aa}.vigencia .io-restante.urgente{color:#f87171}
</style>
</head>
<body>
  <div class="barra">
    <a href="/io/public/mi-biblioteca.php">← Mi biblioteca</a>
    <h1 class="titulo"><?= $e($a['titulo']) ?></h1>
    <a href="https://wa.me/525519514412?text=<?= rawurlencode('Hola, necesito ayuda con: ' . $a['titulo']) ?>" target="_blank" rel="noopener">Ayuda</a>
  </div>
  <div class="escena">
    <div class="vigencia" id="vigencia">
      <?php if ($a['origen'] === 'membresia' && $a['vence'] && in_array($a['estado'], ['por_empezar', 'activo', 'en_gracia'], true)): ?>
        <span>El período pagado de tu membresía termina el <?= IO::htmlFecha($a['vence']) ?></span> <?= IO::htmlRestante($a['vence']) ?>
      <?php elseif ($a['origen'] === 'membresia'): ?>
        <span>Consulta la vigencia de tu membresía en tu biblioteca.</span>
      <?php elseif ($a['vence'] && in_array($a['estado'], ['activo', 'en_gracia'], true)): ?>
        <span>Tu acceso vence el <?= IO::htmlFecha($a['vence']) ?></span> <?= IO::htmlRestante($a['vence']) ?>
      <?php elseif ($a['estado'] === 'por_empezar' && $a['tipo'] === 'ventana'): ?>
        <span>Al dar play tendrás <?= (int) $a['horas_ventana'] ?> h para verlo. Empieza antes del <?= IO::htmlFecha($a['limite_inicio']) ?></span>
      <?php elseif ($a['estado'] === 'en_gracia'): ?>
        <span>Se interrumpió tu reproducción. Continúa antes del <?= IO::htmlFecha($a['gracia_hasta']) ?></span> <?= IO::htmlRestante($a['gracia_hasta']) ?>
      <?php elseif ($a['tipo'] === 'unica'): ?>
        <span>Visualización única · reanudaciones disponibles: <?= max(0, (int) $a['reanudaciones_max'] - (int) $a['reanudaciones']) ?></span>
      <?php elseif ($a['tipo'] === 'permanente'): ?>
        <span>Acceso permanente: sin fecha de vencimiento.</span>
      <?php endif; ?>
    </div>
    <div id="reproductor"></div>
    <p class="ayuda">Si se interrumpe la reproducción, consulta en tu biblioteca si puedes reanudarla.</p>
  </div>
  <script src="https://player.vimeo.com/api/player.js"></script>
  <script src="/io/public/io-player.js?v=2.0.0"></script>
  <script src="/tema/io-theme.js?v=2.0.2" defer></script>
  <script>
    IOPlayer.montar('#reproductor', {
      accesoId: <?= (int) $a['id'] ?>,
      titulo: <?= json_encode($a['titulo'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      tipo: <?= json_encode($a['tipo']) ?>,
      api: '/io/api/reproduccion.php',
      urlCatalogo: '/conferencias-en-linea',
      alIniciar: function (r) {
        var v = document.getElementById('vigencia');
        if (r.vence) {
          v.innerHTML = '<span>Tu acceso vence el <time class="io-fecha"></time></span> <span class="io-restante"></span>';
          v.querySelector('time').setAttribute('datetime', r.vence);
          v.querySelector('.io-restante').setAttribute('data-hasta', r.vence);
        } else if (r.reglas) {
          v.textContent = 'Visualización única · reanudaciones disponibles después de esta: ' + Math.max(0, r.reglas.reanudaciones_rest);
        }
        if (window.IOFechas) window.IOFechas();
      }
    });
  </script>
</body>
</html>
