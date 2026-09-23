/* Meta Pixel de Picando Tabla para las páginas sin snippet propio (fichas, ocasiones y blog).
   El inicio, /eventos/, el blog antiguo y pago/gracias.php traen su propio snippet con el mismo ID.
   Eventos: PageView en toda página; ViewContent en las fichas /tablas/<tabla>/; Contact al abrir WhatsApp;
   el pago (InitiateCheckout) y la compra (Purchase) los mide la ficha del inicio y pago/gracias.php. */
(function () {
  var PIXEL = '2506529536509128';
  if (window.fbq) return;
  !function (f, b, e, v, n, t, s) {
    if (f.fbq) return; n = f.fbq = function () { n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments); };
    if (!f._fbq) f._fbq = n; n.push = n; n.loaded = !0; n.version = '2.0'; n.queue = [];
    t = b.createElement(e); t.async = !0; t.src = v; s = b.getElementsByTagName(e)[0]; s.parentNode.insertBefore(t, s);
  }(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');
  fbq('init', PIXEL);
  fbq('track', 'PageView');
  var ficha = location.pathname.match(/^\/tablas\/([a-z-]+)\/$/);
  if (ficha) fbq('track', 'ViewContent', { content_ids: [ficha[1]], content_type: 'product', currency: 'MXN' });
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a');
    if (a && (a.getAttribute('href') || '').indexOf('wa.me/') !== -1) fbq('track', 'Contact');
  });
})();
