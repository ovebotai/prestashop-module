{**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<div class="ovebotai-wrap">

  {include file="{$ove_tpl_dir}_header.tpl"}

  <a href="{$ove_dashboard_url|escape:'htmlall':'UTF-8'}" class="ovebotai-back-link"><i class="material-icons">chevron_left</i> {l s='Dashboard' d='Modules.Ovebotai.Admin'}</a>

  <div id="oveSettingsNotice" class="ovebotai-save-notice" style="display:none"></div>
  <div id="oveSettingsWarnings" class="ovebotai-save-notice ovebotai-notice-warning" style="display:none"></div>

  {* settings.js takes over the submit (AJAX SaveSettings); without JS a plain POST just reloads the page. *}
  <form id="oveSettingsForm" method="post" action="{$ove_dashboard_url|escape:'htmlall':'UTF-8'}&amp;view=settings">

    {* ── On-site chat widget ─────────────────────────────────────────── *}
    <div class="ovebotai-fieldset">
      <div class="ovebotai-fieldset-legend">
        <i class="material-icons">chat</i>
        {l s='On-site chat widget' d='Modules.Ovebotai.Admin'}
      </div>
      <div class="ovebotai-fieldset-body">
        <div class="ovebotai-field ovebotai-field-switch">
          <label>{l s='Show the chat bubble on your site' d='Modules.Ovebotai.Admin'}</label>
          <div class="ovebotai-switch-wrap">
            <label class="ovebotai-switch">
              <input type="checkbox" name="chat_status" id="oveChatStatus" value="1"{if $ove_chat_status} checked="checked"{/if}>
              <span class="ovebotai-switch-slider"></span>
            </label>
            <span class="ovebotai-switch-lbl" id="oveChatStatusLbl">{if $ove_chat_status}{l s='Enabled' d='Modules.Ovebotai.Admin'}{else}{l s='Disabled' d='Modules.Ovebotai.Admin'}{/if}</span>
          </div>
          <p class="description">{l s="When enabled, the AI agent's chat bubble appears on every page of your site so customers can start a conversation." d='Modules.Ovebotai.Admin'}</p>
        </div>
      </div>
    </div>

    {* ── Product feed ────────────────────────────────────────────────── *}
    <div class="ovebotai-fieldset">
      <div class="ovebotai-fieldset-legend">
        <i class="material-icons">rss_feed</i>
        {l s='Product feed' d='Modules.Ovebotai.Admin'}
      </div>
      <div class="ovebotai-fieldset-body">

        <div class="ovebotai-field ovebotai-field-switch">
          <label>{l s='Recommend products' d='Modules.Ovebotai.Admin'}</label>
          <div class="ovebotai-switch-wrap">
            <label class="ovebotai-switch">
              <input type="checkbox" name="products_recommend" id="oveProductsRecommend" value="1"{if $ove_products_recommend} checked="checked"{/if}>
              <span class="ovebotai-switch-slider"></span>
            </label>
            <span class="ovebotai-switch-lbl" id="oveProductsRecommendLbl">{if $ove_products_recommend}{l s='Enabled' d='Modules.Ovebotai.Admin'}{else}{l s='Disabled' d='Modules.Ovebotai.Admin'}{/if}</span>
          </div>
          <p class="description">{l s='When enabled, your AI agent recommends products from your catalog in conversations.' d='Modules.Ovebotai.Admin'}</p>
        </div>

        <div class="ovebotai-field ovebotai-field-switch">
          <label>{l s='Use the built-in product feed' d='Modules.Ovebotai.Admin'}</label>
          <div class="ovebotai-switch-wrap">
            <label class="ovebotai-switch">
              <input type="checkbox" name="products_builtin" id="oveProductsBuiltin" value="1"{if $ove_products_builtin} checked="checked"{/if}>
              <span class="ovebotai-switch-slider"></span>
            </label>
            <span class="ovebotai-switch-lbl" id="oveProductsBuiltinLbl">{if $ove_products_builtin}{l s='Enabled' d='Modules.Ovebotai.Admin'}{else}{l s='Disabled' d='Modules.Ovebotai.Admin'}{/if}</span>
          </div>
          <p class="description">
            {l s="When off, our own feed URL stops working (returns Forbidden) and Ovebot.ai won't be told about it. If you have a custom feed, configure it directly at:" d='Modules.Ovebotai.Admin'}
            {if $ove_setup_url} <a href="{$ove_setup_url|escape:'htmlall':'UTF-8'}" target="_blank" rel="noopener noreferrer">{$ove_setup_url|escape:'htmlall':'UTF-8'}</a>{/if}
          </p>
        </div>

        <div class="ovebotai-field" id="oveFeedUrlField"{if !$ove_products_builtin} style="display:none"{/if}>
          <label>{l s='Feed URL' d='Modules.Ovebotai.Admin'}</label>
          <div class="ovebotai-url-row">
            <input type="text" class="regular-text ovebotai-readonly-url" id="oveFeedUrl" value="{$ove_feed_url|escape:'htmlall':'UTF-8'}" readonly>
            <button type="button" class="button ovebotai-copy-btn" data-target="oveFeedUrl">{l s='Copy' d='Modules.Ovebotai.Admin'}</button>
            <button type="button" class="button ovebotai-regen-hash-btn" aria-label="{l s='Regenerate hash' d='Modules.Ovebotai.Admin'}" title="{l s='Regenerate hash' d='Modules.Ovebotai.Admin'}">
              <i class="material-icons">refresh</i>
            </button>
          </div>
          <p class="description ovebotai-fieldset-intro">
            {l s="Ovebot.ai reads this URL periodically to keep your AI agent's product recommendations up to date with your catalog (stock, price, availability)." d='Modules.Ovebotai.Admin'}
            {l s='The feed is served live, so catalog changes (stock, price, availability) are reflected immediately.' d='Modules.Ovebotai.Admin'}
          </p>
        </div>

      </div>
    </div>

    {* ── Order tracking API ──────────────────────────────────────────── *}
    <div class="ovebotai-fieldset">
      <div class="ovebotai-fieldset-legend">
        <i class="material-icons">local_shipping</i>
        {l s='Order tracking API' d='Modules.Ovebotai.Admin'}
      </div>
      <div class="ovebotai-fieldset-body">

        <p class="description ovebotai-fieldset-intro">{l s='Ovebot.ai calls this endpoint, authenticated with the credentials below, so your AI agent can answer "Where is my order?" questions with live tracking info.' d='Modules.Ovebotai.Admin'}</p>

        <div class="ovebotai-field ovebotai-field-switch">
          <label>{l s='Enable order tracking' d='Modules.Ovebotai.Admin'}</label>
          <div class="ovebotai-switch-wrap">
            <label class="ovebotai-switch">
              <input type="checkbox" name="order_enabled" id="oveOrderEnabled" value="1"{if $ove_order_enabled} checked="checked"{/if}>
              <span class="ovebotai-switch-slider"></span>
            </label>
            <span class="ovebotai-switch-lbl" id="oveOrderEnabledLbl">{if $ove_order_enabled}{l s='Enabled' d='Modules.Ovebotai.Admin'}{else}{l s='Disabled' d='Modules.Ovebotai.Admin'}{/if}</span>
          </div>
          <p class="description">{l s='When enabled, your AI agent can look up order status and tracking.' d='Modules.Ovebotai.Admin'}</p>
        </div>

        <div class="ovebotai-field">
          <label>{l s='Endpoint URL' d='Modules.Ovebotai.Admin'}</label>
          <div class="ovebotai-url-row">
            <input type="text" class="regular-text ovebotai-readonly-url" id="oveOrderUrl" value="{$ove_order_url|escape:'htmlall':'UTF-8'}" readonly>
            <button type="button" class="button ovebotai-copy-btn" data-target="oveOrderUrl">{l s='Copy' d='Modules.Ovebotai.Admin'}</button>
          </div>
        </div>

        <div class="ovebotai-field">
          <label>{l s='API user' d='Modules.Ovebotai.Admin'}</label>
          <div class="ovebotai-url-row">
            <input type="text" class="regular-text ovebotai-readonly-url" id="oveApiUser" value="{$ove_order_user|escape:'htmlall':'UTF-8'}" readonly>
            <button type="button" class="button ovebotai-copy-btn" data-target="oveApiUser">{l s='Copy' d='Modules.Ovebotai.Admin'}</button>
          </div>
        </div>

        <div class="ovebotai-field">
          <label>{l s='API password' d='Modules.Ovebotai.Admin'}</label>
          <div class="ovebotai-url-row">
            <input type="text" class="regular-text ovebotai-readonly-url" id="oveApiPass" value="{$ove_order_pass|escape:'htmlall':'UTF-8'}" readonly>
            <button type="button" class="button ovebotai-copy-btn" data-target="oveApiPass">{l s='Copy' d='Modules.Ovebotai.Admin'}</button>
          </div>
        </div>

        <div class="ovebotai-field">
          <button type="button" class="button ovebotai-regen-creds-btn">{l s='Regenerate credentials' d='Modules.Ovebotai.Admin'}</button>
          <p class="description">{l s='Generates a new user/password pair. The old ones will stop working immediately.' d='Modules.Ovebotai.Admin'}</p>
        </div>

      </div>
    </div>

    {* ── Appearance ──────────────────────────────────────────────────── *}
    <div class="ovebotai-fieldset">
      <div class="ovebotai-fieldset-legend">
        <i class="material-icons">brush</i>
        {l s='Appearance' d='Modules.Ovebotai.Admin'}
      </div>
      <div class="ovebotai-fieldset-body">

        <div class="ovebotai-field">
          <button type="button" class="button" id="oveAppearanceToggle">
            {l s='Configure appearance' d='Modules.Ovebotai.Admin'}
            <i class="material-icons" id="oveAppearanceToggleIcon">expand_more</i>
          </button>
        </div>

        <div class="ovebotai-appearance-panel" id="oveAppearancePanel" style="display:none">

          <div class="ovebotai-appearance-subhead">{l s='Look & feel' d='Modules.Ovebotai.Admin'}</div>
          <div class="ovebotai-appearance-grid">

            <div class="ovebotai-field">
              <label for="ove_accent_color">{l s='Accent colour' d='Modules.Ovebotai.Admin'}</label>
              <div class="ovebotai-color-wrap">
                <input type="color" id="ove_color_picker" value="{if $ove_widget.accent_color}{$ove_widget.accent_color|escape:'htmlall':'UTF-8'}{else}{$ove_placeholders.accent_color|escape:'htmlall':'UTF-8'}{/if}">
                <input type="text" name="widget_accent_color" id="ove_accent_color" class="regular-text" value="{$ove_widget.accent_color|escape:'htmlall':'UTF-8'}" placeholder="{$ove_placeholders.accent_color|escape:'htmlall':'UTF-8'}" maxlength="7">
              </div>
            </div>

            <div class="ovebotai-field">
              <label for="ove_theme">{l s='Theme' d='Modules.Ovebotai.Admin'}</label>
              <select name="widget_theme" id="ove_theme">
                <option value=""{if $ove_widget.theme == ''} selected="selected"{/if}>{l s='Default (light)' d='Modules.Ovebotai.Admin'}</option>
                <option value="light"{if $ove_widget.theme == 'light'} selected="selected"{/if}>{l s='Light' d='Modules.Ovebotai.Admin'}</option>
                <option value="dark"{if $ove_widget.theme == 'dark'} selected="selected"{/if}>{l s='Dark' d='Modules.Ovebotai.Admin'}</option>
              </select>
            </div>

            <div class="ovebotai-field">
              <label for="ove_language">{l s='Language' d='Modules.Ovebotai.Admin'}</label>
              <select name="widget_language" id="ove_language">
                <option value=""{if $ove_widget.language == ''} selected="selected"{/if}>{l s='Default' d='Modules.Ovebotai.Admin'}</option>
                <option value="auto"{if $ove_widget.language == 'auto'} selected="selected"{/if}>{l s='Auto (browser)' d='Modules.Ovebotai.Admin'}</option>
                {foreach $ove_languages as $ove_code => $ove_name}
                  <option value="{$ove_code|escape:'htmlall':'UTF-8'}"{if $ove_widget.language == $ove_code} selected="selected"{/if}>{$ove_name|escape:'htmlall':'UTF-8'}</option>
                {/foreach}
              </select>
            </div>

            <div class="ovebotai-field">
              <label for="ove_audio_beep">{l s='Audio beep' d='Modules.Ovebotai.Admin'}</label>
              <select name="widget_audio_beep" id="ove_audio_beep">
                <option value=""{if $ove_widget.audio_beep == ''} selected="selected"{/if}>{l s='Default (play)' d='Modules.Ovebotai.Admin'}</option>
                <option value="play"{if $ove_widget.audio_beep == 'play'} selected="selected"{/if}>{l s='Play' d='Modules.Ovebotai.Admin'}</option>
                <option value="none"{if $ove_widget.audio_beep == 'none'} selected="selected"{/if}>{l s='None' d='Modules.Ovebotai.Admin'}</option>
              </select>
            </div>

          </div>{* /.ovebotai-appearance-grid *}

          <div class="ovebotai-appearance-subhead">{l s='Position on page' d='Modules.Ovebotai.Admin'}</div>
          <div class="ovebotai-field ovebotai-field-full">
            <label for="ove_side">{l s='Widget position' d='Modules.Ovebotai.Admin'}</label>
            <div class="ovebotai-combo-row">
              <select name="widget_side" id="ove_side">
                <option value=""{if $ove_widget.side == ''} selected="selected"{/if}>{l s='Default (right)' d='Modules.Ovebotai.Admin'}</option>
                <option value="right"{if $ove_widget.side == 'right'} selected="selected"{/if}>{l s='Bottom right' d='Modules.Ovebotai.Admin'}</option>
                <option value="left"{if $ove_widget.side == 'left'} selected="selected"{/if}>{l s='Bottom left' d='Modules.Ovebotai.Admin'}</option>
              </select>
              <div class="ovebotai-combo-sub">
                <input type="number" name="widget_offset_y" id="ove_offset_y" class="small-text" value="{$ove_widget.offset_y|escape:'htmlall':'UTF-8'}" min="0" placeholder="{$ove_placeholders.offset_y|escape:'htmlall':'UTF-8'}">
                <span class="ovebotai-combo-suffix">{l s='px offset from bottom' d='Modules.Ovebotai.Admin'}</span>
              </div>
              <div class="ovebotai-combo-sub">
                <input type="number" name="widget_offset_x" id="ove_offset_x" class="small-text" value="{$ove_widget.offset_x|escape:'htmlall':'UTF-8'}" min="0" placeholder="{$ove_placeholders.offset_x|escape:'htmlall':'UTF-8'}">
                <span class="ovebotai-combo-suffix">{l s='px offset from the side' d='Modules.Ovebotai.Admin'}</span>
              </div>
            </div>
            <p class="description">{l s='Raise the offset to sit the widget above another floating button, e.g. WhatsApp.' d='Modules.Ovebotai.Admin'}</p>
          </div>

          <div class="ovebotai-field ovebotai-field-full">
            <label for="ove_z_index">{l s='Stacking order (z-index)' d='Modules.Ovebotai.Admin'}</label>
            <input type="number" name="widget_z_index" id="ove_z_index" class="regular-text" value="{$ove_widget.z_index|escape:'htmlall':'UTF-8'}" min="0" placeholder="{$ove_placeholders.z_index|escape:'htmlall':'UTF-8'}">
            <p class="description">{l s='Adjust the value to place the chat widget below/above another element on the page.' d='Modules.Ovebotai.Admin'}</p>
          </div>

          <div class="ovebotai-appearance-subhead">{l s='Messages' d='Modules.Ovebotai.Admin'}</div>
          <div class="ovebotai-field ovebotai-field-full">
            <label for="ove_subtitle">{l s='Subtitle' d='Modules.Ovebotai.Admin'}</label>
            <input type="text" name="widget_subtitle" id="ove_subtitle" class="regular-text" value="{$ove_widget.subtitle|escape:'htmlall':'UTF-8'}" maxlength="255" placeholder="{l s='e.g. Usually replies in a few minutes' d='Modules.Ovebotai.Admin'}">
            <p class="description">{l s="Shown under the assistant's name in the chat header." d='Modules.Ovebotai.Admin'}</p>
          </div>

          <div class="ovebotai-field ovebotai-field-full">
            <label for="ove_proactive_message">{l s='Proactive message' d='Modules.Ovebotai.Admin'}</label>
            <div class="ovebotai-combo-row">
              <input type="text" name="widget_proactive_message" id="ove_proactive_message" class="regular-text" value="{$ove_widget.proactive_message|escape:'htmlall':'UTF-8'}" maxlength="255" placeholder="{l s='e.g. Need help finding something?' d='Modules.Ovebotai.Admin'}">
              <div class="ovebotai-combo-sub">
                <input type="number" name="widget_proactive_delay" id="ove_proactive_delay" class="small-text" value="{$ove_widget.proactive_delay|escape:'htmlall':'UTF-8'}" min="0" max="300" placeholder="{$ove_placeholders.proactive_delay|escape:'htmlall':'UTF-8'}">
                <span class="ovebotai-combo-suffix">{l s='s after page load' d='Modules.Ovebotai.Admin'}</span>
              </div>
            </div>
            <p class="description">{l s='A short message that pops up on its own to invite visitors to chat. Leave empty to disable.' d='Modules.Ovebotai.Admin'}</p>
          </div>

        </div>{* /.ovebotai-appearance-panel *}

      </div>
    </div>

    <div class="ovebotai-save-row">
      <button type="submit" class="button button-primary button-large" id="oveSaveBtn">{l s='Save settings' d='Modules.Ovebotai.Admin'}</button>
    </div>

  </form>

</div>
