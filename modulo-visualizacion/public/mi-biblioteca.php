<?php
/**
 * Mi biblioteca (reemplaza "Mi contenido"). Puede incluirse dentro de la plantilla actual del sitio:
 *   <?php include $_SERVER['DOCUMENT_ROOT'] . '/io/public/mi-biblioteca.php'; ?>
 * o abrirse directo en /io/public/mi-biblioteca.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/IO.php';
if (!headers_sent()) { header('X-Robots-Tag: noindex, nofollow'); }

$__cliente = IO::clienteActual();
$__accesos = $__cliente ? IOAccesos::delCliente((int) $__cliente['id']) : [];
$__grupos = ['viendo' => [], 'empezar' => [], 'permanentes' => [], 'vencidos' => []];
foreach ($__accesos as $a) {
    if (in_array($a['estado'], ['visto', 'vencido'], true)) { $__grupos['vencidos'][] = $a; }
    elseif ($a['tipo'] === 'permanente' && $a['origen'] !== 'membresia') { $__grupos['permanentes'][] = $a; }
    elseif ($a['estado'] === 'por_empezar') { $__grupos['empezar'][] = $a; }
    else { $__grupos['viendo'][] = $a; }
}
$__esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$__standalone = !defined('IO_EMBEBIDO');
?>
<?php if ($__standalone) { require_once __DIR__ . '/../tienda/_layout.php'; encabezado('Mi biblioteca', 'biblioteca'); } ?>
<style>
.iob{padding:clamp(30px,5vw,60px) 0}.iob h1{font-family:Roboto,system-ui,sans-serif;font-weight:300;font-size:clamp(36px,4vw,52px);color:var(--io-marino)!important;margin:0 0 6px}
.iob-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:26px 0}.iob-tabs button{border:1px solid var(--io-borde);background:var(--io-superficie);color:var(--io-texto);padding:8px 18px;border-radius:999px;font-size:14px}
.iob-tabs button.on{background:var(--io-rojo);border-color:var(--io-rojo);color:#fff}.iob-tabs b{font-weight:500;opacity:.7;margin-left:6px}
.iob-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:18px}
.iob-card{border:1px solid var(--io-borde);background:var(--io-superficie);border-radius:18px;padding:22px;display:flex;flex-direction:column;gap:12px}
.iob-card h3{font-size:18px;font-weight:500;margin:0;color:var(--io-texto)!important;line-height:1.35}
.iob-meta{font-size:13px;color:var(--io-texto-suave)}.iob-cuenta{font-size:13px;color:var(--io-rojo);font-weight:500}
.iob-card .btn-io2{justify-content:center;padding:11px 20px;font-size:14px}.iob-vacio{padding:50px 20px;text-align:center;border:1px dashed var(--io-borde);border-radius:18px;color:var(--io-texto-suave)}
</style>
<section class="iob"><div class="container">
  <?php if ($__standalone) { require_once __DIR__ . '/../tienda/_migas.php'; echo migas([['texto' => 'Inicio', 'url' => ruta('inicio')], ['texto' => 'Mi biblioteca']]); } ?>
  <span class="eyebrow">Tu espacio de estudio</span>
  <h1>Mi biblioteca</h1>
  <?php if (!$__cliente): ?>
    <div class="iob-vacio mt-4">Inicia sesión para ver tu contenido. <a href="/?io_acceso=login&amp;io_destino=biblioteca">Iniciar sesión</a></div>
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
        $esMembresia = $a['origen'] === 'membresia';
        $badge = $a['tipo'] === 'unica' ? '<span class="io-badge-acceso unica">Visualización única</span>' : ($a['tipo'] === 'permanente' ? '<span class="io-badge-acceso permanente">Permanente</span>' : '<span class="io-badge-acceso">' . (int) $a['horas_ventana'] . ' h de acceso</span>');
        if ($esMembresia) { $badge = '<span class="io-badge-acceso">Membresía</span>' . ($a['tipo'] === 'unica' ? ' <span class="io-badge-acceso unica">Visualización única</span>' : ''); } ?>
      <article class="iob-card">
        <div><?= $badge ?></div>
        <h3><?= $__esc($a['titulo']) ?></h3>
        <?php if ($a['estado'] !== 'por_empezar' && $a['tipo'] !== 'permanente'): ?>
          <div class="io-progreso"><span style="width:<?= $pct ?>%"></span></div><div class="iob-meta"><?= $pct ?>% visto</div>
        <?php endif; ?>
        <div class="io-vigencia">
        <?php if ($esMembresia && in_array($a['estado'], ['por_empezar', 'activo'], true) && $a['vence']): ?>
          <span><b>Período pagado hasta:</b> <?= IO::htmlFecha($a['vence']) ?></span><?= IO::htmlRestante($a['vence']) ?>
          <span><?= $a['tipo'] === 'unica' ? 'Se reproduce una sola vez dentro del período pagado. Renovar no reinicia una visualización ya consumida.' : 'El acceso está limitado al período pagado de tu membresía.' ?></span>
        <?php elseif ($esMembresia && in_array($a['estado'], ['por_empezar', 'activo'], true)): ?>
          <span>Consulta la vigencia de tu membresía con el equipo.</span>
        <?php elseif ($a['estado'] === 'activo' && $a['vence']): ?>
          <span><b>Vence:</b> <?= IO::htmlFecha($a['vence']) ?></span><?= IO::htmlRestante($a['vence']) ?>
        <?php elseif ($a['estado'] === 'activo' && $a['tipo'] === 'unica'): ?>
          <span><b>Visualización única en curso.</b> Reanudaciones disponibles: <?= max(0, (int) $a['reanudaciones_max'] - (int) $a['reanudaciones']) ?></span>
        <?php elseif ($a['estado'] === 'activo'): ?>
          <span><b>Acceso permanente.</b> Sin fecha de vencimiento.</span>
        <?php elseif ($a['estado'] === 'por_empezar'): ?>
          <span><b>Empieza antes del</b> <?= IO::htmlFecha($a['limite_inicio']) ?></span>
          <span><?= $a['tipo'] === 'unica' ? 'Se reproduce una sola vez.' : 'Al dar play tendrás ' . (int) $a['horas_ventana'] . ' h para verlo.' ?></span>
        <?php elseif ($a['estado'] === 'en_gracia'): ?>
          <?php $hasta = $a['vence'] && $a['vence'] < $a['gracia_hasta'] ? $a['vence'] : $a['gracia_hasta']; ?>
          <span><b>Se interrumpió.</b> Continúa antes del <?= IO::htmlFecha($hasta) ?></span><?= IO::htmlRestante($hasta) ?>
        <?php elseif ($a['estado'] === 'visto'): ?>
          <span><b>Completado</b> el <?= IO::htmlFecha($a['visto_en']) ?></span>
        <?php elseif ($a['estado'] === 'vencido'): ?>
          <span><b>Venció</b> el <?= IO::htmlFecha($a['vence'] ?: $a['limite_inicio']) ?></span>
        <?php endif; ?>
        </div>
        <?php if (in_array($a['estado'], ['por_empezar', 'activo', 'en_gracia'], true)): ?>
          <a class="btn-io2 primario" href="/io/public/ver.php?acceso=<?= (int) $a['id'] ?>"><?= $a['estado'] === 'por_empezar' ? 'Empezar' : 'Continuar' ?></a>
        <?php else: ?>
          <a class="btn-io2" style="border:1px solid var(--io-borde);color:var(--io-texto)" href="<?= $esMembresia ? '/io/tienda/membresias.php' : '/conferencias-en-linea' ?>"><?= $esMembresia ? 'Consultar mi membresía' : 'Volver a adquirir' ?></a>
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
})();
</script>
<?php if ($__standalone) { pie(); } ?>
