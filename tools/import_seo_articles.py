#!/usr/bin/env python3
"""Importa el paquete Markdown de artículos SEO al blog estático."""

from __future__ import annotations

import argparse
import hashlib
import html
import json
import re
import shutil
from pathlib import Path


PROMO = "PROMOCIONES · Tabla para Dos $485 hasta 20 sep · Caja de tapas GRATIS en tablas para más de 4 personas"
LEGACY_ARTICLES = [
    ("01-cuanto-queso-charcuteria-por-persona.md", "rinde-por-tabla.html", "01-cuanto-queso-por-persona", "Guías", "/blog/guias/"),
    ("02-botanas-para-reuniones-cdmx.md", "botanas-para-reuniones-cdmx.html", "02-botanas-para-reuniones", "Reuniones", "/blog/reuniones/"),
    ("03-cuanto-cuesta-tabla-quesos-cdmx.md", "precio-tabla-quesos-cdmx.html", "03-precio-tabla-quesos-cdmx", "Guías", "/blog/guias/"),
    ("04-que-quesos-lleva-una-tabla.md", "como-armar-tabla-de-quesos.html", "04-que-quesos-lleva-una-tabla", "Guías", "/blog/guias/"),
    ("05-ideas-regalos-gourmet-cdmx.md", "regalo-tabla-de-quesos.html", "05-ideas-regalos-gourmet-cdmx", "Regalos", "/blog/regalos/"),
]
AI_ARTICLES = [
    ("01-que-servir-reunion-evento-cdmx.md", "botanas-para-reuniones-cdmx.html", "01-que-servir-reunion-evento-cdmx", "Reuniones", "/blog/reuniones/", "2026-08-25"),
    ("02-cuanta-tabla-quesos-reunion-evento.md", "rinde-por-tabla.html", "02-cuanta-tabla-quesos-reunion-evento", "Guías", "/blog/guias/", "2026-08-25"),
    ("03-tabla-quesos-cumpleanos-cdmx.md", "tabla-quesos-cumpleanos-cdmx.html", "03-tabla-quesos-cumpleanos-cdmx", "Eventos", "/blog/eventos/", "2026-08-27"),
    ("04-tabla-quesos-vino-reunion-cdmx.md", "maridaje-queso-vino-principiantes.html", "04-tabla-quesos-vino-reunion-cdmx", "Guías", "/blog/guias/", "2026-08-27"),
    ("05-tablas-charcuteria-eventos-cdmx.md", "tablas-charcuteria-eventos-cdmx.html", "05-tablas-charcuteria-eventos-cdmx", "Eventos", "/blog/eventos/", "2026-08-27"),
]


def parse_frontmatter(source: str) -> tuple[dict[str, str], str]:
    if not source.startswith("---\n"):
        raise ValueError("El artículo no contiene frontmatter")
    raw_meta, body = source[4:].split("\n---\n", 1)
    meta: dict[str, str] = {}
    for line in raw_meta.splitlines():
        if line.startswith((" ", "-")) or ":" not in line:
            continue
        key, value = line.split(":", 1)
        meta[key.strip()] = value.strip().strip('"')
    return meta, body.strip()


def adapt_copy(value: str) -> str:
    replacements = [
        (r"/orden/", "/#tablas"),
        (r"\bDips\b", "Untables"),
        (r"\bdips\b", "untables"),
        (r"\bDip\b", "Untable"),
        (r"\bdip\b", "untable"),
        (r"\bMermeladas\b", "Conservas dulces"),
        (r"\bmermeladas\b", "conservas dulces"),
        (r"\bMermelada\b", "Conserva dulce"),
        (r"\bmermelada\b", "conserva dulce"),
    ]
    for pattern, replacement in replacements:
        value = re.sub(pattern, replacement, value)
    return value


def inline(value: str) -> str:
    escaped = html.escape(value, quote=False)
    escaped = re.sub(
        r"\[([^\]]+)\]\(([^)]+)\)",
        lambda m: f'<a href="{html.escape(m.group(2), quote=True)}">{m.group(1)}</a>',
        escaped,
    )
    escaped = re.sub(r"\*\*([^*]+)\*\*", r"<strong>\1</strong>", escaped)
    escaped = re.sub(r"(?<!\*)\*([^*]+)\*(?!\*)", r"<em>\1</em>", escaped)
    escaped = re.sub(r"`([^`]+)`", r"<code>\1</code>", escaped)
    return escaped


def table_html(lines: list[str]) -> str:
    rows = [[cell.strip() for cell in line.strip().strip("|").split("|")] for line in lines]
    if len(rows) > 1 and all(re.fullmatch(r":?-{3,}:?", cell) for cell in rows[1]):
        headings, body = rows[0], rows[2:]
    else:
        headings, body = rows[0], rows[1:]
    head = "".join(f"<th>{inline(cell)}</th>" for cell in headings)
    body_html = "".join("<tr>" + "".join(f"<td>{inline(cell)}</td>" for cell in row) + "</tr>" for row in body)
    return f'<div class="table-scroll"><table><thead><tr>{head}</tr></thead><tbody>{body_html}</tbody></table></div>'


def markdown_to_html(markdown: str) -> str:
    lines = markdown.splitlines()
    output: list[str] = []
    paragraph: list[str] = []
    index = 0

    def flush_paragraph() -> None:
        if not paragraph:
            return
        joined = " ".join(part.strip() for part in paragraph)
        joined = joined.replace("  <br>", "<br>")
        output.append(f"<p>{inline(joined).replace('&lt;br&gt;', '<br>')}</p>")
        paragraph.clear()

    while index < len(lines):
        line = lines[index]
        if not line.strip():
            flush_paragraph()
            index += 1
            continue
        heading = re.match(r"^(#{1,3})\s+(.+)$", line)
        if heading:
            flush_paragraph()
            level = len(heading.group(1))
            if level > 1:
                output.append(f"<h{level}>{inline(heading.group(2))}</h{level}>")
            index += 1
            continue
        if line.startswith("|"):
            flush_paragraph()
            block: list[str] = []
            while index < len(lines) and lines[index].startswith("|"):
                block.append(lines[index])
                index += 1
            output.append(table_html(block))
            continue
        if line.startswith("- "):
            flush_paragraph()
            items: list[str] = []
            while index < len(lines) and lines[index].startswith("- "):
                items.append(f"<li>{inline(lines[index][2:].strip())}</li>")
                index += 1
            output.append("<ul>" + "".join(items) + "</ul>")
            continue
        if re.match(r"^\d+\.\s+", line):
            flush_paragraph()
            items = []
            while index < len(lines) and re.match(r"^\d+\.\s+", lines[index]):
                item_text = re.sub(r"^\d+\.\s+", "", lines[index])
                items.append(f"<li>{inline(item_text)}</li>")
                index += 1
            output.append("<ol>" + "".join(items) + "</ol>")
            continue
        if line.startswith("> "):
            flush_paragraph()
            quoted: list[str] = []
            while index < len(lines) and lines[index].startswith("> "):
                quoted.append(lines[index][2:].rstrip().removesuffix("  "))
                index += 1
            output.append("<blockquote>" + "<br>".join(inline(item) for item in quoted) + "</blockquote>")
            continue
        if line.endswith("  "):
            paragraph.append(line[:-2] + "  <br>")
        else:
            paragraph.append(line)
        index += 1
    flush_paragraph()
    return "\n        ".join(output)


def render(meta: dict[str, str], body: str, image_key: str, category: str, category_url: str, published: str | None = None, responsive: bool = True) -> str:
    h1_match = re.search(r"^#\s+(.+)$", body, re.MULTILINE)
    if not h1_match:
        raise ValueError("El artículo no contiene H1")
    headline = h1_match.group(1).strip()
    body = body[: h1_match.start()] + body[h1_match.end() :]
    article_html = markdown_to_html(adapt_copy(body).strip())
    canonical = meta.get("canonical") or meta["canonical_url"]
    description = adapt_copy(meta.get("description") or meta["meta_description"])
    seo_title = adapt_copy(meta.get("seo_title") or meta["meta_title"])
    page_title = seo_title if "Picando Tabla" in seo_title else f"{seo_title} | Picando Tabla"
    image_alt = adapt_copy(meta["image_alt"])
    hero = f"/img/blog/{image_key}-1600x900.webp"
    og = f"https://picandotabla.com/img/blog/{image_key}-og-1200x630.jpg"
    schema_article = {
        "@context": "https://schema.org",
        "@type": "Article",
        "headline": headline,
        "description": description,
        "image": [f"https://picandotabla.com{hero}"],
        "datePublished": meta.get("date_published") or published or "2026-08-27",
        "dateModified": meta.get("date_modified") or meta.get("last_reviewed") or "2026-08-27",
        "inLanguage": "es-MX",
        "mainEntityOfPage": canonical,
        "author": {"@type": "Organization", "name": "Picando Tabla", "url": "https://picandotabla.com/"},
        "publisher": {"@type": "Organization", "name": "Picando Tabla", "url": "https://picandotabla.com/"},
    }
    schema_breadcrumb = {
        "@context": "https://schema.org",
        "@type": "BreadcrumbList",
        "itemListElement": [
            {"@type": "ListItem", "position": 1, "name": "Inicio", "item": "https://picandotabla.com/"},
            {"@type": "ListItem", "position": 2, "name": "Blog", "item": "https://picandotabla.com/blog/"},
            {"@type": "ListItem", "position": 3, "name": category, "item": f"https://picandotabla.com{category_url}"},
            {"@type": "ListItem", "position": 4, "name": headline, "item": canonical},
        ],
    }
    return f'''<!doctype html>
<html lang="es-MX">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <script async src="https://www.googletagmanager.com/gtag/js?id=G-4BMCS7P6DQ"></script>
  <script>window.dataLayer=window.dataLayer||[];function gtag(){{dataLayer.push(arguments)}}gtag("js",new Date());gtag("config","G-4BMCS7P6DQ");function articleCta(el,placement){{try{{gtag("event","article_cta_click",{{article:location.pathname,placement:placement}})}}catch(e){{}}var source=new URLSearchParams(location.search),target=new URLSearchParams;["utm_source","utm_medium","utm_campaign","utm_term","utm_content"].forEach(function(key){{if(source.has(key))target.set(key,source.get(key))}});if(target.toString())el.href="/?"+target.toString()+"#tablas"}}</script>
  <title>{html.escape(page_title)}</title>
  <meta name="description" content="{html.escape(description, quote=True)}">
  <link rel="canonical" href="{html.escape(canonical, quote=True)}">
  <meta name="robots" content="index,follow,max-image-preview:large">
  <meta property="og:type" content="article">
  <meta property="og:title" content="{html.escape(headline, quote=True)}">
  <meta property="og:description" content="{html.escape(description, quote=True)}">
  <meta property="og:url" content="{html.escape(canonical, quote=True)}">
  <meta property="og:image" content="{og}">
  <meta property="og:locale" content="es_MX">
  <meta property="og:site_name" content="Picando Tabla">
  <meta name="twitter:card" content="summary_large_image">
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <meta name="theme-color" content="#26282a">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Lora:ital,wght@0,500;0,600;0,700;1,400&family=Work+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    *{{box-sizing:border-box;margin:0;padding:0}}html{{scroll-behavior:smooth}}body{{background:#e9eaea;color:#26282a;font-family:"Work Sans",system-ui,sans-serif;line-height:1.75}}h1,h2,h3{{font-family:"Lora",Georgia,serif;font-weight:600;line-height:1.2;color:#26282a}}a{{color:#7c2d3e;text-decoration:none}}a:hover{{text-decoration:underline}}.promo{{position:sticky;top:0;z-index:51;min-height:60px;background:#6f1d33;color:#fff;text-align:center;padding:9px 14px;font-size:14px;font-weight:700;line-height:1.3;letter-spacing:.3px;display:flex;align-items:center;justify-content:center;box-shadow:0 3px 12px #26282a2b}}.hdr{{position:sticky;top:60px;z-index:50;background:#e9eaeaf2;backdrop-filter:blur(8px);border-bottom:1px solid #d3d5d4}}.hdr .w{{max-width:1100px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;padding:14px 22px;gap:16px}}.logo{{font-family:"Lora",serif;font-weight:700;font-size:21px;color:#26282a;line-height:1.1}}.logo small{{display:block;font-family:"Work Sans",sans-serif;font-size:10px;letter-spacing:2.5px;color:#75797a;text-transform:uppercase;font-weight:600}}.right,.mid{{display:flex;align-items:center}}.right{{gap:16px}}.mid{{gap:20px;font-size:14px}}.mid a{{color:#55585a}}.mid .on{{color:#7c2d3e;font-weight:600}}.btn-o{{color:#7c2d3e!important;padding:9px 16px;border-radius:999px;font-weight:600;border:1.5px solid #7c2d3e}}.btn-wa{{display:inline-flex;align-items:center;gap:8px;background:#26282a;color:#fff!important;padding:10px 18px;border-radius:999px;font-weight:600}}.dot{{width:15px;height:15px;border-radius:50%;background:#25d366;display:inline-block}}.wrap{{max-width:840px;margin:0 auto;padding:0 22px}}article{{padding:34px 0 48px}}.back{{font-size:13px;color:#75797a;margin-bottom:18px}}.hero{{width:100%;height:auto;display:block;border-radius:18px;box-shadow:0 12px 34px #26282a20}}figcaption{{font-size:12px;color:#75797a;margin-top:8px;text-align:center}}.kick{{font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#7c2d3e;font-weight:700;margin-top:28px}}article h1{{font-size:clamp(30px,5vw,44px);margin:10px 0 10px}}.meta{{font-size:13px;color:#75797a;margin-bottom:24px}}.content h2{{font-size:26px;margin:34px 0 10px}}.content h3{{font-size:20px;margin:26px 0 8px}}.content p{{font-size:16.5px;color:#55585a;margin:13px 0}}.content ul,.content ol{{margin:12px 0 18px 24px;color:#55585a}}.content li{{padding:2px 0}}.content blockquote{{background:#fbfcfc;border-left:4px solid #7c2d3e;border-radius:0 12px 12px 0;padding:18px 22px;margin:22px 0;color:#55585a;font-family:Lora,serif;font-size:18px}}.table-scroll{{overflow-x:auto;margin:18px 0 24px;border:1px solid #d3d5d4;border-radius:14px;background:#fbfcfc}}table{{border-collapse:collapse;width:100%;min-width:590px}}th,td{{padding:12px 14px;text-align:left;border-bottom:1px solid #d3d5d4;vertical-align:top}}th{{background:#26282a;color:#fff;font-size:13px}}td{{font-size:14px;color:#55585a}}tr:last-child td{{border-bottom:0}}.cta-box{{background:#fbfcfc;border:1px solid #d3d5d4;border-left:4px solid #7c2d3e;border-radius:14px;padding:22px;margin:36px 0}}.cta-box b{{font-family:Lora,serif;font-size:20px}}.cta-box .btn{{display:inline-block;margin-top:12px;background:#26282a;color:#fff!important;border-radius:999px;padding:12px 24px;font-weight:600}}footer{{border-top:1px solid #d3d5d4;padding:26px 0;color:#75797a;font-size:13px;text-align:center}}@media(max-width:720px){{.mid{{display:none}}.promo{{min-height:64px;font-size:12px}}.hdr{{top:64px}}.btn-wa{{padding:9px 13px;font-size:13px}}article{{padding-top:24px}}.content h2{{font-size:23px}}}}
  </style>
  <script type="application/ld+json">{json.dumps(schema_article, ensure_ascii=False, separators=(',', ':'))}</script>
  <script type="application/ld+json">{json.dumps(schema_breadcrumb, ensure_ascii=False, separators=(',', ':'))}</script>
</head>
<body>
  <div class="promo">{PROMO}</div>
  <header class="hdr"><div class="w"><a href="/" style="text-decoration:none"><div class="logo">Picando Tabla<small>Quesos · Charcutería · CDMX</small></div></a><div class="right"><nav class="mid" aria-label="Principal"><a href="/tablas/">Tablas</a><a href="/reuniones/">Reuniones</a><a href="/regalos/">Regalos</a><a href="/blog/" class="on">Blog</a><a href="/eventos/" class="btn-o">Eventos</a></nav><a href="/#tablas" class="btn-wa" onclick="articleCta(this,'header')"><span class="dot"></span>Elegir tabla</a></div></div></header>
  <article><div class="wrap">
    <nav class="back" aria-label="Migas de pan"><a href="/">Inicio</a> › <a href="/blog/">Blog</a> › <a href="{category_url}">{html.escape(category)}</a></nav>
    <div class="kick" style="margin-top:0">{html.escape(category)} · Picando Tabla</div>
    <h1>{html.escape(headline)}</h1>
    <div class="meta">Actualizado el 27 de agosto de 2026 · {html.escape(meta.get('reading_time', '7 min'))} de lectura</div>
    <figure>{hero_picture(image_key, hero, image_alt, responsive)}<figcaption>Imagen editorial ilustrativa; la selección final puede variar según temporada.</figcaption></figure>
    <div class="content">{article_html}</div>
    <div class="cta-box"><b>¿Ya sabes para cuántas personas es?</b><br>Elige tu tabla en el landing y completa ahí mismo los datos de tu pedido.<br><a class="btn" href="/#tablas" onclick="articleCta(this,'final')">Ver tablas y pedir</a></div>
  </div></article>
  <footer><div class="wrap">Picando Tabla · Entregas en CDMX · <a href="/eventos/">Cotiza tu evento</a></div></footer>
</body>
</html>
'''


def hero_picture(image_key: str, hero: str, image_alt: str, responsive: bool) -> str:
    alt = html.escape(image_alt, quote=True)
    if responsive:
        return f'<picture><source type="image/webp" srcset="/img/blog/{image_key}-800x450.webp 800w, /img/blog/{image_key}-1200x675.webp 1200w, {hero} 1600w" sizes="(max-width:900px) 100vw, 840px"><img class="hero" src="{hero}" width="1600" height="900" alt="{alt}" loading="eager" fetchpriority="high" decoding="async"></picture>'
    return f'<picture><source type="image/webp" srcset="{hero}"><img class="hero" src="/img/blog/{image_key}-1600x900.jpg" width="1600" height="900" alt="{alt}" loading="eager" fetchpriority="high" decoding="async"></picture>'


def copy_images(package: Path, destination: Path, image_dir: str) -> None:
    destination.mkdir(parents=True, exist_ok=True)
    for source in (package / image_dir).glob("*/*"):
        if source.is_file() and source.suffix.lower() in {".webp", ".jpg", ".jpeg", ".png"}:
            shutil.copy2(source, destination / source.name)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("package", type=Path, help="Carpeta picando_tabla_seo_5_articulos extraída")
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[1])
    args = parser.parse_args()
    package = args.package.resolve()
    root = args.root.resolve()
    is_ai_package = (package / "articles" / "01-que-servir-reunion-evento-cdmx.md").exists()
    article_dir = "articles" if is_ai_package else "articulos"
    image_dir = "images" if is_ai_package else "imagenes"
    articles = AI_ARTICLES if is_ai_package else [(*item, None) for item in LEGACY_ARTICLES]
    copy_images(package, root / "img" / "blog", image_dir)
    for source_name, target_name, image_key, category, category_url, published in articles:
        source_path = package / article_dir / source_name
        meta, body = parse_frontmatter(source_path.read_text(encoding="utf-8"))
        result = render(meta, body, image_key, category, category_url, published, responsive=not is_ai_package)
        target = root / "blog" / target_name
        target.write_text(result, encoding="utf-8", newline="\n")
        print(f"{target.relative_to(root)}  {hashlib.sha256(result.encode()).hexdigest()[:12]}")


if __name__ == "__main__":
    main()
