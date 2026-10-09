// Cek Status helpers. Tokens may be kept in this browser only when the student opts in (default off);
// they travel to the server in POST bodies only, never in URLs.
(() => {
    const KEY = 'portal.receipts', REMEMBER = 'portal.receipts.remember', MAX = 30;
    const store = {
        get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
        set(k, v) { try { localStorage.setItem(k, v); return true; } catch (e) { return false; } },
        del(k) { try { localStorage.removeItem(k); } catch (e) {} },
    };
    const list = () => { try { const x = JSON.parse(store.get(KEY) || '[]'); return Array.isArray(x) ? x.filter(i => i && /^[a-f0-9]{64}$/.test(i.token)) : []; } catch (e) { return []; } };
    const saveList = items => items.length ? store.set(KEY, JSON.stringify(items.slice(0, MAX))) : store.del(KEY);
    const add = item => saveList([item, ...list().filter(i => i.token !== item.token)]);
    const remove = token => saveList(list().filter(i => i.token !== token));
    const mask = t => t.slice(0, 4) + '…' + t.slice(-2);
    const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; };
    const today = () => new Date().toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });

    // ---------- Receipt page: opt-in save ----------
    const box = document.querySelector('#save-device');
    const tokenInput = document.querySelector('#receipt-token');
    if (box && tokenInput) {
        const check = document.querySelector('#save-token');
        const token = tokenInput.value.trim().toLowerCase();
        box.hidden = false;
        const item = { token, kind: box.dataset.kind, practicum: box.dataset.practicum, slug: box.dataset.slug, saved: today() };
        const apply = () => {
            if (check.checked) { add(item); store.set(REMEMBER, '1'); }
            else { remove(token); store.del(REMEMBER); }
        };
        // Preference is remembered only after the student chose to save on this device.
        if (store.get(REMEMBER) === '1') { check.checked = true; add(item); }
        check.addEventListener('change', apply);
        const share = document.querySelector('#share-token');
        if (share && navigator.share) {
            share.hidden = false;
            share.addEventListener('click', () => navigator.share({ title: 'Token Cek Status', text: `${item.kind}${item.practicum ? ' · ' + item.practicum : ''}\nToken: ${token}\nCek di ${location.origin}/cek-status` }).catch(() => {}));
        }
    }

    // ---------- Cek Status page ----------
    const form = document.querySelector('#status-form');
    if (!form) return;
    const csrf = form.querySelector('[name=_token]').value;
    const post = async (url, body) => {
        const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(body), credentials: 'same-origin', cache: 'no-store' });
        if (r.status === 429) throw new Error('Terlalu banyak pemeriksaan. Coba lagi sebentar lagi.');
        if (!r.ok) throw new Error('Status belum dapat diperiksa. Muat ulang halaman lalu coba lagi.');
        return r.json();
    };
    const tones = { pending: 'warn', submitted: 'info', approved: 'ok', accepted: 'ok', complete: 'ok', completed: 'ok', revised: 'info', revision_requested: 'warn', rejected: 'danger', missing_decided: 'danger' };
    const modalEl = document.querySelector('#status-modal');
    const modal = modalEl && window.bootstrap ? bootstrap.Modal.getOrCreateInstance(modalEl) : null;
    const body = document.querySelector('#status-modal-body');
    const reviseForm = document.querySelector('#modal-revise');
    const saveBtn = document.querySelector('#modal-save');
    let current = null;

    const render = (d, token) => {
        body.replaceChildren();
        if (!d.found) { body.append(el('p', 'mb-0', d.message)); return; }
        const wrap = el('div', 'status-detail');
        if (d.practicum) wrap.append(el('p', 'small text-secondary mb-1', d.practicum));
        wrap.append(el('h3', 'h5 mb-2', d.kind));
        const st = el('p', 'mb-3'); st.setAttribute('role', 'status'); st.append(el('span', 'status-badge status-' + (tones[d.state] || 'neutral'), d.label)); wrap.append(st);
        if (d.facts.length) { const dl = el('dl', 'row small-dl mb-3'); d.facts.forEach(([k, v]) => dl.append(el('dt', 'col-sm-4', k), el('dd', 'col-sm-8', v))); wrap.append(dl); }
        wrap.append(el('h4', 'h6', 'Tahapan'));
        const ol = el('ol', 'status-steps mb-3');
        d.steps.forEach(s => { const li = el('li', s.done ? 'done' : ''); li.append(el('span', '', s.label), el('small', '', s.at || (s.done ? 'Waktu tidak tercatat' : 'Belum'))); ol.append(li); });
        wrap.append(ol);
        if (d.notes.length) { wrap.append(el('h4', 'h6', 'Catatan aslab')); d.notes.forEach(n => wrap.append(el('p', 'status-note', n))); }
        const next = el('p', 'status-next mb-0'); next.append(el('i', 'bi bi-arrow-right-circle'), ' ' + d.next); wrap.append(next);
        body.append(wrap);
        reviseForm.hidden = !d.revision;
        reviseForm.querySelector('[name=token]').value = d.revision ? token : '';
        saveBtn.hidden = list().some(i => i.token === token);
    };
    const open = async (token, meta) => {
        current = { token, meta };
        body.replaceChildren(el('p', 'text-secondary mb-0', 'Memeriksa…'));
        reviseForm.hidden = true; saveBtn.hidden = true;
        modal?.show();
        try {
            const d = await post(form.dataset.detail, { token });
            render(d, token);
            if (d.found) { current.meta = { kind: d.kind, practicum: d.practicum }; updateSaved(token, d); }
        } catch (e) { body.replaceChildren(el('p', 'text-danger mb-0', e.message)); }
    };
    modalEl?.addEventListener('hidden.bs.modal', () => { reviseForm.querySelector('[name=token]').value = ''; current = null; });
    saveBtn?.addEventListener('click', () => {
        if (!current) return;
        add({ token: current.token, kind: current.meta?.kind || 'Kiriman', practicum: current.meta?.practicum || '', slug: '', saved: today() });
        saveBtn.hidden = true; drawSaved();
    });

    // Manual token: open the pop-up instead of reloading (the form still works without JavaScript).
    form.addEventListener('submit', e => {
        if (!modal) return;
        e.preventDefault();
        const input = form.querySelector('#token');
        const token = input.value.trim().toLowerCase();
        input.value = '';
        open(token);
    });

    // Saved list
    const panel = document.querySelector('#saved-receipts');
    const ul = document.querySelector('#saved-list');
    const lastStatus = {};
    const updateSaved = (token, d) => { lastStatus[token] = d; drawSaved(); };
    const drawSaved = () => {
        const items = list();
        panel.hidden = items.length === 0;
        ul.replaceChildren();
        items.forEach(i => {
            const li = el('li', 'saved-item');
            const info = el('div', 'flex-grow-1');
            info.append(el('strong', 'd-block', i.kind || 'Kiriman'));
            info.append(el('span', 'small text-secondary d-block', [i.practicum, 'disimpan ' + (i.saved || ''), 'token ' + mask(i.token)].filter(Boolean).join(' · ')));
            const s = lastStatus[i.token];
            if (s) info.append(s.found ? el('span', 'status-badge status-' + (tones[s.state] || 'neutral'), s.label) : el('span', 'status-badge status-neutral', 'Tidak ditemukan'));
            const actions = el('div', 'd-flex gap-1 flex-wrap');
            const b = (label, icon, cls, fn) => { const x = el('button', 'btn btn-sm ' + cls); x.type = 'button'; x.append(el('i', 'bi ' + icon), ' ' + label); x.addEventListener('click', fn); return x; };
            actions.append(
                b('Cek', 'bi-search', 'btn-primary', () => open(i.token, i)),
                b('Salin', 'bi-clipboard', 'btn-outline-secondary', async () => { try { await navigator.clipboard.writeText(i.token); } catch (e) {} }),
                b('Hapus', 'bi-trash', 'btn-outline-danger', () => { if (confirm('Hapus token ini dari perangkat?')) { remove(i.token); delete lastStatus[i.token]; drawSaved(); } }),
            );
            li.append(info, actions);
            ul.append(li);
        });
    };
    document.querySelector('#check-all')?.addEventListener('click', async e => {
        const btn = e.currentTarget, items = list(); if (!items.length) return;
        btn.disabled = true;
        try { const d = await post(form.dataset.batch, { tokens: items.map(i => i.token) }); d.results.forEach((r, n) => { lastStatus[items[n].token] = r; }); drawSaved(); }
        catch (err) { alert(err.message); }
        finally { btn.disabled = false; }
    });
    // "Hapus semua" also forgets the save preference: the device goes back to not saving.
    document.querySelector('#forget-all')?.addEventListener('click', () => {
        if (!confirm('Hapus semua token dari perangkat ini?')) return;
        store.del(KEY); store.del(REMEMBER);
        Object.keys(lastStatus).forEach(k => delete lastStatus[k]);
        drawSaved();
    });
    drawSaved();
})();
