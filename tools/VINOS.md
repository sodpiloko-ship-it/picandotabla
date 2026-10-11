# Agregar vinos y reseñas a Picando Tabla

La sección vive en `https://picandotabla.com/vinos/`. El origen editable es
`data/vinos.json`. `tools/build_wines.py` genera el catálogo, cada ficha, la página
de criterio editorial y los bloques de descubrimiento de sitemap y referencias.
No hace falta una base de datos nueva. El contenido principal está en HTML y se
puede leer sin ejecutar JavaScript.

## Cargar una etiqueta

Desde la raíz del repositorio:

```bash
python tools/build_wines.py --new bodega-linea-uva
```

Esto agrega un registro a `data/vinos.json` con `status: "draft"`, basado en
`tools/vino-ejemplo.json`. Los borradores no se generan como páginas ni aparecen
en el índice, sitemap o archivos de referencia. Las fichas publicadas y todos
los datos de este archivo deben ser públicos: no registrar costos de compra,
credenciales, datos de clientes ni notas privadas. El repositorio es público.

Completa nombre exacto, marca, tipo, origen, uvas, resumen, perfil documentado,
fuentes con fecha real de consulta y propuestas de maridaje con tablas reales.
`sources[].supports` indica los campos respaldados por cada fuente. Deja en
`null` los datos que no estén confirmados, especialmente añada, graduación,
volumen o dulzor. Una añada desconocida no significa «sin añada».

Para publicar cambia `status` a `published`, completa
`review.published_at` y `review.updated_at` con fechas reales, y actualiza
`updated_at` en la raíz del catálogo. Usa el nombre de la etiqueta en el slug;
mantén la URL estable cuando se actualice la información.

```bash
python tools/build_wines.py
python tools/build_wines.py --check
python tools/build_catalog.py --check
python -m unittest discover -s tools -p 'test_wines.py'
node --check vinos/vinos.js
node validate.mjs
```

Versiona el JSON y los archivos generados juntos. Los cambios entran por un PR y
se integran en `main` después de las comprobaciones. No edites directamente los
HTML de `/vinos/`: el generador los reconstruye. Un slug nuevo crea una URL nueva;
si hay que renombrar uno que ya se publicó, prepara también una redirección 301.
Volver una ficha publicada a borrador retira su HTML generado y enlaces; para
retiradas permanentes valora el destino o respuesta HTTP apropiada.

## Tipos editoriales

`review.kind: "documentary"` publica datos atribuidos a sus fuentes y propuestas
de maridaje. Cata, puntuación y evidencia permanecen en `null` o listas vacías.
No hay `Review`, `AggregateRating` ni ofertas inventadas en su JSON-LD. Se emiten
`Article` y `BreadcrumbList`; el índice usa `CollectionPage` e `ItemList`.

Para una cata real usa `review.kind: "tasted"` y registra:

- `author`: nombre de quien hizo la cata.
- `tasted_at`: fecha de la experiencia; no posterior a la actualización.
- `tasting_published_at`: fecha en que se publicó la cata, posterior o igual a la
  experiencia. Permite conservar la fecha original de una ficha que antes era
  documental. La fecha de una reseña no puede ser anterior a la prueba.
- `tasted_vintage`: año de la botella o `null` si no quedó registrado.
- `tasting_notes`: notas propias. Identifica aquí la presentación y botella
  probada si no queda suficientemente clara en los atributos de la ficha.
- `evidence`: lista de objetos `{"url":"https://…","caption":"…"}` con enlaces
  públicos a evidencia propia de la experiencia.
- `rating`: opcional, `{"value":4,"min":1,"max":5,"method":"Criterio explicado"}`.

Solo una puntuación real, visible y explicada habilita `Product` con `Review`.
No se importan puntuaciones de otras plataformas. Una valoración no representa
un conjunto de opiniones: no generamos `AggregateRating`.
Cuando la ficha identifica una añada, la cata debe corresponder a la misma.

La imagen de botella es opcional. Cuando haya una fotografía propia o con permiso,
colócala en `/img/vinos/` y usa
`image: {"src":"/img/vinos/etiqueta.jpg","alt":"…","credit":"…"}`.
Las imágenes actuales de tablas son referencias de presentación, no fotos de las
botellas ni evidencia de cata. Sin imagen de botella se muestra una ficha técnica.

## Relación con las tablas y cotización

`pairings[].product_key` referencia `dos`, `anfitriona`, `fiesta` o `celebracion`.
El generador toma el nombre y la imagen de `data/catalogo.json`; la ficha enlaza
al contenido y precio vigentes. `reason` explica por qué proponemos la combinación.
No copiar precios de las tablas ni garantizar ingredientes distintos al catálogo.

Los enlaces de WhatsApp conservan vino, URL y tabla elegida en el mensaje. No
envían nada automáticamente. El usuario decide enviarlo en WhatsApp.

Esta primera versión es un catálogo editorial con cotización. `offer` debe seguir
en `null`. Antes de vender botellas mediante checkout hace falta confirmar SKUs,
presentación, precios propios, disponibilidad y el flujo de cobro. El precio
consultado en Walmart u otra tienda no puede publicarse como precio de Picando
Tabla. El generador bloquea ofertas a medio configurar.

## Descubrimiento y medición

Una URL por etiqueta; contenido visible desde el HTML, metadatos únicos, enlaces
a productos reales y sitemap actualizado. La guía de maridaje existente conserva
su URL; no se crea otra guía duplicada para la misma intención. Los filtros de
búsqueda y tipo aparecen a partir de cuatro fichas y no crean categorías vacías.
Las URLs filtradas mantienen canonical al índice limpio.

La política vigente de `robots.txt` ya permite los buscadores y asistentes. No se
cambia la preferencia de acceso de otros bots. Se mantienen los archivos `llms`
existentes como directorios de referencia, sin prometer que mejoren rankings.
Google dice que no necesita estos archivos ni un marcado especial para IA.

Analytics reutiliza la propiedad existente. Eventos: `wine_view`,
`wine_table_click`, `wine_quote_click` y `wine_filter`; este último guarda solo
estado, tipo y conteo, no el texto buscado. Un clic de cotización no equivale a
una venta. Para atribuir ingresos hay que relacionarlo después con un pedido real.

Tras publicar, usar Search Console para comprobar indexación, sitemap y la
inclusión en funciones generativas de búsqueda. Cuando esté disponible para la
propiedad, revisar también su informe de rendimiento de IA. Ninguna de estas
comprobaciones garantiza una cita o posición. No se ha accedido ni modificado la
cuenta de Search Console durante la implementación.

Fuentes técnicas revisadas el 10 de octubre de 2026:

- [Google: optimización para funciones de IA](https://developers.google.com/search/docs/fundamentals/ai-optimization-guide)
- [Google: reseñas de calidad](https://developers.google.com/search/docs/specialty/ecommerce/write-high-quality-reviews)
- [Google: datos de Product](https://developers.google.com/search/docs/appearance/structured-data/product-snippet)
- [Google: datos de Review](https://developers.google.com/search/docs/appearance/structured-data/review-snippet)

## Publicación y datos operativos

La ruta existente es GitHub → Hostinger. Se preservan las exclusiones de FTP
`data/**`, `comanda/data/**` y `secrets/**`, porque contienen pedidos y estado
operativo. El JSON de vinos se versiona como fuente; el sitio público sirve sus
HTML generados y no necesita leerlo en producción. No modificar esas exclusiones
para publicar el catálogo.

El workflow FTP puede finalizar correctamente aunque el paso de subida se omita
por falta de `FTP_SERVER`. Un resultado verde no prueba que el dominio se haya
actualizado: confirmar las URLs públicas. Si el hosting usa su integración Git
nativa, conservar ese mecanismo y comprobar su despliegue.

La regla específica de `.htaccess` deniega `/data/vinos.json` si el despliegue Git
copia el archivo al hosting. Comprobar que esa URL responda 403 o 404; las fichas
HTML publicadas deben responder 200. Esto evita servir borradores desde el
dominio, pero no convierte el repositorio público en un lugar para datos privados.
