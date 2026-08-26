(function () {
  var measurementId = 'G-4BMCS7P6DQ';
  window.dataLayer = window.dataLayer || [];
  window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
  window.gtag('js', new Date());
  window.gtag('config', measurementId);

  var script = document.createElement('script');
  script.async = true;
  script.src = 'https://www.googletagmanager.com/gtag/js?id=' + measurementId;
  document.head.appendChild(script);

  document.addEventListener('click', function (event) {
    var link = event.target.closest('a');
    if (!link) return;
    var href = link.getAttribute('href') || '';
    if (href.indexOf('wa.me/') !== -1) {
      window.gtag('event', 'click_whatsapp', { link_url: link.href });
    } else if (/^\/\?tabla=/.test(href)) {
      window.gtag('event', 'begin_checkout', { link_url: link.href });
    } else if (/^\/tablas\/[a-z-]+\/$/.test(href)) {
      window.gtag('event', 'view_product', { link_url: link.href });
    } else if (href.indexOf('/eventos/#solicitud') === 0) {
      window.gtag('event', 'request_quote', { link_url: link.href });
    }
  });
})();
