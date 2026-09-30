{**
 * Ovebot AI - AI Chatbot, Live Chat & AI Sales Agent
 *
 * @author    AWeb Design SRL
 * @copyright 2026 AWeb Design SRL
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
{* Multistore, "All shops" / group context: every shop connects separately, so pick one first. *}
<div class="ovebotai-wrap">
  <div class="ovebotai-setup-card">
    <div class="ovebotai-setup-header">
      <div class="ovebotai-logo">
        <a href="https://ovebot.ai" target="_blank" rel="noopener noreferrer">
          <img src="{$ove_logo_url|escape:'htmlall':'UTF-8'}" alt="Ovebot.ai" height="36">
        </a>
        <span class="ovebotai-version">v{$ove_version|escape:'htmlall':'UTF-8'}</span>
      </div>
    </div>
    <div class="ovebotai-panels">
      <h2>{l s='Select a shop' d='Modules.Ovebotai.Admin'}</h2>
      <p class="ovebotai-lead">{l s='Ovebot.ai connects each shop separately. Pick the shop you want to configure.' d='Modules.Ovebotai.Admin'}</p>
      <div class="ovebotai-shop-list">
        {foreach $ove_shops as $ove_shop}
          <a href="{$ove_shop.url|escape:'htmlall':'UTF-8'}" class="ovebotai-shop-item">
            <span class="ovebotai-shop-name">{$ove_shop.name|escape:'htmlall':'UTF-8'}</span>
            <span class="ovebotai-shop-status{if $ove_shop.connected} is-connected{/if}">
              {if $ove_shop.connected}{l s='Connected' d='Modules.Ovebotai.Admin'} &middot; {$ove_shop.label|escape:'htmlall':'UTF-8'}{else}{l s='Not connected' d='Modules.Ovebotai.Admin'}{/if}
            </span>
          </a>
        {/foreach}
      </div>
    </div>
  </div>
</div>
