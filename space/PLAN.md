# Space implementation plan

## Scope and decisions

- Standalone application in `space`; leave ICHAA and the older Evergather repository untouched.
- Vue 3, TypeScript, Vite, Tailwind; no Laravel or PostgreSQL in the running application.
- Two TypeScript SpaceTimeDB modules: public BitCraft tools and authenticated Evergather.
- Discord sign-in through SpacetimeAuth. Configure issuer and audience on the server; derive ownership from the authenticated caller.
- User requested a temporary login bypass on 2026-10-03. Local play uses a remembered browser identity; owner-only configuration restores Discord requirements without deleting player data.
- Public BitCraft needs no sign-in. Browser preferences remain local; upstream credentials and tracker secrets remain private.
- Preserve current ICHAA behavior and content unless the user chooses a smaller rebuild. Do not silently substitute the older Rust prototype's different game rules.
- Isolated local server at `127.0.0.1:3100`, Vue at `127.0.0.1:5180`; persistent local state under ignored `.runtime`.
- Public source and derived catalogs only in committed BitCraft assets. Do not copy quarantined client/runtime dumps or bearer tokens.

## Milestones

1. [ ] Foundation: Vue routing, navigation, responsive shell, local lifecycle scripts, isolated databases, generated bindings, build checks.
2. [ ] Identity: Discord OIDC login/callback/logout, configured server-side issuer/audience checks, private player views, administrator allowlist, clear unconfigured-login state.
3. [ ] Evergather core: current content export, character, skills and calibrated XP curve, gathering, activities, cooldowns, inventory and result history. Verify rollback, caller isolation, and multi-client synchronization.
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
