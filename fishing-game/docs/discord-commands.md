# Agreed Discord command plan

Status: nine handlers (/fish, /daily, /profile, /inventory, /collection, /biome, /gear, /shop, /help)
are implemented. /biome accepts optional biome_id; /gear accepts optional rod_id.
Meadow Pond and the starter Twig Rod are free. Later rods and permanent biome licences are bought from the camp trader after reaching their level requirements. Collection responses summarize seven
biomes to stay within Discord message limits; the website shows the full book. Other launch handlers remain pending. The nine current commands are registered globally and directly in the configured guild.
The current /profile handler is caller-only; the optional player lookup follows later.
This plan updates the original design specification's Discord command list.
Commands use plain Discord slash syntax. The `./` spelling in chat was only to
avoid triggering another integration; it is not a bot prefix or alias.

## Launch commands

| Command | Intended behavior |
|---|---|
| `/profile [player]` | Show the player's profile, statistics, current progression, and website link. |
| `/fish` | Cast in the selected biome, with a global one-minute cooldown, and show the authoritative saved result. |
| `/daily` | Dockside Delivery: 100 coins, plus 250 every seventh claim; show stamps and next midnight-UTC reset. |
| `/upgrade` | Show the selected rod's upgrade cost and benefit, then confirm before spending. |
| `/sell` | Select catches, preview the total payout, and confirm the sale; protect favorites. |
| `/inventory` | Show owned catches, catch IDs, favorites, and total fish kept; provide management buttons. Storage is unlimited. |
| `/collection` | Show discoveries, missing species, and personal length/weight records. |
| `/biome` | Travel to a licensed biome when level and equipped rod power qualify. |
| `/gear` | Show rod ownership and equip purchased rods; does not grant free gear. |
| `/shop` | Browse the camp trader; `/shop [item_id]` previews a licence or rod with buy/cancel buttons. Bait follows later. |
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
  or requirement to log in every day. Dockside Delivery grants 100 coins, plus
  250 on every seventh lifetime claim (350 that day; 950 per seven deliveries).
  Claims need not be consecutive: missed days preserve stamps. Resets happen
  at midnight UTC, once per Discord account across all servers. No XP, fish,
  discovery, record, catch-odds, or cooldown changes. Repeated commands show
  the next reset with a Discord relative timestamp. SpaceTimeDB owns eligibility,
  the atomic wallet/ledger grant, and replay-safe seven-day receipts.
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

## Camp trader progression (owner update)

Biome licences are permanent and bought in order; each tier's licence and rod
require the previous biome licence. Buying never equips or travels automatically.
Both the website and `/shop` preview the server price before explicit confirmation.
Quotes last two minutes and bind the caller, player, listing, price, and catalog
version. Commit rechecks eligibility, wallet, and ownership; retries cannot spend
again. The website World map can equip an owned suitable rod while travelling.

| Destination | Level | Licence | Rod | Combined coins |
|---|---:|---:|---:|---:|
| Whispering River | 5 | 150 | 250 | 400 |
| Hollow Marsh | 10 | 500 | 750 | 1,250 |
| Moonlit Lake | 18 | 1,500 | 2,000 | 3,500 |
| Sunken Coast | 28 | 4,000 | 6,000 | 10,000 |
| Glacial Reach | 40 | 10,000 | 15,000 | 25,000 |
| Abyssal Shelf | 55 | 25,000 | 35,000 | 60,000 |

Prices are initial tuning in `content/trader.json`. Existing rods are retained;
the one-time transition grants licences through a player's furthest selected or
discovered biome. New players and future unlocks require purchases. Neither
purchase nor travel changes fishing cooldown, XP, odds, collection, or records.
