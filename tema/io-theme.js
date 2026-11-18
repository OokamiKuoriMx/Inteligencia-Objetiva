/*
 * Inteligencia Objetiva — Tema 2.0
 * 1) Pega en <head>, ANTES de cualquier CSS, el snippet de tema/head-snippet.html (evita parpadeo).
 * 2) Carga este archivo al final del <body>:
 *      <script src="/tema/io-theme.js?v=2.0.0" defer></script>
 * No depende de jQuery. No modifica la lógica existente del sitio.
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
    if (!headers.length) headers = [document.body];
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

  // Si el usuario no ha elegido, seguir al sistema en vivo.
  if (mq && mq.addEventListener) {
    mq.addEventListener('change', function () { if (!guardado()) aplicar(sistema()); });
  }

  function init() {
    if (!root.getAttribute('data-io-theme')) aplicar(guardado() || sistema());
    crearBotones();
    corregirEstrellas();
    mejorarBanner();
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
