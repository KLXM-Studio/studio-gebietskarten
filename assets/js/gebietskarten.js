/*
 * Gebietskarten – Info-Fenster auf der Website (nur bei „Klick auf ein Gebiet: Info-Fenster zeigen“, lädt sonst nicht).
 * Ohne Skript springen die Gebiete zur Karte in der Liste (#gk-…); mit Skript erscheint dieselbe Karte als Fenster an der
 * Stelle des Klicks. Tastatur: Enter öffnet, Esc schließt, der Fokus kehrt zum Gebiet zurück. Position per CSSOM (CSP).
 */
(() => {
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
