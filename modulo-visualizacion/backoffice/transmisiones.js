/* Modal de transmisiones: detección de plataforma, vista previa, acceso y portada con arrastrar y soltar. El servidor valida todo al guardar. */
(() => {
  'use strict';
  const dialog = document.getElementById('txEditor');
  if (!dialog) return;
  const $ = id => document.getElementById(id);
  const form = $('txForm'), url = $('tx-url'), img = $('tx-img');
  const csrf = form.elements.namedItem('csrf').value;
  const acceso = () => (form.querySelector('[name=acceso]:checked') || {}).value || 'publico';
  const LOGOS = {
    YouTube: '<svg viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="4" fill="#ff0033"/><path d="m10 9 5 3-5 3z" fill="#fff"/></svg>',
    Facebook: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" fill="#0866ff"/><path d="M13.2 19v-5.6h1.9l.3-2.2h-2.2V9.8c0-.6.2-1.1 1.1-1.1h1.2v-2c-.6-.1-1.2-.1-1.8-.1-1.8 0-3 1.1-3 3.1v1.6H8.8v2.2h1.9V19z" fill="#fff"/></svg>',
    Vimeo: '<svg viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="20" rx="5" fill="#17d5ff"/><path d="M17.5 8.6c-.1 1.1-.8 2.6-2.3 4.5-1.5 2-2.8 3-3.9 3-.7 0-1.2-.6-1.7-1.9l-.9-3.4c-.3-1.2-.7-1.9-1.1-1.9-.1 0-.4.2-.9.6l-.6-.7 1.6-1.4c.7-.6 1.3-1 1.7-1 .9-.1 1.4.5 1.6 1.8.2 1.4.4 2.3.5 2.6.3 1.2.6 1.8.9 1.8.2 0 .6-.4 1.1-1.2.5-.8.8-1.4.8-1.8.1-.7-.2-1-.8-1-.3 0-.6.1-.9.2.6-1.9 1.7-2.8 3.3-2.8 1.2 0 1.8.8 1.7 2.3z" fill="#fff"/></svg>',
    Zoom: '<svg viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="20" rx="6" fill="#0b5cff"/><rect x="5.5" y="8.5" width="9" height="7" rx="1.5" fill="#fff"/><path d="m15 11 3.5-2.2v6.4L15 13z" fill="#fff"/></svg>'
  };
  function plataforma(u) {
    u = (u || '').toLowerCase();
    if (/youtu\.?be/.test(u)) return 'YouTube'; if (/facebook\.com|fb\.watch/.test(u)) return 'Facebook';
    if (/vimeo\.com/.test(u)) return 'Vimeo'; if (/zoom\.us/.test(u)) return 'Zoom'; return '';
  }
  const fecha = new Intl.DateTimeFormat('es-MX', {weekday: 'long', day: 'numeric', month: 'long', hour: 'numeric', minute: '2-digit'});
  function actualizar() {
    const p = plataforma(url.value), a = acceso(), vacio = !url.value.trim();
    $('txLogo').innerHTML = LOGOS[p] || '';
    $('txLogo').hidden = !p;
    $('txDetectado').textContent = vacio ? 'Cópialo desde el botón Compartir de la plataforma.' : (p ? `${p} detectado. Se valida al guardar.` : 'No reconozco el enlace. Usa uno de YouTube, Facebook, Vimeo o Zoom.');
    $('txDetectado').classList.toggle('error', !vacio && !p);
    url.setCustomValidity(!vacio && !p ? 'Usa un enlace de YouTube, Facebook, Vimeo o Zoom.' : '');
    $('txPlataformaChip').hidden = !p; $('txPlataformaChip').textContent = p ? 'Vía ' + p : '';
    $('txProducto').hidden = a !== 'compradores';
    $('tx-prod').required = a === 'compradores';
    const aviso = p === 'Facebook' && a !== 'publico' ? 'Facebook no permite limitar quién la ve. Elige "Pública" o transmite por YouTube como "No listado".'
      : (p === 'YouTube' && a !== 'publico' ? 'Marca el video en YouTube como "No listado" para que solo se vea desde el sitio.'
      : (p === 'Zoom' ? 'El enlace para unirse solo se muestra a quien tiene acceso.' : ''));
    $('txAvisoAcceso').textContent = aviso; $('txAvisoAcceso').hidden = !aviso;
    $('txAvisoAcceso').classList.toggle('error', p === 'Facebook' && a !== 'publico');
    $('txChat').hidden = p && p !== 'YouTube';
    // Vista previa
    $('txVistaTitulo').textContent = form.elements.namedItem('titulo').value.trim() || 'Título de la transmisión';
    const ini = form.elements.namedItem('inicio').value, dur = form.elements.namedItem('duracion_min');
    $('txVistaCuando').textContent = ini ? `${fecha.format(new Date(ini))} · ${dur.selectedOptions[0]?.textContent || ''}` : '';
    const src = img.value || '/portada/img/conferencias.webp';
    if ($('txVistaImagen').getAttribute('src') !== src) $('txVistaImagen').src = src;
    $('txPortadas').querySelectorAll('.st-opcion-miniatura').forEach(b => b.setAttribute('aria-checked', String(b.dataset.src === img.value)));
    dialog.querySelectorAll('[data-contador]').forEach(el => { const c = el.parentElement.querySelector('.st-cuenta'); if (c) c.textContent = `${el.value.length}/${el.dataset.contador}`; });
  }
  ['input', 'change'].forEach(ev => form.addEventListener(ev, actualizar));

  /* Portada: elegir una existente o subir (se recorta a 16:9 en el navegador) */
  function enlazarOpcion(b) { b.addEventListener('click', () => { img.value = b.dataset.src; actualizar(); }); }
  $('txPortadas').querySelectorAll('.st-opcion-miniatura').forEach(enlazarOpcion);
  const zona = $('txSubir'), archivo = $('txArchivo'), estado = $('txEstadoImagen');
  async function preparar(file) {
    const bmp = await createImageBitmap(file), ancho = bmp.width;
    if (bmp.width < 320 || bmp.height < 180) { bmp.close?.(); throw new Error('La imagen es muy pequeña. Usa una de al menos 640 × 360 px.'); }
    let sw = bmp.width, sh = Math.round(sw * 9 / 16);
    if (sh > bmp.height) { sh = bmp.height; sw = Math.round(sh * 16 / 9); }
    const w = Math.min(1280, sw), h = Math.round(w * 9 / 16), c = document.createElement('canvas'); c.width = w; c.height = h;
    const ctx = c.getContext('2d'); ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(bmp, Math.round((bmp.width - sw) / 2), Math.round((bmp.height - sh) / 2), sw, sh, 0, 0, w, h); bmp.close?.();
    let blob = await new Promise(r => c.toBlob(r, 'image/webp', 0.86)), nombre = 'portada.webp';
    if (!blob || blob.type !== 'image/webp') { blob = await new Promise(r => c.toBlob(r, 'image/jpeg', 0.88)); nombre = 'portada.jpg'; }
    if (!blob) throw new Error('No se pudo procesar la imagen en este navegador.');
    return {blob, nombre, pequena: ancho < 1280};
  }
  function subir(data) {
    return new Promise((ok, mal) => {
      const xhr = new XMLHttpRequest(), barra = zona.querySelector('.st-progreso span');
      xhr.open('POST', 'miniatura.php'); xhr.responseType = 'json';
      xhr.upload.addEventListener('progress', e => { if (e.lengthComputable) barra.style.width = Math.round(e.loaded * 100 / e.total) + '%'; });
      xhr.addEventListener('load', () => { const r = xhr.response; if (xhr.status === 200 && r && r.ok) ok(r); else mal(new Error(r?.mensaje || 'No se pudo subir la imagen.')); });
      xhr.addEventListener('error', () => mal(new Error('Sin conexión. Vuelve a intentarlo.')));
      xhr.send(data);
    });
  }
  async function procesar(file) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { estado.textContent = 'Usa una imagen JPG, PNG o WebP.'; return; }
    if (file.size > 20 * 1024 * 1024) { estado.textContent = 'La imagen pesa más de 20 MB. Usa una más ligera.'; return; }
    zona.classList.add('subiendo'); zona.querySelector('.st-progreso').hidden = false; zona.querySelector('.st-progreso span').style.width = '0%';
    estado.textContent = 'Preparando la imagen…';
    try {
      const p = await preparar(file); estado.textContent = 'Subiendo…';
      const data = new FormData(); data.set('csrf', csrf); data.set('archivo', p.blob, p.nombre);
      const r = await subir(data);
      const b = document.createElement('button'); b.type = 'button'; b.className = 'st-opcion-miniatura'; b.setAttribute('role', 'radio'); b.dataset.src = r.url;
      const im = document.createElement('img'); im.src = r.url; im.alt = ''; const et = document.createElement('span'); et.textContent = 'Subida';
      b.append(im, et); zona.after(b); enlazarOpcion(b);
      img.value = r.url; actualizar();
      estado.textContent = 'Imagen subida y seleccionada.' + (p.pequena ? ' Tiene menos de 1280 px de ancho; puede verse borrosa en pantallas grandes.' : '');
    } catch (e) { estado.textContent = e.message || 'No se pudo procesar la imagen.'; }
    finally { zona.classList.remove('subiendo'); zona.querySelector('.st-progreso').hidden = true; }
  }
  zona.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); archivo.click(); } });
  archivo.addEventListener('change', () => { if (archivo.files[0]) procesar(archivo.files[0]); archivo.value = ''; });
  ['dragenter', 'dragover'].forEach(ev => zona.addEventListener(ev, e => { e.preventDefault(); zona.classList.add('arrastrando'); }));
  ['dragleave', 'dragend'].forEach(ev => zona.addEventListener(ev, () => zona.classList.remove('arrastrando')));
  zona.addEventListener('drop', e => { e.preventDefault(); zona.classList.remove('arrastrando'); const f = [...(e.dataTransfer?.files || [])][0]; if (f) procesar(f); });
  dialog.addEventListener('dragover', e => { if ([...(e.dataTransfer?.types || [])].includes('Files')) e.preventDefault(); });
  dialog.addEventListener('drop', e => { if ([...(e.dataTransfer?.types || [])].includes('Files')) e.preventDefault(); });

  /* Abrir, cerrar y validar */
  let cambiado = false;
  form.addEventListener('input', () => { cambiado = true; });
  function cerrar() { if (cambiado && !confirmarSalida()) return; location.href = 'transmisiones.php'; }
  function confirmarSalida() { return window.confirm('Hay cambios sin guardar. ¿Quieres descartarlos?'); }
  dialog.addEventListener('cancel', e => { e.preventDefault(); cerrar(); });
  dialog.querySelectorAll('[data-cerrar-tx], .tx-pie a').forEach(a => a.addEventListener('click', e => { e.preventDefault(); cerrar(); }));
  dialog.addEventListener('click', e => { if (e.target === dialog) { const r = dialog.getBoundingClientRect(); if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) cerrar(); } });
  form.addEventListener('submit', e => {
    actualizar();
    const malo = [...form.elements].find(el => el.willValidate && !el.validity.valid);
    if (malo) { e.preventDefault(); malo.reportValidity(); malo.focus(); return; }
    cambiado = false;
    form.querySelectorAll('[type=submit]').forEach(b => { b.disabled = true; b.textContent = 'Guardando…'; });
  });
  document.body.classList.add('bo-editor-abierto');
  dialog.showModal(); actualizar();
  (($('txError') && $('txError')) || form.elements.namedItem('titulo')).focus();
})();
