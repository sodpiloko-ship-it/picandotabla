"""Tarjetas comerciales compartidas, derivadas de data/catalogo.json.

Contrato de la interfaz (los estilos viven en /tienda.css):
  .pt-product-card[data-pt-product] contiene .pt-card-media, .pt-card-body,
  .pt-card-tag, .pt-card-title, .pt-card-people (opcional), .pt-card-description,
  .pt-card-composition, .pt-card-price, .pt-card-delivery, .pt-card-lead y
  .pt-card-buy[data-pt-buy]. El único control es «Elegir y pedir»; tienda.js
  abre el mismo panel de compra desde cualquier página.
  .pt-card-badge, .pt-card-regular-price y .pt-card-gift son opcionales.
  .pt-card-grid usa data-pt-columns="3" en las selecciones de tres tablas.
  home=True es el menú de la portada: tarjetas sin foto (las fotos viven en la
  galería de la portada). Las tarjetas no muestran gramaje; la recomendación de
  personas sale de presentation.people_text cuando el catálogo la declara.

No modifica archivos. update_seo_cards devuelve una transformación idempotente
del HTML y preserva el contenido ajeno al bloque de tarjetas.
"""

from __future__ import annotations

import html
import re
from textwrap import indent


SEO_CARD_PRODUCTS = {
    "tablas/index.html": ("dos", "anfitriona", "fiesta", "celebracion"),
    "reuniones/index.html": ("anfitriona", "fiesta", "celebracion"),
    "regalos/index.html": ("dos", "anfitriona", "fiesta"),
}
STORE_START = "<!-- POND_STORE_CARDS:START -->"
STORE_END = "<!-- POND_STORE_CARDS:END -->"


def _escape(value: object) -> str:
    return html.escape(str(value), quote=True)


def _money(value: int) -> str:
    return f"${value:,}"


def render_product_card(catalog: dict, product: dict, *, home: bool = False) -> str:
    """Renderiza la misma tarjeta en portada, catálogo y páginas de ocasión."""
    key = _escape(product["key"])
    presentation = product["presentation"]
    featured = presentation["featured"]
    title = _escape(product["title"])
    package = product.get("package")
    bundle = product.get("bundle")
    card_class = (
        "pt-product-card"
        + (" pt-card-featured" if featured else "")
        + (" pt-card-package" if package or bundle else "")
        + (" pt-card-nomedia" if home else "")
    )
    badge = '  <span class="pt-card-badge">La favorita</span>' if featured else ""
    composition = product["composition"]
    composition_text = product.get("composition_text") or (
        f'{composition["cheeses"]} quesos · {composition["meats"]} carnes'
    )
    logistics = catalog["logistics"]
    lead_days = logistics["event_lead_time_days"] if product["is_event"] else logistics["standard_lead_time_hours"] // 24
    price = _money(product["price_mxn"])
    package = product.get("package")
    delivery_text = f'Envío +{_money(catalog["delivery"]["price_mxn"])} MXN · {catalog["delivery"]["area"]}'
    if package:
        extras = {extra["key"]: extra for extra in catalog["extras"]}
        premium = next(modifier for modifier in catalog["modifiers"] if modifier["key"] == "premium")
        total = product["price_mxn"] + catalog["delivery"]["price_mxn"]
        if package.get("premium"):
            total += premium["prices_mxn_by_product_key"][product["key"]]
        total += sum(extras[extra_key]["price_mxn"] for extra_key in package["extra_keys"])
        price = _money(total)
        delivery_text = ""
    quote = product.get("quote")
    per_person = product.get("per_person")
    price_unit = "MXN"
    if per_person:
        price_unit = "MXN por persona"
        delivery_text = f'Desde {per_person["min_people"]} personas · ' + delivery_text
    if quote:
        price = "Desde " + _money(quote["price_per_person_from_mxn"])
        price_unit = "MXN por persona"
        delivery_text = f'Para {quote.get("min_people", 10)} personas o más preparamos una cotización.'
    regular = product.get("regular_price_mxn")
    price_html = f'<s class="pt-card-regular-price">{_money(regular)}</s> ' if regular else ""
    price_html += price
    gift_lines = [
        f'    <p class="pt-card-gift">{_escape(gift["title"])} incluida sin costo</p>'
        for gift in catalog.get("promotions", [])
        if gift.get("type") == "gift" and product["key"] in gift.get("eligible_product_keys", [])
    ]
    lines = [
        f'<article class="{card_class}" data-pt-product="{key}" data-catalog-product="{key}">',
        badge,
        "" if home else f'  <img class="pt-card-media" data-catalog-image src="/{_escape(product["media"]["image"].lstrip("/"))}" alt="{title}" width="640" height="420" loading="lazy" decoding="async">',
        '  <div class="pt-card-body">',
        f'    <p class="pt-card-package-label">{_escape((package or bundle)["card_label"])}</p>' if package or bundle else "",
        '    <p class="pt-card-package-label">Versión Premium</p>' if product.get("premium_included") else "",
        f'    <p class="pt-card-tag" data-catalog-tag>{_escape(presentation["tag"])}</p>',
        f'    <h3 class="pt-card-title" data-catalog-title>{title}</h3>',
        f'    <p class="pt-card-people">{_escape(presentation["people_text"])}</p>' if presentation.get("people_text") else "",
        f'    <p class="pt-card-description">{_escape(presentation["card_description"])}</p>',
        f'    <p class="pt-card-composition">{_escape(composition_text)}</p>',
        f'    <p class="pt-card-price"><span data-catalog-price>{price_html}</span> <small>{price_unit}</small></p>',
        *gift_lines,
        (
            '    <div class="pt-card-delivery pt-card-package-box">'
            f'<p class="pt-card-package-title">{_escape(package["card_title"])}</p>'
            '<ul>' + "".join(f"<li><strong>{_escape(item)}</strong></li>" for item in package["card_items"]) + '</ul>'
            f'<p>{_escape(package["card_note"])}</p></div>'
        ) if package else (
            '    <div class="pt-card-delivery pt-card-package-box">'
            f'<p class="pt-card-package-title">{_escape(bundle["card_title"])}</p>'
            '<ul>' + "".join(f"<li><strong>{_escape(item)}</strong></li>" for item in bundle["card_items"]) + '</ul>'
            f'<p>{_escape(bundle["card_note"])} {_escape(delivery_text)}</p></div>'
        ) if bundle else f'    <p class="pt-card-delivery">{_escape(delivery_text)}</p>',
        (
            f'    <p class="pt-card-note"><strong>{_escape(product["premium_text"]["line"])}:</strong> '
            f'{_money(product["price_mxn"] + next(m for m in catalog["modifiers"] if m["key"] == "premium")["prices_mxn_by_product_key"][product["key"]])} MXN</p>'
        ) if product.get("premium_text") else "",
        f'    <p class="pt-card-lead">Pide con {lead_days} días de anticipación.</p>',
        (f'    <a class="pt-card-buy" href="{_escape(quote["url"])}" aria-label="Pedir cotización de {title}">Pedir cotización</a>'
         if quote else
         f'    <button class="pt-card-buy" type="button" data-pt-buy="{key}" aria-label="Elegir y pedir {title}" aria-haspopup="dialog">Elegir y pedir <span aria-hidden="true">⌄</span></button>'),
        "" if quote else f'    <noscript><a class="pt-card-fallback" href="/?tabla={key}#tablas">Elegir y pedir {title}</a></noscript>',
        '  </div>',
        '</article>',
    ]
    return "\n".join(line for line in lines if line)


def render_card_grid(catalog: dict, keys: tuple[str, ...], *, home: bool = False) -> str:
    products = {product["key"]: product for product in catalog["products"]}
    classes = "pt-grid4 pt-card-grid" if home else "product-grid pt-card-grid"
    lines = [f'<div class="{classes}" data-pt-columns="{len(keys)}">']
    lines.extend(indent(render_product_card(catalog, products[key], home=home), "  ") for key in keys)
    lines.append("</div>")
    return "\n".join(lines)


def _legacy_grid_span(text: str) -> tuple[int, int]:
    opening = re.search(r'<div\b[^>]*\bclass="[^\"]*\bproduct-grid\b[^\"]*"[^>]*>', text)
    if not opening:
        raise ValueError("No se encontró la cuadrícula product-grid para migrar las tarjetas")
    depth = 1
    for token in re.finditer(r'<(/?)div\b[^>]*>', text[opening.end():], flags=re.IGNORECASE):
        depth += -1 if token.group(1) else 1
        if depth == 0:
            return opening.start(), opening.end() + token.end()
    raise ValueError("La cuadrícula product-grid no tiene cierre")


def update_seo_cards(text: str, catalog: dict, keys: tuple[str, ...]) -> str:
    """Sustituye sólo la cuadrícula comercial y reubica un newsletter anidado.

    La primera migración local corrige PT_COMMUNITY insertado dentro de una
    tarjeta <a>: conserva su HTML completo antes del cierre de main. Las
    ejecuciones siguientes sólo reemplazan POND_STORE_CARDS.
    """
    block = STORE_START + "\n" + render_card_grid(catalog, keys) + "\n" + STORE_END
    if STORE_START in text or STORE_END in text:
        if text.count(STORE_START) != 1 or text.count(STORE_END) != 1:
            raise ValueError("Se necesita exactamente un bloque POND_STORE_CARDS")
        start = text.index(STORE_START)
        end = text.index(STORE_END, start) + len(STORE_END)
        return text[:start] + block + text[end:]

    start, end = _legacy_grid_span(text)
    legacy = text[start:end]
    community = re.findall(r'<!-- PT_COMMUNITY:START -->.*?<!-- PT_COMMUNITY:END -->', legacy, flags=re.DOTALL)
    updated = text[:start] + block + text[end:]
    if community:
        if len(community) != 1 or updated.count("</main>") != 1:
            raise ValueError("No se puede preservar de forma inequívoca el newsletter anidado")
        updated = updated.replace("</main>", community[0] + "\n</main>", 1)
    return updated
