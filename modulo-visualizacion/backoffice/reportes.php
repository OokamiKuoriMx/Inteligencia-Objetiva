<?php
declare(strict_types=1);
require __DIR__ . '/_inc.php';
exigir('reportes');

$desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'] ?? '') ? $_GET['desde'] : date('Y-m-d', strtotime('-30 days'));
$hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'] ?? '') ? $_GET['hasta'] : date('Y-m-d');

$filas = IO::todos("SELECT p.producto_id, p.titulo, p.tipo, p.duracion_seg,
    COUNT(a.id) AS accesos,
    SUM(a.estado <> 'por_empezar' AND a.inicio IS NOT NULL) AS empezados,
    SUM(a.estado = 'visto' OR (p.duracion_seg > 0 AND a.max_seg >= p.duracion_seg * 0.9)) AS completados,
    SUM(a.estado = 'vencido' AND a.inicio IS NULL) AS sin_usar,
    ROUND(SUM(a.seg_vistos) / 60) AS minutos,
    ROUND(AVG(CASE WHEN p.duracion_seg > 0 THEN LEAST(100, a.max_seg * 100 / p.duracion_seg) END)) AS avance_prom
  FROM io_politicas p LEFT JOIN io_accesos a ON a.producto_id = p.producto_id AND a.creado BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)
  GROUP BY p.producto_id ORDER BY accesos DESC", [$desde, $hasta]);

if (($_GET['formato'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte-visualizacion-' . $desde . '-a-' . $hasta . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM para Excel
    fputcsv($out, ['Producto', 'Título', 'Tipo', 'Accesos', 'Empezados', 'Completados', 'Sin usar (vencidos)', 'Minutos vistos', 'Avance promedio %']);
    foreach ($filas as $f) {
        // Evita inyección de fórmulas en Excel
        $t = preg_match('/^[=+\-@]/', (string) $f['titulo']) ? "'" . $f['titulo'] : $f['titulo'];
        fputcsv($out, [$f['producto_id'], $t, TIPOS[$f['tipo']] ?? $f['tipo'], (int) $f['accesos'], (int) $f['empezados'], (int) $f['completados'], (int) $f['sin_usar'], (int) $f['minutos'], (int) $f['avance_prom']]);
    }
    exit;
}

pagina('Reportes', function () use ($filas, $desde, $hasta) { ?>
  <form class="bo-filtros" method="get">
    <input class="form-control" type="date" name="desde" value="<?= h($desde) ?>">
    <input class="form-control" type="date" name="hasta" value="<?= h($hasta) ?>">
    <button class="bo-btn sec">Aplicar</button>
    <a class="bo-btn ms-auto" href="?desde=<?= h($desde) ?>&hasta=<?= h($hasta) ?>&formato=csv">Exportar CSV</a>
  </form>
  <div class="bo-card">
    <h2>Uso por contenido · accesos creados del <?= h(date('d/m/Y', strtotime($desde))) ?> al <?= h(date('d/m/Y', strtotime($hasta))) ?></h2>
    <div class="bo-scroll"><table class="bo-tabla"><thead><tr><th>Contenido</th><th>Accesos</th><th>Empezados</th><th>Completados</th><th>Sin usar</th><th>Minutos vistos</th><th>Avance promedio</th></tr></thead><tbody>
    <?php foreach ($filas as $f): $acc = max(1, (int) $f['accesos']); ?>
      <tr>
        <td><?= h($f['titulo']) ?><span class="sub"><?= chip_tipo($f['tipo']) ?></span></td>
        <td><?= (int) $f['accesos'] ?></td>
        <td><?= (int) $f['empezados'] ?><span class="sub"><?= $f['accesos'] ? round($f['empezados'] * 100 / $acc) : 0 ?>%</span></td>
        <td><?= (int) $f['completados'] ?><span class="sub"><?= $f['accesos'] ? round($f['completados'] * 100 / $acc) : 0 ?>%</span></td>
        <td><?= (int) $f['sin_usar'] ?></td>
        <td><?= number_format((int) $f['minutos']) ?></td>
        <td><span class="bo-barra"><span style="width:<?= (int) $f['avance_prom'] ?>%"></span></span><?= (int) $f['avance_prom'] ?>%</td>
      </tr>
    <?php endforeach; ?></tbody></table></div>
    <p class="bo-nota mt-3">"Sin usar" = accesos que vencieron sin una sola reproducción: candidatos a un correo de recuperación.</p>
  </div>
<?php }, 'reportes.php');
