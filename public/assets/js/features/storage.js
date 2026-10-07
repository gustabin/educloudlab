/*
 * Object storage browser: containers (create/delete), lifecycle policy, objects (upload, metadata, tier, download,
 * delete) with a key-prefix filter. Values are rendered with .text(); downloads go through the API (attachment).
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

  var $root = $('#storage');
  if (!$root.length) { return; }
  var resourceId = String($root.data('resourceId'));
  var canEdit = String($root.data('canEdit')) === '1';
  var current = null;
  var filterTimer = null;

  function kb(bytes) { return (Number(bytes) / 1024).toLocaleString('es', { maximumFractionDigits: 1 }) + ' KB'; }

  function loadContainers(select) {
    var $box = $('#st-containers').attr('aria-busy', 'true');
    return api.request({ url: '/api/v1/resources/' + encodeURIComponent(resourceId) + '/containers', silent: true }).then(function (body) {
      $box.empty();
      if (!body.data.length) { $('<p class="small text-body-secondary mb-0"></p>').text(t('storage.no_containers')).appendTo($box); }
      var $g = $('<div class="list-group list-group-flush"></div>').appendTo($box);
      $.each(body.data, function (_, c) {
        var $b = $('<button type="button" class="list-group-item list-group-item-action px-2"></button>').attr('data-container', c.id).appendTo($g);
        $('<span class="d-block font-monospace"></span>').text(c.name).appendTo($b);
        $('<span class="small text-body-secondary"></span>').text(t('storage.objects_count', { n: c.object_count }) + ' · ' + kb(c.total_bytes)).appendTo($b);
        if (current && current.id === c.id) { $b.addClass('active').attr('aria-current', 'true'); }
      });
      if (select) { openContainer(select); }
    }, function (err) {
      $box.empty();
      $('<div class="small text-danger"></div>').text(err.message).appendTo($box);
    }).always(function () { $box.attr('aria-busy', 'false'); });
  }

  function openContainer(id) {
    var prefix = String($('#st-prefix').val() || '');
    api.request({ url: '/api/v1/containers/' + encodeURIComponent(id) + (prefix ? '?prefix=' + encodeURIComponent(prefix) : ''), silent: true }).then(function (body) {
      current = body.data;
      $('#st-empty').addClass('d-none');
      $('#st-container').removeClass('d-none');
      $('#st-container-title').text(current.name);
      var lc = current.lifecycle || {};
      var parts = [t('storage.objects_count', { n: current.object_count }), kb(current.total_bytes)];
      parts.push(lc.archive_after_days || lc.delete_after_days
        ? t('storage.lifecycle_summary', { archive: lc.archive_after_days || '—', del: lc.delete_after_days || '—' })
        : t('storage.no_lifecycle'));
      $('#st-container-summary').text(parts.join(' · '));
      $('#st-archive-days').val(lc.archive_after_days || '');
      $('#st-delete-days').val(lc.delete_after_days || '');
      $('#st-containers [data-container]').removeClass('active').removeAttr('aria-current')
        .filter('[data-container="' + id + '"]').addClass('active').attr('aria-current', 'true');
      renderObjects(current.objects);
    });
  }
  $('#st-containers').on('click', '[data-container]', function () { $('#st-prefix').val(''); openContainer(String($(this).attr('data-container'))); });

  function renderObjects(objects) {
    var $box = $('#st-objects').empty();
    if (!objects.length) {
      $('<p class="small text-body-secondary mb-0"></p>').text(t($('#st-prefix').val() ? 'storage.no_objects' : 'storage.empty_container')).appendTo($box);
      return;
    }
    var $wrap = $('<div class="table-responsive"></div>').appendTo($box);
    var $table = $('<table class="table table-sm align-middle small"><thead><tr></tr></thead><tbody></tbody></table>').appendTo($wrap);
    $.each(['storage.col.key', 'storage.col.size', 'storage.col.tier', 'storage.col.metadata', 'storage.col.actions'], function (_, c) {
      $('<th scope="col"></th>').text(t(c)).appendTo($table.find('thead tr'));
    });
    $.each(objects, function (_, o) {
      var $tr = $('<tr></tr>').appendTo($table.find('tbody'));
      $('<td class="font-monospace"></td>').text(o.key).appendTo($tr);
      $('<td class="text-nowrap"></td>').text(kb(o.bytes)).appendTo($tr);
      var $tier = $('<td></td>').appendTo($tr);
      if (canEdit) {
        var $sel = $('<select class="form-select form-select-sm"></select>').attr('data-tier', o.id).attr('aria-label', t('storage.tier_of', { key: o.key }));
        $.each(['hot', 'cool', 'archive'], function (_, tier) { $('<option></option>').val(tier).text(tier).prop('selected', tier === o.tier).appendTo($sel); });
        $tier.append($sel);
      } else {
        $tier.text(o.tier);
      }
      var meta = $.map(o.metadata || {}, function (v, k) { return k + '=' + v; }).join(', ');
      $('<td class="small"></td>').text(meta || '—').appendTo($tr);
      var $actions = $('<td class="text-end text-nowrap"></td>').appendTo($tr);
      $('<button type="button" class="btn btn-sm btn-outline-secondary"></button>').attr('data-download', o.id).attr('data-name', o.key)
        .attr('aria-label', t('storage.download_named', { key: o.key })).prop('disabled', o.tier === 'archive')
        .attr('title', o.tier === 'archive' ? t('storage.archived_hint') : t('storage.download_named', { key: o.key }))
        .append('<i class="fa-solid fa-download" aria-hidden="true"></i>').appendTo($actions);
      if (canEdit) {
        $actions.append(' ');
        $('<button type="button" class="btn btn-sm btn-outline-danger"></button>').attr('data-delete-object', o.id).attr('data-name', o.key)
          .attr('aria-label', t('storage.delete_named', { key: o.key })).append('<i class="fa-solid fa-trash" aria-hidden="true"></i>').appendTo($actions);
      }
    });
  }

  $('#st-prefix').on('input', function () {
    clearTimeout(filterTimer);
    filterTimer = setTimeout(function () { if (current) { openContainer(current.id); } }, 300);
  });

  $('#st-container-form').on('submit', function (event) {
    event.preventDefault();
    var $form = $(this);
    api.showFieldErrors($form, []);
    api.request({ method: 'POST', url: '/api/v1/resources/' + encodeURIComponent(resourceId) + '/containers',
      data: { name: $.trim(String($('#st-container-name').val() || '')) }, button: $form.find('[type=submit]'), silent: true })
      .then(function (body) { $form.trigger('reset'); loadContainers(body.data.id); }, function (err) {
        if (err.status === 422) { api.showFieldErrors($form, err.details); } else { api.showError(err); }
      });
  });

  $('#st-lifecycle-form').on('submit', function (event) {
    event.preventDefault();
    if (!current) { return; }
    var lc = {};
    var a = parseInt(String($('#st-archive-days').val() || ''), 10);
    var d = parseInt(String($('#st-delete-days').val() || ''), 10);
    if (a > 0) { lc.archive_after_days = a; }
    if (d > 0) { lc.delete_after_days = d; }
    api.request({ method: 'PATCH', url: '/api/v1/containers/' + encodeURIComponent(current.id),
      data: { lifecycle: $.isEmptyObject(lc) ? null : lc }, button: $(this).find('[type=submit]') })
      .then(function (body) { api.announce(body.message); openContainer(current.id); });
  });

  $('#st-upload-form').on('submit', function (event) {
    event.preventDefault();
    if (!current) { return; }
    var $form = $(this);
    var file = $('#st-file')[0].files[0];
    var key = $.trim(String($('#st-key').val() || '')) || (file ? file.name : '');
    var fd = new window.FormData();
    fd.append('key', key);
    fd.append('tier', String($('#st-tier').val()));
    var meta = $.trim(String($('#st-metadata').val() || ''));
    if (meta) { fd.append('metadata', meta); }
    if (file) { fd.append('file', file); }
    api.showFieldErrors($form, []);
    api.upload({ url: '/api/v1/containers/' + encodeURIComponent(current.id) + '/objects', formData: fd, button: $form.find('[type=submit]') })
      .then(function (body) { $form.trigger('reset'); api.announce(body.message); loadContainers(current.id); }, function (err) {
        if (err.status === 422) { api.showFieldErrors($form, err.details); } else { api.showError(err); }
      });
  });

  $('#st-objects').on('change', '[data-tier]', function () {
    var $sel = $(this);
    api.request({ method: 'PATCH', url: '/api/v1/objects/' + encodeURIComponent(String($sel.attr('data-tier'))), data: { tier: String($sel.val()) } })
      .then(function () { openContainer(current.id); });
  });

  $('#st-objects').on('click', '[data-download]', function () {
    // Same-origin navigation to the API: the server answers with Content-Disposition: attachment.
    window.location.href = api.url('/api/v1/objects/' + encodeURIComponent(String($(this).attr('data-download'))) + '/download');
  });

  $('#st-objects').on('click', '[data-delete-object]', function () {
    var $btn = $(this);
    window.Swal.fire({
      icon: 'warning', titleText: t('storage.delete_named', { key: String($btn.attr('data-name')) }), showCancelButton: true,
      confirmButtonText: t('common.delete'), cancelButtonText: t('common.cancel'), confirmButtonColor: '#b91c1c'
    }).then(function (choice) {
      if (!choice.isConfirmed) { return; }
      api.request({ method: 'DELETE', url: '/api/v1/objects/' + encodeURIComponent(String($btn.attr('data-delete-object'))), button: $btn })
        .then(function () { loadContainers(current.id); });
    });
  });

  $('#st-container-delete').on('click', function () {
    if (!current) { return; }
    api.request({ method: 'DELETE', url: '/api/v1/containers/' + encodeURIComponent(current.id), button: $(this) }).then(function () {
      current = null;
      $('#st-container').addClass('d-none');
      $('#st-empty').removeClass('d-none');
      loadContainers();
    });
  });

  loadContainers();
})(window, jQuery);
