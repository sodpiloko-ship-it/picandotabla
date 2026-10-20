"""Tarjetas comerciales compartidas, derivadas de data/catalogo.json.

Contrato de la interfaz (los estilos viven en /tienda.css):
  .pt-product-card[data-pt-product] contiene .pt-card-media, .pt-card-body,
  .pt-card-tag, .pt-card-title, .pt-card-people, .pt-card-description,
  .pt-card-composition, .pt-card-price, .pt-card-delivery, .pt-card-lead y
  .pt-card-buy[data-pt-buy]. El único control es «Elegir y pedir»; tienda.js
  abre el mismo panel de compra desde cualquier página.
  .pt-card-badge, .pt-card-regular-price y .pt-card-gift son opcionales.
  .pt-card-grid usa data-pt-columns="3" en las selecciones de tres tablas.
  home=True conserva card2/card46/card8/card1012 y sus badges para el selector.

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
    people = product["people"]
    suffix = str(people["min"]) if people["min"] == people["max"] else f'{people["min"]}{people["max"]}'
    people_text = str(people["min"]) if people["min"] == people["max"] else f'{people["min"]}–{people["max"]}'
    presentation = product["presentation"]
    featured = presentation["featured"]
    title = _escape(product["title"])
    identity = f' id="card{suffix}"' if home else ""
    card_class = "pt-product-card" + (" pt-card-featured" if featured else "")
    badge = ""
    if featured or home:
        badge_id = f' id="badge{suffix}"' if home else ""
        hidden = ' style="display:none"' if not featured else ""
        badge = f'  <span class="pt-card-badge"{badge_id}{hidden}>{"La favorita" if featured else "Para ti"}</span>'
    composition = product["composition"]
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
        delivery_text = package["card_note"]
    quote = product.get("quote")
    price_unit = "MXN"
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
        f'<article class="{card_class}"{identity} data-pt-product="{key}" data-catalog-product="{key}">',
        badge,
        f'  <img class="pt-card-media" data-catalog-image src="/{_escape(product["media"]["image"].lstrip("/"))}" alt="{title}" width="640" height="420" loading="lazy" decoding="async">',
        '  <div class="pt-card-body">',
        f'    <p class="pt-card-tag" data-catalog-tag>{_escape(presentation["tag"])}</p>',
        f'    <h3 class="pt-card-title" data-catalog-title>{title}</h3>',
        f'    <p class="pt-card-people">Para {people_text} personas · {product["weight_g"]:,} g</p>',
        f'    <p class="pt-card-description">{_escape(presentation["card_description"])}</p>',
        f'    <p class="pt-card-composition">{composition["cheeses"]} quesos · {composition["meats"]} carnes · {composition["accompaniments"]} acompañamientos</p>',
        f'    <p class="pt-card-price"><span data-catalog-price>{price_html}</span> <small>{price_unit}</small></p>',
        *gift_lines,
        f'    <p class="pt-card-delivery">{_escape(delivery_text)}</p>',
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
