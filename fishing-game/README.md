# Fishbound

Rust / SpaceTimeDB authority, Serenity + Poise Discord bot, and SvelteKit companion.
All 251 fish and all seven specified biomes are implemented and locally verified.
The ServBay/Cloudflare site is live at https://fish.ichaa.dev and the Discord bot
connects. Command delivery and the complete OAuth login still need live verification.

## Implemented

- All 251 supplied fish with stable names, catch ranks, stats, and positive encounter odds across seven biomes.
- Every ordinary species supports F through UUR; Fihs only UUR and the Sock only F.
- Rank comes from species-relative length and weight; both must meet the tier minimums.
- Saved catch ranks drive XP, sale value, rank artwork, and collection rank progress.
  Player-facing measurements use inches and pounds/ounces throughout Discord and the website.
  All 251 species have explicit length/weight baselines in
  [species-measurements.csv](content/species-measurements.csv); see
  [measurement design and calibration](docs/species-measurements.md).
- Level cap 120; free Twig Rod and six purchased rod families; authoritative biome/equipment selection.
- Server RNG, fixed-point lengths/weights, XP and first discoveries, global 60-second cooldown.
- Private accounts and inventory, scoped subscriptions, durable cast results, safe retries.
- Junk stacks and immediate treasure rewards with economy ledger entries.
- Five repeatable 10-use bait packs and permanent Common-to-Prismatic quality per rod.
- Power can add one independent mixed pull; both pulls share cooldown and one bait use.
- Confirmed tin/scrap crafting; biome access uses level and licences instead of power.
- Website dashboard, collection, permanent sortable catch journal, favorites, sale preview/confirmation,
  and length/weight records that survive disposal.
- One game shell with Camp, Tackle box, Collection, Journal, World map, Records,
  Trader, Compendium, Achievements and Anglers tabs. Lists have book pages: twelve entries on desktop and four on phones.
- Fourteen Poise handlers: /fish, /daily, /profile, /inventory, /sell, /leaderboard, /collection, /biome, /gear, /rod, /shop, /bait, /upgrade, /help.
  Fish responses attach a rarity-card PNG for each saved fish, including bonus pulls.
  Rusted tin and treasure scrap pulls attach their original sprites on a matching salvage card.
  `/rod` publicly showcases an owned rod with a generated display card, its quality, and effective bonuses.
  Visible artwork is fitted proportionally, with crisp pixel scaling and clear rank labels.
- Eighteen permanent cosmetic badges and selectable titles, with retroactive progress.
- Public angler search/profiles and paged Discord standings; confirmed Discord sales protect favorites.
- Discord OAuth service, browser challenge proofs, single-use CSRF sessions,
  verified identity binding, replacement/revocation, and browser unlinking.
- Automatic client reconnect, owner-controlled service roles, audit rows,
  and bounded scheduled retention work.

All 251 original sprites and 10 rank cards remain in assets. Every sprite has
its requested name and stable species ID in content/species.json. Fihs and
Nidalees Lost Sock retain their special rank and rarity constraints; neither
is part of ordinary collection completion. Both are live only at Abyssal Shelf.
Maximum rod quality and luck bait put Fihs around 2,813 casts and the Sock 5,625;
waiting times depend on equipped gear. [Balance and wait math](docs/world-balance.md).

## Structure

    apps/web/             Dashboard and searchable catalog at /catalog
    assets/               Original fish sprites, rank cards, checksum manifest
    crates/game-rules/    Pure integer rules and tests
    crates/game-client/   Shared native transport and generated Rust bindings
    crates/discord-bot/   Serenity + Poise command handlers
    crates/account-link/ Axum Discord OAuth service
    spacetimedb/          Authoritative Rust WASM module
    packages/generated/ Generated TypeScript bindings
    content/              Versioned full species catalog, biome/rod definitions, prototype balance
    simulations/          Balance analysis entry point
    scripts/              Asset imports, codegen, service setup, integration proof
    docs/                 Specification, decisions, setup, status, verification

## Run and verify

From this directory, run npm ci, then npm run dev. Without database settings,
the site shows the welcome screen and full catalog. See [setup](docs/setup.md)
for local publication, service-role bootstrap, and credential configuration.

The desktop service launchers run the optimized Discord bot. After bot or shared
client changes, build it with `cargo build -p discord-bot --release --locked`
before restarting with `./scripts/local-services.ps1 -Action Restart`.

    npm run content:validate
    npm run check
    npm run build
    npm run rust:test
    npm run rust:check
    cargo test -p discord-bot --locked
    npm run bindings
    npm run test:integration
    npx tsx scripts/trader-proof.ts
    npx tsx scripts/crafting-proof.ts
    npx tsx scripts/social-proof.ts

Integration tests require the isolated loopback host on port 3127 and create
a fresh proof database each time. No existing database is reset. Generated
contracts come from the compiled WASM; never edit them manually.

Preview the exact Discord card renderer without credentials:

    cargo run -p discord-bot --example render-catch-cards --locked

This writes ten example cards and a contact sheet to ignored output/catch-cards.

Preview rusted tin and scrap with `cargo run -p discord-bot --example render-material-cards --locked`.
The PNGs are saved to ignored `output/material-cards`. The generated frame and its
built-in imagegen prompt are in `assets/item-cards/salvage.png` and
`docs/salvage-card-art-prompt.json`.

For the configured public site and double-click start/close/restart launchers,
see [ServBay hosting](docs/servbay-hosting.md). Register the public OAuth callback
in Discord before signing in through the website.

[Current status](docs/implementation-status.md) · [Verification](docs/verification.md)
· [Command plan](docs/discord-commands.md) · [Design specification](docs/fishing-game-design-specification.md)
