<?php
declare(strict_types=1);
require __DIR__ . '/_layout.php';
require_once __DIR__ . '/_migas.php';

$f = [
    'categoria' => (string) ($_GET['categoria'] ?? ''),
    'tema' => (string) ($_GET['tema'] ?? ''),
    'acceso' => (string) ($_GET['acceso'] ?? ''),
    'q' => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80),
    'orden' => (string) ($_GET['orden'] ?? 'populares'),
];
$r = IOTienda::catalogo($f, (int) ($_GET['p'] ?? 1), 12);
$qs = fn(array $cambios) => '?' . http_build_query(array_filter(array_merge($f, ['p' => null], $cambios), fn($v) => $v !== '' && $v !== null));
$titulo = $f['categoria'] && isset(IOTienda::CATEGORIAS[$f['categoria']]) ? IOTienda::CATEGORIAS[$f['categoria']] : 'Catálogo';

encabezado($titulo, 'catalogo', 'Conferencias, cursos, documentales e introspecciones de Inteligencia Objetiva para tu desarrollo personal.');
?>
<section class="container">
  <?= migas(array_merge(
      [['texto' => 'Inicio', 'url' => ruta('inicio')], ['texto' => 'Catálogo', 'url' => ruta('catalogo')]],
      $titulo !== 'Catálogo' ? [['texto' => $titulo]] : []
  )) ?>
  <div class="iot-cabecera">
    <h1><?= e($titulo) ?></h1>
    <p>Más de 250 temas para mirar hacia dentro y ordenar tu vida: pareja, economía, educación de los hijos, trabajo y desarrollo personal.</p>
  </div>

  <form class="iot-filtros" method="get" role="search" aria-label="Buscar y filtrar el catálogo">
    <nav class="iot-categorias" aria-label="Categorías del catálogo">
      <a class="iot-chip<?= $f['categoria'] === '' ? ' on' : '' ?>" href="<?= e($qs(['categoria' => ''])) ?>"<?= $f['categoria'] === '' ? ' aria-current="page"' : '' ?>>Todo</a>
      <?php foreach (['conferencias' => 'Conferencias', 'cursos' => 'Cursos', 'documentales' => 'Documentales e introspecciones', 'audiolibro' => 'Audiolibro'] as $k => $t): ?>
        <a class="iot-chip<?= $f['categoria'] === $k ? ' on' : '' ?>" href="<?= e($qs(['categoria' => $k])) ?>"<?= $f['categoria'] === $k ? ' aria-current="page"' : '' ?>><?= e($t) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="iot-controles">
      <?php if ($f['categoria']): ?><input type="hidden" name="categoria" value="<?= e($f['categoria']) ?>"><?php endif; ?>
      <div class="iot-campo iot-campo-busqueda">
        <label for="catalogo-buscar">Buscar en el catálogo</label>
        <div class="iot-buscador">
          <input id="catalogo-buscar" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Escribe un tema o una palabra" autocomplete="off">
          <button type="submit" aria-label="Buscar en el catálogo" title="Buscar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg></button>
        </div>
      </div>
      <div class="iot-campo">
      <label for="catalogo-tema">Tema</label>
      <select id="catalogo-tema" name="tema" onchange="this.form.submit()">
        <option value="">Todos los temas</option>
        <?php foreach (IOTienda::TEMAS as $k => $t): ?><option value="<?= e($k) ?>"<?= $f['tema'] === $k ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
      </select>
      </div>
      <div class="iot-campo">
      <label for="catalogo-acceso">Tipo de acceso</label>
      <select id="catalogo-acceso" name="acceso" onchange="this.form.submit()">
        <option value="">Cualquier acceso</option>
        <option value="ventana"<?= $f['acceso'] === 'ventana' ? ' selected' : '' ?>>Por tiempo</option>
        <option value="permanente"<?= $f['acceso'] === 'permanente' ? ' selected' : '' ?>>Permanente</option>
        <option value="unica"<?= $f['acceso'] === 'unica' ? ' selected' : '' ?>>Visualización única</option>
      </select>
      </div>
      <div class="iot-campo">
      <label for="catalogo-orden">Ordenar por</label>
      <select id="catalogo-orden" name="orden" onchange="this.form.submit()">
        <?php foreach (['populares' => 'Más vendidos', 'valorados' => 'Mejor valorados', 'nuevos' => 'Nuevos', 'precio_asc' => 'Precio: menor a mayor', 'precio_desc' => 'Precio: mayor a menor', 'az' => 'A–Z'] as $k => $t): ?>
          <option value="<?= e($k) ?>"<?= $f['orden'] === $k ? ' selected' : '' ?>><?= e($t) ?></option>
        <?php endforeach; ?>
      </select>
      </div>
    </div>
  </form>

  <?php if (!$r['productos']): ?>
    <div class="iot-vacio">No encontramos temas con esos filtros. <a href="<?= e(ruta('catalogo')) ?>">Ver todo el catálogo</a></div>
  <?php else: ?>
  <p class="text-muted small mb-3"><?= (int) $r['total'] ?> <?= (int) $r['total'] === 1 ? 'resultado' : 'resultados' ?></p>
  <div class="iot-grid">
    <?php foreach ($r['productos'] as $p): ?>
    <article class="iot-card">
      <div class="img"><img src="<?= e($p['imagen'] ?: '/portada/img/conferencias-800.webp') ?>" alt="" loading="lazy" decoding="async"><?= insignia($p) ?></div>
      <div class="cuerpo">
        <span class="cat"><?= e(IOTienda::CATEGORIAS[$p['categoria']] ?? '') ?><?= $p['tema'] && isset(IOTienda::TEMAS[$p['tema']]) ? ' · ' . e(IOTienda::TEMAS[$p['tema']]) : '' ?></span>
        <h3><a href="<?= e(rproducto($p)) ?>"><?= e($p['titulo']) ?></a></h3>
        <?= estrellas($p) ?>
        <p><?= e($p['resumen']) ?></p>
        <div class="pie"><div><?= precio($p) ?></div><?= form_agregar($p, 'Agregar', 'iot-btn sec', $_SERVER['REQUEST_URI'] ?? ruta('catalogo')) ?></div>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php if ($r['paginas'] > 1): ?>
  <nav class="iot-paginas" aria-label="Páginas">
    <?php if ($r['pagina'] > 1): ?><a class="iot-chip" href="<?= e($qs(['p' => $r['pagina'] - 1])) ?>">Anterior</a><?php endif; ?>
    <?php for ($i = 1; $i <= $r['paginas']; $i++): ?><a class="iot-chip<?= $i === $r['pagina'] ? ' on' : '' ?>" href="<?= e($qs(['p' => $i])) ?>"><?= $i ?></a><?php endfor; ?>
    <?php if ($r['pagina'] < $r['paginas']): ?><a class="iot-chip" href="<?= e($qs(['p' => $r['pagina'] + 1])) ?>">Siguiente</a><?php endif; ?>
  </nav>
  <?php else: ?><div class="mb-5 pb-4"></div><?php endif; ?>
  <?php endif; ?>
</section>
<?php pie();
