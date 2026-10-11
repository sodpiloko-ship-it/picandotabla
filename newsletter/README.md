# La sobremesa · Picando Tabla

Newsletter independiente de pedidos y cotizaciones. No importar clientes a esta lista. Instagram y Facebook se publican con los enlaces confirmados por David el 10/10/2026.

## Publicación y almacenamiento

El sitio vivo toma `main` del repositorio `sodpiloko-ship-it/picandotabla`. La alternativa FTP de GitHub Actions no está configurada: su paso omitido no prueba ni impide el despliegue nativo de Hostinger. Verificar los archivos por HTTP tras cada push.

Requiere PHP 8.3, PDO SQLite y transporte `mail()` del alojamiento. La base, configuración, bloqueos y registros se guardan en `/home/u376905662/domains/picandotabla.com/.picandotabla-newsletter`, fuera de `public_html`. No subir esa carpeta a Git ni copiarla al sitio público. Resguardar esta carpeta con copias privadas del alojamiento.

El envío comienza desactivado. `php newsletter/worker.php --enable` guarda `send_enabled` en `config.json` privado para compartirlo entre el proceso web y cron. `--disable` detiene el envío y nuevas solicitudes; las bajas siguen funcionando.

Configurar un cron cada cinco minutos:

```sh
/usr/bin/php /home/u376905662/domains/picandotabla.com/public_html/newsletter/worker.php
```

## Suscripción y entregas

- Consentimiento explícito; confirmación por enlace y botón, válido durante 48 horas.
- El escáner de enlaces de un correo no activa una suscripción con un GET.
- Una bienvenida por alta confirmada. Baja por enlace y confirmación; cancela la cola pendiente.
- Las solicitudes repetidas reciben respuesta genérica. Límite de solicitudes y campo trampa contra altas automáticas básicas.
- Cinco intentos de envío por minuto y por ejecución; 50 intentos por 24 horas por defecto, incluidos los fallidos, para reservar capacidad a pedidos. Es un límite del newsletter, no una medida del consumo total de la cuenta.
- Hasta cinco intentos cuando el transporte rechaza claramente el mensaje. Un resultado incierto se detiene en `review`; no se reenvía automáticamente.
- Bloqueo entre ejecuciones y control de destinatario/edición. La suscripción se revisa nuevamente al tomar cada mensaje de la cola.
- `accepted` sólo significa aceptado por el servidor de correo. No equivale a llegada a la bandeja, apertura o ausencia de rebotes. No se implementan píxeles de apertura ni procesamiento automático de rebotes.
- No habilitar `one_click` hasta comprobar que el servidor firma con DKIM los encabezados List-Unsubscribe y List-Unsubscribe-Post.

Hostinger documenta 10 mensajes/minuto y 100/día para `mail()`: https://www.hostinger.com/support/11393648-php-mail-limitation-explained-how-to-improve-email-delivery-with-smtp/. Antes de aumentar volumen, migrar a transporte SMTP/proveedor transaccional con rebotes y supresiones.

## Operación

Ver estado agregado sin exponer correos:

```sh
php newsletter/worker.php --status
```

Preparar una edición como JSON con `id` estable, `subject` y `body` en texto UTF-8. Vista previa:

```sh
php newsletter/campaign.php newsletter/edition-01.json
```

Sólo tras aprobar el contenido y sus destinatarios, encolar la campaña:

```sh
php newsletter/campaign.php newsletter/edition-01.json --queue
```

Una edición se encola una sola vez, incluso si los registros viejos de cola se han purgado. Un ID nuevo representa un envío nuevo. La primera edición está preparada; no se distribuye automáticamente por publicarla.

Prueba interna autorizada, sin incorporar al destinatario a la lista:

```sh
php newsletter/worker.php --queue-test newsletter/edition-01.json CORREO_AUTORIZADO
php newsletter/worker.php
```

Revisar la salida del cron y los estados `failed`/`review`. Un resultado incierto requiere comprobar el proveedor antes de intentar cualquier reenvío. No reactivar suscriptores dados de baja. Atender las solicitudes de consulta, corrección y eliminación desde contacto@picandotabla.com.

## Integración con el blog

`tools/import_seo_articles.py` integra automáticamente el bloque. Para otras páginas creadas por herramientas diferentes:

```sh
python tools/install_community.py
node validate.mjs
python tools/build_catalog.py --check
```

La instalación es idempotente, reconoce el pie real sin insertar contenido dentro de scripts ni del panel de compra y omite pago, órdenes y comanda. El pop-up espera 25 segundos y desplazamiento, no interrumpe formularios ni otro diálogo y se suprime 30 días después de mostrarse o solicitar suscripción.

## Pruebas sin correo real

Definir `PT_NEWSLETTER_TEST=1` y `PT_NEWSLETTER_DATA` con una ruta absoluta temporal fuera del sitio. El transporte escribe `test-outbox.jsonl`; jamás usa `mail()`. La suite está en `tools/test_newsletter.php`. Nunca activar modo de prueba en producción.
