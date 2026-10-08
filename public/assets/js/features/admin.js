/*
 * Admin monitor: overview cards and paginated tables (jobs, audit, users). Every value is set with .text().
 */
(function (window, $) {
  'use strict';

  var api = window.EduCloud.api;
  var s = {};
  try { s = JSON.parse($('#ec-i18n').text() || '{}'); } catch (e) { s = {}; }
  function t(key, params) {
    var text = s[key] || key;
    $.each(params || {}, function (k, v) { text = text.split(':' + k).join(String(v)); });
    return text;
  }
  if (!$('#admin').length) { return; }

  function date(iso) { return iso ? new Date(iso).toLocaleString('es') : '—'; }
  function mb(bytes) { return (Number(bytes) / 1048576).toLocaleString('es', { maximumFractionDigits: 1 }) + ' MB'; }
  function sum(obj) { var n = 0; $.each(obj || {}, function (_, v) { n += Number(v); }); return n; }

  /* -------------------------------------------------------------- overview */
  function card(title, value, detail) {
    var $c = $('<div class="col-6 col-lg-3"><div class="ec-card h-100"></div></div>');
    var $inner = $c.find('.ec-card');
    $('<div class="small text-body-secondary"></div>').text(title).appendTo($inner);
    $('<div class="h4 mb-0"></div>').text(value).appendTo($inner);
    if (detail) { $('<div class="small text-body-secondary"></div>').text(detail).appendTo($inner); }
    return $c;
  }

  function loadOverview() {
    var $o = $('#admin-overview').attr('aria-busy', 'true');
    api.request({ url: '/api/v1/admin/overview', silent: true }).then(function (body) {
      var d = body.data;
      $o.empty()
        .append(card(t('admin.card.users'), sum(d.users), t('admin.card.users_detail', { active: d.users.active || 0, disabled: d.users.disabled || 0 })))
        .append(card(t('admin.card.queue'), d.queue.queued, t('admin.card.queue_detail', { running: d.queue.running, failed: (d.jobs_24h.failed || 0) + (d.jobs_24h.timed_out || 0) })))
        .append(card(t('admin.card.storage'), mb(d.storage.total_bytes), t('admin.card.storage_detail', { orgs: d.tenants.organization || 0 })))
        .append(card(t('admin.card.labs'), d.labs.attempts_in_progress, t('admin.card.labs_detail', { completed: d.labs.attempts_completed, denied: d.denied_24h })));
    }, function (err) {
      $o.empty().append($('<div class="col-12"></div>').append($('<div class="alert alert-danger mb-0" role="alert"></div>').text(err.message)));
    }).always(function () { $o.attr('aria-busy', 'false'); });
  }

  /* -------------------------------------------------------------- tables */
  var lists = {
    jobs: {
      url: '/api/v1/admin/jobs',
      columns: ['admin.col.type', 'admin.col.status', 'admin.col.tenant', 'admin.col.user', 'admin.col.error', 'admin.col.queued', 'admin.col.duration'],
      row: function (j) {
        return [j.type, j.status, j.tenant.name, j.user.display_name, j.error ? j.error.code + ': ' + j.error.message : '',
          date(j.queued_at), j.duration_ms === null ? '—' : j.duration_ms + ' ms'];
      }
    },
    audit: {
      url: '/api/v1/admin/audit',
      columns: ['admin.col.when', 'admin.col.action', 'admin.col.outcome', 'admin.col.actor', 'admin.col.tenant', 'admin.col.resource'],
      row: function (a) {
        return [date(a.occurred_at), a.action, a.outcome, a.actor ? a.actor.display_name : '—', a.tenant ? a.tenant.name : '—',
          a.resource ? a.resource.type + (a.resource.id ? ' ' + a.resource.id : '') : '—'];
      }
    },
    users: {
      url: '/api/v1/admin/users',
      columns: ['admin.col.name', 'admin.col.email', 'admin.col.status', 'admin.col.last_login', 'admin.col.storage', 'admin.col.actions'],
      row: function (u) {
        var action = '';
        if (!u.is_platform_admin && (u.status === 'active' || u.status === 'disabled' || u.status === 'locked')) {
          action = $('<button type="button" class="btn btn-sm"></button>')
            .addClass(u.status === 'disabled' ? 'btn-outline-primary' : 'btn-outline-danger')
            .attr('data-user-status', u.status === 'disabled' ? 'active' : 'disabled').attr('data-user-id', u.id)
            .text(u.status === 'disabled' ? t('admin.enable') : t('admin.disable'));
        }
        return [u.display_name + (u.is_platform_admin ? ' ★' : ''), u.email, u.status, date(u.last_login_at), mb(u.storage_bytes), action];
      }
    }
  };

  function load(name, page) {
    var cfg = lists[name];
    var $box = $('[data-admin-list="' + name + '"]').attr('aria-busy', 'true');
    var query = $('[data-admin-filter="' + name + '"]').serialize();
    api.request({ url: cfg.url + '?' + query + (query ? '&' : '') + 'page=' + (page || 1), silent: true }).then(function (body) {
      $box.empty();
      if (!body.data.length) {
        $('<p class="text-body-secondary small"></p>').text(t('admin.empty')).appendTo($box);
        return;
      }
      var $wrap = $('<div class="table-responsive"></div>').appendTo($box);
      var $table = $('<table class="table table-sm table-hover align-middle small"><thead><tr></tr></thead><tbody></tbody></table>').appendTo($wrap);
      $.each(cfg.columns, function (_, c) { $('<th scope="col"></th>').text(t(c)).appendTo($table.find('thead tr')); });
      $.each(body.data, function (_, item) {
        var $tr = $('<tr></tr>');
        $.each(cfg.row(item), function (_, v) {
          var $td = $('<td></td>');
          if (v && v.jquery) { $td.append(v); } else { $td.text(String(v)); }
          $tr.append($td);
        });
        $table.find('tbody').append($tr);
      });
      var meta = body.meta;
      var pages = Math.ceil(meta.total / meta.per_page);
      var $nav = $('<div class="d-flex justify-content-between align-items-center small"></div>').appendTo($box);
      $('<span class="text-body-secondary"></span>').text(t('admin.page', { page: meta.page, pages: pages, total: meta.total })).appendTo($nav);
      var $btns = $('<div class="btn-group btn-group-sm"></div>').appendTo($nav);
      $('<button type="button" class="btn btn-outline-secondary"></button>').text('‹').attr('aria-label', t('admin.prev'))
        .prop('disabled', meta.page <= 1).on('click', function () { load(name, meta.page - 1); }).appendTo($btns);
      $('<button type="button" class="btn btn-outline-secondary"></button>').text('›').attr('aria-label', t('admin.next'))
        .prop('disabled', meta.page >= pages).on('click', function () { load(name, meta.page + 1); }).appendTo($btns);
    }, function (err) {
      $box.empty();
      $('<div class="alert alert-danger" role="alert"></div>').text(err.message).appendTo($box);
    }).always(function () { $box.attr('aria-busy', 'false'); });
  }

  var timer = null;
  $('[data-admin-filter]').on('change input', function () {
    var name = String($(this).attr('data-admin-filter'));
    clearTimeout(timer);
    timer = setTimeout(function () { load(name, 1); }, 300);
  }).on('submit', function (event) { event.preventDefault(); });

  $('[data-admin-list="users"]').on('click', '[data-user-status]', function () {
    var $btn = $(this);
    var status = String($btn.attr('data-user-status'));
    window.Swal.fire({
      icon: 'warning',
      titleText: status === 'disabled' ? t('admin.disable_title') : t('admin.enable_title'),
      text: status === 'disabled' ? t('admin.disable_text') : '',
      showCancelButton: true,
      confirmButtonText: status === 'disabled' ? t('admin.disable') : t('admin.enable'),
      cancelButtonText: t('common.cancel')
    }).then(function (choice) {
      if (!choice.isConfirmed) { return; }
      api.request({ method: 'PATCH', url: '/api/v1/admin/users/' + encodeURIComponent(String($btn.attr('data-user-id'))), data: { status: status }, button: $btn })
        .then(function () { load('users', 1); loadOverview(); });
    });
  });

  /* -------------------------------------------------------------- observability (M11b) */
  var STATUS_CLASS = { ok: 'text-bg-success', warning: 'text-bg-warning', down: 'text-bg-danger', disabled: 'text-bg-secondary' };
  var obsLoaded = false;
  var obsChart = null;

  function ms(v) { return v === null || v === undefined ? '—' : Number(v).toLocaleString('es') + ' ms'; }
  function pct(v) { return v === null || v === undefined ? '—' : Number(v).toLocaleString('es', { style: 'percent', maximumFractionDigits: 2 }); }
  function badge(status) {
    return $('<span class="badge"></span>').addClass(STATUS_CLASS[status] || 'text-bg-secondary').text(t('obs.status.' + status));
  }

  function componentDetail(c) {
    var parts = [];
    if (c.name === 'database' && c.latency_ms !== undefined) { parts.push(t('obs.latency', { ms: c.latency_ms })); }
    if (c.name === 'queue') {
      parts.push(t('obs.queue_detail', { queued: c.queued, running: c.running }));
      if (c.oldest_queued_s !== null) { parts.push(t('obs.oldest', { s: c.oldest_queued_s })); }
    }
    if (c.last_seen_s !== undefined) { parts.push(c.last_seen_s === null ? t('obs.never_seen') : t('obs.last_seen', { s: c.last_seen_s })); }
    if (c.details && c.details.docker === false) { parts.push(t('obs.docker_off')); }
    if (c.outbox_pending) { parts.push(t('obs.outbox', { n: c.outbox_pending })); }
    if (c.name === 'storage') {
      if (!c.writable) { parts.push(t('obs.not_writable')); }
      if (c.free_bytes !== null) { parts.push(t('obs.free', { gb: (c.free_bytes / 1073741824).toLocaleString('es', { maximumFractionDigits: 1 }) })); }
    }
    if (c.name === 'runner' && c.status === 'down') { parts.push(t('obs.runner_missing')); }
    return parts.join(' · ');
  }

  function loadHealth() {
    var $h = $('#obs-health').attr('aria-busy', 'true');
    api.request({ url: '/api/v1/admin/health', silent: true }).then(function (body) {
      $h.empty();
      $('#obs-overall').empty().text(t('obs.overall', { status: '' })).append(badge(body.data.status));
      $.each(body.data.components, function (_, c) {
        var $card = $('<div class="ec-card h-100"></div>').attr('data-obs-component', c.name);
        var $head = $('<div class="d-flex justify-content-between align-items-start gap-2"></div>').appendTo($card);
        $('<h3 class="h6 mb-1"></h3>').text(t('obs.component.' + c.name)).appendTo($head);
        $head.append(badge(c.status));
        $('<div class="small text-body-secondary"></div>').text(componentDetail(c)).appendTo($card);
        $('<div class="col-12 col-sm-6 col-lg-3"></div>').append($card).appendTo($h);
      });
    }, function (err) {
      $h.empty().append($('<div class="col-12"></div>').append($('<div class="alert alert-danger mb-0" role="alert"></div>').text(err.message)));
    }).always(function () { $h.attr('aria-busy', 'false'); });
  }

  function simpleTable(columns, rows, caption) {
    var $table = $('<table class="table table-sm table-hover align-middle small mb-0"><thead><tr></tr></thead><tbody></tbody></table>');
    if (caption) { $('<caption class="visually-hidden"></caption>').text(caption).prependTo($table); }
    $.each(columns, function (_, c) { $('<th scope="col"></th>').text(t(c)).appendTo($table.find('thead tr')); });
    $.each(rows, function (_, cells) {
      var $tr = $('<tr></tr>');
      $.each(cells, function (_, v) { $('<td></td>').text(String(v)).appendTo($tr); });
      $table.find('tbody').append($tr);
    });
    // Scrollable on narrow screens: focusable and labelled so keyboard users can scroll it (WCAG 2.1.1).
    return $('<div class="table-responsive" tabindex="0" role="region"></div>').attr('aria-label', caption || '').append($table);
  }

  function routeSection(title, routes) {
    var $s = $('<section class="col-12 col-xl-4"></section>');
    $('<h3 class="h6"></h3>').text(title).appendTo($s);
    if (!routes.length) {
      $('<p class="small text-body-secondary"></p>').text(t('obs.none')).appendTo($s);
      return $s;
    }
    return $s.append(simpleTable(['obs.col.route', 'obs.col.requests', 'obs.col.p95', 'obs.col.errors'], $.map(routes, function (r) {
      return [[r.method + ' ' + r.route, r.requests.toLocaleString('es'), ms(r.p95_ms), r.server_errors]];
    }), title));
  }

  function renderSeries($box, http) {
    var $section = $('<section class="mb-4"></section>').appendTo($box);
    $('<h3 class="h6"></h3>').text(t('obs.chart_title')).appendTo($section);
    var labels = $.map(http.series, function (p) { return new Date(p.at).toLocaleString('es', { dateStyle: 'short', timeStyle: 'short' }); });
    var $wrap = $('<div class="ec-chart"></div>').appendTo($section);
    var canvas = $('<canvas role="img"></canvas>')
      .attr('aria-label', t('obs.chart_summary', { n: http.series.length, m: http.series_slot_minutes })).appendTo($wrap)[0];
    if (obsChart) { obsChart.destroy(); obsChart = null; }
    if (window.Chart) {
      obsChart = new window.Chart(canvas, {
        type: 'line',
        data: {
          labels: labels,
          datasets: [
            { label: t('obs.series.requests'), data: $.map(http.series, function (p) { return p.requests; }), borderColor: '#0f766e', backgroundColor: '#0f766e', tension: 0.2 },
            { label: t('obs.series.errors'), data: $.map(http.series, function (p) { return p.server_errors; }), borderColor: '#be185d', backgroundColor: '#be185d', tension: 0.2 }
          ]
        },
        options: { animation: false, responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
      });
    }
    var $details = $('<details class="mt-2"></details>').appendTo($section);
    $('<summary class="small"></summary>').text(t('obs.show_data')).appendTo($details);
    $details.append(simpleTable(['obs.col.at', 'obs.series.requests', 'obs.series.errors'], $.map(http.series, function (p, i) {
      return [[labels[i], p.requests, p.server_errors]];
    }), t('obs.chart_title')));
  }

  function loadMetrics() {
    var $m = $('#obs-metrics').attr('aria-busy', 'true');
    var windowValue = String($('#obs-window').val() || '24h');
    api.request({ url: '/api/v1/admin/metrics?window=' + encodeURIComponent(windowValue), silent: true }).then(function (body) {
      var http = body.data.http;
      $m.empty();
      var $kpis = $('<div class="row g-3 mb-3"></div>').appendTo($m);
      $kpis.append(card(t('obs.kpi.requests'), http.requests.toLocaleString('es')))
        .append(card(t('obs.kpi.p95'), ms(http.p95_ms), t('obs.kpi.p95_detail', { p50: ms(http.p50_ms), p99: ms(http.p99_ms) })))
        .append(card(t('obs.kpi.server_errors'), http.server_errors, t('obs.rate', { rate: pct(http.server_error_rate) })))
        .append(card(t('obs.kpi.client_errors'), pct(http.client_error_rate)));
      if (!http.requests) {
        $('<p class="small text-body-secondary"></p>').text(t('obs.no_traffic')).appendTo($m);
      } else {
        renderSeries($m, http);
        $('<div class="row g-3 mb-2"></div>').appendTo($m)
          .append(routeSection(t('obs.slowest'), http.slowest_routes))
          .append(routeSection(t('obs.busiest'), http.busiest_routes))
          .append(routeSection(t('obs.failing'), http.failing_routes));
        $('<p class="small text-body-secondary"></p>').text(t('obs.approx')).appendTo($m);
      }
      $('<h3 class="h6 mt-3"></h3>').text(t('obs.jobs')).appendTo($m);
      if (!body.data.jobs.length) {
        $('<p class="small text-body-secondary"></p>').text(t('obs.no_jobs')).appendTo($m);
      } else {
        $m.append(simpleTable(
          ['obs.col.type', 'obs.col.count', 'obs.col.active', 'obs.col.failure_rate', 'obs.col.wait', 'obs.col.run'],
          $.map(body.data.jobs, function (j) {
            return [[j.type, j.count, j.active, pct(j.failure_rate), ms(j.wait_p50_ms) + ' / ' + ms(j.wait_p95_ms), ms(j.run_p50_ms) + ' / ' + ms(j.run_p95_ms)]];
          }),
          t('obs.jobs')
        ));
      }
    }, function (err) {
      $m.empty().append($('<div class="alert alert-danger" role="alert"></div>').text(err.message));
    }).always(function () { $m.attr('aria-busy', 'false'); });
  }

  function loadLogs() {
    var $l = $('#obs-logs').attr('aria-busy', 'true');
    var rid = $.trim(String($('#obs-request-id').val() || '')).toUpperCase();
    $('#obs-request-id').removeClass('is-invalid').removeAttr('aria-invalid');
    if (rid && !/^[0-9A-HJKMNP-TV-Z]{26}$/.test(rid)) {
      $('#obs-request-id').addClass('is-invalid').attr('aria-invalid', 'true');
      $l.empty().append($('<div class="alert alert-danger py-2 small" role="alert"></div>').text(t('obs.bad_request_id'))).attr('aria-busy', 'false');
      return;
    }
    api.request({ url: '/api/v1/admin/logs' + (rid ? '?request_id=' + encodeURIComponent(rid) : ''), silent: true }).then(function (body) {
      $l.empty();
      if (!body.data.length) {
        $('<p class="small text-body-secondary"></p>').text(t('obs.no_logs')).appendTo($l);
        return;
      }
      $l.append(simpleTable(['obs.col.ts', 'obs.col.level', 'obs.col.event', 'obs.col.request_id'], $.map(body.data, function (e) {
        return [[date(e.ts), e.level, e.event, e.request_id || '—']];
      }), t('obs.logs')));
    }, function (err) {
      $l.empty().append($('<div class="alert alert-danger" role="alert"></div>').text(err.message));
    }).always(function () { $l.attr('aria-busy', 'false'); });
  }

  function loadObservability() { obsLoaded = true; loadHealth(); loadMetrics(); loadLogs(); }

  $('#admin-obs-tab').on('shown.bs.tab', function () { if (!obsLoaded) { loadObservability(); } });
  $('#obs-window').on('change', loadMetrics);
  $('#obs-logs-form').on('submit', function (event) { event.preventDefault(); loadLogs(); });

  $('#admin-refresh').on('click', function () {
    loadOverview(); load('jobs', 1); load('audit', 1); load('users', 1);
    if (obsLoaded) { loadObservability(); }
  });
  loadOverview();
  load('jobs', 1);
  load('audit', 1);
  load('users', 1);
})(window, jQuery);
