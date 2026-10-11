"""Run after catalog/blog generators. Idempotent; excludes payment and order pages."""
from pathlib import Path
import re
from html.parser import HTMLParser

ROOT = Path(__file__).resolve().parents[1]
BLOCK = '''<!-- PT_COMMUNITY:START -->
<section class="pt-community" aria-labelledby="pt-community-heading">
  <div><div class="pt-community-eyebrow">La sobremesa · El newsletter de Picando Tabla</div>
    <h2 id="pt-community-heading">Una buena idea para tu próxima reunión.</h2>
    <p>Ideas para compartir, novedades y propuestas para celebrar. Recíbelas en tu correo y sigue lo que estamos preparando en redes.</p>
    <nav class="pt-social-links" aria-label="Redes sociales de Picando Tabla"><a href="https://www.instagram.com/picandotabla/" target="_blank" rel="noopener noreferrer">Instagram · @picandotabla ↗</a><a href="https://www.facebook.com/profile.php?id=61591673554258" target="_blank" rel="noopener noreferrer">Facebook ↗</a></nav>
  </div>
  <form class="pt-newsletter-form" action="/newsletter/subscribe.php" method="post">
    <label for="pt-newsletter-email">Tu correo electrónico</label><input id="pt-newsletter-email" name="email" type="email" autocomplete="email" placeholder="tu@correo.com" maxlength="254" required>
    <label class="pt-consent"><input type="checkbox" name="consent" required><span>Quiero recibir ideas, novedades y propuestas de Picando Tabla. Puedo darme de baja cuando quiera. <a href="/newsletter/privacy.html">Cómo se usa mi correo</a>.</span></label>
    <div class="pt-honey" aria-hidden="true"><label>Sitio web<input name="website" tabindex="-1" autocomplete="off"></label></div>
    <button class="pt-community-button" type="submit">Quiero recibir La sobremesa</button><p role="status" aria-live="polite"></p>
    <noscript>Activa JavaScript para suscribirte desde este formulario.</noscript>
  </form>
</section>
<!-- PT_COMMUNITY:END -->'''
class FooterFinder(HTMLParser):
    """Ignore footer-looking markup inside scripts or purchase dialogs."""
    def __init__(self, source):
        super().__init__()
        self.offsets = [0]
        for line in source.splitlines(keepends=True):
            self.offsets.append(self.offsets[-1] + len(line))
        self.dialogs = 0
        self.footer = None
    def handle_starttag(self, tag, attrs):
        if tag == 'dialog': self.dialogs += 1
        if tag == 'footer' and not self.dialogs and self.footer is None:
            line, col = self.getpos()
            self.footer = self.offsets[line - 1] + col
    def handle_endtag(self, tag):
        if tag == 'dialog': self.dialogs = max(0, self.dialogs - 1)

def integrate(s):
    if '</body>' not in s: return s
    s = re.sub(r'<!-- PT_COMMUNITY:START -->.*?<!-- PT_COMMUNITY:END -->\s*', '', s, flags=re.S)
    s = re.sub(r'<link rel="stylesheet" href="/newsletter/community\.css(?:\?[^\"]*)?">\n?', '', s)
    s = re.sub(r'<script defer src="/newsletter/community\.js(?:\?[^\"]*)?"></script>\n?', '', s)
    s = s.replace('</head>', '<link rel="stylesheet" href="/newsletter/community.css?v=20261010b">\n<script defer src="/newsletter/community.js?v=20261010b"></script>\n</head>')
    parser = FooterFinder(s)
    parser.feed(s)
    pos = s.find('<!-- Footer CTA -->')
    if pos < 0: pos = parser.footer if parser.footer is not None else s.find('</body>')
    return s[:pos] + BLOCK + '\n' + s[pos:]

def install(root=ROOT):
    count = 0
    for p in root.rglob('*.html'):
        rel = p.relative_to(root)
        if rel.parts[0] in {'.git','newsletter','orden','pago','comanda'}: continue
        s = p.read_text(encoding='utf-8')
        if re.search(r'<meta[^>]+name=[\"\']robots[\"\'][^>]+content=[\"\'][^\"\']*noindex', s, re.I): continue
        updated = integrate(s)
        if updated != s:
            p.write_text(updated, encoding='utf-8', newline='\n')
            count += 1
    print(f'Community modules updated: {count} pages.')

if __name__ == '__main__': install()
