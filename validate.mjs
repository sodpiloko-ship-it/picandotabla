import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = fileURLToPath(new URL(".", import.meta.url));
const read = (name) => fs.readFileSync(path.join(root, name), "utf8");

function walk(dir) {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);
    return entry.isDirectory() ? walk(full) : [full];
  });
}

const htmlFiles = walk(root)
  .filter((file) => file.endsWith(".html"))
  .map((file) => path.relative(root, file).replaceAll("\\", "/"))
  .filter((file) => !/name=["']robots["'][^>]+content=["'][^"']*\bnoindex\b[^"']*["']/i.test(read(file)))
  .sort();
const titles = new Map();
const canonicals = new Map();
const promotion = "PROMOCIONES · Tabla para Dos $485 hasta 20 sep · Caja de tapas GRATIS en tablas para más de 4 personas";

function attribute(html, name, value, attributeName = "content") {
  const tag = html.match(new RegExp(`<meta[^>]+${name}=["']${value}["'][^>]*>`, "i"))?.[0];
  return tag?.match(new RegExp(`${attributeName}=["']([^"']+)["']`, "i"))?.[1];
}

function localTarget(href, sourceFile) {
  if (/^(mailto:|tel:|javascript:|#)/i.test(href)) return null;
  let pathname = href;
  if (/^https?:\/\//i.test(href)) {
    const url = new URL(href);
    if (url.hostname !== "picandotabla.com" && url.hostname !== "www.picandotabla.com") return null;
    pathname = url.pathname;
  }
  pathname = pathname.split(/[?#]/)[0];
  if (!pathname) return null;
  if (pathname.startsWith("/")) pathname = pathname.slice(1);
  else pathname = path.posix.join(path.posix.dirname(sourceFile), pathname);
  if (!pathname || pathname.endsWith("/")) pathname += "index.html";
  return pathname;
}

for (const file of htmlFiles) {
  const html = read(file);
  assert.ok(html.replace(/\s+/g, " ").includes(promotion), `${file}: promoción visible`);
  assert.doesNotMatch(html, /href=["']\/orden\//i, `${file}: no enlaza al configurador legado`);
  const visibleCopy = html.replace(/<script\b[\s\S]*?<\/script>/gi, " ").replace(/<style\b[\s\S]*?<\/style>/gi, " ");
  assert.doesNotMatch(visibleCopy, /\b(dip|mermelada|viernes|sábado)\b/i, `${file}: copia sin regalos ni días específicos`);
  assert.equal((html.match(/<h1\b/gi) || []).length, 1, `${file}: exactamente un H1`);

  const title = html.match(/<title>([^<]+)<\/title>/i)?.[1]?.trim();
  const description = attribute(html, "name", "description");
  const canonical = html.match(/<link[^>]+rel=["']canonical["'][^>]+href=["']([^"']+)/i)?.[1];
  assert.ok(title && title.length >= 25 && title.length <= 70, `${file}: title útil y conciso`);
  assert.ok(description && description.length >= 70 && description.length <= 170, `${file}: meta description útil`);
  assert.ok(canonical?.startsWith("https://picandotabla.com/"), `${file}: canonical propio`);
  assert.ok(attribute(html, "property", "og:title"), `${file}: og:title`);
  assert.ok(attribute(html, "property", "og:description"), `${file}: og:description`);
  assert.ok(attribute(html, "property", "og:image"), `${file}: og:image`);

  const titleKey = title.toLocaleLowerCase("es-MX");
  assert.ok(!titles.has(titleKey), `${file}: title duplicado con ${titles.get(titleKey)}`);
  assert.ok(!canonicals.has(canonical), `${file}: canonical duplicado con ${canonicals.get(canonical)}`);
  titles.set(titleKey, file);
  canonicals.set(canonical, file);

  for (const match of html.matchAll(/<script[^>]*type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi)) {
    assert.doesNotThrow(() => JSON.parse(match[1]), `${file}: JSON-LD válido`);
  }
  const scripts = [...html.matchAll(/<script(?![^>]*\bsrc=)(?![^>]*application\/ld\+json)[^>]*>([\s\S]*?)<\/script>/gi)];
  for (const [, script] of scripts) {
    assert.doesNotThrow(() => new Function(script.replace(/^\s*import\s+[^;]+;\s*$/gm, "")), `${file}: JavaScript inline válido`);
  }

  for (const [, href] of html.matchAll(/<a[^>]+href=["']([^"']+)["']/gi)) {
    const target = localTarget(href, file);
    if (target) assert.ok(fs.existsSync(path.join(root, target)), `${file}: enlace roto ${href}`);
  }
  for (const image of html.matchAll(/<img\b([^>]*)>/gi)) {
    if (/facebook\.com\/tr|width=["']1["']/i.test(image[1])) continue;
    assert.match(image[1], /\balt=["'][^"']*["']/i, `${file}: imagen con alt`);
  }
}

const productFiles = [
  ["tablas/para-dos/index.html", 485],
  ["tablas/anfitriona/index.html", 950],
  ["tablas/fiesta/index.html", 1600],
  ["tablas/celebracion/index.html", 2600],
];
for (const [file, price] of productFiles) {
  const html = read(file);
  const product = [...html.matchAll(/<script[^>]*application\/ld\+json[^>]*>([\s\S]*?)<\/script>/gi)]
    .map((match) => JSON.parse(match[1]))
    .find((data) => data["@type"] === "Product");
  assert.equal(product?.offers?.price, price, `${file}: precio de Product schema`);
  assert.match(html, new RegExp(`\\$${price.toLocaleString("en-US")} MXN`), `${file}: precio visible coincide`);
}

const sitemap = read("sitemap.xml");
for (const canonical of canonicals.keys()) assert.ok(sitemap.includes(`<loc>${canonical}</loc>`), `sitemap incluye ${canonical}`);
assert.doesNotMatch(sitemap, /https:\/\/picandotabla\.com\/orden\//);
assert.match(read("robots.txt"), /Sitemap: https:\/\/picandotabla\.com\/sitemap\.xml/);
assert.match(read("llms.txt"), /^# Picando Tabla/m);
assert.match(read("llms.txt"), /https:\/\/picandotabla\.com\/llms-full\.txt/);
assert.match(read("llms-full.txt"), /La ruta antigua `\/orden\/` no debe usarse/);

const home = read("index.html");
assert.match(home, /extra\.key==='pan'/);
assert.doesNotMatch(home, /data-catalog-extra="dip"/);
assert.doesNotMatch(home, /data-catalog-extra="mermelada"/);
assert.match(home, /pedidos pagados antes del 20 de septiembre de 2026/);
assert.equal((home.match(/Caja de tapas de regalo incluida/g) || []).length, 3, "home: regalo visible en tres tablas elegibles");
assert.match(home, /id="promo-tapas"[\s\S]*caja-tapas-regalo\.jpg[\s\S]*Ver tablas con regalo/);
assert.match(read("tablas/index.html"), /id="promo-tapas"[\s\S]*Se agrega automáticamente:/);
assert.match(home, /new URLSearchParams\(window\.location\.search\)\.get\('tabla'\)/);
for (const field of ["ptmCliente", "ptmWhatsapp", "ptmZona", "ptmFecha", "ptmNotas"]) {
  assert.match(home, new RegExp(`id=["']${field}["']`), `popup incluye ${field}`);
}
assert.match(home, /origen:'landing_popup_producto'/);

const order = read("orden/index.html");
assert.doesNotMatch(order, /data-v="dip"/);
assert.doesNotMatch(order, /data-v="mermelada"/);
assert.match(order, /var wantF = \[\]/);
assert.doesNotMatch(order, /Dip de la casa \(incluido\)/);
assert.doesNotMatch(order, /Mermelada de la casa \(incluida\)/);
assert.match(order, /S\.extras = S\.extras\.filter\(function\(k\)\{ return k === 'pan'; \}\)/);
assert.match(order, /if\(c\.t\.regalo\) out\.push\(\{qty:1, nombre:REGALO\.title\+' \(incluida\)'/);
assert.match(order, /eligible_product_keys\.indexOf\(product\.key\) > -1/);
assert.match(order, /name="robots" content="noindex,follow"/);
assert.match(read(".htaccess"), /RewriteRule \^orden\/\?\$ \/ \[R=301,L\]/);
assert.match(read(".htaccess"), /<\/llms\.txt>; rel=describedby/);

const catalog = read("catalogo.js");
assert.match(catalog, /["']?price_mxn["']?\s*:\s*485/);
assert.match(catalog, /["']?regular_price_mxn["']?\s*:\s*650/);
assert.match(catalog, /["']eligible_product_keys["']:\["anfitriona","fiesta","celebracion"\]/);
assert.doesNotMatch(catalog, /["']?key["']?\s*:\s*["'](?:dip|mermelada)["']/);
assert.match(read("seo.css"), /\.promo\{position:sticky;top:0/);
assert.match(read("seo.css"), /\.site-header\{position:sticky;top:60px/);
assert.match(read("tablas/para-dos/index.html"), /"priceValidUntil": "2026-09-19"/);
assert.doesNotMatch(read("tablas/para-dos/index.html"), /Regalo incluido:/);
for (const file of ["tablas/anfitriona/index.html", "tablas/fiesta/index.html", "tablas/celebracion/index.html"]) {
  assert.match(read(file), /Regalo incluido:[\s\S]*caja de[\s\n]+tapas sin costo/i, `${file}: regalo comunicado`);
}
assert.ok(fs.existsSync(path.join(root, "img/caja-tapas-regalo.jpg")), "imagen de tapas incluida");
assert.match(read("blog/index.html"), /caja-de-tapas-regalo-tablas-cdmx\.html/);
assert.match(read("blog/caja-de-tapas-regalo-tablas-cdmx.html"), /"@type": "FAQPage"/);

const importedArticles = [
  ["blog/rinde-por-tabla.html", "01-cuanto-queso-por-persona"],
  ["blog/botanas-para-reuniones-cdmx.html", "02-botanas-para-reuniones"],
  ["blog/precio-tabla-quesos-cdmx.html", "03-precio-tabla-quesos-cdmx"],
  ["blog/como-armar-tabla-de-quesos.html", "04-que-quesos-lleva-una-tabla"],
  ["blog/regalo-tabla-de-quesos.html", "05-ideas-regalos-gourmet-cdmx"],
];
for (const [file, imageKey] of importedArticles) {
  const html = read(file);
  assert.match(html, /"@type":"Article"/, `${file}: schema Article`);
  assert.match(html, /"@type":"BreadcrumbList"/, `${file}: schema BreadcrumbList`);
  assert.match(html, new RegExp(`/img/blog/${imageKey}-1600x900\\.webp`), `${file}: hero editorial`);
  for (const suffix of ["-800x450.webp", "-1200x675.webp", "-1600x900.webp", "-og-1200x630.jpg"]) {
    assert.ok(fs.existsSync(path.join(root, `img/blog/${imageKey}${suffix}`)), `${file}: imagen ${suffix}`);
  }
}
assert.match(read("blog/index.html"), /botanas-para-reuniones-cdmx\.html/);
assert.match(read("blog/index.html"), /precio-tabla-quesos-cdmx\.html/);
assert.match(read("tablas/index.html"), /Guías para calcular y comparar/);
assert.match(read("reuniones/index.html"), /botanas-para-reuniones-cdmx\.html/);

console.log(`OK: ${htmlFiles.length} páginas, metadatos, schema, enlaces, sitemap y pedidos validados.`);
