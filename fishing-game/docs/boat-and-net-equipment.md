# Boat and net equipment

The Trader has separate Boats and Nets shelves. Each permanent item uses its
matching rod's price and purchase requirements. Purchases use the existing
confirmed quote, wallet, ledger and replay protections and do not auto-equip.

| Family | Boat / net price each | Boat luck / XP | Net luck / XP |
|---|---:|---:|---:|
| Twig | Free starter | +0% / +0% | +0% / +0% |
| River | 500 | +42% / +58% | +21% / +29% |
| Marsh | 15,000 | +84% / +116% | +42% / +58% |
| Moonwood | 16,000 | +126% / +174% | +63% / +87% |
| Tide | 33,000 | +168% / +232% | +84% / +116% |
| Glacial | 74,000 | +210% / +290% | +105% / +145% |
| Abyssal | 130,000 | +252% / +348% | +126% / +174% |

Boat and net catalog version 3 sets boat base luck/XP to four times the matching
rod and net base luck/XP to twice the matching rod. These authoritative values
appear in both Trader offers and equipped Tackle box cards. Each boat/net adds
twice its own permanent quality's luck and XP bonuses, independently of the base
multiplier. They do not include the equipped rod's quality, power or bait bonuses
and do not affect ordinary `/fish` casts. Timed commands consume their own equipment's starting stats; existing rewards
remain unchanged. See [Discord commands](discord-commands.md) for timer behavior.

The Tackle box shows each equipped item, its original artwork, and a selector
containing only owned gear. Boat and net slots are independent and persist across
reloads. Neither consumes bait. Equipping them does not change the selected rod,
rod quality, biome, XP, wallet, bait slots or normal fishing cooldown.

Ownership, purchasing, equipment selection, quality upgrades and timed gameplay
are implemented. `/fishboat` takes ten minutes for ten pulls and `/fishnet` takes
four minutes for four pulls. One of each may run alongside ordinary fishing.

## Quality upgrades

Each owned boat/net starts at Common and upgrades independently through Uncommon,
Rare, Epic, Legendary, Mythic and Prismatic. Its tin/scrap recipe is exactly the
matching rod family's recipe for that step in `content/crafting.json`; there is
no second copy of the recipes or quality catalog. Guaranteed upgrades add the
twice the quality luck/XP of rods and never add power. This doubles each step's
increase rather than compounding the total stats. At Prismatic, quality adds
+14% luck and +26% XP: Abyssal Boat totals +266% luck / +374% XP; Abyssal Net
totals +140% luck / +200% XP. Rod bonuses and recipes are unchanged.

The Tackle box shows current quality, effective bonuses, next recipe, material
balances and bonus increases. Rods, boats and nets share the same native upgrade
confirmation dialog, including cancel/Escape, expiry, focus and disconnect guards.
Materials are spent only on confirmed success. Switching gear retains each
item's own quality; buying another family starts that item at Common.

## Catalog and persistence

- `content/haul-equipment.json` defines seven illustrated families per kind.
  Boat IDs are 101–107 and net IDs 201–207. `rodId` identifies the matching family.
- Trader catalog version 10 adds twelve offers, with listing ID `1000 + equipmentId`.
  Earlier offers retain their IDs, prices and gates. Glacial/Abyssal gear follows
  the matching rods' coins-only purchase requirement.
- Public `haul_equipment_definition` holds display metadata. Private
  `owned_haul_equipment` and `haul_loadout` hold ownership and the two selections.
  Caller-scoped `my_haul_equipment` and `my_haul_loadout` expose linked-player data.
- New players receive the Twig Boat and Twig Net, initially equipped. The
  owner-only `activate_haul_equipment` seeds metadata/offers and grants existing
  players the same starters. It preserves purchases and selections on rerun.

The bonus columns and recipe-family `rod_id` append to
`haul_equipment_definition` with zero defaults. Catalog v3 additionally appends
`quality_bonus_multiplier` with default 1, preserving prior effective bonuses
before activation; activation sets it to 2 for all boats/nets. `upgrade_level=0` appends to
`owned_haul_equipment`, preserving existing ownership at Common. Private
`haul_upgrade_quote` and caller-scoped `my_haul_upgrade_quote` provide the same
two-minute preview and one-second preview throttle as rods. The server checks
identity, ownership, source quality, recipe/quality versions, material costs and
funds again at commit. A consumed nonce never spends twice; expired previews are
pruned by the existing bounded maintenance job.

Publishing preserves existing definition fields and all ownership/selections;
activation replaces only definition metadata with the catalog bonuses and recipe
families. It never resets quality or materials. Existing player/loadout layouts
and rod reducer signatures do not change. Regenerate
both client bindings from the built WASM, deploy the matching module and clients
without deleting data, then run activation before reopening gameplay. The shared
Linux release manifest includes this activation in Fishbound's hooks.

## Verification

`npm run content:validate` checks complete tier/artwork coverage and exact
price/licence parity with rods. `npm run rust:script -- scripts/haul-equipment-proof.mjs`
creates a uniquely named `fishbound-proof-haul-*` database on loopback 3127.
It upgrades the previous module to the actual new WASM, compares canonical rows
across all 64 old tables except the scheduled maintenance clock, verifies the
declared metadata changes and idempotent activation, then exercises purchases,
private views, all fourteen server-supplied bonuses, free starters, equipment changes, replay, reconnect, invalid/bulk
purchases, insufficient funds, licence gates, stale/expired quotes and unchanged
rod/bait/cooldown state. Test-only reducers never enter the production module.

The optional `--browser` flag retains its isolated fixture and writes a private
`.local/haul-equipment-browser.json` for UI checks. Delete that database through
SpaceTimeDB and remove the named temporary fixture directory and identity files
afterward. The normal runner cleans up automatically.

Set `FISHBOUND_HAUL_PREVIOUS_WASM` to a prior boat/net module to exercise the
additional migration path: retained definition fields with zero bonus defaults
before activation, matching rod-family bonuses afterward, and identical retained
ownership, selected gear, wallets, XP and cast history. Use only disposable proof
artifacts, and remove them after verification.

The upgrade proof additionally traverses all 84 family/quality combinations with
actual reducer calls and exact material/ledger checks. It verifies max quality,
concurrent same-nonce confirmations, expired previews, changed recipe/version
quotes, insufficient materials at preview and commit, caller privacy, independent
quality retention, unchanged fishing state and a regression upgrade of the
original Twig Rod through the shared material-spending code.

Local acceptance on October 26, 2026: content validation, generated WASM/TS/Rust
contracts, TypeScript/Svelte checks, subscription checks, website production build,
both native release builds, and the migration/behavior proof passed. Playwright
verified desktop (1440×1000) and mobile (390×844) purchases, cancellation, artwork,
independent selectors and reload persistence with no broken images or horizontal
overflow. Existing hash-navigation code emits a SvelteKit development warning
when changing tabs; there were no browser errors. Production was not updated.

The bonus follow-up also passed an actual prior-equipment-WASM migration across
67 retained tables: zero appended defaults before activation, exact family luck
and XP afterward, retained ownership/selections and idempotence. Both native
clients and the web production build were rebuilt. Browser checks confirmed
Twig zero bonuses, River fractional percentages and Abyssal maximum values,
correct boat/net Trader descriptions, and readable desktop/mobile gear cards.

Quality-upgrade acceptance also passed all 84 real family/quality transitions,
including concurrent retry protection and the original rod regression. Browser
checks exercised desktop boat and mobile net confirmations, Escape cancellation,
exact material deductions, per-item quality persistence after switching/reload,
Prismatic completion and the then-current +70% luck/+100% XP on both Abyssal items. The shared rod
popup still opens correctly. At 390px there was no horizontal overflow, and the
browser reported no errors. Temporary proof data and browser identities were
removed after verification; production remains unchanged.

## Scaling follow-up

Base and quality tuning is confined to the boat/net definitions. Existing quality
levels receive the doubled quality contribution without spending again. Costs,
prices, fixed planned haul sizes and ordinary rod casts are unchanged. Twig's
zero base remains zero; its upgrades receive the same doubled bonuses.

Timed haul reward execution now uses the same graded-luck formula with explicit
haul limits of +266% luck / +374% XP. The original rod helpers retain +120% luck
and +300% XP limits. Pure-rule tests cover every boat/net quality in all seven
waters, including positive weights, preserved totals and the Fihs/Sock hierarchy.

Scaling verification passed: prior-module migration preserved all 68 existing
tables, default multiplier 1 before activation, intended multiplier 2 afterward,
and idempotent activation. All 84 upgrades, exact recipes, replay protection and
the original rod regression passed. Content validation, generated WASM/contracts,
web checks/build/subscriptions and both native release builds passed. Browser
checks verified doubled Common-to-Uncommon previews (+1% luck/+2% XP), both
Prismatic Abyssal totals in Trader and Tackle box, reload persistence, and a
390px layout without horizontal overflow or browser errors. Production unchanged.

## Timed gameplay persistence

Private `haul_trip` stores the starting biome/rules, selected gear/quality,
effective bonuses, deadline, immutable committed results and Discord delivery
state. Private `haul_job` schedules completion. `haul_request` pins each start/status
interaction to its original trip, including delayed retries after completion.
Request receipts expire after seven days. These are additive tables;
existing player, owned equipment and saved catch schemas are unchanged. No new
activation is required for timers; the equipment activation still seeds gear.
Database time gates completion. Duplicate starts, completion calls, concurrent
adapter retries and acknowledgements cannot grant a second reward. A ready
message holds a two-minute delivery lease and survives restarts. Only acknowledged
trips older than thirty days are pruned; pending delivery and running trips stay.

Base category weights, rules, gear and Collection Focus enablement are captured
at start. Species/rank content is resolved from the server catalog at completion;
content deployments do not store a complete catalog copy per trip. Existing
specimens and completed results never reroll. Private browser and adapter status
views keep caller ownership and service-role authorization.

Timed-haul acceptance (local, 2026-10-26): the old equipment module migrated with
all 68 existing tables byte-for-byte equivalent in canonical row snapshots.
Real reducer proofs checked both deadlines, independent active slots, ordinary
fishing during trips, no bait use, frozen stats/biome, exact XP allocation,
per-pull journal entries, cross-command conflicts, duplicate completion and
competing delivery leases. A real scheduled callback resolved ten tins; forced
fish and treasure paths and all fourteen Prismatic equipment families completed.
Browser checks at 1440px and 390px verified active countdowns, switching gear
without altering the trip, reload persistence and automatic net completion while
the boat continued. No horizontal overflow or browser errors occurred. Discord
message serialization and six-command registration tests passed. No live Discord
messages were sent and production was not updated.

The final proof also passed through the real native `Client`: starting both
hauls, reporting an existing trip, normal fishing while underway, a fresh client
connection, overdue recovery, result claiming/acknowledgement, and replaying a
completed status request without starting a new trip. The final schema adds only
`haul_trip`, `haul_job` and `haul_request`; existing retained records stay intact.

Re-running the preservation proof requires the prior equipment WASM at
`.local/timed-haul-previous.wasm`, copied before rebuilding the candidate. Then
run `npm run bindings` and
`npm run rust:script -- scripts/timed-hauls-proof.mjs`. The runner provisions and
removes a unique loopback database and disposable build target; remove the prior
verification WASM afterward. `--browser` intentionally retains only its fixture
and browser identity until the UI check and explicit cleanup are complete.
The completed run left no temporary proof database, browser identity or Rust
build cache. Matching WASM/contracts, website and both native release programs
were built locally; live command registration and delivery remain undeployed.

## Haul statistics

Each completed boat or net haul counts as exactly one completed cast. Its
scheduled catches never count as rod bonus pulls: a private per-player
`haul_statistics` counter records extra haul catches (boat nine, net three)
durable from the first trip onward, so the lifetime bonus-pull projection is
`(fish + junk + treasure) - completed_casts - extra_haul_pulls`. The bonus rate
denominator still includes every completed cast, matching the existing display.
When the counter is absent it is lazily recovered once from the caller's own
durable journal pull keys — contiguous haul groups only; inconsistent history
fails closed — and an existing counter is never rebuilt from history, so repair
is idempotent and touches no rewards or player data. Deploying this module plus
the existing owner-only `backfill_player_statistics` (or any later statistics
refresh) is what updates real persisted projections; no public fields were
added and no gameplay data changes.
