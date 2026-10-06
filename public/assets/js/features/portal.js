/* Portal shell behaviour: switching the active organization (tenant). */
(function (window, $) {
  'use strict';

  var api = window.EduCloud.api;

  $(document).on('click', '[data-ec-switch-tenant]', function () {
    var id = String($(this).data('ecSwitchTenant'));
    api.request({ method: 'POST', url: '/api/v1/tenants/' + encodeURIComponent(id) + '/switch', button: this })
      .then(function () { window.location.assign(api.url('/app')); });
  });
})(window, jQuery);
