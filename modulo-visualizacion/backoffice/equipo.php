<?php
/** Equipo y roles: miembros del backoffice, roles y su matriz de permisos. Solo con el permiso "equipo". */
declare(strict_types=1);
require __DIR__ . '/_inc.php';
$a = exigir('equipo');

$error = '';
$estado = ['abrir' => null, 'datos' => null];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_ok();
    $accion = (string) ($_POST['accion'] ?? '');
    $motivo = (string) ($_POST['motivo'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $lista = static fn(string $k): array => array_values(array_filter(is_array($_POST[$k] ?? null) ? $_POST[$k] : [], 'is_string'));
    try {
        switch ($accion) {
            case 'miembro_guardar':
                $datos = ['nombre' => (string) ($_POST['nombre'] ?? ''), 'email' => (string) ($_POST['email'] ?? ''), 'rol_id' => (int) ($_POST['rol_id'] ?? 0),
                    'extras' => $lista('extras'), 'activo' => !empty($_POST['activo']), 'clave' => (string) ($_POST['clave'] ?? '')];
                if ($id > 0) {
                    IOEquipo::editarMiembro($a, $id, $datos, $motivo);
                    flash($datos['activo'] ? 'Cambios guardados. Aplican desde su siguiente clic.' : 'Miembro desactivado y sus sesiones cerradas.');
                } else {
                    if (!hash_equals($datos['clave'], (string) ($_POST['clave2'] ?? ''))) { throw new IOError('clave', 'Las contraseñas no coinciden.'); }
                    IOEquipo::crearMiembro($a, $datos, $motivo);
                    flash('Miembro agregado. Compártele su contraseña por un medio seguro; podrá cambiarla en “Mi perfil”.');
                }
                break;
            case 'miembro_clave':
                $clave = (string) ($_POST['clave'] ?? '');
                if (!hash_equals($clave, (string) ($_POST['clave2'] ?? ''))) { throw new IOError('clave', 'Las contraseñas no coinciden.'); }
                IOEquipo::restablecerClave($a, $id, $clave, $motivo);
                flash('Contraseña nueva asignada. Sus sesiones abiertas se cerraron.');
                break;
            case 'rol_guardar':
                IOEquipo::guardarRol($a, $id, ['nombre' => (string) ($_POST['nombre'] ?? ''), 'descripcion' => (string) ($_POST['descripcion'] ?? ''),
                    'permisos' => $lista('permisos')], $motivo);
                flash($id > 0 ? 'Rol actualizado. Los permisos aplican de inmediato a sus miembros.' : 'Rol creado.');
                break;
            case 'rol_eliminar':
                IOEquipo::eliminarRol($a, $id, $motivo);
                flash('Rol eliminado.');
                break;
            default:
                throw new IOError('accion', 'Acción no válida.');
        }
        header('Location: equipo.php'); exit;
    } catch (IOError $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('[IO equipo] no se completó la acción ' . $accion . ': ' . get_class($e));
        $error = 'No se pudo guardar. Intenta de nuevo.';
    }
    // Reabrir el modal con lo capturado (nunca se devuelven contraseñas).
    $modal = ['miembro_guardar' => 'miembro', 'miembro_clave' => 'clave', 'rol_guardar' => 'rol', 'rol_eliminar' => 'rol'][$accion] ?? null;
    $estado = ['abrir' => $modal, 'datos' => [
        'id' => $id, 'nombre' => mb_substr((string) ($_POST['nombre'] ?? ''), 0, 120), 'email' => mb_substr((string) ($_POST['email'] ?? ''), 0, 190),
        'rol_id' => (int) ($_POST['rol_id'] ?? 0), 'extras' => $lista('extras'), 'activo' => !empty($_POST['activo']),
        'descripcion' => mb_substr((string) ($_POST['descripcion'] ?? ''), 0, 255), 'permisos' => $lista('permisos'), 'motivo' => mb_substr($motivo, 0, 120),
    ]];
}

$miembros = IOEquipo::lista();
$roles = IOEquipo::roles();
$catalogo = IOEquipo::PERMISOS;

pagina('Equipo y roles', function () use ($a, $miembros, $roles, $catalogo, $error, $estado) {
    $json = static fn($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $motivos = static function (string $id) { ?>
      <div class="st-campo st-motivo"><label for="<?= $id ?>">Motivo del cambio (queda en la bitácora)</label>
        <select id="<?= $id ?>" name="motivo" required><option value="">Elige un motivo</option><?php foreach (IOEquipo::MOTIVOS as $m): ?><option><?= h($m) ?></option><?php endforeach; ?></select></div>
    <?php };
    $cerrar = '<button type="button" class="bo-editor-cerrar" data-cerrar aria-label="Cerrar sin guardar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button>';
    $errorHtml = $error !== '' ? '<div class="bo-editor-error" role="alert" tabindex="-1" data-error>' . h($error) . '</div>' : '';
?>
<link rel="stylesheet" href="/io/backoffice/contenido-editor.css?v=2.1.1"><link rel="stylesheet" href="/io/backoffice/equipo.css?v=1.0.1">

<section class="bo-card">
  <div class="eq-barra">
    <div><h2>Equipo</h2><p class="bo-nota">Quién entra al backoffice y con qué rol. Desactivar a alguien cierra sus sesiones al instante; su historial en la bitácora se conserva.</p></div>
    <button type="button" class="bo-btn" data-nuevo-miembro aria-haspopup="dialog">+ Agregar miembro</button>
  </div>
  <div class="bo-scroll" tabindex="0" role="region" aria-label="Miembros del equipo"><table class="bo-tabla eq-tabla">
    <thead><tr><th>Nombre</th><th>Correo</th><th>Rol</th><th>Estado</th><th>Último acceso</th><th class="eq-col-acciones"><span class="visually-hidden">Acciones</span></th></tr></thead>
    <tbody>
    <?php foreach ($miembros as $m): $yo = $m['id'] === $a['id']; ?>
      <tr<?= $m['activo'] ? '' : ' class="eq-inactivo"' ?>>
        <td><span class="eq-persona"><span class="bo-avatar" aria-hidden="true"><?= h(mb_strtoupper(mb_substr((string) $m['nombre'], 0, 1))) ?></span><b><?= h($m['nombre']) ?></b><?php if ($yo): ?><span class="bo-chip info">Tú</span><?php endif; ?></span></td>
        <td class="eq-correo"><?= h($m['email']) ?></td>
        <td><?= h($m['rol_nombre'] ?? 'Sin rol') ?><?php if ($m['extras']): ?><span class="sub">+ <?= h(implode(', ', array_map(fn($p) => $catalogo[$p][0], $m['extras']))) ?></span><?php endif; ?></td>
        <td><?= $m['activo'] ? '<span class="bo-chip ok">Activo</span>' : '<span class="bo-chip apagado">Desactivado</span>' ?></td>
        <td><?= $m['ultimo_acceso'] ? h(hace($m['ultimo_acceso'])) : '<span class="sub">Nunca</span>' ?></td>
        <td class="eq-col-acciones"><div class="bo-acciones">
          <?php if ($a['total'] || !$m['total']): ?><button type="button" class="bo-btn sec mini" data-editar-miembro="<?= $m['id'] ?>" aria-haspopup="dialog" aria-label="Editar a <?= h($m['nombre']) ?>">Editar</button><?php endif; ?>
          <?php if (!$yo && ($a['total'] || !$m['total'])): ?><button type="button" class="bo-btn sec mini" data-clave-miembro="<?= $m['id'] ?>" aria-haspopup="dialog" aria-label="Asignar contraseña nueva a <?= h($m['nombre']) ?>">Contraseña</button><?php endif; ?>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>

<section class="bo-card">
  <div class="eq-barra">
    <div><h2>Roles y permisos</h2><p class="bo-nota">Cada miembro tiene un rol. Si alguien necesita algo puntual, agrégale un permiso extra sin cambiar el rol de los demás.</p></div>
    <button type="button" class="bo-btn sec" data-nuevo-rol aria-haspopup="dialog">+ Nuevo rol</button>
  </div>
  <div class="bo-scroll" tabindex="0" role="region" aria-label="Matriz de permisos por rol"><table class="bo-tabla eq-matriz">
    <caption class="visually-hidden">Qué puede hacer cada rol</caption>
    <thead><tr><th scope="col">Permiso</th>
      <?php foreach ($roles as $r): ?><th scope="col" class="eq-rol"><span class="eq-rol-nombre"><?= h($r['nombre']) ?></span></th><?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($catalogo as $clave => [$titulo, $explica]): ?>
      <tr><th scope="row"><b><?= h($titulo) ?></b><span class="sub"><?= h($explica) ?></span></th>
        <?php foreach ($roles as $r): $si = in_array($clave, $r['permisos'], true); ?>
          <td class="eq-celda"><?= $si ? '<span class="eq-si" role="img" aria-label="Sí"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></span>' : '<span class="eq-no" role="img" aria-label="No">—</span>' ?></td>
        <?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th scope="row">Miembros activos</th>
      <?php foreach ($roles as $r): ?><td><span class="sub"><?= (int) $r['miembros'] === 1 ? '1 activo' : (int) $r['miembros'] . ' activos' ?></span>
        <?php if ($a['total'] || !$r['total']): ?><button type="button" class="bo-btn sec mini" data-editar-rol="<?= $r['id'] ?>" aria-haspopup="dialog" aria-label="Editar el rol <?= h($r['nombre']) ?>">Editar</button><?php endif; ?></td><?php endforeach; ?>
    </tr></tfoot>
  </table></div>
  <p class="bo-nota mt-3">“Consultar” va incluido en todos los roles. El rol de dirección siempre tiene todos los permisos, incluidos los que se agreguen en el futuro, y debe quedar al menos una persona activa con ese rol.</p>
</section>

<!-- Alta y edición de miembro -->
<dialog id="dlgMiembro" class="bo-editor eq-editor" aria-labelledby="miembroTitulo">
<form method="post" novalidate>
  <?= csrf_input() ?><input type="hidden" name="accion" value="miembro_guardar"><input type="hidden" name="id" value="0">
  <div class="bo-editor-cabecera">
    <div><p class="bo-contexto">Inteligencia Objetiva · Equipo</p><h2 id="miembroTitulo">Agregar miembro</h2></div>
    <button type="submit" class="bo-btn">Guardar</button><?= $cerrar ?>
  </div>
  <?= $estado['abrir'] === 'miembro' ? $errorHtml : '' ?>
  <div class="bo-editor-scroll">
    <div class="bo-editor-campos">
      <div class="bo-editor-dos">
        <div class="st-campo"><label for="mi-nombre">Nombre completo</label><input id="mi-nombre" name="nombre" maxlength="120" required autocomplete="off"></div>
        <div class="st-campo"><label for="mi-email">Correo electrónico</label><input id="mi-email" name="email" type="email" maxlength="190" required autocomplete="off" spellcheck="false"></div>
      </div>
      <div class="st-bloque" data-solo-alta>
        <h3>Contraseña inicial</h3>
        <p class="bo-nota">Mínimo <?= IOEquipo::CLAVE_MIN ?> caracteres. Compártela por un medio seguro; la persona podrá cambiarla en “Mi perfil”.</p>
        <div class="bo-editor-dos">
          <div class="st-campo"><label for="mi-clave">Contraseña</label><input id="mi-clave" name="clave" type="password" minlength="<?= IOEquipo::CLAVE_MIN ?>" maxlength="72" autocomplete="new-password"></div>
          <div class="st-campo"><label for="mi-clave2">Repite la contraseña</label><input id="mi-clave2" name="clave2" type="password" minlength="<?= IOEquipo::CLAVE_MIN ?>" maxlength="72" autocomplete="new-password"></div>
        </div>
        <label class="bo-editor-check eq-mostrar"><input type="checkbox" data-mostrar-clave><span><b>Mostrar contraseñas</b></span></label>
      </div>
      <fieldset class="st-bloque eq-fieldset">
        <legend><h3>Rol</h3></legend>
        <div class="eq-roles" id="miRoles">
          <?php foreach ($roles as $r): if ($r['total'] && !$a['total']) continue; ?>
            <label class="st-opcion"><input type="radio" name="rol_id" value="<?= $r['id'] ?>" required><span><b><?= h($r['nombre']) ?></b><small><?= h($r['descripcion']) ?></small></span></label>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <details class="st-avanzado st-mas" id="miExtrasCaja"><summary>Permisos extra (opcional)</summary>
        <fieldset class="eq-fieldset"><legend class="bo-nota">Se suman a los del rol solo para esta persona.</legend>
        <div class="eq-permisos">
          <?php foreach ($catalogo as $clave => [$titulo, $explica]): if ($clave === 'ver') continue; ?>
            <label class="st-interruptor"><input type="checkbox" name="extras[]" value="<?= h($clave) ?>"><span class="st-riel" aria-hidden="true"></span><span><b><?= h($titulo) ?></b><small data-explica="<?= h($explica) ?>"><?= h($explica) ?></small></span></label>
          <?php endforeach; ?>
        </div>
        </fieldset>
      </details>
    </div>
    <aside class="st-lateral" aria-label="Resumen de permisos">
      <div class="st-visibilidad">
        <h3>Podrá</h3>
        <ul class="eq-resumen" id="miResumen" aria-live="polite"></ul>
      </div>
      <div class="st-visibilidad" data-solo-edicion>
        <h3>Estado</h3>
        <label class="st-interruptor"><input type="checkbox" name="activo" value="1" checked><span class="st-riel" aria-hidden="true"></span><span><b>Cuenta activa</b><small>Al desactivarla se cierran sus sesiones y ya no puede entrar.</small></span></label>
        <p class="st-estado" id="miAvisoPropio" hidden>Es tu cuenta: no puedes desactivarla ni quitarte el permiso “Equipo y roles”.</p>
      </div>
    </aside>
  </div>
  <div class="bo-editor-descartar">
    <?php $motivos('mi-motivo'); ?>
    <button type="button" class="bo-btn sec" data-cerrar>Cancelar</button><button type="submit" class="bo-btn">Guardar</button>
  </div>
</form>
</dialog>

<!-- Contraseña nueva para otro miembro -->
<dialog id="dlgClave" class="bo-editor eq-editor eq-chico" aria-labelledby="claveTitulo">
<form method="post" novalidate>
  <?= csrf_input() ?><input type="hidden" name="accion" value="miembro_clave"><input type="hidden" name="id" value="0">
  <div class="bo-editor-cabecera">
    <div><p class="bo-contexto">Inteligencia Objetiva · Equipo</p><h2 id="claveTitulo">Contraseña nueva</h2></div><?= $cerrar ?>
  </div>
  <?= $estado['abrir'] === 'clave' ? $errorHtml : '' ?>
  <div class="bo-editor-scroll">
    <div class="bo-editor-campos">
      <p class="eq-aviso">Asignarás una contraseña nueva a <b data-nombre-clave></b>. Sus sesiones abiertas se cerrarán.</p>
      <div class="st-campo"><label for="cl-clave">Contraseña nueva (mínimo <?= IOEquipo::CLAVE_MIN ?> caracteres)</label><input id="cl-clave" name="clave" type="password" minlength="<?= IOEquipo::CLAVE_MIN ?>" maxlength="200" required autocomplete="new-password"></div>
      <div class="st-campo"><label for="cl-clave2">Repite la contraseña</label><input id="cl-clave2" name="clave2" type="password" minlength="<?= IOEquipo::CLAVE_MIN ?>" maxlength="200" required autocomplete="new-password"></div>
      <label class="bo-editor-check eq-mostrar"><input type="checkbox" data-mostrar-clave><span><b>Mostrar contraseñas</b></span></label>
    </div>
  </div>
  <div class="bo-editor-descartar">
    <?php $motivos('cl-motivo'); ?>
    <button type="button" class="bo-btn sec" data-cerrar>Cancelar</button><button type="submit" class="bo-btn">Asignar contraseña</button>
  </div>
</form>
</dialog>

<!-- Alta y edición de rol -->
<dialog id="dlgRol" class="bo-editor eq-editor" aria-labelledby="rolTitulo">
<form method="post" novalidate>
  <?= csrf_input() ?><input type="hidden" name="accion" value="rol_guardar"><input type="hidden" name="id" value="0">
  <div class="bo-editor-cabecera">
    <div><p class="bo-contexto">Inteligencia Objetiva · Roles</p><h2 id="rolTitulo">Nuevo rol</h2></div>
    <button type="submit" class="bo-btn" name="accion" value="rol_guardar">Guardar rol</button><?= $cerrar ?>
  </div>
  <?= $estado['abrir'] === 'rol' ? $errorHtml : '' ?>
  <div class="bo-editor-scroll">
    <div class="bo-editor-campos">
      <div class="st-campo"><label for="rol-nombre">Nombre del rol</label><input id="rol-nombre" name="nombre" maxlength="80" required autocomplete="off" placeholder="Ej. Coordinación académica"></div>
      <div class="st-campo"><label for="rol-desc">Para qué sirve (opcional)</label><textarea id="rol-desc" name="descripcion" maxlength="255" rows="2" placeholder="Una frase que ayude a elegirlo al dar de alta a alguien"></textarea></div>
      <fieldset class="st-bloque eq-fieldset">
        <legend><h3>Qué puede hacer</h3></legend>
        <p class="bo-nota" id="rolTotalNota" hidden>Es el rol de dirección: siempre tiene todos los permisos y no se pueden quitar.</p>
        <div class="eq-permisos">
          <?php foreach ($catalogo as $clave => [$titulo, $explica]): ?>
            <label class="st-interruptor"><input type="checkbox" name="permisos[]" value="<?= h($clave) ?>"<?= $clave === 'ver' ? ' checked disabled data-fijo' : '' ?>><span class="st-riel" aria-hidden="true"></span><span><b><?= h($titulo) ?></b><small><?= h($explica) ?><?= $clave === 'ver' ? ' Incluido siempre.' : '' ?></small></span></label>
          <?php endforeach; ?>
        </div>
      </fieldset>
    </div>
    <aside class="st-lateral" aria-label="Uso del rol">
      <div class="st-visibilidad">
        <h3>Miembros con este rol</h3>
        <p class="st-estado" id="rolMiembros">Todavía nadie.</p>
      </div>
      <div class="st-visibilidad" id="rolEliminarCaja" hidden>
        <h3>Eliminar rol</h3>
        <p class="st-estado">Solo los roles creados por el equipo y sin miembros se pueden eliminar. Elige el motivo abajo.</p>
        <button type="submit" class="bo-btn sec eq-peligro" name="accion" value="rol_eliminar" data-eliminar-rol>Eliminar este rol</button>
      </div>
    </aside>
  </div>
  <div class="bo-editor-descartar">
    <?php $motivos('rol-motivo'); ?>
    <button type="button" class="bo-btn sec" data-cerrar>Cancelar</button><button type="submit" class="bo-btn" name="accion" value="rol_guardar">Guardar rol</button>
  </div>
</form>
</dialog>

<script type="application/json" id="equipoDatos"><?= $json([
    'yo' => $a['id'],
    'permisos' => array_map(fn($p) => $p[0], $catalogo),
    'miembros' => array_map(fn($m) => ['id' => $m['id'], 'nombre' => $m['nombre'], 'email' => $m['email'], 'rol_id' => (int) $m['rol_id'],
        'extras' => $m['extras'], 'activo' => (bool) $m['activo']], $miembros),
    'roles' => array_map(fn($r) => ['id' => $r['id'], 'nombre' => $r['nombre'], 'descripcion' => $r['descripcion'], 'total' => $r['total'],
        'sistema' => (bool) $r['sistema'], 'permisos' => $r['permisos'], 'miembros' => (int) $r['miembros']], $roles),
    'estado' => $estado,
]) ?></script>
<script src="/io/backoffice/equipo.js?v=1.0.1" defer></script>
<?php }, 'equipo.php');
