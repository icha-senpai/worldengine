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

Set DISCORD_GUILD_ID for a test guild and DISCORD_REGISTER_COMMANDS=true when
ready to register the nine implemented commands globally. A configured guild
also receives a direct registration for immediate visibility. The application's bot installation needs slash-command access.
There are no dot-prefix commands. The approved twelve-command launch list remains
in discord-commands.md; nine have handlers; upgrade, sell, and leaderboard follow later.

## Run

    npm run dev
    cargo run -p account-link
    cargo run -p discord-bot

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
