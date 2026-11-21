/*
 * Gebietskarten – Editor in der Verwaltung: Flächen per Klick auf die Karte oder per Liste (Tastatur) dem gewählten Gebiet
 * zuordnen, speichern (JSON, CSRF), Vorschau hell/dunkel umschalten und nach dem Speichern neu zeichnen.
 */
(() => {
  const data = (() => { try { return JSON.parse(document.getElementById('gk-ed-data')?.textContent || 'null'); } catch { return null; } })();
  const preview = document.querySelector('[data-gk-preview]');

  // Vorschau: Farbschema
  document.querySelectorAll('[data-gk-scheme]').forEach(btn => btn.addEventListener('click', () => {
    if (!preview) return;
    const dark = btn.dataset.gkScheme === 'dark';
    preview.setAttribute('style', dark ? preview.dataset.dark : preview.dataset.light);
    preview.dataset.scheme = dark ? 'dark' : 'light';
    document.querySelectorAll('[data-gk-scheme]').forEach(b => { b.setAttribute('aria-pressed', String(b === btn)); b.classList.toggle('adm-btn--primary', b === btn); });
  }));

  if (!data || !data.own) return;
  const svg = document.querySelector('[data-gk-svg]');
  const active = document.querySelector('[data-gk-active]');
  const save = document.querySelector('[data-gk-save]');
  const status = document.querySelector('[data-gk-status]');
  const list = document.querySelector('[data-gk-list]');
  const q = document.querySelector('[data-gk-q]');
  if (!svg || !active || !save) return;

  const regions = new Map(data.regions.map(r => [String(r.id), r]));
  const keyToId = new Map(data.regions.map(r => [r.key, String(r.id)]));
  const owner = new Map(Object.entries(data.owner).map(([u, k]) => [u, keyToId.get(k)]));
  const names = data.names || {};
  const tx = (k, p = {}) => (data.t[k] || k).replace(/\{(\w+)\}/g, (_, n) => p[n] ?? '');
  let dirty = false;

  const paint = u => {
    const rid = owner.get(u);
    const r = rid ? regions.get(rid) : null;
    const path = svg.querySelector(`path[data-u="${CSS.escape(u)}"]`);
    if (path) {
      path.classList.toggle('is-free', !r);
      if (r) path.style.setProperty('fill', r.color); else path.style.removeProperty('fill');
      const title = path.querySelector('title');
      if (title) title.textContent = `${names[u] || u} – ${r ? r.name : tx('none')}`;
    }
    const box = list?.querySelector(`input[data-u="${CSS.escape(u)}"]`);
    if (box) {
      box.checked = rid === active.value;
      const own = box.closest('label')?.querySelector('[data-own]');
      if (own) own.textContent = r ? r.name : '';
    }
  };
  const counts = () => {
    const n = new Map();
    owner.forEach(rid => { if (rid) n.set(rid, (n.get(rid) || 0) + 1); });
    document.querySelectorAll('[data-gk-count]').forEach(el => { el.textContent = `(${n.get(el.dataset.gkCount) || 0})`; });
  };
  const mark = () => {
    dirty = true;
    save.disabled = false;
    if (status) status.textContent = tx('unsaved');
  };
  const toggle = (u, on) => {
    const rid = active.value;
    const has = owner.get(u) === rid;
    const want = on ?? !has;
    if (want) owner.set(u, rid); else owner.delete(u);
    paint(u);
    counts();
    mark();
    if (status) status.textContent = want ? tx('assigned', { unit: names[u] || u, region: regions.get(rid)?.name || '' }) : tx('released', { unit: names[u] || u });
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
    regions.forEach((_, rid) => { body[rid] = []; });
    owner.forEach((rid, u) => { if (rid && body[rid]) body[rid].push(u); });
    save.disabled = true;
    try {
      const res = await fetch(data.save, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': document.getElementById('adm-csrf')?.value || '' },
        body: JSON.stringify({ regions: body }),
      });
      const json = await res.json();
      if (!res.ok || !json.ok) throw new Error(json.error || res.status);
      dirty = false;
      if (status) status.textContent = tx('saved');
      if (preview && json.preview) preview.innerHTML = json.preview;
    } catch {
      save.disabled = false;
      if (status) status.textContent = tx('error');
    }
  });
  window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = tx('leave'); } });
  syncList();
})();
