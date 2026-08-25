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
  .filter((file) => !/name=["']robots["'][^>]+content=["']noindex["']/i.test(read(file)))
  .sort();
const titles = new Map();
const canonicals = new Map();
const promotion = "PROMOCIÓN ESPECIAL · Tabla para Dos $485 (antes $650) · pedidos pagados antes del 20 de septiembre de 2026";

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
assert.match(read("robots.txt"), /Sitemap: https:\/\/picandotabla\.com\/sitemap\.xml/);

const home = read("index.html");
assert.match(home, /extra\.key==='pan'/);
assert.doesNotMatch(home, /data-catalog-extra="dip"/);
assert.doesNotMatch(home, /data-catalog-extra="mermelada"/);
assert.match(home, /pedidos pagados antes del 20 de septiembre de 2026/);

const order = read("orden/index.html");
assert.doesNotMatch(order, /data-v="dip"/);
assert.doesNotMatch(order, /data-v="mermelada"/);
assert.match(order, /var wantF = \[\]/);
assert.doesNotMatch(order, /Dip de la casa \(incluido\)/);
assert.doesNotMatch(order, /Mermelada de la casa \(incluida\)/);
assert.match(order, /S\.extras = S\.extras\.filter\(function\(k\)\{ return k === 'pan'; \}\)/);

const catalog = read("catalogo.js");
assert.match(catalog, /["']?price_mxn["']?\s*:\s*485/);
assert.match(catalog, /["']?regular_price_mxn["']?\s*:\s*650/);
assert.doesNotMatch(catalog, /["']?key["']?\s*:\s*["'](?:dip|mermelada)["']/);
assert.match(read("seo.css"), /\.promo\{position:sticky;top:0/);
assert.match(read("seo.css"), /\.site-header\{position:sticky;top:60px/);
assert.match(read("tablas/para-dos/index.html"), /"priceValidUntil": "2026-09-19"/);

console.log(`OK: ${htmlFiles.length} páginas, metadatos, schema, enlaces, sitemap y pedidos validados.`);
