/*
 * Course content (M10b). Course page: staff create/edit/publish/reorder/delete modules and lessons (then reload the
 * server-rendered tree). Lesson page: students mark the lesson as completed; staff edit it. Inputs go through
 * SweetAlert2 inputs or forms; nothing from the server is inserted as HTML.
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
  function reload() { window.location.reload(); }

  /* ------------------------------------------------------------------ course page (staff) */
  var $content = $('#course-content');
  var courseId = String($('#course').data('courseId') || '');

  function moduleOf(el) { return String($(el).closest('[data-module]').attr('data-module')); }
  function lessonOf(el) { return String($(el).closest('[data-lesson]').attr('data-lesson')); }

  function askModule(title, current) {
    return window.Swal.fire({
      titleText: title,
      html: '<label class="form-label small d-block text-start" for="swal-module-title"></label>' +
        '<input class="swal2-input mt-0" id="swal-module-title" maxlength="150">' +
        '<label class="form-label small d-block text-start mt-2" for="swal-module-summary"></label>' +
        '<input class="swal2-input mt-0" id="swal-module-summary" maxlength="500">',
      didOpen: function () {
        $('label[for=swal-module-title]').text(t('content.module_title'));
        $('label[for=swal-module-summary]').text(t('content.module_summary'));
        $('#swal-module-title').val(current.title || '');
        $('#swal-module-summary').val(current.summary || '');
      },
      showCancelButton: true, confirmButtonText: t('content.save'), cancelButtonText: t('common.cancel'),
      preConfirm: function () {
        return { title: $.trim(String($('#swal-module-title').val() || '')), summary: $.trim(String($('#swal-module-summary').val() || '')) || null };
      }
    });
  }

  function failed(err) { api.showError(err); }

  $content.on('click', '[data-content-new-module]', function () {
    askModule(t('content.new_module'), {}).then(function (r) {
      if (!r.isConfirmed) { return; }
      api.request({ method: 'POST', url: '/api/v1/courses/' + encodeURIComponent(courseId) + '/modules', data: r.value, silent: true })
        .then(reload, failed);
    });
  });

  $content.on('click', '[data-module-edit]', function () {
    var id = moduleOf(this);
    askModule(t('content.edit_module'), { title: $(this).attr('data-title'), summary: $(this).attr('data-summary') }).then(function (r) {
      if (!r.isConfirmed) { return; }
      api.request({ method: 'PATCH', url: '/api/v1/course-modules/' + encodeURIComponent(id), data: r.value, silent: true }).then(reload, failed);
    });
  });

  $content.on('click', '[data-module-status], [data-module-move]', function () {
    var data = $(this).is('[data-module-status]')
      ? { status: String($(this).attr('data-module-status')) }
      : { position: parseInt(String($(this).attr('data-module-move')), 10) };
    api.request({ method: 'PATCH', url: '/api/v1/course-modules/' + encodeURIComponent(moduleOf(this)), data: data, button: $(this), silent: true })
      .then(reload, failed);
  });

  $content.on('click', '[data-module-delete], [data-lesson-delete]', function () {
    var isModule = $(this).is('[data-module-delete]');
    var url = isModule ? '/api/v1/course-modules/' + encodeURIComponent(moduleOf(this)) : '/api/v1/lessons/' + encodeURIComponent(lessonOf(this));
    var $btn = $(this);
    window.Swal.fire({
      icon: 'warning', titleText: t('content.delete_title', { title: String($btn.attr('data-title')) }), showCancelButton: true,
      confirmButtonText: t('common.delete'), cancelButtonText: t('common.cancel'), confirmButtonColor: '#b91c1c'
    }).then(function (choice) {
      if (!choice.isConfirmed) { return; }
      api.request({ method: 'DELETE', url: url, button: $btn, silent: true }).then(reload, failed);
    });
  });

  $content.on('click', '[data-lesson-new]', function () {
    var id = moduleOf(this);
    window.Swal.fire({
      titleText: t('content.new_lesson'), input: 'text', inputLabel: t('content.lesson_title'), inputAttributes: { maxlength: '150' },
      showCancelButton: true, confirmButtonText: t('content.create'), cancelButtonText: t('common.cancel')
    }).then(function (r) {
      if (!r.isConfirmed) { return; }
      api.request({ method: 'POST', url: '/api/v1/course-modules/' + encodeURIComponent(id) + '/lessons', silent: true,
        data: { title: $.trim(String(r.value || '')), body_md: t('content.body_placeholder') } })
        .then(function (body) { window.location.href = api.url('/app/lessons/' + encodeURIComponent(body.data.id)); }, failed);
    });
  });

  $content.on('click', '[data-lesson-status], [data-lesson-move]', function () {
    var data = $(this).is('[data-lesson-status]')
      ? { status: String($(this).attr('data-lesson-status')) }
      : { position: parseInt(String($(this).attr('data-lesson-move')), 10) };
    api.request({ method: 'PATCH', url: '/api/v1/lessons/' + encodeURIComponent(lessonOf(this)), data: data, button: $(this), silent: true })
      .then(reload, failed);
  });

  /* ------------------------------------------------------------------ lesson page */
  var $lesson = $('#lesson');
  var lessonId = String($lesson.data('lessonId') || '');

  $('#lesson-complete').on('click', function () {
    var done = String($(this).attr('data-completed')) === '1';
    api.request({ method: done ? 'DELETE' : 'POST', url: '/api/v1/lessons/' + encodeURIComponent(lessonId) + '/complete',
      data: done ? undefined : {}, button: $(this) }).then(reload);
  });

  $('#lesson-edit-form').on('submit', function (event) {
    event.preventDefault();
    var $form = $(this);
    var minutes = String($('#lesson-edit-minutes').val() || '');
    var data = {
      title: $.trim(String($('#lesson-edit-name').val() || '')),
      body_md: String($('#lesson-edit-body').val() || ''),
      estimated_minutes: minutes === '' ? null : parseInt(minutes, 10),
      due_at: String($('#lesson-edit-due').val() || '') || null,
      lab_code: String($('#lesson-edit-lab').val() || '') || null
    };
    api.showFieldErrors($form, []);
    api.request({ method: 'PATCH', url: '/api/v1/lessons/' + encodeURIComponent(lessonId), data: data, button: $form.find('[type=submit]'), silent: true })
      .then(reload, function (err) {
        if (err.status === 422) { api.showFieldErrors($form, err.details); } else { api.showError(err); }
      });
  });
})(window, jQuery);
