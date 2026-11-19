/* Backoffice IO 2.0 — "Otorgar acceso": buscar alumno y contenido, resumen y validación. Sin dependencias. */
(function () {
  'use strict';
  var dlg = document.getElementById('dlgCortesia');
  if (!dlg) return;
  var form = document.getElementById('otForm');
  var conGancho = dlg.getAttribute('data-gancho') === '1';
  var contenidos = [];
  try { contenidos = JSON.parse(document.getElementById('otContenidos').textContent) || []; } catch (e) {}
  var MOTIVO_INICIAL = 'Cortesía comercial';
  var MAX_MOTIVO = 120;

  var estado = { alumno: null, contenido: null };

  function $(sel, raiz) { return (raiz || dlg).querySelector(sel); }
  function el(tag, cls, txt) { var n = document.createElement(tag); if (cls) n.className = cls; if (txt != null) n.textContent = txt; return n; }
  function normal(s) { return String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim(); }
  function etiquetaTipo(c) {
    if (c.tipo === 'permanente') return 'Permanente';
    if (c.tipo === 'unica') return 'Visualización única';
    return (c.horas || 48) + ' h';
  }
  function claseTipo(c) { return 'io-badge-acceso' + (c.tipo === 'unica' ? ' unica' : c.tipo === 'permanente' ? ' permanente' : ''); }
  function iniciales(n) { var p = String(n || '?').trim().split(/\s+/); return ((p[0] || '?')[0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase(); }

  /* ---------- Combobox genérico (patrón ARIA 1.2) ---------- */
  function Combo(nombre, opciones) {
    var campo = $('[data-campo="' + nombre + '"]');
    this.campo = campo;
    this.input = $('[role="combobox"]', campo);
    this.lista = $('[role="listbox"]', campo);
    this.elegido = $('.ot-elegido', campo);
    this.combo = $('.ot-combo', campo);
    this.items = [];
    this.activo = -1;
    this.o = opciones;
    var self = this;
    this.input.addEventListener('input', function () { self.limpiarError(); self.o.alEscribir(self.input.value); });
    this.input.addEventListener('focus', function () { if (self.o.alEnfocar) self.o.alEnfocar(self.input.value); });
    this.input.addEventListener('keydown', function (e) { self.teclado(e); });
    this.input.addEventListener('blur', function () { setTimeout(function () { if (!self.campo.contains(document.activeElement)) self.cerrar(); }, 120); });
    $('[data-cambiar]', campo).addEventListener('click', function () { self.o.alCambiar(); self.mostrarBuscador(true); });
  }
  Combo.prototype.abierta = function () { return !this.lista.hidden; };
  Combo.prototype.cerrar = function () {
    this.lista.hidden = true; this.input.setAttribute('aria-expanded', 'false');
    this.input.removeAttribute('aria-activedescendant'); this.activo = -1;
  };
  /* filas: [{nodo, valor}] seleccionables; aviso: texto para estado vacío/cargando */
  Combo.prototype.pintar = function (filas, aviso) {
    var self = this;
    this.lista.textContent = '';
    this.items = filas;
    this.activo = -1;
    this.input.removeAttribute('aria-activedescendant');
    filas.forEach(function (f, i) {
      var li = f.nodo;
      li.id = self.lista.id + '-' + i;
      li.setAttribute('role', 'option');
      li.setAttribute('aria-selected', 'false');
      li.addEventListener('mousedown', function (e) { e.preventDefault(); });
      li.addEventListener('click', function () { self.elegir(i); });
      self.lista.appendChild(li);
    });
    if (aviso) {
      var av = el('li', 'ot-aviso'); av.setAttribute('role', 'presentation');
      if (typeof aviso === 'string') av.textContent = aviso; else av.appendChild(aviso);
      this.lista.appendChild(av);
    }
    var hay = filas.length > 0 || !!aviso;
    this.lista.hidden = !hay;
    this.input.setAttribute('aria-expanded', hay ? 'true' : 'false');
    if (filas.length) this.mover(0);
  };
  Combo.prototype.mover = function (i) {
    if (!this.items.length) return;
    i = (i + this.items.length) % this.items.length;
    if (this.activo >= 0 && this.items[this.activo]) this.items[this.activo].nodo.setAttribute('aria-selected', 'false');
    this.activo = i;
    var n = this.items[i].nodo;
    n.setAttribute('aria-selected', 'true');
    this.input.setAttribute('aria-activedescendant', n.id);
    n.scrollIntoView({ block: 'nearest' });
  };
  Combo.prototype.teclado = function (e) {
    if (e.key === 'ArrowDown') { e.preventDefault(); if (!this.abierta()) { this.o.alEscribir(this.input.value, true); } else this.mover(this.activo + 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); if (this.abierta()) this.mover(this.activo - 1); }
    else if (e.key === 'Enter') { if (this.abierta() && this.activo >= 0) { e.preventDefault(); this.elegir(this.activo); } else if (this.abierta()) { e.preventDefault(); } }
    else if (e.key === 'Escape') { if (this.abierta()) { e.preventDefault(); e.stopPropagation(); this.cerrar(); } }
  };
  Combo.prototype.elegir = function (i) {
    var f = this.items[i]; if (!f) return;
    this.cerrar();
    this.limpiarError();
    this.mostrarBuscador(false);
    this.o.alElegir(f.valor);
    // El input elegido se oculta: el foco no debe quedarse en un elemento invisible.
    var ae = document.activeElement;
    if (!ae || ae === document.body || ae === this.input) $('[data-cambiar]', this.campo).focus();
  };
  Combo.prototype.mostrarBuscador = function (si) {
    this.combo.hidden = !si; this.elegido.hidden = si;
    var ayuda = $('.ot-ayuda', this.campo); if (ayuda) ayuda.hidden = !si;
    if (si) { this.input.value = ''; this.input.focus(); }
  };
  Combo.prototype.error = function (msg) {
    var e = $('.ot-error', this.campo);
    e.textContent = msg; e.hidden = !msg;
    this.input.setAttribute('aria-invalid', msg ? 'true' : 'false');
    this.campo.classList.toggle('con-error', !!msg);
  };
  Combo.prototype.limpiarError = function () { this.error(''); };
  Combo.prototype.enfocar = function () { if (!this.combo.hidden) this.input.focus(); else $('[data-cambiar]', this.campo).focus(); };

  /* ---------- Alumno: búsqueda en el servidor ---------- */
  var temporizador = null, peticion = null, ultimo = '';
  var alumno = new Combo('alumno', {
    alEscribir: function (txt, forzar) {
      var q = txt.trim();
      clearTimeout(temporizador);
      if (q.length < 2) { if (peticion) peticion.abort(); alumno.cerrar(); ultimo = ''; return; }
      if (q === ultimo && !forzar && alumno.abierta()) return;
      temporizador = setTimeout(function () { buscarAlumnos(q); }, 180);
    },
    alElegir: function (a) {
      estado.alumno = a;
      form.cliente_id.value = a.id; form.email.value = a.email; form.nombre.value = a.nombre || '';
      var e = $('.ot-elegido', alumno.campo);
      $('.ot-avatar', e).textContent = iniciales(a.nombre || a.email);
      $('b', e).textContent = a.nombre || a.email;
      $('.ot-elegido-txt > span', e).textContent = a.email + ' · ' + textoAccesos(a.accesos);
      contenido.refrescarSiAbierta();
      resumen();
      if (!estado.contenido) contenido.input.focus();
    },
    alCambiar: function () {
      estado.alumno = null; form.cliente_id.value = ''; form.email.value = ''; form.nombre.value = ''; ultimo = ''; resumen();
    }
  });
  function textoAccesos(n) { n = n | 0; return n === 0 ? 'sin accesos' : n === 1 ? '1 acceso' : n + ' accesos'; }

  function buscarAlumnos(q) {
    if (peticion) peticion.abort();
    peticion = typeof AbortController === 'function' ? new AbortController() : null;
    ultimo = q;
    alumno.pintar([], 'Buscando…');
    fetch('buscar-alumnos.php?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: peticion ? peticion.signal : undefined })
      .then(function (r) {
        if (r.status === 401) throw new Error('sesion');
        if (!r.ok) throw new Error('http');
        return r.json();
      })
      .then(function (d) {
        if (q !== alumno.input.value.trim()) return; // respuesta vieja
        var res = d.resultados || [];
        if (!res.length) { alumno.pintar([], sinResultados(q)); return; }
        alumno.pintar(res.map(function (a) {
          var li = el('li', 'ot-opcion');
          li.appendChild(el('span', 'ot-avatar', iniciales(a.nombre || a.email))).setAttribute('aria-hidden', 'true');
          var t = el('span', 'ot-opcion-txt');
          t.appendChild(el('b', null, a.nombre || a.email));
          t.appendChild(el('span', null, a.email));
          li.appendChild(t);
          li.appendChild(el('span', 'ot-cuenta', textoAccesos(a.accesos)));
          return { nodo: li, valor: a };
        }));
      })
      .catch(function (e) {
        if (e && e.name === 'AbortError') return;
        alumno.pintar([], e && e.message === 'sesion' ? 'Tu sesión expiró. Recarga la página e inicia sesión de nuevo.' : 'No se pudo buscar. Revisa tu conexión e intenta de nuevo.');
      });
  }
  function sinResultados(q) {
    var f = document.createDocumentFragment();
    f.appendChild(el('b', null, 'No encontramos a nadie con «' + q + '».'));
    f.appendChild(el('span', null, 'El alumno necesita tener cuenta en el sitio. Pídele que se registre en inteligenciaobjetiva.com con su correo y vuelve a buscarlo.'));
    if (!conGancho) f.appendChild(el('span', 'ot-aviso-sec', 'Por ahora aquí solo aparecen alumnos que ya compraron o tienen algún acceso.'));
    return f;
  }

  /* ---------- Contenido: filtro local ---------- */
  var contenido = new Combo('contenido', {
    alEscribir: function (txt) { filtrarContenidos(txt); },
    alEnfocar: function (txt) { if (!contenido.abierta()) filtrarContenidos(txt); },
    alElegir: function (c) {
      estado.contenido = c;
      form.producto_id.value = c.id;
      var e = $('.ot-elegido', contenido.campo);
      $('b', e).textContent = c.titulo;
      var s = $('.ot-elegido-txt > span', e); s.textContent = '';
      s.appendChild(el('span', claseTipo(c), etiquetaTipo(c)));
      resumen();
    },
    alCambiar: function () { estado.contenido = null; form.producto_id.value = ''; resumen(); }
  });
  contenido.refrescarSiAbierta = function () { if (contenido.abierta()) filtrarContenidos(contenido.input.value); };

  function filtrarContenidos(txt) {
    var palabras = normal(txt).split(/\s+/).filter(Boolean);
    var vigentes = estado.alumno ? (estado.alumno.vigentes || []) : [];
    var lista = contenidos.filter(function (c) {
      var t = normal(c.titulo);
      return palabras.every(function (p) { return t.indexOf(p) !== -1; });
    });
    var total = lista.length;
    lista = lista.slice(0, 60);
    var aviso = !total ? 'Ningún título contiene «' + txt.trim() + '». Prueba con otra palabra.' : (total > 60 ? 'Mostrando 60 de ' + total + '. Escribe más para afinar.' : null);
    contenido.pintar(lista.map(function (c) {
      var li = el('li', 'ot-opcion');
      var t = el('span', 'ot-opcion-txt');
      t.appendChild(el('b', null, c.titulo));
      li.appendChild(t);
      var chips = el('span', 'ot-chips');
      if (vigentes.indexOf(c.id) !== -1) chips.appendChild(el('span', 'bo-chip neutro', 'Ya lo tiene'));
      chips.appendChild(el('span', claseTipo(c), etiquetaTipo(c)));
      li.appendChild(chips);
      return { nodo: li, valor: c };
    }), aviso);
  }

  /* ---------- Motivo y nota ---------- */
  var motivo = document.getElementById('otMotivo');
  var nota = document.getElementById('otNota');
  function maxNota() { return Math.max(0, MAX_MOTIVO - (motivo.value || '').length - 2); }
  motivo.addEventListener('change', function () { errorCampo('motivo', ''); nota.maxLength = Math.min(90, maxNota()); resumen(); });
  nota.addEventListener('input', function () { errorCampo('nota', ''); resumen(); });
  function errorCampo(nombre, msg) {
    var c = $('[data-campo="' + nombre + '"]');
    var e = $('.ot-error', c), i = $('select, input', c);
    e.textContent = msg; e.hidden = !msg;
    i.setAttribute('aria-invalid', msg ? 'true' : 'false');
    c.classList.toggle('con-error', !!msg);
  }

  /* ---------- Resumen ---------- */
  var caja = document.getElementById('otResumen');
  function resumen() {
    var a = estado.alumno, c = estado.contenido;
    caja.textContent = '';
    caja.classList.toggle('listo', !!(a && c));
    if (!a || !c) {
      caja.textContent = !a && !c ? 'Elige al alumno y el contenido para ver el resumen.' : !a ? 'Falta elegir al alumno.' : 'Falta elegir el contenido.';
      return;
    }
    var quien = (a.nombre || a.email);
    var p = el('p');
    if (c.tipo === 'unica') {
      p.appendChild(document.createTextNode('Darás una '));
      p.appendChild(el('b', null, 'visualización única'));
      p.appendChild(document.createTextNode(' de «' + c.titulo + '» a '));
    } else {
      p.appendChild(document.createTextNode('Darás '));
      p.appendChild(el('b', null, c.tipo === 'permanente' ? 'acceso permanente' : 'acceso de ' + (c.horas || 48) + ' h'));
      p.appendChild(document.createTextNode(' a «' + c.titulo + '» a '));
    }
    p.appendChild(el('b', null, quien));
    p.appendChild(document.createTextNode(a.nombre ? ' (' + a.email + ').' : '.'));
    caja.appendChild(p);
    var extra = [];
    if (c.tipo === 'ventana') extra.push(c.desde === 'compra' ? 'Las ' + (c.horas || 48) + ' h empiezan a contar desde ahora.' : 'Las ' + (c.horas || 48) + ' h empiezan a contar cuando le dé play por primera vez.');
    if (motivo.value) extra.push('Motivo: ' + motivo.value + (nota.value.trim() ? ' — ' + nota.value.trim() : '') + '.');
    if (extra.length) caja.appendChild(el('p', 'ot-resumen-sec', extra.join(' ')));
    if ((a.vigentes || []).indexOf(c.id) !== -1) caja.appendChild(el('p', 'ot-resumen-aviso', quien + ' ya tiene un acceso vigente a este contenido; se agregará otro.'));
  }

  /* ---------- Validación y envío ---------- */
  form.addEventListener('submit', function (e) {
    var primero = null;
    alumno.limpiarError(); contenido.limpiarError(); errorCampo('motivo', ''); errorCampo('nota', '');
    if (!estado.alumno) {
      alumno.error(alumno.input.value.trim().length >= 2 ? 'Elige al alumno en la lista de resultados.' : 'Busca al alumno por nombre o correo y elígelo.');
      primero = primero || alumno;
    }
    if (!estado.contenido) {
      contenido.error(contenido.input.value.trim() ? 'Elige el contenido en la lista.' : 'Busca el contenido por título y elígelo.');
      primero = primero || contenido;
    }
    if (!motivo.value) { errorCampo('motivo', 'Elige un motivo: queda en la bitácora.'); primero = primero || motivo; }
    var largo = (motivo.value + ': ' + nota.value.trim()).length;
    if (nota.value.trim() && largo > MAX_MOTIVO) { errorCampo('nota', 'Acorta la nota ' + (largo - MAX_MOTIVO) + ' caracteres.'); primero = primero || nota; }
    if (primero) { e.preventDefault(); if (primero.enfocar) primero.enfocar(); else primero.focus(); return; }
    var b = document.getElementById('otEnviar'); b.disabled = true; b.textContent = 'Otorgando…';
  });

  /* ---------- Abrir / cerrar ---------- */
  function reiniciar() {
    estado.alumno = null; estado.contenido = null; ultimo = '';
    ['cliente_id', 'email', 'nombre', 'producto_id'].forEach(function (n) { form[n].value = ''; });
    alumno.mostrarBuscador(true); contenido.mostrarBuscador(true);
    alumno.cerrar(); contenido.cerrar(); alumno.limpiarError(); contenido.limpiarError();
    motivo.value = MOTIVO_INICIAL; nota.value = ''; errorCampo('motivo', ''); errorCampo('nota', '');
    var b = document.getElementById('otEnviar'); b.disabled = false; b.textContent = 'Otorgar acceso';
    resumen();
  }
  document.querySelectorAll('[data-otorgar]').forEach(function (b) {
    b.addEventListener('click', function () { reiniciar(); dlg.showModal(); alumno.input.focus(); });
  });
  $('[data-cerrar]').addEventListener('click', function () { dlg.close(); });
  // Escape con una lista abierta cierra la lista, no el diálogo.
  dlg.addEventListener('cancel', function (e) {
    if (alumno.abierta() || contenido.abierta()) { e.preventDefault(); alumno.cerrar(); contenido.cerrar(); }
  });
})();
