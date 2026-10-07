/*
 * Shared widget rendering for semantic query results (M9): KPI cards, Chart.js bar/line charts and tables.
 * Every chart has role="img" with a summary label plus a "Ver datos" table (accessible alternative).
 * All values are rendered with .text(); Chart.js draws on a canvas (no HTML from data).
 */
(function (window, $) {
  'use strict';

  var s = {};
  try { s = JSON.parse($('#ec-i18n').text() || '{}'); } catch (e) { s = {}; }
  function t(key, params) {
    var text = s[key] || key;
    $.each(params || {}, function (k, v) { text = text.split(':' + k).join(String(v)); });
    return text;
  }

  // Original palette (AA contrast against white for the first colours used by single-series charts).
  var COLORS = ['#0f766e', '#4338ca', '#b45309', '#be185d', '#0369a1', '#4d7c0f'];

  function format(value, fmt) {
    if (value === null || value === undefined) { return '—'; }
    if (typeof value !== 'number') { return String(value); }
    switch (fmt) {
      case 'currency': return value.toLocaleString('es', { style: 'currency', currency: 'EUR', maximumFractionDigits: 2 });
      case 'percent': return value.toLocaleString('es', { style: 'percent', maximumFractionDigits: 1 });
      case 'integer': return Math.round(value).toLocaleString('es');
      default: return value.toLocaleString('es', { maximumFractionDigits: 2 });
    }
  }

  /** meta: { measures: {name: {label, format}}, dimensions: {name: {label}} } */
  function label(meta, name) {
    return (meta.measures[name] && meta.measures[name].label) || (meta.dimensions[name] && meta.dimensions[name].label) || name;
  }

  function table(result, meta, caption) {
    var $table = $('<table class="table table-sm small mb-0"></table>');
    if (caption) { $('<caption class="visually-hidden"></caption>').text(caption).appendTo($table); }
    var $head = $('<tr></tr>').appendTo($('<thead></thead>').appendTo($table));
    $.each(result.columns, function (_, c) {
      $('<th scope="col"></th>').addClass(c.role === 'measure' ? 'text-end' : '').text(label(meta, c.name)).appendTo($head);
    });
    var $body = $('<tbody></tbody>').appendTo($table);
    $.each(result.rows, function (_, row) {
      var $tr = $('<tr></tr>').appendTo($body);
      $.each(row, function (i, v) {
        var col = result.columns[i];
        var fmt = col.role === 'measure' && meta.measures[col.name] ? meta.measures[col.name].format : null;
        $(i === 0 && col.role !== 'measure' ? '<th scope="row" class="fw-normal"></th>' : '<td></td>')
          .addClass(col.role === 'measure' ? 'text-end' : '')
          .text(col.role === 'measure' ? format(v, fmt) : (v === null ? t('analytics.no_value') : String(v)))
          .appendTo($tr);
      });
    });
    return $('<div class="table-responsive"></div>').append($table);
  }

  function render($body, widget, result, meta) {
    $body.empty().attr('aria-busy', 'false');
    if (!result) {
      $('<p class="small text-body-secondary mb-0"></p>').text(t('analytics.no_result')).appendTo($body);
      return;
    }
    if (result.error) {
      $('<p class="small text-danger mb-0"></p>').text(result.error.message).appendTo($body);
      return;
    }
    if (!result.rows.length) {
      $('<p class="small text-body-secondary mb-0"></p>').text(t('analytics.no_rows')).appendTo($body);
      return;
    }
    if (widget.type === 'kpi') {
      $.each(result.columns, function (i, c) {
        var fmt = meta.measures[c.name] ? meta.measures[c.name].format : null;
        var $kpi = $('<div class="ec-kpi"></div>').appendTo($body);
        $('<span class="ec-kpi-value"></span>').text(format(result.rows[0][i], fmt)).appendTo($kpi);
        if (result.columns.length > 1) { $('<span class="ec-kpi-label"></span>').text(label(meta, c.name)).appendTo($kpi); }
      });
      return;
    }
    if (widget.type === 'table') {
      $body.append(table(result, meta, widget.title));
      if (result.truncated) { $('<p class="small text-body-secondary mb-0 mt-1"></p>').text(t('analytics.truncated')).appendTo($body); }
      return;
    }
    // bar / line: first column is the dimension, the rest are measures.
    var labels = $.map(result.rows, function (r) { return r[0] === null ? t('analytics.no_value') : String(r[0]); });
    var datasets = [];
    for (var i = 1; i < result.columns.length; i++) {
      datasets.push({
        label: label(meta, result.columns[i].name),
        data: $.map(result.rows, function (r) { return r[i] === null ? 0 : r[i]; }),
        backgroundColor: COLORS[(i - 1) % COLORS.length],
        borderColor: COLORS[(i - 1) % COLORS.length],
        tension: 0.2
      });
    }
    var summary = t('analytics.chart_summary', {
      title: widget.title, type: t('analytics.widget.' + widget.type), n: result.rows.length,
      dimension: label(meta, result.columns[0].name)
    });
    var $wrap = $('<div class="ec-chart"></div>').appendTo($body);
    var canvas = $('<canvas role="img"></canvas>').attr('aria-label', summary).appendTo($wrap)[0];
    if (window.Chart) {
      // eslint-disable-next-line no-new
      new window.Chart(canvas, {
        type: widget.type,
        data: { labels: labels, datasets: datasets },
        options: {
          animation: false, responsive: true, maintainAspectRatio: false,
          plugins: { legend: { display: datasets.length > 1 }, tooltip: { callbacks: {
            label: function (ctx) {
              var name = result.columns[ctx.datasetIndex + 1].name;
              return ctx.dataset.label + ': ' + format(ctx.parsed.y, meta.measures[name] ? meta.measures[name].format : null);
            }
          } } },
          scales: { y: { beginAtZero: true } }
        }
      });
    }
    var $details = $('<details class="mt-2"></details>').appendTo($body);
    $('<summary class="small"></summary>').text(t('analytics.show_data')).appendTo($details);
    $details.append(table(result, meta, widget.title));
  }

  /** Builds the label/format lookup from a dashboard's model summary (or a model definition). */
  function metaFrom(model) {
    var meta = { measures: {}, dimensions: {} };
    $.each(model.measures || [], function (_, m) { meta.measures[m.name] = { label: m.label || m.name, format: m.format || null }; });
    $.each(model.dimensions || [], function (_, d) { meta.dimensions[d.name] = { label: d.label || d.name }; });
    return meta;
  }

  window.EduCloud = window.EduCloud || {};
  window.EduCloud.charts = { render: render, metaFrom: metaFrom, format: format };
})(window, jQuery);
