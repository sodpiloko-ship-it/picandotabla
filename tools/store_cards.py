"""Tarjetas comerciales compartidas, derivadas de data/catalogo.json.

Contrato de la interfaz (los estilos viven en /tienda.css):
  .pt-product-card[data-pt-product] contiene .pt-card-photo (foto 4:3 con
  .pt-card-badge), .pt-card-body, .pt-card-title, .pt-card-people (opcional),
  .pt-card-price, .pt-card-delivery, .pt-card-description, .pt-card-includes
  («Incluye» con palomitas), .pt-card-lead (tienda.js escribe ahí la entrega
  más pronta) y .pt-card-buy[data-pt-buy]. Tocar la tarjeta abre el detalle; el
  botón «Elegir y pedir» abre directo el pedido en tres pasos (tienda.js).
  presentation.special pone el marco vino de los especiales y los manda
  primero en la portada (home_order). .pt-card-regular-price, .pt-card-gift y
  .pt-card-note son opcionales. .pt-card-grid usa data-pt-columns="3" en las
  selecciones de tres tablas. Las tarjetas no muestran gramaje; la
  recomendación de personas sale de presentation.people_text.

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


def _badge(product: dict) -> str:
    """Etiqueta sobre la foto: paquete, la favorita o la etiqueta del catálogo."""
    package = product.get("package") or product.get("bundle")
    if package:
        return package["card_label"]
    if product["presentation"]["featured"]:
        return "La favorita"
    return product["presentation"]["tag"]


def _includes(product: dict) -> tuple[str, list[str], str]:
    """Bloque «Incluye» de la tarjeta: título, renglones y nota opcional."""
    package = product.get("package") or product.get("bundle")
    if package:
        return package["card_title"], list(package["card_items"]), package["card_note"]
    if product.get("composition_text"):
        return "Incluye", [part.strip() for part in product["composition_text"].split("·") if part.strip()], ""
    composition = product["composition"]
    return "Incluye", [f'{composition["cheeses"]} quesos', f'{composition["meats"]} carnes'], ""


def render_product_card(catalog: dict, product: dict, *, home: bool = False) -> str:
    """Renderiza la misma tarjeta en portada, catálogo y páginas de ocasión."""
    key = _escape(product["key"])
    presentation = product["presentation"]
    featured = presentation["featured"]
    title = _escape(product["title"])
    package = product.get("package")
    card_class = (
        "pt-product-card"
        + (" pt-card-featured" if featured else "")
        + (" pt-card-special" if presentation.get("special") else "")
    )
    logistics = catalog["logistics"]
    lead_days = logistics["event_lead_time_days"] if product["is_event"] else logistics["standard_lead_time_hours"] // 24
    price = _money(product["price_mxn"])
    delivery_text = f'+ envío {_money(catalog["delivery"]["price_mxn"])}'
    if package:
        extras = {extra["key"]: extra for extra in catalog["extras"]}
        premium = next(modifier for modifier in catalog["modifiers"] if modifier["key"] == "premium")
        total = product["price_mxn"] + catalog["delivery"]["price_mxn"]
        if package.get("premium"):
            total += premium["prices_mxn_by_product_key"][product["key"]]
        total += sum(extras[extra_key]["price_mxn"] for extra_key in package["extra_keys"])
        price = _money(total)
        delivery_text = "envío incluido"
    quote = product.get("quote")
    per_person = product.get("per_person")
    price_unit = "MXN"
    meta = [presentation["people_text"]] if presentation.get("people_text") else []
    if per_person:
        price_unit = "por persona"
        meta.append(f'Desde {per_person["min_people"]} personas')
    if quote:
        price = "Desde " + _money(quote["price_per_person_from_mxn"])
        price_unit = "por persona"
        delivery_text = f'desde {quote.get("min_people", 10)} personas'
    regular = product.get("regular_price_mxn")
    price_html = f'<s class="pt-card-regular-price">{_money(regular)}</s> ' if regular else ""
    price_html += price
    gift_lines = [
        f'    <p class="pt-card-gift">{_escape(gift["title"])} incluida sin costo</p>'
        for gift in catalog.get("promotions", [])
        if gift.get("type") == "gift" and product["key"] in gift.get("eligible_product_keys", [])
    ]
    includes_title, includes, includes_note = _includes(product)
    premium_line = ""
    if product.get("premium_text"):
        premium = next(m for m in catalog["modifiers"] if m["key"] == "premium")
        premium_line = (
            f'    <p class="pt-card-note"><strong>{_escape(product["premium_text"]["line"])}:</strong> '
            f'{_money(product["price_mxn"] + premium["prices_mxn_by_product_key"][product["key"]])} MXN</p>'
        )
    image = _escape(product["media"]["image"].lstrip("/"))
    lines = [
        f'<article class="{card_class}" data-pt-product="{key}" data-catalog-product="{key}">',
        '  <div class="pt-card-photo">',
        f'    <img class="pt-card-media" data-catalog-image src="/{image}" alt="{title}" width="640" height="480" loading="lazy" decoding="async">',
        f'    <span class="pt-card-badge">{_escape(_badge(product))}</span>',
        '  </div>',
        '  <div class="pt-card-body">',
        '    <div class="pt-card-head">',
        '      <div class="pt-card-name">',
        f'        <h3 class="pt-card-title" data-catalog-title>{title}</h3>',
        f'        <p class="pt-card-people">{_escape(" · ".join(meta))}</p>' if meta else "",
        '      </div>',
        '      <div class="pt-card-pricebox">',
        f'        <p class="pt-card-price"><span data-catalog-price>{price_html}</span> <small>{price_unit}</small></p>',
        f'        <p class="pt-card-delivery">{_escape(delivery_text)}</p>',
        '      </div>',
        '    </div>',
        f'    <p class="pt-card-description">{_escape(presentation["card_description"])}</p>',
        '    <div class="pt-card-includes">',
        f'      <p class="pt-card-includes-title">{_escape(includes_title)}</p>',
        '      <ul>' + "".join(f"<li>{_escape(item)}</li>" for item in includes) + '</ul>',
        f'      <p class="pt-card-includes-note">{_escape(includes_note)}</p>' if includes_note else "",
        '    </div>',
        *gift_lines,
        premium_line,
        ('    <p class="pt-card-lead">Cotización por WhatsApp</p>' if quote else
         f'    <p class="pt-card-lead" data-pt-lead="{lead_days}">Pide con {lead_days} días de anticipación</p>'),
        (f'    <a class="pt-card-buy" href="{_escape(quote["url"])}" aria-label="Pedir cotización de {title}">Pedir cotización</a>'
         if quote else
         f'    <button class="pt-card-buy" type="button" data-pt-buy="{key}" aria-label="Elegir y pedir {title}" aria-haspopup="dialog">Elegir y pedir</button>'),
        "" if quote else f'    <noscript><a class="pt-card-fallback" href="/?tabla={key}#tablas">Elegir y pedir {title}</a></noscript>',
        '  </div>',
        '</article>',
    ]
    return "\n".join(line for line in lines if line)


def home_order(catalog: dict) -> tuple[str, ...]:
    """Portada: los especiales (marco vino) primero; el resto en orden de catálogo."""
    products = catalog["products"]
    return tuple(p["key"] for p in products if p["presentation"].get("special")) + tuple(
        p["key"] for p in products if not p["presentation"].get("special")
    )


def render_card_grid(catalog: dict, keys: tuple[str, ...], *, home: bool = False) -> str:
    products = {product["key"]: product for product in catalog["products"]}
    classes = "pt-shop pt-card-grid" if home else "product-grid pt-card-grid"
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
