(function () {
  'use strict';

  function initializeWinePages() {
    var body = document.body;
    if (!body || !body.classList.contains('wine-site')) return;

    var page = body.getAttribute('data-wine-page') || 'index';
    var slug = safeKey(body.getAttribute('data-wine-slug'));

    function safeKey(value) {
      return typeof value === 'string' && /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(value) ? value : '';
    }

    function track(name, values) {
      if (typeof window.gtag !== 'function') return;
      var parameters = {
        wine_page: page,
        // Nunca incluir los parámetros de búsqueda en estos eventos.
        page_location: window.location.origin + window.location.pathname
      };
      if (page === 'detail' && slug) parameters.wine_slug = slug;
      Object.keys(values || {}).forEach(function (key) {
        parameters[key] = values[key];
      });
      try {
        window.gtag('event', name, parameters);
      } catch (error) {
        // La navegación y la búsqueda funcionan aunque analytics no esté disponible.
      }
    }

    track('wine_view');

    document.addEventListener('click', function (event) {
      var target = event.target;
      if (!(target instanceof Element)) return;
      var link = target.closest('a[data-wine-table], a[data-wine-quote]');
      if (!link) return;

      if (link.hasAttribute('data-wine-table')) {
        var table = safeKey(link.getAttribute('data-wine-table'));
        track('wine_table_click', table ? { wine_table: table } : {});
      }
      if (link.hasAttribute('data-wine-quote')) track('wine_quote_click');
    });

    var form = document.getElementById('wine-filters');
    var query = document.getElementById('wine-query');
    var type = document.getElementById('wine-type');
    var reset = document.getElementById('wine-reset');
    var count = document.getElementById('wine-count');
    var empty = document.getElementById('wine-empty');
    var cards = Array.prototype.slice.call(document.querySelectorAll('.wine-grid .wine-card'));

    // Las fichas y todos sus enlaces siguen disponibles sin JavaScript.
    // Los controles aparecen cuando la colección hace útil una búsqueda.
    if (!form || !query || !type || !reset || !count || !empty || cards.length < 4) return;

    function normalize(value) {
      return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es-MX').trim();
    }

    var records = cards.map(function (card) {
      return {
        element: card,
        type: normalize(card.getAttribute('data-wine-type')),
        text: normalize([
          card.getAttribute('data-wine-name'),
          card.getAttribute('data-wine-type'),
          card.getAttribute('data-wine-search'),
          card.getAttribute('data-wine-slug')
        ].filter(Boolean).join(' '))
      };
    });
    var options = Array.prototype.slice.call(type.options);
    var allValue = options.length ? options[0].value : '';
    var availableTypes = options.map(function (option) { return option.value; });
    var searchTimer = null;
    var lastReported = '';

    function validatedType(value) {
      return availableTypes.indexOf(value) !== -1 ? value : allValue;
    }

    function restoreFromUrl() {
      var parameters = new URLSearchParams(window.location.search);
      query.value = parameters.get('q') || '';
      type.value = validatedType(parameters.get('tipo'));
    }

    function updateUrl() {
      var url = new URL(window.location.href);
      var value = query.value.trim();
      if (value) url.searchParams.set('q', value);
      else url.searchParams.delete('q');
      if (type.value !== allValue) url.searchParams.set('tipo', type.value);
      else url.searchParams.delete('tipo');

      if (url.href !== window.location.href) {
        try {
          window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
        } catch (error) {
          // Un visor embebido puede impedir cambiar la URL; el filtro sigue activo.
        }
      }
    }

    function applyFilters(writeUrl, report) {
      var normalizedQuery = normalize(query.value);
      var terms = normalizedQuery.split(/\s+/).filter(Boolean);
      var selectedType = normalize(type.value);
      var filterByType = type.value !== allValue;
      var visible = 0;

      records.forEach(function (record) {
        var matchesType = !filterByType || record.type === selectedType;
        var matchesQuery = terms.every(function (term) { return record.text.indexOf(term) !== -1; });
        var matches = matchesType && matchesQuery;
        record.element.hidden = !matches;
        if (matches) visible += 1;
      });

      count.textContent = visible === cards.length && !terms.length && !filterByType
        ? visible + (visible === 1 ? ' vino para descubrir' : ' vinos para descubrir')
        : visible + (visible === 1 ? ' vino encontrado' : ' vinos encontrados') + ' de ' + cards.length;
      empty.hidden = visible !== 0;
      if (writeUrl) updateUrl();

      var signature = normalizedQuery + '\n' + selectedType;
      if (report && signature !== lastReported) {
        // Solo estado de búsqueda y conteos: nunca la consulta escrita por la persona.
        track('wine_filter', {
          search_active: terms.length > 0,
          wine_type: filterByType ? (safeKey(type.value) || 'selected') : 'all',
          result_count: visible
        });
      }
      lastReported = signature;
    }

    function cancelScheduledSearch() {
      if (searchTimer !== null) {
        window.clearTimeout(searchTimer);
        searchTimer = null;
      }
    }

    restoreFromUrl();
    applyFilters(false, false);
    form.hidden = false;

    query.addEventListener('input', function () {
      cancelScheduledSearch();
      searchTimer = window.setTimeout(function () {
        searchTimer = null;
        applyFilters(true, true);
      }, 300);
    });

    type.addEventListener('change', function () {
      cancelScheduledSearch();
      applyFilters(true, true);
    });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      cancelScheduledSearch();
      applyFilters(true, true);
    });

    reset.addEventListener('click', function (event) {
      event.preventDefault();
      cancelScheduledSearch();
      query.value = '';
      type.value = allValue;
      applyFilters(true, true);
      query.focus();
    });

    window.addEventListener('popstate', function () {
      cancelScheduledSearch();
      restoreFromUrl();
      applyFilters(false, false);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeWinePages, { once: true });
  } else {
    initializeWinePages();
  }
})();
