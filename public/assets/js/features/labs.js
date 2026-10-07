/*
 * Lab Engine UI: catalog (start a lab) and the attempt page (answers, hints, submit + polling, abandon).
 * Scores and verdicts always come from the server; the page only renders them, with .text() (never .html()).
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

  function num(value) {
    return Number(value).toLocaleString('es', { maximumFractionDigits: 1 });
  }

  /* ------------------------------------------------------------------ catalog */
  $('#lab-catalog').on('click', '[data-lab-start]', function () {
    var $btn = $(this);
    api.request({ method: 'POST', url: '/api/v1/lab-attempts', data: { lab_code: String($btn.attr('data-lab-start')) }, button: $btn })
      .then(function (body) {
        window.location.href = api.url('/app/lab-attempts/' + encodeURIComponent(body.data.id));
      });
  });

  /* ------------------------------------------------------------------ attempt */
  var $root = $('#lab-attempt');
  if (!$root.length) { return; }
  var base = '/api/v1/lab-attempts/' + encodeURIComponent(String($root.data('attemptId')));
  var editable = String($root.data('editable')) === '1';
  var timer = null;
  var lastStatus = null;

  function showExpiry() {
    var $p = $('#lab-expiry');
    var iso = $p.attr('data-expires');
    if (iso) { $p.text(t('labs.expires', { date: new Date(iso).toLocaleDateString('es') })); }
  }

  function renderTask(task) {
    var $box = $('#task-' + task.key).find('[data-result]').empty();
    if (!task.result) { return; }
    var r = task.result;
    $('<span class="ec-verdict"></span>')
      .addClass(r.passed ? 'ec-verdict-pass' : 'ec-verdict-fail')
      .append($('<i aria-hidden="true"></i>').addClass(r.passed ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-xmark'))
      .append(document.createTextNode(' ' + (r.passed ? t('labs.passed', { points: num(r.points) }) : t('labs.failed'))))
      .appendTo($box);
    if (r.feedback) { $('<p class="small mb-0 mt-1"></p>').text(r.feedback).appendTo($box); }
  }

  function render(a) {
    $('#lab-status').attr('class', 'ec-attempt ec-attempt-' + a.status).text(t('labs.state.' + a.status));
    $('#lab-best').text(num(a.best_score));
    $('#lab-score').text(a.submissions > 0 && a.status !== 'validating' ? num(a.score) : '—');
    $('#lab-submissions').text(String(a.submissions));
    $('#lab-hints-used').text(String(a.hints_used));
    var closed = a.status === 'abandoned' || a.status === 'expired';
    $('#lab-preparing').toggleClass('d-none', a.environment.ready || closed);
    var $err = $('#lab-error').addClass('d-none').empty();
    if (a.error && a.status !== 'validating') {
      $err.removeClass('d-none').append($('<strong></strong>').text(t('labs.validation_error') + ' '))
        .append(document.createTextNode(a.error.message));
    }
    $.each(a.tasks, function (_, task) { renderTask(task); });

    var validating = a.status === 'validating';
    $('#lab-submit').prop('disabled', validating || !a.environment.ready || closed)
      .attr('aria-busy', validating ? 'true' : null)
      .find('i').attr('class', validating ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-circle-check');
    $('#lab-abandon').prop('disabled', validating);

    if (lastStatus === 'validating' && !validating && !a.error) {
      var passed = $.grep(a.tasks, function (task) { return task.result && task.result.passed; }).length;
      window.Swal.fire({
        icon: passed === a.tasks.length ? 'success' : 'info',
        titleText: t('labs.result_title', { score: num(a.score), max: num(a.max_score) }),
        text: t('labs.result_text', { passed: passed, total: a.tasks.length })
      });
      api.announce(t('labs.result_title', { score: num(a.score), max: num(a.max_score) }));
    }
    lastStatus = a.status;

    clearTimeout(timer);
    if (validating || (!a.environment.ready && !closed)) {
      timer = setTimeout(load, 2000);
    }
  }

  function load() {
    return api.request({ url: base, silent: true }).then(function (body) { render(body.data); }, function (err) {
      $('#lab-error').removeClass('d-none').empty().text(err.message || t('labs.load_error'));
      clearTimeout(timer);
      timer = setTimeout(load, 5000);
    });
  }

  if (editable) {
    $('#lab-submit').on('click', function () {
      var $btn = $(this);
      api.request({ method: 'POST', url: base + '/submit', data: {}, button: $btn }).then(function (body) {
        api.announce(t('labs.validating'));
        render(body.data);
      });
    });

    $root.on('submit', '[data-answer-form]', function (event) {
      event.preventDefault();
      var $form = $(this);
      var $status = $form.find('[data-answer-status]');
      var sql = String($form.find('textarea').val() || '');
      if (!$.trim(sql)) { $status.text(t('labs.answer_empty')); return; }
      api.request({
        method: 'POST',
        url: base + '/answers',
        data: { task_key: String($form.closest('[data-task]').attr('data-task')), sql: sql },
        button: $form.find('[type=submit]')
      }).then(function () {
        $status.text(t('labs.answer_saved'));
        api.announce(t('labs.answer_saved'));
      });
    });

    $root.on('click', 'button[data-hint-index]', function () {
      var $btn = $(this);
      var index = Number($btn.attr('data-hint-index'));
      var task = String($btn.closest('[data-task]').attr('data-task'));
      window.Swal.fire({
        icon: 'question',
        titleText: t('labs.hint_confirm_title'),
        text: t('labs.hint_confirm_text', { penalty: $btn.attr('data-penalty') }),
        showCancelButton: true,
        confirmButtonText: t('labs.hint_confirm'),
        cancelButtonText: t('common.cancel')
      }).then(function (choice) {
        if (!choice.isConfirmed) { return; }
        api.request({ method: 'POST', url: base + '/hints', data: { task_key: task, hint_index: index }, button: $btn }).then(function (body) {
          var $hint = $('<div class="ec-hint"></div>').attr('data-hint-index', String(index))
            .append($('<i class="fa-regular fa-lightbulb" aria-hidden="true"></i>'))
            .append(document.createTextNode(' '))
            .append($('<span></span>').text(body.data.text));
          $btn.replaceWith($hint);
          $('#lab-hints-used').text(String(Number($('#lab-hints-used').text()) + (body.data.newly_revealed ? 1 : 0)));
          $hint.attr('tabindex', '-1').trigger('focus');
        });
      });
    });

    $('#lab-abandon').on('click', function () {
      window.Swal.fire({
        icon: 'warning',
        titleText: t('labs.abandon_title'),
        text: t('labs.abandon_text'),
        showCancelButton: true,
        confirmButtonText: t('labs.abandon'),
        cancelButtonText: t('common.cancel'),
        confirmButtonColor: '#b91c1c'
      }).then(function (choice) {
        if (!choice.isConfirmed) { return; }
        api.request({ method: 'DELETE', url: base, button: $('#lab-abandon') }).then(function () {
          window.location.href = api.url('/app/labs');
        });
      });
    });
  }

  showExpiry();
  load();
})(window, jQuery);
