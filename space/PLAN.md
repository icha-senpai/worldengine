# Space implementation plan

## Scope and decisions

- Standalone application in `space`; leave ICHAA and the older Evergather repository untouched.
- Vue 3, TypeScript, Vite, Tailwind; no Laravel or PostgreSQL in the running application.
- Two TypeScript SpaceTimeDB modules: public BitCraft tools and authenticated Evergather.
- Discord sign-in through SpacetimeAuth. Configure issuer and audience on the server; derive ownership from the authenticated caller.
- User requested a temporary login bypass on 2026-10-03. Local play uses a remembered browser identity; owner-only configuration restores Discord requirements without deleting player data.
- User requested removal of Evergather cooldowns on 2026-10-03. Gathering and activities have no action timer, and jobs have no daily completion limit. Materials, progression, tool durability and economy rules remain in force.
- Public BitCraft needs no sign-in. Browser preferences remain local; upstream credentials and tracker secrets remain private.
- Preserve current ICHAA behavior and content unless the user chooses a smaller rebuild. Do not silently substitute the older Rust prototype's different game rules.
- Isolated local server at `127.0.0.1:3100`, Vue at `127.0.0.1:5180`; persistent local state under ignored `.runtime`.
- Public source and derived catalogs only in committed BitCraft assets. Do not copy quarantined client/runtime dumps or bearer tokens.

## Milestones

1. [ ] Foundation: Vue routing, navigation, responsive shell, local lifecycle scripts, isolated databases, generated bindings, build checks.
2. [ ] Identity: Discord OIDC login/callback/logout, configured server-side issuer/audience checks, private player views, administrator allowlist, clear unconfigured-login state.
3. [ ] Evergather core: current content export, character, skills and calibrated XP curve, immediate gathering and activities, inventory and result history. Verify rollback, caller isolation, and multi-client synchronization.
4. [ ] Evergather economy: crafting, equipment, durability/repairs, rarity and tier upgrades, jobs, expeditions, shop, marketplace, achievements/rewards, world events, leaderboard and content administration.
5. [ ] BitCraft catalog/tools: shared ingestion with cache/budget/backoff, market/order book, barter stalls, recipe trees, tool rates, hunting calculator, guides with inline item cards, player search/open crafts.
6. [ ] BitCraft trackers: activity, inventory, passive crafts, tasks, configurable widgets, private tracker setup and provider-neutral refresh status.
7. [ ] Verification and delivery: production build, meaningful server tests, browser checks on desktop/mobile, public/private access tests, persistence across restart, reproducible setup and actual feature status.

## External configuration

Discord OAuth application and SpacetimeAuth project/client credentials are required for a real Discord login. Keep secrets out of Vite environment variables and committed files. Build and verify every independently testable path while these are pending. Do not enable anonymous gameplay as a production substitute.

Existing player progress is not automatically linked to Discord accounts. A later progress import must use an explicit account mapping and a dry run; do not invent ownership or modify the existing database.

## Completion criteria

A starter screen or build success alone does not complete the migration. Mark features complete only when their server behavior, UI, and relevant access rules work. Record any unported feature and external blocker explicitly below.

## Progress

- Initialized official Vue/TypeScript template using installed SpaceTimeDB 2.10.0.
- Existing ICHAA has unrelated in-progress hunting-calculator changes; source files will remain untouched.
- No existing local SpaceTimeDB listener was found on the checked ports.
- October 3 public BitCraft UI port: original Market/Barter pages and order-book dialogs, recipe planner/tree/totals, Tool Rates, Hunting XP, Open Crafts, player picker, published Guide index/reader, four tracker setup drawers and standalone widgets. Public catalog/icons and published guide metadata are bundled; live public data uses the shared server cache.
- Widgets now save private caller-owned settings and expose opaque read-only share URLs. Owner task updates and cross-tab settings updates are supported. Public tools remain free without login.
- October 3 BitCraft completion: Brico asset synchronization/refresh command; private provider configuration matching ICHAA, independent budgets/caches/fallback; relay inventory and passive-craft fallback; guide administrator grants, editor, inline card pickers, drafts, preview and publishing. Public/draft access, owner configuration, failed-primary fallback/shared cache, and desktop/mobile browser flows are verified in README.md. The earlier broad migration goal remains paused.

## October 3 performance pass

- Complete: disconnect Evergather on other routes; lazy-load its page and scope catalog by section and connection.
- Complete: marketplace pages of 50 plus own escrow (100 active listings per character), top 20 per leaderboard, panel-scoped live subscriptions.
- Complete: BitCraft market pages of 20 priced items and groups of 10 scoped claims; preserve filters/categories and share upstream cache across page changes.
- Complete: repeatable independent-client load harness with isolated databases, real reducers and no think-time delay, conservation/fee/ownership checks, page bounds and competing buyers.
- Verified: final 30-client, 90-second run, 451 purchases, zero unexpected failures; all 15 browser sections, main BitCraft paging and character reconnect; build and unit/server checks.
- Remaining performance limits: inventory guide still loads a large catalog on demand; server marketplace/leaderboard views scan stored rows when recomputing; external claim pagination and cold barter scans depend on provider volume. Larger production datasets and sustained WAN load are not benchmarked.

## October 3 cooldown removal

- Complete: zero gathering/activity cooldowns in server rules and UI; owner content refresh clears existing timers.
- Complete: contracts have unlimited completions with normal acceptance, objective and material checks.
- Verified: continuous 30-client rerun, 54 rounds over 91 seconds, 1,680 gathers, 1,621 purchases and zero unexpected failures; market snapshot synchronization is included in conservation checks.
- Verified: 46 unit tests, isolated server checks, production build, browser repeated actions and mobile contract labels; main module published with existing progress preserved.

## October 3 shared marketplace views

- Complete: anonymous public market pages and totals computed once for all subscribers; seller-index own escrow, merged without duplicate listings.
- Complete: selected-page SQL subscriptions, independent browser tabs, automatic page adjustment when the market shrinks; legacy views retained for compatibility and repeatable baseline comparison.
- Complete: database-scoped server profiling counters in the load harness, with absent queue measurements represented explicitly.
- Verified: fresh 30-client/91-second comparison, 1,441 to 3,961 purchases, zero unexpected failures; buying p95 431 to 135 ms and cancellation p95 524 to 139 ms.
- Verified: 47 unit tests, server economy/ownership checks, build, real browser paging/cancellation/mobile checks; published to main with existing progress preserved.
- At this stage, scoped catalog recomputation was the largest measured view cost; addressed below. Shared market sorting still scales with stored listings. These are local measurements.

## October 3 whole-game definition separation

- Complete: shared core, gathering, activities, recipes, equipment, jobs, expeditions, shop, achievements and inventory-guide definition feeds; current payload references determine included item descriptions.
- Complete: private stable item/reward membership, transactional synchronization including both owners of a transferred tool, and backfill of existing characters without changing progress.
- Complete: panel-scoped definition subscriptions, separate definition revision, parsed payload reuse, and live eligibility derived from current player/skill/inventory/tool/contract/reward rows.
- Verified: isolated whole-game actions, caller privacy, unchanged definition events for quantities and wear, cached recipe/job/expedition eligibility, upgrades/repair, both sides of tool transfers, salvage, failed-action rollback and changed-content/cache invalidation.
- Verified: 47 unit tests, server rules and module typecheck, production build; all 15 browser sections, real cached-recipe crafting, independent same-character recipe/shop tabs, market paging/clamping, mobile fit and remembered-character reconnect.
- Verified: fresh 30-client/90-second comparison, 3,391 to 8,641 purchases, zero unexpected failures; buying p95 156 to 64 ms and cancellation 164 to 65 ms. Catalog computation fell 99.46% (35,925 to 195 ms); core definitions did not recompute during the workload.
- Published locally with existing characters and gameplay tables preserved. Legacy catalog views remain for compatibility and `--legacy-catalog` comparisons.
- Remaining performance limits: full inventory guide is a large on-demand payload; journal projections and market sorting still do work per affected update. Long-running large inventories, large stored markets and WAN workloads remain unbenchmarked.
