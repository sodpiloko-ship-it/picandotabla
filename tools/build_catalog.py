#!/usr/bin/env python3
"""Valida data/catalogo.json y genera su proyección estática para el sitio."""

from __future__ import annotations

import argparse
import hashlib
import html
import json
import os
import re
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "data" / "catalogo.json"
TARGET = ROOT / "catalogo.js"
HOME = ROOT / "index.html"
ORDER = ROOT / "orden" / "index.html"
EXPECTED_PRODUCT_KEYS = {"dos", "anfitriona", "fiesta", "celebracion"}
EXPECTED_EXTRA_KEYS = {"dip", "mermelada", "pan"}
PUBLIC_SOURCE_SUFFIXES = {".html", ".php", ".md", ".js"}
PRIVATE_OR_GENERATED_DIRS = {
    ".git",
    "data",
    "secrets",
    "orders",
    "pedidos",
    "storage",
    "uploads",
    "img",
    "vendor",
}
PRICE_LITERAL = re.compile(
    r"(?:\$\s*([0-9][0-9.,]*)|\b([0-9][0-9.,]*)\s*(?:MXN|pesos?)\b)",
    re.IGNORECASE,
)


def fail(message: str) -> None:
    raise ValueError(f"catalogo.json: {message}")


def require_unique(items: list[dict], field: str, label: str) -> None:
    values = [item.get(field) for item in items]
    if any(not value for value in values):
        fail(f"cada {label} necesita {field}")
    if len(values) != len(set(values)):
        fail(f"{field} duplicado en {label}")


def require_price(value: object, field: str) -> None:
    if isinstance(value, bool) or not isinstance(value, int) or value < 0:
        fail(f"{field} debe ser un entero MXN no negativo")


def load_and_validate() -> dict:
    with SOURCE.open(encoding="utf-8") as handle:
        catalog = json.load(handle)

    if catalog.get("schema_version") != "pond.catalog.v1":
        fail("schema_version debe ser pond.catalog.v1")
    if catalog.get("business_id") != "picandotabla":
        fail("business_id debe ser picandotabla")
    if catalog.get("currency") != "MXN" or catalog.get("price_unit") != "major":
        fail("los importes deben declararse como unidades mayores MXN")

    products = catalog.get("products")
    extras = catalog.get("extras")
    modifiers = catalog.get("modifiers")
    if not isinstance(products, list) or not isinstance(extras, list) or not isinstance(modifiers, list):
        fail("products, extras y modifiers deben ser listas")

    require_unique(products, "id", "producto")
    require_unique(products, "key", "producto")
    require_unique(extras, "id", "extra")
    require_unique(extras, "key", "extra")
    require_unique(modifiers, "id", "modificador")
    require_unique(modifiers, "key", "modificador")

    product_keys = {product["key"] for product in products}
    extra_keys = {extra["key"] for extra in extras}
    if product_keys != EXPECTED_PRODUCT_KEYS:
        fail(f"productos esperados: {sorted(EXPECTED_PRODUCT_KEYS)}")
    if extra_keys != EXPECTED_EXTRA_KEYS:
        fail(f"extras esperados: {sorted(EXPECTED_EXTRA_KEYS)}")

    for product in products:
        if not product["id"].startswith("picandotabla:offer:"):
            fail(f"id global inválido: {product['id']}")
        require_price(product.get("price_mxn"), f"products[{product['key']}].price_mxn")
        people = product.get("people", {})
        if not isinstance(people.get("min"), int) or not isinstance(people.get("max"), int):
            fail(f"products[{product['key']}].people requiere min y max enteros")
        if people["min"] < 1 or people["min"] > people["max"]:
            fail(f"rango de personas inválido en {product['key']}")
        composition = product.get("composition", {})
        for field in ("cheeses", "meats", "accompaniments"):
            if not isinstance(composition.get(field), int) or composition[field] < 0:
                fail(f"composición inválida en {product['key']}.{field}")
        presentation = product.get("presentation", {})
        for field in ("tag", "card_description", "order_subtitle"):
            if not isinstance(presentation.get(field), str) or not presentation[field].strip():
                fail(f"presentation.{field} inválido en {product['key']}")
        if not isinstance(presentation.get("featured"), bool):
            fail(f"presentation.featured debe ser booleano en {product['key']}")
        if not isinstance(presentation.get("scale_3d"), (int, float)) or presentation["scale_3d"] <= 0:
            fail(f"presentation.scale_3d inválido en {product['key']}")
        if not isinstance(product.get("media", {}).get("image"), str):
            fail(f"media.image inválido en {product['key']}")
    if sum(bool(product["presentation"]["featured"]) for product in products) != 1:
        fail("exactamente un producto debe tener presentation.featured=true")

    for extra in extras:
        if not extra["id"].startswith("picandotabla:addon:"):
            fail(f"id global inválido: {extra['id']}")
        require_price(extra.get("price_mxn"), f"extras[{extra['key']}].price_mxn")

    premium = next((item for item in modifiers if item["key"] == "premium"), None)
    if not premium or premium["id"] != "picandotabla:modifier:premium":
        fail("falta el modificador global Premium")
    premium_prices = premium.get("prices_mxn_by_product_key", {})
    if set(premium_prices) != product_keys:
        fail("Premium necesita un precio para cada producto")
    for key, value in premium_prices.items():
        require_price(value, f"modifiers[premium].prices_mxn_by_product_key[{key}]")

    delivery = catalog.get("delivery", {})
    if delivery.get("id") != "picandotabla:delivery:cdmx":
        fail("delivery.id debe ser picandotabla:delivery:cdmx")
    require_price(delivery.get("price_mxn"), "delivery.price_mxn")

    logistics = catalog.get("logistics", {})
    if logistics.get("delivery_days_iso") != [5, 6]:
        fail("delivery_days_iso debe conservar viernes y sábado")
    if logistics.get("standard_lead_time_hours") != 48:
        fail("standard_lead_time_hours debe conservar 48 h")
    if logistics.get("event_lead_time_days") != 7:
        fail("event_lead_time_days debe conservar 7 días")

    return catalog


def render_js(catalog: dict) -> str:
    payload = json.dumps(catalog, ensure_ascii=False, separators=(",", ":"))
    return (
        "/* Generado por tools/build_catalog.py desde data/catalogo.json. No editar. */\n"
        "(function(root){\n"
        f"  var catalog={payload};\n"
        "  root.PICANDO_CATALOGO_V1=catalog;\n"
        "  document.documentElement.dataset.pondCatalog='picandotabla:'"
        "+catalog.schema_version;\n"
        "})(window);\n"
    )


def escaped(value: object) -> str:
    return html.escape(str(value), quote=True)


def money(value: int) -> str:
    return f"${value:,}"


def selector_suffix(product: dict) -> str:
    people = product["people"]
    if people["min"] == people["max"]:
        return str(people["min"])
    return f"{people['min']}{people['max']}"


def people_range(products: list[dict]) -> tuple[int, int]:
    return (
        min(product["people"]["min"] for product in products),
        max(product["people"]["max"] for product in products),
    )


def render_home_products(catalog: dict) -> str:
    lines = ['      <div class="pt-grid4">', ""]
    for index, product in enumerate(catalog["products"]):
        key = escaped(product["key"])
        suffix = selector_suffix(product)
        presentation = product["presentation"]
        featured = presentation["featured"]
        border = "2px solid #7c2d3e" if featured else "1px solid #d3d5d4"
        if featured:
            badge = (
                '          <div style="position:absolute;top:14px;left:14px;background:#7c2d3e;color:#fff;'
                'font-size:11px;font-weight:600;letter-spacing:1px;padding:5px 12px;border-radius:999px;'
                'text-transform:uppercase;z-index:2">La favorita</div>'
            )
        else:
            badge = (
                f'          <div id="badge{suffix}" style="display:none;position:absolute;top:14px;left:14px;'
                'background:#26282a;color:#fff;font-size:11px;font-weight:600;letter-spacing:1px;'
                'padding:5px 12px;border-radius:999px;text-transform:uppercase;z-index:2">Para ti</div>'
            )
        lines.extend(
            [
                f'        <div id="card{suffix}" data-catalog-product="{key}" style="border-radius:14px;overflow:hidden;background:#fff;transition:all .3s;border:{border};box-shadow:none;position:relative;display:flex;flex-direction:column">',
                badge,
                f'          <div data-catalog-image style="height:170px;background:#d3d5d4 url(\'{escaped(product["media"]["image"])}\') center/cover no-repeat"></div>',
                '          <div style="padding:20px 22px;display:flex;flex-direction:column;flex:1">',
                f'            <div data-catalog-tag style="font-size:11px;letter-spacing:1.5px;color:#7c2d3e;font-weight:600;text-transform:uppercase;margin-bottom:6px">{escaped(presentation["tag"])}</div>',
                f'            <div data-catalog-title style="font-family:Lora,serif;font-size:20px;font-weight:600;margin-bottom:8px">{escaped(product["title"])}</div>',
                f'            <p style="font-size:13.5px;line-height:1.55;color:#55585a;margin:0 0 14px;flex:1">{escaped(presentation["card_description"])}</p>',
                f'            <div data-catalog-price style="font-family:Lora,serif;font-size:21px;color:#26282a;margin-bottom:12px">{money(product["price_mxn"])}</div>',
                '            <div style="display:flex;gap:8px">',
                f'              <button class="ptbtn" onclick="verDetalles(\'{key}\')" style="flex:1;background:transparent;border:1.5px solid #7c2d3e;color:#7c2d3e;padding:9px 10px;border-radius:999px;font-size:13px;font-weight:600;cursor:pointer">Detalles</button>',
                f'              <button class="ptbtn" onclick="verDetalles(\'{key}\')" style="flex:1;background:#26282a;color:#fff;border:none;padding:10px 10px;border-radius:999px;font-size:13px;font-weight:600;cursor:pointer">La quiero</button>',
                "            </div>",
                "          </div>",
                "        </div>",
            ]
        )
        if index < len(catalog["products"]) - 1:
            lines.append("")
    lines.append("      </div>")
    return "\n".join(lines)


def render_home_people_selector(catalog: dict) -> str:
    buttons = []
    for product in catalog["products"]:
        suffix = selector_suffix(product)
        people = product["people"]
        if people["min"] == people["max"]:
            label = f"{people['min']} personas" if people["min"] == 2 else str(people["min"])
        else:
            label = f"{people['min']}–{people['max']}"
        active = product["presentation"]["featured"]
        background = "#26282a" if active else "transparent"
        color = "#fff" if active else "#55585a"
        buttons.append(
            f'          <button id="seg{suffix}" onclick="pickPeople(\'{suffix}\')" style="border:none;cursor:pointer;font-size:14px;font-weight:600;padding:9px 20px;border-radius:999px;transition:all .2s;background:{background};color:{color}">{label}</button>'
        )
    return "\n".join(
        [
            '        <div style="display:flex;background:#e9eaea;border:1px solid #d3d5d4;border-radius:999px;padding:4px;gap:4px;flex-wrap:wrap">',
            *buttons,
            "        </div>",
        ]
    )


def render_home_extras(catalog: dict) -> str:
    by_key = {extra["key"]: extra for extra in catalog["extras"]}
    cards = []
    for key in ("pan", "dip", "mermelada"):
        extra = by_key[key]
        cards.extend(
            [
                f'        <div data-catalog-extra="{escaped(key)}" style="display:flex;align-items:center;justify-content:space-between;gap:14px;background:#fff;border:1px solid #d3d5d4;border-radius:12px;padding:16px 20px">',
                f'          <div><div data-catalog-extra-title style="font-weight:600;font-size:15px;color:#26282a;margin-bottom:2px">{escaped(extra["title"])}</div><div data-catalog-extra-description style="font-size:13px;color:#75797a">{escaped(extra["description"])}</div></div>',
                f'          <span data-catalog-extra-price style="font-family:Lora,serif;font-size:17px;color:#26282a;flex:none">{money(extra["price_mxn"])}</span>',
                "        </div>",
            ]
        )
    return "\n".join(
        [
            '      <div class="pt-grid3" style="display:grid;grid-template-columns:repeat(3,1fr);gap:18px">',
            *cards,
            "      </div>",
        ]
    )


def render_order_extras(catalog: dict) -> str:
    featured = next(product for product in catalog["products"] if product["presentation"]["featured"])
    premium = next(modifier for modifier in catalog["modifiers"] if modifier["key"] == "premium")
    lines = [
        f'          <button class="card wide" data-q="premium" data-v="si" id="cardPrem">{escaped(premium["title"])}<span class="m" id="premM">+ {money(premium["prices_mxn_by_product_key"][featured["key"]])}</span></button>'
    ]
    for extra in catalog["extras"]:
        lines.append(
            f'          <button class="card" data-q="extras" data-v="{escaped(extra["key"])}" data-multi="1">{escaped(extra["title"])}<span class="m" data-extra-price="{escaped(extra["key"])}">+ {money(extra["price_mxn"])}</span></button>'
        )
    return "\n".join(lines)


def render_html_blocks(catalog: dict) -> dict[Path, dict[str, str]]:
    products = catalog["products"]
    standard = [product for product in products if not product["is_event"]]
    events = [product for product in products if product["is_event"]]
    standard_min, standard_max = people_range(standard)
    event_min, event_max = people_range(events)
    featured = next(product for product in products if product["presentation"]["featured"])
    premium = next(modifier for modifier in catalog["modifiers"] if modifier["key"] == "premium")
    delivery = catalog["delivery"]
    logistics = catalog["logistics"]
    days = " y ".join(logistics["delivery_days_labels"])
    min_price = min(product["price_mxn"] for product in products)
    total = featured["price_mxn"] + delivery["price_mxn"]
    composition = featured["composition"]

    return {
        HOME: {
            "HOME_META": (
                '<meta name="description" content="Tablas de quesos y carnes armadas a mano y entregadas '
                f'listas para servir en CDMX. Tú pones la mesa, nosotros la tabla. Pídela por WhatsApp — desde {money(min_price)}.">'
            ),
            "HOME_HERO_FACTS": "\n".join(
                [
                    '      <div style="display:flex;gap:22px;font-size:13px;color:#75797a;flex-wrap:wrap">',
                    f"        <span>✓ Lista para servir</span><span>✓ Entrega {escaped(days)}</span><span>✓ Desde {money(min_price)}</span>",
                    "      </div>",
                ]
            ),
            "HOME_PEOPLE_SELECTOR": render_home_people_selector(catalog),
            "HOME_PRODUCTS": render_home_products(catalog),
            "HOME_PRODUCTS_NOTE": (
                '      <p style="font-size:12.5px;color:#75797a;margin:16px 0 0;text-align:center">'
                f'Precios en MXN · Mensajería {money(delivery["price_mxn"])} a toda la CDMX (se suma a tu pedido) '
                f'· Entregas {escaped(days)} · Tablas de evento ({event_min}–{event_max}): '
                f'{logistics["event_lead_time_days"]} días de anticipación</p>'
            ),
            "HOME_EXTRAS": render_home_extras(catalog),
            "HOME_DELIVERY_SUMMARY": (
                '      <p style="font-size:13px;color:#75797a;margin:16px 0 0">'
                f'Entregas {escaped(days)} (tablas de {standard_min} a {standard_max}: pide con al menos '
                f'{logistics["standard_lead_time_hours"]} h de anticipación). Tablas de evento de '
                f'{event_min} a {event_max}: con {logistics["event_lead_time_days"]} días de anticipación. '
                f'Mensajería {money(delivery["price_mxn"])} a toda la CDMX.</p>'
            ),
            "HOME_QUOTE_NOTE": (
                '        <p style="text-align:center;font-size:12.5px;color:#75797a;margin:14px 0 0">'
                f'Te respondemos por WhatsApp con el precio y la disponibilidad. Entregas {escaped(days)} '
                f'· mensajería {money(delivery["price_mxn"])} a toda la CDMX.</p>'
            ),
            "HOME_FOOTER_NOTE": (
                '    <div style="border-top:1px solid #3a3d40;text-align:center;padding:18px;font-size:12.5px;color:#75797a">'
                f'Picando Tabla · CDMX · Entregas {escaped(days)} · Tablas de evento bajo agenda · '
                f'Mensajería {money(delivery["price_mxn"])} a toda la CDMX</div>'
            ),
            "HOME_MODAL_DELIVERY": "\n".join(
                [
                    '        <div style="display:flex;align-items:center;justify-content:space-between;border-top:1px solid #d3d5d4;padding-top:12px;margin-bottom:4px">',
                    f'          <span style="font-size:13px;color:#75797a">{escaped(delivery["title"])} a toda la CDMX</span>',
                    f'          <span style="font-family:Lora,serif;font-size:15px;color:#55585a">{money(delivery["price_mxn"])}</span>',
                    "        </div>",
                ]
            ),
            "HOME_MODAL_PREMIUM_TITLE": (
                '            <div style="font-weight:600;font-size:14.5px;color:#26282a">'
                f'{escaped(premium["title"])} <span id="ptmPremPrecio" style="color:#7c2d3e"></span> '
                '<span style="background:#c9a44a;color:#fff;font-size:10px;font-weight:700;letter-spacing:1px;'
                'padding:2px 8px;border-radius:999px;text-transform:uppercase;vertical-align:2px">'
                f'{escaped(premium["title"].replace("Hazla ", ""))}</span></div>'
            ),
        },
        ORDER: {
            "ORDER_META": (
                '<meta name="description" content="Arma tu tabla de quesos y charcutería y mírala tomar '
                f'forma en vivo. Curaduría a tu medida, entrega {escaped(days)} en CDMX. Desde {money(min_price)}.">'
            ),
            "ORDER_HERO": "\n".join(
                [
                    f'    <h1 id="hName">{escaped(featured["title"])}</h1>',
                    f'    <div class="sub" id="hSub">{escaped(featured["presentation"]["order_subtitle"])} · {money(featured["price_mxn"])}</div>',
                    f'    <div class="comp" id="hComp">{composition["cheeses"]} quesos · {composition["meats"]} carnes · {composition["accompaniments"]} acompañamientos</div>',
                ]
            ),
            "ORDER_STEP_DEFAULT": (
                '        <button data-sec="tamano" class="on"><span class="k">Tamaño</span>'
                f'<span class="v" id="vTamano">{escaped(featured["title"])}</span></button>'
            ),
            "ORDER_EXTRAS": render_order_extras(catalog),
            "ORDER_DELIVERY_NOTE": (
                f'          <div class="note" id="noteEntrega">Entregamos {escaped(days)}. '
                f'Mensajería {money(delivery["price_mxn"])} en CDMX.</div>'
            ),
            "ORDER_TOTAL": "\n".join(
                [
                    '          <span class="k">Total con envío</span>',
                    f'          <span class="val" id="total">{money(total)}</span>',
                    f'          <span class="brk" id="brk">Tabla {money(featured["price_mxn"])} · mensajería {money(delivery["price_mxn"])}</span>',
                ]
            ),
        },
    }


def replace_generated_block(text: str, name: str, body: str, path: Path) -> str:
    start_marker = f"<!-- POND_CATALOG:{name}:START -->"
    end_marker = f"<!-- POND_CATALOG:{name}:END -->"
    if text.count(start_marker) != 1 or text.count(end_marker) != 1:
        fail(f"{path.relative_to(ROOT)} necesita exactamente un bloque {name}")
    start = text.index(start_marker)
    end = text.index(end_marker, start)
    line_start = text.rfind("\n", 0, start) + 1
    indent = text[line_start:start]
    replacement = f"{start_marker}\n{body.rstrip()}\n{indent}{end_marker}"
    return text[:start] + replacement + text[end + len(end_marker) :]


def expected_html(
    catalog: dict,
    path: Path,
    blocks: dict[str, str],
    catalog_version: str,
) -> str:
    text = path.read_text(encoding="utf-8")
    for name, body in blocks.items():
        text = replace_generated_block(text, name, body, path)
    return version_catalog_script(text, path, catalog_version)


def version_catalog_script(text: str, path: Path, version: str) -> str:
    pattern = re.compile(
        r'(<script\s+src="(?:\.\./)?catalogo\.js)(?:\?v=[a-f0-9]+)?("></script>)'
    )
    updated, count = pattern.subn(rf"\1?v={version}\2", text)
    if count != 1:
        fail(
            f"{path.relative_to(ROOT)} necesita exactamente un script catalogo.js"
        )
    return updated


def public_source_files() -> list[Path]:
    sources = []
    for current, directories, filenames in os.walk(ROOT):
        directories[:] = [
            name
            for name in directories
            if name.lower() not in PRIVATE_OR_GENERATED_DIRS
        ]
        base = Path(current)
        for filename in filenames:
            lowered = filename.lower()
            if lowered.startswith(".env") or lowered.endswith(".min.js"):
                continue
            path = base / filename
            if path.suffix.lower() in PUBLIC_SOURCE_SUFFIXES:
                sources.append(path)
    return sorted(sources)


def catalog_price_values(catalog: dict) -> set[int]:
    values = {product["price_mxn"] for product in catalog["products"]}
    values.update(extra["price_mxn"] for extra in catalog["extras"])
    values.add(catalog["delivery"]["price_mxn"])
    for modifier in catalog["modifiers"]:
        values.update(modifier.get("prices_mxn_by_product_key", {}).values())
    return values


def validate_no_unmanaged_catalog_prices(
    catalog: dict, blocks_by_path: dict[Path, dict[str, str]]
) -> None:
    catalog_prices = catalog_price_values(catalog)
    findings = []
    for path in public_source_files():
        text = path.read_text(encoding="utf-8")
        if path in blocks_by_path:
            for name in blocks_by_path[path]:
                text = replace_generated_block(text, name, "", path)
        for match in PRICE_LITERAL.finditer(text):
            raw = match.group(1) or match.group(2)
            value = int(re.sub(r"[^0-9]", "", raw))
            if value not in catalog_prices:
                continue
            line = text.count("\n", 0, match.start()) + 1
            findings.append(f"{path.relative_to(ROOT)}:{line} ({match.group(0)})")
    if findings:
        fail(
            "precios del catálogo fuera de fuentes o bloques generados: "
            + ", ".join(findings)
        )


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--check",
        action="store_true",
        help="valida que la proyección JS y los fallbacks HTML estén actualizados sin escribir",
    )
    args = parser.parse_args()
    catalog = load_and_validate()
    generated = render_js(catalog)
    catalog_version = hashlib.sha256(
        generated.encode("utf-8")
    ).hexdigest()[:12]
    blocks_by_path = render_html_blocks(catalog)
    validate_no_unmanaged_catalog_prices(catalog, blocks_by_path)
    if args.check:
        if not TARGET.exists() or TARGET.read_text(encoding="utf-8") != generated:
            fail("catalogo.js está desactualizado; ejecuta tools/build_catalog.py")
        for path, blocks in blocks_by_path.items():
            current = path.read_text(encoding="utf-8")
            if current != expected_html(
                catalog,
                path,
                blocks,
                catalog_version,
            ):
                fail(f"{path.relative_to(ROOT)} contiene un fallback comercial desactualizado")
        print("Catálogo válido; proyección JS y fallbacks HTML actualizados.")
        return 0
    TARGET.write_text(generated, encoding="utf-8", newline="\n")
    updated = [str(TARGET.relative_to(ROOT))]
    for path, blocks in blocks_by_path.items():
        rendered = expected_html(
            catalog,
            path,
            blocks,
            catalog_version,
        )
        if path.read_text(encoding="utf-8") != rendered:
            path.write_text(rendered, encoding="utf-8", newline="\n")
        updated.append(str(path.relative_to(ROOT)))
    print("Generado: " + ", ".join(updated))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
