const { chromium } = require('playwright-core'); const path=require('path');
const EXE = path.join(process.env.LOCALAPPDATA,'ms-playwright','chromium-1208','chrome-win64','chrome.exe');
(async()=>{const b=await chromium.launch({executablePath:EXE});
for (const [n,u,m] of [['antes-portada','https://inteligenciaobjetiva.com/',0],['antes-catalogo','https://inteligenciaobjetiva.com/conferencias-en-linea',0],['antes-portada-movil','https://inteligenciaobjetiva.com/',1]]){
const c=await b.newContext(m?{viewport:{width:390,height:844},deviceScaleFactor:2.5,isMobile:true,hasTouch:true}:{viewport:{width:1440,height:900},deviceScaleFactor:1.5,locale:'es-MX'});
const p=await c.newPage(); try{await p.goto(u,{waitUntil:'networkidle',timeout:45000});}catch(e){console.log('lento',n)}
await p.waitForTimeout(2500); await p.screenshot({path:path.join(process.argv[2],n+'.png')}); console.log('ok',n,p.url()); await c.close();}
await b.close();})();
