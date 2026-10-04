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

The application's redirect list was empty when checked. The server-side callback
and root .env are ready, but Discord will reject login until the URI is registered.
Do not enter the callback in Interactions Endpoint URL; this bot uses the Gateway.

The bot token was verified and matches DISCORD_CLIENT_ID; the native Gateway
reached "Discord adapter ready". DISCORD_REGISTER_COMMANDS remains false as
configured. Set it true and restart when ready for initial command registration;
DISCORD_GUILD_ID chooses test-guild registration, otherwise registration is global.
Live /fish delivery and the complete OAuth consent/callback still need verification.
