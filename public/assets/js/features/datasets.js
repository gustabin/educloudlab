/*
 * Datasets section of the workspace detail page: upload CSV (raw), poll processing, preview, ingest to bronze, delete.
 * API data is inserted only through .text()/.attr() on built elements.
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

  function formatBytes(n) {
    if (!n) { return '—'; }
    var units = ['B', 'KB', 'MB', 'GB'];
    var i = Math.min(units.length - 1, Math.floor(Math.log(n) / Math.log(1024)));
    return (n / Math.pow(1024, i)).toFixed(i ? 1 : 0) + ' ' + units[i];
  }

  function formatInt(n) {
    return n === null || n === undefined ? '—' : Number(n).toLocaleString('es');
  }

  $(function () {
    var $section = $('#ds-section');
    var $detail = $('#ws-detail');
    if (!$section.length || !$detail.length) { return; }
    var workspaceId = String($detail.data('workspaceId'));
    var canDelete = String($detail.data('canDelete')) === '1';
    var canCreate = $('#ds-upload-form').length > 0;
    var $list = $('#ds-list');
    var pollTimer = null;
    var polls = 0;

    function iconButton(icon, label, attrs, variant) {
      var $b = $('<button type="button" class="btn btn-sm"></button>').addClass(variant || 'btn-outline-secondary')
        .attr('aria-label', label).attr('title', label);
      $.each(attrs, function (k, v) { $b.attr(k, v); });
      return $b.append($('<i aria-hidden="true"></i>').addClass('fa-solid ' + icon));
    }

    function row(ds) {
      var $tr = $('<tr></tr>');
      var $name = $('<td></td>').appendTo($tr);
      $('<span class="fw-semibold"></span>').text(ds.table || ds.name).appendTo($name);
      if (ds.version && ds.version.original_name) {
        $('<div class="small text-body-secondary"></div>').text(ds.version.original_name).appendTo($name);
      }
      if (ds.error) {
        $('<div class="ec-ds-error"></div>').text(ds.error.message).appendTo($name);
      }
      $('<td></td>').append($('<span class="ec-layer"></span>').addClass('ec-layer-' + ds.layer).text(ds.layer)).appendTo($tr);
      $('<td class="text-end"></td>').text(formatInt(ds.version && ds.version.row_count)).appendTo($tr);
      $('<td class="text-end"></td>').text(formatInt(ds.version && ds.version.column_count)).appendTo($tr);
      $('<td class="text-end"></td>').text(ds.layer === 'raw' ? formatBytes(ds.version && ds.version.bytes) : '—').appendTo($tr);
      var statusText = ds.status === 'provisioning' ? t('ds.status.provisioning') : t('status.' + ds.status);
      var $status = $('<span class="ec-status"></span>').addClass('ec-status-' + ds.status).text(statusText);
      if (ds.status === 'provisioning' || ds.status === 'deleting') {
        $status.prepend('<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>');
      }
      $('<td></td>').append($status).appendTo($tr);

      var $actions = $('<td class="text-end text-nowrap"></td>').appendTo($tr);
      var label = ds.table || ds.name;
      if (ds.status === 'active') {
        iconButton('fa-table', t('ds.preview_named', { name: label }), { 'data-ec-preview': ds.id, 'data-label': label }).appendTo($actions);
        if (ds.layer === 'raw' && canCreate) {
          $actions.append(' ');
          iconButton('fa-right-to-bracket', t('ds.ingest_named', { name: ds.name }), { 'data-ec-ingest': ds.id, 'data-name': ds.name }, 'btn-outline-primary').appendTo($actions);
        }
      }
      if (canDelete && (ds.status === 'active' || ds.status === 'failed')) {
        $actions.append(' ');
        iconButton('fa-trash', t('ds.delete_named', { name: label }), { 'data-ec-delete-dataset': ds.id, 'data-name': ds.name }, 'btn-outline-danger').appendTo($actions);
      }
      return $tr;
    }

    function schedulePoll(items) {
      clearTimeout(pollTimer);
      var busy = items.some(function (d) { return d.status === 'provisioning' || d.status === 'deleting'; });
      if (busy && polls < 90) {
        polls++;
        pollTimer = setTimeout(function () { load(true); }, 2000);
      } else {
        polls = 0;
      }
    }

    function load(quiet) {
      if (!quiet) { $list.attr('aria-busy', 'true'); }
      api.request({ url: '/api/v1/workspaces/' + encodeURIComponent(workspaceId) + '/datasets', silent: true })
        .then(function (body) {
          $list.empty();
          if (!body.data.length) {
            $list.append($($('#ds-empty-template').html()));
          } else {
            var $table = $('<div class="table-responsive"><table class="table align-middle"><thead><tr></tr></thead><tbody></tbody></table></div>');
            $.each(['ds.col.name', 'ds.col.layer', 'ds.col.rows', 'ds.col.columns', 'ds.col.size', 'ds.col.status', 'ds.col.actions'], function (i, key) {
              $('<th scope="col"></th>').text(t(key)).toggleClass('text-end', i >= 2 && i <= 4 || i === 6).appendTo($table.find('thead tr'));
            });
            $.each(body.data, function (_, ds) { $table.find('tbody').append(row(ds)); });
            $list.append($table);
          }
          schedulePoll(body.data);
        }, function () {
          $list.empty().append($($('#ds-error-template').html()));
        })
        .always(function () { $list.attr('aria-busy', 'false'); });
    }

    $list.on('click', '[data-ec-retry]', function () { load(false); });

    // Upload --------------------------------------------------------------------------------------
    $('#ds-upload-form').on('submit', function (event) {
      event.preventDefault();
      var $form = $(this);
      var file = $('#ds-file')[0].files[0];
      var local = [];
      if (!$.trim($('#ds-name').val())) { local.push({ field: 'name', code: 'required', message: s.required }); }
      if (!file) { local.push({ field: 'file', code: 'required', message: t('ds.select_file') }); }
      api.showFieldErrors($form, local);
      if (local.length) { $form.find('.is-invalid').first().trigger('focus'); return; }

      var data = new window.FormData();
      data.append('name', $.trim($('#ds-name').val()));
      data.append('file', file);
      api.upload({ url: '/api/v1/workspaces/' + encodeURIComponent(workspaceId) + '/datasets', formData: data, button: $form.find('[type=submit]') })
        .then(function () {
          $form.trigger('reset');
          api.announce(t('ds.uploaded'));
          load(true);
        }, function (err) {
          if (err.code === 'DUPLICATE_SUBMIT') { return; }
          if (err.status === 422 && err.details && err.details.some(function (d) { return d.field; })) {
            api.showFieldErrors($form, err.details);
            return;
          }
          api.showError(err);
        });
    });

    // Preview -------------------------------------------------------------------------------------
    $list.on('click', '[data-ec-preview]', function () {
      var $btn = $(this);
      var id = String($btn.data('ecPreview'));
      api.request({ url: '/api/v1/datasets/' + encodeURIComponent(id) + '/preview', button: $btn }).then(function (body) {
        var $body = $('#ds-preview-body').empty();
        $('#ds-preview-title').text(t('ds.preview_named', { name: String($btn.attr('data-label')) }));
        if (!body.data.rows.length) {
          $('<p class="mb-0"></p>').text(t('ds.preview_empty')).appendTo($body);
        } else {
          $('<p class="small text-body-secondary"></p>').text(t('ds.preview_note', { n: body.data.rows.length })).appendTo($body);
          var $table = $('<div class="table-responsive"><table class="table table-sm table-striped ec-preview-table"><thead><tr></tr></thead><tbody></tbody></table></div>');
          $.each(body.data.columns, function (_, c) { $('<th scope="col"></th>').text(c).appendTo($table.find('thead tr')); });
          $.each(body.data.rows, function (_, r) {
            var $tr = $('<tr></tr>');
            $.each(r, function (_, v) {
              var $td = $('<td></td>');
              if (v === null) { $td.addClass('text-body-secondary fst-italic').text('NULL'); } else { $td.text(String(v)).attr('title', String(v)); }
              $tr.append($td);
            });
            $table.find('tbody').append($tr);
          });
          $body.append($table);
        }
        window.bootstrap.Modal.getOrCreateInstance(document.getElementById('ds-preview-modal')).show();
      });
    });

    // Ingest --------------------------------------------------------------------------------------
    $list.on('click', '[data-ec-ingest]', function () {
      var $btn = $(this);
      var id = String($btn.data('ecIngest'));
      window.Swal.fire({
        icon: 'question',
        titleText: t('ds.ingest_title'),
        text: t('ds.ingest_text'),
        input: 'text',
        inputLabel: t('ds.ingest_label'),
        inputValue: String($btn.attr('data-name')),
        inputAttributes: { autocomplete: 'off', maxlength: '63', 'aria-label': t('ds.ingest_label') },
        showCancelButton: true,
        confirmButtonText: t('ds.ingest'),
        cancelButtonText: t('common.cancel'),
        preConfirm: function (value) {
          if (!/^[a-z][a-z0-9_]{0,62}$/.test(value)) {
            window.Swal.showValidationMessage(t('ds.ingest_invalid'));
            return false;
          }
          return api.request({ method: 'POST', url: '/api/v1/datasets/' + encodeURIComponent(id) + '/ingest', data: { table_name: value }, silent: true })
            .then(function (body) { return body; }, function (err) {
              // showValidationMessage renders HTML: always pass escaped text.
              window.Swal.showValidationMessage($('<div></div>').text(err.message || '').html());
              return false;
            });
        }
      }).then(function (r) {
        if (r.isConfirmed) { api.announce(r.value && r.value.message ? r.value.message : ''); load(true); }
      });
    });

    // Delete --------------------------------------------------------------------------------------
    $list.on('click', '[data-ec-delete-dataset]', function () {
      var $btn = $(this);
      var id = String($btn.data('ecDeleteDataset'));
      var name = String($btn.attr('data-name'));
      window.Swal.fire({
        icon: 'warning',
        titleText: t('ds.delete_title'),
        text: t('ds.delete_text'),
        input: 'text',
        inputLabel: t('confirm.type_name', { name: name }),
        inputAttributes: { autocomplete: 'off', 'aria-label': t('confirm.type_name', { name: name }) },
        showCancelButton: true,
        confirmButtonText: t('common.delete'),
        cancelButtonText: t('common.cancel'),
        confirmButtonColor: '#b91c1c',
        preConfirm: function (value) {
          if (value !== name) { window.Swal.showValidationMessage(t('confirm.mismatch')); return false; }
          return true;
        }
      }).then(function (r) {
        if (!r.isConfirmed) { return; }
        api.request({ method: 'DELETE', url: '/api/v1/datasets/' + encodeURIComponent(id), button: $btn }).then(function () { load(true); });
      });
    });

    load(false);
  });
})(window, jQuery);
