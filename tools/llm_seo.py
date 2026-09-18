#!/usr/bin/env python3
"""Capa para busqueda en IA (leccion heredada de Fanatia, 2026-09).

Idempotente. Solo marca lo que YA es visible en la pagina; no inventa copy.

  1. FAQPage JSON-LD a partir de las preguntas frecuentes visibles
     (h2 "Preguntas frecuentes" + h3/p en articulos; <details>/<summary> en landings).
  2. Corrige los <br> escapados que se ven como texto.

Uso:  python tools/llm_seo.py          (escribe)
      python tools/llm_seo.py --check  (falla si algo quedaria distinto)
"""
from __future__ import annotations

import html as htmllib
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
START = "<!-- LLM_SEO:FAQ:START -->"
END = "<!-- LLM_SEO:FAQ:END -->"
SKIP_DIRS = {"comanda", "orden", "tools", ".git"}


def text_of(fragment: str) -> str:
    plain = re.sub(r"<[^>]+>", " ", fragment)
    return re.sub(r"\s+", " ", htmllib.unescape(plain)).strip()


def faq_pairs(page: str) -> list[tuple[str, str]]:
    pairs: list[tuple[str, str]] = []
    for q, a in re.findall(r"<summary[^>]*>(.*?)</summary>(.*?)</details>", page, re.S):
        pairs.append((text_of(q), text_of(a)))
    section = re.search(r"<h2[^>]*>\s*Preguntas frecuentes\s*</h2>(.*?)(?=<h2|</article|</main)", page, re.S)
    if section:
        for q, a in re.findall(r"<h3[^>]*>(.*?)</h3>(.*?)(?=<h3|$)", section.group(1), re.S):
            pairs.append((text_of(q), text_of(a)))
    return [(q, a) for q, a in pairs if q.endswith("?") and a]


def faq_block(pairs: list[tuple[str, str]]) -> str:
    data = {
        "@context": "https://schema.org",
        "@type": "FAQPage",
        "mainEntity": [
            {"@type": "Question", "name": q, "acceptedAnswer": {"@type": "Answer", "text": a}}
            for q, a in pairs
        ],
    }
    body = json.dumps(data, ensure_ascii=False, indent=2)
    return f'{START}\n<script type="application/ld+json">\n{body}\n</script>\n{END}'


def process(page: str) -> str:
    page = page.replace("&lt;br&gt;", "<br>")
    managed = re.compile(re.escape(START) + r".*?" + re.escape(END) + r"\n?", re.S)
    bare = managed.sub("", page)
    if '"FAQPage"' in bare.replace('": "', '":"').replace('":"FAQPage"', '"FAQPage"'):
        return bare  # ya trae FAQPage escrito a mano
    pairs = faq_pairs(bare)
    if not pairs:
        return bare
    return bare.replace("</head>", faq_block(pairs) + "\n</head>", 1)


def main() -> int:
    check = "--check" in sys.argv
    changed = []
    for path in sorted(ROOT.rglob("*.html")):
        if SKIP_DIRS & set(path.relative_to(ROOT).parts):
            continue
        original = path.read_text(encoding="utf-8")
        updated = process(original)
        if updated != original:
            changed.append(path.relative_to(ROOT).as_posix())
            if not check:
                path.write_text(updated, encoding="utf-8", newline="")
    for name in changed:
        print(("PENDIENTE " if check else "actualizado ") + name)
    print(f"{len(changed)} archivo(s)")
    return 1 if (check and changed) else 0


if __name__ == "__main__":
    sys.exit(main())
