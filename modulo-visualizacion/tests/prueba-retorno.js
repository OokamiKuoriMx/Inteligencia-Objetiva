/* Regreso desde el acceso del sitio: ejecuta la función real en un DOM mínimo, sin dependencias. */
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert');

const source = fs.readFileSync(path.resolve(__dirname, '../../tema/io-theme.js'), 'utf8');
const inicio = source.indexOf('  function accesoConRegreso()');
const fin = source.indexOf('  // Si el usuario no ha elegido', inicio);
assert(inicio >= 0 && fin > inicio, 'No se encontró la función real de retorno de autenticación.');
const codigo = source.slice(inicio, fin) + '\naccesoConRegreso();';

function ejecutar(url, guardados = {}, bloqueado = false) {
  const datos = new Map(Object.entries(guardados));
  const nodos = [];
  const contenedor = { firstChild: null, insertBefore(nodo) { nodos.push(nodo); } };
  const ubicacion = new URL(url);
  let reemplazo = '';
  const contexto = {
    URL,
    URLSearchParams,
    window: {
      location: { pathname: ubicacion.pathname, search: ubicacion.search, href: ubicacion.href },
      history: { replaceState(estado, titulo, ruta) { reemplazo = ruta; } }
    },
    sessionStorage: {
      getItem(clave) { if (bloqueado) throw new Error('Storage bloqueado'); return datos.get(clave) || null; },
      setItem(clave, valor) { if (bloqueado) throw new Error('Storage bloqueado'); datos.set(clave, valor); },
      removeItem(clave) { if (bloqueado) throw new Error('Storage bloqueado'); datos.delete(clave); }
    },
    document: {
      body: contenedor,
      createElement() { return { children: [], setAttribute() {}, appendChild(nodo) { this.children.push(nodo); } }; },
      querySelector(selector) { return selector === 'main' ? contenedor : null; },
      getElementById() { return null; }
    }
  };
  vm.runInNewContext(codigo, contexto, { timeout: 1000 });
  return { enlace: nodos[0] && nodos[0].children[0], datos, reemplazo };
}

let correctas = 0;
let fallidas = 0;
function comprobar(nombre, fn) {
  try { fn(); correctas++; console.log('  ✔ ' + nombre); }
  catch (error) { fallidas++; console.error('  ✘ ' + nombre + ': ' + error.message); }
}

const destinos = {
  carrito: '/io/tienda/carrito.php',
  membresias: '/io/tienda/membresias.php',
  pedidos: '/io/tienda/mis-pedidos.php',
  biblioteca: '/io/public/mi-biblioteca.php'
};
for (const [destino, ruta] of Object.entries(destinos)) {
  comprobar('conserva el destino ' + destino + ' sin reabrir el acceso al recargar', () => {
    const resultado = ejecutar('https://example.test/?io_acceso=login&io_destino=' + destino);
    assert.strictEqual(resultado.enlace.href, ruta);
    assert(!resultado.reemplazo.includes('io_acceso='));
    assert.strictEqual(resultado.datos.get('io-volver-destino'), destino);
  });
}
for (const destino of ['https://evil.test/', '//evil.test/', '__proto__', 'toString']) {
  comprobar('rechaza el destino no permitido ' + destino, () => {
    const resultado = ejecutar('https://example.test/?io_acceso=login&io_destino=' + encodeURIComponent(destino));
    assert.strictEqual(resultado.enlace.href, destinos.carrito);
  });
}
comprobar('recupera membresías desde storage después de una recarga', () => {
  assert.strictEqual(ejecutar('https://example.test/', { 'io-volver-destino': 'membresias' }).enlace.href, destinos.membresias);
});
comprobar('mantiene compatibilidad con el marcador anterior del carrito', () => {
  assert.strictEqual(ejecutar('https://example.test/', { 'io-volver-carrito': '1' }).enlace.href, destinos.carrito);
});
comprobar('limpia la intención al llegar al destino esperado', () => {
  assert.strictEqual(ejecutar('https://example.test/membresias', { 'io-volver-destino': 'membresias' }).datos.size, 0);
});
comprobar('conserva el regreso incluso cuando el navegador bloquea storage', () => {
  const resultado = ejecutar('https://example.test/?io_acceso=login&io_destino=pedidos', {}, true);
  assert.strictEqual(resultado.enlace.href, destinos.pedidos);
  assert(resultado.reemplazo.includes('io_destino=pedidos'));
});
console.log('Resultado: ' + correctas + ' correctas, ' + fallidas + ' fallidas');
process.exitCode = fallidas ? 1 : 0;
