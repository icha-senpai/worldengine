# Implementation checkpoint

The owner authorized autonomous implementation after rereading the specification
and project docs, then explicitly requested all fish and all biomes. All 251 species
and seven biomes are implemented with level cap 60 and purchased access rods.
The website and account-link service are exposed through ServBay/Cloudflare at
https://fish.ichaa.dev. The bot connects to Discord; all nine commands are
registered globally and directly in the configured guild; the public OAuth callback is registered.

## Settled decisions

- SpaceTimeDB 2.10.2 authority; SvelteKit companion; Serenity 0.12.5 + Poise 0.7.0.
- Plain slash commands; twelve-command launch plan in discord-commands.md.
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
- Permanent sequential biome licences and paid rods at the camp trader; keep level/power gates.
- No trading, premium currency, durability, offline casting, or website casting.
- Unlimited kept-fish storage; inventory size never blocks casting. Favorites and sale confirmations still apply.
- Generate bindings from WASM; do not edit generated files.

## Implemented locally

Starter account, seven level-purchased access rods, all seven biomes and 251 named species; categories,
measurements, XP, first-discovery bonus, cooldown, compact recent history,
private inventory, junk stacks, treasure bundle, economy ledger, personal progress,
and public per-species length/weight record snapshots. Ranks are saved per catch,
receipt, journal result, and record winner. Rank-specific XP/value drive rewards;
collection progress tracks all ten ranks without repeating species discovery XP.
Weighted physical size bands preserve encounter odds; measured length and weight
determine the final rank. Published minimums appear in collection rank guidance,
and saved catches show their length/weight relative to the species typical size.

Browser dashboard, catalog, collection, favorites, bounded sale quotes and
confirmation, unlinking, token persistence, reconnect, and subscription refresh.
Poise handlers for /fish, /daily, /profile, /inventory, /collection, /biome, /gear, /shop, /help.
/daily uses private durable stamps and eligibility, an atomic wallet/ledger grant,
selected-adapter receipts, and recovery after reconnect. Replies are ephemeral;
already-claimed requests show the next reset without changing any fishing stats.
Camp trader with illustrated stall, licence/rod shelves, responsive pages, ownership
and wallet feedback, purchase previews, confirmation, and atomic server grants.
Private ownership views are shared by browser and adapter; `/gear` only equips
owned rods, and every travel/cast checks its biome licence. Legacy migration
retains rods and licences through previously reached waters exactly once.
Website biome travel, rod equip, and collection/catalog biome/tier/name filters.
The catalog is a public compendium with original fish artwork and only species
name, biome, eligible rarity, and cast rates. Size details stay on personal catches
and collection rank guidance.
Both pages use the fishing-camp theme: a pixel pond and dock, wooden navigation,
parchment panels, framed sprite slots, and locally bundled Pixelify Sans/Nunito
fonts. Decorative motion respects reduced-motion preferences.
The shell now shows one tab at a time: Camp, Tackle box, Collection, Journal,
World map, Records, or Compendium. Switching preserves the database connection,
filters, and inventory selection. Hash links and browser back select views;
/catalog opens the public Compendium directly. Lists use twelve-entry desktop
pages or four-entry phone pages instead of stacking the whole game vertically.
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
one combined fish-on-rarity-card PNG. Transparent sprite margins are cropped;
the visible fish fits a centered box without stretching or covering rank labels.
PNG work runs on two bounded background workers; missing artwork, queue timeout,
or upload failure falls back to the committed text result. The current /profile handler shows the caller;
the optional other-player lookup remains part of the later launch command pass.

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
  Discord's API confirms the public OAuth callback and all nine global commands.
  Live command delivery/deferral, attachment rendering, OAuth
  consent/callback, and credential rotation remain unverified against Discord.
- Upgrade and sell command components,
  leaderboard command, and optional public-player lookup are not registered handlers yet.
  Favorite/sale domain behavior already works through the website.
- Rod upgrade recipes, bait, release/salvage, achievements, bounded standings,
  privacy preferences, administrative correction/restore tooling, and full V1 economy
  are later milestones from the specification.
- All species measurements, economy values, and 20 first-discovery XP are prototype tuning.
  Current pools pass 1.4 million seeded casts plus exact exceptional probability checks.
  Real-player progression/economy pacing still needs launch tuning.
- Long-duration seven/thirty-day retention and backup restoration still need staging proof.

## Next milestone

Supply/configure Discord application credentials using setup.md, complete the
live Phase 0 checks, then implement the Phase 2 progression/economy pass.
The complete catalog is active in fresh local proof databases. Existing player databases
were not reset; migration of an existing deployment needs an explicit preservation plan.
