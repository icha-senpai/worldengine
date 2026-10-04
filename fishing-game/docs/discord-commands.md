# Agreed Discord command plan

Status: seven handlers (/fish, /profile, /inventory, /collection, /biome, /gear, /help)
are implemented. /biome accepts optional biome_id; /gear accepts optional rod_id.
Rods are free level-earned access equipment. Collection responses summarize seven
biomes to stay within Discord message limits; the website shows the full book. Other launch handlers and live Discord verification remain pending.
The current /profile handler is caller-only; the optional player lookup follows later.
This plan updates the original design specification's Discord command list.
Commands use plain Discord slash syntax. The `./` spelling in chat was only to
avoid triggering another integration; it is not a bot prefix or alias.

## Launch commands

| Command | Intended behavior |
|---|---|
| `/profile [player]` | Show the player's profile, statistics, current progression, and website link. |
| `/fish` | Cast in the selected biome, with a global one-minute cooldown, and show the authoritative saved result. |
| `/daily` | Claim an optional daily reward; show eligibility or when the next claim is available. |
| `/upgrade` | Show the selected rod's upgrade cost and benefit, then confirm before spending. |
| `/sell` | Select catches, preview the total payout, and confirm the sale; protect favorites. |
| `/inventory` | Show owned catches, catch IDs, favorites, and remaining capacity; provide management buttons. |
| `/collection` | Show discoveries, missing species, and personal length/weight records. |
| `/biome` | Show the current biome and allow selecting an unlocked destination. |
| `/gear` | Show the loadout and allow equipping owned rods and available bait. |
| `/shop` | Browse rods and bait and purchase through buttons or menus. |
| `/leaderboard` | Show discovery/count standings and per-species length/weight records. |
| `/help` | Explain commands, cooldowns, ranks, account linking, and getting started. |

Use buttons, selects, and website links for follow-up actions where they keep
commands short. Separate `/buy` and `/equip` commands are not needed for launch.

## Implementation order

1. Complete the Phase 0 database, identity, service-role, receipt, and reconnect
   proof before exposing gameplay commands.
2. Build the Meadow Pond `/fish` slice with `/profile`, `/inventory`,
   `/collection`, and `/help`. Inventory, collection, and help are the first
   supporting commands so players can understand and manage their catches.
3. Add progression/economy commands: `/daily`, `/sell`, `/upgrade`, `/biome`,
   `/gear`, and `/shop`.
4. Add `/leaderboard` once durable records and bounded public projections exist.

## Behavior decisions

- Accepted casts start a 60-second cooldown shared across guilds and clients.
  SpaceTimeDB enforces it using server timestamps. Rejected casts and receipt
  replays do not start a new cooldown. No bait reduces the cooldown in V1.
- `/daily` is an optional reward with no streak penalty, missed-day punishment,
  or requirement to log in every day. Reward amounts and reset timing still
  need balance decisions. Eligibility and reward grants belong in SpaceTimeDB.
- `/sell` protects favorites and shows the complete proposed payout before
  confirmation. The module rechecks ownership, disposition, and favorites at
  commit; a stale selection must not silently sell a different set of catches.
- `/upgrade` shows the exact proposed cost and resulting benefit. Confirmation
  rechecks the current rod level and recipe before spending resources.
- Purchases, sales, upgrades, and daily claims need replay-safe receipts so
  interaction retries cannot duplicate rewards or spending.
- Biome, equipment, and shop components revalidate access, ownership, stock,
  and costs in the module. Button IDs are references, not authority.
- The bot transports player intent and renders confirmed module results.
  It does not calculate catch outcomes or maintain a separate economy.

## Later standalone commands

`/release`, `/salvage`, and `/achievements` are accepted later additions.
Initially, release and salvage can be inventory actions, and achievement
progress can be reached from profile buttons or the website. Their domain
behavior must exist before exposing either buttons or standalone commands.
