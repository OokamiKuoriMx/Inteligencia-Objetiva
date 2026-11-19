/* Modal de cupones: tipo, vigencia, contenidos y resumen en lenguaje claro. El servidor valida todo al guardar. */
(() => {
  'use strict';
  const dialog = document.getElementById('cuponEditor');
  if (!dialog) return;
  const $ = id => document.getElementById(id);
  const form = $('cuponForm'), f = n => form.elements.namedItem(n);
  const catalogo = JSON.parse($('cuponCatalogo').textContent);
  const porId = new Map(catalogo.map(p => [String(p.id), p]));
  const moneda = new Intl.NumberFormat('es-MX', {style: 'currency', currency: 'MXN'});
  const fechaLarga = new Intl.DateTimeFormat('es-MX', {day: 'numeric', month: 'long', hour: 'numeric', minute: '2-digit'});
  const normalizar = s => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  const tipo = () => (form.querySelector('[name=tipo]:checked') || {}).value || 'porcentaje';
  const alcance = () => (form.querySelector('[name=alcance]:checked') || {}).value || 'todos';
  let elegidos = f('productos').value.split(',').map(s => s.trim()).filter(Boolean);

  /* Contenidos */
  function pintarElegidos() {
    const caja = $('cupElegidos'); caja.replaceChildren();
    elegidos.forEach(id => {
      const p = porId.get(id); const chip = document.createElement('span'); chip.className = 'cup-chip';
      const t = document.createElement('span'); t.textContent = p ? p.t : `#${id}`;
      const x = document.createElement('button'); x.type = 'button'; x.textContent = '×'; x.setAttribute('aria-label', `Quitar ${p ? p.t : id}`);
      x.addEventListener('click', () => { elegidos = elegidos.filter(e => e !== id); sincronizar(); });
      chip.append(t, x); caja.append(chip);
    });
    if (!elegidos.length) { const v = document.createElement('span'); v.className = 'bo-nota'; v.textContent = 'Aún no eliges contenidos.'; caja.append(v); }
  }
  function pintarResultados() {
    const q = normalizar($('cupBuscar').value.trim()), ul = $('cupResultados'); ul.replaceChildren();
    catalogo.filter(p => !q || normalizar(p.t).includes(q) || String(p.id) === q).slice(0, 8).forEach(p => {
      const li = document.createElement('li'); const b = document.createElement('button'); b.type = 'button';
      const marcado = elegidos.includes(String(p.id)); b.setAttribute('role', 'option'); b.setAttribute('aria-selected', String(marcado));
      const t = document.createElement('span'); t.textContent = p.t; const pr = document.createElement('small'); pr.textContent = p.p > 0 ? moneda.format(p.p) : '';
      b.append(t, pr);
      b.addEventListener('click', () => { const id = String(p.id); elegidos = marcado ? elegidos.filter(e => e !== id) : [...elegidos, id]; sincronizar(); $('cupBuscar').focus(); });
      li.append(b); ul.append(li);
    });
  }
  function sincronizar() {
    f('productos').value = alcance() === 'algunos' ? elegidos.join(',') : '';
    $('cupProductos').hidden = alcance() !== 'algunos';
    pintarElegidos(); pintarResultados(); resumen();
  }
  $('cupBuscar').addEventListener('input', pintarResultados);

  /* Vigencia rápida */
  const local = d => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
  dialog.querySelectorAll('[data-dias]').forEach(b => b.addEventListener('click', () => {
    const ini = f('inicio').value ? new Date(f('inicio').value) : new Date(Math.ceil(Date.now() / 3600000) * 3600000);
    if (!f('inicio').value) f('inicio').value = local(ini);
    const fin = new Date(ini.getTime() + Number(b.dataset.dias) * 86400000 - 60000);
    f('fin').value = local(fin); resumen();
  }));

  /* Resumen y validaciones de interfaz */
  function resumen() {
    const t = tipo(), v = Number(f('valor').value) || 0;
    $('cupValorCaja').hidden = t === '2x1';
    $('cupPrefijo').hidden = t !== 'importe'; $('cupSufijo').hidden = t !== 'porcentaje';
    f('valor').required = t !== '2x1';
    f('valor').max = t === 'porcentaje' ? '100' : '99999999.99';
    f('valor').setCustomValidity(t !== '2x1' && v <= 0 ? 'Escribe cuánto descuenta.' : '');
    if (t === '2x1') f('valor').value = f('valor').value || '0';
    const ini = f('inicio').value, fin = f('fin').value;
    f('fin').setCustomValidity(ini && fin && new Date(fin) <= new Date(ini) ? 'Debe terminar después de empezar.' : '');
    f('productos').value = alcance() === 'algunos' ? elegidos.join(',') : '';
    $('cupBuscar').setCustomValidity(alcance() === 'algunos' && !elegidos.length ? 'Elige al menos un contenido o cambia a “Todo el catálogo”.' : '');
    $('cupTicket').textContent = (f('codigo').value.trim() || 'CÓDIGO').toUpperCase();
    const que = t === '2x1' ? '2×1: el contenido más barato de cada pareja sale gratis' : (t === 'porcentaje' ? `${v || '…'} % de descuento` : `${v ? moneda.format(v) : '$…'} de descuento`);
    const donde = alcance() === 'todos' ? 'en todo el catálogo' : (elegidos.length === 1 ? `en «${porId.get(elegidos[0])?.t || '1 contenido'}»` : `en ${elegidos.length || '…'} contenidos`);
    $('cupFrase').textContent = `${que} ${donde}.`;
    const det = [];
    if (ini && fin) det.push(`Del ${fechaLarga.format(new Date(ini))} al ${fechaLarga.format(new Date(fin))}`.replace(/\.?$/, '.')); else det.push('Falta definir la vigencia.');
    const min = Number(f('minimo_mxn').value) || 0; if (min > 0) det.push(`Compra mínima de ${moneda.format(min)}.`);
    det.push(f('max_usos').value ? `Hasta ${f('max_usos').value} usos en total.` : 'Sin límite de usos en total.');
    const pc = Number(f('max_por_cliente').value) || 1; det.push(pc === 1 ? 'Una vez por alumno.' : `Hasta ${pc} veces por alumno.`);
    if (f('incluir_rebajados').checked) det.push('También en contenidos ya rebajados.');
    if (!f('activo').checked) det.push('Pausado: no se puede usar.');
    const ul = $('cupDetalles'); ul.replaceChildren(...det.map(x => { const li = document.createElement('li'); li.textContent = x; return li; }));
  }
  f('codigo').addEventListener('input', () => { const p = f('codigo').selectionStart; f('codigo').value = f('codigo').value.toUpperCase().replace(/\s+/g, ''); f('codigo').setSelectionRange(p, p); });
  ['input', 'change'].forEach(ev => form.addEventListener(ev, e => { if (e.target.name === 'alcance') sincronizar(); else resumen(); cambiado = true; }));

  /* Abrir, cerrar, guardar */
  let cambiado = false;
  function abrir() { document.body.classList.add('bo-editor-abierto'); dialog.showModal(); sincronizar(); (document.getElementById('cuponError') || f('codigo')).focus(); }
  function cerrar() { if (cambiado && !confirm('Hay cambios sin guardar. ¿Quieres descartarlos?')) return; location.href = 'descuentos.php'; }
  $('nuevoCupon').addEventListener('click', () => { if ($('nuevoCupon').dataset.nuevo === '1') abrir(); else location.href = 'descuentos.php?nuevo=1'; });
  dialog.addEventListener('cancel', e => { e.preventDefault(); cerrar(); });
  dialog.querySelectorAll('[data-cerrar-cupon]').forEach(a => a.addEventListener('click', e => { e.preventDefault(); cerrar(); }));
  dialog.addEventListener('click', e => { if (e.target === dialog) { const r = dialog.getBoundingClientRect(); if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) cerrar(); } });
  form.addEventListener('submit', e => {
    sincronizar();
    const malo = [...form.elements].find(el => el.willValidate && !el.validity.valid);
    if (malo) { e.preventDefault(); malo.closest('details')?.setAttribute('open', ''); malo.reportValidity(); malo.focus(); return; }
    cambiado = false; form.querySelectorAll('[type=submit]').forEach(b => { b.disabled = true; b.textContent = 'Guardando…'; });
  });
  if (dialog.dataset.abrir === '1') abrir();
})();
