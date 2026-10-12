# Fishbound working agreements

These instructions supplement the Dataverse root `AGENTS.md`. Keep work inside
`fishing-game`; sibling applications, editor processes and reference copies are
outside this project's scope.

## What this project is

Fishbound is an asynchronous Discord fishing and collection game with a SvelteKit
companion website. Players catch measured specimens, earn XP and coins, buy and
upgrade equipment, equip bait, explore biomes, and chase collections, rank maxima,
records, badges and titles. Discord and the website share the same player state
through verified account linking.

## Where things belong

| Path | Responsibility |
|---|---|
| `spacetimedb/src/` | Authoritative reducers, persisted tables, scoped views, content activation and backfills |
| `crates/game-rules/` | Pure shared rules: sampling, measurements, progression, gear and bait calculations |
| `crates/game-client/` | Native transport, service identity and generated Rust bindings |
| `crates/discord-bot/` | Discord interactions, catch cards and committed achievement announcements |
| `crates/account-link/` | Discord OAuth and trusted browser/account linking |
| `apps/web/` | SvelteKit companion, subscribed state, UI and displayed odds |
| `packages/generated/` | Generated TypeScript contract |
| `content/`, `assets/` | Versioned game catalogs and original artwork |
| `simulations/`, `scripts/` | Balance analysis, verification, code generation and operations |

## Authority and game invariants

- Mutations, RNG, cooldowns, inventory ownership and rewards belong in the
  SpaceTimeDB reducers. Clients send intent and display confirmed state; never
  grant rewards or trust client-supplied identities, prices or measurements.
- Preserve atomic validation, receipt replay/idempotency, favorite protection
  and private account/inventory views. Retrying an interaction must not spend
  bait or grant rewards twice. Public projections must omit credentials,
  authentication proofs and private Discord/guild identifiers.
- Catch rank uses both species-relative length and weight. A rank maximum needs
  both exact endpoints on one specimen; independent best measurements do not
  prove a perfect catch. Saved specimens and receipts must not reroll.
- Preserve catalog spelling and stable IDs, including `Fihs` and
  `Nidalees Lost Sock`. Their exceptional rarity/rank rules and bonus-collection
  status are deliberate. Keep UUR perfect recognition distinct from other rank
  maxima, and lifetime progress independent of inventory sales/history pruning.
- Check client-side displayed odds against the authoritative Rust calculations.
  Achievement announcements follow newly committed awards; subscription reloads,
  replay and historical backfills must not re-announce them.
- Use manifests, lockfiles, `rust-toolchain.toml`, active catalogs and current code
  for versions and tuning. Some design/status documents contain historical
  prototype notes; verify them before presenting their numbers as current.

## Implement and verify

- Inspect sibling code and relevant mechanic documentation first. Keep changes
  readable and scoped; preserve unrelated working-tree changes.
- For web changes use `npm run check` and a production build. For content changes
  run `npm run content:validate` and the relevant rule/balance proofs. For native
  changes use the appropriate Rust checks/tests with `--locked`.
- The module builds separately for WASM. Schema/reducer changes require
  `npm run bindings`, which builds the module and generates both client contracts
  from that artifact. Never hand-edit generated bindings; rebuild affected
  clients and exercise changed behavior in an isolated proof database.
- Test interaction behavior in a browser when relevant. Documentation-only work
  needs link/content and diff checks, not a full rebuild of disposable caches.
- A build is not a deployment. Use existing authorization for live changes and
  report what actually ran, what was preserved, and any acceptance limits.

## Live services and deployment

- Current Fishbound endpoints: database `fishbound-dev-local` on loopback **3127**,
  website **5173**, OAuth linker **3001**, public origin `https://fish.ichaa.dev`.
  Confirm the configured database identity before mutations. Space's **3100**
  listener and shared ServBay/Cloudflare services are outside this scope.
- Use `start-fishbound.cmd`, `close-fishbound.cmd`, `restart-fishbound.cmd` and
  `scripts/local-services.ps1`. Preserve process ownership checks and credentials.
  Prefer the ServBay-managed Node runtime.
- Native services run from `.runtime/bin`. Build release programs, close clients,
  install with `scripts/install-service-binaries.ps1`, then start them. Cargo
  `target` directories are caches, not deployed program locations.
- The live website runs from `apps/web/build`. While it is running, build into an
  isolated adapter/output directory; do not overwrite the served build in place.
- Before persisted changes, verify a recoverable backup and rehearse the actual
  old-to-new migration without deleting data. Compare retained records and
  intended defaults/backfills, including ownership, wallets, XP and saved rewards.
  Publish with data preservation; verify activation/backfill idempotency and
  matching module, bindings and client artifacts before reopening gameplay.
- Never point fixture/seed/reset helpers at live. Proof databases must have unique
  `fishbound-proof-*` names on the Fishbound loopback server. Do not send Discord
  test messages or catches without authorization.

## Storage and cleanup

- Define retention when creating build output, proof databases, restore copies
  or deployment backups. Clean up task-owned temporary artifacts after checks.
- Keep the latest **two verified rollback sets** after a successful release.
  Preserve the prior recovery set until the new backup/release is verified.
  Compress cold backups and verify every archived file before removing originals;
  remove stopped restore-test copies and redundant builds. Retain small reports
  and required historical migration fixtures instead of whole scratch worktrees.
- Protect `.local/spacetimedb`, `.runtime`, `apps/web/build`, required dependencies,
  environment/service-token files, notification routes and active process/log
  state. Never remove individual active database logs or snapshots.
- Inspect the cleanup helpers' previews and exact absolute targets before using
  `-Apply`. Ensure targeted processes/databases are inactive and owned by this
  task; delete proof databases through SpaceTimeDB, not live storage files.
  Ordinary Start/Close/Restart must never perform cleanup or reset player state.

## Detailed references

- [Overview](README.md), [setup](docs/setup.md), [architecture](docs/architecture-decisions.md)
- [Hosting](docs/servbay-hosting.md), [runtime and retention](docs/runtime-storage.md)
- [Measurements](docs/species-measurements.md), [balance](docs/world-balance.md), [XP](docs/xp-progression.md)
- [Multi-bait](docs/multi-bait.md), [collection focus](docs/collection-focus.md), [rank maxima](docs/rank-maxima-recognition.md)
- [Discord commands](docs/discord-commands.md), [journal](docs/journal.md), [achievements](docs/achievements.md)
- [Implementation status](docs/implementation-status.md), [verification evidence](docs/verification.md)
