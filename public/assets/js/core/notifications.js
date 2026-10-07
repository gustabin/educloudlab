/*
 * Notification bell (M10b): unread count in the top bar, list on open, mark one (on click) or all as read.
 * Titles/bodies are rendered with .text(); links are server-generated app URLs.
 */
(function (window, $) {
  'use strict';

  var api = window.EduCloud && window.EduCloud.api;
  var $root = $('#ec-notifications');
  if (!api || !$root.length) { return; }
  var s = {};
  try { s = JSON.parse($('#ec-i18n').text() || '{}'); } catch (e) { s = {}; }
  function t(key, params) {
    var text = s[key] || key;
    $.each(params || {}, function (k, v) { text = text.split(':' + k).join(String(v)); });
    return text;
  }

  var $toggle = $('#ec-notif-toggle');
  var $count = $('#ec-notif-count');
  var $list = $('#ec-notif-list');

  function setCount(n) {
    $count.text(n > 9 ? '9+' : String(n)).toggleClass('d-none', n === 0);
    $toggle.attr('aria-label', n ? t('notif.label_unread', { n: n }) : t('notif.label'));
  }

  function render(items) {
    $list.empty().attr('aria-busy', 'false');
    if (!items.length) {
      $('<p class="small text-body-secondary px-3 py-2 mb-0"></p>').text(t('notif.empty')).appendTo($list);
      return;
    }
    var $ul = $('<ul class="list-unstyled mb-0"></ul>').appendTo($list);
    $.each(items, function (_, n) {
      var $li = $('<li></li>').appendTo($ul);
      var $a = $('<a class="dropdown-item ec-notif-item"></a>').attr('href', n.url || '#').attr('data-notif', n.id).appendTo($li);
      if (!n.read) { $a.addClass('ec-notif-unread'); $('<span class="visually-hidden"></span>').text(t('notif.unread') + ': ').appendTo($a); }
      $('<span class="d-block fw-semibold text-wrap"></span>').text(n.title).appendTo($a);
      if (n.body) { $('<span class="d-block small text-body-secondary text-wrap"></span>').text(n.body).appendTo($a); }
      $('<span class="d-block small text-body-secondary"></span>').text(new Date(n.created_at).toLocaleString('es')).appendTo($a);
    });
  }

  function load(full) {
    return api.request({ url: '/api/v1/notifications', silent: true }).then(function (body) {
      setCount(body.meta && body.meta.unread ? body.meta.unread : 0);
      if (full) { render(body.data); }
    }, function () { /* the bell is optional chrome: never interrupt the page */ });
  }

  $root.on('show.bs.dropdown', function () {
    $list.attr('aria-busy', 'true');
    load(true);
  });

  $list.on('click', '[data-notif]', function (event) {
    var $a = $(this);
    var href = String($a.attr('href'));
    event.preventDefault();
    api.request({ method: 'POST', url: '/api/v1/notifications/' + encodeURIComponent(String($a.attr('data-notif'))) + '/read', silent: true })
      .always(function () { if (href && href !== '#') { window.location.href = href; } else { load(true); } });
  });

  $('#ec-notif-read-all').on('click', function () {
    api.request({ method: 'POST', url: '/api/v1/notifications/read-all', data: {}, silent: true }).then(function () { load(true); });
  });

  load(false);
})(window, jQuery);
