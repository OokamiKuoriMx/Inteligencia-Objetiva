/* Equipo y roles: llena y abre los modales de miembro, contraseña y rol. El servidor valida todo al guardar. */
(() => {
  'use strict';
  const datos = JSON.parse(document.getElementById('equipoDatos').textContent);
  const miembros = new Map(datos.miembros.map(m => [m.id, m]));
  const roles = new Map(datos.roles.map(r => [r.id, r]));
  const dlg = {miembro: document.getElementById('dlgMiembro'), clave: document.getElementById('dlgClave'), rol: document.getElementById('dlgRol')};
  let cambiado = false, origen = null;

  function abrir(d, foco) {
    origen = document.activeElement;
    cambiado = false;
    document.body.classList.add('bo-editor-abierto');
    d.showModal();
    (d.querySelector('[data-error]') || foco || d.querySelector('input:not([type=hidden]):not([disabled])')).focus();
  }
  function cerrar(d, forzar) {
    if (!forzar && cambiado && !confirm('Hay cambios sin guardar. ¿Quieres descartarlos?')) return;
    d.querySelector('[data-error]')?.remove();
    d.close();
    document.body.classList.remove('bo-editor-abierto');
    if (origen && document.contains(origen)) origen.focus();
  }
  Object.values(dlg).forEach(d => {
    const form = d.querySelector('form');
    d.addEventListener('cancel', e => { e.preventDefault(); cerrar(d); });
    d.querySelectorAll('[data-cerrar]').forEach(b => b.addEventListener('click', () => cerrar(d)));
    d.addEventListener('click', e => {
      if (e.target !== d) return;
      const r = d.getBoundingClientRect();
      if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) cerrar(d);
    });
    ['input', 'change'].forEach(ev => form.addEventListener(ev, () => { cambiado = true; }));
    d.querySelectorAll('[data-mostrar-clave]').forEach(c => c.addEventListener('change', () => {
      d.querySelectorAll('input[name=clave], input[name=clave2]').forEach(i => { i.type = c.checked ? 'text' : 'password'; });
    }));
    form.addEventListener('submit', e => {
      const eliminar = e.submitter && e.submitter.value === 'rol_eliminar';
      const revisar = eliminar ? [form.elements.namedItem('motivo')] : [...form.elements];
      const malo = revisar.find(el => el && el.willValidate && !el.validity.valid);
      if (malo) { e.preventDefault(); malo.closest('details')?.setAttribute('open', ''); malo.reportValidity(); malo.focus(); return; }
      if (eliminar && !confirm('¿Eliminar este rol? Esta acción no se puede deshacer.')) { e.preventDefault(); return; }
      cambiado = false;
      // Tu propia cuenta se muestra fija como activa, pero debe enviarse.
      form.querySelectorAll('input[name=activo]:disabled').forEach(i => { i.disabled = false; });
      form.querySelectorAll('[type=submit]').forEach(b => { b.disabled = true; });
      if (e.submitter) { const h = document.createElement('input'); h.type = 'hidden'; h.name = e.submitter.name || 'accion'; h.value = e.submitter.value; if (e.submitter.name) form.append(h); e.submitter.textContent = 'Guardando…'; }
    });
  });

  /* ---------- Miembro ---------- */
  const fm = dlg.miembro.querySelector('form'), cm = n => fm.elements.namedItem(n);
  const extras = [...fm.querySelectorAll('input[name="extras[]"]')];
  function rolElegido() { const r = fm.querySelector('input[name=rol_id]:checked'); return r ? roles.get(Number(r.value)) : null; }
  function pintarMiembro() {
    const rol = rolElegido(), propios = rol ? rol.permisos : ['ver'];
    extras.forEach(c => {
      const incluido = propios.includes(c.value), small = c.closest('label').querySelector('small');
      if (incluido) { c.dataset.antes = c.checked ? '1' : ''; c.checked = true; c.disabled = true; small.textContent = 'Incluido en el rol.'; }
      else { if (c.disabled) { c.checked = c.dataset.antes === '1'; } c.disabled = false; small.textContent = small.dataset.explica; }
    });
    const efectivos = new Set(propios);
    extras.forEach(c => { if (c.checked) efectivos.add(c.value); });
    const ul = document.getElementById('miResumen');
    ul.replaceChildren(...Object.entries(datos.permisos).filter(([k]) => efectivos.has(k)).map(([, t]) => { const li = document.createElement('li'); li.textContent = t; return li; }));
    if (!rol) { const li = document.createElement('li'); li.className = 'bo-nota'; li.textContent = 'Elige un rol para ver qué podrá hacer.'; ul.replaceChildren(li); }
  }
  fm.addEventListener('change', e => { if (['rol_id', 'extras[]'].includes(e.target.name)) pintarMiembro(); });
  function llenarMiembro(m, conClave) {
    const nuevo = !m.id;
    cm('id').value = m.id || 0;
    cm('nombre').value = m.nombre || '';
    cm('email').value = m.email || '';
    cm('email').readOnly = !nuevo;
    cm('email').closest('.st-campo').querySelector('label').textContent = nuevo ? 'Correo electrónico' : 'Correo electrónico (no se cambia)';
    dlg.miembro.querySelector('#miembroTitulo').textContent = nuevo ? 'Agregar miembro' : `Editar a ${m.nombre}`;
    dlg.miembro.querySelectorAll('[data-solo-alta]').forEach(x => { x.hidden = !nuevo; });
    dlg.miembro.querySelectorAll('[data-solo-edicion]').forEach(x => { x.hidden = nuevo; });
    ['clave', 'clave2'].forEach(n => { cm(n).value = ''; cm(n).required = nuevo; cm(n).disabled = !nuevo; });
    fm.querySelectorAll('input[name=rol_id]').forEach(r => { r.checked = Number(r.value) === Number(m.rol_id); });
    extras.forEach(c => { c.disabled = false; c.checked = (m.extras || []).includes(c.value); delete c.dataset.antes; });
    const propio = m.id === datos.yo;
    cm('activo').checked = nuevo || m.activo !== false;
    cm('activo').disabled = propio;
    document.getElementById('miAvisoPropio').hidden = !propio;
    document.getElementById('miExtrasCaja').open = (m.extras || []).length > 0;
    cm('motivo').value = m.motivo || (nuevo ? 'Alta de personal' : '');
    pintarMiembro();
  }
  document.querySelectorAll('[data-nuevo-miembro]').forEach(b => b.addEventListener('click', () => { llenarMiembro({}); abrir(dlg.miembro, cm('nombre')); }));
  document.querySelectorAll('[data-editar-miembro]').forEach(b => b.addEventListener('click', () => {
    llenarMiembro(miembros.get(Number(b.dataset.editarMiembro))); abrir(dlg.miembro, cm('nombre'));
  }));

  /* ---------- Contraseña de otro miembro ---------- */
  const fc = dlg.clave.querySelector('form');
  function llenarClave(m, motivo) {
    fc.elements.namedItem('id').value = m.id;
    fc.elements.namedItem('motivo').value = motivo || 'Seguridad de la cuenta';
    ['clave', 'clave2'].forEach(n => { fc.elements.namedItem(n).value = ''; });
    dlg.clave.querySelector('[data-nombre-clave]').textContent = m.nombre;
  }
  document.querySelectorAll('[data-clave-miembro]').forEach(b => b.addEventListener('click', () => {
    llenarClave(miembros.get(Number(b.dataset.claveMiembro))); abrir(dlg.clave, fc.elements.namedItem('clave'));
  }));
  const igualar = (f) => { const c2 = f.elements.namedItem('clave2'); if (c2) c2.setCustomValidity(c2.value && c2.value !== f.elements.namedItem('clave').value ? 'Las contraseñas no coinciden.' : ''); };
  [fm, fc].forEach(f => f.addEventListener('input', () => igualar(f)));

  /* ---------- Rol ---------- */
  const fr = dlg.rol.querySelector('form'), cr = n => fr.elements.namedItem(n);
  const permisos = [...fr.querySelectorAll('input[name="permisos[]"]')];
  function llenarRol(r) {
    const nuevo = !r.id;
    cr('id').value = r.id || 0;
    cr('nombre').value = r.nombre || '';
    cr('descripcion').value = r.descripcion || '';
    dlg.rol.querySelector('#rolTitulo').textContent = nuevo ? 'Nuevo rol' : `Editar rol: ${r.nombre}`;
    permisos.forEach(c => {
      if (c.dataset.fijo !== undefined) return;
      c.checked = !!r.total || (r.permisos || []).includes(c.value);
      c.disabled = !!r.total;
    });
    document.getElementById('rolTotalNota').hidden = !r.total;
    const n = r.miembros || 0;
    document.getElementById('rolMiembros').textContent = nuevo ? 'Rol nuevo: asígnalo al dar de alta o editar a alguien.'
      : (n === 0 ? 'Todavía nadie activo.' : (n === 1 ? '1 miembro activo. Los cambios aplican de inmediato.' : `${n} miembros activos. Los cambios aplican de inmediato.`));
    document.getElementById('rolEliminarCaja').hidden = nuevo || r.sistema || r.total || n > 0;
    cr('motivo').value = r.motivo || (nuevo ? 'Cambio de funciones' : '');
  }
  document.querySelectorAll('[data-nuevo-rol]').forEach(b => b.addEventListener('click', () => { llenarRol({}); abrir(dlg.rol, cr('nombre')); }));
  document.querySelectorAll('[data-editar-rol]').forEach(b => b.addEventListener('click', () => {
    llenarRol(roles.get(Number(b.dataset.editarRol))); abrir(dlg.rol, cr('nombre'));
  }));

  /* ---------- Reabrir tras un error del servidor ---------- */
  const e = datos.estado || {}, d = e.datos || {};
  if (e.abrir === 'miembro') {
    const base = d.id ? (miembros.get(d.id) || {}) : {};
    llenarMiembro({...base, id: d.id, nombre: d.nombre, email: d.id ? base.email : d.email, rol_id: d.rol_id, extras: d.extras, activo: d.activo, motivo: d.motivo});
    abrir(dlg.miembro);
  } else if (e.abrir === 'clave' && miembros.has(d.id)) {
    llenarClave(miembros.get(d.id), d.motivo); abrir(dlg.clave);
  } else if (e.abrir === 'rol') {
    const base = d.id ? (roles.get(d.id) || {}) : {};
    llenarRol({...base, id: d.id, nombre: d.nombre, descripcion: d.descripcion, permisos: d.permisos, motivo: d.motivo}); abrir(dlg.rol);
  }
})();
