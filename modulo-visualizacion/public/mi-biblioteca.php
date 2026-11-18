<?php
/**
 * Mi biblioteca (reemplaza "Mi contenido"). Puede incluirse dentro de la plantilla actual del sitio:
 *   <?php include $_SERVER['DOCUMENT_ROOT'] . '/io/public/mi-biblioteca.php'; ?>
 * o abrirse directo en /io/public/mi-biblioteca.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/IO.php';

$__cliente = IO::clienteActual();
$__accesos = $__cliente ? IOAccesos::delCliente((int) $__cliente['id']) : [];
$__grupos = ['viendo' => [], 'empezar' => [], 'permanentes' => [], 'vencidos' => []];
foreach ($__accesos as $a) {
    if (in_array($a['estado'], ['visto', 'vencido'], true)) { $__grupos['vencidos'][] = $a; }
    elseif ($a['tipo'] === 'permanente') { $__grupos['permanentes'][] = $a; }
    elseif ($a['estado'] === 'por_empezar') { $__grupos['empezar'][] = $a; }
    else { $__grupos['viendo'][] = $a; }
}
$__esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$__standalone = !defined('IO_EMBEBIDO');
?>
<?php if ($__standalone): ?><!DOCTYPE html><html lang="es-MX"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Mi biblioteca · Inteligencia Objetiva</title>
<script>(function(){var t=null;try{t=localStorage.getItem('io-theme')}catch(e){}if(!t)t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';document.documentElement.setAttribute('data-io-theme',t)})();</script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="/tema/io-theme.css"><link rel="stylesheet" href="/portada/portada.css"></head><body class="io2"><?php endif; ?>
<style>
.iob{padding:clamp(30px,5vw,60px) 0}.iob h1{font-family:"Cormorant Garamond",serif;font-weight:500;font-size:clamp(36px,4vw,52px);color:var(--io-marino)!important;margin:0 0 6px}
.iob-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:26px 0}.iob-tabs button{border:1px solid var(--io-borde);background:var(--io-superficie);color:var(--io-texto);padding:8px 18px;border-radius:999px;font-size:14px}
.iob-tabs button.on{background:var(--io-rojo);border-color:var(--io-rojo);color:#fff}.iob-tabs b{font-weight:500;opacity:.7;margin-left:6px}
.iob-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:18px}
.iob-card{border:1px solid var(--io-borde);background:var(--io-superficie);border-radius:18px;padding:22px;display:flex;flex-direction:column;gap:12px}
.iob-card h3{font-size:18px;font-weight:500;margin:0;color:var(--io-texto)!important;line-height:1.35}
.iob-meta{font-size:13px;color:var(--io-texto-suave)}.iob-cuenta{font-size:13px;color:var(--io-rojo);font-weight:500}
.iob-card .btn-io2{justify-content:center;padding:11px 20px;font-size:14px}.iob-vacio{padding:50px 20px;text-align:center;border:1px dashed var(--io-borde);border-radius:18px;color:var(--io-texto-suave)}
</style>
<section class="iob"><div class="container">
  <span class="eyebrow">Tu espacio de estudio</span>
  <h1>Mi biblioteca</h1>
  <?php if (!$__cliente): ?>
    <div class="iob-vacio mt-4">Inicia sesión para ver tu contenido.</div>
  <?php else: ?>
  <div class="iob-tabs" role="tablist">
    <?php $__nombres = ['viendo' => 'Viendo ahora', 'empezar' => 'Por empezar', 'permanentes' => 'Permanentes', 'vencidos' => 'Vistos y vencidos'];
    $__primera = array_key_first(array_filter($__grupos)) ?: 'viendo';
    foreach ($__nombres as $k => $t): ?>
      <button type="button" role="tab" data-tab="<?= $k ?>" class="<?= $k === $__primera ? 'on' : '' ?>"><?= $t ?><b><?= count($__grupos[$k]) ?></b></button>
    <?php endforeach; ?>
  </div>
  <?php foreach ($__grupos as $k => $lista): ?>
  <div class="iob-panel" data-panel="<?= $k ?>" <?= $k === $__primera ? '' : 'hidden' ?>>
    <?php if (!$lista): ?>
      <div class="iob-vacio">Aquí aparecerá tu contenido. <a href="/conferencias-en-linea">Explora el catálogo</a></div>
    <?php else: ?><div class="iob-grid">
    <?php foreach ($lista as $a):
        $pct = $a['duracion_seg'] > 0 ? min(100, (int) round($a['max_seg'] * 100 / $a['duracion_seg'])) : 0;
        $badge = $a['tipo'] === 'unica' ? '<span class="io-badge-acceso unica">Visualización única</span>' : ($a['tipo'] === 'permanente' ? '<span class="io-badge-acceso permanente">Permanente</span>' : '<span class="io-badge-acceso">' . (int) $a['horas_ventana'] . ' h de acceso</span>'); ?>
      <article class="iob-card">
        <div><?= $badge ?></div>
        <h3><?= $__esc($a['titulo']) ?></h3>
        <?php if ($a['estado'] !== 'por_empezar' && $a['tipo'] !== 'permanente'): ?>
          <div class="io-progreso"><span style="width:<?= $pct ?>%"></span></div><div class="iob-meta"><?= $pct ?>% visto</div>
        <?php endif; ?>
        <?php if ($a['estado'] === 'activo' && $a['vence']): ?>
          <div class="iob-cuenta" data-vence="<?= $__esc(date('c', strtotime($a['vence']))) ?>">Vence el <?= $__esc(date('d/m/Y H:i', strtotime($a['vence']))) ?></div>
        <?php elseif ($a['estado'] === 'por_empezar'): ?>
          <div class="iob-meta">Tu tiempo empieza a contar cuando des play. Empieza antes del <?= $__esc(date('d/m/Y', strtotime($a['limite_inicio']))) ?>.</div>
        <?php elseif ($a['estado'] === 'en_gracia'): ?>
          <div class="iob-cuenta">Se interrumpió: puedes continuar donde te quedaste.</div>
        <?php elseif ($a['estado'] === 'visto'): ?><div class="iob-meta">Completado</div>
        <?php elseif ($a['estado'] === 'vencido'): ?><div class="iob-meta">Acceso vencido</div><?php endif; ?>
        <?php if (in_array($a['estado'], ['por_empezar', 'activo', 'en_gracia'], true)): ?>
          <a class="btn-io2 primario" href="/io/public/ver.php?acceso=<?= (int) $a['id'] ?>"><?= $a['estado'] === 'por_empezar' ? 'Empezar' : 'Continuar' ?></a>
        <?php else: ?>
          <a class="btn-io2" style="border:1px solid var(--io-borde);color:var(--io-texto)" href="/conferencias-en-linea">Volver a adquirir</a>
        <?php endif; ?>
      </article>
    <?php endforeach; ?></div><?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>
</div></section>
<script>
(function(){
  document.querySelectorAll('.iob-tabs button').forEach(function(b){b.addEventListener('click',function(){
    document.querySelectorAll('.iob-tabs button').forEach(function(x){x.classList.toggle('on',x===b)});
    document.querySelectorAll('.iob-panel').forEach(function(p){p.hidden=p.dataset.panel!==b.dataset.tab});});});
  function tic(){document.querySelectorAll('[data-vence]').forEach(function(e){var s=Math.floor((new Date(e.dataset.vence)-new Date())/1000);
    if(s<=0){e.textContent='Acceso vencido';return}var h=Math.floor(s/3600),m=Math.floor(s%3600/60);e.textContent='Te quedan '+h+' h '+m+' min';});}
  tic();setInterval(tic,30000);
})();
</script>
<?php if ($__standalone): ?><script src="/tema/io-theme.js" defer></script></body></html><?php endif; ?>
