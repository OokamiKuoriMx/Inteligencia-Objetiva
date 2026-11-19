// Capturas del demo local de Inteligencia Objetiva 2.0 (solo 127.0.0.1:8097, datos ficticios).
const { chromium } = require('playwright-core');
const path = require('path');
const fs = require('fs');
const BASE = 'http://127.0.0.1:8097';
const OUT = process.argv[2];
const SOLO = process.argv[3] ? process.argv[3].split(',') : null;
const EXE = path.join(process.env.LOCALAPPDATA, 'ms-playwright', 'chromium-1208', 'chrome-win64', 'chrome.exe');
fs.mkdirSync(OUT, { recursive: true });

const tomas = [
  // [nombre, url, tema, dispositivo, opciones]
  ['portada-claro', '/portada/index.html', 'light', 'desk', {}],
  ['portada-oscuro', '/portada/index.html', 'dark', 'desk', {}],
  ['portada-movil', '/portada/index.html', 'light', 'movil', {}],
  ['catalogo', '/catalogo', 'light', 'desk', {}],
  ['catalogo-oscuro', '/catalogo', 'dark', 'desk', {}],
  ['producto', '/producto/3-errores-basicos-en-una-relacion-de-pareja', 'light', 'desk', {}],
  ['membresias', '/membresias', 'light', 'desk', {}],
  ['en-vivo', '/en-vivo', 'light', 'desk', {}],
  ['carrito', '/carrito', 'light', 'desk', { alumna: true, agregar: true }],
  ['biblioteca', '/io/public/mi-biblioteca.php', 'light', 'desk', { alumna: true }],
  ['biblioteca-oscuro', '/io/public/mi-biblioteca.php', 'dark', 'desk', { alumna: true }],
  ['biblioteca-movil', '/io/public/mi-biblioteca.php', 'light', 'movil', { alumna: true }],
  ['bo-tablero', '/io/backoffice/index.php', 'light', 'desk', { admin: true }],
  ['bo-tablero-oscuro', '/io/backoffice/index.php', 'dark', 'desk', { admin: true }],
  ['bo-accesos', '/io/backoffice/accesos.php', 'light', 'desk', { admin: true }],
  ['bo-contenido', '/io/backoffice/reglas.php', 'light', 'desk', { admin: true }],
  ['bo-editor', '/io/backoffice/reglas.php', 'light', 'desk', { admin: true, clic: '[data-editar-contenido="410"]', espera: 2500 }],
  ['bo-editor-oscuro', '/io/backoffice/reglas.php', 'dark', 'desk', { admin: true, clic: '[data-editar-contenido="410"]', espera: 2500 }],
  ['reproductor', '/io/public/ver.php?acceso=26', 'light', 'desk', { alumna: true, espera: 3000 }],
  ['reproductor-oscuro', '/io/public/ver.php?acceso=26', 'dark', 'desk', { alumna: true, espera: 3000 }],
  ['bo-transmisiones-oscuro', '/io/backoffice/transmisiones.php', 'dark', 'desk', { admin: true }],
  ['en-vivo-movil', '/en-vivo', 'light', 'movil', {}],
  ['bo-cupones', '/io/backoffice/descuentos.php', 'light', 'desk', { admin: true }],
  ['bo-cupon-modal', '/io/backoffice/descuentos.php?nuevo=1', 'light', 'desk', { admin: true }],
  ['bo-transmisiones', '/io/backoffice/transmisiones.php', 'light', 'desk', { admin: true }],
  ['bo-transmision-modal', '/io/backoffice/transmisiones.php?nueva=1', 'light', 'desk', { admin: true }],
  ['bo-youtube', '/io/backoffice/youtube.php', 'light', 'desk', { admin: true }],
  ['bo-vimeo', '/io/backoffice/vimeo.php', 'light', 'desk', { admin: true }],
];

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });
  const desk = { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1.5 };
  const movil = { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2.5, isMobile: true, hasTouch: true };
  const ctxs = {};
  const LOGO = path.resolve(__dirname, process.argv[4] || '.', 'tema/img/io-logo.webp');
  async function ctx(dev, tema, alumna, admin) {
    const k = [dev, tema, !!alumna, !!admin].join('|');
    if (ctxs[k]) return ctxs[k];
    const c = await browser.newContext({ ...(dev === 'movil' ? movil : desk), colorScheme: tema, locale: 'es-MX' });
    await c.addInitScript(t => { try { localStorage.setItem('io-theme', t); localStorage.setItem('io-theme-bo', t); } catch (e) {} }, tema);
    await c.route('**/img/icons/logo.png', r => r.fulfill({ path: LOGO, contentType: 'image/webp' }));
    const p = await c.newPage();
    if (alumna) {
      await p.goto(BASE + '/');
      await Promise.all([p.waitForNavigation(), p.click('button[name=entrar][value="1"]')]);
    }
    if (admin) {
      await p.goto(BASE + '/io/backoffice/login.php');
      await p.fill('#bo-correo', 'demo-admin@ejemplo.test');
      await p.fill('#bo-clave', 'demo-local-12345');
      await Promise.all([p.waitForNavigation(), p.press('#bo-clave', 'Enter')]);
    }
    await p.close();
    ctxs[k] = c;
    return c;
  }
  for (const [nombre, url, tema, dev, o] of tomas) {
    if (SOLO && !SOLO.includes(nombre)) continue;
    try {
      const c = await ctx(dev, tema, o.alumna, o.admin);
      const p = await c.newPage();
      if (o.agregar) {
        await p.goto(BASE + '/producto/3-errores-basicos-en-una-relacion-de-pareja', { waitUntil: 'networkidle' });
        for (const sl of ['5-elementos-para-incrementar-tu-economia', '3-errores-basicos-en-una-relacion-de-pareja']) {
          await p.goto(BASE + '/producto/' + sl, { waitUntil: 'networkidle' });
          const b = p.getByRole('button', { name: 'Agregar al carrito' });
          if (await b.count()) { await Promise.all([p.waitForLoadState('networkidle'), b.first().click()]); await p.waitForTimeout(800); }
        }
      }
      await p.goto(BASE + url, { waitUntil: 'networkidle', timeout: 20000 }).catch(() => {});
      await p.addStyleTag({ content: '*{animation-duration:0s!important;transition-duration:0s!important;scroll-behavior:auto!important}' }).catch(() => {});
      if (o.clic) { await p.click(o.clic); }
      await p.waitForTimeout(o.espera || 1200);
      await p.screenshot({ path: path.join(OUT, nombre + '.png'), fullPage: !!o.completa });
      console.log('ok', nombre, p.url());
      await p.close();
    } catch (e) { console.log('ERROR', nombre, e.message.split('\n')[0]); }
  }
  await browser.close();
})();
