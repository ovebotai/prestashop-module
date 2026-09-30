# Ovebot AI pentru PrestaShop: stadiu și predare

Ultima actualizare: 2026-09-23. Document intern de lucru (folderul `docs/` nu intră în zip-ul de release).

## Unde am rămas

- Modulul `ovebotai` a fost scris integral pe 2026-09-22, după planul de implementare „Ovebot AI - modul PrestaShop (1.7.8 → 9.1)” (fazele F0-F9). Planul a fost primit în chat, nu există pe disc; secțiunile importante sunt rezumate mai jos.
- Referința de UX și fluxuri: modulul OpenCart din `D:\dev\module-ovebotai` (orchestratorul `system/library/ovebotai.php`, template-urile `.tpl`, JS-ul și CSS-ul au fost portate 1:1 unde s-a putut).
- Nimic nu e commit-uit încă: `modules/ovebotai` apare ca folder untracked în repo-ul `D:\dev\prestashop9`.
- Faza F10 (QA pe magazin real) NU a început. Modulul nu a fost încă instalat din Module Manager.

## Mediul de dev (fapte verificate pe 2026-09-23)

- Serverul de dev: 192.168.1.100 (Apache 2.4.67), servește o copie sincronizată a folderului `D:\dev\prestashop9` (fișierele noi din `modules/ovebotai` răspund cu 200).
- `app/config/parameters.php` există doar pe server, nu în copia locală; credențialele MySQL (tot 192.168.1.100) nu sunt disponibile local.
- Domeniul magazinului: `https://prestashop3.a-web.ro/` (inițial era `prestashop.d3.dv`, setat de installer; a fost corectat în `ps_shop_url` + `PS_SHOP_DOMAIN(_SSL)`).
- Admin: `https://prestashop3.a-web.ro/admin/` (folderul nu a fost redenumit). Conturile sunt în `ps_employee`, parola e bcrypt; se resetează cu `password_hash(..., PASSWORD_BCRYPT)`.
- PrestaShop 9.0.1, PHP 8.3 local.

## Ce conține modulul

| Zonă | Fișiere | Observații |
|---|---|---|
| Modul | `ovebotai.php`, `src/Install/Installer.php` | tab sub `AdminParentCustomerThreads`, hook-uri `actionFrontControllerSetMedia`, `actionObjectCmsUpdateAfter`, `actionObjectCmsDeleteAfter`, tabel `ovebotai_rate_limit` |
| Admin | `controllers/admin/AdminOvebotaiController.php`, `views/templates/admin/*.tpl`, `views/css/admin.css`, `views/js/admin/*.js` | wizard 4 pași, dashboard, settings, ecran multistore; AJAX: SyncPages, Finish, SaveSettings, RegenFeedHash, RegenOrderCreds |
| Front | `controllers/front/oauth.php`, `feed.php`, `orders.php` | callback OAuth pe domeniul shopului, feed stream-uit, comenzi cu Basic auth + rate limit |
| Servicii | `src/Config/*`, `src/Service/*`, `src/Api/*` | Settings per shop fără moștenire, Client PKCE, Integration (refresh cu GET_LOCK, probe live/revoked/unreachable/disconnected, /setup, self-heal) |
| Domeniu | `src/KnowledgeBase/*`, `src/Feed/*`, `src/Order/*`, `src/Tracking/*`, `src/Security/*`, `src/Storefront/*` | KB pe slug `cms-{id}`, feed, lookup comenzi, finder-e AWB, widget + purchase |
| Alte | `translations/ro-RO/*.xlf` (150 stringuri), `README.md`, `CHANGELOG.md`, `.github/workflows/release-zip.yml`, `tests/run.php` | testele rulează cu `php tests/run.php` (stub-uri, fără PrestaShop): 209 aserțiuni, toate trec |

## Decizii luate în plus față de plan

1. URL-urile feed / orders / callback folosesc schema din `PS_SSL_ENABLED` (http dacă SSL e oprit), nu https forțat.
2. Preview-ul chat-ului („Chat with the AI agent”) validează `adtoken` cu `LegacyAdminTokenValidator` pe PS 9 și cu hash-ul legacy pe versiuni mai vechi.
3. Refresh-ul de token: serializat cu `GET_LOCK('ovebotai_refresh_{id_shop}')`, recitește tokenii din DB sub lock; doar un refuz explicit al endpoint-ului de token (`AuthException`) golește tokenii; eroare de rețea sau 5xx îi păstrează.
4. `KbSync` aruncă excepție dacă lista KB nu poate fi citită (evită dublurile pe care le-ar produce o hartă parțială de slug-uri).
5. `KbSync::deactivateDeleted()` completează body-ul cu slug-ul ca să treacă minimul de 10 caractere la dezactivarea unei pagini șterse.
6. Finder-ele de curieri (`src/Tracking/couriers.php`) verifică existența tabelului și a coloanelor înainte de orice query; definițiile sunt candidați neverificați.
7. La regenerarea hash-ului feed-ului, push-ul la Ovebot se face doar dacă recommend && built-in; altfel schimbarea e locală.
8. Mesajele „Feed URL regenerated.” / „Credentials regenerated.” (fără „synced”) apar când magazinul nu e conectat sau feed-ul nu e citit de Ovebot.
9. „Reset” din Module Manager (uninstall + install în același request) NU deconectează magazinul și NU regenerează hash-ul feed-ului / credențialele de comenzi: `Installer::isResetting()` detectează resetul prin hook-ul `actionBeforeResetModule` (PS 8+) sau prin ruta `admin_module_manage_action` cu `action=reset` (PS 1.7.8) și sare peste curățare. Există și `Ovebotai::reset()` pentru resetul cu „keep data”.
10. Statusul comenzii din `/orders` vine în limba comenzii (`orders.id_lang`), nu în limba implicită a shopului; feed-ul și KB rămân pe limba implicită.
11. Feed: URL-urile produselor se construiesc cu un singur obiect `Product` neîncărcat, refolosit, ca `Link::getProductLink()` să nu facă `new Product($id)` pentru fiecare produs fără EAN13. Produsele cu combinații nu mai sunt filtrate pe rândul de stoc agregat; fiecare combinație decide singură, exact ca în `counts()`.
12. Folderul `upgrade/` a fost eliminat; se adaugă odată cu prima versiune care are nevoie de un script de upgrade.

## Chei de configurare

Toate per shop, prefix `OVEBOTAI_`: ACCESS_TOKEN, REFRESH_TOKEN, TOKEN_EXPIRES, WORKSPACE, AGENT, SETUP_COMPLETE, KB_PAGE_IDS, OAUTH_PENDING, OAUTH_RESULT, LAST_PUSH, ACCOUNT_HOST, API_HOST, FEED_HASH, ORDER_USER, ORDER_PASS, CHAT_STATUS, PRODUCTS_BUILTIN, PRODUCTS_RECOMMEND, ORDER_ENABLED, WIDGET (JSON), TRACKING_FINDERS, TRACKING_URLS, ORDER_MAX_AGE_DAYS.

Reguli: flag nesetat = on (cu excepția CHAT_STATUS); JSON-ul se salvează cu `JSON_HEX_*` pentru că `Configuration::updateValue` trece valorile prin `pSQL()`; în multistore se citește doar nivelul shop, cu migrare leneșă a valorilor globale către shopul implicit.

## Matricea de gating (feed / orders)

| Condiție | Feed | Orders |
|---|---|---|
| CHAT_STATUS ≠ 1 | 403 | 403 |
| PRODUCTS_RECOMMEND off sau PRODUCTS_BUILTIN off | 403 | - |
| hash greșit | 403 | - |
| ORDER_ENABLED off | - | 403 (înainte de auth) |
| IP blocat (10 eșecuri/oră) | - | 429 |
| Basic auth greșit | - | 401 + WWW-Authenticate |

Contractul `/orders`: `{"success":true,"data":{id,reference,date,status,total,currency,carrier,awb,awb_tracking_url}}` sau `{"success":false,"error":"..."}`; `id` = referința comenzii sau id-ul numeric - clientul nu vede niciodată id-ul, deci referința e cazul normal; ambele variante se caută cu `reference = ? OR id_order = ?`; exact unul dintre `email` / `phone` (ultimele 9 cifre, LIKE pe `phone` și `phone_mobile` din adresa de livrare sau facturare); comenzi mai noi de `ORDER_MAX_AGE_DAYS` (60).

## De testat în F10 (din planul original, secțiunea 15)

1. Instalare din Module Manager → wizard → Start Free (URL cu `plan=wp-freemium&domain=…`) → Connect → pagini → produse (ambele moduri) → Finish → dashboard.
2. Multistore cu 2 magazine: conexiuni separate; contextul „All shops” afișează alegerea magazinului; activarea multistore după o conexiune single-shop păstrează shopul implicit conectat și îl lasă pe cel nou neconectat.
3. Refresh token revocat în cont → pasul 1 la următoarea încărcare, widget-ul de pe storefront încă funcționează.
4. API indisponibil (override `OVEBOTAI_API_HOST` greșit) → dashboard + banner, fără deconectare.
5. Settings: toate combinațiile recommend / builtin / order și payload-ul trimis; regenerări, inclusiv cazul de eșec (switch-urile revin la valorile efective).
6. Feed: produs inactiv, invizibil, fără preț, fără stoc (deny / allow / default), cu combinații, reduceri, caracteristici; friendly URLs on/off; mentenanță pornită.
7. Orders cu `curl`: id / reference / `#id`; email / telefon în mai multe formate; ambele sau niciunul; comandă veche; alt shop; 10 × auth greșit → 429; deblocare după regenerarea credențialelor; Apache + FastCGI (`SetEnvIf Authorization`).
8. KB: cotă atinsă la creare cu update-uri în același batch; intrare ștearsă în cont (404 → recreare); pagină CMS editată sau ștearsă în PS.
9. Storefront: widget pe Classic / Hummingbird; `auto_open` doar cu `adtoken` valid; purchase o singură dată per comandă, inclusiv coș cu mai multe comenzi.
10. Uninstall: tabelul și cheile dispar, `/v1/disconnect` apelat.

Exemple:

```bash
curl -s "https://prestashop3.a-web.ro/index.php?fc=module&module=ovebotai&controller=feed&id_lang=1&hash=HASH" | head -c 500
```

```bash
curl -s -u "USER:PASS" -H "Content-Type: application/json" -d '{"id":"12","email":"client@exemplu.ro"}' "https://prestashop3.a-web.ro/index.php?fc=module&module=ovebotai&controller=orders&id_lang=1"
```

## Checklist Addons (secțiunea 16 din plan)

`index.php` în fiecare folder (făcut), header de licență în toate fișierele (făcut), garda `_PS_VERSION_` (făcut), fără `die`/`exit` în afara gărzilor (verificat), input prin `Tools::getValue` (`$_SERVER` doar în `BasicAuth`), SQL cu `(int)` / `pSQL` / `bqSQL`, escaping în template-uri cu `nofilter` doar pe HTML-ul construit în controller, traduceri pe domeniul `Modules.Ovebotai.Admin`, fără JS inline în BO, uninstall curat, `vendor/` inclus cu `prepend-autoloader: false`. Rămân de făcut: rularea validatorului oficial și completarea `module_key` după submit.

## Puncte deschise cu echipa Ovebot (secțiunea 17 din plan)

1. Planul `wp-freemium` pe account.ovebot.ai.
2. Callback OAuth cu query string existent (`index.php?fc=module&…`): Ovebot adaugă `&code=`? Numele parametrilor de eroare (`error`, `error_description`)? Două magazine pe același domeniu cu URI diferite?
3. `/setup` › `widget`: acceptă și alte chei în afară de `language`?
4. Feed: `options` separat sau contopit în `attributes` (ales: contopit); `sku` / `gtin`; `availability: "preorder"` acceptat?
5. `/orders`: câmpul în plus `reference` e tolerat? Limba câmpului `status`?
6. `event.js` purchase: `transaction_id` = `id_order` (ales) sau `reference`?
7. `/v1/integration/status`: forma `integration.products` / `integration.order_info` (bool) + `counts.products`.
8. KB: `per_page` maxim; PUT parțial acceptat?
9. IP-uri fixe de ieșire ale Ovebot pentru rate limit?
10. ~~Licența finală~~ - decis pe 2026-09-30: MIT (`LICENSE` + headerele din toate fișierele).
