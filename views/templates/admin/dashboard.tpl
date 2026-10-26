{**
 * LinguaFlow AI - jobs dashboard and mass translation
 *}
{if !$lfai_configured}
  <div class="alert alert-warning">
    {l s='No target language is enabled yet.' mod='linguaflowai'}
    <a href="{$lfai_config_url|escape:'htmlall':'UTF-8'}">{l s='Open the settings' mod='linguaflowai'}</a>
  </div>
{/if}

<p>
  <a href="{$lfai_url|escape:'htmlall':'UTF-8'}&amp;view=review" class="btn btn-{if $lfai_review_count > 0}warning{else}default{/if}"><i class="icon-eye-open"></i> {l s='Review' mod='linguaflowai'} <span class="badge">{$lfai_review_count|intval}</span></a>
  <a href="{$lfai_glossary_url|escape:'htmlall':'UTF-8'}" class="btn btn-default"><i class="icon-book"></i> {l s='Glossary and memory' mod='linguaflowai'}</a>
</p>

<div class="row">
  <div class="col-lg-5">
    <form method="post" action="{$lfai_url|escape:'htmlall':'UTF-8'}" class="panel">
      <div class="panel-heading"><i class="icon-globe"></i> {l s='Mass translation' mod='linguaflowai'}</div>
      <p class="text-muted">
        {l s='Provider' mod='linguaflowai'}: <strong>{$lfai_provider_label|escape:'htmlall':'UTF-8'}</strong> ·
        {l s='Model' mod='linguaflowai'}: <strong>{$lfai_model|escape:'htmlall':'UTF-8'}</strong>
        <a href="{$lfai_config_url|escape:'htmlall':'UTF-8'}" class="pull-right">{l s='Settings' mod='linguaflowai'}</a>
      </p>

      <div class="form-group">
        <label>{l s='Shops' mod='linguaflowai'}</label>
        <select name="shop_scope" class="form-control">
          <option value="context" {if $lfai_shop_scope == 'context'}selected{/if}>{l s='Current shop context' mod='linguaflowai'}</option>
          <option value="all" {if $lfai_shop_scope == 'all'}selected{/if}>{l s='All authorized shops (both shops when there are two)' mod='linguaflowai'}</option>
        </select>
      </div>
      <div class="form-group">
        <label>{l s='Products' mod='linguaflowai'}</label>
        <div class="radio"><label><input type="radio" name="lfai_scope" value="missing" checked> {l s='Products not translated yet (empty or identical to the source)' mod='linguaflowai'}</label></div>
        <div class="radio"><label><input type="radio" name="lfai_scope" value="category"> {l s='Products in a category' mod='linguaflowai'}</label></div>
        <div class="lfai-scope-extra" data-scope="category">
          <select name="lfai_category" class="form-control">
            {foreach from=$lfai_categories item=category}
              <option value="{$category.id_category|intval}">{$category.label|escape:'htmlall':'UTF-8'}</option>
            {/foreach}
          </select>
          <div class="checkbox"><label><input type="checkbox" name="lfai_subcategories" value="1" checked> {l s='Include subcategories' mod='linguaflowai'}</label></div>
        </div>
        <div class="radio"><label><input type="radio" name="lfai_scope" value="ids"> {l s='Product IDs' mod='linguaflowai'}</label></div>
        <div class="lfai-scope-extra" data-scope="ids">
          <textarea name="lfai_ids" rows="2" class="form-control" placeholder="12, 15, 18-20"></textarea>
        </div>
        <div class="radio"><label><input type="radio" name="lfai_scope" value="all"> {l s='Whole catalog' mod='linguaflowai'}</label></div>
        <div class="checkbox"><label><input type="checkbox" name="lfai_active_only" value="1"> {l s='Only active products' mod='linguaflowai'}</label></div>
      </div>

      <div class="form-group">
        <label>{l s='Languages' mod='linguaflowai'}</label>
        {foreach from=$lfai_languages item=lang}
          <div class="checkbox"><label><input type="checkbox" name="lfai_langs[]" value="{$lang.id_lang|intval}" {if $lang.lfai_enabled}checked{/if}> {$lang.name|escape:'htmlall':'UTF-8'}</label></div>
        {/foreach}
      </div>

      <div class="form-group">
        <div class="checkbox"><label><input type="checkbox" name="lfai_overwrite" value="1" {if $lfai_overwrite}checked{/if}> {l s='Overwrite existing translations' mod='linguaflowai'}</label></div>
      </div>

      <div class="form-group">
        <label for="lfai_label">{l s='Job name (optional)' mod='linguaflowai'}</label>
        <input type="text" id="lfai_label" name="lfai_label" class="form-control" maxlength="255">
      </div>

      <div class="panel-footer">
        <button type="submit" name="submitLfaiCreateJob" class="btn btn-primary pull-right"><i class="icon-play"></i> {l s='Create job' mod='linguaflowai'}</button>
      </div>
    </form>
  </div>

  <div class="col-lg-7">
    <div class="panel">
      <div class="panel-heading"><i class="icon-tasks"></i> {l s='Translation jobs' mod='linguaflowai'}</div>
      {if !$lfai_bo_worker}
        <p class="alert alert-info">{l s='The back office worker is disabled: jobs only advance through the cron URL.' mod='linguaflowai'}</p>
      {/if}
      <table class="table" id="lfai-jobs">
        <thead>
          <tr>
            <th>#</th>
            <th>{l s='Job' mod='linguaflowai'}</th>
            <th>{l s='Progress' mod='linguaflowai'}</th>
            <th>{l s='Status' mod='linguaflowai'}</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          {foreach from=$lfai_jobs item=job}
            <tr data-id-job="{$job.id_job|intval}">
              <td>{$job.id_job|intval}</td>
              <td>
                {$job.label|escape:'htmlall':'UTF-8'}<br>
                <small>{l s='Shop' mod='linguaflowai'} #{$job.id_shop|intval}</small><br>
                <small class="text-muted">{$job.type|escape:'htmlall':'UTF-8'} · {$job.date_add|escape:'htmlall':'UTF-8'}</small>
              </td>
              <td style="min-width:160px">
                <div class="progress" style="margin-bottom:4px">
                  <div class="progress-bar {if $job.failed > 0}progress-bar-warning{else}progress-bar-success{/if}" style="width: {$job.pct|intval}%"></div>
                </div>
                <small class="lfai-counts">{$job.done|intval} / {$job.total|intval}{if $job.review > 0} · <span class="text-warning">{$job.review|intval} {l s='to review' mod='linguaflowai'}</span>{/if}{if $job.failed > 0} · <span class="text-danger">{$job.failed|intval} {l s='failed' mod='linguaflowai'}</span>{/if}</small>
                {if $job.waiting && $job.next_at}<br><small class="text-muted"><i class="icon-time"></i> {l s='Next item at' mod='linguaflowai'} {$job.next_at|escape:'htmlall':'UTF-8'}</small>{/if}
                {if $job.last_error}<br><small class="text-warning">{$job.last_error|escape:'htmlall':'UTF-8'|truncate:160}</small>{/if}
              </td>
              <td><span class="label label-{if $job.status == 'completed'}success{elseif $job.status == 'running'}info{elseif $job.status == 'cancelled'}default{else}warning{/if}">{$job.status|escape:'htmlall':'UTF-8'}</span></td>
              <td class="text-right">
                <form method="post" action="{$lfai_url|escape:'htmlall':'UTF-8'}" style="display:inline">
                  <input type="hidden" name="id_job" value="{$job.id_job|intval}">
                  {if $job.failed > 0}
                    <a class="btn btn-default btn-xs" href="{$lfai_url|escape:'htmlall':'UTF-8'}&view_job={$job.id_job|intval}">{l s='Errors' mod='linguaflowai'}</a>
                    <button class="btn btn-default btn-xs" name="submitLfaiRetry">{l s='Retry' mod='linguaflowai'}</button>
                  {/if}
                  {if $job.status == 'queued' || $job.status == 'running'}
                    <button class="btn btn-default btn-xs" name="submitLfaiCancel">{l s='Cancel' mod='linguaflowai'}</button>
                  {else}
                    <button class="btn btn-default btn-xs" name="submitLfaiDelete" onclick="return confirm('{l s='Delete this job?' mod='linguaflowai' js=1}')"><i class="icon-trash"></i></button>
                  {/if}
                </form>
              </td>
            </tr>
          {foreachelse}
            <tr><td colspan="5" class="text-center text-muted">{l s='No jobs yet.' mod='linguaflowai'}</td></tr>
          {/foreach}
        </tbody>
      </table>
    </div>

    {if $lfai_view_job}
      <div class="panel">
        <div class="panel-heading"><i class="icon-warning"></i> {l s='Failed translations of job' mod='linguaflowai'} #{$lfai_view_job.id_job|intval}</div>
        <table class="table">
          <thead><tr><th>{l s='Product' mod='linguaflowai'}</th><th>{l s='Language' mod='linguaflowai'}</th><th>{l s='Attempts' mod='linguaflowai'}</th><th>{l s='Error' mod='linguaflowai'}</th></tr></thead>
          <tbody>
            {foreach from=$lfai_failed_items item=item}
              <tr>
                <td>#{$item.id_product|intval} {$item.product_name|escape:'htmlall':'UTF-8'}</td>
                <td>{$item.iso_code|escape:'htmlall':'UTF-8'}</td>
                <td>{$item.attempts|intval}</td>
                <td><small>{$item.error|escape:'htmlall':'UTF-8'}</small></td>
              </tr>
            {/foreach}
          </tbody>
        </table>
      </div>
    {/if}
  </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    var radios = document.querySelectorAll('input[name="lfai_scope"]');
    function sync() {
      var current = document.querySelector('input[name="lfai_scope"]:checked').value;
      document.querySelectorAll('.lfai-scope-extra').forEach(function (el) {
        el.style.display = el.getAttribute('data-scope') === current ? '' : 'none';
      });
    }
    radios.forEach(function (r) { r.addEventListener('change', sync); });
    sync();
  });
</script>
