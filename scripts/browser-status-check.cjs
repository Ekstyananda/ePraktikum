// Cek Status browser check. Playwright 1.58.2 against a fresh MultiPreviewSeeder on portal_test only.
// Opt-in device list (default off, remembered, delete one / delete all), pop-up details with the student note,
// "Cek semua", token reissue by aslab, tokens never in URLs, mobile 390 px, no JS errors.
const {chromium}=require('playwright');const assert=require('node:assert/strict');const fs=require('node:fs');
let diagnosticPage;
(async()=>{
const browser=await chromium.launch({headless:true,args:['--no-sandbox']});const base=process.env.PORTAL_BASE_URL||'http://127.0.0.1:18781';const password=process.env.PORTAL_DEMO_PASSWORD;const errors=[];const urls=[];
const P='/demo-m5';
const watch=p=>{p.on('dialog',d=>d.accept());p.on('pageerror',e=>errors.push(e.message));p.on('console',m=>{if(m.type()==='error')errors.push(m.text())});p.on('request',r=>urls.push(r.url()));};
const ctx=await browser.newContext({viewport:{width:1440,height:1000}});const page=await ctx.newPage();diagnosticPage=page;watch(page);
const shot=(name,p=page)=>p.screenshot({path:'/artifacts/status-'+name+'.png',fullPage:true,animations:'disabled'});
const pick=async(p,selector,text)=>{const value=await p.$eval(selector,(s,t)=>{const o=Array.from(s.options).find(o=>!o.disabled&&o.value&&o.textContent.includes(t));return o?o.value:null;},text);assert.ok(value,`option "${text}" in ${selector}`);await p.selectOption(selector,value);};
const identity=async(p,nbi,name,session)=>{await p.fill('#nbi',nbi);await p.fill('#name',name);await pick(p,'#session_id',session);};
const token=async p=>{await p.locator('#receipt-token').waitFor();const t=await p.locator('#receipt-token').inputValue();assert.match(t,/^[a-f0-9]{64}$/);return t;};
const saved=()=>page.evaluate(()=>({list:JSON.parse(localStorage.getItem('portal.receipts')||'[]').map(i=>i.token),remember:localStorage.getItem('portal.receipts.remember')}));
const izin=async(nbi,name)=>{await page.goto(base+P+'/pengajuan');await identity(page,nbi,name,'Sesi A');await pick(page,'#source_execution_id','Pertemuan 1');await page.fill('#reason','Sakit — Data contoh');await page.locator('[name=confirmed]').check();await page.getByRole('button',{name:'Kirim pengajuan'}).click();return token(page);};
const overflow=async p=>assert.equal(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'no horizontal overflow on '+p.url());
const login=async email=>{const c=await browser.newContext({viewport:{width:1440,height:1000}});const p=await c.newPage();watch(p);await p.goto(base+'/login');await p.fill('#email',email);await p.fill('#password',password);await p.getByRole('button',{name:'Masuk',exact:true}).click();await p.waitForURL('**/dashboard');return p;};

// 1. Default off: first receipt is not stored.
const andiIzin=await izin('00500001','Andi Pratama — Data contoh');
assert.equal(await page.locator('#save-token').isChecked(),false,'save is off by default');await shot('receipt-default');
assert.deepEqual(await saved(),{list:[],remember:null});
// 2. Opt in: stored and remembered for the next receipt on this device.
const bungaIzin=await izin('00500002','Bunga Lestari — Data contoh');await page.locator('#save-token').check();
assert.deepEqual(await saved(),{list:[bungaIzin],remember:'1'});await shot('receipt-saved');
await page.goto(base+P+'/pengumpulan');await identity(page,'00500001','Andi Pratama — Data contoh','Sesi A');await pick(page,'#schedule_id','Sesi A');
await page.locator('#file').setInputFiles({name:'00500001_Andi_Query.sql',mimeType:'application/sql',buffer:Buffer.from('SELECT 1;\n')});await page.locator('[name=confirmed]').check();await page.getByRole('button',{name:/Kirim/}).click();
const andiDelivery=await token(page);assert.equal(await page.locator('#save-token').isChecked(),true,'remembered preference');
assert.deepEqual((await saved()).list,[andiDelivery,bungaIzin]);

// 3. Staff: decision with a note for the student; delivery accepted with a note.
const admin=await login('admin@example.test');
const o=Number((await admin.locator('section.card').filter({has:admin.getByRole('heading',{name:'M5 — Data contoh',exact:true})}).getByRole('link',{name:'Lihat sesi →'}).getAttribute('href')).match(/praktikum\/(\d+)/)[1]);
await admin.goto(base+`/praktikum/${o}/pengajuan?type=izin`);await admin.getByRole('row').filter({hasText:'Bunga'}).locator('a.btn').first().click();
await admin.locator('[name=decision][value=approved]').check();await admin.fill('#reason','Surat sakit valid — internal');await admin.fill('#student_note','Bawa surat dokter asli ke Lab A minggu depan.');await admin.locator('form').filter({has:admin.getByRole('button',{name:'Simpan keputusan'})}).locator('[name=confirmed]').check();await admin.getByRole('button',{name:'Simpan keputusan'}).click();await admin.getByRole('status').first().waitFor();
await admin.goto(base+`/praktikum/${o}/kiriman-digital`);const row=admin.getByRole('row').filter({hasText:'00500001_Andi_Query.sql'});await row.locator('select[name=decision]').selectOption('accepted');await row.locator('[name=student_note]').fill('Query diterima, format sesuai.');await row.getByRole('button',{name:'Simpan'}).click();await admin.getByRole('status').first().waitFor();

// 4. Cek Status: saved list, "Cek semua", pop-up details.
await page.goto(base+'/cek-status');await page.locator('#saved-list li').first().waitFor();assert.equal(await page.locator('#saved-list li').count(),2);
await page.getByRole('button',{name:'Cek semua'}).click();await page.locator('#saved-list .status-badge').nth(1).waitFor();
let listText=await page.locator('#saved-list').innerText();assert.match(listText,/Disetujui/);assert.match(listText,/Diterima aslab/);assert.doesNotMatch(listText,new RegExp(bungaIzin));await shot('saved-list');
await page.locator('#saved-list li').filter({hasText:'Izin'}).getByRole('button',{name:'Cek'}).click();await page.locator('#status-modal .status-detail').waitFor();
let modal=await page.locator('#status-modal').innerText();assert.match(modal,/Bawa surat dokter asli/);assert.doesNotMatch(modal,/internal|Bunga|00500002/);assert.match(modal,/Disetujui/);await shot('modal-izin');
await page.getByRole('button',{name:'Tutup'}).first().click();
await page.locator('#saved-list li').filter({hasText:'Pengumpulan'}).getByRole('button',{name:'Cek'}).click();await page.locator('#status-modal .status-detail').waitFor();
modal=await page.locator('#status-modal').innerText();assert.match(modal,/Berkas kiriman SQL/);assert.match(modal,/Query diterima, format sesuai/);assert.doesNotMatch(modal,/00500001|Andi/);await shot('modal-delivery');
await page.getByRole('button',{name:'Tutup'}).first().click();

// 5. Manual token (not saved): pop-up offers saving.
await page.fill('#token',andiIzin);await page.getByRole('button',{name:'Periksa status'}).click();await page.locator('#status-modal .status-detail').waitFor();
assert.equal(await page.locator('#token').inputValue(),'','token cleared from the input');assert.equal(await page.locator('#modal-save').isVisible(),true);await page.getByRole('button',{name:'Tutup'}).first().click();

// 6. Reissue for Andi's izin; the old token stops working.
await admin.goto(base+`/praktikum/${o}/pengajuan?type=izin`);await admin.getByRole('row').filter({hasText:'Andi'}).locator('a.btn').first().click();
await admin.getByText('Praktikan kehilangan token?').click();await admin.locator('[name=method][value=in_person]').check();await admin.fill('#reissue-reason','HP hilang, identitas dicocokkan di lab — Data contoh');await admin.locator('[name=verified]').check();await shot('reissue-form',admin);
await admin.getByRole('button',{name:'Terbitkan token baru'}).click();const newToken=await token(admin);assert.notEqual(newToken,andiIzin);await shot('reissue-issued',admin);
await page.fill('#token',andiIzin);await page.getByRole('button',{name:'Periksa status'}).click();await page.getByText('Bukti tidak ditemukan').waitFor();await page.getByRole('button',{name:'Tutup'}).first().click();
await page.fill('#token',newToken);await page.getByRole('button',{name:'Periksa status'}).click();await page.locator('#status-modal .status-detail').waitFor();await page.getByRole('button',{name:'Tutup'}).first().click();

// 7. Delete one keeps the other; delete all clears list and preference.
await page.locator('#saved-list li').filter({hasText:'Izin'}).getByRole('button',{name:'Hapus'}).click();assert.deepEqual((await saved()).list,[andiDelivery]);
await page.setViewportSize({width:390,height:844});await page.reload();await page.locator('#saved-list li').first().waitFor();await overflow(page);await shot('mobile-list');
await page.locator('#saved-list li').first().getByRole('button',{name:'Cek'}).click();await page.locator('#status-modal .status-detail').waitFor();await overflow(page);await shot('mobile-modal');await page.getByRole('button',{name:'Tutup'}).first().click();
await page.setViewportSize({width:1440,height:1000});
await page.getByRole('button',{name:'Hapus semua'}).click();assert.deepEqual(await saved(),{list:[],remember:null});assert.equal(await page.locator('#saved-receipts').isVisible(),false);
await izin('00500003','Candra Wijaya — Data contoh');assert.equal(await page.locator('#save-token').isChecked(),false,'preference forgotten after delete all');

// Tokens never appear in any requested URL.
for(const t of [andiIzin,bungaIzin,andiDelivery,newToken])assert.ok(!urls.some(u=>u.includes(t)),'token in URL');
assert.deepEqual(errors,[],'no JS errors');
fs.writeFileSync('/artifacts/browser-status-results.json',JSON.stringify({result:'passed',checks:['save off by default; opt-in stores and is remembered','Cek semua and pop-up details with student notes, no NBI/name/file name/internal reason','manual token cleared from input, offer to save','reissue: old token not found, new token works','delete one keeps others; delete all clears list and preference','tokens never in URLs','mobile 390 px list and modal without overflow','no JS errors']},null,2));
await browser.close();
})().catch(async e=>{console.error(e);try{await diagnosticPage?.screenshot({path:'/artifacts/status-failure.png',fullPage:true});}catch{}process.exit(1);});
