# Rank maxima recognition

Rules 15 deployed on 2026-10-12. Includes unrestricted two-slot bait from rules 14.

Each valid species/rank pair gets a permanent gold ✦ in the collection after one specimen reaches **both exact integer maxima for that rank**. The rank filter highlights its own mark, and each species shows completed rank maxima out of its allowed ranks. Catching the same maximum again never increases completion counters.

Web public profiles and Discord `/profile` show unique rank maxima and fully maxed species alongside the existing UUR perfected-species counter. Fully maxed means every allowed rank: ten for each ordinary fish, one for each bonus species. Bonus Fihs UUR and Nidalees Lost Sock F are included in rank progress. Sock never earns the UUR perfect badge/title.

New qualifying catches receive a Discord **RANK MAXIMUM** card with angler, fish, rank, exact length/weight and XP. Its normal rank frame gains a gold pixel star. Official UUR catches retain **PERFECT CATCH**, the existing badge artwork, achievement and Perfect Angler title. The saved receipt supplies every displayed measurement; repeated maxima still receive a special catch card. No extra completion XP, coins or title is awarded.

## Persistence and historical recovery

The additive public `rank_maximum` table stores one row per player/species/rank: earliest qualifying catch ID/time, saved rank and paired measurements. Its collision-free key is `(player_id << 64) | (species_id << 8) | rank_ordinal`. Inventory sales and journal/receipt pruning never remove these lifetime marks. Existing tables are unchanged.

Live casting records maxima when rules 15 is active. Owner-only `activate_rank_maxima` recovers evidence from retained inventory, journal, command receipt and cast-pull receipt rows, ordered by catch time then ID. It validates allowed rank and both exact dimensions using the authoritative Rust measurement helper. Independent length/weight bests do not qualify. Missing historical specimens cannot be reconstructed. Backfill is idempotent, adds only new progress rows and sends no Discord announcements.

## Verification and release order

`scripts/rank-maxima-recognition-proof.ts` uses a uniquely named loopback `fishbound-proof-rank-maxima-*` database. Its saved rules-14 fixture contains paired evidence, lower-rank near misses, sold journal history, receipt-only history, bonus species, existing UUR awards, two bait slots and a complete ten-rank species. It migrates to the **actual rules-15 production WASM** with `--delete-data=never`, compares all 55 retained tables exactly, checks activation/backfill ownership and idempotence, then exercises real casting, replay, repeated maxima and history removal through an isolated new fixture. It also checks the actual web counter helper.

Native rule/card/art tests cover all 2,492 valid rank pairs, both one-unit near misses, wrong ranks, special-card labels, separate art caches and official UUR badge retention. Build both native clients and the web output, and regenerate both binding sets from the same final production WASM. Preview rank art with `cargo run -p discord-bot --example render-rank-maximum-card -- <output.png>`.

For a later authorized deployment: stop gameplay clients; take and verify a recoverable runtime backup; capture retained state; publish the candidate without data deletion; activate rules via `activate_xp_progression`; call `activate_rank_maxima`; compare retained rows and expected new ledger; deploy matching bot, linker and web artifacts; restart and verify. Do not rerun destructive fixtures or send a catch to verify deployment. Do not treat proof JSON snapshots as recoverable backups.

Deployment acceptance: a hash-verified 4,355-file cold backup restored all 7,427 prior rows. Both the actual-state rehearsal and live upgrade preserved existing tables and added declared secondary-slot defaults. Seven historical species/rank maxima were recovered; all 55 other tables remained unchanged, including wallets, XP, badges and titles. Activation and recovery were idempotent. The deployed WASM matches the tested candidate; 537 served web files and both native clients were hash-verified. Public site/account-link health, all 15 Discord commands including the optional bait slot, and the live public profile counters passed. The temporary restore listener is closed and the neighboring database is unchanged. Acceptance artifacts: `C:\laragon\www\dataverse\fishing-game\.local\deployments\rank-maxima-20261012-114135`.
