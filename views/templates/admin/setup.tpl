{**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
{* Setup wizard: 1 Connect account, 2 Website pages, 3 Products, 4 Go live. The opening step comes from state ($ove_step). *}
<div class="ovebotai-wrap">
  <div class="ovebotai-setup-card">

    <div class="ovebotai-setup-header">
      <div class="ovebotai-logo">
        <a href="https://ovebot.ai" target="_blank" rel="noopener noreferrer">
          <img src="{$ove_logo_url|escape:'htmlall':'UTF-8'}" alt="Ovebot.ai" height="36">
        </a>
        <span class="ovebotai-version">v{$ove_version|escape:'htmlall':'UTF-8'}</span>
        {if $ove_shop_name}<span class="ovebotai-version">{$ove_shop_name|escape:'htmlall':'UTF-8'}</span>{/if}
      </div>
    </div>

    <div class="ovebotai-progress-track">
      <div class="ovebotai-progress-bar" id="oveProgressBar"></div>
    </div>

    <div class="ovebotai-steps-nav">
      <div class="ovebotai-steps-nav-inner">
        {foreach $ove_step_labels as $ove_num => $ove_label}
          <div class="ovebotai-step-dot{if $ove_num == $ove_step} is-active{/if}{if $ove_num < $ove_step} is-done{/if}" data-step="{$ove_num|intval}">
            <div class="ovebotai-dot-circle">
              <span class="dot-num">{$ove_num|intval}</span>
              <span class="dot-check"><i class="material-icons">check</i></span>
            </div>
            <span class="ovebotai-dot-label">{$ove_label|escape:'htmlall':'UTF-8'}</span>
          </div>
        {/foreach}
      </div>
    </div>

    <div class="ovebotai-panels">

      {* ── Step 1: Connect ─────────────────────────────────────────────── *}
      <div class="ovebotai-panel" data-panel="1"{if $ove_step != 1} style="display:none"{/if}>
        <h2>{l s='Connect your store to Ovebot.ai' d='Modules.Ovebotai.Admin'}</h2>
        <p>{l s='Your AI agent learns your store and answers customers 24/7, in any language.' d='Modules.Ovebotai.Admin'}</p>
        <p class="ovebotai-connect-free"><strong>{l s='Free forever. No credit card.' d='Modules.Ovebotai.Admin'}</strong></p>

        {if $ove_oauth_error}
          <div class="ovebotai-notice ovebotai-notice-error"><p>{$ove_oauth_error|escape:'htmlall':'UTF-8'}</p></div>
        {/if}

        <div class="ovebotai-connect-box">
          <div class="ovebotai-connect-actions">
            <a href="{$ove_register_url|escape:'htmlall':'UTF-8'}" target="_blank" rel="noopener noreferrer" class="button ovebotai-btn-trial">{l s='Start Free' d='Modules.Ovebotai.Admin'} &rarr;</a>
            <a href="{$ove_connect_url|escape:'htmlall':'UTF-8'}" class="ovebotai-btn-existing-link">{l s='I already have an account' d='Modules.Ovebotai.Admin'} &rarr;</a>
          </div>
          <p class="ovebotai-connect-note">{l s="You'll sign in on ovebot.ai and return here to finish setup." d='Modules.Ovebotai.Admin'}</p>
        </div>
      </div>

      {* ── Step 2: Website pages ───────────────────────────────────────── *}
      <div class="ovebotai-panel" data-panel="2"{if $ove_step != 2} style="display:none"{/if}>
        <h2>{l s='Teach the AI chat agent about your website' d='Modules.Ovebotai.Admin'}</h2>
        <p class="ovebotai-lead">{l s="Select the pages below (e.g. About, FAQ, Shipping & Returns). Your AI agent will read them and use that information to answer your customers' questions accurately, live in the on-site chat." d='Modules.Ovebotai.Admin'}</p>

        <div class="ovebotai-notice ovebotai-notice-warning" id="oveKbLimitNotice" style="display:none"></div>

        {if !$ove_pages}
          <p class="ovebotai-muted">{$ove_no_pages_html nofilter}</p>
        {else}
          <div class="ovebotai-pages-list">
            {foreach $ove_pages as $ove_page}
              <div class="ovebotai-page-row" data-page-id="{$ove_page.id|intval}">
                <label class="ovebotai-page-item">
                  <input type="checkbox" name="kb_pages[]" value="{$ove_page.id|intval}"{if $ove_page.checked} checked="checked"{/if}>
                  <span class="ovebotai-checkbox-mark" aria-hidden="true"></span>
                  <div class="ovebotai-page-info">
                    <span class="ovebotai-page-title">{$ove_page.title|escape:'htmlall':'UTF-8'}</span>
                    <a href="{$ove_page.url|escape:'htmlall':'UTF-8'}" class="ovebotai-page-view-link" target="_blank" rel="noopener noreferrer">{l s='View page' d='Modules.Ovebotai.Admin'}</a>
                  </div>
                </label>
                <span class="ovebotai-page-error" style="display:none"></span>
              </div>
            {/foreach}
          </div>
        {/if}
      </div>

      {* ── Step 3: Products ────────────────────────────────────────────── *}
      <div class="ovebotai-panel" data-panel="3"{if $ove_step != 3} style="display:none"{/if}>
        <h2>{l s='Teach the AI chat agent about your products' d='Modules.Ovebotai.Admin'}</h2>
        <p class="ovebotai-lead" id="oveProductMsg">{l s='Checking how many products can be sent to your AI agent…' d='Modules.Ovebotai.Admin'}</p>
        <p class="ovebotai-muted ovebotai-products-note" id="oveProductVariantsNote">{l s='The count is per product variation: a product with several combinations (size, colour, etc.) is sent once for each variant that can be ordered, so the agent can recommend exactly the option a customer asks for.' d='Modules.Ovebotai.Admin'}</p>

        <div class="ovebotai-products-mode">
          <label class="ovebotai-radio-item">
            <input type="radio" name="products_mode" value="integrated" id="oveProductsIntegrated"{if $ove_products_builtin} checked="checked"{/if}>
            <span class="ovebotai-radio-mark" aria-hidden="true"></span>
            <div class="ovebotai-radio-info">
              <span class="ovebotai-radio-title">{l s='Use the built-in feed' d='Modules.Ovebotai.Admin'}</span>
              <span class="ovebotai-radio-desc">{l s='Only products that can be ordered right now (in stock or available on backorder) are sent to Ovebot.ai.' d='Modules.Ovebotai.Admin'}</span>
            </div>
          </label>
          <label class="ovebotai-radio-item">
            <input type="radio" name="products_mode" value="external" id="oveProductsExternal"{if !$ove_products_builtin} checked="checked"{/if}>
            <span class="ovebotai-radio-mark" aria-hidden="true"></span>
            <div class="ovebotai-radio-info">
              <span class="ovebotai-radio-title">{l s="I'll provide my own feed" d='Modules.Ovebotai.Admin'}</span>
              <span class="ovebotai-radio-desc">{$ove_products_external_html nofilter}</span>
            </div>
          </label>
        </div>
      </div>

      {* ── Step 4: Go live ─────────────────────────────────────────────── *}
      <div class="ovebotai-panel" data-panel="4"{if $ove_step != 4} style="display:none"{/if}>
        <div class="ovebotai-sync-idle" id="oveSyncIdle">
          <h2>{l s='Ready to go live' d='Modules.Ovebotai.Admin'}</h2>
          <p class="ovebotai-lead">{l s='Everything is set. Click below to send the selected pages and products to Ovebot.ai - your AI chat agent will start using them right away.' d='Modules.Ovebotai.Admin'}</p>
        </div>
        <div class="ovebotai-sync-loading" id="oveSyncLoading" style="display:none">
          <div class="ovebotai-spinner-wrap">
            <span class="ovebotai-spinner"></span>
            <p id="oveSyncStatus">{l s='Syncing with Ovebot.ai…' d='Modules.Ovebotai.Admin'}</p>
          </div>
        </div>
        <div class="ovebotai-sync-done" id="oveSyncDone" style="display:none">
          <div class="ovebotai-done-icon"><i class="material-icons">check</i></div>
          <h2>{l s='All done!' d='Modules.Ovebotai.Admin'}</h2>
          <p class="ovebotai-lead">{l s='Your store is now connected to Ovebot.ai. Your AI agent is live on your website right now, ready to chat with customers, recommend products and answer order-status questions.' d='Modules.Ovebotai.Admin'}</p>
          {if $ove_done_recommend_html}
            <p class="ovebotai-done-recommend">{$ove_done_recommend_html nofilter}</p>
          {/if}
          <div class="ovebotai-done-actions">
            <a href="{$ove_settings_url|escape:'htmlall':'UTF-8'}" class="button ovebotai-btn-muted">{l s='Go to settings' d='Modules.Ovebotai.Admin'}</a>
            <a href="{$ove_chat_url|escape:'htmlall':'UTF-8'}" class="button button-primary" target="_blank" rel="noopener noreferrer">{l s='Chat with the AI agent' d='Modules.Ovebotai.Admin'} &rarr;</a>
          </div>
        </div>
        <div class="ovebotai-sync-error" id="oveSyncError" style="display:none">
          <div class="ovebotai-notice ovebotai-notice-error" id="oveSyncErrorMsg"></div>
        </div>
      </div>

    </div>{* /.ovebotai-panels *}

    <div class="ovebotai-setup-nav" id="oveSetupNav">
      <button type="button" class="button" id="ovePrevBtn" style="display:none">&larr; {l s='Previous' d='Modules.Ovebotai.Admin'}</button>
      <button type="button" class="button button-primary" id="oveNextBtn"{if $ove_step == 1 && !$ove_connected} style="display:none"{/if}>{l s='Next' d='Modules.Ovebotai.Admin'} &rarr;</button>
    </div>

  </div>{* /.ovebotai-setup-card *}
</div>{* /.ovebotai-wrap *}
