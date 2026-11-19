/* Editor de contenido estilo estudio de video: el servidor valida permisos, versión y todos los campos. */
(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const normalizar = s => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
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
  const valor = name => { const el = field(name); return el ? (el.type === 'checkbox' ? (el.checked ? '1' : '0') : String(el.value ?? '')) : ''; };
  const tabs = [...dialog.querySelectorAll('[data-editor-tab]')];
  const fallback = '/portada/img/conferencias-800.webp';
  const enlace = $('contenido-enlace-vimeo'), duracionTexto = $('contenido-duracion-texto');
  const moneda = new Intl.NumberFormat('es-MX', {style: 'currency', currency: 'MXN'});
  let busy = false, opener = null, request = 0, videoKey = '', original = null, contenidoCargado = null;
  let videoPrevio = '', consulta = 0, ultimaConsulta = '', miniaturaVimeo = '', subidas = [];

  /* ---------- Enlaces de Vimeo (misma lectura que IOVimeoCliente::analizarEnlace; el servidor vuelve a validar) ---------- */
  function analizarEnlace(entrada) {
    let t = String(entrada || '').trim();
    if (!t || t.length > 2000) return null;
    if (/^\d{1,32}$/.test(t)) return /^0+$/.test(t) ? null : {id: t, hash: ''};
    const src = t.match(/\bsrc\s*=\s*["']([^"']+)["']/i); if (src) t = src[1].replace(/&amp;/g, '&');
    if (t.startsWith('//')) t = 'https:' + t;
    if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(t)) t = 'https://' + t;
    let u; try { u = new URL(t); } catch (_) { return null; }
    if (!['http:', 'https:'].includes(u.protocol)) return null;
    const host = u.hostname.toLowerCase().replace(/^www\./, '');
    if (host !== 'vimeo.com' && host !== 'player.vimeo.com') return null;
    const seg = u.pathname.split('/').filter(Boolean); let id = null, pos = -1;
    if (host === 'player.vimeo.com') { if (seg[0] === 'video' && /^\d{1,32}$/.test(seg[1] || '')) { id = seg[1]; pos = 1; } }
    else {
      for (let i = 0; i < seg.length && id === null; i++) if (['video', 'videos'].includes(seg[i].toLowerCase()) && /^\d{1,32}$/.test(seg[i + 1] || '')) { id = seg[i + 1]; pos = i + 1; }
      for (let i = 0; i < seg.length && id === null; i++) if (/^\d{1,32}$/.test(seg[i])) { id = seg[i]; pos = i; }
    }
    if (id === null || /^0+$/.test(id)) return null;
    const h = u.searchParams.get('h');
    const hash = h && /^[A-Za-z0-9]{1,32}$/.test(h) ? h : (/^[0-9a-f]{6,32}$/i.test(seg[pos + 1] || '') ? seg[pos + 1] : '');
    return {id, hash};
  }
  const enlaceDe = (id, hash) => id ? `https://vimeo.com/${id}${hash ? '/' + hash : ''}` : '';
  function hms(total) { total = Math.max(0, Math.floor(Number(total) || 0)); const h = Math.floor(total / 3600), m = Math.floor(total % 3600 / 60), s = total % 60; return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`; }
  function segundos(texto) {
    const t = String(texto || '').trim(); if (t === '') return 0;
    if (!/^\d{1,6}(:[0-5]?\d){0,2}$/.test(t)) return null;
    return t.split(':').map(Number).reduce((total, parte) => total * 60 + parte, 0);
  }
  const imagenValida = raw => /^\/(?!\/)[^\s\\]*$/.test(raw) || /^https:\/\//i.test(raw);

  /* ---------- Pestañas ---------- */
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
      if (e.key === 'ArrowRight') next = (index + 1) % tabs.length;
      if (e.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
      if (e.key === 'Home') next = 0;
      if (e.key === 'End') next = tabs.length - 1;
      if (next !== undefined) { e.preventDefault(); panel(tabs[next].dataset.editorTab, true); }
    });
  });
  dialog.querySelectorAll('[data-ir-tab]').forEach(b => b.addEventListener('click', () => panel(b.dataset.irTab, true)));

  /* ---------- Lista "Lo que vas a comprender" ---------- */
  const lista = $('listaAprenderas'), aprenderas = $('contenido-aprenderas');
  function sincronizarLista() { aprenderas.value = [...lista.querySelectorAll('input')].map(i => i.value.trim()).filter(Boolean).join('\n'); }
  function agregarIdea(texto = '', enfocar = false, despues = null) {
    const li = document.createElement('li');
    const input = document.createElement('input'); input.type = 'text'; input.maxLength = 300; input.value = texto; input.placeholder = 'Escribe una idea'; input.setAttribute('aria-label', 'Idea');
    const quitar = document.createElement('button'); quitar.type = 'button'; quitar.className = 'st-icono'; quitar.setAttribute('aria-label', 'Quitar idea'); quitar.textContent = '×';
    quitar.addEventListener('click', () => { const sig = li.nextElementSibling || li.previousElementSibling; li.remove(); sincronizarLista(); cambio(); (sig?.querySelector('input') || $('agregarIdea')).focus(); });
    input.addEventListener('keydown', e => {
      if (e.key === 'Enter') { e.preventDefault(); agregarIdea('', true, li); }
      if (e.key === 'Backspace' && !input.value && lista.children.length > 1) { e.preventDefault(); quitar.click(); }
    });
    li.append(input, quitar);
    if (despues) despues.after(li); else lista.append(li);
    if (enfocar) input.focus();
    sincronizarLista();
  }
  function pintarLista() { lista.replaceChildren(); const ideas = aprenderas.value.split('\n').map(s => s.trim()).filter(Boolean); (ideas.length ? ideas : ['']).forEach(t => agregarIdea(t)); }
  $('agregarIdea').addEventListener('click', () => agregarIdea('', true));

  /* ---------- Miniaturas ---------- */
  const mosaico = $('miniaturas');
  function opcionesMiniatura() {
    const actual = field('imagen').value.trim(), ops = [];
    const add = (src, etiqueta) => { if (src && imagenValida(src) && !ops.some(o => o.src === src)) ops.push({src, etiqueta}); };
    subidas.forEach(s => add(s, 'Subida'));
    add(miniaturaVimeo, 'De Vimeo');
    add(actual, 'Actual');
    if (contenidoCargado?.imagen) add(contenidoCargado.imagen, 'Anterior');
    return ops;
  }
  function pintarMiniaturas() {
    mosaico.querySelectorAll('.st-opcion-miniatura').forEach(n => n.remove());
    const actual = field('imagen').value.trim();
    opcionesMiniatura().forEach(o => {
      const b = document.createElement('button'); b.type = 'button'; b.className = 'st-opcion-miniatura'; b.setAttribute('role', 'radio');
      b.setAttribute('aria-checked', String(o.src === actual)); b.setAttribute('aria-label', `Usar miniatura: ${o.etiqueta}`);
      const img = document.createElement('img'); img.src = o.src; img.alt = ''; img.loading = 'lazy';
      img.addEventListener('error', () => b.classList.add('rota'));
      const tag = document.createElement('span'); tag.textContent = o.etiqueta;
      b.append(img, tag);
      b.addEventListener('click', () => { field('imagen').value = o.src; cambio(); });
      mosaico.append(b);
    });
  }
  const zona = $('zonaSubir'), archivo = $('archivoMiniatura');
  zona.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); archivo.click(); } });
  archivo.addEventListener('change', () => { if (archivo.files[0]) subirMiniatura(archivo.files[0]); archivo.value = ''; });
  ['dragenter', 'dragover'].forEach(ev => zona.addEventListener(ev, e => { e.preventDefault(); zona.classList.add('arrastrando'); }));
  ['dragleave', 'dragend'].forEach(ev => zona.addEventListener(ev, () => zona.classList.remove('arrastrando')));
  zona.addEventListener('drop', e => { e.preventDefault(); zona.classList.remove('arrastrando'); const f = [...(e.dataTransfer?.files || [])][0]; if (f) subirMiniatura(f); });
  // Evita que soltar una imagen fuera de la zona abra el archivo en la pestaña.
  dialog.addEventListener('dragover', e => { if ([...(e.dataTransfer?.types || [])].includes('Files')) e.preventDefault(); });
  dialog.addEventListener('drop', e => { if ([...(e.dataTransfer?.types || [])].includes('Files')) e.preventDefault(); });

  /** Recorta al centro en 16:9 y reduce a 1280 × 720 como máximo; entrega WebP (o JPG si el navegador no lo genera). */
  async function prepararImagen(file) {
    const bmp = await createImageBitmap(file); const anchoOriginal = bmp.width;
    if (bmp.width < 320 || bmp.height < 180) { bmp.close?.(); throw new Error('La imagen es muy pequeña. Usa una de al menos 640 × 360 px (ideal 1280 × 720).'); }
    let sw = bmp.width, sh = Math.round(sw * 9 / 16);
    if (sh > bmp.height) { sh = bmp.height; sw = Math.round(sh * 16 / 9); }
    const sx = Math.round((bmp.width - sw) / 2), sy = Math.round((bmp.height - sh) / 2);
    const w = Math.min(1280, sw), h = Math.round(w * 9 / 16);
    const canvas = document.createElement('canvas'); canvas.width = w; canvas.height = h;
    const ctx = canvas.getContext('2d'); ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(bmp, sx, sy, sw, sh, 0, 0, w, h); bmp.close?.();
    const blob = await new Promise(r => canvas.toBlob(r, 'image/webp', 0.86));
    if (blob && blob.type === 'image/webp') return {blob, nombre: 'miniatura.webp', pequena: anchoOriginal < 1280};
    const jpg = await new Promise(r => canvas.toBlob(r, 'image/jpeg', 0.88));
    if (!jpg) throw new Error('No se pudo procesar la imagen en este navegador.');
    return {blob: jpg, nombre: 'miniatura.jpg', pequena: anchoOriginal < 1280};
  }
  function subirConProgreso(data) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest(); const barra = zona.querySelector('.st-progreso span');
      xhr.open('POST', 'miniatura.php'); xhr.withCredentials = true; xhr.responseType = 'json';
      xhr.upload.addEventListener('progress', e => { if (e.lengthComputable) barra.style.width = Math.round(e.loaded * 100 / e.total) + '%'; });
      xhr.addEventListener('load', () => { const r = xhr.response; if (xhr.status === 200 && r && r.ok) resolve(r); else reject(new Error(r?.mensaje || 'No se pudo subir la imagen.')); });
      xhr.addEventListener('error', () => reject(new Error('Sin conexión. Vuelve a intentarlo.')));
      xhr.send(data);
    });
  }
  async function subirMiniatura(file) {
    const estado = $('estadoMiniatura');
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { estado.textContent = 'Usa una imagen JPG, PNG o WebP.'; return; }
    if (file.size > 20 * 1024 * 1024) { estado.textContent = 'La imagen pesa más de 20 MB. Usa una más ligera.'; return; }
    const seq = request; zona.classList.add('subiendo'); zona.querySelector('.st-progreso').hidden = false; zona.querySelector('.st-progreso span').style.width = '0%';
    estado.textContent = 'Preparando la imagen…';
    try {
      const img = await prepararImagen(file);
      estado.textContent = 'Subiendo…';
      const data = new FormData(); data.set('csrf', field('csrf').value); data.set('archivo', img.blob, img.nombre);
      const r = await subirConProgreso(data);
      if (seq !== request) return;
      subidas.unshift(r.url); field('imagen').value = r.url; cambio();
      estado.textContent = 'Miniatura subida y seleccionada.' + (img.pequena ? ' Tiene menos de 1280 px de ancho; puede verse borrosa en pantallas grandes.' : '');
    } catch (e) { if (seq === request) estado.textContent = e.message || 'No se pudo procesar la imagen.'; }
    finally { zona.classList.remove('subiendo'); zona.querySelector('.st-progreso').hidden = true; }
  }

  /* ---------- Vista previa, contadores, precio ---------- */
  function sincronizarDuracion() {
    const s = segundos(duracionTexto.value);
    duracionTexto.setCustomValidity(s === null ? 'Escribe la duración como h:mm:ss, por ejemplo 1:05:30.' : (valor('tipo') === 'unica' && s < 1 ? 'La visualización única requiere la duración del video.' : ''));
    if (s !== null) field('duracion_seg').value = String(s);
  }
  function stopVideo() { $('previewVideo').replaceChildren(); $('vistaPreviaVideo').hidden = !field('vimeo_id').value; }
  function update() {
    const tipo = valor('tipo'), publicado = valor('activo') === '1';
    const precio = Number(field('precio_mxn').value) || 0, anterior = Number(field('precio_anterior').value) || 0;
    dialog.querySelectorAll('[data-contador]').forEach(el => { const c = el.parentElement.querySelector('.st-cuenta'); if (c) c.textContent = `${el.value.length}/${el.dataset.contador}`; });
    sincronizarDuracion();
    const secs = Number(field('duracion_seg').value);
    $('previewDuracion').textContent = secs > 0 ? hms(secs).replace(/^0:/, '') : ''; $('previewDuracion').hidden = !(secs > 0);
    $('editorEstado').textContent = publicado ? 'En catálogo' : 'Borrador';
    $('editorEstado').className = 'bo-chip ' + (publicado ? 'ok' : 'neutro');
    dialog.querySelector('[data-regla-ventana]').hidden = tipo !== 'ventana';
    dialog.querySelector('[data-regla-inicio]').hidden = tipo === 'permanente';
    dialog.querySelector('[data-regla-unica]').hidden = tipo !== 'unica';
    field('horas_ventana').min = tipo === 'ventana' ? '1' : '0';
    enlace.required = publicado || tipo === 'unica';
    enlace.setCustomValidity(enlace.value.trim() && !field('vimeo_id').value ? 'Pega un enlace de Vimeo válido, por ejemplo https://vimeo.com/123456789.'
      : (enlace.required && !field('vimeo_id').value ? 'Vincula el video de Vimeo para publicar o para la visualización única.' : ''));
    field('precio_mxn').setCustomValidity(publicado && precio <= 0 ? 'Para mostrarlo en el catálogo, define un precio mayor que cero.' : '');
    const oferta = $('mostrarOferta').checked;
    field('precio_anterior').setCustomValidity(oferta && anterior <= precio ? 'El precio anterior debe ser mayor que el precio actual.' : '');
    // Precio y tarjeta del catálogo
    $('desglosePrecio').textContent = precio > 0 ? `Subtotal ${moneda.format(precio / 1.16)} + IVA ${moneda.format(precio - precio / 1.16)}` : 'Sin precio: solo puede quedar como borrador.';
    const pct = oferta && anterior > precio && precio > 0 ? Math.round((1 - precio / anterior) * 100) : 0;
    $('porcentajeOferta').textContent = pct ? `−${pct}%` : '';
    $('previewPrecio').textContent = precio > 0 ? moneda.format(precio) + ' MXN' : 'Sin precio';
    $('previewAcceso').textContent = {ventana: `${field('horas_ventana').value || 0} h de acceso`, permanente: 'Acceso permanente', unica: 'Visualización única'}[tipo] || '';
    $('tarjetaTitulo').textContent = field('titulo').value.trim() || 'Título del contenido';
    $('tarjetaCategoria').textContent = config.categorias[valor('categoria')] || '';
    $('tarjetaPrecio').textContent = precio > 0 ? moneda.format(precio) : 'Sin precio';
    $('tarjetaAnterior').textContent = pct ? moneda.format(anterior) : '';
    $('tarjetaDescuento').textContent = pct ? `−${pct}%` : '';
    // Imagen y video
    const raw = field('imagen').value.trim(), src = imagenValida(raw) ? raw : fallback;
    if ($('previewMiniatura').getAttribute('src') !== src) $('previewMiniatura').src = src;
    if ($('tarjetaImagen').getAttribute('src') !== src) $('tarjetaImagen').src = src;
    const id = field('vimeo_id').value, key = id + ':' + field('vimeo_hash').value;
    if (key !== videoKey) { videoKey = key; stopVideo(); }
    $('sinVideo').hidden = !!id; $('previewMiniatura').hidden = !id && !raw;
    $('copiarEnlace').disabled = !id; $('vistaPreviaVideo').disabled = !/^\d{1,32}$/.test(id);
    const slug = contenidoCargado?.slug; $('enlaceTiendaDato').hidden = !slug;
    if (slug) { $('enlaceTienda').href = '/producto/' + encodeURIComponent(slug); $('enlaceTienda').textContent = '/producto/' + slug; }
    pintarMiniaturas();
    const cambios = cambiosHechos();
    $('deshacerCambios').disabled = !cambios.length || busy;
    $('editorGuardado').textContent = cambios.length ? `Cambios sin guardar: ${cambios.join(', ')}.` : 'Sin cambios.';
  }
  $('previewMiniatura').addEventListener('error', () => { if (!$('previewMiniatura').src.endsWith(fallback)) $('previewMiniatura').src = fallback; });
  $('tarjetaImagen').addEventListener('error', () => { if (!$('tarjetaImagen').src.endsWith(fallback)) $('tarjetaImagen').src = fallback; });
  $('mostrarOferta').addEventListener('change', () => {
    $('bloqueOferta').hidden = !$('mostrarOferta').checked;
    const ant = field('precio_anterior');
    if (!$('mostrarOferta').checked) { if (ant.value) ant.dataset.recordado = ant.value; ant.value = ''; }
    else { if (!ant.value && ant.dataset.recordado) ant.value = ant.dataset.recordado; ant.focus(); }
  });

  /* ---------- Cambios, motivo automático y deshacer ---------- */
  const NOMBRES = {titulo: 'título', resumen: 'resumen', descripcion: 'descripción', imagen: 'miniatura', categoria: 'categoría', tema: 'tema',
    aprenderas: 'ideas clave', incluye: 'material incluido', trailer_vimeo: 'muestra gratuita', precio_mxn: 'precio', precio_anterior: 'oferta',
    activo: 'visibilidad', destacado: 'destacado', vimeo_id: 'video', vimeo_hash: 'video', duracion_seg: 'duración', producto_id: 'ID',
    tipo: 'tipo de acceso', horas_ventana: 'reglas de acceso', cuenta_desde: 'reglas de acceso', dias_para_empezar: 'reglas de acceso',
    umbral_pct: 'reglas de acceso', retroceso_seg: 'reglas de acceso', pausa_max_min: 'reglas de acceso', reanudaciones_max: 'reglas de acceso',
    gracia_min: 'reglas de acceso', permitir_adelantar: 'reglas de acceso'};
  const instantanea = () => Object.fromEntries(Object.keys(NOMBRES).map(n => [n, n.startsWith('precio') && valor(n) !== '' ? Number(valor(n)).toFixed(2) : valor(n).trim()]));
  function cambiosHechos() {
    if (!original) return [];
    const ahora = instantanea(), nombres = [];
    for (const n of Object.keys(NOMBRES)) if (ahora[n] !== original[n] && !nombres.includes(NOMBRES[n])) nombres.push(NOMBRES[n]);
    return nombres;
  }
  function motivoSugerido() {
    if (!field('producto_id_original').value) return 'Alta de contenido';
    const c = cambiosHechos(); if (!c.length) return 'Revisión sin cambios';
    const ult = c.length > 1 ? c.slice(0, -1).join(', ') + ' y ' + c[c.length - 1] : c[0];
    const t = 'Actualización de ' + ult; return t.length > 120 ? t.slice(0, 117) + '…' : t;
  }
  function cambio() { update(); }
  ['input', 'change'].forEach(event => form.addEventListener(event, e => { if (e.target === aprenderas) return; if (e.target.closest('#listaAprenderas')) sincronizarLista(); cambio(); }));
  $('deshacerCambios').addEventListener('click', () => { if (contenidoCargado) { llenar(contenidoCargado); $('estadoMiniatura').textContent = ''; update(); } });

  /* ---------- Abrir, llenar y cerrar ---------- */
  function error(message) { $('editorError').textContent = message; $('editorError').hidden = false; $('editorError').focus(); }
  function close(force = false) {
    if (busy) return;
    if (cambiosHechos().length && !force) { $('editorConfirmar').hidden = true; $('editorDescartar').hidden = false; $('seguirEditando').focus(); return; }
    request++; stopVideo(); dialog.close(); document.body.classList.remove('bo-editor-abierto'); opener?.focus();
  }
  dialog.addEventListener('cancel', e => { e.preventDefault(); close(); });
  dialog.addEventListener('click', e => { if (e.target === dialog) { const r = dialog.getBoundingClientRect(); if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) close(); } });
  dialog.querySelectorAll('[data-cerrar-editor]').forEach(b => b.addEventListener('click', () => close()));
  $('seguirEditando').addEventListener('click', () => { $('editorDescartar').hidden = true; tabs.find(t => t.getAttribute('aria-selected') === 'true')?.focus(); });
  $('descartarCambios').addEventListener('click', () => close(true));
  async function json(url, options = {}) {
    const response = await fetch(url, {credentials: 'same-origin', ...options});
    let data;
    try { data = await response.json(); } catch (_) { throw new Error('No se pudo leer la respuesta. Revisa tu sesión y vuelve a intentar.'); }
    if (!response.ok || !data.ok) throw new Error(data.mensaje || 'No se pudo completar la operación.');
    return data;
  }
  function setBusy(value) {
    busy = value; form.setAttribute('aria-busy', String(value));
    $('guardarContenido').disabled = value; $('confirmarGuardar').disabled = value; $('confirmarGuardar').textContent = value ? 'Guardando…' : 'Guardar cambios';
  }
  function llenar(content) {
    for (const [name, value] of Object.entries(content)) {
      const element = field(name);
      if (!element || name === 'csrf' || name === 'motivo' || name === 'accion') continue;
      if (element instanceof RadioNodeList) element.value = String(value ?? '');
      else if (element.type === 'checkbox') element.checked = Number(value) === 1;
      else element.value = value ?? '';
    }
    if (!valor('categoria')) field('categoria').value = 'conferencias';
    const precio = Number(content.precio_mxn) || 0, anterior = Number(content.precio_anterior) || 0;
    $('mostrarOferta').checked = anterior > 0; $('bloqueOferta').hidden = !(anterior > 0);
    if (precio > 0) field('precio_mxn').value = precio.toFixed(2);
    if (anterior > 0) field('precio_anterior').value = anterior.toFixed(2);
    enlace.value = enlaceDe(field('vimeo_id').value, field('vimeo_hash').value);
    duracionTexto.value = Number(field('duracion_seg').value) > 0 ? hms(field('duracion_seg').value) : '';
    pintarLista();
  }
  async function open(id, trigger) {
    if (dialog.open || busy) return;
    opener = trigger || document.activeElement;
    form.reset(); original = null; contenidoCargado = null; subidas = []; miniaturaVimeo = '';
    $('editorError').hidden = true; $('editorDescartar').hidden = true; $('editorConfirmar').hidden = true; $('estadoMiniatura').textContent = '';
    $('estadoVimeo').textContent = 'Copia el enlace desde Vimeo (botón Compartir) y pégalo aquí: título, duración y miniatura se llenan solos.';
    privacidad(null); consulta++;
    panel('detalles'); dialog.showModal(); document.body.classList.add('bo-editor-abierto');
    const seq = ++request;
    $('guardarContenido').disabled = true;
    try {
      const content = id ? (await json(`contenido.php?producto=${encodeURIComponent(id)}`)).contenido : config.nuevo;
      if (seq !== request || !dialog.open) return;
      contenidoCargado = content;
      llenar(content);
      field('producto_id_original').value = id || '';
      field('producto_id').readOnly = !!id;
      videoPrevio = field('vimeo_id').value; ultimaConsulta = '';
      $('editorTitulo').textContent = id ? 'Detalles del contenido' : 'Agregar contenido';
      original = instantanea();
      update(); $('guardarContenido').disabled = false; field('titulo').focus();
      if (field('vimeo_id').value) consultarVimeo(false); else privacidad(null);
    } catch (e) { if (seq === request) error(e.message); }
  }
  $('nuevoContenido')?.addEventListener('click', e => open(0, e.currentTarget));
  document.querySelectorAll('[data-editar-contenido]').forEach(b => b.addEventListener('click', () => open(Number(b.dataset.editarContenido), b)));

  /* ---------- Video: vista previa, copiar, Vimeo ---------- */
  $('vistaPreviaVideo').addEventListener('click', () => {
    const id = field('vimeo_id').value, hash = field('vimeo_hash').value;
    if (!/^\d{1,32}$/.test(id) || !/^[a-zA-Z0-9]*$/.test(hash)) return;
    const iframe = document.createElement('iframe');
    iframe.title = 'Vista previa del video'; iframe.allow = 'autoplay; fullscreen; picture-in-picture'; iframe.allowFullscreen = true;
    iframe.src = `https://player.vimeo.com/video/${id}?dnt=1&autoplay=1${hash ? '&h=' + encodeURIComponent(hash) : ''}`;
    $('previewVideo').replaceChildren(iframe); $('vistaPreviaVideo').hidden = true;
  });
  $('copiarEnlace').addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(enlace.value); $('estadoVimeo').textContent = 'Enlace copiado.'; } catch (_) { enlace.select(); }
  });
  const niveles = {ok: ['✓', 'Correcto'], aviso: ['!', 'Revisar'], falla: ['✕', 'Corregir']};
  const nombresPriv = {oculto: 'Oculto de Vimeo', dominio: 'Solo en el sitio', descargas: 'Descargas de Vimeo'};
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
      const icono = document.createElement('span'); icono.className = 'bo-vimeo-icono'; icono.textContent = niveles[ind.nivel][0]; icono.setAttribute('aria-hidden', 'true');
      const texto = document.createElement('span'); const b = document.createElement('b'); b.textContent = nombresPriv[clave] || clave;
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
  function aplicarDatosVimeo(video, llenarCampos) {
    const llenados = [];
    if (video.miniatura) miniaturaVimeo = video.miniatura;
    if (!llenarCampos) return llenados;
    if (video.titulo && !field('titulo').value.trim()) { field('titulo').value = video.titulo; llenados.push('título'); }
    if (video.duracion_seg > 0) { duracionTexto.value = hms(video.duracion_seg); field('duracion_seg').value = String(video.duracion_seg); llenados.push('duración'); }
    if (video.miniatura && (!field('imagen').value.trim() || video.id !== videoPrevio)) { field('imagen').value = video.miniatura; llenados.push('miniatura'); }
    return llenados;
  }
  async function consultarVimeo(llenarCampos) {
    if (!enlace.value.trim()) { field('vimeo_id').value = ''; field('vimeo_hash').value = ''; miniaturaVimeo = ''; privacidad(null); update(); return; }
    const v = analizarEnlace(enlace.value);
    if (!v) {
      $('estadoVimeo').textContent = 'No se reconoció un video de Vimeo. Pega el enlace completo, por ejemplo https://vimeo.com/123456789.';
      field('vimeo_id').value = ''; field('vimeo_hash').value = ''; privacidad(null); update(); return;
    }
    if (llenarCampos) { const anterior = field('vimeo_id').value; field('vimeo_id').value = v.id; if (v.hash || v.id !== anterior) field('vimeo_hash').value = v.hash; }
    update();
    const clave = `${v.id}:${v.hash}:${llenarCampos}`; if (clave === ultimaConsulta) return; ultimaConsulta = clave;
    const seq = request, n = ++consulta, boton = $('consultarVimeo'); boton.disabled = true; dialog.querySelector('.st-video').classList.add('cargando');
    $('estadoVimeo').textContent = 'Consultando Vimeo…'; privacidad(null, null, 'Revisando la privacidad en Vimeo…');
    const data = new FormData(); data.set('accion', 'consultar'); data.set('csrf', field('csrf').value); data.set('enlace', enlace.value.trim());
    try {
      const r = await json('vimeo-api.php', {method: 'POST', body: data});
      if (seq !== request || n !== consulta) return;
      const video = r.video;
      if (llenarCampos && video.hash && !field('vimeo_hash').value) field('vimeo_hash').value = video.hash;
      const llenados = aplicarDatosVimeo(video, llenarCampos);
      if (llenarCampos) enlace.value = enlaceDe(field('vimeo_id').value, field('vimeo_hash').value);
      videoPrevio = video.id; update(); privacidad(video, r.cuenta);
      $('estadoVimeo').textContent = `Vinculado: «${video.titulo}».` + (llenados.length ? ` Se llenó: ${llenados.join(', ')}.` : '');
    } catch (e) {
      if (seq !== request || n !== consulta) return;
      ultimaConsulta = '';
      $('estadoVimeo').textContent = e.message + (llenarCampos ? ' El video quedó vinculado; puedes escribir la duración a mano.' : '');
      privacidad(null, null, 'No se pudo revisar la privacidad en este momento.');
    } finally { if (n === consulta) { boton.disabled = false; dialog.querySelector('.st-video').classList.remove('cargando'); } }
  }
  $('consultarVimeo').addEventListener('click', () => { ultimaConsulta = ''; consultarVimeo(true); });
  enlace.addEventListener('paste', () => setTimeout(() => consultarVimeo(true), 0));
  enlace.addEventListener('change', () => consultarVimeo(true));
  enlace.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); ultimaConsulta = ''; consultarVimeo(true); } });
  // Soltar un enlace de Vimeo sobre el video también lo vincula.
  const vistaVideo = $('videoVista');
  vistaVideo.addEventListener('dragover', e => { if ([...(e.dataTransfer?.types || [])].some(t => t === 'text/uri-list' || t === 'text/plain')) { e.preventDefault(); vistaVideo.classList.add('arrastrando'); } });
  vistaVideo.addEventListener('dragleave', () => vistaVideo.classList.remove('arrastrando'));
  vistaVideo.addEventListener('drop', e => {
    vistaVideo.classList.remove('arrastrando');
    const t = e.dataTransfer?.getData('text/uri-list') || e.dataTransfer?.getData('text/plain') || '';
    if (t && analizarEnlace(t.split('\n')[0])) { e.preventDefault(); enlace.value = t.split('\n')[0].trim(); ultimaConsulta = ''; consultarVimeo(true); }
  });
  duracionTexto.addEventListener('blur', () => { const s = segundos(duracionTexto.value); if (s !== null && duracionTexto.value.trim()) duracionTexto.value = hms(s); });
  field('trailer_vimeo').addEventListener('change', () => { const v = analizarEnlace(field('trailer_vimeo').value); if (v) field('trailer_vimeo').value = v.id; });
  $('aplicarVimeo').addEventListener('click', async () => {
    const id = field('vimeo_id').value, motivo = $('vimeo-motivo').value.trim();
    if (!id) return;
    if (!motivo) { $('aplicarNota').textContent = 'Escribe el motivo del cambio.'; $('vimeo-motivo').focus(); return; }
    const boton = $('aplicarVimeo'), seq = request; boton.disabled = true; $('aplicarNota').textContent = 'Aplicando en Vimeo…';
    const data = new FormData(); data.set('accion', 'aplicar'); data.set('csrf', field('csrf').value); data.set('vimeo_id', id); data.set('motivo', motivo);
    if (field('producto_id_original').value) data.set('producto_id', field('producto_id_original').value);
    try {
      const r = await json('vimeo-api.php', {method: 'POST', body: data});
      if (seq !== request) return;
      privacidad(r.video, r.cuenta); $('privacidadMensaje').textContent = 'Configuración aplicada en Vimeo y registrada en la bitácora.';
    } catch (e) { if (seq === request) { $('aplicarNota').textContent = e.message; boton.disabled = false; } }
  });

  /* ---------- Guardar: validar, pedir motivo (sugerido) y enviar ---------- */
  form.addEventListener('submit', e => {
    e.preventDefault(); if (busy) return;
    const muestra = analizarEnlace(field('trailer_vimeo').value); if (muestra) field('trailer_vimeo').value = muestra.id;
    sincronizarLista(); update();
    const invalid = [...form.elements].find(el => el.willValidate && !el.validity.valid);
    if (invalid) {
      const section = invalid.closest('[data-editor-panel]'); if (section) panel(section.dataset.editorPanel);
      if (invalid.type === 'radio') invalid.closest('[role=radiogroup]')?.scrollIntoView({block: 'center'});
      invalid.reportValidity(); invalid.focus(); return;
    }
    $('editorDescartar').hidden = true; $('editorConfirmar').hidden = false;
    $('motivoCambio').value = motivoSugerido(); $('motivoCambio').focus(); $('motivoCambio').select();
  });
  $('volverEditar').addEventListener('click', () => { $('editorConfirmar').hidden = true; $('guardarContenido').focus(); });
  $('motivoCambio').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); $('confirmarGuardar').click(); } if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); $('volverEditar').click(); } });
  $('confirmarGuardar').addEventListener('click', async () => {
    if (busy) return;
    const motivo = $('motivoCambio').value.trim();
    if (!motivo) { $('motivoCambio').setCustomValidity('Escribe el motivo del cambio.'); $('motivoCambio').reportValidity(); $('motivoCambio').setCustomValidity(''); return; }
    field('motivo').value = motivo;
    $('editorError').hidden = true; setBusy(true);
    try {
      await json('contenido.php', {method: 'POST', body: new FormData(form)});
      original = instantanea(); setBusy(false); close(true);
      const url = new URL(location.href); url.searchParams.delete('producto'); url.searchParams.delete('nuevo'); location.replace(url.href);
    } catch (err) { setBusy(false); $('editorConfirmar').hidden = true; error(err.message); }
  });
  window.addEventListener('beforeunload', e => { if (dialog.open && cambiosHechos().length) { e.preventDefault(); e.returnValue = ''; } });
  if (config.abrir.producto || config.abrir.nuevo) open(config.abrir.producto || 0);
})();
