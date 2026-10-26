{**
 * LinguaFlow AI - translations held for review
 *}
<div class="panel">
  <div class="panel-heading">
    <i class="icon-eye-open"></i> {l s='Translations to review' mod='linguaflowai'} <span class="badge">{$lfai_total|intval}</span>
    <a href="{$lfai_url|escape:'htmlall':'UTF-8'}" class="pull-right">{l s='Back to jobs' mod='linguaflowai'}</a>
  </div>
  <p class="text-muted">
    {l s='These translations were not saved to the product because the automatic checks found something to look at. Edit them if needed and approve: they are saved to the product and to the translation memory, so the same text is reused next time.' mod='linguaflowai'}
  </p>

  {foreach from=$lfai_items item=item}
    <form method="post" action="{$lfai_url|escape:'htmlall':'UTF-8'}&amp;view=review&amp;page={$lfai_page|intval}" class="lfai-review-item">
      <input type="hidden" name="id_item" value="{$item.id_item|intval}">
      <h4>
        <a href="{$item.product_url|escape:'htmlall':'UTF-8'}" target="_blank">#{$item.id_product|intval} {$item.product_name|escape:'htmlall':'UTF-8'}</a>
        → <span class="label label-info">{$item.lang_name|escape:'htmlall':'UTF-8'}</span>
        <small>{l s='Shop' mod='linguaflowai'} #{$item.id_shop|intval}</small>
      </h4>

      {foreach from=$item.payload.fields key=field item=data}
        <div class="row lfai-review-field {if $data.issues}lfai-has-issues{/if}">
          <div class="col-md-2">
            <strong>{$data.label|escape:'htmlall':'UTF-8'}</strong>
            {if $data.from == 'memory'}<br><span class="label label-success">{l s='memory' mod='linguaflowai'}</span>{/if}
            {foreach from=$data.issues item=issue}
              <div class="lfai-issue"><i class="icon-warning"></i> {$issue|escape:'htmlall':'UTF-8'}</div>
            {/foreach}
          </div>
          <div class="col-md-5">
            <div class="lfai-source">{if $data.html}{$data.source|strip_tags:false|escape:'htmlall':'UTF-8'}{else}{$data.source|escape:'htmlall':'UTF-8'}{/if}</div>
          </div>
          <div class="col-md-5">
            <textarea name="outputs[{$field|escape:'htmlall':'UTF-8'}]" class="form-control" rows="{if $data.html}8{else}2{/if}">{$data.output|escape:'htmlall':'UTF-8'}</textarea>
          </div>
        </div>
      {/foreach}

      <div class="text-right">
        <button type="submit" name="submitLfaiReject" class="btn btn-default"><i class="icon-remove"></i> {l s='Discard' mod='linguaflowai'}</button>
        <button type="submit" name="submitLfaiRegenerate" class="btn btn-default"><i class="icon-refresh"></i> {l s='Translate again' mod='linguaflowai'}</button>
        <button type="submit" name="submitLfaiApprove" class="btn btn-primary"><i class="icon-check"></i> {l s='Approve and save' mod='linguaflowai'}</button>
      </div>
    </form>
  {foreachelse}
    <p class="text-center text-muted">{l s='Nothing to review.' mod='linguaflowai'}</p>
  {/foreach}

  {if $lfai_pages > 1}
    <ul class="pagination">
      {for $p=1 to $lfai_pages}
        <li {if $p == $lfai_page}class="active"{/if}><a href="{$lfai_url|escape:'htmlall':'UTF-8'}&amp;view=review&amp;page={$p}">{$p}</a></li>
      {/for}
    </ul>
  {/if}
</div>
