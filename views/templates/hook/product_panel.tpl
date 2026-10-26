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
  <label>{l s='Shops' mod='linguaflowai'}</label>
  <select class="form-control lfai-shop-scope">
    <option value="context" {if $lfai_shop_scope == 'context'}selected{/if}>{l s='Current shop context' mod='linguaflowai'}</option>
    <option value="all" {if $lfai_shop_scope == 'all'}selected{/if}>{l s='All authorized shops (both shops when there are two)' mod='linguaflowai'}</option>
  </select>
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
