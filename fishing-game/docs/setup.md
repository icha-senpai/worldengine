# Local setup

## Versions

Node 24.15+, npm and Rust 1.97.1. The SpaceTimeDB module and browser SDK use
2.12.0; the native transport SDK stays on 2.10.2. The TypeScript binding
generator uses 2.12.0; the Rust binding
generator stays on 2.10.2. Install both CLI versions. On Windows the binding
script selects their installed version directories without changing the global
default. Elsewhere, set `FISHBOUND_TYPESCRIPT_CLI` and `FISHBOUND_RUST_CLI`
to the respective CLI executables. `npm run bindings -- --check-cli` verifies
these tools without building or generating files.

`npm run test:module` runs the actual Rust module against SpaceTimeDB's native
in-memory datastore, including transaction rollback, caller privacy, journal
pagination and durable delivery leases. On Windows the wrapper reuses ServBay's
OpenSSL libraries when available; elsewhere provide the normal OpenSSL native
build prerequisites. The test-only ICU pins match the 2.12 release lockfile.
`npm run test:subscriptions` checks tab query boundaries. Run the isolated
`npm run rust:script -- scripts/spacetime-features-proof.ts` for installed-to-candidate
preservation, subscription privacy and reconnect acknowledgement checks.
The manifests and lockfiles pin SvelteKit 3, Svelte 5, TypeScript 6, Vite 8,
Serenity 0.12.5, Poise 0.7.0, Axum 0.8.9, and Reqwest 0.12.28.
The Rust toolchain file requests rustfmt, clippy, and wasm32-unknown-unknown.

Run commands from C:\laragon\www\dataverse\fishing-game.

    npm ci
    cargo fetch --locked
    cargo fetch --manifest-path spacetimedb/Cargo.toml --locked
    npm run content:validate
    npm run bindings

Bindings generate both clients from WASM. Native workspace checks never try
to link the host-dependent module. Generated source must not be hand-edited.

## Isolated database

Start in a separate terminal, if port 3127 is free:

    spacetime start --listen-addr 127.0.0.1:3127 --data-dir .local/spacetimedb --non-interactive

A proof instance may already be running from implementation. Do not start a
second server on the same port or stop unrelated servers. This data directory
is separate from sibling projects.

Publish a newly named development database using the current CLI publishing identity:

    npm run module:build
    spacetime publish --server http://127.0.0.1:3127 --no-config --yes=skip-login --bin-path .local/rust-artifacts/fishing_game_module.wasm fishbound-dev-local

Use a new name for incompatible prototype schemas. Do not reset a database
containing data you want to preserve. The publishing identity becomes module
owner; service configuration must use that same owner.

## Service credentials

Copy .env.example to .env and apps/web/.env.example to apps/web/.env.
Set the URI/database consistently in both files. Environment files are ignored.
Keep service tokens and Discord secrets out of PUBLIC_ variables.

For loopback development, bootstrap distinct identities and owner grants:

    npm run service:create -- discordAdapter --grant
    npm run service:create -- accountLinker --grant

The helper reuses an existing ignored token file or creates one. It prints the
identity and file path, never the token. Root .env.example already declares the
corresponding SPACETIMEDB_BOT_TOKEN_FILE and SPACETIMEDB_LINKER_TOKEN_FILE.
Direct environment tokens can be supplied instead. Protect these files on a
production host; the helper deliberately supports loopback only.

Configure the Discord application's bot token, client ID/secret, exact OAuth
redirect URI, and website URL. Default development URLs are:

- Website: http://localhost:5173
- OAuth service: http://localhost:3001
- Registered callback: http://localhost:3001/auth/discord/callback

The callback must point at the Axum service. PUBLIC_ACCOUNT_LINK_URL points at
that service; WEBSITE_URL must match the website Origin exactly. Public internet
URLs require HTTPS. The localhost exception is for development.

Set DISCORD_REGISTER_COMMANDS=true to register the six commands globally:
`/fish`, `/fishboat`, `/fishnet`, `/daily`, `/rod` and `/profile`. Registration replaces the previous
command list. DISCORD_GUILD_ID and comma-separated DISCORD_GUILD_IDS identify
servers where startup removes legacy Fishbound guild commands, including retired
commands; unrelated guild commands are preserved. Startup creates no server
copies. The application's installation needs slash-command access.

There are no dot-prefix commands. See [Discord commands](discord-commands.md).
Other game management belongs on the website. `/profile` replies publicly with
public progression, the profile portrait, selected title and matching earned badge.

## Run

    npm run dev
    npm run rust:run -- run -p account-link
    npm run rust:run -- run -p discord-bot

Those Cargo commands are development runs. The desktop launchers use the
optimized bot; build it before starting or restarting local services:

    npm run rust:build
    ./close-fishbound.cmd
    ./scripts/install-service-binaries.ps1
    ./start-fishbound.cmd

Services fail startup when required configuration/roles are absent.
OAuth /health/live reports process liveness; /health/ready requires a connected,
authorized linker. Native services reconnect after dropped database transport.
The website uses SDK-managed recovery for established connections, retaining
its browser identity, subscriptions, cached player data, selected tab and filters.
Actions pause until subscriptions finish resynchronizing; interrupted writes
are never automatically repeated. If a write's outcome is unknown, the website
asks the player to check refreshed inventory and coins before trying again.
Only failed initial connections use the application's bounded retry loop.
Explicit logout or reconnect can still reset private state. This does not
prevent browser reloads or development-server reloads.

ASSET_ROOT defaults to assets when running the bot from the project root.
The bot composes the original sprite onto the saved rarity's card as one PNG
attachment, using alpha bounds and proportional nearest-neighbor scaling.
Original asset files stay intact. Missing artwork or failed image delivery falls
back to the already committed text result. Preview all ten ranks without credentials:

    npm run rust:run -- run -p discord-bot --example render-catch-cards --locked

Output goes to output/catch-cards, including all-ranks-preview.png.
Full-resolution previews remain 1024 × 1536. Live Discord attachments use
512 × 768 PNGs with nearest-neighbor scaling, preserving the centered artwork
and frame. A per-process cache keeps up to 64 rendered cards within 32 MiB,
evicting the least recently used entries. It contains only artwork, never catch
statistics or player data; restarting the bot clears it.

Benchmark cold and cached attachments without sending Discord messages:

    npm run rust:run -- run -p discord-bot --release --example benchmark-catch-art --locked

`/rod [rod_id]` shares a public showcase of the caller's equipped or selected
owned rod. It uses `assets/item-cards/rod.png`, the existing rod sprite, and
quality-colored accents; stats and quality come from the authoritative snapshot.
It does not equip the selected rod, consume materials, or modify cast cooldown.
Preview all seven rods and seven Twig Rod qualities without credentials:

    npm run rust:run -- run -p discord-bot --release --example render-rod-cards --locked

Previews go into `output/rod-cards`. The generated background's exact built-in
imagegen prompt is recorded in `docs/rod-card-art-prompt.json`.

The optimized previews appear in `output/performance-cards`. Timing events in
`.local/bot.stdout.log` separate Discord acknowledgement, game requests, reply
delivery, card rendering/cache hits, and database selection queue/reducer waits.
`command_ms` measures from handler start. Commands with confirmation buttons
include the user's wait in their final `complete` event; use the first `reply`
event to measure when the initial offer appeared. Logs contain command names,
interaction IDs, durations, cache keys and byte counts, without credentials or
private inventory contents.

The local service log runner appends to `.local/<service>.stdout.log` and
`.stderr.log`, preserving output across restarts. Each active file is capped at
1,000,000,000 bytes (1 GB); writes crossing the limit are split exactly, the
closed file moves into `.local/log-archive`, and a fresh active file opens.
Archives use `.log.xz` with Python's XZ/LZMA2 preset 9 plus EXTREME, equivalent
to `xz -9e`. Compression runs in a separate background process and verifies
the decompressed bytes against the original before removing the closed log.
Compression failures retain the original and retry when that service starts
again. `.local/<service>.archive-status.json` records the latest archive job.
Compressed archives are retained. The runner waits for stdout/stderr to drain
when closing a service; archive compression may finish after the service exits.
ServBay's installed Python is required by the launcher. Verify rotation without
generating gigabytes of logs using `node --test scripts/service-log-runner.test.mjs`.
Assets are served by SvelteKit at /fish/*
and /rank-cards/*. All 251 species can be browsed at /catalog.

## Verification

    npm run test:integration
    npm run check
    npm run build
    npm run rust:run -- test -p game-rules -p account-link -p discord-bot --locked
    npm run rust:run -- clippy --workspace --all-targets --locked -- -D warnings
    npm run rust:run -- clippy --manifest-path spacetimedb/Cargo.toml --target wasm32-unknown-unknown --locked -- -D warnings

Integration needs the isolated loopback host and owner CLI credentials. It
builds/generates contracts, builds the native proof adapter, publishes a fresh
fishbound-proof-* database, and runs SDK/security/replay/economy checks. It writes
a browser fixture only inside ignored .local; this is genuine reducer state with
test identities, not a Discord login. Never use it as production authentication.

See verification.md for current results. Real Discord delivery and OAuth consent
need live verification. The configured ServBay deployment uses adapter-node 6,
the existing Cloudflare tunnel, and a public HTTPS origin; see
[ServBay hosting](servbay-hosting.md) for routes, launchers, and the Discord callback.
Restore proof, full progression, and late-game balance are later work.

## Updating species measurements to catalog 5

The source is `content/species-measurements.csv`. Rebuild with
`node scripts/build-world-content.mjs`, run content validation and rule tests,
then `npm run bindings` to compile WASM and regenerate the new owner reducer.
Build the website and native clients before restarting them.

Stop web/linker/bot through the supplied launcher and run
`node scripts/measurement-preservation.mjs before`. Publish the WASM additively
with the existing deployment name and owner identity; never use a database reset.
Then run:

    spacetime call --server http://127.0.0.1:3127 --no-config --yes fishbound-dev-local activate_species_measurements
    node scripts/measurement-preservation.mjs after

The comparison checks all 251 new definitions and every other stored table except
the scheduled maintenance timer. Restart services after it passes. Existing catches,
history, records, progression, equipment, XP and coins are preserved; only new
catches carry content version 5. Rank thresholds stay at version 4 and gameplay
rules stay at version 6. See [measurement notes](species-measurements.md).

The isolated migration proof can be repeated with
`npm run rust:script -- scripts/measurements-proof.ts <saved-pre-update-WASM>`; it verifies old
catch preservation, owner authorization, idempotence and fresh version-5 catches.

## Crafting activation

Back up and compare player state; never reset the database. Build/generate with
`npm run bindings`, publish the production WASM additively, then call the owner
reducer `activate_xp_progression`. This activates rules 10, the shorter-session XP curve, gold-gated rods and family recipes without spending
or granting player resources. Build the website and native clients, then restart
with the supplied local-services launcher. Recipes and bait prices live in
`content/crafting.json` and `content/trader.json`.

The rules-9 update adds only the public `rod_upgrade_recipe` metadata table.
Run `npm run bindings`, build all clients, and verify
`npm run rust:script -- scripts/rod-balance-proof.ts` and `npm run rust:script -- scripts/crafting-proof.ts`
in isolated proof databases before publication. Existing owned qualities,
wallets, XP, catches, inventories, quotes and receipts are retained. Outstanding
unconsumed shop/upgrade quotes reject changed prices or recipes on commit.

## Rules-10 XP curve activation

### Trophy Chum catalog update

Crafting catalog 5 and Trader catalog 7 add Trophy Chum. Back up the deployed
module/clients and a recoverable database before an authorized live update.
`bait_definition.size_rolls` defaults to 1;
saved cast-equipment receipts append the matching bait fields with the same
defaults. Existing bait behavior and rewards remain intact before activation.
Regenerate both bindings with `npm run bindings` and rebuild all affected clients.

Run `npm run rust:script -- scripts/trophy-chum-migration-proof.ts <saved-pre-Trophy-fixture.wasm>`
and `npm run rust:script -- scripts/crafting-proof.ts` in fresh isolated databases. Publish
without deleting data, activate through owner-only `activate_xp_progression`,
then compare canonical player state with `scripts/xp-preservation.mjs` before
starting rebuilt clients. Gameplay rules become version 11; XP thresholds and
size ceilings do not change. Old receipts retain their original outcomes.

### Chum tier catalog update

Crafting catalog 4 and Trader catalog 6 add four 10-use +2/+3 tin/scrap
packs. Before rebuilding, save the deployed WASM and an old crafting fixture
WASM. `npm run bindings` builds the module and regenerates both clients.
The additive migration appends `bait_definition.resource_quantity` and
`cast_equipment_receipt.bait_quantity`, each with default zero. Existing resource
baits continue to grant +1 before activation; old saved receipts render as +1
when their material field is populated. New casts save their actual quantity.

Run `npm run rust:script -- scripts/chum-migration-proof.ts <saved-old-crafting-fixture.wasm>`
and `npm run rust:script -- scripts/crafting-proof.ts` in fresh proof databases. For an
authorized live deployment, compare preserved state using temporary verification
data, publish without deleting data, then call `activate_xp_progression`
to refresh metadata. Verify `scripts/xp-preservation.mjs after` before starting
rebuilt clients. Lifetime XP, level thresholds and existing bait charges are
unchanged. Builds alone do not publish the catalog.

Rules 10 change level thresholds without changing lifetime XP or the schema.
Use a temporary rules-9 fixture for the migration proof. Do not retain previous
builds or make runtime backups unless Boss explicitly requests them.
Run the isolated `scripts/xp-curve-proof.ts <saved-rules-9-crafting-fixture.wasm>`
and `scripts/crafting-proof.ts`, then build the production module, website and
native clients. Publish with `--delete-data=never`, call owner-only
`activate_xp_progression`, and run `scripts/xp-preservation.mjs after` against the
saved before snapshot. All 41 retained gameplay tables must remain identical;
public profile levels deliberately increase according to the new curve.
Clients and server gates retain rules-9 thresholds until the rules-10 config
is activated, avoiding inconsistent levels during the publication window.

### Perfect-catch recognition update

After publishing the additive perfect_species table and rebuilding both clients,
run owner-only activate_perfect_catches with the bot stopped. It seeds badge 19,
backfills only paired perfect inventory/journal/receipt measurements, and awards
the cosmetic Perfect Angler title. Existing awards and rewards remain intact;
no old catches are re-announced on bot startup. Trophy Chum's catalog remains
Crafting 5 / Trader 7: all fish, +25% luck, eight within-band rolls, 1,000 coins per ten uses.
Gameplay rules 11 enable the 1-in-5,000 exact-maxima chance per UUR size attempt;
other bait and no bait receive one attempt. Natural perfects remain possible.
Before live publication, run the isolated crafting and Trophy migration proofs,
including old-schema history backfill, owner rejection and replay checks.


## Website appearance

The footer theme picker offers Dockside, Ocean, Rose, Cosmic and Neon Synthwave.
Each palette supports light and dark panels through the neighbouring sun/moon
button; switching palettes preserves the selected mode. Cosmic adds a subtle
star field, while Synthwave uses a static grid with purple and cyan colours.
Dockside light is the initial default.

The mode is kept under `fishbound:theme` and the palette under `fishbound:palette`
in browser local storage, with changes synchronised across tabs. Existing saved
light/dark choices remain valid. Unknown palette values fall back to Dockside.
The early head script in `apps/web/src/app.html` validates and applies saved choices
before styles paint. If storage is unavailable, both controls still work for that
page. Clearing storage in another tab restores Dockside light.

`theme.css` owns the shared palette, surface, text and native control tokens.
Page styles use those tokens while retaining original sprite and badge artwork.
Selected controls, podiums, page frames and sprite slots also use theme tokens.
The art background changes with both palette and mode across trader bait/rods,
fish cards/details, records, equipped gear and material storage. Rank accents mix
with the palette's surfaces so their labels stay readable.
`ThemeToggle.svelte` supplies a labelled native select and keyboard/pressed-state
handling, and waits for hydration before accepting input. Appearance changes no
game state.

The pond uses the same SVG coordinate system for scenery and fish, with a wider
water crop in compact headers. Its separate control bar follows the active palette
and keeps the play/pause icon and caption clear of the fish. The existing
`fishbound:pond-motion` preference and reduced-motion default still apply.
At widths up to 600px, the Fishbound name bar opens the page navigation instead
of showing the desktop tab strip. Choosing a page or pressing Escape closes it;
keyboard focus returns to the name-bar button.

Browser checks cover six public views, quest/gear/journal/achievement fixtures,
fish and quest dialogs, 320/390/1440px layouts, keyboard toggling, both saved
choices, cross-tab events and blocked storage. A narrow quest-grid clipping issue
was corrected during the initial dark-mode checks. Palette checks additionally
cover all ten palette/mode combinations across the six public views, text contrast
against solid surfaces, the fish dialog, keyboard selection, invalid preferences
and storage clearing. Selected-tab hover contrast and Dockside text contrast were
corrected during these checks.

Deployed to `https://fish.ichaa.dev` on October 14, 2026. All 546 website build
files matched the staged artifact. Only the tracked website process restarted;
the database, bot and account linker remained running. Public-site checks verified
all ten palette/mode combinations, saved preferences, pond play/pause, fish framing,
the revised desktop/mobile sizes and name-bar navigation, with no browser errors.

The follow-up surface correction replaced remaining fixed colours in Trader,
Records and Compendium, their fish detail dialog, and shared gear/material art
slots. Its 70 public-site visual states covered all palettes/modes, trader shelves,
both record views and compendium details; checks found ten distinct art backgrounds
with no text contrast failures on the tested solid surfaces or browser errors.
Additional local gear/material fixtures passed at 320/390/1440px. Type checks and
the production build passed before the website-only deployment.
