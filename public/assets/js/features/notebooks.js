/*
 * Notebooks page (M8): list, cell editor (CodeMirror Python for code cells, textarea for Markdown text), save,
 * run in the Docker sandbox (202 + polling), per-cell outputs and saved artifacts. Every output is student-produced
 * text: it is rendered with .text() and DOM-built tables only, never as HTML.
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

  var $root = $('#notebooks');
  if (!$root.length || !window.CodeMirror) { return; }
  var ws = String($root.data('workspaceId'));
  var canEdit = String($root.data('canEdit')) === '1';
  var canRun = String($root.data('canRun')) === '1';
  var current = null;   // saved notebook (null = new)
  var cells = [];       // [{id, type, source, editor}]
  var seq = 0;
  var timer = null;

  var TEMPLATE = [
    { type: 'markdown', source: '## Exploración\nConsulta una tabla del lakehouse con DuckDB y analízala con pandas.' },
    { type: 'code', source: 'con = lakehouse()\ncon.sql("SELECT table_schema, table_name FROM information_schema.tables").df()' }
  ];

  function nextId() { seq += 1; return 'c' + Date.now().toString(36) + seq; }

  /* ------------------------------------------------------------------ list */
  function loadList(select) {
    var $list = $('#nb-list').attr('aria-busy', 'true');
    return api.request({ url: '/api/v1/workspaces/' + encodeURIComponent(ws) + '/notebooks', silent: true }).then(function (body) {
      $list.empty();
      if (!body.data.length) { $('<p class="small text-body-secondary mb-0"></p>').text(t('notebooks.none')).appendTo($list); }
      var $g = $('<div class="list-group list-group-flush"></div>').appendTo($list);
      $.each(body.data, function (_, n) {
        var $b = $('<button type="button" class="list-group-item list-group-item-action px-2"></button>').attr('data-notebook', n.id).appendTo($g);
        $('<span class="d-block"></span>').text(n.name).appendTo($b);
        if (n.last_run_status) {
          $('<span></span>').addClass('ec-run ec-run-' + n.last_run_status).text(t('pipelines.state.' + n.last_run_status)).appendTo($b);
        }
        if (current && current.id === n.id) { $b.addClass('active').attr('aria-current', 'true'); }
      });
      if (select) { open(select); }
    }, function (err) {
      $list.empty();
      $('<div class="small text-danger"></div>').text(err.message).appendTo($list);
    }).always(function () { $list.attr('aria-busy', 'false'); });
  }

  /* ------------------------------------------------------------------ cells */
  function renderCells() {
    var $box = $('#nb-cells').empty();
    $.each(cells, function (i, cell) {
      var $li = $('<li class="ec-nb-cell"></li>').attr('data-cell', cell.id).appendTo($box);
      var $head = $('<div class="d-flex justify-content-between align-items-center mb-1"></div>').appendTo($li);
      var label = t(cell.type === 'code' ? 'notebooks.cell_code' : 'notebooks.cell_text', { n: i + 1 });
      $('<span class="small fw-semibold"></span>').attr('id', 'nb-label-' + cell.id).text(label).appendTo($head);
      if (canEdit) {
        var $tools = $('<div class="btn-group btn-group-sm"></div>').attr('role', 'group').attr('aria-label', label).appendTo($head);
        $('<button type="button" class="btn btn-outline-secondary"></button>').attr('data-cell-up', cell.id).prop('disabled', i === 0)
          .attr('aria-label', t('notebooks.move_up', { n: i + 1 })).append('<i class="fa-solid fa-arrow-up" aria-hidden="true"></i>').appendTo($tools);
        $('<button type="button" class="btn btn-outline-secondary"></button>').attr('data-cell-down', cell.id).prop('disabled', i === cells.length - 1)
          .attr('aria-label', t('notebooks.move_down', { n: i + 1 })).append('<i class="fa-solid fa-arrow-down" aria-hidden="true"></i>').appendTo($tools);
        $('<button type="button" class="btn btn-outline-danger"></button>').attr('data-cell-remove', cell.id)
          .attr('aria-label', t('notebooks.remove_cell', { n: i + 1 })).append('<i class="fa-solid fa-xmark" aria-hidden="true"></i>').appendTo($tools);
      }
      var $ta = $('<textarea class="form-control font-monospace" rows="4" spellcheck="false"></textarea>')
        .attr('id', 'nb-src-' + cell.id).attr('aria-labelledby', 'nb-label-' + cell.id).val(cell.source).prop('readonly', !canEdit).appendTo($li);
      if (cell.type === 'code') {
        cell.editor = window.CodeMirror.fromTextArea($ta[0], {
          mode: 'python', lineNumbers: true, matchBrackets: true, indentUnit: 4, viewportMargin: Infinity, readOnly: canEdit ? false : 'nocursor'
        });
        $(cell.editor.getInputField()).attr('aria-labelledby', 'nb-label-' + cell.id);
      } else {
        cell.editor = null;
      }
      $('<div class="ec-nb-output"></div>').attr('data-output', cell.id).appendTo($li);
    });
  }

  function collect() {
    return $.map(cells, function (c) {
      var source = c.editor ? c.editor.getValue() : String($('#nb-src-' + c.id).val() || '');
      c.source = source;
      return { id: c.id, type: c.type, source: source };
    });
  }

  function setCells(list) {
    cells = $.map(list, function (c) { return { id: c.id || nextId(), type: c.type, source: c.source }; });
    renderCells();
  }

  function open(id) {
    api.request({ url: '/api/v1/notebooks/' + encodeURIComponent(id), silent: true }).then(function (body) {
      current = body.data;
      $('#nb-editor').removeClass('d-none');
      $('#nb-empty').addClass('d-none');
      $('#nb-name').val(current.name);
      setCells(current.cells);
      showProblems([]);
      $('#nb-list [data-notebook]').removeClass('active').removeAttr('aria-current')
        .filter(function () { return $(this).attr('data-notebook') === String(id); }).addClass('active').attr('aria-current', 'true');
      loadLastRun();
    }, function (err) { api.showError(err); });
  }
  $('#nb-list').on('click', '[data-notebook]', function () { open(String($(this).attr('data-notebook'))); });

  $('#nb-new').on('click', function () {
    current = null;
    $('#nb-editor').removeClass('d-none');
    $('#nb-empty').addClass('d-none');
    $('#nb-name').val('').trigger('focus');
    setCells(TEMPLATE);
    $('#nb-status').text('');
    $('#nb-artifacts').addClass('d-none');
    showProblems([]);
  });

  $root.on('click', '[data-nb-add]', function () {
    collect();
    cells.push({ id: nextId(), type: String($(this).attr('data-nb-add')), source: '' });
    renderCells();
  });
  $root.on('click', '[data-cell-up], [data-cell-down], [data-cell-remove]', function () {
    collect();
    var id = String($(this).attr('data-cell-up') || $(this).attr('data-cell-down') || $(this).attr('data-cell-remove'));
    var i = $.map(cells, function (c, k) { return c.id === id ? k : null; })[0];
    if ($(this).is('[data-cell-remove]')) { cells.splice(i, 1); }
    else {
      var j = $(this).is('[data-cell-up]') ? i - 1 : i + 1;
      var tmp = cells[i]; cells[i] = cells[j]; cells[j] = tmp;
    }
    renderCells();
  });

  function showProblems(list) {
    var $p = $('#nb-problems').empty().toggleClass('d-none', !list.length);
    if (!list.length) { return; }
    var $ul = $('<ul class="mb-0 ps-3"></ul>').appendTo($p);
    $.each(list, function (_, msg) { $('<li></li>').text(msg).appendTo($ul); });
  }
  function problemsFrom(err) {
    if (err.details && err.details.length) { return $.map(err.details, function (d) { return (d.field ? d.field + ': ' : '') + d.message; }); }
    return [err.message];
  }

  function save() {
    var data = { name: $.trim(String($('#nb-name').val() || '')), cells: collect() };
    var req = current
      ? { method: 'PATCH', url: '/api/v1/notebooks/' + encodeURIComponent(current.id) }
      : { method: 'POST', url: '/api/v1/workspaces/' + encodeURIComponent(ws) + '/notebooks' };
    return api.request($.extend(req, { data: data, button: $('#nb-save'), silent: true })).then(function (body) {
      showProblems([]);
      current = body.data;
      api.announce(body.message);
      loadList();
      return body.data;
    }, function (err) { showProblems(problemsFrom(err)); return $.Deferred().reject(err).promise(); });
  }
  $('#nb-save').on('click', function () { save(); });

  $('#nb-run').on('click', function () {
    if (!canRun) { return; }
    var $btn = $(this);
    (canEdit ? save() : $.Deferred().resolve(current).promise()).then(function (nb) {
      return api.request({ method: 'POST', url: '/api/v1/notebooks/' + encodeURIComponent(nb.id) + '/runs', data: {}, button: $btn, silent: true });
    }).then(function (body) { poll(body.data.id); }, function (err) { if (err && err.message) { showProblems(problemsFrom(err)); } });
  });

  $('#nb-delete').on('click', function () {
    if (!current) { return; }
    window.Swal.fire({
      icon: 'warning', titleText: t('notebooks.delete_title', { name: current.name }), showCancelButton: true,
      confirmButtonText: t('common.delete'), cancelButtonText: t('common.cancel'), confirmButtonColor: '#b91c1c'
    }).then(function (choice) {
      if (!choice.isConfirmed) { return; }
      api.request({ method: 'DELETE', url: '/api/v1/notebooks/' + encodeURIComponent(current.id) }).then(function () {
        current = null;
        $('#nb-editor').addClass('d-none');
        $('#nb-empty').removeClass('d-none');
        loadList();
      });
    });
  });

  /* ------------------------------------------------------------------ runs and outputs */
  function loadLastRun() {
    $('#nb-status').text('');
    $('#nb-artifacts').addClass('d-none');
    api.request({ url: '/api/v1/notebooks/' + encodeURIComponent(current.id) + '/runs', silent: true }).then(function (body) {
      if (body.data.length) { show(body.data[0]); if (body.data[0].status === 'queued' || body.data[0].status === 'running') { poll(body.data[0].id); } }
    });
  }

  function poll(runId) {
    clearTimeout(timer);
    $('#nb-status').text(t('notebooks.running'));
    api.request({ url: '/api/v1/notebook-runs/' + encodeURIComponent(runId), silent: true }).then(function (body) {
      var run = body.data;
      if (run.status === 'queued' || run.status === 'running') { timer = setTimeout(function () { poll(runId); }, 1000); return; }
      show(run);
      loadList();
    }, function (err) { $('#nb-status').text(err.message); });
  }

  function table(tbl, caption) {
    var $wrap = $('<div class="table-responsive" tabindex="0" role="region"></div>').attr('aria-label', caption);
    var $table = $('<table class="table table-sm small mb-1"></table>').appendTo($wrap);
    $('<caption class="visually-hidden"></caption>').text(caption).appendTo($table);
    var $tr = $('<tr></tr>').appendTo($('<thead></thead>').appendTo($table));
    $.each(tbl.columns, function (_, c) { $('<th scope="col"></th>').text(c).appendTo($tr); });
    var $tb = $('<tbody></tbody>').appendTo($table);
    $.each(tbl.rows, function (_, row) {
      var $r = $('<tr></tr>').appendTo($tb);
      $.each(row, function (_, v) { $('<td></td>').text(v === null ? '—' : String(v)).appendTo($r); });
    });
    if (tbl.total_rows > tbl.rows.length) {
      $('<p class="small text-body-secondary mb-0"></p>').text(t('notebooks.rows_shown', { n: tbl.rows.length, total: tbl.total_rows })).appendTo($wrap);
    }
    return $wrap;
  }

  function show(run) {
    $('[data-output]').empty();
    var stamp = t('pipelines.state.' + run.status) + (run.duration_ms !== null ? ' · ' + run.duration_ms + ' ms' : '');
    $('#nb-status').text(t('notebooks.last_run', { state: stamp }) + (run.error && run.error.code !== 'CELL_ERROR' ? ' — ' + run.error.message : ''));
    $.each(run.outputs || [], function (_, out) {
      // Compare attributes instead of building a selector from run data (gate M8-F7).
      var $o = $('[data-output]').filter(function () { return $(this).attr('data-output') === String(out.id); });
      if (!$o.length) { return; }
      if (out.stdout) { $('<pre class="ec-nb-stdout"></pre>').text(out.stdout).appendTo($o); }
      if (out.stderr) { $('<pre class="ec-nb-stderr"></pre>').text(out.stderr).appendTo($o); }
      if (out.table) { $o.append(table(out.table, t('notebooks.output_of', { id: out.id }))); }
      if (out.value !== null && out.value !== undefined) { $('<pre class="ec-nb-value"></pre>').text(out.value).appendTo($o); }
      if (out.error) {
        $('<pre class="ec-nb-error" role="status"></pre>')
          .text(out.error.type + (out.error.line ? ' (' + t('notebooks.line', { n: out.error.line }) + ')' : '') + ': ' + out.error.message).appendTo($o);
      }
    });
    var $art = $('#nb-artifacts-body').empty();
    var names = Object.keys(run.artifacts || {});
    $('#nb-artifacts').toggleClass('d-none', !names.length);
    $.each(names, function (_, name) {
      $('<h4 class="h6 font-monospace mt-2"></h4>').text(name).appendTo($art);
      $art.append(table(run.artifacts[name], name));
    });
  }

  loadList();
})(window, jQuery);
