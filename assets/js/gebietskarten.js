/*
 * Gebietskarten – Website (lädt nur bei Bedarf):
 *  - Info-Fenster (bei „Klick auf ein Gebiet: Info-Fenster zeigen“). Ohne Skript springen die Gebiete zur Karte in der Liste
 *    (#gk-…); mit Skript erscheint dieselbe Karte als Fenster an der Stelle des Klicks. Tastatur: Enter öffnet, Esc schließt,
 *    der Fokus kehrt zum Gebiet zurück. Position per CSSOM (CSP).
 *  - Hover-Effekt „animiert“ (Karten mit gk--hover-lift, seit 0.4.0): Kopie des Gebiets samt inneren Grenzen in eine Ebene über
 *    den Flächen und unter den Beschriftungen, um den Pol (data-gk-pole) angehoben – Federung, Schatten und Bewegung macht CSS;
 *    die Beschriftung (gk-lk) geht mit. Maus/Stift und Tastaturfokus (:focus-visible), nicht bei Berührung. Nur Klassen und
 *    CSSOM (transform-origin), keine style-Attribute. Ereignisse am Dokument – gilt auch für nachgeladene Vorschauen.
 */
(() => {
  const NS = 'http://www.w3.org/2000/svg';
  const AREA = '.gk--hover-lift .gk-area[data-gk-pole]';
  const lifted = new Map();      // svg → { key, layer, lab }
  let clips = 0;
  const down = (svg, key) => {
    const st = lifted.get(svg);
    if (!st || (key && st.key !== key)) return;
    lifted.delete(svg);
    st.layer.classList.remove('is-up');
    st.lab?.classList.remove('is-up');
    setTimeout(() => st.layer.remove(), 320);
  };
  const up = (area, focus) => {
    const svg = area.ownerSVGElement;
    const key = area.dataset.gkK;
    const src = area.querySelector('path');
    const [x, y] = String(area.dataset.gkPole).split(' ').map(Number);
    if (!svg || !src || !Number.isFinite(x) || !Number.isFinite(y)) return;
    const st = lifted.get(svg);
    if (st && st.key === key) { st.layer.classList.toggle('is-focus', !!focus); return; }
    down(svg);
    const origin = `${x}px ${y}px`;
    const layer = document.createElementNS(NS, 'g');
    layer.setAttribute('class', 'gk-lift' + (focus ? ' is-focus' : ''));
    layer.setAttribute('aria-hidden', 'true');
    layer.style.setProperty('transform-origin', origin);
    const shape = document.createElementNS(NS, 'g');
    shape.setAttribute('class', area.getAttribute('class').replace(/\bgk-area\b/, 'gk-liftarea').replace(/\bis-open\b/, ''));
    const dark = area.style.getPropertyValue('--gk-d');     // Vorschau der Verwaltung
    if (dark) shape.style.setProperty('--gk-d', dark);
    shape.append(src.cloneNode(false));
    layer.append(shape);
    // innere Grenzen: Linien der Karte, auf das Gebiet beschnitten
    const lines = svg.querySelector(':scope > .gk-lines');
    if (lines) {
      const clip = document.createElementNS(NS, 'clipPath');
      clip.id = `${svg.closest('.gk')?.id || 'gk'}-lift${++clips}`;
      clip.append(src.cloneNode(false));
      const l = lines.cloneNode(false);
      l.setAttribute('clip-path', `url(#${clip.id})`);
      layer.append(clip, l);
    }
    svg.insertBefore(layer, svg.querySelector(':scope > .gk-labels'));
    const lab = svg.querySelector(`.gk-lk[data-gk-k="${CSS.escape(key)}"]`);
    lab?.style.setProperty('transform-origin', origin);
    lifted.set(svg, { key, layer, lab });
    layer.getBoundingClientRect();
    requestAnimationFrame(() => { if (lifted.get(svg)?.layer === layer) { layer.classList.add('is-up'); lab?.classList.add('is-up'); } });
  };
  const areaOf = t => (t instanceof Element ? t.closest(AREA) : null);
  document.addEventListener('pointerover', e => { if (e.pointerType !== 'touch') { const a = areaOf(e.target); if (a) up(a, false); } });
  document.addEventListener('pointerout', e => {
    const a = areaOf(e.target);
    if (!a || a.contains(e.relatedTarget) || (a === document.activeElement && a.matches(':focus-visible'))) return;
    down(a.ownerSVGElement, a.dataset.gkK);
  });
  document.addEventListener('focusin', e => { const a = areaOf(e.target); if (a && a.matches(':focus-visible')) up(a, true); });
  document.addEventListener('focusout', e => { const a = areaOf(e.target); if (a) down(a.ownerSVGElement, a.dataset.gkK); });

  const init = fig => {
    if (fig.dataset.gkReady) return;
    fig.dataset.gkReady = '1';
    const pop = fig.querySelector('[data-gk-pop]');
    const body = pop?.querySelector('[data-gk-body]');
    const map = fig.querySelector('.gk-map');
    if (!pop || !body || !map) return;
    let opener = null;

    const close = (focus = true) => {
      if (pop.hidden) return;
      pop.hidden = true;
      body.textContent = '';
      fig.querySelectorAll('.gk-area.is-open').forEach(a => a.classList.remove('is-open'));
      if (focus && opener) opener.focus();
      opener = null;
    };

    const open = (area, x, y) => {
      const item = fig.querySelector('#' + CSS.escape(fig.id + '-' + area.dataset.gkInfo));
      const card = item?.querySelector('[data-gk-card]');
      if (!card) return false;
      close(false);
      const copy = card.cloneNode(true);
      copy.querySelectorAll('[id]').forEach(n => n.removeAttribute('id'));
      const title = copy.querySelector('.gk-info__title');
      if (title) title.id = fig.id + '-pop-t';
      body.append(copy);
      pop.setAttribute('aria-labelledby', fig.id + '-pop-t');
      pop.hidden = false;
      area.classList.add('is-open');
      opener = area;
      // an der Klickstelle, im Rahmen der Karte
      const r = map.getBoundingClientRect();
      const w = pop.offsetWidth, h = pop.offsetHeight;
      let left = x - r.left - w / 2, top = y - r.top - h - 14;
      if (top < 4) top = y - r.top + 14;
      left = Math.max(4, Math.min(left, r.width - w - 4));
      top = Math.max(4, Math.min(top, Math.max(4, r.height - h - 4)));
      pop.style.setProperty('left', left + 'px');
      pop.style.setProperty('top', top + 'px');
      (pop.querySelector('.gk-info__more a') || pop.querySelector('[data-gk-close]')).focus({ preventScroll: true });
      return true;
    };

    fig.addEventListener('click', e => {
      const area = e.target.closest('a.gk-area[data-gk-info]');
      if (area) {
        let x = e.clientX, y = e.clientY;
        if (!x && !y) { const b = area.getBoundingClientRect(); x = b.left + b.width / 2; y = b.top + b.height / 2; }
        if (open(area, x, y)) e.preventDefault();
        return;
      }
      if (e.target.closest('[data-gk-close]')) close();
    });
    fig.addEventListener('keydown', e => {
      if (e.key === 'Escape' && !pop.hidden) { e.preventDefault(); close(); }
    });
    document.addEventListener('click', e => {
      if (!pop.hidden && !pop.contains(e.target) && !e.target.closest('a.gk-area')) close(false);
    });
  };

  const run = () => document.querySelectorAll('.gk[data-gk-action="info"]').forEach(init);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
  document.addEventListener('cms:block-preview', run);
})();
