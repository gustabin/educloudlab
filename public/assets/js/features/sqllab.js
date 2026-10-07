/*
 * SQL Lab: CodeMirror editor, lakehouse catalog, async query execution (202 + polling), result grid, history,
 * and "save as table" (transform to silver/gold). All data is rendered with .text()/.attr() — never as HTML.
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

  $(function () {
    var $root = $('#sql-lab');
    if (!$root.length || !window.CodeMirror) { return; }
    var workspaceId = String($root.data('workspaceId'));
    var canExecute = String($root.data('canExecute')) === '1';
    var base = '/api/v1/workspaces/' + encodeURIComponent(workspaceId);
    var hintTables = {};
    var pollTimer = null;
    var running = false;

    var editor = window.CodeMirror.fromTextArea(document.getElementById('sql-editor'), {
      mode: 'text/x-sql',
      lineNumbers: true,
      matchBrackets: true,
      indentWithTabs: false,
      tabSize: 2,
      viewportMargin: Infinity,
      readOnly: canExecute ? false : 'nocursor',
      extraKeys: {
        'Ctrl-Enter': function () { run(); },
        'Cmd-Enter': function () { run(); },
        'Ctrl-Space': 'autocomplete'
      },
      hintOptions: { tables: hintTables, completeSingle: false }
    });
    // Label the editor's hidden input for assistive technology.
    $(editor.getInputField()).attr('aria-labelledby', 'sql-editor-label').attr('aria-describedby', 'sql-editor-help');

    /* ------------------------------------------------------------- catalog */
    function loadCatalog() {
      var $cat = $('#sql-catalog').attr('aria-busy', 'true');
      return api.request({ url: base + '/catalog', silent: true }).then(function (body) {
        $cat.empty();
        var total = 0;
        $.each(['bronze', 'silver', 'gold'], function (_, layer) {
          var tables = body.data[layer] || [];
          var $section = $('<div class="mb-2"></div>').appendTo($cat);
          $('<div class="ec-catalog-layer"></div>').append($('<span class="ec-layer"></span>').addClass('ec-layer-' + layer).text(layer)).appendTo($section);
          if (!tables.length) {
            $('<div class="small text-body-secondary ps-2"></div>').text(t('sql.catalog_layer_empty')).appendTo($section);
          }
          $.each(tables, function (_, table) {
            total++;
            hintTables[table.qualified_name] = $.map(table.columns, function (c) { return c.name; });
            // No buttons inside <summary> (nested interactive controls): the summary only toggles, inserting is inside.
            var $details = $('<details class="ec-catalog-table"></details>').appendTo($section);
            var $summary = $('<summary></summary>').text(table.table).appendTo($details);
            if (table.row_count !== null) {
              $('<span class="small text-body-secondary ms-1"></span>').text('(' + Number(table.row_count).toLocaleString('es') + ')').appendTo($summary);
            }
            $('<button type="button" class="btn btn-link btn-sm p-0 ps-3 text-start d-block"></button>')
              .attr('data-insert', table.qualified_name).text(t('sql.insert') + ': ' + table.qualified_name).appendTo($details);
            var $cols = $('<ul class="list-unstyled small ps-3 mb-1"></ul>').appendTo($details);
            $.each(table.columns, function (_, col) {
              var $li = $('<li></li>').appendTo($cols);
              $('<button type="button" class="btn btn-link btn-sm p-0"></button>').attr('data-insert', col.name).text(col.name).appendTo($li);
              $('<span class="text-body-secondary ms-1"></span>').text(col.type.toLowerCase()).appendTo($li);
            });
          });
        });
        if (!editor.getValue()) {
          var first = Object.keys(hintTables)[0];
          editor.setValue(first ? 'SELECT *\nFROM ' + first + '\nLIMIT 100;' : t('sql.placeholder').replace(/\\n/g, '\n'));
        }
        return total;
      }, function () {
        $cat.empty();
        $('<div class="small text-danger"></div>').text(t('sql.catalog_error')).appendTo($cat);
      }).always(function () { $cat.attr('aria-busy', 'false'); });
    }

    $('#sql-catalog').on('click', '[data-insert]', function () {
      editor.replaceSelection(String($(this).attr('data-insert')));
      editor.focus();
    });
    $('#sql-catalog-refresh').on('click', loadCatalog);

    /* ------------------------------------------------------------- results */
    function showError(error) {
      $('#sql-error').removeClass('d-none').empty()
        .append($('<strong></strong>').text((error.code || 'SQL_ERROR') + ': '))
        .append(document.createTextNode(error.message || t('sql.failed')));
    }

    function renderResult(query) {
      var $out = $('#sql-results').empty();
      $('#sql-error').addClass('d-none').empty();
      var $status = $('#sql-status');
      if (query.status === 'failed' || query.status === 'timed_out') {
        $status.text(t('sql.status_failed'));
        showError(query.error || {});
        return;
      }
      if (!query.result) {
        $status.text(query.result_expired ? t('sql.result_expired') : t('sql.results_empty'));
        return;
      }
      var result = query.result;
      var parts = [t('sql.rows', { n: query.row_count }), t('sql.duration', { ms: query.duration_ms })];
      if (result.truncated) { parts.push(t('sql.truncated')); }
      $status.text(parts.join(' · '));
      if (!result.columns.length) { return; }
      // Scrollable region: focusable and labelled so keyboard users can scroll it.
      var $wrap = $('<div class="table-responsive ec-result-grid" tabindex="0" role="region"></div>').attr('aria-label', t('sql.results')).appendTo($out);
      var $table = $('<table class="table table-sm table-striped table-hover ec-preview-table mb-0"><thead><tr></tr></thead><tbody></tbody></table>').appendTo($wrap);
      $.each(result.columns, function (_, c) {
        var $th = $('<th scope="col"></th>').text(c.name).appendTo($table.find('thead tr'));
        $('<span class="ec-col-type"></span>').text(c.type.toLowerCase()).appendTo($th);
      });
      var $tbody = $table.find('tbody');
      $.each(result.rows, function (_, r) {
        var $tr = $('<tr></tr>');
        $.each(r, function (_, v) {
          var $td = $('<td></td>');
          if (v === null) { $td.addClass('text-body-secondary fst-italic').text('NULL'); } else { $td.text(String(v)).attr('title', String(v)); }
          $tr.append($td);
        });
        $tbody.append($tr);
      });
    }

    function poll(queryId, delay) {
      clearTimeout(pollTimer);
      pollTimer = setTimeout(function () {
        api.request({ url: '/api/v1/queries/' + encodeURIComponent(queryId), silent: true }).then(function (body) {
          var q = body.data;
          if (q.status === 'queued' || q.status === 'running') {
            $('#sql-status').text(q.status === 'queued' ? t('sql.status_queued') : t('sql.status_running'));
            poll(queryId, Math.min(delay * 1.5, 1500));
            return;
          }
          finish();
          renderResult(q);
          loadHistory();
        }, function (err) { finish(); showError(err); });
      }, delay);
    }

    function finish() {
      running = false;
      $('#sql-run').prop('disabled', false).removeAttr('aria-busy');
    }

    function run() {
      if (!canExecute || running) { return; }
      var sql = $.trim(editor.getSelection() || editor.getValue());
      if (!sql) { return; }
      running = true;
      $('#sql-run').prop('disabled', true).attr('aria-busy', 'true');
      $('#sql-error').addClass('d-none').empty();
      $('#sql-results').empty();
      $('#sql-status').text(t('sql.status_queued'));
      window.bootstrap.Tab.getOrCreateInstance(document.getElementById('sql-results-tab')).show();
      api.request({ method: 'POST', url: base + '/queries', data: { sql: sql }, silent: true }).then(function (body) {
        poll(body.data.id, 300);
      }, function (err) {
        finish();
        $('#sql-status').text(t('sql.status_failed'));
        showError(err);
      });
    }
    $('#sql-run').on('click', run);

    /* ------------------------------------------------------------- history */
    function loadHistory() {
      api.request({ url: base + '/queries?per_page=20', silent: true }).then(function (body) {
        var $h = $('#sql-history').empty();
        if (!body.data.length) {
          $('<p class="small text-body-secondary mb-0"></p>').text(t('sql.history_empty')).appendTo($h);
          return;
        }
        var $list = $('<div class="list-group list-group-flush"></div>').appendTo($h);
        $.each(body.data, function (_, q) {
          var $item = $('<button type="button" class="list-group-item list-group-item-action"></button>').attr('data-query', q.id).appendTo($list);
          $('<code class="d-block text-truncate ec-history-sql"></code>').text(q.sql).appendTo($item);
          var meta = [t('sql.state.' + q.status)];
          if (q.row_count !== null) { meta.push(t('sql.rows', { n: q.row_count })); }
          if (q.duration_ms !== null) { meta.push(t('sql.duration', { ms: q.duration_ms })); }
          meta.push(new Date(q.created_at).toLocaleString('es'));
          $('<span class="small text-body-secondary"></span>').text(meta.join(' · ')).appendTo($item);
        });
      });
    }
    $('#sql-history').on('click', '[data-query]', function () {
      var id = String($(this).attr('data-query'));
      api.request({ url: '/api/v1/queries/' + encodeURIComponent(id) }).then(function (body) {
        editor.setValue(body.data.sql);
        window.bootstrap.Tab.getOrCreateInstance(document.getElementById('sql-results-tab')).show();
        renderResult(body.data);
      });
    });

    /* ------------------------------------------------------------- save as table (transform) */
    $('#sql-save-form').on('submit', function (event) {
      event.preventDefault();
      var $form = $(this);
      var data = { sql: $.trim(editor.getValue()).replace(/;\s*$/, ''), layer: $('#sql-save-layer').val(), table: $.trim($('#sql-save-table-name').val()) };
      api.showFieldErrors($form, []);
      api.request({ method: 'POST', url: base + '/transforms', data: data, button: $form.find('[type=submit]'), silent: true })
        .then(function (body) {
          window.bootstrap.Modal.getOrCreateInstance(document.getElementById('sql-save-modal')).hide();
          $form.trigger('reset');
          var target = data.layer + '.' + data.table;
          window.Swal.fire({ icon: 'info', titleText: t('sql.saving_title', { table: target }), text: t('sql.saving_text') });
          pollTable(body.data.dataset.id, target, 0);
        }, function (err) {
          if (err.status === 422 && err.details && err.details.some(function (d) { return d.field; })) {
            api.showFieldErrors($form, $.map(err.details, function (d) { return d.field === 'table' || d.field === 'layer' ? d : null; }));
            if (!$form.find('.is-invalid').length) { api.showError(err); }
            return;
          }
          api.showError(err);
        });
    });

    function pollTable(datasetId, target, attempt) {
      setTimeout(function () {
        api.request({ url: '/api/v1/datasets/' + encodeURIComponent(datasetId), silent: true }).then(function (body) {
          var ds = body.data;
          if (ds.status === 'provisioning' && attempt < 60) { pollTable(datasetId, target, attempt + 1); return; }
          if (ds.status === 'active') {
            window.Swal.fire({ icon: 'success', titleText: t('sql.saved', { table: target }), text: t('sql.rows', { n: ds.version.row_count }) });
            loadCatalog();
          } else if (ds.error) {
            window.Swal.fire({ icon: 'error', titleText: t('sql.save_failed'), text: ds.error.code + ': ' + ds.error.message });
          }
        });
      }, 1000);
    }

    loadCatalog();
    loadHistory();
  });
})(window, jQuery);
