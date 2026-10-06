/*
 * Generic AJAX form handling for auth pages (and any form marked data-ec-form):
 *   data-endpoint   app-relative API endpoint (POST, JSON)
 *   data-redirect   app-relative path to open on success (optional)
 *   data-reset      reset the form and show the server message on success (optional)
 * Fields with data-ec-local are validated in the browser only and never sent (e.g. password confirmation).
 * Server validation is authoritative; 422 details are shown next to the matching inputs.
 */
(function (window, $) {
  'use strict';

  var api = window.EduCloud.api;
  var strings = {};
  try { strings = JSON.parse($('#ec-i18n').text() || '{}'); } catch (e) { strings = {}; }

  function clientErrors($form) {
    var details = [];
    $form.find('[required]').each(function () {
      if ($.trim($(this).val()) === '') {
        details.push({ field: this.name, code: 'required', message: strings.required });
      }
    });
    $form.find('[data-ec-match]').each(function () {
      var other = $form.find('[name="' + $(this).data('ecMatch') + '"]').val();
      if ($(this).val() !== '' && $(this).val() !== other) {
        details.push({ field: this.name, code: 'mismatch', message: strings.passwords_mismatch });
      }
    });
    return details;
  }

  function payload($form) {
    var data = {};
    $form.find('input[name], select[name], textarea[name]').not('[data-ec-local]').each(function () {
      data[this.name] = $(this).val();
    });
    return data;
  }

  $(document).on('submit', 'form[data-ec-form]', function (event) {
    event.preventDefault();
    var $form = $(this);
    var $button = $form.find('[type="submit"]').first();

    var local = clientErrors($form);
    api.showFieldErrors($form, local);
    if (local.length) {
      $form.find('.is-invalid').first().trigger('focus');
      return;
    }

    api.request({ method: 'POST', url: $form.data('endpoint'), data: payload($form), button: $button, silent: true })
      .then(function (body) {
        var redirect = $form.data('redirect');
        if (redirect) {
          window.location.assign(api.url(String(redirect)));
          return;
        }
        if ($form.data('reset')) {
          $form.trigger('reset');
        }
        window.Swal.fire({ icon: 'success', titleText: strings.done, text: body.message || '' });
      }, function (err) {
        if (err.code === 'DUPLICATE_SUBMIT') {
          return;
        }
        if (err.status === 422 && err.details && err.details.some(function (d) { return d.field; })) {
          api.showFieldErrors($form, err.details);
          $form.find('.is-invalid').first().trigger('focus');
          return;
        }
        api.showError(err);
      });
  });

  $(document).on('click', '[data-ec-logout]', function () {
    api.request({ method: 'POST', url: '/api/v1/auth/logout', button: this })
      .then(function () { window.location.assign(api.url('/login?notice=logout')); });
  });
})(window, jQuery);
