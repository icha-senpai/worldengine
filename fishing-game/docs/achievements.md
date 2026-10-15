# Achievements, titles and public anglers

Owner implementation update: this first achievement catalog is cosmetic only.
It supersedes coin/XP achievement examples in the original design specification.
No title or badge changes fishing odds, rod stats, XP, wallet or cooldown.

The badge book contains 114 badges: 111 ordinary milestones and collections, plus three bonuses. Fihs and the Sock are
optional bonus badges; Perfect catch is also optional. These badges do not gate ordinary completion. Awards are permanent,
including after a fish sale or title change. One earned badge supplies one title;
the linked website lets you select a title or clear it back to Angler.

Newly earned badges announce publicly in Discord with the angler's name, badge
artwork, achievement name, description and earned title. New awards enqueue a
private pending delivery in the same transaction, including website purchases
and upgrades. Owner backfills, initial subscriptions, reconnects and receipt
replays do not queue historical badges.
Announcing an award never changes the award, player stats or fishing rules.

Announcements use a known Discord command channel. Local channel history in
`.local/achievement-notification-routes.json` seeds a private database route when
a delivery needs it. Pending deliveries and their database routes survive bot
restarts. Missing routes keep a delivery pending until a destination is known.
Only an active Discord adapter can read, lease or acknowledge deliveries.
The bot checks pending work every five seconds, leases each attempt for two
minutes, and removes the pending row after Discord accepts the post. Failures
remain pending for a later attempt. Award state never depends on delivery.
An enforced Discord nonce derived from the award key suppresses short retries.
Delivery is at least once: a crash after Discord accepts a message but before
its acknowledgement reaches the database can still cause a duplicate after
Discord's nonce-deduplication window. It is not an exactly-once guarantee.

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
| 19 | Perfect catch (bonus) | Catch one fish at its exact maximum catchable length AND weight. | Perfect Angler |

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
that game ID and shares a public embed with portrait, selected title and badge,
level, lifetime fish and public collection/record statistics. Wallet and inventory
stay private. Standings and game IDs are available on the website.

Verification: npm run rust:script -- scripts/social-proof.ts builds a separate test module with
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

Verification: `npm run rust:script -- scripts/biome-achievements-proof.ts` covers partial
collections, duplicates, rank separation, a real final-species cast, all rank
and biome boundaries, optional bonus separation, title selection, private
progress and idempotent cosmetic backfill. The separate
`biome-achievements-migration-proof.ts` upgrades the prior production WASM and
checks every gameplay table plus existing awards. Test fixtures stay out of
production. `achievement-preservation.mjs before/after DATABASE` also verifies
live migration and credit without changing player, journal, inventory or gear.

## Perfect catches

A perfect catch reaches the exact maximum catchable length and weight on one
specimen, using the integer generator's highest allowed size band and gram
rounding. The wider safety weight cap is not the target. Fihs can qualify;
Nidalees Lost Sock is not a real fish and cannot qualify. Bait and gear are
never required: the same recognition applies to every fish catch.

The first perfect grants badge 19 and the selectable title Perfect Angler.
The public perfect_species table keeps one lifetime record per angler/species,
including its first catch ID, date and exact measurements. Duplicate perfects
do not add species or badges. Collection entries retain a gold ✦ after selling,
and web/Discord profiles show the perfected-species count. Rewards are cosmetic.
Each perfect Discord catch uses a gold PERFECT CATCH embed with the angler,
species, both maximum measurements and dedicated badge-stamped catch artwork.
Normal catches and near misses retain their normal cards.

Owner-only activate_perfect_catches adds the definition and credits exact paired
measurements from retained inventory, journal and catch receipts. Separate best
length/weight aggregates cannot establish a perfect specimen. Missing historical
evidence cannot be reconstructed. It preserves old achievements, progress,
titles, wallets, XP, measurements and saved rewards. Historical awards use the
first retained qualifying catch date; repeat activation preserves it. Stop the
bot during activation so backfilled inserts are hydrated without announcements.

Gameplay rules 11 add a 1-in-5,000 chance of both exact maxima to every UUR
size attempt. Trophy Chum supplies eight attempts for every fish species,
including Fihs; other bait and no bait supply one. Natural perfects still
qualify. This never changes the selected species or rank, and lower bands do
not receive the endpoint roll. The fake Sock stays in the encounter pool but
cannot earn the badge or a perfect-species mark.
