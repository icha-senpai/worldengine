# Player statistics

Statistics are authoritative Rust reducer projections. Discord and the website read the public `angler_statistics` table; neither client supplies counter values. Existing player, inventory, collection, reward and economy schemas remain unchanged.

## Definitions

| Statistic | Source and meaning |
| --- | --- |
| Total casts | Lifetime completed casts; one increment per successfully resolved cast |
| Bonus pulls and rate | Lifetime fish + junk + treasure minus casts; rate is extra pulls divided by casts |
| Bonus fish, junk, treasure | Outcome of the second pull; original journal cast keys preserve the pull index |
| Treasure and junk | Lifetime category counters, independent of inventory disposition |
| Rank totals | Sum of saved species-progress rank counts, in F, D, C, B, A, S, SS, SSS, UR, UUR order |
| Collection pairs | Number of ordinary species/rank combinations with a positive lifetime count; currently 2,490 possible; legendary finds remain bonus discoveries |
| Most-caught species | Greatest saved lifetime species count; ties choose the lowest species ID |
| Caught weight | Sum of measured fish weights; selling never subtracts it |
| Fishing days | Distinct UTC dates on which a successful cast occurred; matches the daily reward clock |
| Favorite biome | Most completed casts, counting a double pull as one cast; ties choose the lowest biome ID |
| Relative size | Longest fish relative to that species' typical catalog length, stored in millionths; weight is shown alongside the winning specimen |
| Sale earnings | Fish-sale proceeds only, excluding daily rewards, treasure, purchases and grants |
| Biggest individual sale | Greatest sale value of one fish in an accepted sale |
| Biggest sale batch | Greatest sum of an accepted sale batch |
| Record-setting catches | One increment when a fish establishes or breaks either global species length or weight record; winning both still counts once; independent of current records held |

## Persistence and recovery

`angler_statistics` holds one aggregate row per player. Private `fishing_day` rows deduplicate active UTC dates. Private `fishing_biome_activity` rows hold cast totals per player and biome. Public statistics exclude Discord IDs, browser identities, coin balances, inventory and the raw date history.

`statistics::ensure` recovers historical evidence once. It reads permanent journal entries for weights, relative size, dates and biome activity, saved collection progress for ranks and species, and surviving positive `fish_sale` coin ledger entries for earnings and batch records. Both journal pulls share a timestamp, so they count as one cast. Legacy reconciled entries do not invent bonus outcome types.

Lifetime cast/category counters and species progress are the durable sources for their totals. Older journal or ledger coverage may be incomplete. Separate completeness flags let the UI say “recorded” and explain available evidence rather than imply full historical coverage. Individual sale values and previous record-setting events cannot be reconstructed from aggregate sales or current record holders; they begin at their stored tracking dates. Initializing an old player never invents those events.

The owner-only `backfill_player_statistics(player_id)` reducer initializes and refreshes a projection. Repeating it does not reset measured weight, sales, bonus types, days or record-setting counters. Casting initializes the projection before recording the new cast. Sales initialize it before inserting the new sale ledger entry. Updates happen in the same transaction as the game action, after existing authorization/replay guards, so failures roll everything back and retries do not count twice.

## Clients

The Records book retains species records and the original four leaderboards, and adds twelve aggregate leaderboards. Angler profiles show activity, catch-book and coin-trail summaries, all ten rank totals and history coverage notes. A linked player's own summary also appears in Records. The shared Svelte statistics panel consumes live inserts and updates from the generated bindings. Discord `/leaderboard` exposes the same comparable aggregates; detailed personal breakdowns are available through the existing profile website link.

## Verification and deployment

- `npx tsx scripts/statistics-proof.ts`: isolated fixture WASM, guaranteed double pulls in all three categories, cast/sale replays, sold weight, sale totals and maxima, rank pairs, relative length, UTC date deduplication, favorite biome, owner authorization and repeated recovery.
- `npx tsx scripts/statistics-migration-proof.ts`: previous production WASM with saved player/catch data, additive publication with `--delete-data=never`, historical recovery and canonical equality of all 49 existing tables except scheduled maintenance.
- `node scripts/statistics-preservation.mjs before|after DATABASE [SERVER] [FILE]`: read-only canonical comparison of every retained table. Snapshots are evidence, not a replacement for a restorable runtime backup.
- Regenerate bindings from the built WASM, run Svelte checks/build, Rust workspace checks and rules tests, and rebuild the Discord executable before restarting the owned clients.
- Before live publication, cold-copy the owned runtime directory and verify a second copy on an isolated listener against the saved table snapshot. Retain the old WASM and the cold backup. Apply the additive module, backfill existing players, compare retained rows, and verify live desktop/mobile views and service readiness.

No catch odds, reward values, cooldowns, gear, progression or wallets are changed by this feature.

## Live acceptance — 11 October 2026

Published to `fishbound-dev-local` on the isolated Fishbound host `127.0.0.1:3127`, retaining database identity `c2002230cb292d622fb9716c64c8c73f84b1934e4eb6e6db546fb271ab32e488`. The migration plan created exactly the three new tables. Backfilled all eight existing players and repeated the backfill. All 49 retained tables and 6,458 rows matched the pre-deployment canonical snapshot. Scheduled maintenance is intentionally excluded from this comparison.

The cold runtime backup in `.local/deployments/statistics-tiers-20261011-153921` was copied with 3,070 verified file hashes. A second copy started on loopback port 3128 and matched the same 49 tables and 6,458 rows. The verification listener was then closed; the backup and old module are retained. Production web, OAuth linker and Discord adapter were restarted with the generated bindings; linker readiness returned 200 and the bot logged its ready state.

`icha_` recovered 226 casts, 24 bonus pulls (20 fish / 4 junk / 0 treasure; 10.62%), 206 measured fish, 121 ordinary species/rank pairs and eight UTC fishing days. Weight and bonus history are complete for this player. Surviving sales total 19,223 coins; the largest recovered batch is 5,673 coins. Individual sale and record-setting counters start at deployment and are not populated from guesses about previous inventory or record holders.

The final public website at `https://fish.ichaa.dev` was checked with all twelve new leaderboard categories and the live `icha_` profile. Desktop and 390-pixel mobile screenshots are in `output/playwright/statistics-*.png`; the pages had no horizontal overflow or console errors. Svelte checks passed without warnings, the production website build completed, Rust workspace checks and both native client builds passed, and all 22 game-rules tests passed. The isolated WASM statistics and previous-module migration proofs passed, including single pulls, rejected cooldown casts, double-pull recovery, sales and replay protection.
