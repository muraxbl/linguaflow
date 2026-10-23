/**
 * LinguaFlow AI - back office notifier.
 *
 * Loaded on every back office page. It:
 *  - polls the queue status and shows a small progress pill while jobs run,
 *  - drives the queue ("ticks") when the back office worker is enabled,
 *  - shows a toast and a native browser notification when a job finishes,
 *  - handles the "Translate with AI" button of the product page panel.
 */
(function () {
  'use strict';

  var cfg = window.linguaflowai;
  if (!cfg || !window.fetch) {
    return;
  }

  var TAB_ID = Math.random().toString(36).slice(2);
  var LEADER_KEY = 'linguaflowai.leader';
  var LEADER_TTL = 45000;
  var ticking = false;
  var pill = null;

  function storageGet(key) {
    try { return window.localStorage.getItem(key); } catch (e) { return null; }
  }

  function storageSet(key, value) {
    try { window.localStorage.setItem(key, value); } catch (e) { /* private mode */ }
  }

  /** Only one open tab drives the queue; the server lock protects us anyway. */
  function isLeader() {
    var raw = storageGet(LEADER_KEY);
    var now = Date.now();
    var leader = raw ? raw.split('|') : null;
    if (!leader || leader[0] === TAB_ID || now - parseInt(leader[1], 10) > LEADER_TTL) {
      storageSet(LEADER_KEY, TAB_ID + '|' + now);
      return true;
    }
    return false;
  }

  function post(url, data) {
    var body = new FormData();
    Object.keys(data || {}).forEach(function (key) {
      var value = data[key];
      if (Array.isArray(value)) {
        value.forEach(function (v) { body.append(key + '[]', v); });
      } else {
        body.append(key, value);
      }
    });
    return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }

  function format(template, values) {
    return template.replace(/%(\w+)%/g, function (m, key) {
      return values[key] !== undefined ? values[key] : m;
    });
  }

  /* ---------------------------------------------------------------- toasts */

  function toast(title, message, type) {
    if (window.jQuery && window.jQuery.growl) {
      var fn = type === 'warning' ? 'warning' : (type === 'error' ? 'error' : 'notice');
      window.jQuery.growl[fn]({ title: title, message: message, duration: 10000 });
      return;
    }
    var el = document.createElement('div');
    el.className = 'lfai-toast lfai-toast-' + (type || 'success');
    el.innerHTML = '<strong></strong><div></div>';
    el.querySelector('strong').textContent = title;
    el.querySelector('div').textContent = message;
    el.addEventListener('click', function () { el.remove(); });
    document.body.appendChild(el);
    setTimeout(function () { el.classList.add('lfai-toast-hide'); }, 9000);
    setTimeout(function () { el.remove(); }, 10000);
  }

  function nativeNotification(title, message) {
    if (!('Notification' in window) || Notification.permission !== 'granted') {
      return;
    }
    try {
      var n = new Notification(title, { body: message, icon: cfg.icon, tag: 'linguaflowai-' + title + message });
      n.onclick = function () { window.focus(); window.location.href = cfg.dashboardUrl; n.close(); };
    } catch (e) { /* some browsers only allow notifications from a service worker */ }
  }

  function notifyCompleted(job) {
    var message = format(cfg.i18n.completedBody, {
      label: job.label,
      done: job.done,
      failed: job.failed
    });
    toast(cfg.i18n.completedTitle, message, parseInt(job.failed, 10) > 0 ? 'warning' : 'success');
    nativeNotification(cfg.i18n.completedTitle, message);
  }

  /* ---------------------------------------------------------- progress pill */

  function renderProgress(active) {
    if (!active.length) {
      if (pill) { pill.remove(); pill = null; }
      return;
    }
    var total = 0, processed = 0;
    active.forEach(function (job) {
      total += parseInt(job.total, 10);
      processed += parseInt(job.done, 10) + parseInt(job.failed, 10);
    });
    if (!pill) {
      pill = document.createElement('a');
      pill.className = 'lfai-pill';
      pill.href = cfg.dashboardUrl;
      document.body.appendChild(pill);
    }
    pill.textContent = cfg.i18n.progress + ' ' + processed + '/' + total;

    active.forEach(function (job) {
      var row = document.querySelector('#lfai-jobs tr[data-id-job="' + job.id_job + '"]');
      if (!row) { return; }
      var pct = job.total > 0 ? Math.round((parseInt(job.done, 10) + parseInt(job.failed, 10)) * 100 / job.total) : 0;
      var bar = row.querySelector('.progress-bar');
      if (bar) { bar.style.width = pct + '%'; }
      var counts = row.querySelector('.lfai-counts');
      if (counts) { counts.textContent = job.done + ' / ' + job.total + (job.failed > 0 ? ' · ' + job.failed + ' ✗' : ''); }
    });
  }

  /* ------------------------------------------------------------ poll / tick */

  function handleStatus(status) {
    if (!status || !status.ok) {
      return status;
    }
    renderProgress(status.active || []);
    (status.completed || []).forEach(notifyCompleted);
    return status;
  }

  function poll() {
    return post(cfg.statusUrl).then(handleStatus).then(function (status) {
      if (status && status.due > 0 && cfg.worker && !ticking && isLeader()) {
        tick();
      }
    }).catch(function () { /* session expired or offline: try again later */ });
  }

  function tick() {
    ticking = true;
    post(cfg.tickUrl).then(function (result) {
      ticking = false;
      if (!result.ok) {
        toast('LinguaFlow AI', result.error || 'Error', 'error');
        return;
      }
      storageSet(LEADER_KEY, TAB_ID + '|' + Date.now());
      poll();
    }).catch(function () { ticking = false; });
  }

  /* --------------------------------------------------------- product panel */

  document.addEventListener('click', function (event) {
    var button = event.target.closest ? event.target.closest('.lfai-translate-now') : null;
    if (!button) {
      return;
    }
    event.preventDefault();
    var panel = button.closest('.lfai-product-panel');
    var langs = Array.prototype.map.call(panel.querySelectorAll('.lfai-lang-input:checked'), function (i) { return i.value; });
    var result = panel.querySelector('.lfai-product-result');
    button.disabled = true;
    post(panel.getAttribute('data-url'), {
      id_product: panel.getAttribute('data-id-product'),
      overwrite: panel.querySelector('.lfai-overwrite').checked ? 1 : 0,
      langs: langs
    }).then(function (r) {
      button.disabled = false;
      result.textContent = r.ok ? r.message : r.error;
      result.className = 'lfai-product-result ' + (r.ok ? 'text-success' : 'text-danger');
      if (r.ok) { poll(); }
    }).catch(function () { button.disabled = false; });
  });

  /* ------------------------------------------------- notification opt-in */

  document.addEventListener('click', function (event) {
    if (!event.target.closest || !event.target.closest('#lfai-enable-notifications')) {
      return;
    }
    if (!('Notification' in window)) {
      toast('LinguaFlow AI', 'This browser does not support notifications.', 'warning');
      return;
    }
    Notification.requestPermission().then(function (permission) {
      if (permission === 'granted') {
        nativeNotification('LinguaFlow AI', 'Browser notifications enabled.');
      }
    });
  });

  setTimeout(poll, 1500);
  setInterval(poll, cfg.pollInterval || 15000);
})();
