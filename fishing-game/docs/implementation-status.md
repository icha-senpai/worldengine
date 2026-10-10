# Implementation checkpoint

The owner authorized autonomous implementation after rereading the specification
and project docs, then explicitly requested all fish and all biomes. All 251 species
and seven biomes are implemented with level cap 120 and optional purchased rods.
The website and account-link service are exposed through ServBay/Cloudflare at
https://fish.ichaa.dev. The bot connects to Discord; all fourteen commands are
registered globally; matching legacy server copies are removed on startup; the public OAuth callback is registered.

## Settled decisions

- SpaceTimeDB 2.10.2 authority; SvelteKit companion; Serenity 0.12.5 + Poise 0.7.0.
- Plain slash commands; command launch plan in discord-commands.md.
- One global 60-second cooldown between accepted casts. Rejection/replay does not reset it.
- All 251 sprite names become stable catalog species; preserve their spelling.
- Owner correction: all 249 ordinary fish can be caught at every rank, F through UUR.
  Only Fihs (UUR) and the Sock (F) have restricted ranks.
- Owner correction: ordinary rank is derived from species-relative length and weight.
  Both measurements must meet the tier's minimum; the highest qualifying rank wins.
- Fihs: mythical BitCraft fish, UUR only, second-rarest, half the rarest ordinary UUR chance.
- Nidalees Lost Sock: the one fake fish, F rank, rarest, half Fihs's chance.
- Neither exceptional fish gates progression or ordinary collection completion.
- Soften upper rarity odds toward months of active play; current prototype odds and analytic waits are in world-balance.md.
- Dockside Delivery: 100 coins daily, +250 every seventh claim, midnight UTC reset, no XP.
  Nonconsecutive stamps persist; one claim per account across servers.
- Protect favorites; confirm spending/sales.
- Permanent sequential biome licences and paid rods at the camp trader; keep level/licence gates; any owned rod can fish unlocked waters.
- No trading, premium currency, durability, offline casting, or website casting.
- Unlimited kept-fish storage; inventory size never blocks casting. Favorites and sale confirmations still apply.
- Generate bindings from WASM; do not edit generated files.

## Implemented locally

Starter account, seven rod families (free starter plus six level-gated purchases), all seven biomes and 251 named species; categories,
measurements, XP, first-discovery bonus, cooldown, permanent catch journal plus compact recent history,
private inventory, junk stacks, treasure bundle, economy ledger, personal progress,
and public per-species length/weight record snapshots. Ranks are saved per catch,
receipt, journal result, and record winner. Rank-specific XP/value drive rewards;
collection progress tracks all ten ranks without repeating species discovery XP.
Weighted physical size bands preserve encounter odds; measured length and weight
determine the final rank. Published minimums appear in collection rank guidance,
and saved catches show their length/weight relative to the species typical size.

Browser dashboard, catalog, collection, favorites, bounded sale quotes and
confirmation, unlinking, token persistence, reconnect, and subscription refresh.
The personal Journal retains individual fish, tin, and treasure pulls after sales
and receipt pruning. It has date/rank/weight/length/XP sorting in either direction,
name/biome/rank/outcome filters, and bounded server pages. Historical import
recovers retained snapshots without inventing missing XP or biome data; see journal.md.
Poise handlers for /fish, /daily, /profile, /inventory, /sell, /leaderboard, /collection, /biome, /gear, /rod, /shop, /bait, /upgrade, /help.
`/rod` shares a public card for an equipped or selected owned rod, including quality
and effective bonuses. Its original rod sprite fits an imagegen-created display
frame; quality accents, half-size PNGs and the bounded cache are applied at runtime.
/daily uses private durable stamps and eligibility, an atomic wallet/ledger grant,
selected-adapter receipts, and recovery after reconnect. Replies are ephemeral;
already-claimed requests show the next reset without changing any fishing stats.
Camp trader with illustrated stall, licence/rod shelves, responsive pages, ownership
and wallet feedback, purchase previews, confirmation, and atomic server grants.
Private ownership views are shared by browser and adapter; `/gear` only equips
owned rods, and every travel/cast checks its biome licence. Legacy migration
retains rods and licences through previously reached waters exactly once.
Website biome travel, rod equip, and collection/catalog biome/tier/name filters.
The catalog is a public compendium with 24 fish per page. Compact cards show
original artwork, species name, biome, eligible rarity, and base cast rates.
Opening a card reveals a parchment field-notes dialog with reference measurements,
catchable ranges, rank distribution, exact per-rank size envelopes, and base odds.
All measurements use inches, pounds, and ounces; derived ranges match the Rust
generator, including Fihs's UUR-only and the Sock's F-only restrictions.
Both pages use the fishing-camp theme: a pixel pond and dock, wooden navigation,
parchment panels, framed sprite slots, and locally bundled Pixelify Sans/Nunito
fonts. Decorative motion respects reduced-motion preferences.
The shell now shows one tab at a time: Camp, Tackle box, Collection, Journal,
World map, Trader, Records, Compendium, Achievements, or Anglers. Switching preserves the database connection,
filters, and inventory selection. Hash links and browser back select views;
/catalog opens the public Compendium directly. Lists use twelve-entry desktop
pages or four-entry phone pages instead of stacking the whole game vertically;
the public Compendium uses 24 fish per page at both sizes.
Records is now a public parchment record book and angler bragging board. Fish
Records lists all 249 ordinary species with independent biome/search/held-record
filters, separate longest/heaviest holders, rarity badges, and expandable catch
dates. Angler Leaderboards has Collectors, Most Fish Caught, Trophy Hunters, and
Record Holders, with podiums, shared ranks for ties, paged rows, and your position.
Legendary Finds preserves each angler's first Fihs/Sock discovery and lifetime
count separately from ordinary completion. New public tables contain only game
IDs, display names, lifetime totals, and discovery dates; private data stays scoped.
Server catch transactions refresh both current and displaced record holders.
Existing players were backfilled from durable progress, including sold catches.
/fish defers before the database operation and renders a saved receipt with
up to two reward PNGs: fish use their saved rarity card; rusted tin and treasure
scrap use the original item sprites on a matching salvage frame. Transparent
sprite margins are cropped; each sprite fits a centered box without stretching
or covering labels. Embed quantities, coins and XP come from the saved receipts.
PNG work runs on two bounded background workers; missing artwork, queue timeout,
or upload failure falls back to the committed text result. /profile shows the caller or a safe public profile by game player ID.

Axum OAuth service with identify scope, HTTP-only CSRF session cookie, exact
website Origin validation, ten-minute session and module challenge, random
browser proof, verified Discord user fetch, and restricted linker mutation.
Consumed matching link requests recover safely; mismatched replays reject.

Shared native connection serializes selected-account operations, caps waits,
reconnects with the same service credential, and retries interrupted casts with
the same interaction ID. Browser replacement removes the old mapping and nonce.
Owner service-role changes create audit rows. A scheduler prunes expired
nonces/challenges, seven-day receipts, and thirty-day audit/economy entries in
indexed batches of at most 1,000 per table per minute.

## Verified

See verification.md for the concrete checks and browser evidence. The proof
uses its own loopback host, data directory, and newly named test databases.
No sibling application's server or data was changed.

## Remaining boundaries

- Discord credentials were supplied and the bot reached Gateway readiness.
  Discord's API confirms the public OAuth callback and all fourteen global commands.
  Live command delivery/deferral, attachment rendering, OAuth
  consent/callback, and credential rotation remain unverified against Discord.
- Release/salvage,
  privacy preferences, administrative correction/restore tooling, and full V1 economy
  are later milestones from the specification.
- Catalog 5 uses 251 explicit species measurement baselines, with individual
  fantasy-creature sizes and no generic fallback. Economy values and 20
  first-discovery XP remain prototype tuning. See species-measurements.md.
  Current pools pass 1.4 million seeded casts plus exact exceptional probability checks.
  Real-player progression/economy pacing still needs launch tuning.
- Long-duration seven/thirty-day retention and backup restoration still need staging proof.

## Crafting and mixed pulls shipped

Five 10-use baits, permanent qualities per rod, exact confirmed tin/scrap recipes,
maximum 92.5% bonus pulls and +85% luck. Per-pull receipts retain results and
equipment snapshots; replay never spends twice. Website Gear and Trader flows
and /bait /upgrade share server state. Biome power gates have been removed.

## Next milestone

Confirm live Discord command/button delivery and OAuth sign-in with a real player,
then tune economy pacing from actual play. The current live deployment was updated
additively and fourteen gameplay tables matched preservation snapshots exactly.
See verification.md for completed checks and remaining boundaries.

## Social and command milestone shipped

/sell accepts explicit catch IDs with exact two-minute server quotes and
user-scoped confirm/cancel buttons. Website and Discord share atomic settlement;
favorites, ownership, prices and stale selections are checked again at commit.
/leaderboard exposes four paged standings categories with shared places for
ties, plus optional per-species length/weight records. /profile supports public
angler lookup by game ID without exposing other players' private state.

The 113 permanent cosmetic badges and titles are authoritative and retroactive:
111 ordinary milestones and collections plus separate Fihs and Sock bonus badges.
Biome collections cover every ordinary species, every individual rank and all
ten ranks, per biome and globally; lifetime catches receive retroactive credit. The website
has paged Achievements and public Anglers books, title selection and shareable
profiles. Earned badges and public titles use separate additive tables; no XP,
coins, gear, daily state or fishing odds are changed by the backfill.

Live OAuth consent and Discord command/button delivery remain a real-player
acceptance check. Isolated integration/native proofs verify server behavior.
