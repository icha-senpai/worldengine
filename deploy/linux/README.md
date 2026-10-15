# Linux hosting

These scripts deploy the two applications independently from this checkout.
They supplement the Windows/ServBay launchers, which remain local development tools.

- Space: `https://bitcraft.ichaa.dev`, NGINX static output in `/var/www/dataverse/bitcraft`.
- Fishbound: `https://fishbound.ichaa.dev`, adapter-node on loopback 5173.
- Application database listeners: Space 3100; Fishbound 3127. The existing standalone
  host on 3000 is preserved. All runtimes are pinned to SpacetimeDB 2.12.0.
- Fishbound account linker: loopback 3001. Discord's production callback is
  `https://fishbound.ichaa.dev/auth/discord/callback`.

## First setup

Install the pinned Node/Rust toolchains and SpacetimeDB first. Run the reviewed
`bootstrap-host.py` with sudo. It installs the two NGINX sites and Let's Encrypt
certificate renewal, enables the deployment user's systemd linger, and creates a
maintenance flag. It does not publish modules, migrate data or start the bot.
Cloudflare must forward HTTP ACME challenges and use Full (strict) for HTTPS.

Use `node deploy/linux/install-user-services.mjs` as the deployment user. The six
services are installed under `~/.config/systemd/user`; they are not started or
enabled automatically. No general passwordless sudo permission is added.

Keep server-only environments outside Git:

| File | Purpose |
| --- | --- |
| `space/.runtime/production-web.env` | Browser build settings, including `VITE_SPACETIMEDB_HOST=wss://bitcraft.ichaa.dev` |
| `space/.runtime/production-collector.env` | Collector host, owner token/config and CLI paths |
| `fishing-game/.local/production-web.env` | Public Fishbound settings and loopback HOST/PORT |
| `fishing-game/.local/production-services.env` | Native Discord credentials, loopback database and canonical production website/callback |
| `~/.config/spacetime-production/` | Migrated database signing keys and owner credentials; private to the deployment user |

Restore and verify existing database state before enabling application services.
Do not initialize empty replacements for the existing player/tracker databases.
Database identities, ownership, stored records, service identities, current sealed
maintenance artifacts and notification routes must be included in verification.
Never run the same production Discord bot simultaneously on both machines.
Temporary migration transfer files must be removed after successful verification.

## Publishing source updates

After an approved Git fast-forward, use the deployment user's Node environment:

```sh
node deploy/linux/publish.mjs space
node deploy/linux/publish.mjs fishbound
```

`--build-only` performs dependency installation, required checks and a production
build without changing published files. Completed publishing promotes a temporary
stage and removes the previous build after success; it does not keep dated releases.
Fishbound restarts only its website and restores the prior build if startup fails.
Space uses its NGINX static directory. Source environments and application state
are never copied from development as part of ordinary publishing.

Rust program updates use `npm run rust:build` inside `fishing-game`. This produces
Linux executables in `.local/rust-artifacts` and cleans its temporary Cargo output.
Stop the affected user service, install the hash-verified program into `.runtime/bin`,
then restart it. Database module publication is a separate data-preserving operation.

Use `systemctl --user` for the `dataverse-*` services. On noninteractive SSH sessions,
set `XDG_RUNTIME_DIR=/run/user/$(id -u)` and
`DBUS_SESSION_BUS_ADDRESS=unix:path=$XDG_RUNTIME_DIR/bus` if not already present.
Remove `/var/www/dataverse/.maintenance` only after both production sites, live
database connections, collector/storage guard and OAuth routing are verified.
