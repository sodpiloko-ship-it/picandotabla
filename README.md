# Picando Tabla — sitio web (tienda)

Tienda en línea estática de **Picando Tabla** (tablas de parota & maridaje, CDMX). Un solo `index.html`
**self-contained** (sin dependencias externas): Inicio, Tienda, Producto (con grabado), Carrito, Pago,
Maridaje y Nosotros. Carrito en `localStorage`. Pensado para **GitHub → Hostinger**.

```
index.html        ← el sitio completo (HTML/CSS/JS en un archivo)
favicon.svg       ← ícono (monograma PT)
robots.txt        ← SEO (apunta al sitemap)
sitemap.xml       ← SEO
.github/workflows/deploy.yml  ← auto-deploy a Hostinger por FTP (opción B)
```

---

## 1) Publicar como repo de GitHub

> Esta carpeta vive dentro del repo de Patológicos. Para que sea **su propio repo**, cópiala a un lugar
> aparte y haz `git init` ahí (así no anidas repos).

1. Copia la carpeta `web/picandotabla` a un lugar propio, p. ej. `C:\picandotabla.com`.
2. Crea un repo **vacío** en GitHub (github.com/new) llamado `picandotabla` (sin README ni .gitignore).
3. En la carpeta nueva:

```bash
cd C:\picandotabla.com
git init
git add .
git commit -m "Picando Tabla — sitio inicial"
git branch -M main
git remote add origin https://github.com/<tu-usuario>/picandotabla.git
git push -u origin main
```

---

## 2) Conectar Hostinger (elige UNA opción)

### Opción A — Git nativo de Hostinger (la más simple, sin secrets) ✅ recomendada
1. hPanel → **Avanzado → Git** (o "GIT").
2. **Crear repositorio**: pega la URL del repo, rama `main`, y **directorio de despliegue** = el
   `public_html` del dominio (p. ej. `public_html` o `domains/picandotabla.com/public_html`).
3. Activa **Auto-deployment / webhook** para que jale en cada push (o "Deploy" manual cuando quieras).

### Opción B — GitHub Actions por FTP (auto-deploy en cada push)
Ya viene el workflow en `.github/workflows/deploy.yml`. Solo agrega **3 secrets** en el repo
(GitHub → Settings → Secrets and variables → Actions → New repository secret):

| Secret | Qué es | Dónde sale |
|---|---|---|
| `FTP_SERVER` | host FTP | hPanel → Archivos → Cuentas FTP (ej. `ftp.picandotabla.com` o la IP) |
| `FTP_USERNAME` | usuario FTP | la cuenta FTP que crees ahí |
| `FTP_PASSWORD` | contraseña FTP | la de esa cuenta |

Si el dominio es **addon**, cambia en el workflow `server-dir: public_html/` por
`server-dir: domains/picandotabla.com/public_html/`. Con eso, cada `git push` publica solo.

---

## 3) Dominio
Apunta **picandotabla.com** a Hostinger (si el dominio no está ahí, cambia los nameservers a los de
Hostinger o crea un registro A a la IP del hosting). Luego activa **SSL** (hPanel → Seguridad → SSL).

---

## Pendiente (lo siguiente, no bloquea el deploy)
- **Cobro con Stripe.** Hoy el pago se cierra por WhatsApp (honesto). Para cobrar en línea:
  **Stripe Payment Links** (sin backend, ideal para este sitio estático) o **Checkout** con un mini-endpoint
  PHP en Hostinger. Tus llaves de Stripe son secretas — tú las pones; el cableado se deja listo aparte.
- **Fotos reales + precios finales** (de Jessica). Hoy hay ilustraciones SVG y precios v0 de referencia.

— Hecho por Patológicos · `web/picandotabla/` es la fuente; este repo es su copia desplegable.
