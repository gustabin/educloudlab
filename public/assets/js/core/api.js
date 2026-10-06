/*
 * EduCloud Lab API client (jQuery AJAX wrapper).
 * - Sends/receives the standard JSON envelope.
 * - Adds the CSRF token from <meta name="csrf-token"> (available from M2).
 * - Guards against double submits and maps errors to SweetAlert2 feedback.
 * Never insert response data with .html(); use .text() or build elements.
 */
(function (window, $) {
  'use strict';

  var strings = {};
  try {
    strings = JSON.parse(($('#ec-i18n').text() || '{}'));
  } catch (e) {
    strings = {};
  }

  // Deployment base path (e.g. "/educloudlab"); app-relative URLs passed to request() are prefixed with it.
  var base = $('meta[name="app-base"]').attr('content') || '';

  function appUrl(path) {
    return path.charAt(0) === '/' ? base + path : path;
  }

  function csrfToken() {
    return $('meta[name="csrf-token"]').attr('content') || '';
  }

  function announce(message) {
    $('#ec-live').text(message);
  }

  /**
   * Normalises any failure into {status, code, message, details, requestId}.
   */
  function normaliseError(xhr, textStatus) {
    var body = xhr && xhr.responseJSON;
    if (body && body.error) {
      return {
        status: xhr.status,
        code: body.error.code,
        message: body.error.message,
        details: body.error.details || [],
        requestId: body.meta ? body.meta.request_id : null
      };
    }
    if (textStatus === 'timeout') {
      return { status: 0, code: 'TIMEOUT', message: strings.timeout, details: [], requestId: null };
    }
    if (!xhr || xhr.status === 0) {
      return { status: 0, code: 'NETWORK_ERROR', message: strings.network_error, details: [], requestId: null };
    }
    return { status: xhr.status, code: 'UNEXPECTED', message: strings.generic_error, details: [], requestId: null };
  }

  /**
   * request({method, url, data, button, silent}) -> jQuery promise resolving to the envelope.
   * - button: element to disable while in flight (prevents duplicate submissions)
   * - silent: do not show the SweetAlert2 error dialog (caller handles it)
   */
  function request(opts) {
    var $btn = opts.button ? $(opts.button) : null;
    if ($btn && $btn.data('ecBusy')) {
      return $.Deferred().reject({ code: 'DUPLICATE_SUBMIT' }).promise();
    }
    if ($btn) {
      $btn.data('ecBusy', true).prop('disabled', true).attr('aria-busy', 'true').addClass('ec-busy');
    }

    var method = (opts.method || 'GET').toUpperCase();
    var headers = { 'Accept': 'application/json' };
    if (method !== 'GET') {
      headers['X-CSRF-Token'] = csrfToken();
    }

    return $.ajax({
      url: appUrl(opts.url),
      method: method,
      headers: headers,
      timeout: opts.timeout || 15000,
      dataType: 'json',
      contentType: opts.data !== undefined ? 'application/json' : undefined,
      data: opts.data !== undefined ? JSON.stringify(opts.data) : undefined
    }).then(
      function (body, textStatus, xhr) {
        if (xhr && xhr.status === 204) {
          return { success: true, data: null };
        }
        if (!body || body.success !== true) {
          return $.Deferred().reject(normaliseError(null, 'invalid')).promise();
        }
        if (body.message) {
          announce(body.message);
        }
        return body;
      },
      function (xhr, textStatus) {
        var err = normaliseError(xhr, textStatus);
        if (!opts.silent) {
          showError(err);
        }
        return $.Deferred().reject(err).promise();
      }
    ).always(function () {
      if ($btn) {
        $btn.data('ecBusy', false).prop('disabled', false).removeAttr('aria-busy').removeClass('ec-busy');
      }
    });
  }

  function showError(err) {
    if (!window.Swal) {
      announce(err.message);
      return;
    }
    var footer = null;
    if (err.requestId) {
      footer = document.createElement('small');
      footer.textContent = 'ID: ' + err.requestId;
    }
    // titleText (not title): SweetAlert2 renders `title` as HTML.
    window.Swal.fire({ icon: 'error', titleText: err.message, footer: footer || undefined });
  }

  /** Applies 422 field errors to a form (inputs matched by name attribute). */
  function showFieldErrors($form, details) {
    $form.find('.is-invalid').removeClass('is-invalid');
    $form.find('.invalid-feedback[data-ec-generated]').remove();
    $.each(details || [], function (_, d) {
      if (!d.field) { return; }
      var $input = $form.find('[name="' + String(d.field).replace(/"/g, '') + '"]');
      if ($input.length) {
        $input.addClass('is-invalid').attr('aria-invalid', 'true');
        $('<div class="invalid-feedback" data-ec-generated></div>').text(d.message).insertAfter($input);
      }
    });
  }

  window.EduCloud = window.EduCloud || {};
  window.EduCloud.api = { request: request, url: appUrl, showError: showError, showFieldErrors: showFieldErrors, announce: announce };
})(window, jQuery);
