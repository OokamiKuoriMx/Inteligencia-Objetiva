/*
 * Inteligencia Objetiva — Tema 2.0
 * 1) Pega en <head>, ANTES de cualquier CSS, el snippet de tema/head-snippet.html (evita parpadeo).
 * 2) Carga este archivo al final del <body>:
 *      <script src="/tema/io-theme.js?v=2.0.2" defer></script>
 * No depende de jQuery. Reutiliza los modales de acceso existentes al volver desde el carrito.
 */
(function () {
  'use strict';
  var KEY = 'io-theme';
  var root = document.documentElement;
  var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

  function guardado() { try { return localStorage.getItem(KEY); } catch (e) { return null; } }
  function guardar(v) { try { localStorage.setItem(KEY, v); } catch (e) {} }
  function sistema() { return mq && mq.matches ? 'dark' : 'light'; }
  function actual() { return root.getAttribute('data-io-theme') || 'light'; }

  function aplicar(tema) {
    root.setAttribute('data-io-theme', tema);
    var meta = document.querySelector('meta[name="theme-color"]');
    if (!meta) { meta = document.createElement('meta'); meta.name = 'theme-color'; document.head.appendChild(meta); }
    meta.content = tema === 'dark' ? '#0e0f12' : '#ffffff';
    var label = tema === 'dark' ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro';
    Array.prototype.forEach.call(document.querySelectorAll('.io-theme-toggle'), function (btn) {
      btn.setAttribute('aria-pressed', tema === 'dark' ? 'true' : 'false');
      btn.setAttribute('aria-label', label);
      btn.title = label;
    });
  }

  // Sincroniza la preferencia con el perfil (opcional). Define window.IO_THEME_SYNC_URL para activarlo.
  function sincronizar(tema) {
    if (!window.IO_THEME_SYNC_URL || !window.fetch) return;
    fetch(window.IO_THEME_SYNC_URL, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ tema: tema })
    }).catch(function () {});
  }

  // El sitio tiene dos encabezados (escritorio y móvil): un botón en cada uno.
  function crearBotones() {
    var headers = document.querySelectorAll('header');
    if (!headers.length) return; // páginas sin encabezado (p. ej. el reproductor): sin botón
    Array.prototype.forEach.call(headers, crearBoton);
    aplicar(actual());
  }

  function crearBoton(header) {
    if (header.querySelector('.io-theme-toggle')) return;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'io-theme-toggle';
    btn.innerHTML =
      '<svg class="io-ico-luna" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>' +
      '<svg class="io-ico-sol" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>';
    btn.addEventListener('click', function () {
      var nuevo = actual() === 'dark' ? 'light' : 'dark';
      aplicar(nuevo); guardar(nuevo); sincronizar(nuevo);
    });

    // Se coloca junto al botón "Ayuda" del menú; si no existe, antes del carrito; si no, al final del header.
    var ancla = header.querySelector('a[href*="ayuda"]') || header.querySelector('a[href*="carrito"]');
    if (ancla && ancla.parentNode) {
      var li = ancla.closest('li');
      if (li) { var nuevoLi = document.createElement('li'); nuevoLi.className = 'd-flex align-items-center'; nuevoLi.appendChild(btn); li.parentNode.insertBefore(nuevoLi, li.nextSibling); }
      else { ancla.parentNode.insertBefore(btn, ancla.nextSibling); }
    } else {
      (header.querySelector('.container') || header).appendChild(btn);
    }
  }

  // Productos con 0 reseñas: no mostrar 5 estrellas llenas.
  function corregirEstrellas() {
    var wrappers = document.querySelectorAll('.ec-stars-wrapper');
    Array.prototype.forEach.call(wrappers, function (w) {
      var cont = w.parentElement;
      if (!cont || cont.classList.contains('io-sin-resenas')) return;
      if (/\(\s*0\s*\)/.test(cont.textContent)) {
        cont.classList.add('io-sin-resenas');
        var label = document.createElement('span');
        label.className = 'io-sin-resenas-label';
        label.textContent = 'Sin reseñas aún';
        w.insertAdjacentElement('afterend', label);
      }
    });
  }

  // Banner de ofertas: botón para cerrarlo (se recuerda el resto del día) y aviso visual cuando quedan < 24 h.
  function mejorarBanner() {
    var cont = document.querySelector('.promociones-carrusel-container');
    if (!cont) return;
    var hoy = new Date().toISOString().slice(0, 10);
    var cerrado = null;
    try { cerrado = localStorage.getItem('io-promo-cerrado'); } catch (e) {}
    if (cerrado === hoy) { cont.classList.add('io-oculto'); return; }
    if (!cont.querySelector('.io-promo-cerrar')) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'io-promo-cerrar'; b.setAttribute('aria-label', 'Cerrar ofertas por hoy'); b.title = 'Cerrar por hoy';
      b.textContent = '×';
      b.addEventListener('click', function () { cont.classList.add('io-oculto'); try { localStorage.setItem('io-promo-cerrado', hoy); } catch (e) {} });
      cont.appendChild(b);
    }
    Array.prototype.forEach.call(cont.querySelectorAll('.anuncio-promo'), function (p) {
      var f = p.querySelector('.fechaPromo');
      var fin = f ? Date.parse(f.value.replace(' ', 'T')) : NaN;
      if (!isNaN(fin) && fin - Date.now() < 864e5) p.classList.add('io-urgente');
    });
  }

  // Fechas de vencimiento en la hora local del alumno + cuenta regresiva.
  function fechas() {
    var fmt = null;
    try { fmt = new Intl.DateTimeFormat('es-MX', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short' }); } catch (e) {}
    Array.prototype.forEach.call(document.querySelectorAll('time.io-fecha[datetime]'), function (el) {
      if (el.dataset.ioListo || !fmt) return;
      var d = new Date(el.getAttribute('datetime')); if (isNaN(d)) return;
      el.textContent = fmt.format(d); el.title = 'Hora de tu dispositivo'; el.dataset.ioListo = '1';
    });
    Array.prototype.forEach.call(document.querySelectorAll('.io-restante[data-hasta]'), function (el) {
      var s = Math.floor((new Date(el.dataset.hasta) - new Date()) / 1000);
      if (isNaN(s)) return;
      if (s <= 0) { el.textContent = 'tiempo agotado'; el.classList.add('agotado'); return; }
      var d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60);
      el.textContent = 'te quedan ' + (d ? d + ' d ' : '') + (d || h ? h + ' h ' : '') + (m < 10 && (d || h) ? '0' : '') + m + ' min';
      el.classList.toggle('urgente', s < 6 * 3600);
    });
  }
  window.IOFechas = fechas;

  // Reutiliza el acceso del sitio y conserva únicamente destinos internos aprobados.
  function accesoConRegreso() {
    var destinos = {
      carrito: { url: '/io/tienda/carrito.php', texto: 'Volver a mi carrito', alias: '/carrito' },
      membresias: { url: '/io/tienda/membresias.php', texto: 'Volver a membresías', alias: '/membresias' },
      pedidos: { url: '/io/tienda/mis-pedidos.php', texto: 'Volver a mis pedidos y facturas' },
      biblioteca: { url: '/io/public/mi-biblioteca.php', texto: 'Volver a mi biblioteca' }
    };
    function valido(clave) { return Object.prototype.hasOwnProperty.call(destinos, clave); }
    var parametros = new URLSearchParams(window.location.search);
    var modo = parametros.get('io_acceso');
    var pedido = parametros.get('io_destino');
    var destino = valido(pedido) ? pedido : 'carrito';
    var solicitado = modo === 'login' || modo === 'registro';
    var pendiente = solicitado || valido(pedido);
    try {
      if (solicitado || valido(pedido)) sessionStorage.setItem('io-volver-destino', destino);
      var guardado = sessionStorage.getItem('io-volver-destino');
      if (!pendiente && valido(guardado)) { destino = guardado; pendiente = true; }
      if (!pendiente && sessionStorage.getItem('io-volver-carrito') === '1') { destino = 'carrito'; pendiente = true; }
      var actual = window.location.pathname.replace(/\/$/, '') || '/';
      if (actual === destinos[destino].url || actual === destinos[destino].alias) {
        sessionStorage.removeItem('io-volver-destino'); sessionStorage.removeItem('io-volver-carrito'); return;
      }
    } catch (e) {}
    if (!pendiente || window.location.pathname !== '/') return;
    var aviso = document.createElement('div');
    aviso.className = 'container py-3';
    aviso.setAttribute('role', 'status');
    var volver = document.createElement('a');
    volver.className = 'btn btn-primary';
    volver.href = destinos[destino].url;
    volver.textContent = destinos[destino].texto;
    aviso.appendChild(volver);
    var cont = document.querySelector('main') || document.body;
    cont.insertBefore(aviso, cont.firstChild);
    if (!solicitado) return;
    // Quita la orden de abrir el modal antes de que el login recargue la página.
    var url = new URL(window.location.href);
    url.searchParams.delete('io_acceso');
    // La clave validada permite conservar el regreso aun si el navegador bloquea sessionStorage.
    url.searchParams.set('io_destino', destino);
    window.history.replaceState(null, '', url.pathname + url.search + url.hash);
    var modal = document.getElementById(modo);
    if (modal && window.bootstrap && window.bootstrap.Modal) {
      if (modo === 'login' && typeof window.recordar === 'function') window.recordar();
      window.bootstrap.Modal.getOrCreateInstance(modal).show();
    } else {
      var enlace = document.querySelector('[data-bs-target="#' + modo + '"]');
      if (enlace) enlace.click();
    }
  }

  // Aviso "En vivo ahora" en todas las páginas cuando hay una transmisión al aire.
  // Para desactivarlo en una página: window.IO_EN_VIVO_API = false;
  function avisoEnVivo() {
    var api = window.IO_EN_VIVO_API === undefined ? '/io/api/transmisiones.php?accion=ahora' : window.IO_EN_VIVO_API;
    if (!api || !window.fetch || /^\/en-vivo/.test(location.pathname) || document.querySelector('.io-envivo-aviso')) return;
    function pintar(d) {
      if (!d || !d.en_vivo || !/^\/en-vivo\?t=[\w%-]+$/.test(d.url || '')) return;
      var a = document.createElement('a');
      a.className = 'io-envivo-aviso'; a.href = d.url;
      var b = document.createElement('b'); b.textContent = 'En vivo';
      var t = document.createElement('span'); t.textContent = d.titulo || 'Transmisión en vivo';
      var u = document.createElement('u'); u.textContent = 'Ver ahora';
      a.appendChild(b); a.appendChild(t); a.appendChild(u);
      document.body.insertBefore(a, document.body.firstChild);
    }
    var c = null;
    try { c = JSON.parse(sessionStorage.getItem('io-en-vivo') || 'null'); } catch (e) {}
    if (c && Date.now() - c.t < 60000) { pintar(c.d); return; }
    fetch(api, { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
      try { sessionStorage.setItem('io-en-vivo', JSON.stringify({ t: Date.now(), d: d })); } catch (e) {}
      pintar(d);
    }).catch(function () {});
  }

  // Si el usuario no ha elegido, seguir al sistema en vivo.
  if (mq && mq.addEventListener) {
    mq.addEventListener('change', function () { if (!guardado()) aplicar(sistema()); });
  }

  function init() {
    if (!root.getAttribute('data-io-theme')) aplicar(guardado() || sistema());
    crearBotones();
    corregirEstrellas();
    mejorarBanner();
    avisoEnVivo();
    accesoConRegreso();
    fechas(); setInterval(fechas, 30000);
    // El catálogo pagina por AJAX: volver a corregir cuando cambie el DOM.
    if (window.MutationObserver) {
      var t;
      new MutationObserver(function () { clearTimeout(t); t = setTimeout(corregirEstrellas, 150); })
        .observe(document.body, { childList: true, subtree: true });
    }
  }

  window.IOTheme = { aplicar: function (t) { aplicar(t); guardar(t); }, actual: actual };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
