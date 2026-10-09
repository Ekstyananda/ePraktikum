// M5 browser check. Run with Playwright 1.58.2 against a fresh PublicPreviewSeeder on portal_test only.
const {chromium}=require('playwright');const assert=require('node:assert/strict');const fs=require('node:fs');
let diagnosticPage;
(async()=>{
const browser=await chromium.launch({headless:true,args:['--no-sandbox']});const base=process.env.PORTAL_BASE_URL||'http://127.0.0.1:18781';const tick=async l=>{if(await l.count())await l.first().check();};const P='/demo-m5';const password=process.env.PORTAL_DEMO_PASSWORD;const errors=[];
const ctx=await browser.newContext({viewport:{width:1440,height:1000}});const page=await ctx.newPage();diagnosticPage=page;
const watch=p=>{p.on('dialog',d=>d.accept());p.on('pageerror',e=>errors.push(e.message));p.on('console',m=>{if(m.type()==='error')errors.push(m.text())});};watch(page);
const shot=(name,p=page)=>p.screenshot({path:'/artifacts/m5-'+name+'.png',fullPage:true,animations:'disabled'});
const pick=async(p,selector,text)=>{const value=await p.$eval(selector,(s,t)=>{const o=Array.from(s.options).find(o=>!o.disabled&&o.value&&o.textContent.includes(t));return o?o.value:null;},text);assert.ok(value,`option "${text}" in ${selector}`);await p.selectOption(selector,value);};
const identity=async(p,nbi,name,session)=>{await p.fill('#nbi',nbi);await p.fill('#name',name);await pick(p,'#session_id',session);};
const token=async p=>{await p.locator('#receipt-token').waitFor();const t=await p.locator('#receipt-token').inputValue();assert.match(t,/^[a-f0-9]{64}$/);return t;};
const overflow=async p=>assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'no horizontal page overflow');

// Public pages, no login.
await page.goto(base+'/');await shot('hub');await page.locator(`a[href$="${P}"]`).first().click();assert.equal(new URL(page.url()).pathname,P);await shot('home');
for(const path of [P+'/jadwal',P+'/pengumpulan',P+'/pengajuan',P+'/remidi','/cek-status']){await page.goto(base+path);assert.equal(await page.locator('#sidebar').count(),0);assert.doesNotMatch(await page.locator('main').innerText(),/Andi Pratama|00500001/);}
await page.goto(base+P+'/jadwal');assert.match(await page.locator('main').innerText(),/Senin/);await shot('jadwal-desktop');

// Wrong identity: one generic message, nothing created.
await page.goto(base+P+'/pengajuan');await identity(page,'00500001','Nama Salah','Sesi A');await pick(page,'#source_execution_id','Pertemuan 1');await page.fill('#reason','Sakit — Data contoh');await tick(page.locator('[name=confirmed]'));await page.getByRole('button',{name:'Kirim pengajuan'}).click();
await page.getByRole('alert').waitFor();assert.match(await page.getByRole('alert').innerText(),/Data tidak dapat diproses/);await shot('identity-error');

// Izin request through the form.
await page.goto(base+P+'/pengajuan');await identity(page,'00500001','Andi Pratama — Data contoh','Sesi A');await pick(page,'#source_execution_id','Pertemuan 1');await page.fill('#reason','Sakit — Data contoh');await tick(page.locator('[name=confirmed]'));await shot('pengajuan-izin');
await page.getByRole('button',{name:'Kirim pengajuan'}).click();const izinToken=await token(page);await shot('receipt');

// Two one-meeting moves to Sesi B (capacity 3, 2 members): only one may be approved.
for(const [nbi,name] of [['00500002','Bunga Lestari — Data contoh'],['00500003','Candra Wijaya — Data contoh']]){
 await page.goto(base+P+'/pengajuan');await page.locator('label.nav-link',{hasText:'Pindah'}).click();await identity(page,nbi,name,'Sesi A');await pick(page,'#source_execution_id','Pertemuan 1');await pick(page,'#target_session_id','Sesi B');await pick(page,'#target_execution_id','Pertemuan 1');
 assert.equal(await page.locator('#effective_date').isVisible(),false);await page.fill('#reason','Bentrok jadwal — Data contoh');await tick(page.locator('[name=confirmed]'));if(nbi==='00500002')await shot('pengajuan-pindah');await page.getByRole('button',{name:'Kirim pengajuan'}).click();await token(page);}

// Digital delivery and remidi request.
await page.goto(base+P+'/pengumpulan');await identity(page,'00500001','Andi Pratama — Data contoh','Sesi A');await pick(page,'#schedule_id','Sesi A');await page.locator('#file').setInputFiles({name:'jawaban-v1.sql',mimeType:'application/sql',buffer:Buffer.from('SELECT 1;\n')});await tick(page.locator('[name=confirmed]'));await shot('pengumpulan');
await page.getByRole('button',{name:'Kirim tugas'}).click();const deliveryToken=await token(page);
await page.goto(base+P+'/remidi');await identity(page,'00500001','Andi Pratama — Data contoh','Sesi A');await pick(page,'#program_id','Remidi');await page.fill('#reason','Ingin memperbaiki nilai — Data contoh');await tick(page.locator('[name=confirmed]'));await shot('remidi');await page.getByRole('button',{name:'Kirim pengajuan'}).click();const remidiToken=await token(page);
await page.goto(base+'/cek-status');await page.fill('#token',izinToken);await page.getByRole('button',{name:'Periksa status'}).click();await page.locator('#status-modal .status-detail, #status-modal-body p.mb-0:not(.text-secondary)').first().waitFor();assert.match(await page.locator('#status-modal').innerText(),/Menunggu pemeriksaan/);await shot('status-pending');

// Staff: two accounts approve the two moves at the same time.
async function staff(email){const c=await browser.newContext({viewport:{width:1440,height:1000}});const p=await c.newPage();watch(p);await p.goto(base+'/login');await p.fill('#email',email);await p.fill('#password',password);await p.getByRole('button',{name:'Masuk',exact:true}).click();await p.waitForURL('**/dashboard');return {c,p};}
const admin=await staff('admin@example.test');const aslab=await staff('aslab@example.test');
const o=Number((await admin.p.locator('section.card').filter({has:admin.p.getByRole('heading',{name:'M5 — Data contoh',exact:true})}).getByRole('link',{name:'Lihat sesi →'}).getAttribute('href')).match(/praktikum\/(\d+)/)[1]);
await admin.p.goto(base+`/praktikum/${o}/pengajuan?type=temporary`);await shot('staff-requests',admin.p);
const ids=await admin.p.$$eval('table a.btn',as=>as.map(a=>Number(a.href.match(/pengajuan\/(\d+)/)[1])));assert.equal(ids.length,2);
const form=async(s,id)=>{await s.p.goto(base+`/praktikum/${o}/pengajuan/${id}`);return {token:await s.p.locator('[name=_token]').first().inputValue(),version:await s.p.locator('[name=version]').first().inputValue()};};
const fa=await form(admin,ids[0]),fb=await form(aslab,ids[1]);
const race=await Promise.all([[admin,ids[0],fa],[aslab,ids[1],fb]].map(([s,id,f])=>s.c.request.post(base+`/praktikum/${o}/pengajuan/${id}/keputusan`,{form:{_token:f.token,version:f.version,decision:'approved',reason:'Persetujuan bersamaan — Data contoh',confirmed:'1'},maxRedirects:0})));
assert.deepEqual(race.map(r=>r.status()),[302,302]);
const states=[];for(const id of ids){await admin.p.goto(base+`/praktikum/${o}/pengajuan/${id}`);states.push(await admin.p.locator('.page-actions .status-badge').innerText());}
assert.deepEqual(states.sort(),['Disetujui','Menunggu pemeriksaan'],'capacity allows exactly one concurrent approval');
await admin.p.goto(base+`/praktikum/${o}/pengajuan/${ids[0]}`);await shot('staff-move-detail',admin.p);

// Izin approved through the UI.
await admin.p.goto(base+`/praktikum/${o}/pengajuan?type=izin`);await admin.p.locator('table a.btn').first().click();await admin.p.locator('[name=decision][value=approved]').check();await admin.p.fill('#reason','Surat sakit valid — Data contoh');await tick(admin.p.locator('[name=confirmed]'));await admin.p.getByRole('button',{name:'Simpan keputusan'}).click();await admin.p.getByRole('status').waitFor();assert.match(await admin.p.getByRole('status').innerText(),/tidak otomatis mengubah presensi/);

// Digital delivery accepted, then appears as an M4 digital receipt.
await admin.p.goto(base+`/praktikum/${o}/kiriman-digital`);await shot('staff-deliveries',admin.p);const row=admin.p.getByRole('row').filter({hasText:'jawaban-v1.sql'});await row.locator('select[name=decision]').selectOption('accepted');await row.locator('[name=reason]').fill('Format sesuai — Data contoh');await tick(row.locator('[name=confirmed]'));await row.getByRole('button',{name:'Simpan'}).click();await admin.p.getByRole('status').waitFor();
const file=await admin.c.request.get(await admin.p.getByRole('link',{name:/jawaban-v1\.sql/}).getAttribute('href'));assert.match(await file.text(),/SELECT 1/);
const guest=await browser.newContext();assert.equal((await guest.request.get(await admin.p.getByRole('link',{name:/jawaban-v1\.sql/}).getAttribute('href'),{maxRedirects:0})).status(),302);await guest.close();

// Remidi: approve, record result; original grade stays.
await admin.p.goto(base+`/praktikum/${o}/pengajuan?type=remidi`);await admin.p.locator('table a.btn').first().click();await admin.p.locator('[name=decision][value=approved]').check();await admin.p.fill('#reason','Memenuhi ketentuan — Data contoh');await tick(admin.p.locator('[name=confirmed]'));await admin.p.getByRole('button',{name:'Simpan keputusan'}).click();await admin.p.getByRole('status').waitFor();
const now=new Date(Date.now()+7*3600e3-60e3).toISOString().slice(0,16);await admin.p.fill('#performed_at',now);await admin.p.fill('#score','78');await admin.p.fill('#result-reason','Hasil remidi — Data contoh');await admin.p.locator('form').filter({has:admin.p.getByRole('button',{name:'Simpan hasil'})}).locator('[name=confirmed]').check();await admin.p.getByRole('button',{name:'Simpan hasil'}).click();await admin.p.getByRole('status').waitFor();
const compare=await admin.p.locator('.compare-box strong').allInnerTexts();assert.deepEqual(compare.map(Number),[45,78]);await shot('staff-remidi',admin.p);

// Public status reflects decisions without grades.
await page.goto(base+'/cek-status');await page.fill('#token',remidiToken);await page.getByRole('button',{name:'Periksa status'}).click();await page.locator('#status-modal .status-detail, #status-modal-body p.mb-0:not(.text-secondary)').first().waitFor();const statusText=await page.locator('#status-modal').innerText();assert.match(statusText,/Selesai/);assert.doesNotMatch(statusText,/78|45/);await shot('status-done');
await page.goto(base+'/cek-status');await page.fill('#token',deliveryToken);await page.getByRole('button',{name:'Periksa status'}).click();await page.locator('#status-modal .status-detail, #status-modal-body p.mb-0:not(.text-secondary)').first().waitFor();assert.match(await page.locator('#status-modal').innerText(),/Dikirim/);

// Mobile layout.
await page.setViewportSize({width:390,height:844});
for(const [name,path] of [['hub','/'],['home',P],['jadwal',P+'/jadwal'],['pengajuan',P+'/pengajuan']]){await page.goto(base+path);await overflow(page);await shot(name+'-mobile');}
await page.locator('.public-nav-toggle').click();await page.locator('#public-nav.show').waitFor();await page.screenshot({path:'/artifacts/m5-nav-mobile.png'});
await admin.p.setViewportSize({width:390,height:844});await admin.p.goto(base+`/praktikum/${o}/pengajuan`);await overflow(admin.p);await shot('staff-requests-mobile',admin.p);await admin.p.goto(base+`/praktikum/${o}/pengajuan/${ids[0]}`);await overflow(admin.p);await shot('staff-detail-mobile',admin.p);
assert.deepEqual(errors,[]);
fs.writeFileSync('/artifacts/browser-m5-results.json',JSON.stringify({result:'passed',offering_id:o,checks:['public pages without login, sidebar or roster','generic identity error','izin, pindah satu pertemuan, pengumpulan digital and remidi through public forms with one-time token','two staff accounts approve two moves concurrently; capacity allows exactly one','izin approval does not change attendance','digital delivery accepted, private download needs login','remidi result stored separately; original 45 kept, result 78','status page shows state without grades','mobile 390px without page overflow, public menu collapse','no JS or console errors']},null,2));
await browser.close();
})().catch(async e=>{console.error(e);await diagnosticPage?.screenshot({path:'/artifacts/m5-failure.png',fullPage:true}).catch(()=>{});process.exit(1)});
