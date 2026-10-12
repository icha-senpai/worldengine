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
| Most expensive fish | Highest original saved coin value of any caught fish, kept or sold; captured when caught and retained permanently |
| Biggest sale batch | Greatest sum of an accepted sale batch |
| Record-setting catches | One increment when a fish establishes or breaks either global species length or weight record; winning both still counts once; independent of current records held; verified chronological journal replay recovers earlier events |

## Persistence and recovery

`angler_statistics` holds one aggregate row per player. Private `fishing_day` rows deduplicate active UTC dates. Private `fishing_biome_activity` rows hold cast totals per player and biome. Public statistics exclude Discord IDs, browser identities, coin balances, inventory and the raw date history.

`statistics::ensure` recovers historical evidence once. It reads permanent journal entries for weights, relative size, dates and biome activity, saved collection progress for ranks and species, and surviving positive `fish_sale` coin ledger entries for earnings and batch records. Both journal pulls share a timestamp, so they count as one cast. Legacy reconciled entries do not invent bonus outcome types.

Lifetime cast/category counters and species progress are the durable sources for their totals. Older journal or ledger coverage may be incomplete. Separate completeness flags let the UI say “recorded” and explain available evidence rather than imply full historical coverage. Current record holders alone cannot reconstruct previous record-setting events. The owner-only `backfill_catch_records()` reducer replays the entire fish journal in timestamp/catch-ID order, verifies complete fish counts for every player and unique catch IDs, and requires the final replayed winners to match every saved species record. It then assigns recovered lifetime record-setting totals and changes their tracking start to account creation. Most expensive fish values are recovered from original specimen and cast/command receipts, including sold fish, with no catalog repricing. Recovery fails atomically if identifiers, fish counts, final records or original price evidence are incomplete or inconsistent. Repeating recovery assigns totals rather than adding them.

The owner-only `backfill_player_statistics(player_id)` reducer initializes and refreshes a projection. Repeating it does not reset measured weight, sales, bonus types, days or record-setting counters. Casting initializes the projection before recording the new cast. Sales initialize it before inserting the new sale ledger entry. The persisted `biggest_single_sale` field retains its legacy schema name for compatibility, but now represents Most expensive fish. It is updated on each fish pull before inventory disposition; the maximum survives receipt cleanup and selling. Updates happen in the same transaction as the game action, after existing authorization/replay guards, so failures roll everything back and retries do not count twice.

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

`icha_` recovered 226 casts, 24 bonus pulls (20 fish / 4 junk / 0 treasure; 10.62%), 206 measured fish, 121 ordinary species/rank pairs and eight UTC fishing days. Weight and bonus history are complete for this player. Surviving sales total 19,223 coins; the largest recovered batch is 5,673 coins. At the first deployment, individual sale and record-setting counters started at deployment. The subsequent verified catch-record recovery described below replaces that limitation.

The final public website at `https://fish.ichaa.dev` was checked with all twelve new leaderboard categories and the live `icha_` profile. Desktop and 390-pixel mobile screenshots are in `output/playwright/statistics-*.png`; the pages had no horizontal overflow or console errors. Svelte checks passed without warnings, the production website build completed, Rust workspace checks and both native client builds passed, and all 22 game-rules tests passed. The isolated WASM statistics and previous-module migration proofs passed, including single pulls, rejected cooldown casts, double-pull recovery, sales and replay protection.

## Verified catch-record recovery

The follow-up runtime update adds `backfill_catch_records()` without changing persisted table layouts. Before applying it, complete live evidence contained 294 fish, all with original value snapshots. Chronological replay reproduced all 72 current record entries. For `icha_`, that history contains 70 distinct record-setting catches and a maximum original fish value of 4,180 coins. Current titles held remain a separate metric.

The website profile and Records leaderboard, and the Discord leaderboard choice, now say “Most expensive fish.” No sale is required. Sale earnings and batch records retain their separate definitions and older-receipt coverage notes. The legacy storage field name remains internal.

`catch-record-preservation.mjs` snapshots all 52 existing tables (except scheduled maintenance), preserves every old field apart from `records_set`, `records_tracked_since` and `biggest_single_sale`, and forbids decreasing the previously saved maximum value. Its `restored` mode requires complete equality for all fields. `verify-catch-records.mjs` independently replays the journal and compares all record winners, per-player event counts, tracking dates and original maximum values against the projections.

The isolated statistics proof also checks catch-time value tracking before selling, owner-only recovery, replay/idempotency, incomplete-history rejection and mismatched-record rejection without partial changes. The previous statistics-enabled WASM migration proof validates all 52 tables and their intended field differences.

Live recovery was applied on 11 October 2026. The runtime update required no table-layout changes. A fresh cold backup in `.local/deployments/catch-records-20261011-162626` verified 3,154 file hashes and restored all 52 tables and 6,518 rows on the isolated listener. After recovery, the same tables matched except for the three intentional statistics fields. Independent verification reproduced all 72 record winners from 294 fish and validated both statistics for all eight players; repeating recovery produced the same results. `icha_` now displays 70 record-setting catches, tracked from account creation on 4 October, and Most expensive fish of 4,180 coins. All 57 currently held titles remain intact.

The final live profile and both affected leaderboards passed desktop/mobile browser checks with no overflow or console errors. Screenshots are `output/playwright/catch-records-desktop.png` and `output/playwright/catch-records-mobile.png`. Script/Svelte type checks, production web build, Rust workspace checks and bot/linker builds passed. Production services are running; the temporary restore listener was closed.
