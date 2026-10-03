# DataVerse Space

Standalone Vue 3 + TypeScript frontend with two SpaceTimeDB 2.10 modules. BitCraft is public. Evergather currently uses remembered browser identities for local play; Discord through SpacetimeAuth can be restored later. The running app has no Laravel or PostgreSQL dependency. Existing ICHAA files and databases are left untouched.

## Local development

Use Node 24 and the SpaceTimeDB 2.10 CLI. From this folder:

```powershell
npm install
npm install --prefix spacetimedb
npm install --prefix bitcraft
```

Start the isolated server in a terminal and keep it open:

```powershell
npm run spacetime:start
```

In another terminal:

```powershell
Copy-Item .env.example .env.local # First setup only; preserve your existing settings.
npm run generate
npm run spacetime:publish
npm run dev
```

Open http://127.0.0.1:5180. SpaceTimeDB listens only on 127.0.0.1:3100. Data persists in ignored `.runtime/data`. Ctrl+C stops the process in its own terminal. Restarting preserves data; publishing updates schema and bundled catalogs without clearing player progress. Catalog import currently replaces the bundled definitions: use it intentionally after editing content. Commands explicitly bypass the template's database configuration to target `space-evergather` and `space-bitcraft-tools`.

The existing local proxy can also serve https://space.test/evergather. Vite explicitly allows `space.test` in development and preview.

## Temporary local play

Discord login is currently bypassed at the user's request for local development. Set `VITE_EVERGATHER_LOCAL_PLAY=true` in `.env.local`, then run `npm run spacetime:local -- on` with the database running. Restart Vite after changing its environment. A server-issued identity is saved in this browser's local storage, so refreshing keeps the same character. Different browsers have separate characters; clearing site data loses the local identity. Caller ownership checks and private player views still apply.

To restore Discord login, run `npm run spacetime:local -- off`, set `VITE_EVERGATHER_LOCAL_PLAY=false`, and restart Vite. Existing Discord settings and player rows are preserved. Local characters are not automatically linked to a later Discord account.

The secondary frontend uses `npm run dev -- --mode playtest --port 5181` and `space-evergather-test`; enable its local mode separately with the owner-only `configure_local_play` reducer.

## Discord login

1. Create a Discord OAuth application. Set its redirect URI to `https://auth.spacetimedb.com/interactions/federated/callback/discord`.
2. In your SpacetimeAuth project, enable Discord and enter the Discord client ID and secret there. Enable only Discord if you want the hosted sign-in screen to offer only Discord.
3. Configure the SpacetimeAuth public client with redirect URI `http://127.0.0.1:5180/auth/callback` and post-logout URI `http://127.0.0.1:5180/evergather`.
4. Set `VITE_AUTH_CLIENT_ID` in `.env.local` to that **SpacetimeAuth public client ID**, restart Vite, and configure the local Evergather database:

```powershell
npm run spacetime:auth -- YOUR_PUBLIC_SPACETIMEAUTH_CLIENT_ID
```

The frontend uses OIDC authorization-code flow with PKCE. SpaceTimeDB validates tokens; reducers additionally require the configured issuer and audience, and every private view filters by caller identity. With local play off, gameplay fails closed until login is configured. Secrets belong in the auth provider, never in Vite variables or committed files. The database publisher alone can change auth/local-play settings and refresh catalogs.

These provider settings follow the [official SpacetimeAuth configuration guide](https://spacetimedb.com/docs/core-concepts/authentication/spacetimeauth/configuring-a-project/). Real Discord login is pending external project configuration and must be tested before deployment.

## Verification

```powershell
npm test
npm run test:server # Requires the local server; publishes only space-evergather-checks.
npm run test:bitcraft-server # Publishes only space-bitcraft-checks.
npm run build
```

Server tests mint local fixture identities in a separate database. They exercise server rules rather than providing an anonymous production login. A fresh identity is used on every run. No player accounts or tokens are imported from ICHAA.

## Current implementation

- Responsive Vue shell, public BitCraft navigation, OIDC callback/logout, generated typed bindings.
- Evergather: current content and calibrated XP thresholds; character and 38 skills/field tools; gathering, activities, global cooldown, inventory, crafting, shop, expeditions, job turn-ins, equipment lifecycle, tier/rarity upgrades, marketplace with price bands and 5% fee, NPC vendor, achievement claims/titles, world-event bonuses, live leaderboard and journal.
- BitCraft: ICHAA's public Vue pages and widget setup drawers, backed by public catalogs and a shared live-data cache. Market order books, coin barter listings, claim/empire/region filters, recipe trees and totals, tool rates, hunting XP, open-craft projections, player selection, guide editing/publishing, and all four trackers use the original page layouts and interactions. Each provider has independent shared and per-browser request budgets, cache durations, refresh leases and cooldowns. Fresh fallback data wins over delayed primary data; otherwise the freshest valid stale result is used. Data refresh labels remain provider-neutral.

### Evergather UI

The live ICHAA command board and its Vue panels are ported into `src/components/evergather`. Their forms call SpaceTimeDB reducers through `src/evergather/useActionForm.js`; `presentation.js` supplies the original panel contracts from subscriptions. Inventory displays committed server state without applying result deltas a second time.

| Workspace | Sections |
| --- | --- |
| Overview | Character customization, progression and claimed titles, latest result |
| Gather | Actions, skill activities, latest result |
| Craft | Equipment and tool storage, recipes, jobs, expeditions, latest result |
| Trade | Marketplace, shop, inventory sources and uses, latest result |
| Progress | Skills and unlock paths, world events, ranks, recent gathering/activity ledger |

Search, board filters, show-more controls, repeat/auto-repeat, navigation restoration, tool maintenance, and result skill-level feedback use the ICHAA layout. Auto-repeat stops when leaving its panel or when an action fails. Character appearance and descriptive title are saved separately from the earned title loadout.

The October 3 UI verification walked all 15 distinct source sections, plus each available Result placement in Space, at desktop and mobile sizes. A separate test character exercised customization persistence, gathering, activities, reward claims/title equip, shop materials/tools, crafting, job delivery, expeditions, tool equip/unequip, item listing/cancellation, and NPC selling. Screenshots and walkthrough output are under ignored `output/playwright`. Unit and isolated server checks cover presentation contracts, ownership, action rollback, pricing and trade fees.

### BitCraft UI

The public ICHAA BitCraft pages are ported into `src/bitcraft-ui`; `BitcraftUiPage.vue` supplies their original prop contracts and navigation through SpaceTimeDB. The earlier generic BitCraft screens have been removed. Public definitions contain 9,094 catalog items and 504 gathering actions, with recipe alternatives, deferred ingredient branches, bait-processing outputs, and locally copied icons. The 19 published guides retain their rich documents, author labels and dates; inline item and activity cards use the same reader components.

Activity, inventory, passive-craft and task trackers have both `/setup` pages and standalone `/widget` pages. Their drawers retain titles, emoji, goals, themes, colors, width, scale, opacity and radius. Saving creates a private profile owned by the current browser's remembered SpaceTimeDB identity; the widget URL contains only an opaque `profile` token. Anyone with that URL can view it. Only its owner identity can save settings or toggle tasks; clearing browser site data loses that ownership. A widget picks up saved settings from another open tab every five seconds. Public BitCraft requires no game login or Discord account.

The October 3 browser checks covered desktop pages, 17 mobile routes, a mobile setup drawer, real market order books and claim listings, barter item/cargo results, recipe alternatives and gathered-material totals, calculator inputs, player selection and XP projections, guide pagination/reading, inline reader-card fixtures, widget goal persistence, task completion, and cross-browser read-only sharing/live updates. Browser artifacts are under ignored `output/playwright`. Unit checks exercise real public payload shapes and calculator math; the isolated BitCraft server checks verify catalog access, publisher-only guide writes, widget ownership, sharing, and identity reconnection.

Guide administration uses a local owner grant for the browser's remembered identity. Open **Guides → Editor access**, copy the command, run it from this folder, then refresh Guides. Granted browsers see New guide, draft status and Edit guide. The editor has ICHAA's formatting toolbar, inline item/crafting/gathering cards, card layout controls, write/preview tabs, image URLs and small embedded uploads. Saving and publishing run through caller-authorized reducers. Draft documents and their metadata are private; unpublishing removes them from public views. Clearing browser site data loses the saved editor identity. Revoke access with `npm run bitcraft:admin -- BROWSER_IDENTITY Admin --revoke`. Publishing/importing bundled content preserves existing edited guides.

Inventory trackers resolve players and inventories through the relay first, then fall back to the player APIs. Passive crafts use the player APIs first, with relay craft/claim data as fallback, including public catalog item labels and recipe-duration estimates.

Refresh the Brico mirror with `npm run bitcraft:sync-assets`; `-- --dry-run` reports missing assets, and `-- --force` downloads existing assets again. The manifest records upstream 404s separately from download failures. The October 3 sync added seven assets, retained 1,516 referenced assets and recorded 161 unavailable references with no download failures. Previously copied extra assets are retained.

Provider settings stay in private server tables. Defaults match ICHAA's configuration; its current overrides can be copied without exposing credentials to Vite:

```powershell
php scripts/export-bitcraft-providers.php
npm run bitcraft:providers -- .runtime/provider-config.json
```

The export is read-only and writes only an ignored local file. Bitjita's optional identity/token and application identifier are sent by the server. Configuration is publisher-only; anonymous visitors and guide editors cannot read or change credentials. Each actual outbound request consumes its provider's allowance. The primary player paths include player details with catalog skill metadata. Client paging concurrency and stall cache lifetime follow the server's public policy.

The additional October 3 checks created, edited, previewed, published and unpublished a guide with all three inline card types in the isolated BitCraft checks database. A separate browser verified public reading, hidden editing controls and draft denial. The editor and advanced toolbar fit a 375px viewport. Server checks cover administrator grants/revocation, draft/metadata privacy, edit preservation during import, failed-primary fallback and shared cache reuse. The playtest frontend on port 5181 now uses `space-bitcraft-checks` for BitCraft as well as its separate Evergather test database. Test content is kept off the main site.

This remains an active migration. `PLAN.md` tracks the broader work, including Evergather administration and external Discord configuration. Existing ICHAA progress has not been migrated. These checks establish local behavior, not production readiness or capacity under a large number of users.

## Content provenance

`spacetimedb/src/content/evergather.json` contains the current ICHAA game's definitions. `scripts/export-evergather.php` is an optional, one-time read-only export from the sibling Laravel app; PHP is not needed to run this application. It exports definitions, not accounts, inventories or private BitCraft runtime dumps. Public BitCraft data comes through whitelisted upstream endpoints; browsers cannot supply arbitrary URLs.

`scripts/export-bitcraft-ui.php` and `scripts/export-bitcraft-guides.php` are read-only exports of publicly exposed catalog definitions and currently published guides from ICHAA. The bundled BitCraft snapshot records its source date; it is served by the module and does not require Laravel at runtime. Draft guides, account records, game tokens and quarantined runtime data are not copied.
