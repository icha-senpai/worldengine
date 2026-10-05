# Fishbound through ServBay and Cloudflare Tunnel

Public website: https://fish.ichaa.dev

ServBay owns the Fishbound NGINX site at fishbound.test, its local certificate,
and the cloudflared tunnel mapping. The existing test.ichaa.dev mapping remains.
The canonical application origin and Discord login callback use the public HTTPS
hostname. Root .env holds native service settings; apps/web/.env holds public
browser settings plus the production Node host/port. Existing Discord secrets
were preserved and remain in ignored configuration files.

## Services and routes

| Public route | Local service |
|---|---|
| `/` and website assets | Production adapter-node 6 server at 127.0.0.1:5173 |
| `/auth/discord/*` | Rust account-link service at 127.0.0.1:3001 |
| `/health/account-link` | Account-link `/health/ready` |
| `/v1/database/fishbound-dev-local/subscribe` | SpaceTimeDB WebSocket at 127.0.0.1:3127 |
| `/v1/identity/websocket-token` | SpaceTimeDB short-lived token exchange |

Other `/v1/` routes return 404. The public browser uses
`wss://fish.ichaa.dev` with database `fishbound-dev-local`; native services
continue connecting directly to loopback. NGINX forwards WebSocket upgrade
headers and keeps long-lived subscriptions open. Authentication routes disable
access logging so callback codes and short-lived WebSocket tokens are not logged.
The managed site's source configuration is deploy/servbay-fishbound.conf.
Use ServBay's website editor/MCP to apply changes; its generated files are not
the configuration source.

## Double-click launchers

- start-fishbound.cmd starts the production website, account linker, and bot.
- close-fishbound.cmd closes only those tracked game processes.
- restart-fishbound.cmd closes and starts those game processes.

The launchers leave shared ServBay/NGINX/cloudflared and the local database running.
Start also brings up the existing isolated database data directory if port 3127
is free. It does not publish, reset, or migrate a database. Repeated Start calls
reuse tracked services; occupied untracked website/linker ports are left alone.
Process records and stdout/stderr logs live in ignored .local. PID, executable,
and process start time must match before a launcher stops a process.

After source changes, run `npm run build` for the website or
`cargo build -p account-link -p discord-bot --locked` for native services, then
restart. The website runs its built Node server, with apps/web/.env loaded.
ServBay itself must be running with the Fishbound website and cloudflared enabled.

## Discord callback

In the application's Discord Developer Portal, open OAuth2, add this exact
redirect URI, and save:

    https://fish.ichaa.dev/auth/discord/callback

The owner added this callback and enabled command registration. Both were
verified through Discord's API after restarting the services.
Do not enter the callback in Interactions Endpoint URL; this bot uses the Gateway.

The bot token was verified and matches DISCORD_CLIENT_ID; the native Gateway
reached "Discord adapter ready". DISCORD_REGISTER_COMMANDS is true. Discord's API
confirms all seven commands registered globally: /fish, /profile, /inventory,
/collection, /biome, /gear, and /help. After the owner reported that commands
were not visible, DISCORD_GUILD_ID was set to The Magic Tree House's server ID.
The restart registered the same seven commands directly to that server, verified
through Discord's API. Existing global commands remain registered. Startup now
refreshes the server command set; clear DISCORD_GUILD_ID for global registration.
Live /fish delivery and the complete OAuth consent/callback still need verification.

## Records projections

The Records page subscribes to public angler_standing and legendary_find alongside
species_record. Publishing the records update added these two tables without
altering existing tables or clearing the database. For another existing Fishbound
database, publish the current module first, then backfill with the deployment
owner's CLI identity:

    npx tsx scripts/backfill-records.ts fishbound-dev-local

The script connects to the isolated loopback server on 3127, reads public player
IDs, and calls the owner-only rebuild_player_records reducer once per player.
Repeated backfills are safe; totals come from durable player/progress/record
state, including fish no longer in inventory. No Discord token is needed.

## Unlimited fish storage

Casting has no inventory-capacity check. The old inventory_capacity column is
retained solely for schema compatibility and is ignored by gameplay. Fresh
databases seed it as zero. After updating an existing database, the owner can
clear its legacy value without altering catches, rules versions, or cooldowns:

    spacetime call --server http://127.0.0.1:3127 --no-config --yes fishbound-dev-local migrate_unlimited_inventory

This was applied to the running database. Kept counts remain accurate for casts
and sales; favorites, sale previews, and the 50-catch sale batch still apply.


Dockside Delivery update: `/daily` is the eighth handler. Command registration
refreshes the global list and the configured guild list, so direct guild visibility
and installations in other servers share the same supported commands. The local
module update adds private daily eligibility/receipt tables without resetting data.


Camp trader update: nine commands now register globally and in the configured
guild, including `/shop`. Public trader catalog/anonymous ownership views use
the existing restricted WebSocket route. The additive migration keeps player,
wallet, inventory, daily, and rod tables; owner activation seeds the offers and
runs the one-time visited-water licence transition. Restart uses the existing
tracked-process launcher and leaves ServBay/database/tunnel running.
