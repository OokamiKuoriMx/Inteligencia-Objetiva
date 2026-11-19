// Captura /llms.txt del demo tal como lo muestra el navegador (texto plano). Si el navegador lo descarga,
// se muestra el mismo texto recibido en una vista de texto plano equivalente.
const { chromium } = require('playwright-core'); const path=require('path');
const EXE=path.join(process.env.LOCALAPPDATA,'ms-playwright','chromium-1208','chrome-win64','chrome.exe');
(async()=>{const b=await chromium.launch({executablePath:EXE});
const c=await b.newContext({viewport:{width:1440,height:900},deviceScaleFactor:1.5});const p=await c.newPage();
for (const [arch,nombre] of [['llms','agentes-llms'],['robots','agentes-robots']]){
 const u='http://127.0.0.1:8097/io/tienda/agentes.php?archivo='+arch;
 const txt=await (await c.request.get(u)).text();
 const esc=txt.replace(/&/g,'&amp;').replace(/</g,'&lt;');
 await p.setContent(`<html><head><meta charset="utf-8"></head><body style="margin:0;background:#fff"><div style="font:13px Arial;color:#555;background:#f1f3f4;padding:10px 16px;border-bottom:1px solid #ddd">127.0.0.1:8097/${arch==='llms'?'llms.txt':'robots.txt'}</div><pre style="white-space:pre-wrap;word-wrap:break-word;font:15px/1.45 Consolas,monospace;margin:16px 20px;color:#111">${esc}</pre></body></html>`);
 await p.screenshot({path:path.join(process.argv[2],nombre+'.png')});console.log('ok',nombre);}
await b.close();})();
