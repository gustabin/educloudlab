/*
 * Workspace explorer (/app/workspaces) and workspace detail with resources (/app/workspaces/{id}).
 * All API data is inserted with .text()/.attr() on built elements — never as HTML.
 */
(function (window, $) {
  'use strict';

  var api = window.EduCloud.api;
  var s = {};
  try { s = JSON.parse($('#ec-i18n').text() || '{}'); } catch (e) { s = {}; }

  function t(key, params) {
    var text = s[key] || key;
    $.each(params || {}, function (k, v) { text = text.replace(':' + k, String(v)); });
    return text;
  }

  function fromTemplate(id) {
    return $($('#' + id).html());
  }

  function formatDate(iso) {
    if (!iso) { return ''; }
    try {
      return new Date(iso).toLocaleString('es', { dateStyle: 'medium', timeStyle: 'short' });
    } catch (e) { return iso; }
  }

  function statusBadge(status) {
    return $('<span class="ec-status"></span>').addClass('ec-status-' + status).text(t('status.' + status));
  }

  function formData($form) {
    var data = {};
    $form.find('input[name], textarea[name], select[name]').each(function () {
      if (this.type === 'radio' && !this.checked) { return; }
      data[this.name] = $(this).val();
    });
    return data;
  }

  function submitForm($form, options) {
    var $button = $form.find('[type="submit"]').first();
    api.showFieldErrors($form, []);
    return api.request($.extend({ button: $button, silent: true }, options)).fail(function (err) {
      if (err.code === 'DUPLICATE_SUBMIT') { return; }
      if (err.status === 422 && err.details && err.details.some(function (d) { return d.field; })) {
        api.showFieldErrors($form, err.details);
        $form.find('.is-invalid').first().trigger('focus');
        return;
      }
      api.showError(err);
    });
  }

  function confirmByName(name, title, text) {
    return window.Swal.fire({
      icon: 'warning',
      titleText: title,
      text: text,
      input: 'text',
      inputLabel: t('confirm.type_name', { name: name }),
      inputAttributes: { autocomplete: 'off', 'aria-label': t('confirm.type_name', { name: name }) },
      showCancelButton: true,
      confirmButtonText: t('common.delete'),
      cancelButtonText: t('common.cancel'),
      confirmButtonColor: '#b91c1c',
      preConfirm: function (value) {
        if (value !== name) {
          window.Swal.showValidationMessage(t('confirm.mismatch'));
          return false;
        }
        return true;
      }
    }).then(function (r) { return r.isConfirmed === true; });
  }

  /* ---------------------------------------------------------------- workspace list */
  function initList() {
    var $list = $('#ws-list');
    if (!$list.length) { return; }
    var state = { page: 1, q: '', sort: 'updated_at', dir: 'desc' };
    var timer = null;

    function renderPagination(meta) {
      var $p = $('#ws-pagination').empty();
      if (!meta || meta.total_pages <= 1) { return; }
      for (var i = 1; i <= meta.total_pages; i++) {
        var $li = $('<li class="page-item"></li>').toggleClass('active', i === meta.page);
        $('<button type="button" class="page-link"></button>').text(i).attr('data-page', i)
          .attr('aria-current', i === meta.page ? 'page' : null).appendTo($li);
        $p.append($li);
      }
    }

    function card(ws) {
      var $col = $('<div class="col-12 col-md-6 col-xl-4"></div>');
      var $card = $('<article class="ec-card h-100"></article>').appendTo($col);
      var $h = $('<h2 class="h6 mb-1"></h2>').appendTo($card);
      $('<a class="stretched-link text-decoration-none"></a>').attr('href', api.url('/app/workspaces/' + encodeURIComponent(ws.id))).text(ws.name).appendTo($h);
      $('<p class="small text-body-secondary mb-2 ec-clamp"></p>').text(ws.description || t('ws.no_description')).appendTo($card);
      var $meta = $('<div class="d-flex flex-wrap gap-2 small text-body-secondary"></div>').appendTo($card);
      $('<span class="badge text-bg-light border"></span>').text(t('ws.resources_count', { n: ws.resource_count })).appendTo($meta);
      $('<span></span>').text(t('ws.owner') + ' ' + ws.owner.display_name).appendTo($meta);
      $('<span></span>').text(t('ws.updated') + ' ' + formatDate(ws.updated_at)).appendTo($meta);
      return $col;
    }

    function load() {
      $list.attr('aria-busy', 'true');
      var qs = $.param({ page: state.page, per_page: 12, q: state.q, sort: state.sort, dir: state.dir });
      api.request({ url: '/api/v1/workspaces?' + qs, silent: true })
        .then(function (body) {
          $list.empty();
          if (!body.data.length) {
            $list.append(fromTemplate(state.q ? 'ws-noresults-template' : 'ws-empty-template'));
          } else {
            var $row = $('<div class="row g-3"></div>');
            $.each(body.data, function (_, ws) { $row.append(card(ws)); });
            $list.append($row);
          }
          renderPagination(body.meta);
        }, function () {
          $list.empty().append(fromTemplate('ws-error-template'));
        })
        .always(function () { $list.attr('aria-busy', 'false'); });
    }

    $('#ws-search').on('input', function () {
      clearTimeout(timer);
      var value = $.trim($(this).val());
      timer = setTimeout(function () { state.q = value; state.page = 1; load(); }, 300);
    });
    $('#ws-sort').on('change', function () {
      var parts = String($(this).val()).split(':');
      state.sort = parts[0]; state.dir = parts[1]; state.page = 1; load();
    });
    $('#ws-pagination').on('click', '[data-page]', function () { state.page = Number($(this).data('page')); load(); });
    $list.on('click', '[data-ec-retry]', load);

    $('#ws-create-form').on('submit', function (event) {
      event.preventDefault();
      var $form = $(this);
      var data = formData($form);
      if (!data.description) { delete data.description; }
      submitForm($form, { method: 'POST', url: '/api/v1/workspaces', data: data }).then(function (body) {
        window.location.assign(api.url('/app/workspaces/' + encodeURIComponent(body.data.id)));
      });
    });
    $('#ws-create-modal').on('shown.bs.modal', function () { $('#ws-name').trigger('focus'); });

    load();
  }

  /* ---------------------------------------------------------------- workspace detail */
  function initDetail() {
    var $detail = $('#ws-detail');
    if (!$detail.length) { return; }
    var workspaceId = String($detail.data('workspaceId'));
    var workspaceName = String($detail.data('workspaceName'));
    var $list = $('#res-list');

    function configSummary(res) {
      var parts = [];
      $.each(res.config || {}, function (k, v) { parts.push(k + ': ' + (typeof v === 'boolean' ? (v ? t('common.yes') : t('common.no')) : v)); });
      return parts.join(' · ');
    }

    function row(res) {
      var $tr = $('<tr></tr>');
      var $name = $('<td></td>').appendTo($tr);
      $('<i class="fa-solid fa-fw me-1 text-body-secondary" aria-hidden="true"></i>')
        .addClass(res.type === 'lakehouse' ? 'fa-warehouse' : (res.type === 'storage' ? 'fa-box-archive' : 'fa-cube')).appendTo($name);
      $('<span class="fw-semibold"></span>').text(res.name).appendTo($name);
      $('<td></td>').text(t('res.type.' + res.type)).appendTo($tr);
      $('<td></td>').append(statusBadge(res.status)).appendTo($tr);
      $('<td></td>').text(res.region).appendTo($tr);
      $('<td class="small text-body-secondary"></td>').text(configSummary(res)).appendTo($tr);
      var $actions = $('<td class="text-end"></td>').appendTo($tr);
      if (String($detail.data('canDelete')) === '1') {
        $('<button type="button" class="btn btn-sm btn-outline-danger"></button>')
          .attr('data-ec-delete-resource', res.id).attr('data-name', res.name)
          .attr('aria-label', t('res.delete_named', { name: res.name }))
          .append('<i class="fa-solid fa-trash" aria-hidden="true"></i>').appendTo($actions);
      }
      return $tr;
    }

    function load() {
      $list.attr('aria-busy', 'true');
      api.request({ url: '/api/v1/workspaces/' + encodeURIComponent(workspaceId) + '/resources', silent: true })
        .then(function (body) {
          $list.empty();
          // Datasets are listed (and managed) in their own section.
          var items = $.grep(body.data, function (r) { return r.type !== 'dataset'; });
          if (!items.length) {
            $list.append(fromTemplate('res-empty-template'));
            return;
          }
          var $table = $('<div class="table-responsive"><table class="table align-middle"><thead><tr></tr></thead><tbody></tbody></table></div>');
          $.each(['res.col.name', 'res.col.type', 'res.col.status', 'res.col.region', 'res.col.config', 'res.col.actions'], function (_, key) {
            $('<th scope="col"></th>').text(t(key)).toggleClass('text-end', key === 'res.col.actions').appendTo($table.find('thead tr'));
          });
          $.each(items, function (_, res) { $table.find('tbody').append(row(res)); });
          $list.append($table);
        }, function () {
          $list.empty().append(fromTemplate('res-error-template'));
        })
        .always(function () { $list.attr('aria-busy', 'false'); });
    }

    $list.on('click', '[data-ec-retry]', load);

    $list.on('click', '[data-ec-delete-resource]', function () {
      var $btn = $(this);
      var id = String($btn.data('ecDeleteResource'));
      var name = String($btn.attr('data-name'));
      confirmByName(name, t('res.delete_title'), t('res.delete_text')).then(function (ok) {
        if (!ok) { return; }
        api.request({ method: 'DELETE', url: '/api/v1/resources/' + encodeURIComponent(id), button: $btn }).then(function () {
          api.announce(t('res.deleted'));
          load();
        });
      });
    });

    $('[data-ec-delete-workspace]').on('click', function () {
      var $btn = $(this);
      confirmByName(workspaceName, t('ws.delete_title'), t('ws.delete_text')).then(function (ok) {
        if (!ok) { return; }
        api.request({ method: 'DELETE', url: '/api/v1/workspaces/' + encodeURIComponent(workspaceId), button: $btn }).then(function () {
          window.location.assign(api.url('/app/workspaces'));
        });
      });
    });

    $('#ws-edit-form').on('submit', function (event) {
      event.preventDefault();
      var $form = $(this);
      var data = formData($form);
      if (data.description === '') { data.description = null; }
      submitForm($form, { method: 'PATCH', url: '/api/v1/workspaces/' + encodeURIComponent(workspaceId), data: data }).then(function (body) {
        workspaceName = body.data.name;
        $detail.attr('data-workspace-name', body.data.name);
        $('#ws-title').text(body.data.name);
        $('.breadcrumb-item.active').text(body.data.name);
        $('#ws-description-text').text(body.data.description || t('ws.no_description'));
        window.bootstrap.Modal.getOrCreateInstance(document.getElementById('ws-edit-modal')).hide();
        api.announce(body.message || '');
      });
    });

    var $resForm = $('#res-create-form');
    $resForm.on('change', 'input[name="type"]', function () {
      var type = $(this).val();
      $resForm.find('[data-ec-config]').each(function () { this.hidden = $(this).data('ecConfig') !== type; });
    });
    $resForm.on('submit', function (event) {
      event.preventDefault();
      var data = formData($resForm);
      var config = {};
      $resForm.find('[data-ec-config="' + data.type + '"] [data-config-key]').each(function () {
        config[$(this).data('configKey')] = $(this).is('[data-config-bool]') ? this.checked : $(this).val();
      });
      data.config = config;
      submitForm($resForm, { method: 'POST', url: '/api/v1/workspaces/' + encodeURIComponent(workspaceId) + '/resources', data: data })
        .then(function (body) {
          window.bootstrap.Modal.getOrCreateInstance(document.getElementById('res-create-modal')).hide();
          $resForm.trigger('reset').find('input[name="type"]:checked').trigger('change');
          api.announce(body.message || '');
          load();
        });
    });
    $('#res-create-modal').on('shown.bs.modal', function () { $('#res-name').trigger('focus'); });

    load();
  }

  $(function () {
    initList();
    initDetail();
  });
})(window, jQuery);
