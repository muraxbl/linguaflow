{**
 * LinguaFlow AI - panel in the product page "Modules" tab
 *}
<div class="lfai-product-panel" data-url="{$lfai_enqueue_url|escape:'htmlall':'UTF-8'}" data-id-product="{$lfai_id_product|intval}">
  <h3>LinguaFlow AI</h3>
  {if !$lfai_configured}
    <p class="alert alert-warning">
      {l s='No target language is enabled yet.' mod='linguaflowai'}
      <a href="{$lfai_config_url|escape:'htmlall':'UTF-8'}">{l s='Open the settings' mod='linguaflowai'}</a>
    </p>
  {/if}
  <p>{l s='Save the product first, then queue its translation:' mod='linguaflowai'}</p>
  {foreach from=$lfai_languages item=lang}
    <label class="lfai-lang">
      <input type="checkbox" class="lfai-lang-input" value="{$lang.id_lang|intval}" {if $lang.lfai_enabled}checked{/if}> {$lang.name|escape:'htmlall':'UTF-8'}
    </label>
  {/foreach}
  <div>
    <label class="lfai-lang"><input type="checkbox" class="lfai-overwrite"> {l s='Overwrite existing translations' mod='linguaflowai'}</label>
  </div>
  <button type="button" class="btn btn-primary lfai-translate-now">{l s='Translate with AI' mod='linguaflowai'}</button>
  <span class="lfai-product-result"></span>
</div>
