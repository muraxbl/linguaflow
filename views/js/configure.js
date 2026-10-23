/**
 * LinguaFlow AI - settings page: provider defaults and model discovery.
 */
document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  var form = document.getElementById('lfai-config');
  if (!form) {
    return;
  }

  var defaults = JSON.parse(form.getAttribute('data-defaults'));
  var provider = document.getElementById('LFAI_PROVIDER');
  var apiKey = document.getElementById('LFAI_API_KEY');
  var baseUrl = document.getElementById('LFAI_BASE_URL');
  var modelInput = document.getElementById('LFAI_MODEL');
  var modelSelect = document.getElementById('LFAI_MODEL_SELECT');
  var status = document.getElementById('lfai-models-status');
  var sourceLang = document.getElementById('LFAI_SOURCE_LANG');

  function syncProvider() {
    var d = defaults[provider.value] || {};
    baseUrl.placeholder = d.base_url || 'https://…/v1';
    document.querySelectorAll('.lfai-anthropic-only').forEach(function (el) {
      el.style.display = provider.value === 'anthropic' ? '' : 'none';
    });
    if (!modelInput.value && d.model) {
      modelInput.value = d.model;
    }
  }

  function syncSourceLanguage() {
    document.querySelectorAll('.lfai-target').forEach(function (el) {
      var isSource = el.getAttribute('data-id-lang') === sourceLang.value;
      el.style.display = isSource ? 'none' : '';
      if (isSource) {
        el.querySelector('input').checked = false;
      }
    });
  }

  function showSelect(models) {
    modelSelect.innerHTML = '';
    var current = modelInput.value;
    var manual = document.createElement('option');
    manual.value = '__manual__';
    manual.textContent = '— type the model name —';
    modelSelect.appendChild(manual);
    models.forEach(function (id) {
      var opt = document.createElement('option');
      opt.value = id;
      opt.textContent = id;
      if (id === current) { opt.selected = true; }
      modelSelect.appendChild(opt);
    });
    if (models.indexOf(current) === -1 && models.length && !current) {
      modelSelect.selectedIndex = 1;
      modelInput.value = models[0];
    }
    modelSelect.style.display = '';
    modelInput.style.display = models.indexOf(modelInput.value) !== -1 ? 'none' : '';
  }

  modelSelect.addEventListener('change', function () {
    if (modelSelect.value === '__manual__') {
      modelInput.style.display = '';
      modelInput.focus();
    } else {
      modelInput.value = modelSelect.value;
      modelInput.style.display = 'none';
    }
  });

  document.getElementById('lfai-load-models').addEventListener('click', function () {
    var body = new FormData();
    body.append('provider', provider.value);
    body.append('api_key', apiKey.value);
    body.append('base_url', baseUrl.value);
    status.textContent = '…';
    fetch(form.getAttribute('data-models-url'), { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (r) {
        if (r.ok && r.models.length) {
          status.textContent = r.models.length + ' models found.';
          status.className = 'help-block text-success';
          showSelect(r.models);
        } else {
          status.textContent = (r.ok ? 'The provider returned no models.' : 'Could not list models: ' + r.error) + ' Type the model name manually.';
          status.className = 'help-block text-warning';
          modelSelect.style.display = 'none';
          modelInput.style.display = '';
        }
      })
      .catch(function (e) {
        status.textContent = 'Could not list models: ' + e + '. Type the model name manually.';
        status.className = 'help-block text-warning';
      });
  });

  provider.addEventListener('change', function () {
    modelInput.value = '';
    modelSelect.style.display = 'none';
    modelInput.style.display = '';
    syncProvider();
  });
  sourceLang.addEventListener('change', syncSourceLanguage);
  syncProvider();
  syncSourceLanguage();
});
