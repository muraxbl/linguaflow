{**
 * LinguaFlow AI - settings page
 *}
<form method="post" action="{$lfai_action|escape:'htmlall':'UTF-8'}" id="lfai-config" class="form-horizontal"
      data-models-url="{$lfai_models_url|escape:'htmlall':'UTF-8'}"
      data-saved-provider="{$lfai_provider|escape:'htmlall':'UTF-8'}"
      data-defaults="{$lfai_provider_defaults|escape:'htmlall':'UTF-8'}">

  <div class="panel">
    <div class="panel-heading"><i class="icon-cogs"></i> {l s='AI provider' mod='linguaflowai'}</div>

    <div class="form-group">
      <label class="control-label col-lg-3" for="LFAI_PROVIDER">{l s='Provider' mod='linguaflowai'}</label>
      <div class="col-lg-6">
        <select name="LFAI_PROVIDER" id="LFAI_PROVIDER" class="form-control">
          {foreach from=$lfai_providers key=key item=label}
            <option value="{$key|escape:'htmlall':'UTF-8'}" {if $key == $lfai_provider}selected{/if}>{$label|escape:'htmlall':'UTF-8'}</option>
          {/foreach}
        </select>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3" for="LFAI_API_KEY">{l s='API key' mod='linguaflowai'}</label>
      <div class="col-lg-6">
        <input type="password" name="LFAI_API_KEY" id="LFAI_API_KEY" class="form-control" autocomplete="new-password"
               placeholder="{if $lfai_has_key}{l s='Saved - leave empty to keep it' mod='linguaflowai'}{else}{l s='Paste your API key' mod='linguaflowai'}{/if}">
        {if $lfai_has_key}
          <div class="checkbox"><label><input type="checkbox" name="LFAI_API_KEY_CLEAR" value="1"> {l s='Remove the saved key' mod='linguaflowai'}</label></div>
        {/if}
        <p class="help-block lfai-key-help">{l s='Optional for local Ollama and for OpenAI compatible servers that do not require authentication.' mod='linguaflowai'}</p>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3" for="LFAI_BASE_URL">{l s='Base URL' mod='linguaflowai'}</label>
      <div class="col-lg-6">
        <input type="text" name="LFAI_BASE_URL" id="LFAI_BASE_URL" class="form-control" value="{$lfai_base_url|escape:'htmlall':'UTF-8'}">
        <p class="help-block">{l s='Leave empty to use the provider default (shown as placeholder). Required for OpenAI compatible providers, e.g. https://openrouter.ai/api/v1' mod='linguaflowai'}</p>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3" for="LFAI_MODEL">{l s='Model' mod='linguaflowai'}</label>
      <div class="col-lg-6">
        <div class="input-group">
          <select id="LFAI_MODEL_SELECT" class="form-control" style="display:none"></select>
          <input type="text" name="LFAI_MODEL" id="LFAI_MODEL" class="form-control" value="{$lfai_model|escape:'htmlall':'UTF-8'}" placeholder="{l s='Model name' mod='linguaflowai'}">
          <span class="input-group-btn">
            <button type="button" class="btn btn-default" id="lfai-load-models"><i class="icon-refresh"></i> {l s='Load models' mod='linguaflowai'}</button>
          </span>
        </div>
        <p class="help-block" id="lfai-models-status">{l s='Save the provider and URL before loading models. You can choose the model after saving.' mod='linguaflowai'}</p>
      </div>
    </div>

    <div class="form-group lfai-anthropic-only">
      <label class="control-label col-lg-3" for="LFAI_EFFORT">{l s='Effort' mod='linguaflowai'}</label>
      <div class="col-lg-6">
        <select name="LFAI_EFFORT" id="LFAI_EFFORT" class="form-control">
          {foreach from=['low','medium','high','xhigh','max'] item=level}
            <option value="{$level}" {if $level == $lfai_effort}selected{/if}>{$level}</option>
          {/foreach}
        </select>
        <p class="help-block">{l s='Anthropic only. Lower effort is faster and cheaper; higher effort is more careful.' mod='linguaflowai'}</p>
      </div>
    </div>

    <div class="form-group lfai-ollama-only">
      <label class="control-label col-lg-3">{l s='Ollama options' mod='linguaflowai'}</label>
      <div class="col-lg-6">
        <div class="checkbox"><label><input type="checkbox" name="LFAI_DISABLE_THINKING" value="1" {if $lfai_disable_thinking}checked{/if}> {l s='Disable thinking (think=false) for reasoning models such as Gemma 4 or Qwen 3: it only adds time when translating' mod='linguaflowai'}</label></div>
        <div class="input-group" style="max-width:320px">
          <span class="input-group-addon">num_ctx</span>
          <input type="number" min="0" name="LFAI_CONTEXT_SIZE" class="form-control" value="{$lfai_context_size|intval}">
        </div>
        <p class="help-block">{l s='0 = model default. Use the same value as the loaded model (e.g. 4096) so Ollama does not reload it.' mod='linguaflowai'}</p>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3" for="LFAI_TIMEOUT">{l s='Request timeout (s)' mod='linguaflowai'}</label>
      <div class="col-lg-2">
        <input type="number" min="10" max="600" name="LFAI_TIMEOUT" id="LFAI_TIMEOUT" class="form-control" value="{$lfai_timeout|intval}">
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-heading"><i class="icon-flag"></i> {l s='Languages and content' mod='linguaflowai'}</div>

    <div class="form-group">
      <label class="control-label col-lg-3" for="LFAI_SOURCE_LANG">{l s='Source language' mod='linguaflowai'}</label>
      <div class="col-lg-4">
        <select name="LFAI_SOURCE_LANG" id="LFAI_SOURCE_LANG" class="form-control">
          {foreach from=$lfai_languages item=lang}
            <option value="{$lang.id_lang|intval}" {if $lang.id_lang == $lfai_source_lang}selected{/if}>{$lang.name|escape:'htmlall':'UTF-8'}</option>
          {/foreach}
        </select>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3">{l s='Translate into' mod='linguaflowai'}</label>
      <div class="col-lg-9">
        {foreach from=$lfai_languages item=lang}
          <div class="checkbox lfai-target" data-id-lang="{$lang.id_lang|intval}">
            <label>
              <input type="checkbox" name="LFAI_TARGET_LANGS[]" value="{$lang.id_lang|intval}" {if in_array($lang.id_lang, $lfai_target_langs)}checked{/if}>
              {$lang.name|escape:'htmlall':'UTF-8'} <span class="text-muted">({$lang.iso_code|escape:'htmlall':'UTF-8'})</span>
              {if !$lang.active}<span class="label label-default">{l s='inactive' mod='linguaflowai'}</span>{/if}
            </label>
          </div>
        {/foreach}
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3">{l s='Fields' mod='linguaflowai'}</label>
      <div class="col-lg-9">
        {foreach from=$lfai_all_fields key=field item=label}
          <div class="checkbox"><label><input type="checkbox" name="LFAI_FIELDS[]" value="{$field|escape:'htmlall':'UTF-8'}" {if in_array($field, $lfai_fields)}checked{/if}> {$label|escape:'htmlall':'UTF-8'}</label></div>
        {/foreach}
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3">{l s='Options' mod='linguaflowai'}</label>
      <div class="col-lg-9">
        <div class="checkbox"><label><input type="checkbox" name="LFAI_OVERWRITE" value="1" {if $lfai_overwrite}checked{/if}> {l s='Overwrite existing translations (otherwise only empty fields and fields still identical to the source are translated)' mod='linguaflowai'}</label></div>
        <div class="checkbox"><label><input type="checkbox" name="LFAI_SLUG" value="1" {if $lfai_slug}checked{/if}> {l s='Generate the friendly URL from the translated name' mod='linguaflowai'}</label></div>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3">{l s='Style per language' mod='linguaflowai'}</label>
      <div class="col-lg-6">
        {foreach from=$lfai_style_languages item=row}
          <label for="lfai-style-{$row.code|escape:'htmlall':'UTF-8'}">{$row.name|escape:'htmlall':'UTF-8'} <span class="text-muted">({$row.code|escape:'htmlall':'UTF-8'})</span></label>
          <textarea name="LFAI_LANG_STYLES[{$row.code|escape:'htmlall':'UTF-8'}]" id="lfai-style-{$row.code|escape:'htmlall':'UTF-8'}" rows="2" class="form-control" style="margin-bottom:8px">{$row.style|escape:'htmlall':'UTF-8'}</textarea>
        {/foreach}
        <p class="help-block">{l s='Variant and register of each language, e.g. "Write in European Portuguese, never Brazilian. Formal tone."' mod='linguaflowai'}</p>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3">{l s='Glossary' mod='linguaflowai'}</label>
      <div class="col-lg-6">
        <a href="{$lfai_glossary_url|escape:'htmlall':'UTF-8'}" class="btn btn-default"><i class="icon-book"></i> {l s='Glossary, translation memory and category hints' mod='linguaflowai'}</a>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3" for="LFAI_INSTRUCTIONS">{l s='Style instructions' mod='linguaflowai'}</label>
      <div class="col-lg-6">
        <textarea name="LFAI_INSTRUCTIONS" id="LFAI_INSTRUCTIONS" rows="4" class="form-control" placeholder="{l s='e.g. Informal tone, address the customer as tú in Spanish. Use British English.' mod='linguaflowai'}">{$lfai_instructions|escape:'htmlall':'UTF-8'}</textarea>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3" for="LFAI_DO_NOT_TRANSLATE">{l s='Protected brands' mod='linguaflowai'}</label>
      <div class="col-lg-6">
        <textarea name="LFAI_DO_NOT_TRANSLATE" id="LFAI_DO_NOT_TRANSLATE" rows="4" class="form-control" placeholder="{l s='Brand names, one per line or comma separated' mod='linguaflowai'}">{$lfai_do_not_translate|escape:'htmlall':'UTF-8'}</textarea>
        <div class="checkbox"><label><input type="checkbox" name="LFAI_PROTECT_MANUFACTURERS" value="1" {if $lfai_protect_manufacturers}checked{/if}> {l s='Also protect the manufacturer of each product' mod='linguaflowai'}</label></div>
        <p class="help-block">{l s='Never translated, and a translation that loses one of them is asked again.' mod='linguaflowai'}</p>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3" for="LFAI_PROTECTED_PATTERNS">{l s='Protected codes' mod='linguaflowai'}</label>
      <div class="col-lg-6">
        <textarea name="LFAI_PROTECTED_PATTERNS" id="LFAI_PROTECTED_PATTERNS" rows="4" class="form-control" style="font-family:monospace">{$lfai_protected_patterns|escape:'htmlall':'UTF-8'}</textarea>
        <p class="help-block">{l s='Regular expressions, one per line (e.g. IP ratings, lamp sockets, LED chip codes). Figures with units are not protected: the decimal separator must be adapted.' mod='linguaflowai'}</p>
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-heading"><i class="icon-time"></i> {l s='Automation and batches' mod='linguaflowai'}</div>

    <div class="form-group">
      <label class="control-label col-lg-3" for="LFAI_SHOP_SCOPE">{l s='Shops' mod='linguaflowai'}</label>
      <div class="col-lg-6">
        <select name="LFAI_SHOP_SCOPE" id="LFAI_SHOP_SCOPE" class="form-control">
          <option value="context" {if $lfai_shop_scope == 'context'}selected{/if}>{l s='Current shop context' mod='linguaflowai'}</option>
          <option value="all" {if $lfai_shop_scope == 'all'}selected{/if}>{l s='All authorized shops (both shops when there are two)' mod='linguaflowai'}</option>
        </select>
        <p class="help-block">{l s='Default for automatic translations and product list bulk actions. Manual jobs and the product panel can override it. Each shop is translated separately.' mod='linguaflowai'}</p>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3">{l s='New products' mod='linguaflowai'}</label>
      <div class="col-lg-9">
        <div class="checkbox"><label><input type="checkbox" name="LFAI_AUTO_NEW" value="1" {if $lfai_auto_new}checked{/if}> {l s='Translate every new product automatically into the enabled languages' mod='linguaflowai'}</label></div>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3" for="LFAI_AUTO_DELAY">{l s='Wait before translating (minutes)' mod='linguaflowai'}</label>
      <div class="col-lg-2">
        <input type="number" min="0" max="1440" name="LFAI_AUTO_DELAY" id="LFAI_AUTO_DELAY" class="form-control" value="{$lfai_auto_delay|intval}">
      </div>
      <div class="col-lg-7"><p class="help-block">{l s='Gives you time to finish writing the product before it is translated.' mod='linguaflowai'}</p></div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3" for="LFAI_BATCH_SIZE">{l s='Items per batch' mod='linguaflowai'}</label>
      <div class="col-lg-2">
        <input type="number" min="1" max="50" name="LFAI_BATCH_SIZE" id="LFAI_BATCH_SIZE" class="form-control" value="{$lfai_batch_size|intval}">
      </div>
      <div class="col-lg-7"><p class="help-block">{l s='Product x language translations processed per back office tick (each tick stops after about 20 seconds).' mod='linguaflowai'}</p></div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3">{l s='Processing' mod='linguaflowai'}</label>
      <div class="col-lg-9">
        <div class="checkbox"><label><input type="checkbox" name="LFAI_BO_WORKER" value="1" {if $lfai_bo_worker}checked{/if}> {l s='Process the queue while any back office page is open' mod='linguaflowai'}</label></div>
        <p class="help-block">{l s='For processing without the back office open, call this URL from a cron job every few minutes:' mod='linguaflowai'}</p>
        <input type="text" readonly class="form-control" value="{$lfai_cron_url|escape:'htmlall':'UTF-8'}" onclick="this.select()">
        <div class="checkbox"><label><input type="checkbox" name="LFAI_REGENERATE_TOKEN" value="1"> {l s='Generate a new cron token' mod='linguaflowai'}</label></div>
      </div>
    </div>

    <div class="form-group">
      <label class="control-label col-lg-3">{l s='Notifications' mod='linguaflowai'}</label>
      <div class="col-lg-9">
        <button type="button" class="btn btn-default" id="lfai-enable-notifications"><i class="icon-bell"></i> {l s='Enable browser notifications' mod='linguaflowai'}</button>
        <p class="help-block">{l s='A toast is always shown in the back office when a batch finishes. Native browser notifications also reach you when the tab is in the background.' mod='linguaflowai'}</p>
      </div>
    </div>

    <div class="panel-footer">
      <button type="submit" name="submitLinguaFlowAI" class="btn btn-default pull-right"><i class="process-icon-save"></i> {l s='Save' mod='linguaflowai'}</button>
      <a href="{$lfai_dashboard_url|escape:'htmlall':'UTF-8'}" class="btn btn-default"><i class="process-icon-stats"></i> {l s='Translation jobs' mod='linguaflowai'}</a>
    </div>
  </div>
</form>
