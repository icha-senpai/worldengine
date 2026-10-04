# DataVerse Space

Standalone Vue 3 + TypeScript frontend with two SpaceTimeDB 2.10 modules. BitCraft is public. Evergather currently uses remembered browser identities for local play; Discord through SpacetimeAuth can be restored later. The running app has no Laravel or PostgreSQL dependency. Existing ICHAA files and databases are left untouched.

## Local development

On Windows, double-click `start-test.cmd`, `stop-test.cmd`, or `restart-test.cmd`
to manage the existing local database and both frontends. The main app is at
https://space.test/evergather (port 5180). The test UI is at
http://127.0.0.1:5181/evergather and SpaceTimeDB listens on port 3100. These
scripts use `.env.local` for the main app and `.env.playtest.local` for tests,
preserve `.runtime/data`, and leave unrelated services alone. Starting twice
reuses the running services. Logs
are saved in `.runtime/services`. They do not publish or reset any databases.

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
npm run test:catalog-server # Fresh fixture for all definition and live-state dependencies.
npm run test:bitcraft-server # Publishes only space-bitcraft-checks.
npm run test:load -- --users 30 --seconds 90 # Fresh isolated database each run.
npm run build
```

Server tests mint local fixture identities in a separate database. They exercise server rules rather than providing an anonymous production login. A fresh identity is used on every run. No player accounts or tokens are imported from ICHAA.

## Current implementation

- Responsive Vue shell, public BitCraft navigation, OIDC callback/logout, generated typed bindings.
- Evergather: current content and calibrated XP thresholds; character and 38 skills/field tools; gathering and activities without action cooldowns, unlimited job turn-ins, inventory, crafting, shop, expeditions, equipment lifecycle, tier/rarity upgrades, marketplace with price bands and 5% fee, NPC vendor, achievement claims/titles, world-event bonuses, live leaderboard and journal.
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

### Performance and simulated players

BitCraft routes do not connect to Evergather. Evergather's page code is lazy-loaded, and the catalog subscription follows the active section, including metadata for its definitions and owned items. The complete inventory guide loads its sources and uses when opened. Marketplace and leaderboard subscriptions exist only while their sections are open. UI scope is per connection, so two tabs for the same character can use different sections or market pages; disconnecting removes the temporary scope.

The marketplace computes its public pages and totals once in shared anonymous views, then subscribes to 50 public listings on the selected page. A caller-only view uses the seller index for own escrow, merged by listing ID so items appearing on both feeds are shown once. The UI automatically selects a valid page when sales or cancellations shrink the market. Each character can hold at most 100 active listings. Leaderboards retain the top 20 entries for wealth, total XP and each skill, including specialists who are outside the overall top 20. Existing public tables remain available; these bounds apply to the application's subscriptions. Journal history already retains 30 results per player and public sales retain 100 transactions. Market search and sorting apply to the displayed page.

BitCraft market searches price 20 items per page, and regional/empire/claim searches load listings for 10 matching claims at a time. Page buttons preserve filters, and all candidate categories remain available. Local page numbers are excluded from upstream cache keys. Upstream pagination within a claim, matching-claim discovery and cold barter-stall scans still depend on external dataset size and provider limits.

`npm run test:load -- --users 30 --seconds 90` publishes the current module to a new `space-evergather-loadtest-<timestamp>` database. Each simulated client gets a separate server-issued identity and character, subscribes to scoped catalog and private views, claims legitimate level-one rewards, buys materials, crafts, gathers, lists items, buys another player's listing and cancels its own. Rounds repeat as soon as transactions finish, without a think-time delay. Clients gather and craft repair supplies when tools wear out. The test also checks more than one market page and two buyers racing for one listing. It asserts private ownership, item conservation including escrow, exact rounded fees, cancellation, immediate repeat actions and stale-purchase rejection. Connections close after the run; the fixture database is retained for inspection. No main game database or user identity is imported or cleared. JSON reports are written under ignored `output/performance`.

On October 3, the user requested removal of Evergather waiting rules. Gathering and skill activities now have zero cooldown, existing character action timers are cleared during content refresh, and contracts have no daily completion cap. Tool wear, repair costs, skill requirements, materials, prices, ownership and trade fees still apply. BitCraft provider request limits and backoff protect external API access and are separate from Evergather gameplay.

The earlier October 3 paced run used 30 connected clients for a 90-second market workload, completed 60 gathers, 30 crafts and 451 successful purchases, and observed zero unexpected failures. Median/p95 reducer round trips were 143/319 ms for listing, 200/324 ms for buying and 370/397 ms for cancellation. These measurements include synchronized bursts of 30 requests on this development machine; they are not a production capacity guarantee or a comparison against an equivalent ICHAA load test. Browser QA visited all 15 sections, verified catalog removal when switching sections, independent same-character tabs, remembered-character reconnection, and no Evergather connection on BitCraft. A live Plank search showed 20 then 7 of its 27 results with working page controls. The full inventory guide remains the largest catalog view (about 4.4 MB of uncompressed JSON payload for the current fixture), loaded only when opened.

The October 3 cooldown-free rerun completed 54 rounds in 91 seconds with 30 clients, 1,680 gathers, 30 setup crafts and 1,621 successful purchases. There were zero unexpected failures and 55 expected stale/racing purchase rejections. Median/p95 reducer round trips were 63/138 ms for gathering, 162/401 ms for listing, 229/426 ms for buying and 447/523 ms for cancellation. The harness waits for every market subscriber to observe completed trades before combining inventory and escrow snapshots; without that barrier a stale escrow row can produce a false conservation failure. The earlier paced run and this continuous workload have different request rates. These are local development measurements. Report: `output/performance/space-evergather-loadtest-1791066527858.json`.

Verification for this change passed 46 unit tests, the production build, isolated server checks for consecutive gathering/activities and four daily contract completions beyond the old cap, and browser repeat actions with zero timers. Jobs fit a 375px viewport. Publishing cleared the two existing main character timers and preserved their remaining player fields, inventory, skills, tools, contracts and claimed rewards.

### Shared market update optimization

The old per-viewer `market_listings` and `my_market_page` views remain for compatibility and an optional benchmark baseline. The application uses `market_page_listings WHERE page = N`, `my_market_listings` and `market_summary`. Public pages are anonymous views, so the server shares their materialization across subscribers; the own-listings view depends only on the caller's seller-index range. The database remains authoritative for transfers, fees and ownership. This follows the [official anonymous-view guidance](https://spacetimedb.com/docs/functions/views/).

The load harness reads `/v1/metrics` immediately before and after its timed workload and selects counters by the fresh fixture database identity. Reports include view invocation counts and computation time, module runtime and reducer-plus-subscription execution means. Queue samples are absent for regular reducer calls on this local V8 server and are reported as unmeasured. Counters are collected outside the workload clock. For the earlier market and catalog subscription path, run `npm run test:load -- --users 30 --seconds 90 --legacy-market --legacy-catalog`; the default exercises the current application path.

Fresh October 3 comparisons both used 30 clients and 91 seconds. The legacy path completed 1,441 purchases; the shared path completed 3,961, with 4,071 gathers, 120 total crafts and 30 legitimate repairs. Both had zero unexpected failures and passed all economy invariants. Median/p95 ms changed from 186/420 to 66/125 for listing, 271/431 to 74/135 for buying, and 491/524 to 125/139 for cancellation. Public-page and own-escrow views together reduced market view invocations from 60 to 3 per listing change. Mean server execution including subscription queries fell from 14.07 to 4.40 ms for buying and 14.13 to 4.14 ms for cancellation; module game logic remained below 1 ms on average.

The comparison report is `output/performance/market-comparison-2026-10-03.json`, with source reports `space-evergather-loadtest-1791067873032.json` and `space-evergather-loadtest-1791068218862.json` in the same directory. Browser checks created 60 legitimate test listings, verified 50/10 public page windows, deduplicated own escrow, independent same-character tabs, automatic page adjustment as the market shrank to 50, cancellation cleanup, and no overflow at 375px. All 47 unit tests, isolated server checks and the production build passed. Main player progress was verified unchanged during publication. At this stage, the scoped catalog was the largest profiled view cost; the following change addresses it. Very large stored markets and sustained WAN workloads remain unbenchmarked.

### Whole-game definition feeds

The application subscribes to shared anonymous definitions by game system, plus independent caller-owned player, skill, inventory, equipment, contract, reward and journal state. Public market pages and ranks remain panel-scoped. Definitions no longer depend on quantity, gold, XP or durability. The shared groups contain the current catalog payloads and metadata for the items those payloads reference.

| Definition feed | Loaded for |
| --- | --- |
| Core | Character options, skills, equipment families/tiers, rarity rules and world events; always connected |
| Gathering / activities | Their respective Gather section; both for skill unlock paths |
| Recipes | Craft recipes |
| Equipment | Tool tier upgrade definitions |
| Jobs / expeditions | Their respective Craft section |
| Shop | Trade shop |
| Achievements | Overview progression |
| Inventory guide | Full item sources and uses; loaded only when opened |

`my_reference_catalog` supplies descriptions of owned item types and claimed achievements. Its private `catalog_reference` backing table changes only when membership changes: receiving the first unit, spending the last unit, gaining or losing the last tool with a given item key, or claiming a reward. Repeated quantities and tool wear do not write reference rows. Escrow listings carry their own metadata. Tool purchases synchronize both owners' references in the same transaction. Character creation and publisher content refresh populate references for new and existing characters.

The frontend keeps a separate definition revision and reuses parsed payloads. Eligibility is derived from committed live rows, so crafting consumes ingredients and updates readiness without fetching recipes again; gold changes shop affordability, job deliveries update progress, and skill XP updates unlocks. Content publication updates shared definitions and replaces changed parsed payloads. Unchanged payloads keep their cache objects. Catalog parsing never applies gameplay deltas.

`my_catalog` is retained for existing clients and comparison only. Add `--legacy-catalog` to the load command to compare its subscription with the shared-definition path while keeping the shared marketplace unchanged. Add both `--legacy-catalog --legacy-market` to exercise both earlier paths. Each command creates a fresh fixture database.

The catalog server checks exercise cached recipe/expedition/job eligibility, quantity and durability isolation, first/last item membership, tier upgrades and repairs, reward claims, caller privacy, both sides of tool transfers and salvage. A content-change fixture is copied into ignored `.runtime`, then published only into that run's isolated database to verify fresh references and cache invalidation. No main content or progress is replaced by the test.

Fresh October 3 runs used 30 clients for 90 seconds with the same shared-market path. The earlier catalog subscription completed 3,391 purchases; the new feeds completed 8,641, with 8,792 gathers, 210 total crafts and 60 legitimate repairs. Both had zero unexpected failures and passed item conservation, ownership, rounded-fee, cancellation and competing-buyer checks. Buying median/p95 changed from 86/156 to 35/64 ms; cancellation from 148/164 to 56/65 ms. Gathering p95 fell from 162 to 63 ms and listing from 146 to 55 ms.

Catalog work fell from 17,123 `my_catalog` calls and 35,925 ms of computation to 223 `my_reference_catalog` calls and 195 ms, a 99.46% reduction despite the higher completed workload. Core definitions had zero recomputations during the timed run. Mean server execution including subscription queries changed from 5.09 to 2.03 ms for buying and 4.89 to 1.84 ms for cancellation. The comparison is `output/performance/catalog-comparison-2026-10-03.json`; source reports are `space-evergather-loadtest-1791071182928.json` and `space-evergather-loadtest-1791071326749.json` in the same directory. Setup snapshot latencies are excluded from this comparison because their initial catalog scopes differ.

Browser checks walked all 15 sections, verified a real Craft button updated live quantities without changing the definition revision, and kept recipe and shop subscriptions independent across two tabs while synchronizing their character state. Market paging, own escrow, shrink/clamp and cleanup passed again with the new catalog path, including a 375px viewport. A foreign item's actual metadata rendered after leaving and reopening the market. Content refresh preserved main player state and gameplay tables. The full inventory guide still loads about 4.4 MB uncompressed on demand; journal projection and market sorting remain measured work. These results describe a local synchronized market workload, not WAN latency or production capacity with a large stored market.
