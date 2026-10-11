#!/usr/bin/env python3
"""Genera /vinos/ desde datos editoriales, sin tocar pedidos ni datos del servidor.

python tools/build_wines.py             # validar y generar
python tools/build_wines.py --check     # detectar diferencias sin escribir
python tools/build_wines.py --new slug  # agregar una ficha en borrador
"""
from __future__ import annotations

import argparse
import copy
from datetime import date, datetime
import html
import json
from pathlib import Path
import re
import sys
from urllib.parse import urlencode, urlparse
from zoneinfo import ZoneInfo

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "data/vinos.json"
BASE = "https://picandotabla.com"
WHATSAPP = "525623632404"
SCHEMA = "picandotabla.wines.v1"
MARKER = "<!-- Generado por tools/build_wines.py; editar data/vinos.json. -->"
TYPES = {"tinto": "Tinto", "blanco": "Blanco", "rosado": "Rosado", "espumoso": "Espumoso", "dulce": "Dulce / de postre"}
TABLE_PATHS = {"dos": "/tablas/para-dos/", "anfitriona": "/tablas/anfitriona/", "fiesta": "/tablas/fiesta/", "celebracion": "/tablas/celebracion/"}
MONTHS = ("enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre")


def today() -> date:
    return datetime.now(ZoneInfo("America/Mexico_City")).date()


def require(condition: bool, message: str) -> None:
    if not condition:
        raise ValueError(message)


def iso(value: object, field: str) -> date:
    require(isinstance(value, str) and bool(re.fullmatch(r"\d{4}-\d{2}-\d{2}", value)), f"{field}: fecha YYYY-MM-DD obligatoria")
    try:
        return date.fromisoformat(value)
    except ValueError as exc:
        raise ValueError(f"{field}: fecha inválida") from exc


def safe_url(value: object, field: str, internal: bool = False) -> None:
    require(isinstance(value, str) and bool(value), f"{field}: URL obligatoria")
    parsed = urlparse(value)
    require(not parsed.username and not parsed.password, f"{field}: no admite credenciales")
    if internal:
        require(value.startswith("/") and not value.startswith("//") and not parsed.scheme and ".." not in parsed.path.split("/"), f"{field}: usar una ruta interna absoluta")
    else:
        require(parsed.scheme == "https" and bool(parsed.hostname), f"{field}: usar HTTPS")


def nonempty(value: object) -> bool:
    return isinstance(value, str) and bool(value.strip())


def validate_catalog(data: dict, table_catalog: dict) -> list[dict]:
    require(isinstance(data, dict) and data.get("schema_version") == SCHEMA, f"schema_version debe ser {SCHEMA}")
    updated = iso(data.get("updated_at"), "updated_at")
    require(updated <= today(), "updated_at no puede estar en el futuro")
    wines = data.get("wines")
    require(isinstance(wines, list), "wines debe ser una lista")
    products = {p["key"]: p for p in table_catalog["products"] if p.get("status") == "active"}
    ids, slugs = set(), set()
    published = []
    for wine in wines:
        require(isinstance(wine, dict), "cada vino debe ser un objeto")
        slug = wine.get("slug")
        require(isinstance(slug, str) and bool(re.fullmatch(r"[a-z0-9]+(?:-[a-z0-9]+)*", slug)) and len(slug) <= 100, "slug inválido; usa palabras en minúsculas separadas con guion")
        require(slug not in {"como-resenamos", "index", "catalogo"}, f"slug reservado: {slug}")
        require(nonempty(wine.get("id")), f"{slug}: id obligatorio")
        require(wine["id"] not in ids and slug not in slugs, f"id o slug duplicado: {slug}")
        ids.add(wine["id"])
        slugs.add(slug)
        require(wine.get("status") in {"draft", "published"}, f"{slug}: status debe ser draft o published")
        if wine["status"] == "draft":
            continue
        for field in ("name", "brand", "country", "summary", "palate"):
            require(nonempty(wine.get(field)), f"{slug}: falta {field}")
        require(len(wine["summary"]) >= 70, f"{slug}: summary debe explicar el vino en al menos 70 caracteres")
        require(wine.get("type") in TYPES, f"{slug}: type inválido")
        for field in ("description", "aromas", "considerations", "grapes"):
            require(isinstance(wine.get(field), list) and all(nonempty(v) for v in wine[field]), f"{slug}: {field} debe ser una lista de textos")
        require(wine["description"] and wine["grapes"], f"{slug}: descripción y uvas obligatorias")
        for field in ("region", "sweetness", "body"):
            require(wine.get(field) is None or nonempty(wine[field]), f"{slug}: {field} debe ser texto o null")
        for field, low, high in (("vintage", 1900, today().year), ("volume_ml", 1, 30000)):
            value = wine.get(field)
            require(value is None or (type(value) is int and low <= value <= high), f"{slug}: {field} inválido")
        alcohol = wine.get("alcohol_percent")
        require(alcohol is None or (type(alcohol) in (int, float) and 0 <= alcohol <= 30), f"{slug}: alcohol_percent inválido")
        temperature = wine.get("serving_temperature_c")
        if temperature is not None:
            require(isinstance(temperature, dict) and all(type(temperature.get(k)) in (int, float) for k in ("min", "max")), f"{slug}: temperatura inválida")
            require(0 <= temperature["min"] <= temperature["max"] <= 25, f"{slug}: rango de temperatura inválido")
        sources = wine.get("sources")
        require(isinstance(sources, list) and sources, f"{slug}: falta una fuente verificable")
        source_ids = set()
        for source in sources:
            require(isinstance(source, dict), f"{slug}: fuente inválida")
            for field in ("id", "title", "publisher"):
                require(nonempty(source.get(field)), f"{slug}: fuente sin {field}")
            require(source["id"] not in source_ids, f"{slug}: id de fuente duplicado")
            source_ids.add(source["id"])
            safe_url(source.get("url"), f"{slug}.sources.url")
            require(iso(source.get("checked_on"), f"{slug}.sources.checked_on") <= today(), f"{slug}: fuente con fecha futura")
            require(isinstance(source.get("supports"), list) and source["supports"] and all(nonempty(v) for v in source["supports"]), f"{slug}: indica qué datos respalda la fuente")
        pairings = wine.get("pairings")
        require(isinstance(pairings, list) and pairings, f"{slug}: agrega al menos un maridaje con una tabla")
        for pairing in pairings:
            require(isinstance(pairing, dict) and pairing.get("product_key") in products and pairing["product_key"] in TABLE_PATHS, f"{slug}: tabla de maridaje inexistente o inactiva")
            require(nonempty(pairing.get("reason")), f"{slug}: explica el maridaje")
        require(isinstance(wine.get("faq"), list), f"{slug}: faq debe ser lista")
        for item in wine["faq"]:
            require(isinstance(item, dict) and nonempty(item.get("question")) and nonempty(item.get("answer")), f"{slug}: pregunta incompleta")
        review = wine.get("review")
        require(isinstance(review, dict) and review.get("kind") in {"documentary", "tasted"}, f"{slug}: tipo editorial inválido")
        published_at = iso(review.get("published_at"), f"{slug}.review.published_at")
        changed_at = iso(review.get("updated_at"), f"{slug}.review.updated_at")
        require(published_at <= changed_at <= updated, f"{slug}: fechas de publicación/actualización inconsistentes")
        require(nonempty(review.get("author")), f"{slug}: autor o editor obligatorio")
        if review["kind"] == "documentary":
            require(all(review.get(k) is None for k in ("tasted_at", "tasted_vintage", "tasting_notes", "tasting_published_at", "rating")) and not review.get("evidence"), f"{slug}: una ficha documental no puede atribuirse cata ni puntuación")
        else:
            require(review["author"] not in {"Picando Tabla", "Equipo editorial de Picando Tabla"}, f"{slug}: una cata propia necesita el nombre de quien la hizo")
            tasted_at = iso(review.get("tasted_at"), f"{slug}.review.tasted_at")
            tasting_published_at = iso(review.get("tasting_published_at"), f"{slug}.review.tasting_published_at")
            require(tasted_at <= tasting_published_at <= changed_at, f"{slug}: fechas de cata y su publicación inconsistentes")
            require(nonempty(review.get("tasting_notes")), f"{slug}: faltan notas de cata propias")
            require(review.get("tasted_vintage") is None or (type(review["tasted_vintage"]) is int and 1900 <= review["tasted_vintage"] <= today().year), f"{slug}: añada de cata inválida")
            require(wine.get("vintage") is None or wine["vintage"] == review.get("tasted_vintage"), f"{slug}: la cata debe corresponder a la añada documentada; separa las fichas de añadas distintas")
            require(isinstance(review.get("evidence"), list) and review["evidence"], f"{slug}: agrega evidencia propia de la cata")
            for evidence in review["evidence"]:
                require(isinstance(evidence, dict) and nonempty(evidence.get("caption")), f"{slug}: evidencia sin descripción")
                safe_url(evidence.get("url"), f"{slug}.review.evidence.url")
            rating = review.get("rating")
            if rating is not None:
                require(isinstance(rating, dict) and all(type(rating.get(k)) in (int, float) for k in ("value", "min", "max")), f"{slug}: puntuación inválida")
                require(rating["min"] < rating["max"] and rating["min"] <= rating["value"] <= rating["max"] and nonempty(rating.get("method")), f"{slug}: puntuación fuera de escala o sin método")
        image = wine.get("image")
        if image is not None:
            require(isinstance(image, dict) and all(nonempty(image.get(k)) for k in ("src", "alt", "credit")), f"{slug}: imagen sin src, alt o crédito")
            safe_url(image["src"], f"{slug}.image.src", internal=True)
            require((ROOT / image["src"].lstrip("/")).is_file(), f"{slug}: imagen no encontrada")
        # El catálogo inicial es editorial y se cotiza. No se admiten ofertas a
        # medio configurar que pudieran introducir un precio o stock ficticio.
        require(wine.get("offer") is None, f"{slug}: las ofertas de vino requieren integrar primero el SKU y el cobro; usa offer:null y cotización")
        published.append(wine)
    return published


def esc(value: object) -> str:
    return html.escape(str(value), quote=True)


def short(text: str, length: int) -> str:
    return text if len(text) <= length else text[:length - 1].rsplit(" ", 1)[0] + "…"


def readable(value: str) -> str:
    day = date.fromisoformat(value)
    return f"{day.day} de {MONTHS[day.month - 1]} de {day.year}"


def url(wine: dict) -> str:
    return f"/vinos/{wine['slug']}/"


def quote_url(wine: dict | None = None, table: dict | None = None) -> str:
    if wine:
        message = f"Hola, vi {wine['name']} en {BASE}{url(wine)}. "
        message += f"Me interesa cotizar este vino con {table['title']}. " if table else "Me interesa cotizar este vino con una tabla de Picando Tabla. "
    else:
        message = f"Hola, llegué desde {BASE}/vinos/ y quiero elegir vino y una tabla para mi reunión. "
    message += "¿Podemos revisar la selección y disponibilidad?"
    return "https://wa.me/" + WHATSAPP + "?" + urlencode({"text": message})


def ld(data: dict) -> str:
    payload = json.dumps(data, ensure_ascii=False, separators=(",", ":")).replace("<", "\\u003c").replace(">", "\\u003e").replace("&", "\\u0026")
    return '<script type="application/ld+json">' + payload + '</script>'


def breadcrumbs(items: list[tuple[str, str]]) -> dict:
    return {"@context": "https://schema.org", "@type": "BreadcrumbList", "itemListElement": [{"@type": "ListItem", "position": i + 1, "name": name, "item": BASE + path} for i, (name, path) in enumerate(items)]}


def page(title: str, description: str, path: str, body: str, schemas: list[dict], kind: str, slug: str = "", image: str = "/img/tabla-850g.jpg") -> str:
    title, description = short(title, 70), short(description, 165)
    require(25 <= len(title) <= 70 and 70 <= len(description) <= 170, f"metadatos incompletos en {path}")
    return f'''<!doctype html>
<html lang="es-MX">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>{esc(title)}</title>
  <meta name="description" content="{esc(description)}">
  <link rel="canonical" href="{BASE}{path}">
  <meta name="robots" content="index,follow,max-image-preview:large">
  <meta property="og:type" content="{'website' if kind == 'index' else 'article'}">
  <meta property="og:title" content="{esc(title)}">
  <meta property="og:description" content="{esc(description)}">
  <meta property="og:url" content="{BASE}{path}">
  <meta property="og:image" content="{BASE}{image}">
  <meta property="og:locale" content="es_MX">
  <meta property="og:site_name" content="Picando Tabla">
  <meta name="twitter:card" content="summary_large_image">
  <link rel="icon" href="/favicon.svg" type="image/svg+xml">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Lora:wght@400;500;600;700&amp;family=Work+Sans:wght@400;500;600;700&amp;display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/seo.css">
  <link rel="stylesheet" href="/vinos/vinos.css?v=1">
  <script defer src="/seo.js"></script>
  <script defer src="/vinos/vinos.js?v=1"></script>
  {''.join(ld(s) for s in schemas)}
</head>
{MARKER}
<body class="wine-site" data-wine-page="{kind}" data-wine-slug="{esc(slug)}">
  <a class="skip-link" href="#main">Saltar al contenido</a>
  <header class="site-header"><nav class="nav" aria-label="Navegación principal">
    <a class="logo" href="/">Picando Tabla<small>Quesos · Charcutería · CDMX</small></a>
    <div class="nav-links"><a href="/tablas/">Tablas</a><a href="/vinos/" {'aria-current="page"' if kind == 'index' else ''}>Vinos</a><a href="/blog/maridaje-queso-vino-principiantes.html">Guía de maridaje</a><a href="/eventos/">Catering y eventos</a><a class="nav-cta" href="{esc(quote_url())}" data-wine-quote>Armar mi mesa</a></div>
  </nav></header>
  <main id="main">{body}</main>
  <footer class="wine-footer"><div class="wrap footer-grid"><div><a class="logo" href="/">Picando Tabla</a><p>Vinos, quesos y una mesa para compartir.</p><p>La selección de vinos se cotiza por separado y se confirma antes del pedido.</p></div><nav aria-label="Enlaces al pie"><a href="/vinos/">Vinos</a><a href="/vinos/como-resenamos/">Cómo elaboramos las fichas</a><a href="/tablas/">Tablas</a><a href="/eventos/">Catering y eventos</a><a href="/blog/">Blog</a></nav></div></footer>
</body>
</html>
'''


def card(wine: dict, number: int) -> str:
    badge = "Cata propia" if wine["review"]["kind"] == "tasted" else "Ficha documental"
    search = " ".join([wine["name"], wine["brand"], wine["country"], wine.get("region") or "", *wine["grapes"], *wine["aromas"]])
    return f'''<article class="wine-card" data-wine-slug="{esc(wine['slug'])}" data-wine-name="{esc(wine['name'])}" data-wine-type="{esc(wine['type'])}" data-wine-search="{esc(search)}">
      <div class="wine-card-visual"><span aria-hidden="true">{number:02}</span><p>{esc(wine['country'])} · {TYPES[wine['type']]}</p><strong>{esc(' / '.join(wine['grapes']))}</strong></div>
      <div class="wine-card-body"><span class="wine-badge">{badge}</span><h2><a href="{url(wine)}">{esc(wine['name'])}</a></h2><p>{esc(wine['summary'])}</p><div class="wine-tags"><span>{esc(wine['brand'])}</span>{f'<span>Añada {wine["vintage"]}</span>' if wine.get('vintage') else ''}</div><div class="wine-card-footer"><a href="{url(wine)}">Conocer el vino y su maridaje <span aria-hidden="true">→</span></a></div></div>
    </article>'''


def guide_links() -> str:
    return '''<div class="wine-guide-grid"><a class="wine-guide-card" href="/blog/maridaje-queso-vino-principiantes.html"><span class="wine-kicker">Para elegir</span><h3>Quesos, charcutería y vino</h3><p>Una guía para pensar en sabores, intensidad y el tipo de reunión.</p><span>Leer la guía →</span></a><a class="wine-guide-card" href="/vinos/como-resenamos/"><span class="wine-kicker">Nuestro criterio</span><h3>De dónde sale cada recomendación</h3><p>Fuentes de la bodega, propuestas de maridaje y catas propias, identificadas con claridad.</p><span>Conocer el método →</span></a></div>'''


def render_index(wines: list[dict], updated_at: str) -> str:
    types = sorted({wine["type"] for wine in wines})
    cards = ''.join(card(wine, i + 1) for i, wine in enumerate(wines))
    listing = cards or '<p>Estamos preparando nuevas fichas. Mientras tanto, explora la guía de maridaje y cuéntanos qué vino te gustaría acompañar.</p>'
    body = f'''
    <section class="wine-hero wrap"><div class="wine-hero-copy"><p class="wine-kicker">La mesa y la copa</p><h1>Vinos para disfrutar con quesos y charcutería.</h1><p class="wine-lede">Conoce una etiqueta, entiende su perfil y encuentra una tabla para acompañarla. Aquí reunimos fichas con fuentes y propuestas de maridaje de Picando Tabla.</p><div class="wine-actions"><a class="btn primary" href="#catalogo">Explorar los vinos</a><a class="btn" href="/blog/maridaje-queso-vino-principiantes.html">Elegir por maridaje</a></div><p class="wine-meta">Selección de vinos por cotización · Tablas en CDMX y zona oriente</p></div><figure class="wine-hero-media"><img src="/img/tabla-850g.jpg" width="1600" height="1200" alt="Tabla con quesos, charcutería, fruta y acompañamientos para compartir" fetchpriority="high" decoding="async"><figcaption>Presentación de referencia de Picando Tabla. La selección cambia según la temporada.</figcaption></figure></section>
    <section id="catalogo" class="wine-section wrap"><div class="wine-section-head"><div><p class="wine-kicker">Etiqueta por etiqueta</p><h2>Encuentra tu próxima combinación</h2></div><p>Las fichas documentales reúnen información de sus fuentes. Las reseñas de cata se identifican cuando existe una prueba propia.</p></div>
      <form id="wine-filters" hidden role="search" aria-label="Buscar en el catálogo de vinos"><label for="wine-query">Nombre, uva u origen<input id="wine-query" type="search" name="q" placeholder="Por ejemplo, Carmenère" autocomplete="off"></label><label for="wine-type">Tipo de vino<select id="wine-type" name="tipo"><option value="">Todos los tipos</option>{''.join(f'<option value="{kind}">{TYPES[kind]}</option>' for kind in types)}</select></label><button id="wine-reset" type="button">Limpiar filtros</button></form>
      <p id="wine-count" role="status" aria-live="polite">{len(wines)} {'vino con ficha disponible' if len(wines) == 1 else 'vinos con ficha disponible'}</p><div class="wine-grid">{listing}</div><p id="wine-empty" hidden>No encontramos un vino con esos filtros. Prueba otra uva, origen o nombre.</p>
    </section>
    <section class="wine-section wrap"><div class="wine-section-head"><h2>Una buena elección empieza en la mesa</h2></div>{guide_links()}</section>
    <section class="wine-cta"><div class="wrap"><div><p class="wine-kicker">De la lectura a la mesa</p><h2>¿Ya tienes vino en mente?</h2><p>Cuéntanos la etiqueta, la ocasión y cuántos van a compartir. Te proponemos la tabla y revisamos la selección de vino dentro de tu presupuesto.</p><p class="wine-note">El vino se cotiza por separado. Precio, presentación y disponibilidad se confirman antes de cerrar el pedido.</p></div><a class="btn primary" href="{esc(quote_url())}" data-wine-quote>Elegir vino y tabla</a><a class="btn" href="/tablas/" data-wine-table="all">Ver las tablas</a></div></section>'''
    schemas = [breadcrumbs([("Inicio", "/"), ("Vinos", "/vinos/")]), {"@context": "https://schema.org", "@type": "CollectionPage", "@id": BASE + "/vinos/#webpage", "url": BASE + "/vinos/", "name": "Vinos y maridajes de Picando Tabla", "inLanguage": "es-MX", "dateModified": updated_at, "mainEntity": {"@type": "ItemList", "itemListElement": [{"@type": "ListItem", "position": i + 1, "name": wine["name"], "url": BASE + url(wine)} for i, wine in enumerate(wines)]}}]
    return page("Vinos y maridajes con quesos en CDMX | Picando Tabla", "Explora fichas de vinos con fuentes, perfiles y propuestas para acompañar quesos y charcutería. Elige tu tabla y cotiza la selección de vino en CDMX.", "/vinos/", body, schemas, "index")


def facts(wine: dict) -> str:
    pairs = [("Tipo", TYPES[wine["type"]]), ("Origen", wine["country"]), ("Uvas", ", ".join(wine["grapes"]))]
    for key, label in (("region", "Región"), ("vintage", "Añada documentada"), ("body", "Cuerpo"), ("sweetness", "Estilo de dulzor")):
        if wine.get(key) is not None:
            pairs.append((label, wine[key]))
    if wine.get("volume_ml") is not None:
        pairs.append(("Presentación documentada", f"{wine['volume_ml']} ml"))
    if wine.get("alcohol_percent") is not None:
        pairs.append(("Alcohol declarado", f"{wine['alcohol_percent']} % vol."))
    if temperature := wine.get("serving_temperature_c"):
        value = str(temperature["min"]) if temperature["min"] == temperature["max"] else f"{temperature['min']}–{temperature['max']}"
        pairs.append(("Servicio recomendado", f"{value} °C"))
    return '<dl class="wine-facts">' + ''.join(f'<div><dt>{esc(label)}</dt><dd>{esc(value)}</dd></div>' for label, value in pairs) + '</dl>'


def render_pairing(wine: dict, pairing: dict, products: dict) -> str:
    product = products[pairing["product_key"]]
    image = '/' + product["media"]["image"].lstrip('/')
    return f'''<div class="wine-pairing"><img src="{esc(image)}" alt="Presentación de referencia: {esc(product['title'])}" loading="lazy" width="480" height="360"><div><h3>{esc(product['title'])}</h3><p>{esc(pairing['reason'])}</p><p class="wine-note">Selección de temporada; revisa la composición y el precio vigente.</p><div class="wine-actions"><a href="{TABLE_PATHS[product['key']]}" data-wine-table="{esc(product['key'])}">Ver la tabla →</a><a href="{esc(quote_url(wine, product))}" data-wine-quote data-wine-pairing="{esc(product['key'])}">Cotizar esta combinación</a></div></div></div>'''


def render_detail(wine: dict, products: dict) -> str:
    review = wine["review"]
    documentary = review["kind"] == "documentary"
    label = "Ficha documental" if documentary else "Reseña de cata propia"
    first_source = wine["sources"][0]
    last_checked = max(source["checked_on"] for source in wine["sources"])
    intro_note = "Perfil documentado a partir de las fuentes enlazadas; los maridajes son propuestas editoriales de Picando Tabla." if documentary else f"Cata de {review['author']} realizada el {readable(review['tasted_at'])}."
    tasting = ''
    if not documentary:
        vintage = str(review["tasted_vintage"]) if review.get("tasted_vintage") else "No registrada; la valoración corresponde únicamente a la botella probada"
        evidence = ''.join(f'<li><a href="{esc(item["url"])}">{esc(item["caption"])}</a></li>' for item in review["evidence"])
        score = ''
        if review.get("rating"):
            rating = review["rating"]
            score = f'<p><strong>Valoración: {rating["value"]} / {rating["max"]}</strong>. Escala desde {rating["min"]}. {esc(rating["method"])}</p>'
        tasting = f'<section class="wine-tasting"><h2>Nuestra cata</h2><p>Por {esc(review["author"])} · Cata del {readable(review["tasted_at"])}</p><p class="wine-note">Cata publicada el {readable(review["tasting_published_at"])}.</p><p>Añada: {esc(vintage)}</p><p>{esc(review["tasting_notes"])}</p>{score}<h3>Evidencia de la cata</h3><ul>{evidence}</ul></section>'
    source_list = ''.join(f'<li id="fuente-{esc(source["id"])}"><a href="{esc(source["url"])}" rel="noopener">{esc(source["title"])}</a><p>{esc(source["publisher"])} · Consultada el {readable(source["checked_on"])}</p></li>' for source in wine["sources"])
    faq = ''.join(f'<details><summary>{esc(item["question"])}</summary><p>{esc(item["answer"])}</p></details>' for item in wine["faq"])
    image = wine.get("image")
    visual = f'<figure class="wine-hero-media"><img src="{esc(image["src"])}" alt="{esc(image["alt"])}" width="800" height="800"><figcaption>{esc(image["credit"])}</figcaption></figure>' if image else f'<div class="wine-profile"><p class="wine-kicker">Datos para elegir</p>{facts(wine)}<p class="wine-note">Datos de las fuentes citadas. {"La añada no está especificada en esta ficha." if wine.get("vintage") is None else "Esta información corresponde a la añada indicada."} Confirma la botella que recibirás al cotizar.</p><a href="#fuentes">Consultar fuentes ↓</a></div>'
    body = f'''
    <div class="wrap"><nav class="crumbs" aria-label="Migas de pan"><a href="/">Inicio</a><span>›</span><a href="/vinos/">Vinos</a><span>›</span>{esc(wine['name'])}</nav></div>
    <section class="wine-hero wrap"><div class="wine-hero-copy"><span class="wine-badge">{label}</span><p class="wine-kicker">{esc(wine['country'])} · {TYPES[wine['type']]}</p><h1>{esc(wine['name'])}</h1><p class="wine-lede">{esc(wine['summary'])}</p><p class="wine-meta">Por {esc(review['author'])} · Actualizado el {readable(review['updated_at'])}</p><p class="wine-note">{esc(intro_note)}</p><div class="wine-actions"><a class="btn primary" href="#maridaje">Ver con qué tabla combinarlo</a><a class="btn" href="{esc(quote_url(wine))}" data-wine-quote>Cotizar vino y tabla</a></div></div>{visual}</section>
    <div class="wine-detail-layout wrap"><article class="wine-article"><section class="wine-answer"><p class="wine-kicker">En pocas palabras</p><h2>¿Qué puedes esperar de este vino?</h2>{''.join(f'<p>{esc(paragraph)}</p>' for paragraph in wine['description'])}<p class="wine-note">La descripción del perfil se atribuye a las fuentes citadas. <a href="{esc(first_source['url'])}">Ver {esc(first_source['publisher'])}</a>.</p></section>
      <section class="wine-section"><h2>Perfil descrito en sus fuentes</h2><p>{esc(wine['palate'])}</p>{f'<p><strong>Aromas descritos:</strong> {esc(", ".join(wine["aromas"]))}.</p>' if wine['aromas'] else ''}{facts(wine) if image else ''}<p class="wine-note">Última consulta de fuentes: {readable(last_checked)}. <a href="#fuentes">Ver referencias</a>.</p></section>
      {tasting}
      <section id="maridaje" class="wine-section"><p class="wine-kicker">Del vino a tu mesa</p><h2>Cómo lo combinaríamos en Picando Tabla</h2><p>Estas son propuestas de maridaje a partir del perfil documentado y de nuestras tablas. La combinación final se ajusta a los quesos y la charcutería de temporada.</p><div class="wine-pairings">{''.join(render_pairing(wine, pairing, products) for pairing in wine['pairings'])}</div></section>
      <section class="wine-section"><h2>Antes de elegir la botella</h2><ul class="wine-considerations">{''.join(f'<li>{esc(item)}</li>' for item in wine['considerations'])}</ul><p>Revisa la añada y la presentación de la cotización. Una ficha editorial no confirma existencia ni precio de venta.</p></section>
      <section class="wine-section"><h2>Preguntas sobre {esc(wine['name'])}</h2><div class="wine-faq">{faq}<details><summary>¿El vino viene incluido en la tabla?</summary><p>El vino se cotiza por separado. Confirmamos selección, presentación, precio y disponibilidad antes de cerrar el pedido.</p></details></div></section>
      <section id="fuentes" class="wine-section"><h2>Fuentes de esta ficha</h2><ol class="wine-sources">{source_list}</ol><p><a href="/vinos/como-resenamos/">Cómo diferenciamos una ficha documental de una cata propia</a></p></section>
    </article><aside class="wine-sidebar" aria-label="Cotización y guías"><p class="wine-kicker">Para compartir</p><h2>Arma la combinación</h2><p>Cuéntanos la fecha, tu zona y el número de invitados. Revisamos este vino y la tabla para tu ocasión.</p><a class="btn primary" href="{esc(quote_url(wine))}" data-wine-quote>Consultar por WhatsApp</a><p class="wine-note">Selección de vino por cotización. Disponibilidad sujeta a confirmación.</p><a href="/blog/maridaje-queso-vino-principiantes.html">Leer la guía de quesos y vino</a><a href="/vinos/">Explorar más vinos</a></aside></div>'''
    article = {"@context": "https://schema.org", "@type": "Article", "@id": BASE + url(wine) + "#article", "mainEntityOfPage": {"@type": "WebPage", "@id": BASE + url(wine)}, "headline": wine["name"], "description": wine["summary"], "inLanguage": "es-MX", "datePublished": review["published_at"], "dateModified": review["updated_at"], "author": {"@type": "Organization" if documentary else "Person", "name": review["author"], "url": BASE + "/vinos/como-resenamos/"}, "publisher": {"@type": "Organization", "@id": BASE + "/#organization", "name": "Picando Tabla", "url": BASE + "/"}, "about": {"@type": "Thing", "name": wine["name"], "sameAs": first_source["url"]}, "citation": [source["url"] for source in wine["sources"]], "image": BASE + (image["src"] if image else '/' + products[wine["pairings"][0]["product_key"]]["media"]["image"].lstrip('/'))}
    if not documentary:
        # El criterio editorial no es un perfil de la persona que realizó la cata.
        article["author"].pop("url", None)
    schemas = [article, breadcrumbs([("Inicio", "/"), ("Vinos", "/vinos/"), (wine["name"], url(wine))])]
    # Solo una cata propia con puntuación visible puede generar Review. Nunca
    # rellenar AggregateRating ni Offer para hacer que un validador muestre verde.
    if not documentary and review.get("rating"):
        rating = review["rating"]
        product_name = wine["name"] + (f' {review["tasted_vintage"]}' if review.get("tasted_vintage") else "")
        schemas.append({"@context": "https://schema.org", "@type": "Product", "@id": BASE + url(wine) + "#wine", "name": product_name, "brand": {"@type": "Brand", "name": wine["brand"]}, "review": {"@type": "Review", "author": {"@type": "Person", "name": review["author"]}, "datePublished": review["tasting_published_at"], "reviewBody": review["tasting_notes"], "reviewRating": {"@type": "Rating", "ratingValue": rating["value"], "bestRating": rating["max"], "worstRating": rating["min"]}}})
    return page(wine["name"] + " | Picando Tabla", wine["summary"], url(wine), body, schemas, "detail", wine["slug"], image["src"] if image else "/img/tabla-850g.jpg")


def render_methodology(updated_at: str) -> str:
    body = f'''<div class="wrap"><nav class="crumbs" aria-label="Migas de pan"><a href="/">Inicio</a><span>›</span><a href="/vinos/">Vinos</a><span>›</span>Nuestro criterio</nav></div>
    <article class="wine-policy wrap"><p class="wine-kicker">Nuestro criterio editorial</p><h1>De dónde sale cada recomendación de vino.</h1><p class="wine-lede">Queremos que puedas distinguir lo que dice la bodega, lo que proponemos para tu mesa y lo que hemos probado personalmente.</p><p class="wine-meta">Picando Tabla · {readable(updated_at)}</p>
    <h2>Una ficha documental identifica sus fuentes</h2><p>Reúne datos de una etiqueta concreta: origen, uvas, añada cuando está identificada, perfil descrito y servicio recomendado. Citamos las páginas o fichas consultadas y la fecha de revisión. Esos datos se atribuyen a su fuente; no significan que hayamos hecho una cata.</p><p>Si no hemos verificado un atributo, lo omitimos o indicamos su alcance. Desconocer la añada no convierte a un vino en «sin añada», y un aroma frutal no confirma por sí solo que sea dulce.</p>
    <h2>La cata propia necesita una prueba identificable</h2><p>Una página solo se presenta como reseña de cata propia cuando incluye quién probó el vino, cuándo, qué botella o añada se registró, sus notas y evidencia de la experiencia. Si publicamos una puntuación, explicamos la escala y el criterio utilizado junto a la valoración.</p><p>Las notas pertenecen a esa experiencia concreta. No trasladamos una valoración de una añada a otra ni copiamos puntuaciones de otros sitios para presentarlas como nuestras.</p>
    <h2>Cómo construimos las propuestas de maridaje</h2><p>Conectamos el perfil documentado del vino con la composición de nuestras tablas y explicamos el motivo de cada propuesta. Hasta probar una combinación, la describimos como una sugerencia editorial. El tamaño de la reunión ayuda a elegir la cantidad de tabla; la intensidad del vino depende de los sabores que se servirán.</p><p>Los quesos y la charcutería cambian con la temporada. Por eso cada ficha enlaza a la tabla correspondiente y la selección final se confirma al cotizar.</p>
    <h2>Qué vendemos y qué se cotiza</h2><p>Picando Tabla prepara tablas de quesos y charcutería. También ofrece selección y coordinación de vinos por cotización aparte. Tener una ficha de una botella no significa que esté en inventario o que venga incluida en una tabla.</p><p>Los precios de otras tiendas no son precios de Picando Tabla. Antes de cerrar un pedido confirmamos la etiqueta, la presentación, la disponibilidad y el precio aplicable. La presencia de un enlace a una bodega identifica una fuente de información.</p>
    <h2>Fechas, cambios y correcciones</h2><p>Cada ficha muestra cuándo se publicó o actualizó y cuándo consultamos sus fuentes. Una modificación de diseño no renueva la fecha de verificación de un vino. Cuando cambia una etiqueta o recibimos una corrección, revisamos el dato y su respaldo antes de actualizarlo.</p><p>Si detectas un error, <a href="{esc(quote_url())}">escríbenos con el nombre del vino y el dato a revisar</a>. No atribuimos títulos profesionales ni experiencia de cata a personas que no hayan participado.</p>
    <div class="wine-actions"><a class="btn primary" href="/vinos/">Explorar las fichas</a><a class="btn" href="/blog/maridaje-queso-vino-principiantes.html">Leer la guía de maridaje</a></div></article>'''
    schema = {"@context": "https://schema.org", "@type": "WebPage", "@id": BASE + "/vinos/como-resenamos/#webpage", "url": BASE + "/vinos/como-resenamos/", "name": "Cómo elaboramos fichas y reseñas de vinos", "inLanguage": "es-MX", "dateModified": updated_at, "publisher": {"@type": "Organization", "@id": BASE + "/#organization", "name": "Picando Tabla"}}
    return page("Cómo elaboramos fichas y reseñas de vinos | Picando Tabla", "Conoce cómo Picando Tabla distingue fuentes de bodegas, propuestas de maridaje y catas propias, y cómo confirma la selección de vino antes de un pedido.", "/vinos/como-resenamos/", body, [schema, breadcrumbs([("Inicio", "/"), ("Vinos", "/vinos/"), ("Nuestro criterio", "/vinos/como-resenamos/")])], "methodology")


def replace_block(text: str, name: str, body: str, before: str | None = None) -> str:
    start, end = f"<!-- WINES:{name}:START -->", f"<!-- WINES:{name}:END -->"
    require(text.count(start) == text.count(end) and text.count(start) <= 1, f"marcadores {name} inválidos")
    block = start + '\n' + body.rstrip() + '\n' + end
    if start in text:
        return re.sub(re.escape(start) + r".*?" + re.escape(end), lambda _: block, text, flags=re.S)
    if before:
        require(text.count(before) == 1, f"no se encontró un punto único para {name}")
        return text.replace(before, block + '\n' + before, 1)
    return text.rstrip() + '\n\n' + block + '\n'


def generated_files(data: dict, table_catalog: dict) -> dict[Path, str]:
    wines = validate_catalog(data, table_catalog)
    products = {product["key"]: product for product in table_catalog["products"]}
    files = {ROOT / "vinos/index.html": render_index(wines, data["updated_at"]), ROOT / "vinos/como-resenamos/index.html": render_methodology(data["updated_at"])}
    files.update({ROOT / url(wine).lstrip('/') / 'index.html': render_detail(wine, products) for wine in wines})
    locations = [("/vinos/", data["updated_at"]), ("/vinos/como-resenamos/", data["updated_at"])] + [(url(wine), wine["review"]["updated_at"]) for wine in wines]
    sitemap = '\n'.join(f'  <url><loc>{BASE}{path}</loc><lastmod>{changed}</lastmod></url>' for path, changed in locations)
    files[ROOT / "sitemap.xml"] = replace_block((ROOT / "sitemap.xml").read_text(encoding="utf-8"), "SITEMAP", sitemap, "</urlset>")
    index_text = '\n'.join(["## Vinos y maridaje", "", f"- [Fichas de vinos y maridajes]({BASE}/vinos/): Catálogo editorial de vinos con fuentes y conexiones a las tablas de Picando Tabla.", f"- [Cómo elaboramos fichas y reseñas]({BASE}/vinos/como-resenamos/): Distinción entre información documental y catas propias.", *[f"- [{wine['name']}]({BASE}{url(wine)}): {'Cata propia' if wine['review']['kind'] == 'tasted' else 'Ficha documental'}; {wine['country']}; {', '.join(wine['grapes'])}." for wine in wines], "", "Las fichas de vinos no son confirmación de inventario. Los vinos se cotizan por separado; los precios de otras tiendas no son precios de Picando Tabla."])
    for name in ("llms.txt", "llms-full.txt"):
        files[ROOT / name] = replace_block((ROOT / name).read_text(encoding="utf-8"), "INDEX", index_text)
    return files


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    group = parser.add_mutually_exclusive_group()
    group.add_argument("--check", action="store_true")
    group.add_argument("--new", metavar="SLUG")
    args = parser.parse_args()
    try:
        data = json.loads(SOURCE.read_text(encoding="utf-8"))
        table_catalog = json.loads((ROOT / "data/catalogo.json").read_text(encoding="utf-8"))
        if args.new:
            draft = json.loads((ROOT / "tools/vino-ejemplo.json").read_text(encoding="utf-8"))
            draft.update({"id": "picandotabla:wine:" + args.new, "slug": args.new, "status": "draft"})
            proposed = copy.deepcopy(data)
            proposed["wines"].append(draft)
            validate_catalog(proposed, table_catalog)
            SOURCE.write_text(json.dumps(proposed, ensure_ascii=False, indent=2) + '\n', encoding="utf-8")
            print(f"Borrador agregado: {args.new}. Completa data/vinos.json antes de publicarlo.")
            return 0
        files = generated_files(data, table_catalog)
        stale = [path for path in (ROOT / "vinos").rglob("*.html") if path not in files and MARKER in path.read_text(encoding="utf-8")]
        changed = [path for path, content in files.items() if not path.exists() or path.read_text(encoding="utf-8") != content]
        if args.check:
            for path in changed + stale:
                print("PENDIENTE " + path.relative_to(ROOT).as_posix())
            require(not changed and not stale, "regenera el catálogo con python tools/build_wines.py")
        else:
            for path in changed:
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(files[path], encoding="utf-8", newline="\n")
            for path in stale:
                path.unlink()
        published = sum(wine["status"] == "published" for wine in data["wines"])
        print(f"Vinos: {published} fichas publicadas; HTML, sitemap y enlaces de referencia consistentes.")
        return 0
    except (OSError, ValueError, KeyError, TypeError) as exc:
        print(f"ERROR vinos: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
