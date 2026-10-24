# Linux hosting

For browser-to-backend disconnect investigations, see
[connection diagnostics](connection-diagnostics.md).

These scripts deploy the two applications independently from this checkout.
They supplement the Windows/ServBay launchers, which remain local development tools.

- Space: `https://bitcraft.ichaa.dev`, NGINX static output in `/var/www/dataverse/bitcraft`.
- Fishbound: `https://fishbound.ichaa.dev`, adapter-node on loopback 5173.
- Application database listeners: Space 3100; Fishbound 3127. The existing standalone
  host on 3000 is preserved. All runtimes are pinned to SpacetimeDB 2.12.0.
- Fishbound account linker: loopback 3001. Discord's production callback is
  `https://fishbound.ichaa.dev/auth/discord/callback`.

Evergather can share this OAuth service for persistent Discord accounts without
guild membership. See [account activation and preservation](../../space/docs/discord-accounts.md).

## First setup

Install the pinned Node/Rust toolchains and SpacetimeDB first. Run the reviewed
`bootstrap-host.py` with sudo. It installs the two NGINX sites and Let's Encrypt
certificate renewal, enables the deployment user's systemd linger, and creates a
maintenance flag. It does not publish modules, migrate data or start the bot.
Cloudflare must forward HTTP ACME challenges and use Full (strict) for HTTPS.

Use `node deploy/linux/install-user-services.mjs` as the deployment user. The six
services are installed under `~/.config/systemd/user`; they are not started or
enabled automatically. No general passwordless sudo permission is added.

The Space collector unit includes `--authorize`: storage maintenance rebuilds its
temporary authorization table, so the owner must authorize the saved collector
identity again on each connection. Keep this option when regenerating the unit.

Keep server-only environments outside Git:

| File | Purpose |
| --- | --- |
| `space/.runtime/production-web.env` | Browser build settings, including `VITE_SPACETIMEDB_HOST=wss://bitcraft.ichaa.dev` |
| `space/.runtime/production-collector.env` | Collector host, owner token/config and CLI paths |
| `fishing-game/.local/production-web.env` | Public Fishbound settings and loopback HOST/PORT |
| `fishing-game/.local/production-services.env` | Native Discord credentials, loopback database and canonical production website/callback |
| `~/.config/spacetime-production/` | Migrated database signing keys and owner credentials; private to the deployment user |

Production BitCraft storage uses `SPACE_STORAGE_TARGET_BYTES=6000000000` and
`SPACE_STORAGE_BUDGET_BYTES=7000000000` in `space/.runtime/production-collector.env`.
These decimal-byte settings trigger maintenance at 6 GB with a 7 GB budget.
Without overrides, local development keeps its 2 GB trigger and 3 GB budget.
Changing these settings requires restarting the collector, not the database.
For a manual storage-status command, load the same production environment so
the command reports the collector's configured thresholds.

Restore and verify existing database state before enabling application services.
Do not initialize empty replacements for the existing player/tracker databases.
Database identities, ownership, stored records, service identities, current sealed
maintenance artifacts and notification routes must be included in verification.
Never run the same production Discord bot simultaneously on both machines.
Temporary migration transfer files must be removed after successful verification.

After cutover, set `LOCAL_DISCORD_BOT_ENABLED=false` in the Windows Fishbound
root `.env`. The Windows launchers then keep the local database, linker and
websites available without launching another copy of the production bot. Use a
separate Discord application/token for local bot testing.

The old ServBay public entries forward website requests to these production
domains and reject their old database endpoints. Private `space.test` (5180),
Space playtest (5181), and `fishbound.test` (5174) keep using local databases.
The old Fishbound OAuth path remains on the local linker for private development;
keep its Discord redirect registered alongside the new production callback.

Browser storage belongs to an origin. The new domains do not automatically
inherit remembered identities or preferences from the old domains. Fishbound
users can link Discord again to reach their retained player. Space's anonymous
tracker/Evergather identities remain in the old origin's browser storage; their
database records are preserved, but moving an existing browser identity needs a
separate explicit transfer. Do not clear old site data or place tokens in URLs.

## Publishing source updates

After testing locally and pushing your changes, SSH into the server and run:

```sh
dataverse-update
```

The same command updates both apps. It pulls Git with `--ff-only`, compares source
and production-environment fingerprints, then builds only affected components.
It includes dependent clients when modules change. There is no app/component
selection to remember. If your current shell has not picked up `~/.local/bin`,
use `~/.local/bin/dataverse-update` (or reconnect SSH).

- Websites: production dependency installation, checks, builds and promotion.
- Collector: script checks, production configuration and an owned-service restart.
- Bot/linker: managed Linux Rust builds, verified executable installation and restart.
- Database modules: matching binding generation, publication with
  `--delete-data=never`, release activation hooks, preserved identities/ownership
  and retained-row comparisons before gameplay reopens. Local DBs are never copied.
- User services: regenerate the managed units when their definitions change.

Builds and contract checks finish before the production switch. Module updates
briefly put the public sites into maintenance and disconnect existing public
database clients so retained state can be compared. The updater preserves server
secrets, service identities and database files. It never resets a database or
keeps dated release copies. A concurrent invocation exits instead of overlapping.

An incompatible schema or preservation failure stops the update. If switching has
started, maintenance remains on and writers stay stopped; fix the reported source
problem, push it and rerun the same command. Do not bypass the failure with a reset.
Failed pre-publication builds leave the current public build running. A dirty
server source checkout is preserved and reported instead of being overwritten.

The installer creates `~/.local/bin/dataverse-update` plus one active bootstrap
installation under `~/.local/share/dataverse-update`. It prefers the freshly
pulled updater source. `~/.local/state/dataverse-update/state.json` holds only the
last successful component fingerprints and module versions. Temporary private
row fingerprints are removed after success; unfinished ones remain for comparison.

For diagnostics, `dataverse-update --plan` pulls and lists pending components
without deploying; `--force` rebuilds/reapplies all components. `--no-pull` is for
testing reviewed local deployment scripts, not normal publishing. Runtime/OS
upgrades, DNS changes and new secrets remain explicit host setup work.

Developers: `release.json` records the production database identities, activation
hooks and the exact metadata tables/columns those hooks may change. Existing
protected rows and values must remain present, including integers beyond JS's
safe range. Update this definition and add the appropriate isolated migration
proof when introducing a new activation or intentional persisted transformation.
Do not add fixture/reset commands or blanket player-table exceptions. Commit
regenerated client bindings with module changes; mismatched contracts stop before
publication and generated source is returned to its original Git state.

The lower-level `node deploy/linux/publish.mjs space|fishbound [--build-only]`
commands remain available for focused maintenance. They only publish websites.
