/*
 * Dashboard viewer (M9): renders every widget in one job (POST /dashboards/{id}/render → 202), polls
 * GET /semantic-queries/{id} and draws the results with EduCloud.charts. Filter selectors are filled from the
 * render result (distinct values), keeping the current selection.
 */
(function (window, $) {
  'use strict';

  var api = window.EduCloud.api;
  var charts = window.EduCloud.charts;
  var s = {};
  try { s = JSON.parse($('#ec-i18n').text() || '{}'); } catch (e) { s = {}; }
  function t(key, params) {
    var text = s[key] || key;
    $.each(params || {}, function (k, v) { text = text.split(':' + k).join(String(v)); });
    return text;
  }

  var $root = $('#dashboard');
  if (!$root.length || !charts) { return; }
  var id = String($root.data('dashboardId'));
  var data = {};
  try { data = JSON.parse($('#dashboard-data').text() || '{}'); } catch (e) { data = {}; }
  var definition = data.definition || { widgets: [] };
  var meta = charts.metaFrom(data.model || {});
  var timer = null;

  function values() {
    var body = { filters: {} };
    var from = $('#db-date-from').val();
    var to = $('#db-date-to').val();
    if (from) { body.date_from = String(from); }
    if (to) { body.date_to = String(to); }
    $('[data-filter]').each(function () {
      var v = $(this).val();
      if (v) { body.filters[String($(this).attr('data-filter'))] = [String(v)]; }
    });
    return body;
  }

  function busy() {
    $('#db-widgets .ec-widget-body').attr('aria-busy', 'true');
    $('#db-status').text(t('analytics.loading'));
  }

  function fillFilters(results) {
    $('[data-filter]').each(function () {
      var $sel = $(this);
      var res = results['f_' + $sel.attr('data-filter')];
      if (!res || res.error) { return; }
      var current = String($sel.val() || '');
      $sel.find('option:not(:first)').remove();
      $.each(res.rows, function (_, r) {
        if (r[0] === null) { return; }
        $('<option></option>').val(String(r[0])).text(String(r[0])).appendTo($sel);
      });
      $sel.val(current);
    });
  }

  function show(query) {
    var results = query.results || {};
    $.each(definition.widgets, function (_, w) {
      charts.render($('[data-widget="' + w.id + '"] .ec-widget-body'), w, results['w_' + w.id], meta);
    });
    fillFilters(results);
    $('#db-status').text(t('analytics.updated', { ms: query.duration_ms === null ? '—' : query.duration_ms }));
  }

  function poll(queryId) {
    clearTimeout(timer);
    api.request({ url: '/api/v1/semantic-queries/' + encodeURIComponent(queryId), silent: true }).then(function (body) {
      var q = body.data;
      if (q.status === 'queued' || q.status === 'running') {
        timer = setTimeout(function () { poll(queryId); }, 800);
        return;
      }
      if (q.status === 'succeeded') {
        if (q.result_expired) { $('#db-status').text(t('analytics.expired')); return; }
        show(q);
        return;
      }
      $('#db-widgets .ec-widget-body').attr('aria-busy', 'false').empty();
      $('#db-status').text(q.error ? q.error.message : t('analytics.failed'));
    }, function (err) { $('#db-status').text(err.message); });
  }

  function render() {
    if (String($root.data('canRender')) !== '1') {
      $('#db-widgets .ec-widget-body').attr('aria-busy', 'false').empty();
      return;
    }
    busy();
    api.request({ method: 'POST', url: '/api/v1/dashboards/' + encodeURIComponent(id) + '/render', data: values(), silent: true })
      .then(function (body) { poll(body.data.id); }, function (err) {
        $('#db-widgets .ec-widget-body').attr('aria-busy', 'false').empty();
        $('#db-status').text(err.message);
        if (err.status !== 422) { api.showError(err); }
      });
  }

  $('#db-filters').on('submit', function (event) { event.preventDefault(); render(); });
  render();
})(window, jQuery);
