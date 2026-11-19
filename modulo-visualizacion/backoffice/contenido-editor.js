/* Editor de contenido: el servidor valida permisos, versión y todos los campos. */
(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const normalizar = s => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  $('buscarContenido')?.addEventListener('input', e => {
    const texto = normalizar(e.target.value.trim());
    let total = 0;
    document.querySelectorAll('[data-contenido-filtro]').forEach(row => {
      row.hidden = !normalizar(row.dataset.contenidoFiltro).includes(texto);
      if (!row.hidden) total++;
    });
    $('conteoContenidos').textContent = `${total} ${total === 1 ? 'contenido' : 'contenidos'}`;
    $('contenidosVacios').hidden = total > 0;
  });
  const dialog = $('editorContenido');
  if (!dialog) return;
  const config = JSON.parse($('editorConfiguracion').textContent);
  const form = $('formContenido');
  const field = name => form.elements.namedItem(name);
  const tabs = [...dialog.querySelectorAll('[data-editor-tab]')];
  let dirty = false, busy = false, opener = null, request = 0, videoKey = '';
  const fallback = '/portada/img/conferencias-800.webp';
  const enlace = $('contenido-enlace-vimeo'), duracionTexto = $('contenido-duracion-texto');
  let videoPrevio = '', consulta = 0, ultimaConsulta = '';
  /** Misma lectura de enlaces que IOVimeoCliente::analizarEnlace (el servidor vuelve a validar). */
  function analizarEnlace(entrada) {
    let t = String(entrada || '').trim();
    if (!t || t.length > 2000) return null;
    if (/^\d{1,32}$/.test(t)) return /^0+$/.test(t) ? null : {id:t, hash:''};
    const src = t.match(/\bsrc\s*=\s*["']([^"']+)["']/i); if (src) t = src[1].replace(/&amp;/g, '&');
    if (t.startsWith('//')) t = 'https:' + t;
    if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(t)) t = 'https://' + t;
    let u; try { u = new URL(t); } catch (_) { return null; }
    if (!['http:','https:'].includes(u.protocol)) return null;
    const host = u.hostname.toLowerCase().replace(/^www\./, '');
    if (host !== 'vimeo.com' && host !== 'player.vimeo.com') return null;
    const seg = u.pathname.split('/').filter(Boolean); let id = null, pos = -1;
    if (host === 'player.vimeo.com') { if (seg[0] === 'video' && /^\d{1,32}$/.test(seg[1] || '')) { id = seg[1]; pos = 1; } }
    else {
      for (let i = 0; i < seg.length && id === null; i++) if (['video','videos'].includes(seg[i].toLowerCase()) && /^\d{1,32}$/.test(seg[i + 1] || '')) { id = seg[i + 1]; pos = i + 1; }
      for (let i = 0; i < seg.length && id === null; i++) if (/^\d{1,32}$/.test(seg[i])) { id = seg[i]; pos = i; }
    }
    if (id === null || /^0+$/.test(id)) return null;
    const h = u.searchParams.get('h');
    const hash = h && /^[A-Za-z0-9]{1,32}$/.test(h) ? h : (/^[0-9a-f]{6,32}$/i.test(seg[pos + 1] || '') ? seg[pos + 1] : '');
    return {id, hash};
  }
  const enlaceDe = (id, hash) => id ? `https://vimeo.com/${id}${hash ? '/' + hash : ''}` : '';
  function hms(total) { total = Math.max(0, Math.floor(Number(total) || 0)); const h = Math.floor(total / 3600), m = Math.floor(total % 3600 / 60), s = total % 60; return `${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`; }
  function segundos(texto) {
    const t = String(texto || '').trim(); if (t === '') return 0;
    if (!/^\d{1,6}(:[0-5]?\d){0,2}$/.test(t)) return null;
    return t.split(':').map(Number).reduce((total, parte) => total * 60 + parte, 0);
  }
  function sincronizarDuracion() {
    const s = segundos(duracionTexto.value);
    duracionTexto.setCustomValidity(s === null ? 'Escribe la duración como h:mm:ss, por ejemplo 1:05:30.' : (field('tipo').value === 'unica' && s < 1 ? 'La visualización única requiere la duración del video.' : ''));
    if (s !== null) field('duracion_seg').value = String(s);
  }
  function panel(key, focus = false) {
    tabs.forEach(tab => {
      const active = tab.dataset.editorTab === key;
      tab.setAttribute('aria-selected', String(active)); tab.tabIndex = active ? 0 : -1;
      if (active && focus) tab.focus();
    });
    dialog.querySelectorAll('[data-editor-panel]').forEach(p => p.hidden = p.dataset.editorPanel !== key);
    dialog.querySelector('.bo-editor-scroll').scrollTop = 0;
  }
  tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => panel(tab.dataset.editorTab));
    tab.addEventListener('keydown', e => {
      let next;
      if (['ArrowRight','ArrowDown'].includes(e.key)) next = (index + 1) % tabs.length;
      if (['ArrowLeft','ArrowUp'].includes(e.key)) next = (index + tabs.length - 1) % tabs.length;
      if (e.key === 'Home') next = 0;
      if (e.key === 'End') next = tabs.length - 1;
      if (next !== undefined) { e.preventDefault(); panel(tabs[next].dataset.editorTab, true); }
    });
  });
  const orientation = () => dialog.querySelector('[role=tablist]').setAttribute('aria-orientation', matchMedia('(max-width:767px)').matches ? 'horizontal' : 'vertical');
  window.addEventListener('resize', orientation); orientation();
  function error(message) { $('editorError').textContent = message; $('editorError').hidden = false; $('editorError').focus(); }
  function stopVideo() { $('previewVideo').replaceChildren(); $('vistaPreviaVideo').hidden = false; }
  function update() {
    const tipo = field('tipo').value;
    $('previewTitulo').textContent = field('titulo').value.trim() || 'Título del contenido';
    $('previewCategoria').textContent = field('categoria').selectedOptions[0]?.textContent || '';
    $('previewAcceso').textContent = {ventana:`${field('horas_ventana').value || 0} h de acceso`,permanente:'Acceso permanente',unica:'Visualización única'}[tipo];
    $('previewPrecio').textContent = Number(field('precio_mxn').value) > 0 ? new Intl.NumberFormat('es-MX', {style:'currency',currency:'MXN'}).format(Number(field('precio_mxn').value)) + ' MXN' : 'Sin precio definido';
    sincronizarDuracion();
    const secs = Number(field('duracion_seg').value);
    $('previewDuracion').textContent = secs > 0 ? `Duración ${hms(secs)}` : 'Sin duración definida';
    $('resumenCuenta').textContent = `${field('resumen').value.length} / 400`;
    $('editorEstado').textContent = field('activo').checked ? 'En catálogo' : 'Borrador';
    dialog.querySelector('[data-regla-ventana]').hidden = tipo !== 'ventana';
    dialog.querySelector('[data-regla-inicio]').hidden = tipo === 'permanente';
    dialog.querySelector('[data-regla-unica]').hidden = tipo !== 'unica';
    field('horas_ventana').min = tipo === 'ventana' ? '1' : '0';
    enlace.required = field('activo').checked || tipo === 'unica';
    enlace.setCustomValidity(enlace.value.trim() && !field('vimeo_id').value ? 'Pega un enlace de Vimeo válido, por ejemplo https://vimeo.com/123456789.' : '');
    const raw = field('imagen').value.trim();
    const src = (/^\/(?!\/)[^\s\\]*$/.test(raw) || /^https:\/\//i.test(raw)) ? raw : fallback;
    if ($('previewMiniatura').getAttribute('src') !== src) $('previewMiniatura').src = src;
    const key = field('vimeo_id').value + ':' + field('vimeo_hash').value;
    if (key !== videoKey) { stopVideo(); videoKey = key; }
    $('vistaPreviaVideo').disabled = !/^\d{1,32}$/.test(field('vimeo_id').value);
  }
  $('previewMiniatura').addEventListener('error', () => { if (!$('previewMiniatura').src.endsWith(fallback)) $('previewMiniatura').src = fallback; });
  ['input','change'].forEach(event => form.addEventListener(event, () => { dirty = true; $('editorGuardado').textContent = 'Cambios sin guardar'; update(); }));
  function close(force = false) {
    if (busy) return;
    if (dirty && !force) { $('editorDescartar').hidden = false; $('seguirEditando').focus(); return; }
    request++; stopVideo(); dialog.close(); document.body.classList.remove('bo-editor-abierto'); opener?.focus();
  }
  dialog.addEventListener('cancel', e => { e.preventDefault(); close(); });
  dialog.addEventListener('click', e => { if (e.target === dialog) { const r = dialog.getBoundingClientRect(); if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) close(); } });
  dialog.querySelectorAll('[data-cerrar-editor]').forEach(b => b.addEventListener('click', () => close()));
  $('seguirEditando').addEventListener('click', () => { $('editorDescartar').hidden = true; tabs.find(t => t.getAttribute('aria-selected') === 'true')?.focus(); });
  $('descartarCambios').addEventListener('click', () => close(true));
  async function json(url, options = {}) {
    const response = await fetch(url, {credentials:'same-origin', ...options});
    let data;
    try { data = await response.json(); } catch (_) { throw new Error('No se pudo leer la respuesta. Revisa tu sesión y vuelve a intentar.'); }
    if (!response.ok || !data.ok) throw new Error(data.mensaje || 'No se pudo completar la operación.');
    return data;
  }
  function setBusy(value) { busy = value; form.setAttribute('aria-busy', String(value)); $('guardarContenido').disabled = value; $('guardarContenido').textContent = value ? 'Guardando…' : 'Guardar cambios'; }
  async function open(id, trigger) {
    if (dialog.open || busy) return;
    opener = trigger || document.activeElement;
    form.reset(); $('editorError').hidden = true; $('editorDescartar').hidden = true; dirty = false;
    $('editorGuardado').textContent = 'Los cambios se guardarán al confirmar.';
    $('estadoVimeo').textContent = 'Cópialo desde Vimeo con el botón Compartir o desde la barra del navegador. El video es obligatorio para publicar o para visualización única.';
    privacidad(null); consulta++;
    panel('detalles'); dialog.showModal(); document.body.classList.add('bo-editor-abierto');
    const seq = ++request;
    $('guardarContenido').disabled = true;
    try {
      const content = id ? (await json(`contenido.php?producto=${encodeURIComponent(id)}`)).contenido : config.nuevo;
      if (seq !== request || !dialog.open) return;
      for (const [name, value] of Object.entries(content)) {
        const element = field(name);
        if (!element) continue;
        if (element instanceof RadioNodeList) element.value = String(value);
        else if (element.type === 'checkbox') element.checked = Number(value) === 1;
        else element.value = value ?? '';
      }
      field('producto_id_original').value = id || '';
      field('producto_id').readOnly = !!id;
      field('motivo').value = '';
      enlace.value = enlaceDe(field('vimeo_id').value, field('vimeo_hash').value);
      duracionTexto.value = Number(field('duracion_seg').value) > 0 ? hms(field('duracion_seg').value) : '';
      videoPrevio = field('vimeo_id').value; ultimaConsulta = ''; $('sugerenciasVimeo').hidden = true;
      $('editorTitulo').textContent = id ? 'Detalles del contenido' : 'Agregar contenido';
      update(); $('guardarContenido').disabled = false; field('titulo').focus();
      if (field('vimeo_id').value) consultarVimeo(false); else privacidad(null);
    } catch (e) { if (seq === request) error(e.message); }
  }
  $('nuevoContenido')?.addEventListener('click', e => open(0, e.currentTarget));
  document.querySelectorAll('[data-editar-contenido]').forEach(b => b.addEventListener('click', () => open(Number(b.dataset.editarContenido), b)));
  $('vistaPreviaVideo').addEventListener('click', () => {
    const id = field('vimeo_id').value, hash = field('vimeo_hash').value;
    if (!/^\d{1,32}$/.test(id) || !/^[a-zA-Z0-9]*$/.test(hash)) return;
    const iframe = document.createElement('iframe');
    iframe.title = 'Vista previa del video'; iframe.allow = 'fullscreen; picture-in-picture'; iframe.allowFullscreen = true;
    iframe.src = `https://player.vimeo.com/video/${id}?dnt=1${hash ? '&h=' + encodeURIComponent(hash) : ''}`;
    $('previewVideo').replaceChildren(iframe); $('vistaPreviaVideo').hidden = true;
  });
  const niveles = {ok:['✓','Correcto'], aviso:['!','Revisar'], falla:['✕','Corregir']};
  const nombres = {oculto:'Oculto de Vimeo', dominio:'Solo en el sitio', descargas:'Descargas de Vimeo'};
  function privacidad(video, cuenta, mensaje) {
    const lista = $('privacidadIndicadores'), caja = $('privacidadAplicar');
    lista.replaceChildren(); lista.hidden = true; caja.hidden = true; $('aplicarNota').textContent = '';
    if (mensaje) { $('privacidadMensaje').textContent = mensaje; return; }
    if (!video) { $('privacidadMensaje').textContent = 'Conecta un video para revisar su privacidad.'; return; }
    if (!video.privacidad) {
      $('privacidadMensaje').textContent = cuenta && cuenta.configurado ? 'No se pudo leer la privacidad de este video.' : 'Para revisar la privacidad, vincula la cuenta de Vimeo (una sola vez).';
      return;
    }
    const p = video.privacidad;
    $('privacidadMensaje').textContent = p.listo ? 'La configuración en Vimeo es la recomendada.' : 'Hay ajustes pendientes en Vimeo.';
    for (const [clave, ind] of Object.entries(p.indicadores)) {
      const li = document.createElement('li'); li.className = ind.nivel;
      const icono = document.createElement('span'); icono.className = 'bo-vimeo-icono'; icono.textContent = niveles[ind.nivel][0]; icono.setAttribute('aria-hidden','true');
      const texto = document.createElement('span'); const b = document.createElement('b'); b.textContent = nombres[clave] || clave;
      const sr = document.createElement('span'); sr.className = 'visually-hidden'; sr.textContent = ` (${niveles[ind.nivel][1]})`;
      texto.append(b, sr, document.createElement('br'), ind.texto); li.append(icono, texto); lista.append(li);
    }
    lista.hidden = false;
    if (!p.listo) {
      caja.hidden = false; const boton = $('aplicarVimeo');
      boton.disabled = !(cuenta && cuenta.puede_editar && cuenta.dominio);
      $('aplicarNota').textContent = !cuenta || !cuenta.puede_editar ? 'El token de Vimeo no tiene el permiso “Edit”. Agrégalo desde “Cuenta de Vimeo” o haz el ajuste a mano en Vimeo.'
        : (!cuenta.dominio ? 'El dominio configurado es de prueba: aplica este ajuste desde el backoffice del sitio publicado.' : 'Oculta el video en Vimeo, autoriza solo el dominio del sitio y desactiva las descargas de Vimeo.');
    }
  }
  function sugerir(video, llenar) {
    const caja = $('sugerenciasVimeo'); caja.replaceChildren(); caja.hidden = true;
    if (!llenar) return [];
    const ofrecer = (texto, aplicar) => {
      const b = document.createElement('button'); b.type = 'button'; b.className = 'bo-btn sec mini'; b.textContent = texto;
      b.addEventListener('click', () => { aplicar(); b.remove(); caja.hidden = !caja.children.length; dirty = true; update(); });
      caja.append(b); caja.hidden = false;
    };
    const llenados = [];
    if (video.titulo) {
      if (!field('titulo').value.trim()) { field('titulo').value = video.titulo; llenados.push('título'); }
      else if (field('titulo').value.trim() !== video.titulo) ofrecer(`Usar el título de Vimeo: «${video.titulo.slice(0, 60)}»`, () => { field('titulo').value = video.titulo; });
    }
    if (video.duracion_seg > 0) { duracionTexto.value = hms(video.duracion_seg); field('duracion_seg').value = String(video.duracion_seg); llenados.push('duración'); }
    if (video.miniatura) {
      if (!field('imagen').value.trim() || video.id !== videoPrevio) { field('imagen').value = video.miniatura; llenados.push('miniatura'); }
      else if (field('imagen').value.trim() !== video.miniatura) ofrecer('Usar la miniatura de Vimeo', () => { field('imagen').value = video.miniatura; });
    }
    return llenados;
  }
  async function consultarVimeo(llenar) {
    if (!enlace.value.trim()) { field('vimeo_id').value = ''; field('vimeo_hash').value = ''; privacidad(null); update(); return; }
    const v = analizarEnlace(enlace.value);
    if (!v) {
      $('estadoVimeo').textContent = 'No se reconoció un video de Vimeo. Pega el enlace completo, por ejemplo https://vimeo.com/123456789.';
      field('vimeo_id').value = ''; field('vimeo_hash').value = ''; privacidad(null); update(); return;
    }
    if (llenar) {
      const anterior = field('vimeo_id').value;
      field('vimeo_id').value = v.id; if (v.hash || v.id !== anterior) field('vimeo_hash').value = v.hash;
      dirty = true; $('editorGuardado').textContent = 'Cambios sin guardar';
    }
    update();
    const clave = `${v.id}:${v.hash}:${llenar}`; if (clave === ultimaConsulta) return; ultimaConsulta = clave;
    const seq = request, n = ++consulta, boton = $('consultarVimeo'); boton.disabled = true;
    $('estadoVimeo').textContent = 'Consultando Vimeo…'; privacidad(null, null, 'Revisando la privacidad en Vimeo…');
    const data = new FormData(); data.set('accion','consultar'); data.set('csrf',field('csrf').value); data.set('enlace',enlace.value.trim());
    try {
      const r = await json('vimeo-api.php', {method:'POST',body:data});
      if (seq !== request || n !== consulta) return;
      const video = r.video;
      if (llenar && video.hash && !field('vimeo_hash').value) field('vimeo_hash').value = video.hash;
      const llenados = sugerir(video, llenar);
      if (llenar) enlace.value = enlaceDe(field('vimeo_id').value, field('vimeo_hash').value);
      videoPrevio = video.id; update(); privacidad(video, r.cuenta);
      $('estadoVimeo').textContent = `Video conectado: «${video.titulo}».` + (llenados.length ? ` Se llenó: ${llenados.join(', ')}.` : '');
    } catch (e) {
      if (seq !== request || n !== consulta) return;
      ultimaConsulta = '';
      $('estadoVimeo').textContent = e.message + (llenar ? ' El video quedó conectado; puedes escribir la duración a mano.' : '');
      privacidad(null, null, 'No se pudo revisar la privacidad en este momento.');
    } finally { if (n === consulta) boton.disabled = false; }
  }
  $('consultarVimeo').addEventListener('click', () => { ultimaConsulta = ''; consultarVimeo(true); });
  enlace.addEventListener('paste', () => setTimeout(() => consultarVimeo(true), 0));
  enlace.addEventListener('change', () => consultarVimeo(true));
  enlace.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); ultimaConsulta = ''; consultarVimeo(true); } });
  duracionTexto.addEventListener('blur', () => { const s = segundos(duracionTexto.value); if (s !== null && duracionTexto.value.trim()) duracionTexto.value = hms(s); });
  field('trailer_vimeo').addEventListener('change', () => { const v = analizarEnlace(field('trailer_vimeo').value); if (v) field('trailer_vimeo').value = v.id; });
  $('aplicarVimeo').addEventListener('click', async () => {
    const id = field('vimeo_id').value, motivo = $('vimeo-motivo').value.trim();
    if (!id) return;
    if (!motivo) { $('aplicarNota').textContent = 'Escribe el motivo del cambio.'; $('vimeo-motivo').focus(); return; }
    const boton = $('aplicarVimeo'), seq = request; boton.disabled = true; $('aplicarNota').textContent = 'Aplicando en Vimeo…';
    const data = new FormData(); data.set('accion','aplicar'); data.set('csrf',field('csrf').value); data.set('vimeo_id',id); data.set('motivo',motivo);
    if (field('producto_id_original').value) data.set('producto_id', field('producto_id_original').value);
    try {
      const r = await json('vimeo-api.php', {method:'POST',body:data});
      if (seq !== request) return;
      privacidad(r.video, r.cuenta); $('privacidadMensaje').textContent = 'Configuración aplicada en Vimeo y registrada en la bitácora.';
    } catch (e) { if (seq === request) { $('aplicarNota').textContent = e.message; boton.disabled = false; } }
  });
  form.addEventListener('submit', async e => {
    e.preventDefault(); if (busy) return;
    const muestra = analizarEnlace(field('trailer_vimeo').value); if (muestra) field('trailer_vimeo').value = muestra.id;
    update();
    const invalid = [...form.elements].find(el => el.willValidate && !el.validity.valid);
    if (invalid) { const section = invalid.closest('[data-editor-panel]'); if (section) panel(section.dataset.editorPanel); invalid.reportValidity(); invalid.focus(); return; }
    $('editorError').hidden = true; setBusy(true);
    try {
      await json('contenido.php', {method:'POST',body:new FormData(form)});
      dirty = false; setBusy(false); close(true);
      const url = new URL(location.href); url.searchParams.delete('producto'); url.searchParams.delete('nuevo'); location.replace(url.href);
    } catch (err) { setBusy(false); error(err.message); }
  });
  window.addEventListener('beforeunload', e => { if (dirty && dialog.open) { e.preventDefault(); e.returnValue = ''; } });
  if (config.abrir.producto || config.abrir.nuevo) open(config.abrir.producto || 0);
})();
