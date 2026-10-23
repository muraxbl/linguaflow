{**
 * LinguaFlow AI - glossary, translation memory and category hints
 *}
<div class="row">
  <div class="col-lg-8">
    <div class="panel">
      <div class="panel-heading">
        <i class="icon-book"></i> {l s='Glossary' mod='linguaflowai'} <span class="badge">{$lfai_total|intval}</span>
        <span class="panel-heading-action">
          <a href="{$lfai_url|escape:'htmlall':'UTF-8'}&amp;export=glossary" class="btn btn-default btn-xs"><i class="icon-download"></i> {l s='Export JSON' mod='linguaflowai'}</a>
        </span>
      </div>
      <p class="text-muted">
        {l s='Mandatory terminology. When a term (or its plural) appears in the text, its translation is given to the model as mandatory, and the review flags translations that do not use it. Only the terms present in each text are sent.' mod='linguaflowai'}
      </p>

      <form method="get" action="{$lfai_url|escape:'htmlall':'UTF-8'}" class="form-inline" style="margin-bottom:10px">
        <input type="hidden" name="controller" value="AdminLinguaFlowAIGlossary">
        <input type="hidden" name="token" value="{$lfai_token|escape:'htmlall':'UTF-8'}">
        <input type="text" name="q" value="{$lfai_query|escape:'htmlall':'UTF-8'}" class="form-control" placeholder="{l s='Search' mod='linguaflowai'}">
        <button class="btn btn-default"><i class="icon-search"></i></button>
      </form>

      <form method="post" action="{$lfai_url|escape:'htmlall':'UTF-8'}" class="lfai-term-form">
        <input type="hidden" name="id_term" value="{if $lfai_edit_term}{$lfai_edit_term.id_term|intval}{else}0{/if}">
        <table class="table">
          <thead>
            <tr>
              <th>{l s='Source term' mod='linguaflowai'}</th>
              {foreach from=$lfai_codes item=code}<th>{$code|escape:'htmlall':'UTF-8'}</th>{/foreach}
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr class="lfai-term-edit">
              <td><input type="text" name="term" class="form-control" required value="{if $lfai_edit_term}{$lfai_edit_term.term|escape:'htmlall':'UTF-8'}{/if}" placeholder="{l s='New term' mod='linguaflowai'}"></td>
              {foreach from=$lfai_codes item=code}
                <td><input type="text" name="tr[{$code|escape:'htmlall':'UTF-8'}]" class="form-control" value="{if $lfai_edit_term && isset($lfai_edit_term.translations[$code])}{$lfai_edit_term.translations[$code]|escape:'htmlall':'UTF-8'}{/if}"></td>
              {/foreach}
              <td class="text-right">
                <button type="submit" name="submitLfaiTerm" class="btn btn-primary btn-sm"><i class="icon-save"></i></button>
                {if $lfai_edit_term}<a href="{$lfai_url|escape:'htmlall':'UTF-8'}" class="btn btn-default btn-sm"><i class="icon-remove"></i></a>{/if}
              </td>
            </tr>
            {foreach from=$lfai_terms item=term}
              <tr>
                <td><strong>{$term.term|escape:'htmlall':'UTF-8'}</strong></td>
                {foreach from=$lfai_codes item=code}
                  <td>{if isset($term.translations[$code])}{$term.translations[$code]|escape:'htmlall':'UTF-8'}{else}<span class="text-muted">—</span>{/if}</td>
                {/foreach}
                <td class="text-right" style="white-space:nowrap">
                  <a href="{$lfai_url|escape:'htmlall':'UTF-8'}&amp;edit_term={$term.id_term|intval}&amp;q={$lfai_query|escape:'url'}&amp;page={$lfai_page|intval}" class="btn btn-default btn-xs"><i class="icon-pencil"></i></a>
                  <button type="submit" name="submitLfaiDeleteTerm" value="1" class="btn btn-default btn-xs" onclick="this.form.id_term.value='{$term.id_term|intval}'; this.form.term.required=false; return confirm('{l s='Delete this term?' mod='linguaflowai' js=1}')"><i class="icon-trash"></i></button>
                </td>
              </tr>
            {/foreach}
          </tbody>
        </table>
      </form>

      {if $lfai_pages > 1}
        <ul class="pagination">
          {for $p=1 to $lfai_pages}
            <li {if $p == $lfai_page}class="active"{/if}><a href="{$lfai_url|escape:'htmlall':'UTF-8'}&amp;page={$p}&amp;q={$lfai_query|escape:'url'}">{$p}</a></li>
          {/for}
        </ul>
      {/if}
    </div>
  </div>

  <div class="col-lg-4">
    <form method="post" action="{$lfai_url|escape:'htmlall':'UTF-8'}" enctype="multipart/form-data" class="panel">
      <div class="panel-heading"><i class="icon-upload"></i> {l s='Import glossary' mod='linguaflowai'}</div>
      <p class="text-muted">{l s='JSON file with the shape {"term": {"EN": "...", "FR": "..."}}.' mod='linguaflowai'}</p>
      <input type="file" name="glossary_file" accept=".json,application/json">
      <div class="checkbox"><label><input type="checkbox" name="replace" value="1"> {l s='Replace the whole glossary (otherwise merge)' mod='linguaflowai'}</label></div>
      <button type="submit" name="submitLfaiImportGlossary" class="btn btn-default"><i class="icon-upload"></i> {l s='Import' mod='linguaflowai'}</button>
    </form>

    <div class="panel">
      <div class="panel-heading"><i class="icon-archive"></i> {l s='Translation memory' mod='linguaflowai'}</div>
      <p>
        <strong>{$lfai_tm_stats.ok|intval}</strong> {l s='approved' mod='linguaflowai'} ·
        <strong>{$lfai_tm_stats.mt|intval}</strong> {l s='machine' mod='linguaflowai'}
      </p>
      <p class="text-muted">{l s='Approved entries are reused with any model. Machine entries are reused only with the same model, category hint and glossary terms.' mod='linguaflowai'}</p>
      <form method="post" action="{$lfai_url|escape:'htmlall':'UTF-8'}" enctype="multipart/form-data">
        <input type="file" name="tm_file" accept=".json,application/json">
        <button type="submit" name="submitLfaiImportTm" class="btn btn-default" style="margin-top:6px"><i class="icon-upload"></i> {l s='Import tm.json' mod='linguaflowai'}</button>
      </form>
      <hr>
      <form method="post" action="{$lfai_url|escape:'htmlall':'UTF-8'}" class="form-inline">
        <select name="status" class="form-control">
          <option value="mt">{l s='Machine entries' mod='linguaflowai'}</option>
          <option value="ok">{l s='Approved entries' mod='linguaflowai'}</option>
        </select>
        <button type="submit" name="submitLfaiPurgeTm" class="btn btn-default" onclick="return confirm('{l s='Delete these memory entries?' mod='linguaflowai' js=1}')"><i class="icon-trash"></i> {l s='Empty' mod='linguaflowai'}</button>
      </form>
    </div>

    <div class="panel">
      <div class="panel-heading"><i class="icon-sitemap"></i> {l s='Category hints' mod='linguaflowai'}</div>
      <p class="text-muted">{l s='Context given to the model for products whose default category (or a parent of it) has a hint. Example: "This is a waterproof light fixture: \'barra\' has integrated LED, \'pantalla\' uses T8 tubes."' mod='linguaflowai'}</p>
      {foreach from=$lfai_category_hints item=row}
        <p><strong>{$row.name|escape:'htmlall':'UTF-8'}</strong>: {$row.hint|escape:'htmlall':'UTF-8'}</p>
      {/foreach}
      <form method="post" action="{$lfai_url|escape:'htmlall':'UTF-8'}">
        <select name="id_category" class="form-control">
          {foreach from=$lfai_categories item=category}
            <option value="{$category.id_category|intval}">{$category.label|escape:'htmlall':'UTF-8'}</option>
          {/foreach}
        </select>
        <textarea name="hint" rows="3" class="form-control" style="margin-top:6px" placeholder="{l s='Context: this is a LED strip product...' mod='linguaflowai'}"></textarea>
        <p class="help-block">{l s='Save an empty hint to remove it.' mod='linguaflowai'}</p>
        <button type="submit" name="submitLfaiCategoryHint" class="btn btn-default"><i class="icon-save"></i> {l s='Save hint' mod='linguaflowai'}</button>
      </form>
    </div>

    <a href="{$lfai_jobs_url|escape:'htmlall':'UTF-8'}" class="btn btn-default"><i class="icon-arrow-left"></i> {l s='Translation jobs' mod='linguaflowai'}</a>
  </div>
</div>
