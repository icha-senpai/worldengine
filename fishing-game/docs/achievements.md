# Achievements, titles and public anglers

Owner implementation update: this first achievement catalog is cosmetic only.
It supersedes coin/XP achievement examples in the original design specification.
No title or badge changes fishing odds, rod stats, XP, wallet or cooldown.

Sixteen ordinary milestones count toward the badge book. Fihs and the Sock are
optional bonus badges and do not gate ordinary completion. Awards are permanent,
including after a fish sale or title change. One earned badge supplies one title;
the linked website lets you select a title or clear it back to Angler.

| ID | Badge | Requirement | Title |
|---|---|---|---|
| 1 | First ripple | Complete your first cast. | New Angler |
| 2 | First fish | Catch your first fish. | Hooked |
| 3 | A hundred ripples | Complete 100 casts. | Patient Angler |
| 4 | A thousand ripples | Complete 1,000 casts. | Ripplekeeper |
| 5 | A full net | Catch 100 fish over your lifetime. | Steady Hand |
| 6 | Old hand | Catch 1,000 fish over your lifetime. | Veteran Angler |
| 7 | Curious angler | Discover 10 ordinary species. | Curious Angler |
| 8 | Naturalist | Discover 50 ordinary species. | Naturalist |
| 9 | Fish scholar | Discover 100 ordinary species. | Fish Scholar |
| 10 | Pond collection | Discover every ordinary Pond species. | Pond Naturalist |
| 11 | River wanderer | Own a River fishing licence. | River Wanderer |
| 12 | Deepwater explorer | Own an Abyssal fishing licence. | Deepwater Explorer |
| 13 | Rare company | Catch an A-rank fish or better. | Trophy Hunter |
| 14 | Beyond typical | Catch a fish at least 1.35 times its typical length. | Tall Tale Teller |
| 15 | Mythic maker | Upgrade any rod to Mythic or better. | Mythic Crafter |
| 16 | Prismatic masterpiece | Upgrade any rod to Prismatic. | Prismatic Artisan |
| 17 | A fish called Fihs (bonus) | Discover Fihs. A bonus outside ordinary completion. | Myth Hunter |
| 18 | The lost sock (bonus) | Discover Nidalees Lost Sock. A bonus outside ordinary completion. | Sock Detective |

Unlocks run in the same transaction after casts, purchases and upgrades.
Durable counters and per-species lifetime progress provide retroactive credit
for existing players, even when specimens have already been sold. Backfill
completion dates record when credit was awarded; they are not reconstructed
historical event dates. Repeating backfill preserves the original award date.

Private achievement progress uses scoped browser/adapter views. Public profiles
show only game ID, display name, level, lifetime fish, ordinary discoveries,
UUR/record totals, earned badge dates and selected title. They expose no Discord
ID, identity, wallet, private inventory, materials or recent-cast history.

Deployment uses additive tables plus owner-only activate_achievements and
bounded backfill_player_achievements(player_id). Existing gameplay tables stay
unchanged. Never reset the database or run proof-only helpers on live players.

The website Anglers book searches names or game IDs, with 12-entry desktop and
4-entry phone pages. Profile links use ?angler=GAME_ID#anglers. /profile accepts
that game ID; /leaderboard lists it beside each public standing.

Verification: npx tsx scripts/social-proof.ts builds a separate test module with
owner-only fixture controls and a new loopback proof database. It exercises
retroactive credit, all badges, title validation, cosmetic-only backfill,
sale races, reconnects, scoped views and revocation. Fixture controls never enter
the production WASM.
