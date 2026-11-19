/* Inteligencia Objetiva 2.0 — Portada: animación al hacer scroll y video bajo demanda. Sin dependencias. */
(function () {
  'use strict';

  // Aparición suave de secciones (solo si este script corre: sin JS todo queda visible)
  if ('IntersectionObserver' in window && !matchMedia('(prefers-reduced-motion: reduce)').matches) document.body.classList.add('js-revela');
  var els = document.querySelectorAll('.io2 [data-revela]');
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('visible'); io.unobserve(e.target); } });
    }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
    els.forEach(function (el) { io.observe(el); });
  } else {
    els.forEach(function (el) { el.classList.add('visible'); });
  }

  // Se inserta solo al hacer clic (una sola carga, con controles).
  document.querySelectorAll('.io2 .video[data-vimeo], .io2 .video[data-youtube]').forEach(function (box) {
    function cargar() {
      var youtube = box.getAttribute('data-youtube');
      var id = youtube || box.getAttribute('data-vimeo');
      if (!id || !(youtube ? /^[A-Za-z0-9_-]{11}$/ : /^\d+$/).test(id) || box.querySelector('iframe')) return;
      var f = document.createElement('iframe');
      f.src = youtube ? 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(id) + '?autoplay=1&rel=0'
        : 'https://player.vimeo.com/video/' + encodeURIComponent(id) + '?autoplay=1&title=0&byline=0&portrait=0&dnt=1';
      f.allow = 'autoplay; fullscreen; picture-in-picture';
      f.allowFullscreen = true;
      f.title = box.getAttribute('aria-label') || 'Video';
      box.appendChild(f);
    }
    box.addEventListener('click', cargar);
    box.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); cargar(); } });
  });
})();
