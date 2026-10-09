document.querySelector('#nav-close')?.addEventListener('click',()=>{document.querySelector('#sidebar').classList.remove('open');document.querySelector('#nav-toggle').setAttribute('aria-expanded','false');});
document.querySelector('#nav-toggle')?.addEventListener('click',function(){const open=document.querySelector('#sidebar').classList.toggle('open');this.setAttribute('aria-expanded',String(open));});
const dirtyForms=Array.from(document.querySelectorAll('[data-dirty-form]'));const form=dirtyForms[0];
for(const form of dirtyForms){let dirty=false;form.addEventListener('input',()=>dirty=true);form.addEventListener('change',()=>dirty=true);form.addEventListener('submit',event=>{if(form.dataset.confirm&&!confirm(form.dataset.confirm)){event.preventDefault();return;}dirty=false;const button=form.querySelector('[type=submit]');if(button){if(event.submitter?.name){const action=document.createElement('input');action.type='hidden';action.name=event.submitter.name;action.value=event.submitter.value;form.append(action);}button.disabled=true;button.textContent='Menyimpan…';}});window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});}
document.querySelectorAll('.offering-enabled').forEach(enabled=>{
 const id=enabled.closest('[data-offering]').dataset.offering;
 const groups=document.querySelectorAll(`[data-offering="${id}"]`);
 const all=document.querySelector(`[data-offering="${id}"] .all-sessions`);
 function sync(){groups.forEach(group=>group.querySelector('fieldset').disabled=!enabled.checked);groups.forEach(group=>group.querySelectorAll('.session-check').forEach(input=>input.disabled=!enabled.checked||all.checked));}
 enabled.addEventListener('change',sync);all.addEventListener('change',sync);sync();
 groups.forEach(group=>group.querySelector('.preset')?.addEventListener('click',()=>{group.querySelectorAll('.permission-check').forEach(input=>input.checked=input.dataset.standard==='1');form?.dispatchEvent(new Event('change'));}));
});

const errorData=document.querySelector('#validation-errors');
if(errorData){Object.entries(JSON.parse(errorData.textContent)).forEach(([path,messages])=>{
 const [first,...rest]=path.split('.');const name=first+rest.map(part=>`[${part}]`).join('');
 const input=Array.from(document.querySelectorAll('input,textarea,select')).find(el=>el.name===name||el.name===name+'[]');
 if(input){input.classList.add('is-invalid');input.setAttribute('aria-invalid','true');const next=input.nextElementSibling;if(!next?.classList.contains('invalid-feedback')){const feedback=document.createElement('div');feedback.className='invalid-feedback';feedback.textContent=messages[0];input.after(feedback);}}
});}

const selection=document.querySelector('#selection-form');
if(selection){const boxes=Array.from(selection.querySelectorAll('.row-select'));const mode=selection.querySelector('#selection_mode');function updateSelection(){const count=boxes.filter(box=>box.checked).length;selection.querySelector('#selection-count').textContent=mode.value==='filtered'?'Seluruh hasil filter dipilih; nama dan jumlah akan diperiksa pada pratinjau.':`${count} baris dicentang pada halaman aktif.`;selection.querySelector('#bulk-preview').disabled=mode.value==='selected'&&count===0;mode.options[0].textContent=`Baris dicentang di halaman aktif (${count})`;}
 selection.querySelector('#select-page').addEventListener('change',event=>{boxes.forEach(box=>box.checked=event.target.checked);mode.value='selected';updateSelection();});boxes.forEach(box=>box.addEventListener('change',updateSelection));mode.addEventListener('change',updateSelection);updateSelection();}

const attendance=document.querySelector('#attendance-form');
if(attendance){const boxes=Array.from(attendance.querySelectorAll('.attendance-select'));function syncAttendance(){const count=boxes.filter(b=>b.checked).length;attendance.querySelector('#attendance-count').textContent=`${count} baris dipilih pada halaman aktif.`;attendance.querySelectorAll('.attendance-save').forEach(b=>b.disabled=count===0);}attendance.querySelector('#attendance-page').addEventListener('change',e=>{boxes.forEach(b=>b.checked=e.target.checked);syncAttendance();});boxes.forEach(b=>b.addEventListener('change',syncAttendance));syncAttendance();}

document.querySelectorAll('select[data-autosubmit]').forEach(select=>select.addEventListener('change',()=>select.form.submit()));
(()=>{const sidebar=document.querySelector('#sidebar'),backdrop=document.querySelector('#nav-backdrop'),toggle=document.querySelector('#nav-toggle');if(!sidebar||!backdrop)return;new MutationObserver(()=>{backdrop.hidden=!sidebar.classList.contains('open');}).observe(sidebar,{attributes:true,attributeFilter:['class']});backdrop.addEventListener('click',()=>{sidebar.classList.remove('open');toggle?.setAttribute('aria-expanded','false');});})();
document.querySelectorAll('select.status-select').forEach(select=>{const sync=()=>select.dataset.status=select.value;select.addEventListener('change',sync);sync();});
document.querySelectorAll('select[data-navigate]').forEach(select=>select.addEventListener('change',()=>{if(select.value)location.href=select.value;}));
(()=>{const button=document.querySelector('#sidebar-collapse');if(!button)return;const sync=()=>button.setAttribute('aria-pressed',String(document.body.classList.contains('sidebar-collapsed')));sync();button.addEventListener('click',()=>{const collapsed=document.body.classList.toggle('sidebar-collapsed');try{localStorage.setItem('portal.sidebar',collapsed?'collapsed':'expanded');}catch(e){}sync();});})();
// Phone layout: stacked tables read their column name from the header row.
document.querySelectorAll('table.table-stack').forEach(table=>{const labels=Array.from(table.querySelectorAll('thead th')).map(th=>th.textContent.trim());table.querySelectorAll('tbody tr').forEach(row=>Array.from(row.children).forEach((cell,i)=>{if(labels[i]&&!cell.hasAttribute('colspan'))cell.dataset.label=labels[i];}));});
// Public request form: one form, fields shown per request type; options narrowed to the chosen sessions/meeting.
(()=>{const form=document.querySelector('#request-form');const type=form?.querySelector('#type');if(!form||!type)return;
const session=form.querySelector('#session_id'),source=form.querySelector('#source_execution_id'),targetSession=form.querySelector('#target_session_id'),target=form.querySelector('#target_execution_id');
const filter=(select,keep)=>{if(!select)return;Array.from(select.options).forEach(o=>{if(!o.value)return;const show=keep(o);o.hidden=!show;o.disabled=!show;if(!show&&o.selected)select.value='';});};
const sync=()=>{const t=type.value;form.querySelectorAll('[data-for]').forEach(el=>{const on=el.dataset.for.split(' ').includes(t);el.hidden=!on;el.querySelectorAll('input:not([type=radio]),select').forEach(i=>i.disabled=!on);});
 form.querySelectorAll('.request-tab').forEach(r=>r.closest('label').classList.toggle('active',r.checked));
 filter(source,o=>!session.value||o.dataset.session===session.value);
 const meeting=source?.selectedOptions[0]?.dataset.meeting;filter(target,o=>(!targetSession.value||o.dataset.session===targetSession.value)&&(!meeting||o.dataset.meeting===meeting)&&o.dataset.session!==session.value);};
form.querySelectorAll('.request-tab').forEach(r=>r.addEventListener('change',()=>{type.value=r.value==='temporary'?(form.querySelector('.move-kind:checked')?.value||'temporary'):r.value;sync();}));
form.querySelectorAll('.move-kind').forEach(r=>r.addEventListener('change',()=>{type.value=r.value;sync();}));
[session,source,targetSession].forEach(el=>el?.addEventListener('change',sync));sync();})();
