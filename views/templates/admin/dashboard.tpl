{**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<div class="ovebotai-wrap">

  {include file="{$ove_tpl_dir}_header.tpl"}

  {if $ove_unreachable}
    <div class="ovebotai-notice ovebotai-notice-warning ovebotai-notice-titled">
      <div class="ovebotai-notice-content">
        <span class="ovebotai-notice-title">{l s="Couldn't reach Ovebot.ai right now." d='Modules.Ovebotai.Admin'}</span>
        <p>{l s='Your store stays connected - figures below may be out of date.' d='Modules.Ovebotai.Admin'}</p>
      </div>
    </div>
  {/if}

  <div class="ovebotai-dashboard-cards">
    {if $ove_account_url}
      <a href="{$ove_account_url|escape:'htmlall':'UTF-8'}" target="_blank" rel="noopener noreferrer" class="ovebotai-dash-card">
        <span class="ovebotai-dash-card-icon"><i class="material-icons">open_in_new</i></span>
        <span class="ovebotai-dash-card-title">{l s='Ovebot.ai account' d='Modules.Ovebotai.Admin'}</span>
      </a>
    {/if}
    <a href="{$ove_settings_url|escape:'htmlall':'UTF-8'}" class="ovebotai-dash-card">
      <span class="ovebotai-dash-card-icon"><i class="material-icons">settings</i></span>
      <span class="ovebotai-dash-card-title">{l s='Go to settings' d='Modules.Ovebotai.Admin'}</span>
    </a>
    {if $ove_chat_status}
      <a href="{$ove_chat_url|escape:'htmlall':'UTF-8'}" target="_blank" rel="noopener noreferrer" class="ovebotai-dash-card">
        <span class="ovebotai-dash-card-icon"><i class="material-icons">chat</i></span>
        <span class="ovebotai-dash-card-title">{l s='Chat with the AI agent' d='Modules.Ovebotai.Admin'} &rarr;</span>
      </a>
    {else}
      <a href="{$ove_chat_highlight_url|escape:'htmlall':'UTF-8'}" class="ovebotai-dash-card ovebotai-dash-card-muted">
        <span class="ovebotai-dash-card-icon"><i class="material-icons">chat</i></span>
        <span class="ovebotai-dash-card-title">{l s='Chat is disabled, enable it in settings' d='Modules.Ovebotai.Admin'} &rarr;</span>
      </a>
    {/if}
  </div>

  {if $ove_setup_url}
    <a href="{$ove_setup_url|escape:'htmlall':'UTF-8'}" target="_blank" rel="noopener noreferrer" class="ovebotai-advanced-panel">
      <span class="ovebotai-advanced-icon"><i class="material-icons">tune</i></span>
      <span class="ovebotai-advanced-body">
        <span class="ovebotai-advanced-title">{l s='Looking for advanced settings?' d='Modules.Ovebotai.Admin'}</span>
        <span class="ovebotai-advanced-desc">{l s="Fine-tune your AI agent right from your Ovebot.ai account - that's where the advanced options and customizations live." d='Modules.Ovebotai.Admin'}</span>
      </span>
      <span class="ovebotai-advanced-cta">{l s='Open account settings' d='Modules.Ovebotai.Admin'} &rarr;</span>
    </a>
  {/if}

  {if $ove_products_recommend}
    <div class="ovebotai-dash-card-wide">
      <span class="ovebotai-dash-card-wide-label">
        <i class="material-icons">shopping_cart</i>
        {l s='Products indexed by the AI agent' d='Modules.Ovebotai.Admin'}
      </span>
      {if $ove_products_url}
        <a class="ovebotai-dash-card-wide-count {if $ove_products_count > 0}is-positive{else}is-zero{/if}" href="{$ove_products_url|escape:'htmlall':'UTF-8'}" target="_blank" rel="noopener noreferrer">{$ove_products_count_label|escape:'htmlall':'UTF-8'}</a>
      {else}
        <span class="ovebotai-dash-card-wide-count {if $ove_products_count > 0}is-positive{else}is-zero{/if}">{$ove_products_count_label|escape:'htmlall':'UTF-8'}</span>
      {/if}
    </div>
  {else}
    <div class="ovebotai-notice ovebotai-notice-warning ovebotai-notice-titled">
      <div class="ovebotai-notice-content">
        <span class="ovebotai-notice-title">{l s='Product recommendations are turned off' d='Modules.Ovebotai.Admin'}</span>
        <p>{$ove_products_off_html nofilter}</p>
      </div>
    </div>
  {/if}

  {if !$ove_order_enabled}
    <div class="ovebotai-notice ovebotai-notice-warning ovebotai-notice-titled">
      <div class="ovebotai-notice-content">
        <span class="ovebotai-notice-title">{l s='Order tracking is turned off' d='Modules.Ovebotai.Admin'}</span>
        <p>{$ove_order_off_html nofilter}</p>
      </div>
    </div>
  {/if}

  <div class="ovebotai-fieldset">
    <div class="ovebotai-fieldset-legend">
      <i class="material-icons">menu_book</i>
      {l s='Knowledge Bases' d='Modules.Ovebotai.Admin'}
    </div>
    <div class="ovebotai-fieldset-body">

      <p class="ovebotai-muted ovebotai-fieldset-intro">{l s='These are the resources your AI agent uses to answer customer questions in the chat. Editing one of the selected pages in PrestaShop updates its entry here automatically - or use "Edit on Ovebot.ai" below to change it directly.' d='Modules.Ovebotai.Admin'}</p>

      {if $ove_kb_error}
        <div class="ovebotai-notice ovebotai-notice-warning"><p>{l s='Could not load knowledge base entries from Ovebot.ai.' d='Modules.Ovebotai.Admin'}</p></div>
      {elseif !$ove_kb_entries}
        <p class="ovebotai-muted">{$ove_kb_none_html nofilter}</p>
      {else}
        <p class="ovebotai-muted ovebotai-kb-summary">{$ove_kb_summary|escape:'htmlall':'UTF-8'}</p>
        <div class="ovebotai-pages-list ovebotai-kb-list">
          {foreach $ove_kb_entries as $ove_entry}
            <div class="ovebotai-page-item">
              <div class="ovebotai-page-info">
                <span class="ovebotai-page-title">{$ove_entry.title|escape:'htmlall':'UTF-8'}</span>
              </div>
              <div class="ovebotai-kb-item-actions">
                {if $ove_entry.is_active}
                  <span class="ovebotai-status-badge is-active">{l s='Active' d='Modules.Ovebotai.Admin'}</span>
                {else}
                  <span class="ovebotai-status-badge is-inactive">{l s='Inactive' d='Modules.Ovebotai.Admin'}</span>
                {/if}
                <a href="{$ove_entry.edit_url|escape:'htmlall':'UTF-8'}" target="_blank" rel="noopener noreferrer" class="ovebotai-page-url">
                  {l s='Edit on Ovebot.ai' d='Modules.Ovebotai.Admin'}
                  <i class="material-icons ovebotai-ext-icon">open_in_new</i>
                </a>
              </div>
            </div>
          {/foreach}
        </div>
      {/if}

    </div>
  </div>

</div>
