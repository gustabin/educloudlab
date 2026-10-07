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

  $('#admin-refresh').on('click', function () { loadOverview(); load('jobs', 1); load('audit', 1); load('users', 1); });
  loadOverview();
  load('jobs', 1);
  load('audit', 1);
  load('users', 1);
})(window, jQuery);
