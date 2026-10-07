/*
 * Courses: join with a code, create, publish/archive, join codes, lab assignments, start course labs.
 * Data is rendered with .text() only; the join code is shown once in a dialog (text, never HTML).
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

  function fieldErrors($form, err) {
    if (err.status === 422 && err.details && err.details.length) {
      api.showFieldErrors($form, err.details);
      return;
    }
    api.showError(err);
  }

  /* ------------------------------------------------------------------ join */
  $('#course-join-form').on('submit', function (event) {
    event.preventDefault();
    var $form = $(this);
    api.showFieldErrors($form, []);
    api.request({ method: 'POST', url: '/api/v1/courses/join', data: { code: String($form.find('[name=code]').val() || '') }, button: $form.find('[type=submit]'), silent: true })
      .then(function (body) {
        var r = body.data;
        return window.Swal.fire({
          icon: 'success',
          titleText: body.message,
          text: t('courses.joined_text', { course: r.course.title, org: r.tenant.name }),
          confirmButtonText: t('courses.go_course')
        }).then(function () {
          // Switch the session to the course's organization, then open the course.
          return api.request({ method: 'POST', url: '/api/v1/tenants/' + encodeURIComponent(r.tenant.id) + '/switch' }).then(function () {
            window.location.href = api.url('/app/courses/' + encodeURIComponent(r.course.id));
          });
        });
      }, function (err) { fieldErrors($form, err); });
  });

  /* ------------------------------------------------------------------ create */
  $('#course-create-form').on('submit', function (event) {
    event.preventDefault();
    var $form = $(this);
    api.showFieldErrors($form, []);
    var data = {
      code: String($form.find('[name=code]').val() || '').toUpperCase().trim(),
      title: String($form.find('[name=title]').val() || '').trim()
    };
    var description = String($form.find('[name=description]').val() || '').trim();
    if (description) { data.description = description; }
    api.request({ method: 'POST', url: '/api/v1/courses', data: data, button: $form.find('[type=submit]'), silent: true })
      .then(function (body) {
        window.location.href = api.url('/app/courses/' + encodeURIComponent(body.data.id));
      }, function (err) { fieldErrors($form, err); });
  });

  /* ------------------------------------------------------------------ course page */
  var $course = $('#course');
  if (!$course.length) { return; }
  var courseId = String($course.data('courseId'));
  var base = '/api/v1/courses/' + encodeURIComponent(courseId);

  function reload() { window.location.reload(); }

  $course.on('click', '[data-course-lab-start]', function () {
    var $btn = $(this);
    api.request({ method: 'POST', url: '/api/v1/lab-attempts', data: { lab_code: String($btn.attr('data-course-lab-start')), course_id: courseId }, button: $btn })
      .then(function (body) {
        window.location.href = api.url('/app/lab-attempts/' + encodeURIComponent(body.data.id));
      });
  });

  $course.on('click', '[data-course-status]', function () {
    var $btn = $(this);
    api.request({ method: 'PATCH', url: base, data: { status: String($btn.attr('data-course-status')) }, button: $btn }).then(reload);
  });

  $('#course-join-rotate').on('click', function () {
    var $btn = $(this);
    window.Swal.fire({
      icon: 'question',
      titleText: t('courses.code_confirm_title'),
      text: t('courses.code_confirm_text'),
      showCancelButton: true,
      confirmButtonText: t('courses.code_generate'),
      cancelButtonText: t('common.cancel')
    }).then(function (choice) {
      if (!choice.isConfirmed) { return; }
      api.request({ method: 'POST', url: base + '/join-code', data: {}, button: $btn }).then(function (body) {
        window.Swal.fire({ icon: 'success', titleText: body.data.join_code, text: body.message }).then(reload);
      });
    });
  });

  $('#course-join-disable').on('click', function () {
    api.request({ method: 'DELETE', url: base + '/join-code', button: $(this) }).then(reload);
  });

  $course.on('click', '[data-course-lab-remove]', function () {
    var $btn = $(this);
    var code = String($btn.attr('data-course-lab-remove'));
    window.Swal.fire({
      icon: 'warning',
      titleText: t('courses.unassign_title', { lab: code }),
      text: t('courses.unassign_text'),
      showCancelButton: true,
      confirmButtonText: t('courses.unassign'),
      cancelButtonText: t('common.cancel'),
      confirmButtonColor: '#b91c1c'
    }).then(function (choice) {
      if (!choice.isConfirmed) { return; }
      api.request({ method: 'DELETE', url: base + '/labs/' + encodeURIComponent(code), button: $btn }).then(reload);
    });
  });

  $('#course-assign-form').on('submit', function (event) {
    event.preventDefault();
    var $form = $(this);
    var data = { lab_code: String($form.find('[name=lab_code]').val()), required: $form.find('[name=required]').is(':checked') };
    var due = String($form.find('[name=due_at]').val() || '');
    if (due) { data.due_at = due; }
    api.request({ method: 'POST', url: base + '/labs', data: data, button: $form.find('[type=submit]'), silent: true })
      .then(reload, function (err) { fieldErrors($form, err); });
  });
})(window, jQuery);
