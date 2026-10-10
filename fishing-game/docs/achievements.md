# Achievements, titles and public anglers

Owner implementation update: this first achievement catalog is cosmetic only.
It supersedes coin/XP achievement examples in the original design specification.
No title or badge changes fishing odds, rod stats, XP, wallet or cooldown.

The badge book contains 113 badges: 111 ordinary milestones and collections, plus two bonuses. Fihs and the Sock are
optional bonus badges and do not gate ordinary completion. Awards are permanent,
including after a fish sale or title change. One earned badge supplies one title;
the linked website lets you select a title or clear it back to Angler.

Newly earned badges announce publicly in Discord with the angler's name, badge
artwork, achievement name, description and earned title. The bot observes
committed award inserts, including website purchases and upgrades. Initial
subscriptions, reconnects and receipt replays do not announce existing badges.
Announcing an award never changes the award, player stats or fishing rules.

Announcements use each angler's most recent Discord command channel. Channel
history is saved locally in `.local/achievement-notification-routes.json`, scoped
to the configured database, and survives bot restarts. Existing anglers were
seeded from their latest retained fishing receipts. New anglers establish their
channel with their first bot command. If no channel is known, or Discord cannot
deliver the embed, the badge remains earned; a missing destination is logged.
Delivery retries use an enforced Discord nonce derived from the award key.

The owner can run `python scripts/bootstrap-achievement-channels.py DATABASE`
while the bot is stopped to seed missing channel history from retained casts.
This only reads the local database and preserves newer saved channels. The bot
needs its existing Send Messages and Embed Links permissions in that channel.

Click or keyboard-activate a badge image in the website badge book to inspect
larger artwork, its name, requirement and title. Locked list badges stay faded;
their preview shows the full artwork and clearly says Still to earn. The dialog
fits desktop and phone screens, closes by Escape, its close button or a backdrop
click, and returns focus to the image button without changing filters or titles.

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
retroactive credit, the original milestones, title validation, cosmetic-only backfill,
sale races, reconnects, scoped views and revocation. Fixture controls never enter
the production WASM.

## Biome and rank collections

There are 96 collection badges, including the existing Pond collection. This
adds 95 badges without duplicating that badge or changing its title:

| Collection | Badges | Requirement |
|---|---:|---|
| Every species in a biome | 7 | Each ordinary species in that biome at any rank |
| Every species across all biomes | 1 | All 249 ordinary species at any rank |
| A rank collection in a biome | 70 | Each ordinary species in that biome at the specified rank |
| A rank collection across all biomes | 10 | All 249 ordinary species at the specified rank |
| Every rank in a biome | 7 | Each ordinary species in that biome at all ten ranks |
| Every fish, every rank | 1 | All 2,490 ordinary species/rank pairs |

Ranks are F, D, C, B, A, S, SS, SSS, UR and UUR. Duplicate catches cannot fill
missing species or ranks. One UUR catch contributes to the UUR collection and
any-rank discovery, but does not substitute for catching that species at F.
Fihs and Nidalees Lost Sock remain optional bonus finds in every collection.

Targets derive from the authoritative ordinary species catalog. Progress reads
per-species lifetime counts and rank counts, so selling a specimen never loses
credit. Every-rank progress counts distinct species/rank pairs rather than
completed species, allowing steady progress toward the final badge.

Stable IDs: Pond uses 10; other biome discoveries use 20 + biome ID; world
species discovery uses 30. Biome rank collections use 100 + (biome ID - 1) * 10
+ rank ordinal; world ranks use 200 + ordinal. Biome capstones use 300 + biome
ID - 1; world completion uses 310. Ordinals run from F = 0 through UUR = 9.
Public achievement_collection metadata supplies scope and criteria; individual
unfinished progress stays in the existing scoped views. New titles follow
biome/rank names, with World Completionist for the final global collection.

The badge book filters by earned status, collection scope, biome and rank,
with 12 badges per desktop page and four per phone page. It displays species
or species/rank-pair progress as appropriate.

Deployment: publish the additive WASM, then run
`node scripts/backfill-biome-achievements.mjs fishbound-dev-local`. This owner-only
bounded backfill seeds the catalog and grants retrospective credit, preserving
existing earned dates and gameplay. No database reset is needed.

Verification: `npx tsx scripts/biome-achievements-proof.ts` covers partial
collections, duplicates, rank separation, a real final-species cast, all rank
and biome boundaries, optional bonus separation, title selection, private
progress and idempotent cosmetic backfill. The separate
`biome-achievements-migration-proof.ts` upgrades the prior production WASM and
checks every gameplay table plus existing awards. Test fixtures stay out of
production. `achievement-preservation.mjs before/after DATABASE` also verifies
live migration and credit without changing player, journal, inventory or gear.
