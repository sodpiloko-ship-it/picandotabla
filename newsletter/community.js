(() => {
  'use strict';
  if (!document.querySelector('.pt-community')) return;
  const get = key => { try { return localStorage.getItem(key); } catch { return null; } };
  const set = (key, value) => { try { localStorage.setItem(key, value); } catch {} };
  const form = document.querySelector('.pt-newsletter-form');
  const dialog = document.createElement('dialog');
  dialog.className = 'pt-newsletter-dialog';
  dialog.setAttribute('aria-labelledby', 'pt-newsletter-title');
  dialog.innerHTML = '<button type="button" class="pt-newsletter-close" aria-label="Cerrar invitación">×</button><div class="pt-community-eyebrow">La sobremesa · Picando Tabla</div><h2 id="pt-newsletter-title">Las buenas reuniones empiezan con una idea.</h2><p>Recibe inspiración para compartir, novedades y propuestas para tu próxima celebración.</p>';
  const copy = form.cloneNode(true);
  copy.querySelectorAll('[id]').forEach(el => { const old = el.id; el.id += '-popup'; copy.querySelectorAll('label[for]').forEach(label => { if (label.htmlFor === old) label.htmlFor = el.id; }); });
  dialog.append(copy);
  const later = document.createElement('button'); later.type = 'button'; later.className = 'pt-newsletter-later'; later.textContent = 'En otro momento'; dialog.append(later);
  document.body.append(dialog);
  let previousFocus;
  const dismiss = () => { set('pt-newsletter-dismissed', String(Date.now())); dialog.close(); previousFocus?.focus(); };
  later.addEventListener('click', dismiss);
  dialog.querySelector('.pt-newsletter-close').addEventListener('click', dismiss);
  dialog.addEventListener('cancel', e => { e.preventDefault(); dismiss(); });
  dialog.addEventListener('click', e => { const r = dialog.getBoundingClientRect(); if (e.target === dialog && (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom)) dismiss(); });
  document.querySelectorAll('.pt-newsletter-form').forEach(f => f.addEventListener('submit', async e => {
    e.preventDefault(); if (!f.reportValidity()) return;
    const button = f.querySelector('[type=submit]'), status = f.querySelector('[role=status]');
    button.disabled = true; status.textContent = 'Guardando tu solicitud…';
    const data = new FormData(f);
    try {
      const response = await fetch('/newsletter/subscribe.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: data.get('email'), consent: data.get('consent') === 'on', website: data.get('website'), source: location.pathname + (f === copy ? '#popup' : '#newsletter') }), signal: AbortSignal.timeout(15000) });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.message || 'No pudimos guardar la solicitud. Intenta de nuevo.');
      status.textContent = result.message; set('pt-newsletter-requested', String(Date.now())); f.reset();
    } catch (error) { status.textContent = error.name === 'TimeoutError' ? 'La conexión tardó demasiado. Intenta de nuevo.' : (error instanceof TypeError ? 'No pudimos conectar. Revisa tu conexión e intenta de nuevo.' : error.message); }
    finally { button.disabled = false; }
  }));
  // Wait for reading engagement. Never interrupt a form, checkout or another dialog.
  let ready = false, shown = false;
  const offer = () => {
    if (!ready || shown) return;
    const quietUntil = Math.max(Number(get('pt-newsletter-dismissed')) || 0, Number(get('pt-newsletter-requested')) || 0) + 30 * 86400000;
    if (Date.now() < quietUntil || document.visibilityState !== 'visible' || scrollY < 180 || /INPUT|TEXTAREA|SELECT/.test(document.activeElement?.tagName || '') || document.querySelector('dialog[open]')) return;
    const productModal = document.getElementById('ptModalBg');
    if (productModal && getComputedStyle(productModal).display !== 'none') return;
    if (typeof dialog.showModal === 'function') { shown = true; previousFocus = document.activeElement; dialog.showModal(); set('pt-newsletter-dismissed', String(Date.now())); }
  };
  setTimeout(() => { ready = true; offer(); }, 25000);
  window.addEventListener('scroll', offer, {passive: true});
})();
