// M6 browser check. Playwright 1.58.2 against a fresh PublicPreviewSeeder on portal_test, through the Docker stack
// (the scheduler container must be running so queued backups are processed).
const {chromium}=require('playwright');const assert=require('node:assert/strict');const fs=require('node:fs');
let diagnosticPage;
(async()=>{
const browser=await chromium.launch({headless:true,args:['--no-sandbox']});const base=process.env.PORTAL_BASE_URL||'http://127.0.0.1:18781';const P='/demo-m5';const password=process.env.PORTAL_DEMO_PASSWORD;const errors=[];
const ctx=await browser.newContext({viewport:{width:1440,height:1000},acceptDownloads:true});const page=await ctx.newPage();diagnosticPage=page;
page.on('dialog',d=>d.accept());page.on('pageerror',e=>errors.push(e.message));page.on('console',m=>{if(m.type()==='error')errors.push(m.text())});
const shot=(name,p=page)=>p.screenshot({path:'/artifacts/m6-'+name+'.png',fullPage:true,animations:'disabled'});
const ok=async()=>{await page.getByRole('status').first().waitFor();assert.equal(await page.locator('.alert-danger').count(),0);};
const overflow=async()=>assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'no horizontal page overflow');
await page.goto(base+'/login');await page.fill('#email','admin@example.test');await page.fill('#password',password);await page.getByRole('button',{name:'Masuk',exact:true}).click();await page.waitForURL('**/dashboard');
const o=Number((await page.locator('section.card').filter({has:page.getByRole('heading',{name:'M5 — Data contoh',exact:true})}).getByRole('link',{name:'Lihat sesi →'}).getAttribute('href')).match(/praktikum\/(\d+)/)[1]);
await page.goto(base+`/dashboard?praktikum=${o}`);

// Announcement: sanitized markdown, draft hidden, published public.
await page.goto(base+`/praktikum/${o}/kelola-pengumuman/baru`);
await page.fill('#title','Jadwal praktikum minggu depan — Data contoh');
await page.fill('#body','**Penting:** praktikum dimulai pukul 08.00.\n\n- Bawa kartu praktikum\n- Datang 10 menit lebih awal\n\n<script>alert(1)</script> [tautan](javascript:alert(2))');
await page.getByRole('button',{name:'Simpan draf'}).click();await ok();
const announcementId=Number(page.url().match(/kelola-pengumuman\/(\d+)/)[1]);
const guest=await browser.newContext();const g=await guest.newPage();
assert.equal((await g.goto(base+`/pengumuman/${announcementId}`)).status(),404);
await page.fill('#reason','Terbitkan untuk praktikan');await page.locator('form').filter({has:page.getByRole('button',{name:'Terbitkan'})}).locator('[name=confirmed]').check();await page.getByRole('button',{name:'Terbitkan'}).click();await ok();await shot('announcement-editor');
await g.goto(base+P);assert.match(await g.locator('main').innerText(),/Jadwal praktikum minggu depan/);await g.screenshot({path:'/artifacts/m6-home.png',fullPage:true});
await g.goto(base+`/pengumuman/${announcementId}`);const html=await g.content();assert.match(html,/<strong>Penting:<\/strong>/);assert.doesNotMatch(html,/<script>alert|javascript:alert/);await g.screenshot({path:'/artifacts/m6-announcement-public.png',fullPage:true});

// Reports: page, CSV and XLSX.
await page.goto(base+`/praktikum/${o}/rekap?type=pengajuan`);await page.goto(base+`/praktikum/${o}/rekap?type=presensi`);await shot('report-presensi');
const download=async(label)=>{const [d]=await Promise.all([page.waitForEvent('download'),page.getByRole('link',{name:label,exact:true}).click()]);const p=await d.path();return {name:d.suggestedFilename(),bytes:fs.readFileSync(p)};};
const csv=await download('CSV');assert.match(csv.name,/^rekap-presensi-.*\.csv$/);assert.match(csv.bytes.toString('utf8'),/NBI/);
await page.goto(base+`/praktikum/${o}/rekap?type=nilai`);await shot('report-nilai');const xlsx=await download('XLSX');assert.equal(xlsx.bytes.subarray(0,2).toString(),'PK');
const [print]=await Promise.all([ctx.waitForEvent('page'),page.getByRole('link',{name:'Cetak'}).click()]);await print.waitForLoadState();await print.emulateMedia({media:'print'});await print.pdf({path:'/artifacts/m6-report-a4.pdf',format:'A4',landscape:true,printBackground:true});await print.close();

// Activity log: list, filter, read-only detail.
await page.goto(base+'/pengaturan/log-aktivitas?action=announcement');await shot('log-index');assert.match(await page.locator('main').innerText(),/announcement\.created/);
await page.getByRole('link',{name:'Detail'}).first().click();await page.locator('.diff-table').waitFor();assert.equal(await page.locator('main form').count(),0,'log detail has no forms');await shot('log-detail');

// Backup: queue from the UI, scheduler container processes it in the background.
await page.goto(base+'/pengaturan/backup');await shot('backup-before');await page.getByRole('button',{name:'Jalankan Backup'}).click();await ok();
let state='';for(let i=0;i<24&&!/Berhasil|Gagal/.test(state);i++){await page.waitForTimeout(5000);await page.goto(base+'/pengaturan/backup');state=await page.locator('table tbody tr').first().innerText();}
assert.match(state,/Berhasil/,'backup processed by the scheduler: '+state);assert.match(state,/tabel/);await shot('backup-after');

// Semester lock: public services disappear, staff writes refused; admin reopens with a reason.
await page.goto(base+'/pengaturan/master/semester');const row=page.getByRole('row').filter({hasText:'Data contoh — Semester uji'});
await row.locator('summary').click();await row.locator('[name=reason]').fill('Semester selesai — uji kunci');await row.locator('[name=confirmed]').check();await row.getByRole('button',{name:'Kunci sekarang'}).click();await ok();await shot('semester-locked');
assert.equal((await g.goto(base+P+'/pengajuan')).status(),404);assert.match(await g.locator('main').innerText(),/sedang tidak dibuka/);await g.goto(base+'/');assert.match(await g.locator('main').innerText(),/Belum ada praktikum yang dibuka/);
assert.equal((await ctx.request.post(base+`/praktikum/${o}/kelola-pengumuman`,{form:{_token:await page.locator('[name=_token]').first().inputValue(),title:'X',body:'Y',audience:'public',action:'draft',version:'0'},maxRedirects:0})).status(),423);
const locked=page.getByRole('row').filter({hasText:'Data contoh — Semester uji'});await locked.locator('summary').click();await locked.locator('[name=reason]').fill('Dibuka untuk koreksi — uji');await locked.locator('[name=confirmed]').check();await locked.getByRole('button',{name:'Buka kembali'}).click();await ok();
await g.goto(base+P+'/pengajuan');assert.match(await g.locator('main').innerText(),/Kirim pengajuan|Form pengajuan/);
await page.goto(base+'/pengaturan/log-aktivitas?action=master.');assert.match(await page.locator('main').innerText(),/master\.reopened/);assert.match(await page.locator('main').innerText(),/Dibuka untuk koreksi/);
await page.goto(base+'/pengaturan');await shot('settings');

// Mobile.
await page.setViewportSize({width:390,height:844});
for(const [name,path] of [['settings','/pengaturan'],['backup','/pengaturan/backup'],['log','/pengaturan/log-aktivitas'],['report',`/praktikum/${o}/rekap`],['announcements',`/praktikum/${o}/kelola-pengumuman`]]){await page.goto(base+path);await overflow();await shot(name+'-mobile');}
await g.setViewportSize({width:390,height:844});await g.goto(base+`/pengumuman/${announcementId}`);assert.equal(await g.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);await g.screenshot({path:'/artifacts/m6-announcement-mobile.png',fullPage:true});
await guest.close();assert.deepEqual(errors,[]);
fs.writeFileSync('/artifacts/browser-m6-results.json',JSON.stringify({result:'passed',offering_id:o,checks:['announcement draft hidden (404), published visible on home and detail, script/javascript links stripped','report page, CSV and XLSX downloads, A4 landscape print PDF','activity log filter and read-only diff detail without forms','backup queued from UI and completed by the scheduler container with table/row counts','semester lock hides public services and returns 423 for staff writes; reopen with reason restores and is logged','mobile 390px without page overflow','no JS or console errors']},null,2));
await browser.close();
})().catch(async e=>{console.error(e);await diagnosticPage?.screenshot({path:'/artifacts/m6-failure.png',fullPage:true}).catch(()=>{});process.exit(1)});
