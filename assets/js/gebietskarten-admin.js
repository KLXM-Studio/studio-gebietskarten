/*
 * Gebietskarten – Editor in der Verwaltung:
 *  - Flächen per Klick auf die Karte oder per Liste (Tastatur) dem gewählten Gebiet zuordnen, speichern (JSON, CSRF).
 *    Eigene Karte: Gebiete der Erweiterung. Datentabelle: das Feld der Einträge (Server: Core-Regeln je Eintrag); Flächen
 *    gesperrter Einträge (kein Recht, fremd, veröffentlicht ohne Veröffentlichungsrecht) bleiben unverändert.
 *  - Reihenfolge der eigenen Gebiete: ziehen, Pfeil-Knöpfe oder Alt+Pfeil auf/ab (POST …/reihenfolge, Ansage per Live-Region).
 *  - Vorschau hell/dunkel umschalten und nach dem Speichern neu zeichnen.
 *  - Beschriftungen (labelEditor): ziehen (Maus, Stift, Finger) oder Pfeiltasten (Umschalt = große Schritte), Text, Größe, Stil,
 *    Großbuchstaben, Hinweislinie mit eigenem Ankerpunkt, „Lage zurücksetzen“. Jede Änderung zeichnet die Karte als Entwurf neu
 *    (POST …/beschriftung, draft) – Größe „automatisch“, Ton hell/dunkel und Kontrast rechnet der Server wie auf der Website.
 */
(() => {
  const data = (() => { try { return JSON.parse(document.getElementById('gk-ed-data')?.textContent || 'null'); } catch { return null; } })();
  const preview = document.querySelector('[data-gk-preview]:not([data-gk-labbox])');
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

  // Vorschau und Beschriftungen: Farbschema (Dunkel: Kit-Variablen + Dunkel-Werte eigener Farben über --gk-d) – beide Schalter gemeinsam
  document.querySelectorAll('[data-gk-scheme]').forEach(btn => btn.addEventListener('click', () => {
    const dark = btn.dataset.gkScheme === 'dark';
    document.querySelectorAll('[data-gk-preview]').forEach(box => {
      box.setAttribute('style', dark ? box.dataset.dark : box.dataset.light);
      box.dataset.scheme = dark ? 'dark' : 'light';
    });
    document.querySelectorAll('[data-gk-darknote]').forEach(n => { n.hidden = !dark; });
    document.querySelectorAll('[data-gk-scheme]').forEach(b => { const on = b.dataset.gkScheme === btn.dataset.gkScheme; b.setAttribute('aria-pressed', String(on)); b.classList.toggle('adm-btn--primary', on); });
  }));
  const setPreview = html => { if (preview && html) preview.innerHTML = html; };

  labelEditor(post);

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

  // ---------------------------------------------------------------- Beschriftungen
  function labelEditor(post) {
    const ld = (() => { try { return JSON.parse(document.getElementById('gk-lab-data')?.textContent || 'null'); } catch { return null; } })();
    const box = document.querySelector('[data-gk-labbox]');
    const panel = document.querySelector('[data-gk-labpanel]');
    if (!ld || !box || !panel) return;
    const tl = (k, p = {}) => ((ld.t || {})[k] || k).replace(/\{(\w+)\}/g, (_, n) => p[n] ?? '');
    const sel = panel.querySelector('[data-gk-lab-sel]');
    const f = name => panel.querySelector(`[data-gk-lab-f="${name}"]`);
    const posEl = panel.querySelector('[data-gk-lab-pos]');
    const infoEl = panel.querySelector('[data-gk-lab-info]');
    const resetBtn = panel.querySelector('[data-gk-lab-reset]');
    const saveBtn = document.querySelector('[data-gk-lab-save]');
    const status = document.querySelector('[data-gk-lab-status]');
    const regions = new Map(ld.regions.map(r => [r.key, r]));
    const labels = JSON.parse(JSON.stringify(ld.labels || {}));
    const texts = {};              // Datentabelle mit Feld „label“: geänderte Texte je Eintrag
    let info = ld.info || {};
    let current = sel.value;
    let dirty = false;
    const fmt = n => (Math.round(n * 10) / 10).toLocaleString(document.documentElement.lang || 'de') + ':1';
    const nameOf = k => regions.get(k)?.name || k;

    const svgOf = () => box.querySelector('svg.gk-svg');
    const proj = () => (svgOf()?.dataset.gkProj || '').split(',').map(Number);
    const toGeo = (x, y) => { const p = proj(); return p.length === 6 ? [+((x - p[4]) / p[2] + p[0]).toFixed(5), +(p[1] - (y - p[5]) / p[3]).toFixed(5)] : null; };
    const svgPoint = e => { const svg = svgOf(); const pt = svg.createSVGPoint(); pt.x = e.clientX; pt.y = e.clientY; return pt.matrixTransform(svg.getScreenCTM().inverse()); };
    const setting = k => (labels[k] ||= {});
    const handle = k => box.querySelector(`[data-gk-lab="${CSS.escape(k)}"] .gk-lab__box`);
    const tidy = k => { const s = labels[k]; if (s) Object.keys(s).forEach(x => { if (s[x] === '' || s[x] === false || s[x] == null) delete s[x]; }); if (s && !Object.keys(s).length) delete labels[k]; };

    // Formular rechts: Werte des gewählten Gebiets
    const fill = () => {
      const s = labels[current] || {};
      const r = regions.get(current) || {};
      f('show').value = s.show || '';
      const tx = f('text');
      tx.value = ld.bound ? (current in texts ? texts[current] : (r.text || '')) : (s.text || '');
      tx.placeholder = r.name || '';
      tx.readOnly = !!(ld.bound && r.textLock);
      tx.title = ld.bound && r.textLock ? tl('textLocked', { reason: r.textLock }) : '';
      f('size').value = s.size || '';
      f('style').value = s.style || '';
      f('caps').value = s.caps || '';
      f('leader').checked = !!s.leader;
      describe();
      box.querySelectorAll('.gk-lab.is-sel').forEach(g => g.classList.remove('is-sel'));
      box.querySelector(`[data-gk-lab="${CSS.escape(current)}"]`)?.classList.add('is-sel');
    };
    // Lage, Größe, Ton, Kontrast, Platz – aus der letzten Berechnung des Servers
    const describe = () => {
      const i = info[current];
      const s = labels[current] || {};
      const r = regions.get(current) || {};
      posEl.textContent = s.pos || s.anchor ? tl('posManual') : tl('posAuto');
      resetBtn.disabled = !(s.pos || s.anchor);
      const out = [];
      if (!r.has || !i) { infoEl.replaceChildren(Object.assign(document.createElement('p'), { textContent: tl('noArea') })); return; }
      const line = (text, warn) => { const p = document.createElement('p'); if (warn) p.className = 'gk-lab-warn'; p.textContent = text; out.push(p); };
      if (!i.visible) line(tl('off'));
      const tone = k => ({ d: tl('toneD'), l: tl('toneL'), kit: tl('toneKit'), tag: tl('toneTag') }[k] || k);
      line(tl('size', { size: i.size.toUpperCase() }) + ' · ' + tl('tone', { tone: tone(i.toneL) + (i.toneD !== i.toneL ? ' / ' + tone(i.toneD) : '') }));
      if (i.cL != null) line(tl('contrastL', { ratio: fmt(i.cL) }) + (i.cL < 4.5 ? ' – ' + tl('low') : ''), i.cL < 4.5);
      if (i.cD != null) line(tl('contrastD', { ratio: fmt(i.cD) }) + (i.cD < 4.5 ? ' – ' + tl('low') : ''), i.cD < 4.5);
      if (!i.fits) line(tl('tight'), true);
      if (ld.pole && !i.fitsPhone) line(i.leader ? tl('phoneLeader') : tl('phone'));
      if (ld.bound && r.textLock) line(tl('textLocked', { reason: r.textLock }));
      infoEl.replaceChildren(...out);
    };

    const mark = () => { dirty = true; saveBtn.disabled = false; if (status) status.textContent = tl('unsaved'); };
    // Entwurf neu zeichnen (verzögert); Fokus bleibt auf derselben Beschriftung
    let timer = null, seq = 0;
    const draft = (delay = 250) => {
      clearTimeout(timer);
      timer = setTimeout(async () => {
        const mine = ++seq;
        try {
          const json = await post(ld.save, { labels, texts, draft: true });
          if (mine !== seq || !json.box) return;
          render(json);
        } catch { /* nächste Änderung versucht es erneut */ }
      }, delay);
    };
    const render = json => {
      const a = document.activeElement && box.contains(document.activeElement) ? document.activeElement : null;
      const lab = a?.closest('[data-gk-lab]')?.dataset.gkLab;
      const anc = a?.dataset.gkAnchor;
      box.innerHTML = json.box;
      info = json.info || {};
      if (lab) handle(lab)?.focus({ preventScroll: true });
      else if (anc) box.querySelector(`[data-gk-anchor="${CSS.escape(anc)}"]`)?.focus({ preventScroll: true });
      fill();
    };

    sel.addEventListener('change', () => { current = sel.value; fill(); });
    const choose = k => { if (!k || !regions.has(k)) return; current = k; sel.value = k; fill(); };
    panel.addEventListener('change', e => {
      const el = e.target.closest('[data-gk-lab-f]');
      if (!el || el.dataset.gkLabF === 'text') return;
      const s = setting(current);
      const k = el.dataset.gkLabF;
      if (k === 'leader') { s.leader = el.checked; if (!el.checked) delete s.anchor; delete s.pos; target.delete('pos:' + current); target.delete('anchor:' + current); }
      else s[k] = el.value;
      tidy(current);
      mark();
      draft(0);
    });
    f('text').addEventListener('input', e => {
      if (e.target.readOnly) return;
      const v = e.target.value.split('\n').slice(0, 3).join('\n');
      if (ld.bound) texts[current] = v; else setting(current).text = v;
      tidy(current);
      mark();
      draft(400);
    });
    resetBtn.addEventListener('click', () => {
      const s = labels[current];
      if (!s) return;
      delete s.pos; delete s.anchor;
      target.delete('pos:' + current); target.delete('anchor:' + current);
      tidy(current);
      mark();
      draft(0);
      if (status) status.textContent = tl('posAuto') + ' · ' + tl('unsaved');
    });

    // Verschieben: Ziehen (Zeiger) – Text bzw. Ankerpunkt
    let drag = null;
    const target = new Map();     // 'pos:r12' → letzter Zielpunkt (Karteneinheiten)
    box.addEventListener('pointerdown', e => {
      const el = e.target.closest('[data-gk-lab], [data-gk-anchor]');
      if (!el || e.button > 0) return;
      const kind = el.dataset.gkLab ? 'pos' : 'anchor';
      const k = el.dataset.gkLab || el.dataset.gkAnchor;
      choose(k);
      const p = svgPoint(e);
      const base = target.get(kind + ':' + k) || [+el.dataset.x, +el.dataset.y];
      drag = { el, k, kind, x0: p.x, y0: p.y, ox: base[0], oy: base[1], sx: base[0] - +el.dataset.x, sy: base[1] - +el.dataset.y, moved: false, id: e.pointerId };
      el.setPointerCapture(e.pointerId);
      el.classList.add('is-drag');
      e.preventDefault();
    });
    box.addEventListener('pointermove', e => {
      if (!drag || e.pointerId !== drag.id) return;
      const p = svgPoint(e);
      const dx = p.x - drag.x0, dy = p.y - drag.y0;
      if (!drag.moved && Math.hypot(dx, dy) < 1.5) return;
      drag.moved = true;
      shift(drag.el, drag.kind, drag.sx + dx, drag.sy + dy);
    });
    const end = e => {
      if (!drag || e.pointerId !== drag.id) return;
      const d = drag;
      drag = null;
      d.el.classList.remove('is-drag');
      if (!d.moved) { (d.kind === 'pos' ? d.el.querySelector('.gk-lab__box') : d.el)?.focus({ preventScroll: true }); return; }
      const p = svgPoint(e);
      const x = d.ox + p.x - d.x0, y = d.oy + p.y - d.y0;
      target.set(d.kind + ':' + d.k, [x, y]);
      place(d.k, d.kind, x, y);
    };
    box.addEventListener('pointerup', end);
    box.addEventListener('pointercancel', end);
    // Klick auf ein Gebiet wählt es aus
    box.addEventListener('click', e => { const a = e.target.closest('[data-gk-key]'); if (a && !e.target.closest('[data-gk-lab]')) choose(a.dataset.gkKey); });

    // Zwischenbild beim Ziehen bzw. Tastendruck: Text (mit Kasten) verschieben, Linie mitziehen; Anker als Kreis
    const shift = (el, kind, dx, dy) => {
      if (kind === 'anchor') { el.setAttribute('cx', +el.dataset.x + dx); el.setAttribute('cy', +el.dataset.y + dy); return; }
      el.querySelectorAll('text, rect').forEach(n => n.setAttribute('transform', `translate(${dx} ${dy})`));
      const line = el.querySelector('.gk-leader');
      if (line) { const m = line.getAttribute('d').match(/^M([\d.-]+) ([\d.-]+)/); if (m) line.setAttribute('d', `M${m[1]} ${m[2]}L${+el.dataset.x + dx} ${+el.dataset.y + dy}`); }
    };
    const place = (k, kind, x, y) => {
      const g = toGeo(x, y);
      if (!g) return;
      setting(k)[kind] = g;
      mark();
      if (status) status.textContent = tl('moved', { name: nameOf(k) }) + ' · ' + tl('unsaved');
      describe();
      draft(0);
    };
    // Tastatur: Pfeile 1 Einheit, Umschalt 10; Enter/Leertaste wählt das Gebiet rechts aus. Zielpunkt je Beschriftung merken
    // (unabhängig davon, wann der Entwurf zurückkommt): Zwischenbild relativ zur gerade gezeigten Lage
    let keyTimer = null;
    box.addEventListener('keydown', e => {
      const el = e.target.closest('[data-gk-lab], [data-gk-anchor]');
      if (!el) return;
      const k = el.dataset.gkLab || el.dataset.gkAnchor;
      const kind = el.dataset.gkLab ? 'pos' : 'anchor';
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); choose(k); f('text').focus(); return; }
      const dir = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] }[e.key];
      if (!dir) return;
      e.preventDefault();
      if (current !== k) choose(k);
      const step = e.shiftKey ? 10 : 1;
      const id = kind + ':' + k;
      const base = target.get(id) || [+el.dataset.x, +el.dataset.y];
      const next = [base[0] + dir[0] * step, base[1] + dir[1] * step];
      target.set(id, next);
      shift(el, kind, next[0] - +el.dataset.x, next[1] - +el.dataset.y);
      const g = toGeo(next[0], next[1]);
      if (g) setting(k)[kind] = g;
      mark();
      if (status) status.textContent = tl('moved', { name: nameOf(k) }) + ' · ' + tl('unsaved');
      describe();
      clearTimeout(keyTimer);
      keyTimer = setTimeout(() => draft(0), 350);
    });
    box.addEventListener('focusin', e => { const el = e.target.closest('[data-gk-lab], [data-gk-anchor]'); if (el) choose(el.dataset.gkLab || el.dataset.gkAnchor); });

    saveBtn.addEventListener('click', async () => {
      saveBtn.disabled = true;
      clearTimeout(timer);
      seq++;
      if (status) status.textContent = tl('saving');
      try {
        const json = await post(ld.save, { labels, texts });
        if (json.box) render(json);
        if (json.preview) { const pv = document.querySelector('[data-gk-preview]:not([data-gk-labbox])'); if (pv) pv.innerHTML = json.preview; }
        if (json.texts && ld.bound) Object.entries(json.texts).forEach(([k, v]) => { const r = regions.get(k); if (r) r.text = v === r.name ? '' : v; });
        Object.keys(texts).forEach(k => delete texts[k]);
        fill();
        if (!json.ok) throw new Error(json.message || '');
        dirty = false;
        if (status) status.textContent = json.message || tl('saved');
      } catch (err) {
        saveBtn.disabled = false;
        if (status) status.textContent = err && err.message && err.message.length > 3 ? err.message : tl('error');
      }
    });
    window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = tl('leave'); } });
    fill();
  }
})();
