/*
 * Pipelines page: list, JSON editor (CodeMirror, JSON mode) with templates and server-side validation, step preview,
 * save/run/delete and run history with per-step status (polling while queued/running, cancel).
 * All data is rendered with .text(); definitions are parsed with JSON.parse and never evaluated.
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

  var $root = $('#pipelines');
  if (!$root.length || !window.CodeMirror) { return; }
  var ws = String($root.data('workspaceId'));
  var canEdit = String($root.data('canEdit')) === '1';
  var current = null;
  var timer = null;

  var editor = window.CodeMirror.fromTextArea(document.getElementById('pl-definition'), {
    mode: { name: 'javascript', json: true }, lineNumbers: true, matchBrackets: true, tabSize: 2, readOnly: canEdit ? false : 'nocursor'
  });
  $(editor.getInputField()).attr('aria-labelledby', 'pl-definition-label').attr('aria-describedby', 'pl-definition-help');

  var TEMPLATES = {
    clean: { nodes: [
      { id: 'leer', type: 'source', table: 'bronze.customers' },
      { id: 'con_email', type: 'filter', conditions: [{ column: 'email', op: 'not_null' }] },
      { id: 'normalizar', type: 'select', columns: [{ column: 'customer_id' }, { column: 'email', transform: 'lower' }, { column: 'city' }] },
      { id: 'calidad', type: 'quality_check', rules: [{ rule: 'not_null', column: 'email' }], on_fail: 'stop' },
      { id: 'guardar', type: 'output', layer: 'silver', table: 'customers_clean' }
    ] },
    aggregate: { nodes: [
      { id: 'pedidos', type: 'source', table: 'bronze.orders' },
      { id: 'completados', type: 'filter', conditions: [{ column: 'status', op: 'eq', value: 'completed' }] },
      { id: 'con_tienda', type: 'join', table: 'bronze.stores', on: [{ left: 'store_id', right: 'store_id' }], columns: ['region'] },
      { id: 'por_region', type: 'aggregate', group_by: ['region'], measures: [{ fn: 'count', column: 'order_id', as: 'pedidos' }] },
      { id: 'guardar', type: 'output', layer: 'gold', table: 'pedidos_por_region' }
    ] },
    raw: { nodes: [
      { id: 'archivo', type: 'source', raw: 'customers' },
      { id: 'guardar', type: 'output', layer: 'silver', table: 'customers_raw_copy' }
    ] }
  };

  function definition() {
    try {
      return { ok: true, value: JSON.parse(editor.getValue()) };
    } catch (e) {
      return { ok: false, message: t('pipelines.invalid_json') };
    }
  }

  function showProblems(list) {
    var $p = $('#pl-problems').empty().toggleClass('d-none', !list.length);
    if (!list.length) { return; }
    var $ul = $('<ul class="mb-0 ps-3"></ul>').appendTo($p);
    $.each(list, function (_, item) { $('<li></li>').text(item).appendTo($ul); });
  }

  function renderSteps() {
    var $ol = $('#pl-steps').empty();
    var parsed = definition();
    if (!parsed.ok || !parsed.value || !$.isArray(parsed.value.nodes)) { return; }
    $.each(parsed.value.nodes, function (_, node) {
      var $li = $('<li></li>').appendTo($ol);
      $('<span class="ec-node-type"></span>').text(String(node.type || '?')).appendTo($li);
      $('<span class="ms-2 font-monospace small"></span>').text(String(node.id || '')).appendTo($li);
      var detail = node.table || node.raw || (node.layer ? node.layer + '.' + node.table : '');
      if (node.type === 'output') { detail = node.layer + '.' + node.table; }
      if (detail) { $('<span class="ms-2 small text-body-secondary"></span>').text(String(detail)).appendTo($li); }
    });
  }
  editor.on('change', function () { clearTimeout(timer); timer = setTimeout(renderSteps, 300); });

  /* ------------------------------------------------------------------ list */
  function loadList(selectId) {
    var $list = $('#pl-list').attr('aria-busy', 'true');
    return api.request({ url: '/api/v1/workspaces/' + encodeURIComponent(ws) + '/pipelines', silent: true }).then(function (body) {
      $list.empty();
      if (!body.data.length) {
        $('<p class="small text-body-secondary mb-0"></p>').text(t('pipelines.none')).appendTo($list);
      }
      var $group = $('<div class="list-group list-group-flush"></div>').appendTo($list);
      $.each(body.data, function (_, p) {
        var $b = $('<button type="button" class="list-group-item list-group-item-action px-2"></button>').attr('data-pipeline', p.id).appendTo($group);
        $('<span class="d-block"></span>').text(p.name).appendTo($b);
        if (p.last_run_status) {
          $('<span></span>').addClass('ec-run ec-run-' + p.last_run_status).text(t('pipelines.state.' + p.last_run_status)).appendTo($b);
        }
        if (current && current.id === p.id) { $b.addClass('active').attr('aria-current', 'true'); }
      });
      if (selectId) { open(selectId); }
      $('#pl-empty').toggleClass('d-none', body.data.length > 0 || current !== null);
    }, function (err) {
      $list.empty();
      $('<div class="small text-danger"></div>').text(err.message).appendTo($list);
    }).always(function () { $list.attr('aria-busy', 'false'); });
  }

  function open(id) {
    api.request({ url: '/api/v1/pipelines/' + encodeURIComponent(id), silent: true }).then(function (body) {
      current = body.data;
      $('#pl-editor, #pl-runs-card').removeClass('d-none');
      $('#pl-empty').addClass('d-none');
      $('#pl-name').val(current.name);
      editor.setValue(JSON.stringify(current.definition, null, 2));
      showProblems([]);
      renderSteps();
      $('#pl-list [data-pipeline]').removeClass('active').removeAttr('aria-current')
        .filter('[data-pipeline="' + id + '"]').addClass('active').attr('aria-current', 'true');
      loadRuns();
    });
  }
  $('#pl-list').on('click', '[data-pipeline]', function () { open(String($(this).attr('data-pipeline'))); });

  /* ------------------------------------------------------------------ editor actions */
  function problemsFrom(err) {
    if (err.details && err.details.length) {
      return $.map(err.details, function (d) { return (d.field ? d.field + ': ' : '') + d.message; });
    }
    return [err.message];
  }

  $('#pl-new').on('click', function () {
    current = null;
    $('#pl-editor').removeClass('d-none');
    $('#pl-runs-card').addClass('d-none');
    $('#pl-empty').addClass('d-none');
    $('#pl-name').val('').trigger('focus');
    editor.setValue(JSON.stringify(TEMPLATES.clean, null, 2));
    showProblems([]);
    renderSteps();
  });

  $('#pl-template').on('change', function () {
    var key = String($(this).val());
    if (TEMPLATES[key]) { editor.setValue(JSON.stringify(TEMPLATES[key], null, 2)); }
    $(this).val('');
  });

  $('#pl-validate').on('click', function () {
    var parsed = definition();
    if (!parsed.ok) { showProblems([parsed.message]); return; }
    api.request({ method: 'POST', url: '/api/v1/pipeline-definitions/validate', data: { definition: parsed.value }, button: $(this), silent: true })
      .then(function (body) { showProblems([]); api.announce(body.message); window.Swal.fire({ icon: 'success', titleText: body.message }); },
        function (err) { showProblems(problemsFrom(err)); });
  });

  $('#pl-save').on('click', function () {
    var parsed = definition();
    if (!parsed.ok) { showProblems([parsed.message]); return; }
    var data = { name: $.trim(String($('#pl-name').val() || '')), definition: parsed.value };
    var req = current
      ? { method: 'PATCH', url: '/api/v1/pipelines/' + encodeURIComponent(current.id) }
      : { method: 'POST', url: '/api/v1/workspaces/' + encodeURIComponent(ws) + '/pipelines' };
    api.request($.extend(req, { data: data, button: $(this), silent: true })).then(function (body) {
      showProblems([]);
      current = body.data;
      api.announce(body.message);
      loadList(current.id);
    }, function (err) { showProblems(problemsFrom(err)); });
  });

  $('#pl-run').on('click', function () {
    if (!current) { showProblems([t('pipelines.save_first')]); return; }
    api.request({ method: 'POST', url: '/api/v1/pipelines/' + encodeURIComponent(current.id) + '/runs', data: {}, button: $(this), silent: true })
      .then(function () { showProblems([]); loadRuns(); }, function (err) { showProblems(problemsFrom(err)); });
  });

  $('#pl-delete').on('click', function () {
    if (!current) { return; }
    window.Swal.fire({
      icon: 'warning', titleText: t('pipelines.delete_title'), text: t('pipelines.delete_text'), showCancelButton: true,
      confirmButtonText: t('common.delete'), cancelButtonText: t('common.cancel'), confirmButtonColor: '#b91c1c'
    }).then(function (choice) {
      if (!choice.isConfirmed) { return; }
      api.request({ method: 'DELETE', url: '/api/v1/pipelines/' + encodeURIComponent(current.id) }).then(function () {
        current = null;
        $('#pl-editor, #pl-runs-card').addClass('d-none');
        loadList();
      });
    });
  });

  /* ------------------------------------------------------------------ runs */
  var runTimer = null;
  function loadRuns() {
    if (!current) { return; }
    clearTimeout(runTimer);
    api.request({ url: '/api/v1/pipelines/' + encodeURIComponent(current.id) + '/runs', silent: true }).then(function (body) {
      var $box = $('#pl-runs').empty();
      if (!body.data.length) {
        $('<p class="small text-body-secondary mb-0"></p>').text(t('pipelines.no_runs')).appendTo($box);
        $('#pl-run-status').text('');
        return;
      }
      var active = false;
      $.each(body.data, function (i, run) {
        var open = i === 0;
        var $d = $('<details class="ec-run-item"></details>').prop('open', open).appendTo($box);
        var $sum = $('<summary></summary>').appendTo($d);
        $('<span></span>').addClass('ec-run ec-run-' + run.status).text(t('pipelines.state.' + run.status)).appendTo($sum);
        $('<span class="ms-2 small"></span>').text(new Date(run.created_at).toLocaleString('es') + ' · v' + run.definition_version + (run.output ? ' → ' + run.output : '')).appendTo($sum);
        if (run.error) { $('<p class="small text-danger mb-1 mt-1"></p>').text(run.error.code + ': ' + run.error.message).appendTo($d); }
        if (run.steps && run.steps.length) {
          var $table = $('<table class="table table-sm small mb-1"><thead><tr></tr></thead><tbody></tbody></table>').appendTo($d);
          $.each(['pipelines.col.step', 'pipelines.col.type', 'pipelines.col.status', 'pipelines.col.rows', 'pipelines.col.ms', 'pipelines.col.message'], function (_, c) {
            $('<th scope="col"></th>').text(t(c)).appendTo($table.find('thead tr'));
          });
          $.each(run.steps, function (_, st) {
            var $tr = $('<tr></tr>').appendTo($table.find('tbody'));
            $('<td class="font-monospace"></td>').text(st.id).appendTo($tr);
            $('<td></td>').text(st.type).appendTo($tr);
            $('<td></td>').append($('<span></span>').addClass('ec-step ec-step-' + st.status).text(t('pipelines.step.' + st.status))).appendTo($tr);
            $('<td></td>').text(st.rows === null ? '—' : Number(st.rows).toLocaleString('es')).appendTo($tr);
            $('<td></td>').text(st.duration_ms === null ? '—' : st.duration_ms).appendTo($tr);
            $('<td></td>').text(st.message || '').appendTo($tr);
          });
        }
        if ((run.status === 'queued' || run.status === 'running') && canEdit) {
          active = true;
          $('<button type="button" class="btn btn-sm btn-outline-danger"></button>').attr('data-cancel', run.id)
            .prop('disabled', run.cancel_requested).text(run.cancel_requested ? t('pipelines.cancelling') : t('pipelines.cancel')).appendTo($d);
        }
      });
      $('#pl-run-status').text(active ? t('pipelines.running') : '');
      if (active) { runTimer = setTimeout(loadRuns, 2000); } else { loadList(); }
    });
  }
  $('#pl-runs').on('click', '[data-cancel]', function () {
    api.request({ method: 'POST', url: '/api/v1/pipeline-runs/' + encodeURIComponent(String($(this).attr('data-cancel'))) + '/cancel', data: {}, button: $(this) })
      .then(loadRuns);
  });

  loadList();
})(window, jQuery);
