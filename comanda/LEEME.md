# Comanda de Picando Tabla — notas de operación

La comanda vive en **https://picandotabla.com/comanda** y es el **registro único de pedidos**:
los que entran por la web (home y `/orden`) llegan solos; los que se cierran por WhatsApp se
capturan con **“＋ Registrar pedido manual”**. Si un pedido no está aquí, no existe.

## Dónde viven los datos (y por qué ahí)

Todo el estado de producción está en **`public_html/data/`**, fuera del árbol que sincroniza el
deploy:

| Archivo | Qué guarda |
| --- | --- |
| `data/orders.jsonl` | los pedidos |
| `data/eventos.jsonl` | las solicitudes de la página de eventos |
| `data/comanda/clave.txt` | el **hash** de la contraseña (nunca la contraseña) |
| `data/comanda/estado.json` | nueva / confirmada / entregada de cada pedido |
| `data/comanda/fallos.log` | intentos fallidos de acceso (freno anti fuerza bruta) |

⚠️ **Regla que costó un susto (2026-07-24):** el deploy por FTP *sincroniza y borra* lo que no
está en el repo dentro de las carpetas que toca. Cuando este estado vivía en `comanda/data/`,
cada publicación se llevaba la contraseña y los estados de los pedidos. Por eso vive en `data/`
y el workflow excluye `data/**`, `comanda/data/**` y `secrets/**`.
**No muevas datos de producción a una carpeta que el deploy sincroniza.**

## Acceso

Entran solo los correos de la lista en `comanda/config.php` (Jessica, David y contacto@), con
una contraseña compartida. Se cambia desde la propia comanda, en **“Cambiar contraseña”**.

### Si nadie puede entrar (contraseña perdida)

1. Entra a hPanel → Administrador de archivos → `public_html/data/comanda/`.
2. Borra **`clave.txt`** (y `fallos.log` si el acceso está frenado).
3. Vuelve a abrir `picandotabla.com/comanda`: como ya no hay contraseña, la primera que se
   registre queda como la nueva. Hazlo de inmediato para que nadie más la tome.

Alternativa sin borrar nada: sube a mano `public_html/secrets/comanda-clave.txt` con un hash
bcrypt; ese archivo **manda** sobre `clave.txt`.

### Freno de seguridad

Tras 8 intentos fallidos desde la misma conexión, esa conexión espera 10 minutos. No afecta a
las demás.
