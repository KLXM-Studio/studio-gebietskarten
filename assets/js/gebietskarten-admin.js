/*
 * Gebietskarten – Editor in der Verwaltung:
 *  - Flächen per Klick auf die Karte oder per Liste (Tastatur) dem gewählten Gebiet zuordnen, speichern (JSON, CSRF).
 *    Eigene Karte: Gebiete der Erweiterung. Datentabelle: das Feld der Einträge (Server: Core-Regeln je Eintrag); Flächen
 *    gesperrter Einträge (kein Recht, fremd, veröffentlicht ohne Veröffentlichungsrecht) bleiben unverändert.
 *  - Reihenfolge der eigenen Gebiete: ziehen, Pfeil-Knöpfe oder Alt+Pfeil auf/ab (POST …/reihenfolge, Ansage per Live-Region).
 *  - Vorschau hell/dunkel umschalten und nach dem Speichern neu zeichnen.
 */
(() => {
  const data = (() => { try { return JSON.parse(document.getElementById('gk-ed-data')?.textContent || 'null'); } catch { return null; } })();
  const preview = document.querySelector('[data-gk-preview]');
  const csrf = () => document.getElementById('adm-csrf')?.value || '';
  const post = async (url, body) => {
    const res = await fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrf() },
      body: JSON.stringify(body),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok && !json.message && !json.error) throw new Error(String(res.status));
    return json;
  };
  const tx = (k, p = {}) => ((data?.t || {})[k] || k).replace(/\{(\w+)\}/g, (_, n) => p[n] ?? '');

  // Vorschau: Farbschema (Dunkel: Kit-Variablen + Dunkel-Werte eigener Farben über --gk-d)
  document.querySelectorAll('[data-gk-scheme]').forEach(btn => btn.addEventListener('click', () => {
    if (!preview) return;
    const dark = btn.dataset.gkScheme === 'dark';
    preview.setAttribute('style', dark ? preview.dataset.dark : preview.dataset.light);
    preview.dataset.scheme = dark ? 'dark' : 'light';
    document.querySelectorAll('[data-gk-darknote]').forEach(n => { n.hidden = !dark; });
    document.querySelectorAll('[data-gk-scheme]').forEach(b => { b.setAttribute('aria-pressed', String(b === btn)); b.classList.toggle('adm-btn--primary', b === btn); });
  }));
  const setPreview = html => { if (preview && html) preview.innerHTML = html; };

  if (!data) return;
  const regions = new Map(data.regions.map(r => [String(r.id), r]));
  const keyToId = new Map(data.regions.map(r => [r.key, String(r.id)]));
  let repaintAll = null;

  // ---------------------------------------------------------------- Reihenfolge (nur eigene Gebiete)
  const sortList = document.querySelector('[data-gk-sort]');
  const sortStatus = document.querySelector('[data-gk-sort-status]');
  if (data.own && sortList && sortList.children.length > 1) {
    const items = () => [...sortList.querySelectorAll(':scope > li[data-id]')];
    const buttons = () => items().forEach((li, i, all) => {
      li.querySelector('[data-gk-move="-1"]')?.toggleAttribute('disabled', i === 0);
      li.querySelector('[data-gk-move="1"]')?.toggleAttribute('disabled', i === all.length - 1);
    });
    let pending = null;
    const persist = async (li, focusSel) => {
      buttons();
      const all = items();
      const pos = all.indexOf(li) + 1;
      const name = regions.get(li.dataset.id)?.name || '';
      if (focusSel) {
        const el = li.querySelector(focusSel);
        (el && !el.disabled ? el : li.querySelector('[data-gk-reglink]'))?.focus();
      }
      if (sortStatus) sortStatus.textContent = tx('moved', { name, pos, n: all.length });
      // Legende und Auswahl in derselben Reihenfolge
      const legend = document.querySelector('[data-gk-legend]');
      const active = document.querySelector('[data-gk-active]');
      all.forEach(x => {
        const l = legend?.querySelector(`li[data-id="${x.dataset.id}"]`);
        if (l) legend.insertBefore(l, legend.lastElementChild);
        const o = active?.querySelector(`option[value="${x.dataset.id}"]`);
        if (o) active.append(o);
      });
      const ids = all.map(x => Number(x.dataset.id));
      const mine = pending = ids.join(',');
      try {
        const json = await post(data.sort, { ids });
        if (mine !== pending || !json.ok) { if (!json.ok) throw new Error(); return; }
        // automatische Farben folgen der Position
        Object.entries(json.colors || {}).forEach(([id, c]) => {
          const r = regions.get(id);
          if (r) r.color = c;
          document.querySelectorAll(`[data-gk-sw="${id}"]`).forEach(sw => { sw.style.background = c; });
        });
        repaintAll?.();
        setPreview(json.preview);
      } catch {
        if (sortStatus) sortStatus.textContent = tx('sortError');
      }
    };
    const move = (li, dir, focusSel) => {
      const sib = dir < 0 ? li.previousElementSibling : li.nextElementSibling;
      if (!sib) return;
      if (dir < 0) sortList.insertBefore(li, sib); else sortList.insertBefore(sib, li);
      persist(li, focusSel);
    };
    sortList.addEventListener('click', e => {
      const b = e.target.closest('[data-gk-move]');
      if (!b) return;
      const dir = Number(b.dataset.gkMove);
      move(b.closest('li'), dir, `[data-gk-move="${dir}"]`);
    });
    sortList.addEventListener('keydown', e => {
      if (!e.altKey || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return;
      const li = e.target.closest('li[data-id]');
      if (!li) return;
      e.preventDefault();
      const sel = e.target.matches('[data-gk-move]') ? `[data-gk-move="${e.target.dataset.gkMove}"]` : '[data-gk-reglink]';
      move(li, e.key === 'ArrowUp' ? -1 : 1, sel);
    });
    // Ziehen (Maus/Stift; HTML Drag and Drop)
    let drag = null;
    sortList.addEventListener('dragstart', e => {
      drag = e.target.closest('li[data-id]');
      if (!drag) return;
      drag.classList.add('is-dragging');
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', drag.dataset.id);
    });
    sortList.addEventListener('dragover', e => {
      if (!drag) return;
      e.preventDefault();
      const over = e.target.closest('li[data-id]');
      if (!over || over === drag) return;
      const r = over.getBoundingClientRect();
      sortList.insertBefore(drag, e.clientY > r.top + r.height / 2 ? over.nextElementSibling : over);
    });
    sortList.addEventListener('drop', e => { if (drag) e.preventDefault(); });
    sortList.addEventListener('dragend', () => {
      if (!drag) return;
      const li = drag;
      drag = null;
      li.classList.remove('is-dragging');
      persist(li, null);
    });
  }

  // ---------------------------------------------------------------- Zuordnung per Klick
  if (!data.editable) return;
  const svg = document.querySelector('[data-gk-svg]');
  const active = document.querySelector('[data-gk-active]');
  const save = document.querySelector('[data-gk-save]');
  const status = document.querySelector('[data-gk-status]');
  const list = document.querySelector('[data-gk-list]');
  const q = document.querySelector('[data-gk-q]');
  if (!svg || !active || !save) return;

  let owner = new Map(Object.entries(data.owner).map(([u, k]) => [u, keyToId.get(k)]));
  const names = data.names || {};
  const lockedOf = u => { const rid = owner.get(u); const r = rid ? regions.get(rid) : null; return r && r.locked ? r : null; };
  let dirty = false;

  const paint = u => {
    const rid = owner.get(u);
    const r = rid ? regions.get(rid) : null;
    const path = svg.querySelector(`path[data-u="${CSS.escape(u)}"]`);
    if (path) {
      path.classList.toggle('is-free', !r);
      path.classList.toggle('is-locked', !!(r && r.locked));
      if (r) path.style.setProperty('fill', r.color); else path.style.removeProperty('fill');
      const title = path.querySelector('title');
      if (title) title.textContent = `${names[u] || u} – ${r ? r.name : tx('none')}`;
    }
    const box = list?.querySelector(`input[data-u="${CSS.escape(u)}"]`);
    if (box) {
      box.checked = !!rid && rid === active.value;
      box.disabled = !!(r && r.locked);
      const own = box.closest('label')?.querySelector('[data-own]');
      if (own) own.textContent = r ? r.name : '';
    }
  };
  repaintAll = () => svg.querySelectorAll('path[data-u]').forEach(p => paint(p.dataset.u));
  const counts = () => {
    const n = new Map();
    owner.forEach(rid => { if (rid) n.set(rid, (n.get(rid) || 0) + 1); });
    document.querySelectorAll('[data-gk-count]').forEach(el => { el.textContent = `(${n.get(el.dataset.gkCount) || 0})`; });
  };
  const mark = () => {
    dirty = true;
    save.disabled = false;
  };
  const toggle = (u, on) => {
    const lk = lockedOf(u);
    if (lk && lk.id !== Number(active.value)) {
      if (status) status.textContent = tx('locked', { unit: names[u] || u, region: lk.name, reason: lk.locked });
      paint(u);
      return;
    }
    const rid = active.value;
    const has = owner.get(u) === rid;
    const want = on ?? !has;
    if (want) owner.set(u, rid); else owner.delete(u);
    paint(u);
    counts();
    mark();
    if (status) status.textContent = (want ? tx('assigned', { unit: names[u] || u, region: regions.get(rid)?.name || '' }) : tx('released', { unit: names[u] || u })) + ' · ' + tx('unsaved');
  };
  const syncList = () => list?.querySelectorAll('input[data-u]').forEach(b => { b.checked = owner.get(b.dataset.u) === active.value; });

  svg.addEventListener('click', e => {
    const p = e.target.closest('path[data-u]');
    if (!p) return;
    toggle(p.dataset.u);
    p.classList.add('is-flash');
    setTimeout(() => p.classList.remove('is-flash'), 350);
  });
  list?.addEventListener('change', e => {
    const b = e.target.closest('input[data-u]');
    if (b) toggle(b.dataset.u, b.checked);
  });
  active.addEventListener('change', syncList);
  q?.addEventListener('input', () => {
    const s = q.value.trim().toLowerCase();
    list?.querySelectorAll('li').forEach(li => { li.hidden = s !== '' && !li.dataset.name.includes(s); });
  });

  save.addEventListener('click', async () => {
    const body = {};
    regions.forEach((r, rid) => { if (!r.locked) body[rid] = []; });
    owner.forEach((rid, u) => { if (rid && body[rid]) body[rid].push(u); });
    save.disabled = true;
    if (status) status.textContent = tx('saving');
    try {
      const json = await post(data.save, { regions: body });
      if (json.owner) {
        owner = new Map(Object.entries(json.owner).map(([u, k]) => [u, keyToId.get(k)]));
        repaintAll();
        counts();
      }
      setPreview(json.preview);
      if (!json.ok) throw new Error(json.message || json.error || '');
      dirty = false;
      if (status) status.textContent = json.message || tx('saved');
    } catch (err) {
      save.disabled = false;
      if (status) status.textContent = (err && err.message && err.message.length > 3 ? err.message : tx('error'));
    }
  });
  window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = tx('leave'); } });
  syncList();
  repaintAll();
})();
