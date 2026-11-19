<?php
/** Mi perfil: cualquier miembro activo cambia su nombre y su contraseña. */
declare(strict_types=1);
require __DIR__ . '/_inc.php';
$a = exigir('ver');

$error = '';
$abrir = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_ok();
    $accion = (string) ($_POST['accion'] ?? '');
    $motivo = (string) ($_POST['motivo'] ?? '');
    try {
        if ($accion === 'nombre') {
            $abrir = 'nombre';
            IOEquipo::cambiarNombrePropio($a, (string) ($_POST['nombre'] ?? ''), $motivo);
            flash('Nombre actualizado.');
        } elseif ($accion === 'clave') {
            $abrir = 'clave';
            $nueva = (string) ($_POST['nueva'] ?? '');
            if (!hash_equals($nueva, (string) ($_POST['nueva2'] ?? ''))) { throw new IOError('clave', 'Las contraseñas nuevas no coinciden.'); }
            $v = IOEquipo::cambiarClavePropia($a, (string) ($_POST['actual'] ?? ''), $nueva, $motivo);
            // Esta sesión sigue abierta con la versión nueva; las demás quedan cerradas.
            session_regenerate_id(true);
            $_SESSION['io_admin']['v'] = $v;
            flash('Contraseña cambiada. Cerramos tus sesiones en otros dispositivos.');
        } else {
            throw new IOError('accion', 'Acción no válida.');
        }
        header('Location: perfil.php'); exit;
    } catch (IOError $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('[IO perfil] no se completó la acción: ' . get_class($e));
        $error = 'No se pudo guardar. Intenta de nuevo.';
    }
}

pagina('Mi perfil', function () use ($a, $error, $abrir) {
    $catalogo = IOEquipo::PERMISOS;
    $cerrar = '<button type="button" class="bo-editor-cerrar" data-cerrar aria-label="Cerrar sin guardar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button>';
    $errorHtml = $error !== '' ? '<div class="bo-editor-error" role="alert" tabindex="-1" data-error>' . h($error) . '</div>' : '';
    $motivos = static function (string $id, string $preferido) { ?>
      <div class="st-campo st-motivo"><label for="<?= $id ?>">Motivo del cambio (queda en la bitácora)</label>
        <select id="<?= $id ?>" name="motivo" required><option value="">Elige un motivo</option><?php foreach (IOEquipo::MOTIVOS as $m): ?><option<?= $m === $preferido ? ' selected' : '' ?>><?= h($m) ?></option><?php endforeach; ?></select></div>
    <?php };
?>
<link rel="stylesheet" href="/io/backoffice/contenido-editor.css?v=2.1.1"><link rel="stylesheet" href="/io/backoffice/equipo.css?v=1.0.1">
<div class="bo-grid2">
<section class="bo-card">
  <h2>Tus datos</h2>
  <dl class="eq-perfil">
    <div><dt>Nombre</dt><dd><?= h($a['nombre']) ?></dd></div>
    <div><dt>Correo</dt><dd><?= h($a['email']) ?></dd></div>
    <div><dt>Rol</dt><dd><?= h($a['rol_nombre'] ?? '—') ?></dd></div>
    <div><dt>Último inicio de sesión</dt><dd><?= h(fecha($a['ultimo_acceso'] ?? null)) ?></dd></div>
  </dl>
  <div class="eq-acciones-perfil">
    <button type="button" class="bo-btn" data-abrir="dlgNombre" aria-haspopup="dialog">Cambiar nombre</button>
    <button type="button" class="bo-btn sec" data-abrir="dlgClavePropia" aria-haspopup="dialog">Cambiar contraseña</button>
  </div>
</section>
<section class="bo-card">
  <h2>Lo que puedes hacer</h2>
  <p class="bo-nota">Depende de tu rol<?= puede('equipo') ? '' : '; si necesitas algo más, pídelo a dirección' ?>.</p>
  <ul class="eq-lista-permisos">
    <?php foreach ($catalogo as $clave => [$titulo, $explica]): if (!puede($clave)) continue; ?>
      <li><span class="eq-si" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></span><span><b><?= h($titulo) ?></b><span class="bo-nota"><?= h($explica) ?></span></span></li>
    <?php endforeach; ?>
  </ul>
</section>
</div>

<dialog id="dlgNombre" class="bo-editor eq-editor eq-chico" aria-labelledby="nombreTitulo" data-abrir-inicio="<?= $abrir === 'nombre' ? '1' : '0' ?>">
<form method="post" novalidate>
  <?= csrf_input() ?><input type="hidden" name="accion" value="nombre">
  <div class="bo-editor-cabecera"><div><p class="bo-contexto">Mi perfil</p><h2 id="nombreTitulo">Cambiar nombre</h2></div><?= $cerrar ?></div>
  <?= $abrir === 'nombre' ? $errorHtml : '' ?>
  <div class="bo-editor-scroll"><div class="bo-editor-campos">
    <div class="st-campo"><label for="pf-nombre">Nombre completo</label><input id="pf-nombre" name="nombre" maxlength="120" required autocomplete="name" value="<?= h($abrir === 'nombre' ? mb_substr((string) ($_POST['nombre'] ?? ''), 0, 120) : $a['nombre']) ?>"></div>
    <p class="st-estado">Así te ve el resto del equipo y así aparece en la bitácora.</p>
  </div></div>
  <div class="bo-editor-descartar">
    <?php $motivos('pf-motivo-nombre', 'Solicitud del interesado'); ?>
    <button type="button" class="bo-btn sec" data-cerrar>Cancelar</button><button type="submit" class="bo-btn">Guardar nombre</button>
  </div>
</form>
</dialog>

<dialog id="dlgClavePropia" class="bo-editor eq-editor eq-chico" aria-labelledby="clavePropiaTitulo" data-abrir-inicio="<?= $abrir === 'clave' ? '1' : '0' ?>">
<form method="post" novalidate>
  <?= csrf_input() ?><input type="hidden" name="accion" value="clave">
  <input type="email" name="usuario" value="<?= h($a['email']) ?>" autocomplete="username" hidden>
  <div class="bo-editor-cabecera"><div><p class="bo-contexto">Mi perfil</p><h2 id="clavePropiaTitulo">Cambiar contraseña</h2></div><?= $cerrar ?></div>
  <?= $abrir === 'clave' ? $errorHtml : '' ?>
  <div class="bo-editor-scroll"><div class="bo-editor-campos">
    <div class="st-campo"><label for="pf-actual">Contraseña actual</label><input id="pf-actual" name="actual" type="password" required maxlength="200" autocomplete="current-password"></div>
    <div class="st-campo"><label for="pf-nueva">Contraseña nueva (mínimo <?= IOEquipo::CLAVE_MIN ?> caracteres)</label><input id="pf-nueva" name="nueva" type="password" required minlength="<?= IOEquipo::CLAVE_MIN ?>" maxlength="72" autocomplete="new-password"></div>
    <div class="st-campo"><label for="pf-nueva2">Repite la contraseña nueva</label><input id="pf-nueva2" name="nueva2" type="password" required minlength="<?= IOEquipo::CLAVE_MIN ?>" maxlength="72" autocomplete="new-password"></div>
    <p class="st-estado">Al guardar, cerramos tus sesiones en otros dispositivos. Esta sigue abierta.</p>
  </div></div>
  <div class="bo-editor-descartar">
    <?php $motivos('pf-motivo-clave', 'Seguridad de la cuenta'); ?>
    <button type="button" class="bo-btn sec" data-cerrar>Cancelar</button><button type="submit" class="bo-btn">Cambiar contraseña</button>
  </div>
</form>
</dialog>
<script>
(() => {
  'use strict';
  let origen = null;
  document.querySelectorAll('dialog.eq-editor').forEach(d => {
    const form = d.querySelector('form');
    const abrir = () => { origen = document.activeElement; document.body.classList.add('bo-editor-abierto'); d.showModal(); (d.querySelector('[data-error]') || d.querySelector('input:not([type=hidden]):not([hidden])')).focus(); };
    const cerrar = () => { d.querySelector('[data-error]')?.remove(); form.reset(); d.close(); document.body.classList.remove('bo-editor-abierto'); if (origen) origen.focus(); };
    document.querySelectorAll('[data-abrir="' + d.id + '"]').forEach(b => b.addEventListener('click', abrir));
    d.querySelectorAll('[data-cerrar]').forEach(b => b.addEventListener('click', cerrar));
    d.addEventListener('cancel', e => { e.preventDefault(); cerrar(); });
    const n1 = form.elements.namedItem('nueva'), n2 = form.elements.namedItem('nueva2');
    if (n2) form.addEventListener('input', () => n2.setCustomValidity(n2.value && n2.value !== n1.value ? 'Las contraseñas no coinciden.' : ''));
    form.addEventListener('submit', e => {
      const malo = [...form.elements].find(el => el.willValidate && !el.validity.valid);
      if (malo) { e.preventDefault(); malo.reportValidity(); malo.focus(); return; }
      form.querySelectorAll('[type=submit]').forEach(b => { b.disabled = true; b.textContent = 'Guardando…'; });
    });
    if (d.dataset.abrirInicio === '1') abrir();
  });
})();
</script>
<?php }, 'perfil.php');
