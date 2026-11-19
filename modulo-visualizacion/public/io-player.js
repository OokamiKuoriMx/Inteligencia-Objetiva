/*
 * Inteligencia Objetiva 2.0 — Reproductor sobre Vimeo con reglas de acceso y visualización única.
 * Requiere el SDK oficial de Vimeo antes de este archivo:
 *   <script src="https://player.vimeo.com/api/player.js"></script>
 *   <script src="/io/public/io-player.js?v=2.0.0"></script>
 * Uso:
 *   <div id="reproductor"></div>
 *   <script>IOPlayer.montar('#reproductor', { accesoId: 123, titulo: 'Nombre', api: '/io/api/reproduccion.php' });</script>
 */
(function (w, d) {
  'use strict';

  function post(api, datos) {
    return fetch(api, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(datos)
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, mensaje: 'Respuesta inválida del servidor.' }; })
        .then(function (j) { j._http = r.status; return j; });
    });
  }

  function el(tag, cls, html) { var e = d.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }

  var CSS = '' +
    '.iop{position:relative;aspect-ratio:16/9;background:#07080b;border-radius:18px;overflow:hidden;color:#fff;font-family:Roboto,system-ui,sans-serif}' +
    '.iop iframe{position:absolute;inset:0;width:100%;height:100%;border:0}' +
    '.iop-capa{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:28px;gap:14px;background:radial-gradient(ellipse at center,rgba(20,22,30,.82),rgba(7,8,11,.97));z-index:3}' +
    '.iop-capa h3{font-family:"Cormorant Garamond",Georgia,serif;font-weight:500;font-size:clamp(24px,3vw,36px);margin:0;color:#fff}' +
    '.iop-capa p{margin:0;max-width:34em;color:rgba(255,255,255,.75);font-size:15px;line-height:1.6}' +
    '.iop-regla{display:inline-flex;gap:8px;align-items:center;padding:6px 14px;border-radius:999px;font-size:12.5px;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.45);color:#fca5a5}' +
    '.iop-btn{margin-top:6px;border:0;border-radius:999px;padding:14px 30px;font-weight:500;font-size:15px;background:#cf1717;color:#fff;cursor:pointer;box-shadow:0 10px 30px -10px rgba(207,23,23,.8)}' +
    '.iop-btn:hover{background:#b01212}.iop-btn[disabled]{opacity:.6;cursor:wait}' +
    '.iop-check{display:flex;gap:10px;align-items:flex-start;font-size:13.5px;color:rgba(255,255,255,.8);text-align:left;max-width:30em}' +
    '.iop-agua{position:absolute;z-index:2;pointer-events:none;font-size:12px;letter-spacing:.04em;color:rgba(255,255,255,.22);transition:top 1.5s,left 1.5s;white-space:nowrap}' +
    '.iop-barra{position:absolute;left:0;right:0;bottom:0;height:3px;z-index:2;background:rgba(255,255,255,.08)}.iop-barra span{display:block;height:100%;background:#cf1717;width:0;transition:width .5s}' +
    '.iop-aviso{position:absolute;top:14px;left:50%;transform:translateX(-50%);z-index:4;padding:8px 16px;border-radius:999px;background:rgba(0,0,0,.7);font-size:13px;opacity:0;transition:opacity .3s}.iop-aviso.on{opacity:1}';

  function inyectarCSS() {
    if (d.getElementById('iop-css')) return;
    var s = el('style'); s.id = 'iop-css'; s.textContent = CSS; d.head.appendChild(s);
  }

  function montar(selector, opciones) {
    inyectarCSS();
    var raiz = typeof selector === 'string' ? d.querySelector(selector) : selector;
    var o = Object.assign({ api: '/io/api/reproduccion.php', titulo: '', tipo: null }, opciones || {});
    raiz.innerHTML = '';
    var cont = el('div', 'iop'); raiz.appendChild(cont);
    var aviso = el('div', 'iop-aviso'); cont.appendChild(aviso);

    var estado = { token: null, player: null, max: 0, reglas: null, timer: null, aguaTimer: null, reproduciendo: false, corrigiendo: false, terminado: false };

    function avisar(txt) { aviso.textContent = txt; aviso.classList.add('on'); clearTimeout(aviso._t); aviso._t = setTimeout(function () { aviso.classList.remove('on'); }, 3200); }

    function capa(html) {
      var c = cont.querySelector('.iop-capa'); if (c) c.remove();
      c = el('div', 'iop-capa', html); cont.appendChild(c); return c;
    }

    function pantallaInicial() {
      var unica = o.tipo === 'unica';
      var c = capa(
        '<h3>' + esc(o.titulo || 'Tu contenido') + '</h3>' +
        (unica
          ? '<span class="iop-regla">Visualización única</span><p>Este contenido se reproduce <b>una sola vez</b>. Busca un momento y un lugar tranquilos. Si se corta tu conexión podrás continuar donde te quedaste.</p>' +
            '<label class="iop-check"><input type="checkbox" class="iop-acepto"> Entiendo que al empezar no podré volver a reproducirlo completo.</label>'
          : '<p>Tu acceso comienza a contar cuando das play.</p>') +
        '<button type="button" class="iop-btn">' + (unica ? 'Comenzar ahora' : 'Ver ahora') + '</button>'
      );
      var btn = c.querySelector('.iop-btn');
      var chk = c.querySelector('.iop-acepto');
      if (chk) { btn.disabled = true; chk.addEventListener('change', function () { btn.disabled = !chk.checked; }); }
      btn.addEventListener('click', iniciar);
    }

    function pantallaFinal(titulo, texto) {
      detener();
      capa('<h3>' + esc(titulo) + '</h3><p>' + esc(texto) + '</p>' + (o.urlCatalogo ? '<a class="iop-btn" style="text-decoration:none" href="' + esc(o.urlCatalogo) + '">Explorar más temas</a>' : ''));
    }

    function detener() {
      clearInterval(estado.timer); clearInterval(estado.aguaTimer);
      if (estado.player) { try { estado.player.pause(); } catch (e) {} }
    }

    function iniciar() {
      var btn = cont.querySelector('.iop-btn'); if (btn) { btn.disabled = true; btn.textContent = 'Preparando…'; }
      post(o.api, { accion: 'iniciar', acceso_id: o.accesoId }).then(function (r) {
        if (!r.ok) {
          var t = { visto: 'Contenido ya visto', vencido: 'Acceso vencido', sin_sesion: 'Inicia sesión' }[r.codigo] || 'No se pudo reproducir';
          return pantallaFinal(t, r.mensaje || 'Intenta de nuevo.');
        }
        estado.token = r.token; estado.reglas = r.reglas; estado.max = r.empezar_en || 0;
        if (typeof o.alIniciar === 'function') { try { o.alIniciar(r); } catch (e) {} }
        crearPlayer(r);
      }).catch(function () { pantallaFinal('Sin conexión', 'Revisa tu internet e intenta de nuevo.'); });
    }

    function crearPlayer(r) {
      var c = cont.querySelector('.iop-capa'); if (c) c.remove();
      var url = 'https://player.vimeo.com/video/' + encodeURIComponent(r.vimeo_id) + (r.vimeo_hash ? '?h=' + encodeURIComponent(r.vimeo_hash) : '');
      var host = el('div'); host.style.cssText = 'position:absolute;inset:0'; cont.appendChild(host);
      var p = new w.Vimeo.Player(host, { url: url, autoplay: true, dnt: true, title: false, byline: false, portrait: false, responsive: false, width: 1280 });
      estado.player = p;
      var f = host.querySelector('iframe'); if (f) { f.style.cssText = 'position:absolute;inset:0;width:100%;height:100%'; }

      // Marca de agua con el correo del alumno
      var agua = el('div', 'iop-agua'); agua.textContent = r.marca_agua || ''; cont.appendChild(agua);
      function moverAgua() { agua.style.top = (10 + Math.random() * 75) + '%'; agua.style.left = (5 + Math.random() * 60) + '%'; }
      moverAgua(); estado.aguaTimer = setInterval(moverAgua, 20000);

      var barra = el('div', 'iop-barra', '<span></span>'); cont.appendChild(barra);
      var unica = !!r.reglas;

      p.ready().then(function () {
        if (estado.max > 0) { estado.corrigiendo = true; p.setCurrentTime(estado.max).finally(function () { estado.corrigiendo = false; }); avisar('Continuamos donde te quedaste'); }
      });

      p.on('play', function () { estado.reproduciendo = true; });
      p.on('pause', function () { estado.reproduciendo = false; });

      p.on('timeupdate', function (e) {
        if (!estado.corrigiendo && e.seconds <= estado.max + 3) estado.max = Math.max(estado.max, e.seconds);
        if (e.duration) barra.firstChild.style.width = Math.min(100, estado.max * 100 / e.duration) + '%';
      });

      if (unica) {
        p.on('seeked', function (e) {
          if (estado.corrigiendo) return;
          var R = estado.reglas;
          if (!R.permitir_adelantar && e.seconds > estado.max + 2) {
            estado.corrigiendo = true; p.setCurrentTime(estado.max).finally(function () { estado.corrigiendo = false; });
            avisar('En visualización única no se puede adelantar');
          } else if (R.retroceso_seg < 999999 && e.seconds < estado.max - R.retroceso_seg) {
            var a = Math.max(0, estado.max - R.retroceso_seg);
            estado.corrigiendo = true; p.setCurrentTime(a).finally(function () { estado.corrigiendo = false; });
            avisar(R.retroceso_seg === 0 ? 'En visualización única no se puede regresar' : 'Solo puedes regresar ' + R.retroceso_seg + ' segundos');
          }
        });
      }

      p.on('ended', function () {
        post(o.api, { accion: 'finalizado', token: estado.token }).then(function (j) {
          if (j && j.fin) { estado.terminado = true; pantallaFinal('Has completado este contenido', 'Gracias por tu atención. Lo que comprendiste ya es tuyo.'); }
        });
      });

      estado.timer = setInterval(latido, (r.latido_seg || 15) * 1000);
    }

    function latido() {
      if (!estado.player || estado.terminado) return;
      Promise.all([estado.player.getCurrentTime(), estado.player.getDuration()]).then(function (v) {
        return post(o.api, { accion: 'latido', token: estado.token, posicion: Math.floor(v[0]), reproduciendo: estado.reproduciendo });
      }).then(function (r) {
        if (!r) return;
        if (!r.ok) {
          estado.terminado = true;
          return pantallaFinal(r.codigo === 'pausa_larga' ? 'Pausa demasiado larga' : 'Reproducción detenida', r.mensaje || '');
        }
        if (typeof r.max_seg === 'number') estado.max = Math.max(estado.max, r.max_seg);
        if (typeof r.corregir_a === 'number') { estado.corrigiendo = true; estado.player.setCurrentTime(r.corregir_a).finally(function () { estado.corrigiendo = false; }); }
        if (r.fin) { estado.terminado = true; pantallaFinal('Has completado este contenido', 'Gracias por tu atención. Lo que comprendiste ya es tuyo.'); }
      }).catch(function () { /* red intermitente: el siguiente latido reintenta */ });
    }

    // Al cerrar la pestaña: avisar al servidor (la visualización única pasa a periodo de gracia).
    w.addEventListener('pagehide', function () {
      if (!estado.token || estado.terminado) return;
      // text/plain: tipo permitido para sendBeacon sin preflight. El servidor lee el JSON del cuerpo.
      var datos = new Blob([JSON.stringify({ accion: 'cerrar', token: estado.token })], { type: 'text/plain' });
      if (navigator.sendBeacon) navigator.sendBeacon(o.api, datos);
    });

    pantallaInicial();
    return { detener: detener };
  }

  w.IOPlayer = { montar: montar };
})(window, document);
