{**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
{* Shared header for the dashboard and the settings page: logo, title, version chip, connection badge. *}
<div class="ovebotai-settings-header">
  <div class="ovebotai-logo">
    <a href="https://ovebot.ai" target="_blank" rel="noopener noreferrer">
      <img src="{$ove_logo_url|escape:'htmlall':'UTF-8'}" alt="Ovebot.ai" height="32">
    </a>
    <h1>{$ove_page_title|escape:'htmlall':'UTF-8'}</h1>
    <span class="ovebotai-version">v{$ove_version|escape:'htmlall':'UTF-8'}</span>
  </div>
  <span class="ovebotai-connection-badge">
    <span class="ovebotai-status-dot{if $ove_is_connected} is-connected{else} is-disconnected{/if}"></span>
    {if $ove_is_connected}{l s='Connected' d='Modules.Ovebotai.Admin'}{else}{l s='Not connected' d='Modules.Ovebotai.Admin'}{/if}
    {if $ove_connection_label}&middot; {$ove_connection_label|escape:'htmlall':'UTF-8'}{/if}
    {if $ove_shop_name}&middot; {$ove_shop_name|escape:'htmlall':'UTF-8'}{/if}
    {if $ove_is_connected && $ove_disconnect_url}
      &middot; <a href="{$ove_disconnect_url|escape:'htmlall':'UTF-8'}" class="ovebotai-disconnect-link" data-ovebotai-confirm="{l s='Disconnect this store from Ovebot.ai? The AI chat agent will stop working until you reconnect.' d='Modules.Ovebotai.Admin'}">{l s='Disconnect' d='Modules.Ovebotai.Admin'}</a>
    {/if}
  </span>
</div>
