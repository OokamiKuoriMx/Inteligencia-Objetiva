<?php
declare(strict_types=1);
require __DIR__ . '/_inc.php';
require_once __DIR__ . '/../lib/Descuentos.php';
$a = exigir('politicas');
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_ok();
    try {
        $id = IODescuentos::guardar($a, $_POST, (string) ($_POST['motivo'] ?? ''));
        flash('Cupón guardado. Los descuentos de pedidos ya creados conservan sus condiciones.');
        header('Location: descuentos.php'); exit;
    } catch (IOError $e) { $error = $e->getMessage(); }
    catch (Throwable $e) { error_log('[IO cupones] no se pudo guardar el cupón'); $error = 'No se pudo guardar. Comprueba que el código no esté repetido.'; }
}
$editando = isset($_GET['id']) ? IO::uno('SELECT * FROM io_cupones WHERE id = ?', [(int) $_GET['id']]) : null;
$d = $editando ?: ['id' => 0, 'codigo' => '', 'tipo' => 'porcentaje', 'valor' => '', 'inicio' => '', 'fin' => '', 'minimo_mxn' => 0,
    'max_usos' => '', 'max_por_cliente' => 1, 'productos' => '[]', 'incluir_rebajados' => 0, 'activo' => 1];
if ($error !== '') { $d = $_POST + $d; $d['activo'] = !empty($_POST['activo']); $d['incluir_rebajados'] = !empty($_POST['incluir_rebajados']); }
$productos = $error !== '' ? (string) ($_POST['productos'] ?? '') : implode(',', json_decode((string) $d['productos'], true) ?: []);
$abrir = $error !== '' || $editando || isset($_GET['nuevo']);
$catalogo = IO::todos('SELECT producto_id, titulo, precio_mxn FROM io_productos ORDER BY titulo');
$lista = IO::todos('SELECT c.*, (SELECT COUNT(*) FROM io_descuentos_pedidos r WHERE r.cupon_id=c.id AND r.estado="reservado") reservados,
    (SELECT COUNT(*) FROM io_descuentos_pedidos r WHERE r.cupon_id=c.id AND r.estado="consumido") consumidos FROM io_cupones c ORDER BY c.creado DESC, c.id DESC LIMIT 200');

pagina('Cupones y promociones 2×1', function () use ($d, $error, $productos, $lista, $abrir, $catalogo) {
    $fecha = static function ($v) { return $v ? date('Y-m-d\TH:i', strtotime((string) $v)) : ''; };
    $ahora = time();
    $vigencia = static function (array $c) use ($ahora): array {
        if (!$c['activo']) { return ['Pausado', 'neutro']; }
        if (strtotime((string) $c['inicio']) > $ahora) { return ['Programado', 'info']; }
        if (strtotime((string) $c['fin']) < $ahora) { return ['Vencido', 'apagado']; }
        return ['Vigente', 'ok'];
    };
    $regla = static fn(array $c) => $c['tipo'] === '2x1' ? '2×1' : ($c['tipo'] === 'porcentaje' ? rtrim(rtrim(number_format((float) $c['valor'], 2), '0'), '.') . ' % de descuento' : '$' . number_format((float) $c['valor'], 2) . ' MXN de descuento');
?>
<link rel="stylesheet" href="/io/backoffice/contenido-editor.css?v=2.1.1"><link rel="stylesheet" href="/io/backoffice/descuentos.css?v=1.0.1">
<section class="bo-card">
  <h2>Cupones <button type="button" class="bo-btn mini" id="nuevoCupon" data-nuevo="<?= (int) $d['id'] || $error !== '' ? '0' : '1' ?>">+ Nuevo cupón</button></h2>
  <p class="bo-nota">Los descuentos se validan en el servidor. Una reserva cuenta contra el cupo hasta pagarse o cancelarse definitivamente el pedido en la pasarela. No se publica ninguna promoción automáticamente.</p>
  <?php if (!$lista): ?><div class="bo-vacio">Todavía no hay cupones. Crea el primero con “Nuevo cupón”.</div><?php else: ?>
  <div class="bo-scroll"><table class="bo-tabla">
    <thead><tr><th>Código</th><th>Descuento</th><th>Vigencia</th><th>Usos</th><th>Estado</th><th class="tx-col-acciones"><span class="visually-hidden">Acciones</span></th></tr></thead><tbody>
    <?php foreach ($lista as $c): [$et, $cl] = $vigencia($c); ?>
      <tr>
        <td><code class="cup-codigo"><?= h($c['codigo']) ?></code></td>
        <td><?= h($regla($c)) ?><?php if ((float) $c['minimo_mxn'] > 0): ?><span class="sub">Compra mínima $<?= number_format((float) $c['minimo_mxn'], 2) ?></span><?php endif; ?></td>
        <td><?= h(fecha($c['inicio'])) ?><span class="sub">hasta <?= h(fecha($c['fin'])) ?></span></td>
        <td><?= (int) $c['consumidos'] ?><?= $c['max_usos'] ? ' de ' . (int) $c['max_usos'] : '' ?><span class="sub"><?= (int) $c['reservados'] ?> reservados</span></td>
        <td><span class="bo-chip <?= $cl ?>"><?= h($et) ?></span></td>
        <td class="tx-col-acciones"><a class="bo-btn sec mini" href="descuentos.php?id=<?= (int) $c['id'] ?>">Editar</a></td>
      </tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</section>

<dialog id="cuponEditor" class="bo-editor cup-editor" aria-labelledby="cuponTitulo" data-abrir="<?= $abrir ? '1' : '0' ?>">
<form method="post" id="cuponForm" novalidate>
  <?= csrf_input() ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
  <div class="bo-editor-cabecera">
    <div><p class="bo-contexto">Inteligencia Objetiva · Cupones</p><h2 id="cuponTitulo"><?= (int) $d['id'] ? 'Editar cupón' : 'Nuevo cupón' ?></h2></div>
    <button type="submit" class="bo-btn">Guardar cupón</button>
    <a class="bo-editor-cerrar" href="descuentos.php" data-cerrar-cupon aria-label="Cerrar sin guardar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></a>
  </div>
  <?php if ($error !== ''): ?><div class="bo-editor-error" id="cuponError" role="alert" tabindex="-1"><?= h($error) ?></div><?php endif; ?>
  <div class="bo-editor-scroll">
    <div class="bo-editor-campos">
      <div class="st-campo"><label for="cup-codigo">Código (obligatorio)</label><input id="cup-codigo" name="codigo" maxlength="40" pattern="[A-Za-z0-9][A-Za-z0-9_\-]{2,39}" required value="<?= h($d['codigo']) ?>" placeholder="Ej. BIENVENIDA20" autocomplete="off" spellcheck="false" class="cup-mayus"></div>
      <p class="st-estado cup-ayuda">Letras, números, guion o guion bajo; de 3 a 40 caracteres. El alumno lo escribe en el carrito.</p>

      <div class="st-bloque">
        <h3>Tipo de descuento</h3>
        <div class="cup-tipos" role="radiogroup" aria-label="Tipo de descuento">
          <?php foreach (['porcentaje' => ['%', 'Porcentaje', 'Un % menos sobre el total.'], 'importe' => ['$', 'Importe fijo', 'Pesos menos, con IVA.'], '2x1' => ['2×1', '2×1', 'Gratis el más barato de cada pareja.']] as $k => [$ic, $t, $desc]): ?>
            <label class="st-opcion cup-tipo"><input type="radio" name="tipo" value="<?= $k ?>"<?= $d['tipo'] === $k ? ' checked' : '' ?>><span class="cup-icono" aria-hidden="true"><?= h($ic) ?></span><span><b><?= h($t) ?></b><small><?= h($desc) ?></small></span></label>
          <?php endforeach; ?>
        </div>
        <div class="st-precio cup-valor" id="cupValorCaja"><span id="cupPrefijo" aria-hidden="true">$</span><input id="cup-valor" name="valor" type="number" min="0" step="0.01" inputmode="decimal" value="<?= h($d['valor']) ?>" aria-label="Valor del descuento"><span id="cupSufijo" aria-hidden="true">%</span></div>
      </div>

      <div class="st-bloque">
        <h3>Vigencia</h3>
        <p class="bo-nota">Hora de Ciudad de México.</p>
        <div class="bo-editor-dos">
          <div class="st-campo"><label for="cup-inicio">Empieza</label><input id="cup-inicio" name="inicio" type="datetime-local" required value="<?= h($fecha($d['inicio'])) ?>"></div>
          <div class="st-campo"><label for="cup-fin">Termina</label><input id="cup-fin" name="fin" type="datetime-local" required value="<?= h($fecha($d['fin'])) ?>"></div>
        </div>
        <div class="cup-atajos"><span class="bo-nota">Duración:</span>
          <?php foreach ([1 => '1 día', 3 => '3 días', 7 => '1 semana', 30 => '1 mes'] as $dias => $t): ?><button type="button" class="st-chip-btn" data-dias="<?= $dias ?>"><?= $t ?></button><?php endforeach; ?>
        </div>
      </div>

      <div class="st-bloque">
        <h3>¿En qué contenidos aplica?</h3>
        <div class="st-chips" role="radiogroup" aria-label="Alcance del cupón">
          <label class="st-chip"><input type="radio" name="alcance" value="todos"<?= $productos === '' ? ' checked' : '' ?>><span>Todo el catálogo</span></label>
          <label class="st-chip"><input type="radio" name="alcance" value="algunos"<?= $productos !== '' ? ' checked' : '' ?>><span>Solo algunos contenidos</span></label>
        </div>
        <div class="cup-productos" id="cupProductos"<?= $productos === '' ? ' hidden' : '' ?>>
          <div class="cup-elegidos" id="cupElegidos" aria-live="polite"></div>
          <div class="st-campo"><label for="cupBuscar">Buscar contenido</label><input id="cupBuscar" type="search" placeholder="Escribe parte del título" autocomplete="off"></div>
          <ul class="cup-resultados" id="cupResultados" role="listbox" aria-label="Contenidos"></ul>
        </div>
        <input type="hidden" name="productos" id="cup-productos" value="<?= h($productos) ?>">
      </div>

      <details class="st-avanzado st-mas"<?= ((float) $d['minimo_mxn'] > 0 || $d['max_usos'] !== '' && $d['max_usos'] !== null || (int) $d['max_por_cliente'] !== 1) ? ' open' : '' ?>><summary>Límites</summary>
        <div class="bo-editor-dos">
          <div class="st-campo"><label for="cup-min">Compra mínima (MXN con IVA)</label><input id="cup-min" name="minimo_mxn" type="number" min="0" step="0.01" value="<?= h($d['minimo_mxn']) ?>"></div>
          <div class="st-campo"><label for="cup-max">Usos en total (vacío = sin límite)</label><input id="cup-max" name="max_usos" type="number" min="1" value="<?= h($d['max_usos']) ?>"></div>
          <div class="st-campo"><label for="cup-cli">Usos por alumno</label><input id="cup-cli" name="max_por_cliente" type="number" min="1" required value="<?= h($d['max_por_cliente']) ?>"></div>
        </div>
      </details>
    </div>

    <aside class="st-lateral" aria-label="Resumen del cupón">
      <div class="st-visibilidad cup-resumen">
        <span class="cup-ticket" id="cupTicket"><?= h($d['codigo'] ?: 'CÓDIGO') ?></span>
        <p class="cup-frase" id="cupFrase"></p>
        <ul class="cup-detalles" id="cupDetalles"></ul>
      </div>
      <div class="st-visibilidad">
        <h3>Estado</h3>
        <label class="st-interruptor"><input type="checkbox" name="activo" value="1"<?= !empty($d['activo']) ? ' checked' : '' ?>><span class="st-riel" aria-hidden="true"></span><span><b>Activo</b><small>Se puede usar dentro de su vigencia.</small></span></label>
        <label class="st-interruptor"><input type="checkbox" name="incluir_rebajados" value="1"<?= !empty($d['incluir_rebajados']) ? ' checked' : '' ?>><span class="st-riel" aria-hidden="true"></span><span><b>Combinar con ofertas</b><small>También descuenta en contenidos que ya tienen precio rebajado.</small></span></label>
      </div>
    </aside>
  </div>
  <div class="bo-editor-descartar">
    <div class="st-campo st-motivo"><label for="cup-motivo">Motivo del cambio (queda en la bitácora)</label><input id="cup-motivo" name="motivo" maxlength="120" required value="<?= h($error !== '' ? (string) ($_POST['motivo'] ?? '') : ((int) $d['id'] ? 'Ajuste del cupón' : 'Nueva promoción')) ?>"></div>
    <a class="bo-btn sec" href="descuentos.php" data-cerrar-cupon>Cancelar</a><button type="submit" class="bo-btn">Guardar cupón</button>
  </div>
</form>
</dialog>
<script type="application/json" id="cuponCatalogo"><?= json_encode(array_map(static fn($p) => ['id' => (int) $p['producto_id'], 't' => (string) $p['titulo'], 'p' => (float) $p['precio_mxn']], $catalogo), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="/io/backoffice/descuentos.js?v=1.0.1" defer></script>
<?php }, 'descuentos.php');
