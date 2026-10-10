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

The BitCraft XP tracker continues refreshing in hidden tabs and refreshes immediately when you return. If the browser suspends it, the running collector retains minute XP observations so the tracker can restore its rate window and catch up an existing session. XP watches last six hours after the last request; other companion feed watches remain ten minutes. Catch-up respects player/source generations, resets and actual observation gaps. The collector and app database must stay running; an outage or exhausted watch still requires a fresh sampling window.

`npx tsx scripts/xp-background-check.ts [Character]` checks the managed local app by disconnecting its tracker reader for 75 seconds, then verifying collection advanced and the rate/session restore from history. The default character is Icha.

The existing local proxy can also serve https://space.test/evergather. ServBay's existing Cloudflare tunnel maps https://space.ichaa.dev to `space.test`. Vite allows both hostnames in development and preview and proxies `/v1` HTTP/WebSocket requests to SpaceTimeDB on port 3100. Public visitors use the site's secure WebSocket address; local development keeps the configured database address. ServBay, its Cloudflare tunnel, and the local app services must stay running for the public site to be available.

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
- BitCraft: ICHAA's public Vue tools and widget setup drawers, backed by public catalogs and a shared live-data cache. Market and Barter use a compact trading workspace with comparison rows and selected-trade details; recipe trees and totals, tool rates, hunting XP, open-craft projections, player selection, guide editing/publishing, and all four trackers retain their established layouts. Each provider has independent shared and per-browser request budgets, cache durations, refresh leases and cooldowns. Fresh fallback data wins over delayed primary data; otherwise the freshest valid stale result is used. Data refresh labels remain provider-neutral.

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

Activity, inventory, passive-craft and task trackers have both `/setup` pages and standalone `/widget` pages. Their drawers retain titles, emoji, goals, themes, colors, width, scale, opacity and radius. Edits to an existing profile save in the background, and Done saves before refreshing the preview. Pending edits survive a page refresh and resume saving, including cleared fields and custom Dataverse colors. Saving creates a private profile owned by the current browser's remembered SpaceTimeDB identity; the widget URL contains only an opaque `profile` token. Anyone with that URL can view it. Only its owner identity can save settings or toggle tasks; clearing browser site data loses that ownership. A widget picks up saved settings from another open tab every five seconds. Public BitCraft requires no game login or Discord account.

All four widgets use the shared custom emoji picker with name search, categories, skin tones, recent choices, and removable selections. Its complete Unicode 18.0 catalog (3,972 entries, including components) is served locally and fetched only when a picker opens. Search results render 120 entries per page. Locally hosted Noto Emoji SVGs render the picker and widget selections consistently, including flags and newer characters; artwork loads lazily for displayed pages and selections. Regenerate with `node scripts/sync-emoji-catalog.mjs` followed by `node scripts/sync-emoji-artwork.mjs`. The pinned [Unicode source](https://www.unicode.org/Public/18.0.0/emoji/emoji-test.txt), [Noto artwork release](https://github.com/googlefonts/noto-emoji/tree/e20cbc2bbec1926686be9f9bee7d1d2cfa1fea0e), and licenses are recorded under `public/assets/emoji`.

The October 3 browser checks covered desktop pages, 17 mobile routes, a mobile setup drawer, real market order books and claim listings, barter item/cargo results, recipe alternatives and gathered-material totals, calculator inputs, player selection and XP projections, guide pagination/reading, inline reader-card fixtures, widget goal persistence, task completion, and cross-browser read-only sharing/live updates. Browser artifacts are under ignored `output/playwright`. Unit checks exercise real public payload shapes and calculator math; the isolated BitCraft server checks verify catalog access, publisher-only guide writes, widget ownership, sharing, and identity reconnection.

Guide administration uses a local owner grant for the browser's remembered identity. Open **Guides → Editor access**, copy the command, run it from this folder, then refresh Guides. Granted browsers see New guide, draft status and Edit guide. The editor has ICHAA's formatting toolbar, inline item/crafting/gathering cards, card layout controls, write/preview tabs, image URLs and small embedded uploads. Saving and publishing run through caller-authorized reducers. Draft documents and their metadata are private; unpublishing removes them from public views. Clearing browser site data loses the saved editor identity. Revoke access with `npm run bitcraft:admin -- BROWSER_IDENTITY Admin --revoke`. Publishing/importing bundled content preserves existing edited guides.

Players, skills, inventory and passive crafts use the collected relay state first, with BitJita as the HTTP fallback where supported. Open Crafts combines current selected-player jobs with BitJita recipe XP and the public job directory. Task checklists remain manual.

### Shared BitCraft collection

The [data source and subscription reference](docs/bitcraft-data-reference.md) separates today's tools from the proposed subscription foundation and twelve optional feeds. Each card explains what the data provides, an example use, and when to choose it. A printable, linked [PDF edition](output/pdf/BitCraft_Data_Sources_and_Subscriptions.pdf) includes the same reference.

The Windows start/stop/restart launchers also manage the BitCraft collector. It authenticates with its own saved local identity, receives an owner grant, and writes validated snapshots through reducers. Browsers register ten-minute watches for the public records they use, then subscribe to locally stored updates. BitCraftSync supplies player skills, inventories, housing, claim storage, crafts, session observations and nearby resource summaries. BitJita supplies world directories, public crafts, market orders and completed-trade price statistics. BitJuice is disabled by default. Provider credentials remain private.

Directory collection progresses through claims and stalls, up to 100 pages per directory. Player and claim detail collection follows active watches; this is not a complete mirror of every regional table. Refresh intervals range from two seconds for last-known session state to an hour for stable directories, subject to shared budgets, provider caches and backoff. Errors preserve the previous complete snapshot and show a delayed refresh. The module rejects malformed or out-of-order snapshots and unsafe numeric IDs before replacing a scope. Collector restarts and source changes break XP continuity; backward XP retains the trusted baseline.

Current storage is bounded to 500 feed scopes and 100,000 projected entities. XP history retains up to six hours of minute samples with a global 6,000-sample cap. `.runtime/collector/space-bitcraft-tools` keeps the collector identity; duplicate compressed checkpoints have been removed. Nearby resource collection decodes the relay's versioned 400×400 window, excludes paving, counts origin tiles once, and displays the nearest node coordinates. It uses the player's last known position and does not establish that the player is online.

Inventory and Crafting can include housing and an explicitly selected claim's storage. Crafting's Held/Missing comparison applies to the displayed gross ingredient totals; it does not optimize recipe branches around stock or reserve materials. Hunting can fill its inputs from collected skill XP. Tool Rates compares estimates with measured XP and offers nearby resources. Market order books include 24-hour and seven-day completed-trade averages when available.

For a separate collector terminal, run `npm run bitcraft:collector -- --authorize` with the local database running. Automatic owner authorization is restricted to localhost. Optional `BITCRAFT_COLLECTOR_PLAYERS` contains comma-separated decimal player IDs to keep selected players watched. `BITCRAFT_COLLECTOR_HOST` and `BITCRAFT_COLLECTOR_DATABASE` select another target; remote operation requires a pre-authorized collector identity. Logs and startup readiness are under `.runtime/services`. Use a single collector per database. SQL tables `collection_status`, `collection_feed`, `collected_entity` and `collection_sample` expose health, scope, provenance and timestamps without provider credentials. Native upstream WebSocket replication, elevation maps and a permanent historical archive remain separate extensions.

Use `npm run bitcraft:publish` for main BitCraft module updates, or `npm run bitcraft:publish-test` for the separate test database. Publication pauses and resumes only the selected database's managed worker and updates its sealed maintenance artifact. The lifecycle launchers manage both the main collector and a separate test storage guard. Each database has its own 2 GB maintenance threshold and 3 GB monitored budget. The test guard does not subscribe to global relays or poll APIs while idle. Direct CLI publication bypasses this managed workflow. The lifecycle scripts themselves never publish or reset data; their storage-aware workers can perform verified maintenance when a threshold is reached. See [storage and recovery](docs/bitcraft-storage.md).

Refresh the Brico mirror with `npm run bitcraft:sync-assets`; `-- --dry-run` reports missing assets, and `-- --force` downloads existing assets again. The manifest records upstream 404s separately from download failures. The October 3 sync added seven assets, retained 1,516 referenced assets and recorded 161 unavailable references with no download failures. Previously copied extra assets are retained.

Provider settings stay in private server tables. Defaults match ICHAA's configuration; its current overrides can be copied without exposing credentials to Vite:

```powershell
php scripts/export-bitcraft-providers.php
npm run bitcraft:providers -- .runtime/provider-config.json
```

The export is read-only and writes only an ignored local file. Bitjita's optional identity/token and application identifier are sent by the server. Configuration is publisher-only; anonymous visitors and guide editors cannot read or change credentials. Each actual outbound request consumes its provider's allowance. The primary player paths include player details with catalog skill metadata. Client paging concurrency and stall cache lifetime follow the server's public policy.

The additional October 3 checks created, edited, previewed, published and unpublished a guide with all three inline card types in the isolated BitCraft checks database. A separate browser verified public reading, hidden editing controls and draft denial. The editor and advanced toolbar fit a 375px viewport. Server checks cover administrator grants/revocation, draft/metadata privacy, edit preservation during import, failed-primary fallback and shared cache reuse. The playtest frontend on port 5181 now uses `space-bitcraft-checks` for BitCraft as well as its separate Evergather test database. Test content is kept off the main site.

This remains an active migration. `PLAN.md` tracks the broader work, including Evergather administration and external Discord configuration. Existing ICHAA progress has not been migrated. These checks establish local behavior, not production readiness or capacity under a large number of users.

## Site appearance

The shared palette and typography live in `src/styles/theme.css`: local Inter variable fonts, dark plum surfaces, cream foregrounds, gold accents and mint highlights. Font files and their SIL Open Font License are bundled under `public/fonts/inter` from [the official Inter project](https://github.com/rsms/inter). Regular and italic weights use local assets rather than relying on an installed system font or external font CDN.

Base styles use the base cascade layer; shared controls and tool styles use the components layer; Tailwind utilities remain free to style individual controls. The older BitCraft/Evergather `revert-layer` resets were removed. Component warnings/errors use dedicated semantic colors, while the optional widget presets and authored guide formatting keep their deliberate choices. Code blocks retain monospace typography. This theme covers Home, navigation, BitCraft pages/widgets, guides/dialogs and Evergather.

## Content provenance

`spacetimedb/src/content/evergather.json` contains the current ICHAA game's definitions. `scripts/export-evergather.php` is an optional, one-time read-only export from the sibling Laravel app; PHP is not needed to run this application. It exports definitions, not accounts, inventories or private BitCraft runtime dumps. Public BitCraft data comes through whitelisted upstream endpoints; browsers cannot supply arbitrary URLs.

`scripts/export-bitcraft-ui.php` and `scripts/export-bitcraft-guides.php` are read-only exports of publicly exposed catalog definitions and currently published guides from ICHAA. The bundled BitCraft snapshot records its source date; it is served by the module and does not require Laravel at runtime. Draft guides, account records, game tokens and quarantined runtime data are not copied.

### Performance and simulated players

The retired Southern, Western, Eastern and Northern Islands (region IDs 3, 11, 15 and 23) are hidden from region choices, market orders and summaries, barter listings and public open crafts. All-region prices, quantities and fill estimates use visible regions only. Canonical snapshots remain stored; the collector rebuilds older summaries locally so retired offers disappear without waiting for an upstream refresh.

BitCraft routes do not connect to Evergather. Evergather's page code is lazy-loaded, and the catalog subscription follows the active section, including metadata for its definitions and owned items. The complete inventory guide loads its sources and uses when opened. Marketplace and leaderboard subscriptions exist only while their sections are open. UI scope is per connection, so two tabs for the same character can use different sections or market pages; disconnecting removes the temporary scope.

The marketplace computes its public pages and totals once in shared anonymous views, then subscribes to 50 public listings on the selected page. A caller-only view uses the seller index for own escrow, merged by listing ID so items appearing on both feeds are shown once. The UI automatically selects a valid page when sales or cancellations shrink the market. Each character can hold at most 100 active listings. Leaderboards retain the top 20 entries for wealth, total XP and each skill, including specialists who are outside the overall top 20. Existing public tables remain available; these bounds apply to the application's subscriptions. Journal history already retains 30 results per player and public sales retain 100 transactions. Market search and sorting apply to the displayed page.

BitCraft Market reads a durable stored dataset. Leaving Find item empty browses all regions or the selected region without upstream market/order requests. The complete matching stored item set is filtered and sorted before local pages of 20 are displayed; paging, category filters and order inspection reuse stored data. Typing an item or opening an exact item link shows stored matches immediately and queues a shared refresh. Duplicate searches coalesce, recently refreshed books are reused for 30 seconds, and subscription updates only reread the database: they do not enqueue another refresh. Completed-trade history remains an explicit on-demand lookup when History or the full popup is opened. An explicitly selected empire resolves membership through the shared reference cache, then scopes the stored orders to its claims.

The managed collector discovers the complete world item directory every five minutes, retains a canonical unfiltered book for each item/cargo, and publishes summaries for all regions and individual regions. Full books and summaries live in `market_item`, `market_meta`, `market_summary` and `market_status`, independently of the temporary request cache and collection-feed caps. Valid complete cache entries can seed the initial dataset. A full directory confirming no offers supplies an empty initial book; previously populated books require their own complete refresh before offers are removed. Successful complete books reconcile cancelled orders and replace their regional summaries atomically. Failed, malformed or older responses preserve the last good book. Removed directory items stop appearing in the active dataset without deleting their retained book.

Before native coverage is established, background HTTP books have a 30-minute rolling refresh target; sweep time depends on dataset size, HTTP latency and the shared budget. Once all nine native regions have applied, rolling books stop and item searches retain targeted API checks. Native regional rows remain authoritative during an outage; disabling the transport does not silently restore HTTP authority. The collector requests at most two market tasks per cycle, uses the existing server cache/budget/backoff, and bounds other watched feeds so a cold directory scan cannot monopolize a cycle. Initial collection coverage remains visible, missing prices stay unknown, and regional results can expand while their books arrive. Stored books remain browsable through upstream failures and collector restarts. The server's indexed book reads and public status subscription keep the selected details current without downloading all raw orders into the browser. `npm run test:market-server` publishes fixtures into a new isolated database and checks authorization, complete world/region/claim reads, zero-request browsing, coalescing, retained failures, and cancellation reconciliation.

The October 7 native relay integration connects the managed collector to BITCONNECT coin orders and player barter in all nine current regions. BitCraftSync subscriptions supply selected-player XP and invalidate enriched inventory/craft API snapshots on raw changes. A live local check loaded 9,100 item/cargo entries, 1,304 barter stalls, and Icha's 20 native skill records. These counts are an observed snapshot and change with the game. Raw provider schemas are pinned separately; large IDs are preserved as decimal strings. Complete regional snapshots are reconciled before readiness, late API books cannot overwrite native regional rows, and transport failures retain stored data with coverage warnings.

Passive craft countdowns use the live `passive_craft_state` processing timestamp plus the catalog recipe duration. The collector includes those rows in its existing selected-player snapshot; the enriched HTTP craft list supplies item/station/claim details. The game's [queue transition](https://github.com/ClockworkLabs/BitCraftPublic/blob/main/BitCraftServer/packages/game/src/game/entities/passive_craft_state.rs) updates that timestamp when processing begins. Queued timestamps are not treated as start times. Each group shows the latest running craft's estimated finish and any crafts still waiting to start. Countdowns tick locally each second and retain their absolute deadline across refreshes. At zero they show “Estimate reached” until completion is confirmed. If live timing is unavailable, the existing recipe estimate remains a fallback.

XP Tracker at `/bitcraft/activity/setup` now has full, compact popout and OBS presentations, up to five character tabs, session XP, observed active time, measured rate/ETA, skill goals, passive crafts. The tracker is silent in every presentation. **Popout** opens a companion window; **Copy OBS URL** supplies an opaque shared profile URL. OBS has no editing/audio controls and a transparent page; panel opacity is configurable. Unknown XP, backward observations, provider changes and gaps over two minutes cannot create gains. Rate estimates require at least a minute of continuous observations. A local `space.test` URL must resolve on the OBS machine.

The current skill heading can include `Skill - [icon] Source`. The existing Relay session supplies a current Craft/Extract recipe ID, which is resolved through the cached local catalog. Crafting shows the output item; gathering shows the resource because several random drops can share one action. The source appears only for a matching displayed skill and player, and expires after the action's 20-second grace. Cancelled, signed-out, delayed, unknown and unrelated actions leave the skill heading without a source. This identifies the observed activity rather than allocating historical XP to individual item drops, and adds no Relay calls.

When passive crafts are enabled, the XP widget shows one compact row per output/claim batch, with total output quantity and a ticking completion estimate. It shares the standalone passive tracker snapshot, Relay start times, recipe durations and countdown formatting. The setting applies to full, popout and OBS presentations; disabling it skips the craft snapshot reads. Queued crafts wait for a processing start and expired estimates wait for completion confirmation.

Trading publication targets three seconds between changed regional saves, watched XP ten seconds, and relay heartbeats fifteen seconds; HTTP enrichment and database work can increase the interval. Initial ingestion includes joins and summary writes and takes longer than the research order-only timings. Player fan-out is capped at thirty native scopes; extra users retain shared API paths. `npm run test:relay-server` checks authority, reconciliation and generation readiness; `npm run test:relay-live` probes provider schemas/snapshots; `npx tsx scripts/relay-runtime-check.ts` checks the managed app; `npm run test:xp-render` checks the three component presentations with isolated network/navigation fixtures. The optional data feed catalog remains in [the editable reference](docs/bitcraft-data-reference.md) and [the readable PDF](output/pdf/BitCraft_Data_Sources_and_Subscriptions.pdf). Terrain and full-world inventories are separate optional work.

Market comparisons use shopper intent (buy or sell), aligned unit prices and stock, and the best claim from stored summaries. The inspector estimates partial fills, lists every available claim in a local filter, sorts all matching orders by price, quantity or claim name, then displays pages of 20. Estimates use all matching orders, including those beyond the current order page; selecting a claim scopes the estimate to that claim. The full order-book popup remains available. Search refresh keeps current rows visible; selection, quantity, item page and browsing preferences are remembered per tool in the browser session. On phones, selecting a row moves to its details with a Back to results action.

Barter displays each stall order once as exact You give / You get stacks, including cargo. Coin-only trades, item swaps, mixed bundles and one-sided source listings have distinct labels and filters. Estimates multiply all required and offered stacks by whole bundles and cap at known remaining stock; unknown stock stays explicit. Only a single item traded for coins alone receives a coin unit price. Mixed bundles and item swaps remain unpriced, and one-sided listings state which side is missing. These are read-only plans, not reservations or game actions.

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

## BitCraft storage budget

SpacetimeDB remains the only database. The collector now publishes changed regional item books and barter stalls, removes cancelled rows, and skips unchanged market projections. A disk monitor triggers a verified storage rebuild around 2 GB within a 3 GB BitCraft budget. Short write leases pause updates if monitoring stops; guides, widget ownership/settings, administrator grants, provider configuration and bounded XP history survive maintenance. Rebuildable market and API data reload afterward. See [the storage and recovery guide](docs/bitcraft-storage.md) for scope, thresholds, commands and the difference between this monitored budget and an OS-enforced hard quota.


### October 8 relay storage efficiency

The collector now stores individual native market orders and barter exchanges, shares claim/owner labels, and reconstructs global books for reads. Barter legs use catalog IDs and quantities. Refresh timestamps, retry leases and feed errors use small status records, so unchanged responses do not rewrite large payloads. Always-on API seeds are regions, empires and levels; claim and stall pages are collected for requested searches/fallback scopes.

Maintenance preserves the latest market dataset in bounded owner-only chunks alongside existing application and XP preservation. It hashes backups, checks every retained field, resumes interrupted restoration safely and removes only the verified retired replica. Main and test retain independent 2 GB maintenance targets and 3 GB monitored budgets; test stays in storage-only mode. See [the storage guide](docs/bitcraft-storage.md).

Repeatable diagnostics: `npx tsx scripts/relay-storage-audit.ts` reports aggregate logical payload sizes and physical disk use without exporting private payloads. `npx tsx scripts/relay-growth-sample.ts 360 steady` measures a live six-minute log-growth interval. `npm run test:refresh-cache-server` verifies unchanged-cache/feed writes using the live HTTPS regions API; the populated-market storage server test exercises interruption and exact recovery. Temporary market/relay test databases are removed by verified database/replica identity after checks.
