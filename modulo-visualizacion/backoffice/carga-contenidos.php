<?php
declare(strict_types=1);
require __DIR__ . '/_inc.php';
require_once __DIR__ . '/../lib/CargaContenidos.php';
$actor = exigir('politicas');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['descargar'] ?? '') === 'plantilla') {
    $separador = ($_GET['separador'] ?? '') === 'coma' ? ',' : ';';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="plantilla-contenidos.csv"');
    echo IOCargaContenidos::plantilla($separador); exit;
}
$error = ''; $lote = null;
if (isset($_SESSION['io_carga_contenidos'])) {
    $pendiente = $_SESSION['io_carga_contenidos'];
    if (is_array($pendiente) && (int) ($pendiente['lote']['actor_id'] ?? 0) === (int) $actor['id'] &&
        (int) ($pendiente['lote']['creado_en'] ?? 0) >= time() - IOCargaContenidos::VIGENCIA_SEGUNDOS) {
        $lote = $pendiente['lote'];
    } else { unset($_SESSION['io_carga_contenidos']); }
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_ok();
    try {
        $accion = (string) ($_POST['accion'] ?? '');
        if ($accion === 'previsualizar') {
            unset($_SESSION['io_carga_contenidos']); $lote = null;
            $archivo = $_FILES['csv'] ?? null;
            if (!$archivo || !is_array($archivo) || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new IOError('csv_archivo', 'Selecciona un CSV válido de hasta 2 MB. Si supera el límite de carga del servidor, divídelo en archivos más pequeños.');
            }
            if (!is_string($archivo['tmp_name'] ?? null) || !is_uploaded_file($archivo['tmp_name']) ||
                !is_string($archivo['name'] ?? null) || strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION)) !== 'csv') {
                throw new IOError('csv_archivo', 'Selecciona el archivo CSV de contenidos; no se admiten archivos XLSX.');
            }
            if ((int) ($archivo['size'] ?? 0) > IOCargaContenidos::MAX_BYTES) { throw new IOError('csv_tamano', 'El CSV debe pesar como máximo 2 MB.'); }
            $csv = file_get_contents($archivo['tmp_name'], false, null, 0, IOCargaContenidos::MAX_BYTES + 1);
            if ($csv === false) { throw new IOError('csv_archivo', 'No se pudo leer el archivo. Vuelve a seleccionarlo.'); }
            $lote = IOCargaContenidos::previsualizar($actor, $csv);
            if (!$lote['errores']) { $_SESSION['io_carga_contenidos'] = ['token' => bin2hex(random_bytes(24)), 'lote' => $lote]; }
        } elseif ($accion === 'confirmar') {
            $pendiente = $_SESSION['io_carga_contenidos'] ?? null; $token = $_POST['lote_token'] ?? '';
            if (!$pendiente || !is_string($token) || !hash_equals((string) $pendiente['token'], $token)) {
                throw new IOError('csv_caducado', 'La vista previa ya no está disponible. Vuelve a cargar el archivo.', 409);
            }
            if (($_POST['confirmacion'] ?? '') !== '1' || !is_string($_POST['motivo'] ?? null)) {
                throw new IOError('confirmacion', 'Confirma que revisaste los contenidos e indica un motivo.');
            }
            // Las filas proceden exclusivamente de sesión. No se aceptan datos o importes reenviados por el navegador.
            $ids = IOCargaContenidos::confirmar($actor, $pendiente['lote'], $_POST['motivo']);
            unset($_SESSION['io_carga_contenidos']);
            flash(count($ids) === 1 ? 'Se creó 1 contenido como borrador. Revísalo en el editor antes de publicarlo.' : 'Se crearon ' . count($ids) . ' contenidos como borradores. Revísalos en el editor antes de publicarlos.');
            header('Location: reglas.php'); exit;
        } elseif ($accion === 'descartar') {
            unset($_SESSION['io_carga_contenidos']); $lote = null;
        } else { throw new IOError('accion', 'Acción no válida.'); }
    } catch (IOError $e) { $error = $e->getMessage(); http_response_code($e->getCode() >= 400 ? $e->getCode() : 400); }
    catch (PDOException $e) {
        error_log('[IO carga CSV] No se pudo confirmar la carga.');
        $error = 'No se guardó ningún contenido. Algún ID pudo haberse creado mientras revisabas la vista previa. Vuelve a cargar el archivo para comprobarlo.';
        http_response_code(409);
    } catch (Throwable $e) {
        error_log('[IO carga CSV] Error al procesar la carga.');
        $error = 'No se pudo completar la carga. No se guardó ningún cambio parcial. Vuelve a intentarlo.'; http_response_code(500);
    }
}
pagina('Carga masiva de contenidos', function () use ($lote, $error) {
?>
<section class="bo-card">
  <h2>Plantilla de contenidos</h2>
  <p>Prepara varias fichas en Excel o en otra hoja de cálculo y revisa los datos antes de guardarlos. La carga crea únicamente <b>contenidos nuevos como borradores</b>; no reemplaza contenidos existentes ni los publica.</p>
  <div class="bo-acciones"><a class="bo-btn" href="?descargar=plantilla">Descargar plantilla CSV para Excel</a><a class="bo-btn sec" href="?descargar=plantilla&amp;separador=coma">Plantilla separada por comas</a><a class="bo-btn sec" href="reglas.php">Volver al catálogo</a></div>
  <p class="bo-nota mt-3">La plantilla incluye solo encabezados, sin ejemplos que puedan importarse por accidente. Se descarga como UTF-8 con BOM.</p>
  <ol>
    <li>Completa <code>producto_id</code> con un ID nuevo y único del sitio, y <code>titulo</code> con el nombre del contenido.</li>
    <li>Completa las columnas adicionales que tengas aprobadas. Las celdas opcionales vacías conservan los valores de un borrador nuevo: acceso por ventana de 48 h, 30 días para empezar y precio pendiente de $0.00.</li>
    <li>Guarda como <b>CSV UTF-8</b>, separado por comas o punto y coma. Usa punto decimal y sin separador de miles en precios: <code>579.00</code>. Formatea IDs de Vimeo como texto para que Excel no los convierta a notación científica.</li>
    <li>Carga el archivo, corrige todas las incidencias y confirma la vista previa. Después abre cada ficha en el editor para revisar y publicar.</li>
  </ol>
  <details class="mb-3"><summary class="bo-btn sec">Consultar columnas y valores permitidos</summary><div class="table-responsive mt-3"><table class="bo-tabla"><thead><tr><th>Columnas</th><th>Qué escribir</th></tr></thead><tbody>
    <tr><td><code>producto_id, titulo</code></td><td>Obligatorias. ID numérico positivo del sitio y título de hasta 255 caracteres. Un ID existente se rechaza.</td></tr>
    <tr><td><code>categoria</code></td><td><?= h(implode(', ', array_keys(IOTienda::CATEGORIAS))) ?>.</td></tr>
    <tr><td><code>tema</code></td><td><?= h(implode(', ', array_keys(IOTienda::TEMAS))) ?>, o vacío.</td></tr>
    <tr><td><code>resumen, descripcion, aprenderas, incluye</code></td><td>Texto plano, sin HTML ni fórmulas. Resumen de hasta 400 caracteres; una idea por línea en aprenderas. Excel conserva los saltos dentro de una celda al guardarlos entre comillas.</td></tr>
    <tr><td><code>imagen</code></td><td>Ruta local como /portada/img/conferencias.webp o URL HTTPS de una imagen existente. No se suben archivos de imagen en esta carga.</td></tr>
    <tr><td><code>precio_mxn, precio_anterior</code></td><td>MXN con IVA, hasta dos decimales. Precio anterior opcional. El precio de venta puede quedar vacío mientras sea borrador.</td></tr>
    <tr><td><code>vimeo_id, vimeo_hash, trailer_vimeo, duracion_seg</code></td><td>ID numérico de Vimeo, hash alfanumérico opcional, ID numérico de la muestra y duración entera en segundos. No pegues URLs de video.</td></tr>
    <tr><td><code>tipo, cuenta_desde</code></td><td>Tipo: ventana, permanente o unica. Cuenta desde: compra o primera_reproduccion. Un contenido unica necesita duración mayor que cero.</td></tr>
    <tr><td><code>horas_ventana, dias_para_empezar, umbral_pct, retroceso_seg, pausa_max_min, reanudaciones_max, gracia_min</code></td><td>Valores enteros de las reglas de acceso del editor. Umbral entre 50 y 100; reanudaciones entre 0 y 5. Sin valor se usan los mismos valores iniciales del editor.</td></tr>
    <tr><td><code>permitir_adelantar, destacado, activo</code></td><td>Usa 0 o 1 para adelanto/destacado. Activo debe quedar en 0 o vacío: se publicará más tarde desde el editor.</td></tr>
  </tbody></table></div></details>
  <p>Máximo <?= IOCargaContenidos::MAX_FILAS ?> contenidos y 2 MB por archivo. No se admiten fórmulas ni celdas que comiencen con =, +, - o @. La vista previa vence a los 30 minutos.</p>
</section>
<?php if ($error !== ''): ?><div class="bo-flash error" role="alert"><?= h($error) ?></div><?php endif; ?>
<section class="bo-card"><h2>Cargar CSV y revisar</h2><form method="post" enctype="multipart/form-data">
  <?= csrf_input() ?><input type="hidden" name="accion" value="previsualizar"><input type="hidden" name="MAX_FILE_SIZE" value="<?= IOCargaContenidos::MAX_BYTES ?>">
  <label class="form-label" for="csv-contenidos">Archivo CSV UTF-8</label><input class="form-control mb-3" id="csv-contenidos" name="csv" type="file" accept=".csv,text/csv" required>
  <button class="bo-btn">Generar vista previa</button>
</form></section>
<?php if ($lote): ?>
<section class="bo-card"><h2>Vista previa: <?= (int) $lote['total'] ?> <?= (int) $lote['total'] === 1 ? 'contenido' : 'contenidos' ?></h2>
  <?php if ($lote['errores']): ?><div class="bo-flash error" role="alert"><b>No se puede confirmar la carga todavía.</b><ul><?php foreach ($lote['errores'] as $e): ?><li>Fila <?= (int) $e['fila'] ?>: <?= h($e['mensaje']) ?></li><?php endforeach; ?></ul>Corrige el archivo y vuelve a cargarlo. No se ha guardado ninguna fila.</div><?php endif; ?>
  <?php if ($lote['filas']): ?><div class="table-responsive"><table class="bo-tabla"><thead><tr><th>Fila</th><th>Contenido</th><th>Categoría</th><th>Precio MXN</th><th>Video</th><th>Acceso</th><th>Estado</th><th>Revisión completa</th></tr></thead><tbody>
  <?php foreach ($lote['filas'] as $fila): $d = $fila['datos']; ?>
    <tr><td><?= (int) $fila['fila'] ?></td><td><?= h($d['titulo']) ?><span class="sub">Producto #<?= (int) $d['producto_id'] ?></span></td><td><?= h(IOTienda::CATEGORIAS[$d['categoria']]) ?></td><td><?= h(IOTienda::dinero((float) $d['precio_mxn'])) ?></td><td><?= h($d['vimeo_id'] ?: 'Pendiente') ?></td><td><?= chip_tipo($d['tipo']) ?></td><td>Borrador<?php if ($fila['avisos']): ?><span class="sub"><?= h(implode(', ', $fila['avisos'])) ?></span><?php endif; ?></td>
    <td><details><summary class="bo-btn sec mini">Revisar ficha y reglas</summary>
      <?php $detalle = [
          'ID del contenido' => $d['producto_id'], 'Título' => $d['titulo'], 'Categoría' => IOTienda::CATEGORIAS[$d['categoria']],
          'Tema' => IOTienda::TEMAS[$d['tema']] ?? 'Sin tema seleccionado', 'Resumen' => $d['resumen'] ?: 'Pendiente',
          'Descripción' => $d['descripcion'] ?: 'Pendiente', 'Lo que vas a comprender' => $d['aprenderas'] ?: 'Pendiente',
          'Dirección de la imagen' => $d['imagen'] ?: 'Pendiente', 'Precio de venta con IVA' => IOTienda::dinero((float) $d['precio_mxn']) . ' MXN',
          'Precio anterior con IVA' => $d['precio_anterior'] === null ? 'Sin precio anterior' : IOTienda::dinero((float) $d['precio_anterior']) . ' MXN',
          'Material incluido' => $d['incluye'] ?: 'Sin material adicional indicado', 'Video principal de Vimeo' => $d['vimeo_id'] ?: 'Pendiente',
          'Hash de video no listado' => $d['vimeo_hash'] ?: 'Sin hash', 'Video de muestra de Vimeo' => $d['trailer_vimeo'] ?: 'Sin muestra',
          'Duración del video' => (int) $d['duracion_seg'] . ' segundos', 'Tipo de acceso' => TIPOS[$d['tipo']],
          'Duración de la ventana de acceso' => (int) $d['horas_ventana'] . ' horas; se aplica al acceso por tiempo',
          'La ventana comienza desde' => $d['cuenta_desde'] === 'compra' ? 'La confirmación de la compra' : 'La primera reproducción',
          'Plazo para empezar' => (int) $d['dias_para_empezar'] . ' días', 'Dar por completado al' => (int) $d['umbral_pct'] . '% del video',
          'Retroceso permitido' => (int) $d['retroceso_seg'] === 999999 ? 'Libre' : (int) $d['retroceso_seg'] . ' segundos',
          'Permitir adelantar' => $d['permitir_adelantar'] ? 'Sí' : 'No', 'Pausa máxima' => (int) $d['pausa_max_min'] . ' minutos',
          'Reanudaciones permitidas' => (int) $d['reanudaciones_max'], 'Gracia tras una interrupción' => (int) $d['gracia_min'] . ' minutos',
          'Marcar como destacado' => $d['destacado'] ? 'Sí, cuando se publique' : 'No', 'Publicación' => 'Borrador oculto al público',
      ]; ?>
      <dl class="mt-3 text-wrap text-break" style="min-width:18rem;max-width:38rem"><?php foreach ($detalle as $etiqueta => $valor): ?><dt><?= h($etiqueta) ?></dt><dd><?= nl2br(h((string) $valor)) ?></dd><?php endforeach; ?></dl>
    </details></td></tr>
  <?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
  <?php if (!$lote['errores'] && isset($_SESSION['io_carga_contenidos'])): ?>
    <p class="mt-3"><?= count($lote['filas']) === 1 ? 'Se guardará exactamente este contenido y sus reglas en una sola operación. Permanecerá oculto al público hasta que lo revises y publiques desde el editor.' : 'Se guardarán exactamente estos contenidos y sus reglas en una sola operación. Permanecerán ocultos al público hasta que los revises y publiques desde el editor.' ?></p>
    <form method="post" class="mt-3">
      <?= csrf_input() ?><input type="hidden" name="accion" value="confirmar"><input type="hidden" name="lote_token" value="<?= h($_SESSION['io_carga_contenidos']['token']) ?>">
      <label class="form-label" for="motivo-carga">Motivo de la carga (queda en bitácora)</label><input class="form-control mb-3" name="motivo" id="motivo-carga" maxlength="120" required>
      <label class="d-flex align-items-center gap-2 mb-3"><input type="checkbox" name="confirmacion" value="1" required> <?= count($lote['filas']) === 1 ? 'Revisé la vista previa y quiero crear este nuevo borrador.' : 'Revisé la vista previa y quiero crear estos nuevos borradores.' ?></label>
      <button class="bo-btn">Confirmar y crear <?= count($lote['filas']) ?> <?= count($lote['filas']) === 1 ? 'borrador' : 'borradores' ?></button>
    </form>
  <?php endif; ?>
  <form method="post" class="mt-3"><?= csrf_input() ?><input type="hidden" name="accion" value="descartar"><button class="bo-btn sec">Descartar vista previa</button></form>
</section>
<?php endif; ?>
<?php }, 'reglas.php');
