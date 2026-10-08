# Local setup

## Versions

Node 24.15+, npm, Rust 1.97.1, and SpaceTimeDB CLI/module/SDK 2.10.2.
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
    spacetime publish --server http://127.0.0.1:3127 --no-config --yes=skip-login --bin-path spacetimedb/target/wasm32-unknown-unknown/release/fishing_game_module.wasm fishbound-dev-local

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

Set DISCORD_REGISTER_COMMANDS=true to register the eleven implemented commands
globally for all installed servers. DISCORD_GUILD_ID and comma-separated
DISCORD_GUILD_IDS identify servers where startup removes legacy guild commands
that match a global command's name and type. Guild-only commands are preserved;
startup creates no server copies. The application's bot installation needs
slash-command access.
There are no dot-prefix commands. The command launch plan remains
in discord-commands.md; eleven have handlers, including /bait and /upgrade; sell and leaderboard follow later.

## Run

    npm run dev
    cargo run -p account-link
    cargo run -p discord-bot

Those Cargo commands are development runs. The desktop launchers use the
optimized bot; build it before starting or restarting local services:

    cargo build -p discord-bot --release --locked
    ./scripts/local-services.ps1 -Action Restart

Services fail startup when required configuration/roles are absent.
OAuth /health/live reports process liveness; /health/ready requires a connected,
authorized linker. Native services reconnect after dropped database transport.
The website reconnects and reconstructs state using its saved browser credential.

ASSET_ROOT defaults to assets when running the bot from the project root.
The bot composes the original sprite onto the saved rarity's card as one PNG
attachment, using alpha bounds and proportional nearest-neighbor scaling.
Original asset files stay intact. Missing artwork or failed image delivery falls
back to the already committed text result. Preview all ten ranks without credentials:

    cargo run -p discord-bot --example render-catch-cards --locked

Output goes to output/catch-cards, including all-ranks-preview.png.
Full-resolution previews remain 1024 × 1536. Live Discord attachments use
512 × 768 PNGs with nearest-neighbor scaling, preserving the centered artwork
and frame. A per-process cache keeps up to 64 rendered cards within 32 MiB,
evicting the least recently used entries. It contains only artwork, never catch
statistics or player data; restarting the bot clears it.

Benchmark cold and cached attachments without sending Discord messages:

    cargo run -p discord-bot --release --example benchmark-catch-art --locked

`/rod [rod_id]` shares a public showcase of the caller's equipped or selected
owned rod. It uses `assets/item-cards/rod.png`, the existing rod sprite, and
quality-colored accents; stats and quality come from the authoritative snapshot.
It does not equip the selected rod, consume materials, or modify cast cooldown.
Preview all seven rods and seven Twig Rod qualities without credentials:

    cargo run -p discord-bot --release --example render-rod-cards --locked

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
    cargo test -p game-rules -p account-link -p discord-bot --locked
    cargo clippy --workspace --all-targets --locked -- -D warnings
    cargo clippy --manifest-path spacetimedb/Cargo.toml --target wasm32-unknown-unknown --locked -- -D warnings

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
`npx tsx scripts/measurements-proof.ts <saved-pre-update-WASM>`; it verifies old
catch preservation, owner authorization, idempotence and fresh version-5 catches.

## Crafting activation

Back up and compare player state; never reset the database. Build/generate with
`npm run bindings`, publish the production WASM additively, then call the owner
reducer `activate_crafting`. This activates rules 6 and crafting definitions without spending
or granting player resources. Build the website and native clients, then restart
with the supplied local-services launcher. Recipes and bait prices live in
`content/crafting.json` and `content/trader.json`.
