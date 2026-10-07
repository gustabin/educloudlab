/*
 * Analytics page (M9): semantic models and dashboards of a workspace. One JSON editor (CodeMirror, JSON mode) serves
 * both kinds: templates, server-side validation, save, delete; models can be explored with a quick query
 * (202 + polling) and dashboards open in their viewer. All data is rendered with .text(); definitions are parsed
 * with JSON.parse and never evaluated.
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

  var $root = $('#analytics');
  if (!$root.length || !window.CodeMirror) { return; }
  var ws = String($root.data('workspaceId'));
  var canEdit = String($root.data('canEdit')) === '1';
  var kind = null;      // 'model' | 'dashboard'
  var current = null;   // saved object being edited (null = new)
  var models = [];
  var timer = null;

  var editor = window.CodeMirror.fromTextArea(document.getElementById('an-definition'), {
    mode: { name: 'javascript', json: true }, lineNumbers: true, matchBrackets: true, tabSize: 2, readOnly: canEdit ? false : 'nocursor'
  });
  $(editor.getInputField()).attr('aria-labelledby', 'an-definition-label').attr('aria-describedby', 'an-definition-help');

  var TEMPLATES = {
    model: {
      star: {
        description: 'Ventas de la tienda: hechos por línea de pedido con tienda y fecha',
        fact: 'gold.fact_sales',
        relationships: [
          { table: 'gold.dim_store', fact_column: 'store_key', column: 'store_key' },
          { table: 'gold.dim_date', fact_column: 'date_key', column: 'date_key' }
        ],
        measures: [
          { name: 'ingresos', label: 'Ingresos', agg: 'sum', column: 'line_total', format: 'currency' },
          { name: 'pedidos', label: 'Pedidos', agg: 'count_distinct', column: 'order_id', format: 'integer' },
          { name: 'ticket_medio', label: 'Ticket medio', ratio: ['ingresos', 'pedidos'], format: 'currency' }
        ],
        dimensions: [
          { name: 'region', label: 'Región', table: 'gold.dim_store', column: 'region' },
          { name: 'mes', label: 'Mes', table: 'gold.dim_date', column: 'full_date', grain: 'month' }
        ]
      },
      single: {
        fact: 'gold.revenue_by_region',
        measures: [{ name: 'ingresos', label: 'Ingresos', agg: 'sum', column: 'revenue', format: 'currency' }],
        dimensions: [{ name: 'region', label: 'Región', column: 'region' }]
      }
    },
    dashboard: {
      sales: {
        date_filter: { dimension: 'mes' },
        filters: [{ dimension: 'region' }],
        widgets: [
          { id: 'ingresos', type: 'kpi', title: 'Ingresos', measures: ['ingresos'] },
          { id: 'pedidos', type: 'kpi', title: 'Pedidos', measures: ['pedidos'] },
          { id: 'por_region', type: 'bar', title: 'Ingresos por región', measures: ['ingresos'], dimension: 'region',
            order: { by: 'ingresos', dir: 'desc' } },
          { id: 'por_mes', type: 'line', title: 'Ingresos por mes', measures: ['ingresos'], dimension: 'mes' }
        ]
      }
    }
  };

  /* ------------------------------------------------------------------ lists */
  function renderList($box, items, type) {
    $box.empty().attr('aria-busy', 'false');
    if (!items.length) {
      $('<p class="small text-body-secondary mb-0"></p>').text(t(type === 'model' ? 'analytics.no_models' : 'analytics.no_dashboards')).appendTo($box);
      return;
    }
    var $g = $('<div class="list-group list-group-flush"></div>').appendTo($box);
    $.each(items, function (_, item) {
      var $b = $('<button type="button" class="list-group-item list-group-item-action px-2"></button>')
        .attr('data-an-open', type).attr('data-id', item.id).appendTo($g);
      $('<span class="d-block"></span>').text(item.name).appendTo($b);
      $('<span class="small text-body-secondary"></span>').text(type === 'model'
        ? t('analytics.model_summary', { m: item.definition.measures.length, d: (item.definition.dimensions || []).length })
        : t('analytics.dashboard_summary', { w: item.definition.widgets.length, model: item.model.name })).appendTo($b);
      if (current && kind === type && current.id === item.id) { $b.addClass('active').attr('aria-current', 'true'); }
    });
  }

  function load() {
    var base = '/api/v1/workspaces/' + encodeURIComponent(ws);
    return $.when(
      api.request({ url: base + '/semantic-models', silent: true }),
      api.request({ url: base + '/dashboards', silent: true })
    ).then(function (m, d) {
      models = m.data;
      renderList($('#an-models'), m.data, 'model');
      renderList($('#an-dashboards'), d.data, 'dashboard');
      var $sel = $('#an-model').empty();
      $.each(models, function (_, model) { $('<option></option>').val(model.id).text(model.name).appendTo($sel); });
    }, function (err) {
      $('#an-models, #an-dashboards').attr('aria-busy', 'false').empty();
      $('<div class="small text-danger"></div>').text(err.message).appendTo('#an-models');
    });
  }

  /* ------------------------------------------------------------------ editor */
  function showProblems(list) {
    var $p = $('#an-problems').empty().toggleClass('d-none', !list.length);
    if (!list.length) { return; }
    var $ul = $('<ul class="mb-0 ps-3"></ul>').appendTo($p);
    $.each(list, function (_, msg) { $('<li></li>').text(msg).appendTo($ul); });
  }

  function problemsFrom(err) {
    if (err.details && err.details.length) {
      return $.map(err.details, function (d) { return (d.field ? d.field + ': ' : '') + d.message; });
    }
    return [err.message];
  }

  function openEditor(type, item) {
    kind = type;
    current = item;
    clearTimeout(timer);
    $('#an-empty').addClass('d-none');
    $('#an-editor').removeClass('d-none');
    $('#an-editor-title').text(t(type === 'model' ? (item ? 'analytics.edit_model' : 'analytics.new_model') : (item ? 'analytics.edit_dashboard' : 'analytics.new_dashboard')));
    $('#an-definition-help').text(t(type === 'model' ? 'analytics.model_help' : 'analytics.dashboard_help'));
    $('#an-model-field').toggleClass('d-none', type !== 'dashboard');
    $('#an-validate').toggleClass('d-none', type !== 'model');
    $('#an-open').toggleClass('d-none', !(type === 'dashboard' && item))
      .attr('href', item && type === 'dashboard' ? api.url('/app/dashboards/' + encodeURIComponent(item.id)) : '#');
    $('#an-delete').toggleClass('d-none', !item);
    var $tpl = $('#an-template').empty();
    $('<option value=""></option>').text(t('analytics.template')).appendTo($tpl);
    $.each(TEMPLATES[type], function (key) { $('<option></option>').val(key).text(t('analytics.template.' + key)).appendTo($tpl); });
    $('#an-name').val(item ? item.name : '');
    if (type === 'dashboard') { $('#an-model').val(item ? item.model.id : (models[0] ? models[0].id : '')); }
    var first = TEMPLATES[type][Object.keys(TEMPLATES[type])[0]];
    editor.setValue(JSON.stringify(item ? item.definition : first, null, 2));
    showProblems([]);
    $('[data-an-open]').removeClass('active').removeAttr('aria-current');
    if (item) { $('[data-an-open="' + type + '"][data-id="' + item.id + '"]').addClass('active').attr('aria-current', 'true'); }
    setupExplore();
  }

  function parsed() {
    try { return { ok: true, value: JSON.parse(editor.getValue()) }; } catch (e) { return { ok: false }; }
  }

  $root.on('click', '[data-an-new]', function () {
    var type = String($(this).attr('data-an-new'));
    if (type === 'dashboard' && !models.length) { api.showError({ message: t('analytics.model_first') }); return; }
    openEditor(type, null);
    $('#an-name').trigger('focus');
  });

  $root.on('click', '[data-an-open]', function () {
    var type = String($(this).attr('data-an-open'));
    var url = type === 'model' ? '/api/v1/semantic-models/' : '/api/v1/dashboards/';
    api.request({ url: url + encodeURIComponent(String($(this).attr('data-id'))), silent: true })
      .then(function (body) { openEditor(type, body.data); }, function (err) { api.showError(err); });
  });

  $('#an-template').on('change', function () {
    var key = String($(this).val());
    if (TEMPLATES[kind] && TEMPLATES[kind][key]) { editor.setValue(JSON.stringify(TEMPLATES[kind][key], null, 2)); }
    $(this).val('');
  });

  $('#an-validate').on('click', function () {
    var p = parsed();
    if (!p.ok) { showProblems([t('analytics.invalid_json')]); return; }
    api.request({ method: 'POST', url: '/api/v1/workspaces/' + encodeURIComponent(ws) + '/semantic-models/validate',
      data: { definition: p.value }, button: $(this), silent: true })
      .then(function (body) { showProblems([]); api.announce(body.message); window.Swal.fire({ icon: 'success', titleText: body.message }); },
        function (err) { showProblems(problemsFrom(err)); });
  });

  $('#an-save').on('click', function () {
    var p = parsed();
    if (!p.ok) { showProblems([t('analytics.invalid_json')]); return; }
    var data = { name: $.trim(String($('#an-name').val() || '')), definition: p.value };
    if (kind === 'dashboard') { data.model_id = String($('#an-model').val() || ''); }
    var path = kind === 'model' ? 'semantic-models' : 'dashboards';
    var req = current
      ? { method: 'PATCH', url: '/api/v1/' + path + '/' + encodeURIComponent(current.id) }
      : { method: 'POST', url: '/api/v1/workspaces/' + encodeURIComponent(ws) + '/' + path };
    var type = kind;
    api.request($.extend(req, { data: data, button: $(this), silent: true })).then(function (body) {
      api.announce(body.message);
      load().then(function () { openEditor(type, body.data); });
    }, function (err) { showProblems(problemsFrom(err)); });
  });

  $('#an-delete').on('click', function () {
    if (!current) { return; }
    var path = kind === 'model' ? '/api/v1/semantic-models/' : '/api/v1/dashboards/';
    window.Swal.fire({
      icon: 'warning', titleText: t('analytics.delete_title', { name: current.name }), showCancelButton: true,
      confirmButtonText: t('common.delete'), cancelButtonText: t('common.cancel'), confirmButtonColor: '#b91c1c'
    }).then(function (choice) {
      if (!choice.isConfirmed) { return; }
      api.request({ method: 'DELETE', url: path + encodeURIComponent(current.id) }).then(function () {
        current = null;
        $('#an-editor, #an-explore').addClass('d-none');
        $('#an-empty').removeClass('d-none');
        load();
      });
    });
  });

  /* ------------------------------------------------------------------ explore (saved models only) */
  function setupExplore() {
    var show = kind === 'model' && current !== null;
    $('#an-explore').toggleClass('d-none', !show);
    $('#an-explore-result').empty();
    $('#an-explore-status').text('');
    if (!show) { return; }
    var def = current.definition;
    var $m = $('#an-explore-measures').empty();
    $.each(def.measures, function (i, m) {
      var idAttr = 'an-m-' + i;
      var $wrap = $('<div class="form-check form-check-inline me-0"></div>').appendTo($m);
      $('<input class="form-check-input" type="checkbox">').attr('id', idAttr).val(m.name).prop('checked', i === 0).appendTo($wrap);
      $('<label class="form-check-label small"></label>').attr('for', idAttr).text(m.label || m.name).appendTo($wrap);
    });
    var $d = $('#an-explore-dimension').empty();
    $('<option value=""></option>').text(t('analytics.no_dimension')).appendTo($d);
    $.each(def.dimensions || [], function (_, d) { $('<option></option>').val(d.name).text(d.label || d.name).appendTo($d); });
  }

  $('#an-explore-form').on('submit', function (event) {
    event.preventDefault();
    if (!current) { return; }
    var measures = $('#an-explore-measures input:checked').map(function () { return String($(this).val()); }).get();
    var dimension = String($('#an-explore-dimension').val() || '');
    var body = { measures: measures, limit: 100 };
    if (dimension) { body.dimensions = [dimension]; }
    var widget = { type: !measures.length ? 'table' : (dimension ? 'bar' : 'kpi'), title: t('analytics.explore') };
    $('#an-explore-status').text(t('analytics.loading'));
    $('#an-explore-result').empty();
    api.request({ method: 'POST', url: '/api/v1/semantic-models/' + encodeURIComponent(current.id) + '/query', data: body,
      button: $(this).find('[type=submit]'), silent: true }).then(function (res) { poll(res.data.id, widget); }, function (err) {
      $('#an-explore-status').text('');
      showProblems(problemsFrom(err));
    });
  });

  function poll(queryId, widget) {
    clearTimeout(timer);
    api.request({ url: '/api/v1/semantic-queries/' + encodeURIComponent(queryId), silent: true }).then(function (body) {
      var q = body.data;
      if (q.status === 'queued' || q.status === 'running') { timer = setTimeout(function () { poll(queryId, widget); }, 800); return; }
      if (q.status !== 'succeeded') { $('#an-explore-status').text(q.error ? q.error.message : t('analytics.failed')); return; }
      $('#an-explore-status').text(t('analytics.updated', { ms: q.duration_ms === null ? '—' : q.duration_ms }));
      var $box = $('<div class="ec-widget-body"></div>').appendTo($('#an-explore-result').empty());
      charts.render($box, widget, (q.results || {}).q, charts.metaFrom(current.definition));
    }, function (err) { $('#an-explore-status').text(err.message); });
  }

  load();
})(window, jQuery);
