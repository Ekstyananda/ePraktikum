// Multi-practicum browser check. Playwright 1.58.2 against a fresh MultiPreviewSeeder on portal_test only.
// Hub, two portals with different accents, data isolation, disabled service, public forms per practicum,
// Tampilan Portal (QR, save, slug change with reason), a real two-admin slug race, old-slug GET/POST, mobile 390 px.
const {chromium}=require('playwright');const assert=require('node:assert/strict');const fs=require('node:fs');
let diagnosticPage;
(async()=>{
const browser=await chromium.launch({headless:true,args:['--no-sandbox']});const base=process.env.PORTAL_BASE_URL||'http://127.0.0.1:18781';const password=process.env.PORTAL_DEMO_PASSWORD;const errors=[];
const SBD='/demo-m5',PCD='/demo-pcd';
const watch=p=>{p.on('dialog',d=>d.accept());p.on('pageerror',e=>errors.push(e.message));p.on('console',m=>{if(m.type()==='error'&&!/status of 404/.test(m.text()))errors.push(m.text())});};
const ctx=await browser.newContext({viewport:{width:1440,height:1000}});const page=await ctx.newPage();diagnosticPage=page;watch(page);
const shot=(name,p=page)=>p.screenshot({path:'/artifacts/multi-'+name+'.png',fullPage:true,animations:'disabled'});
const pick=async(p,selector,text)=>{const value=await p.$eval(selector,(s,t)=>{const o=Array.from(s.options).find(o=>!o.disabled&&o.value&&o.textContent.includes(t));return o?o.value:null;},text);assert.ok(value,`option "${text}" in ${selector}`);await p.selectOption(selector,value);};
const identity=async(p,nbi,name,session)=>{await p.fill('#nbi',nbi);await p.fill('#name',name);await pick(p,'#session_id',session);};
const token=async p=>{await p.locator('#receipt-token').waitFor();const t=await p.locator('#receipt-token').inputValue();assert.match(t,/^[a-f0-9]{64}$/);return t;};
const overflow=async p=>assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'no horizontal page overflow on '+p.url());
const accent=p=>p.evaluate(()=>getComputedStyle(document.body).getPropertyValue('--accent').trim());
const main=p=>p.locator('main').innerText();
const login=async(email)=>{const c=await browser.newContext({viewport:{width:1440,height:1000}});const p=await c.newPage();watch(p);await p.goto(base+'/login');await p.fill('#email',email);await p.fill('#password',password);await p.getByRole('button',{name:'Masuk',exact:true}).click();await p.waitForURL('**/dashboard');return {c,p};};
const offeringOf=async(p,heading)=>Number((await p.locator('section.card').filter({has:p.getByRole('heading',{name:heading,exact:true})}).getByRole('link',{name:'Lihat sesi →'}).getAttribute('href')).match(/praktikum\/(\d+)/)[1]);

// Hub: both practicums, each card in its own accent; general announcement only.
await page.goto(base+'/');const hub=await main(page);
assert.match(hub,/Sistem Basis Data — Data contoh/);assert.match(hub,/Pengolahan Citra Digital — Data contoh/);assert.match(hub,/Lab tutup saat libur nasional/);assert.doesNotMatch(hub,/Bawa laptop dengan OpenCV/);
const cards=await page.locator('.practicum-card').evaluateAll(cs=>cs.map(c=>getComputedStyle(c).getPropertyValue('--card-accent').trim()));
assert.equal(cards.length,2);assert.notEqual(cards[0],cards[1]);await shot('hub-desktop');

// PCD portal: teal, no Remidi, only PCD data.
await page.locator(`a.practicum-card[href$="${PCD}"]`).click();await page.waitForURL('**'+PCD);
const pcdAccent=await accent(page);let txt=await main(page);
assert.match(txt,/Pengolahan Citra Digital/);assert.match(txt,/Bawa laptop dengan OpenCV/);assert.doesNotMatch(txt,/Query SQL|Pertemuan 1 — Data contoh/);
assert.equal(await page.locator('#public-nav a',{hasText:'Remidi'}).count(),0);assert.equal(await page.locator('.portal-chip').innerText().then(t=>/PCD/.test(t)),true);await shot('pcd-home');
assert.equal((await page.goto(base+PCD+'/remidi')).status(),404);
await page.goto(base+PCD+'/modul');txt=await main(page);assert.match(txt,/Modul Citra 1/);assert.doesNotMatch(txt,/M5 — Data contoh/);await shot('pcd-modul');
const pcdDownload=await page.locator('a[href*="/unduh"]').first().getAttribute('href');const pcdMaterial=pcdDownload.match(/modul\/(\d+)\/unduh/)[1];
const dl=await ctx.request.get(pcdDownload.startsWith('http')?pcdDownload:base+pcdDownload);assert.equal(dl.status(),200);assert.match(dl.headers()['content-disposition'],/attachment/);assert.equal((await dl.body()).subarray(0,4).toString(),'%PDF');

// SBD portal: blue, isolation of modules/announcements and sessions.
await page.goto(base+SBD);const sbdAccent=await accent(page);assert.notEqual(sbdAccent,pcdAccent);txt=await main(page);assert.doesNotMatch(txt,/OpenCV|Modul Citra/);await shot('sbd-home');
assert.equal((await ctx.request.get(base+SBD+'/modul/'+pcdMaterial+'/unduh')).status(),404);
await page.goto(base+SBD+'/pengajuan');const sbdSessions=await page.$eval('#session_id',s=>Array.from(s.options).map(o=>o.textContent).join('|'));assert.doesNotMatch(sbdSessions,/Sesi C/);

// Public form per practicum: PCD student submits izin in PCD; status shows the practicum.
await page.goto(base+PCD+'/pengajuan');await identity(page,'00600001','Fajar Nugroho — Data contoh','Sesi C');await pick(page,'#source_execution_id','Pertemuan 1');await page.fill('#reason','Sakit — Data contoh');await page.locator('[name=confirmed]').check();await shot('pcd-pengajuan');
await page.getByRole('button',{name:'Kirim pengajuan'}).click();const pcdToken=await token(page);
await page.goto(base+'/cek-status');await page.fill('#token',pcdToken);await page.getByRole('button',{name:'Periksa status'}).click();await page.locator('#status-modal .status-detail, #status-modal-body p.mb-0:not(.text-secondary)').first().waitFor();assert.match(await page.locator('#status-modal').innerText(),/Pengolahan Citra Digital/);await shot('status-pcd');

// Tampilan Portal (admin): QR rendered, save with checkbox.
const A=await login('admin@example.test');const oPcd=await offeringOf(A.p,'Pengolahan Citra Digital — Data contoh');const oSbd=await offeringOf(A.p,'M5 — Data contoh');
await A.p.goto(base+`/praktikum/${oPcd}/tampilan-portal`);await A.p.locator('#qr svg').waitFor();assert.match(await main(A.p),/semua semester/);
await A.p.fill('#tagline','Filter, segmentasi dan deteksi tepi dengan OpenCV — Data contoh');await A.p.locator('[name=confirmed]').check();await A.p.getByRole('button',{name:'Simpan tampilan'}).click();await A.p.getByRole('status').first().waitFor();
assert.equal(await A.p.locator('.alert-danger').count(),0);await shot('identity-desktop',A.p);
await page.goto(base+PCD);assert.match(await main(page),/Filter, segmentasi dan deteksi tepi/);assert.equal(await page.locator('#public-nav a',{hasText:'Pengajuan'}).count(),1,'services kept after save');

// Real race: two admins save the same new slug for different practicums at the same moment.
const B=await login('admin2@example.test');
await A.p.goto(base+`/praktikum/${oSbd}/tampilan-portal`);await B.p.goto(base+`/praktikum/${oPcd}/tampilan-portal`);
for(const p of [A.p,B.p]){await p.fill('#slug','lab-rebutan');await p.fill('#reason','Uji simpan bersamaan — Data contoh');await p.locator('[name=confirmed]').check();}
await Promise.all([A.p.getByRole('button',{name:'Simpan tampilan'}).click(),B.p.getByRole('button',{name:'Simpan tampilan'}).click()]);
await Promise.all([A.p.waitForLoadState(),B.p.waitForLoadState()]);
const outcome=async p=>(await p.locator('.alert-danger, .invalid-feedback').count())>0?'lost':'won';
const results=[await outcome(A.p),await outcome(B.p)];assert.deepEqual(results.slice().sort(),['lost','won'],'exactly one admin wins: '+results);
const loser=results[0]==='lost'?A.p:B.p;assert.match(await main(loser),/Slug (baru saja dipakai|sudah dipakai)/);await shot('slug-race-loser',loser);
const winnerSlug='/lab-rebutan',winnerOld=results[0]==='won'?SBD:PCD,loserSlug=results[0]==='won'?PCD:SBD;
assert.equal((await ctx.request.get(base+loserSlug,{maxRedirects:0})).status(),200,'loser keeps its address');

// Old slug: GET 301 to the new slug; a form opened before the rename still posts through the old slug.
const g=await (await browser.newContext({viewport:{width:1440,height:1000}})).newPage();watch(g);
const r=await ctx.request.get(base+winnerOld+'/jadwal?x=1',{maxRedirects:0});assert.equal(r.status(),301);assert.equal(new URL(r.headers()['location'],base).pathname+new URL(r.headers()['location'],base).search,winnerSlug+'/jadwal?x=1');
await page.goto(base+winnerOld);assert.equal(new URL(page.url()).pathname,winnerSlug);
// Open the PCD/SBD form on the current address, rename again, then submit the stale form.
const formSlug=winnerSlug;await g.goto(base+formSlug+'/pengajuan');
if(winnerOld===PCD){await identity(g,'00600002','Gita Permata — Data contoh','Sesi C');await pick(g,'#source_execution_id','Pertemuan 1');}else{await identity(g,'00500004','Dewi Anggraini — Data contoh','Sesi B');await pick(g,'#source_execution_id','Pertemuan 1');}
await g.fill('#reason','Keperluan keluarga — Data contoh');await g.locator('[name=confirmed]').check();
const W=results[0]==='won'?A.p:B.p;await W.reload();await W.fill('#slug','lab-baru');await W.fill('#reason','Alamat final setelah uji — Data contoh');await W.locator('[name=confirmed]').check();await W.getByRole('button',{name:'Simpan tampilan'}).click();await W.getByRole('status').first().waitFor();
assert.match(await W.locator('#share-link').inputValue(),/\/lab-baru$/);assert.match(await main(W),/lab-rebutan/);await shot('identity-alias',W);
await g.getByRole('button',{name:'Kirim pengajuan'}).click();await token(g);assert.equal(new URL(g.url()).pathname,formSlug+'/pengajuan','POST through the old slug is processed, not redirected');await shot('old-slug-post-receipt',g);

// Laptop 1024 px: full portal menu (8 items) fits without overlap or overflow.
await page.setViewportSize({width:1024,height:768});await page.goto(base+(winnerOld===SBD?'/lab-baru':SBD));await overflow(page);
assert.equal(await page.locator('#public-nav').isVisible(),false,'laptop uses the menu toggle');await page.locator('.public-nav-toggle').click();await page.locator('#public-nav a',{hasText:'Pengumuman'}).waitFor();await overflow(page);await shot('sbd-laptop');
// 1280 and 1440 px: full menu on one line, chip never overlaps it.
for(const w of [1280,1440]){await page.setViewportSize({width:w,height:900});await page.goto(base+(winnerOld===SBD?'/lab-baru':SBD));await overflow(page);
  const chip=await page.locator('.portal-chip').boundingBox();const first=await page.locator('#public-nav a').first().boundingBox();assert.ok(chip.x+chip.width<=first.x,'chip does not overlap the menu at '+w);
  const hs=await page.locator('#public-nav > a').evaluateAll(as=>as.map(a=>Math.round(a.getBoundingClientRect().height)));assert.ok(Math.max(...hs)<48,'menu items on one line at '+w+': '+hs);}
await page.setViewportSize({width:1440,height:1000});await page.goto(base+(winnerOld===SBD?'/lab-baru':SBD));await shot('sbd-home-final');
// Mobile 390 px: hub, both portals, a form and Tampilan Portal.
for(const p of [page,A.p]){await p.setViewportSize({width:390,height:844});}
for(const [name,path] of [['hub','/'],['pcd',PCD],['pcd-pengajuan',PCD+'/pengajuan'],['sbd','/lab-baru'],['sbd-modul','/lab-baru/modul']]){
  const target=(name.startsWith('sbd')&&winnerOld!==SBD)?path.replace('/lab-baru',SBD):(name.startsWith('pcd')&&winnerOld===PCD)?path.replace(PCD,'/lab-baru'):path;
  await page.goto(base+target);await overflow(page);await shot(name+'-mobile');}
await A.p.goto(base+`/praktikum/${oPcd}/tampilan-portal`);await overflow(A.p);await shot('identity-mobile',A.p);

assert.deepEqual(errors,[],'no JS errors');
fs.writeFileSync('/artifacts/browser-multi-results.json',JSON.stringify({result:'passed',accents:{sbd:sbdAccent,pcd:pcdAccent},race:results,checks:['hub lists two practicums with different accents and only general announcements','PCD portal teal, no Remidi, PCD-only modules and announcements; remidi 404','SBD cannot download PCD module; SBD form lists no PCD session','PCD public izin with token; Cek Status names the practicum','Tampilan Portal QR, save keeps services','two admins race for one slug: exactly one wins, loser keeps address with validation message','old slug GET 301 keeps query; stale form POST via old slug processed','mobile 390 px without overflow','no JS errors']},null,2));
await browser.close();
})().catch(async e=>{console.error(e);try{await diagnosticPage?.screenshot({path:'/artifacts/multi-failure.png',fullPage:true});}catch{}process.exit(1);});
