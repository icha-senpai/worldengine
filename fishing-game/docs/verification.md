# Implementation verification — October 3–4, 2026

## US customary measurement displays (October 8)

Discord catches, inventory lengths and species leaderboards, plus website catch
cards, collection bests, Records and public angler records now show inches and
avoirdupois ounces/pounds. Length keeps one decimal; weight keeps two decimal
places in ounces, showing ounces alone below a pound and pounds plus remaining
ounces above it. Both clients use exact integer conversion and round before
splitting pounds, preventing a 16-ounce remainder. Stored mm/g values and all
gameplay rules remain unchanged. Conversion constants were checked against
[NIST Handbook 44, Appendix C](https://www.nist.gov/system/files/documents/2025/12/30/appc-26-HB44-20251222.pdf).

All eight Discord-bot tests passed, including tiny 1 g catches, the pound boundary,
exact pound displays and inch rounding. Equivalent TypeScript checks passed.
Svelte check found zero errors/warnings; the production website and bot builds
passed. Restarted all local services; the Discord gateway reported ready.
Playwright verified real public Carp record values at desktop and 390 px phone
widths, with no horizontal overflow and readable lb/oz labels. Screenshots are
`output/playwright/us-units-records-desktop.png` and
`output/playwright/us-units-records-mobile.png`. No real cast was performed.

## Species measurements, catalog 5 (October 8)

Reviewed every supplied sprite and replaced shared family/fallback sizes with
251 explicit CSV baselines. 249 measurement pairs changed; two reviewed original
pairs were retained. Rebuilding preserves all stable IDs/names/sprites, biomes,
encounter tickets, XP, base values, rank prices and exceptional probabilities.
The eight historical starter definitions no longer override active measurements.
Rank thresholds remain version 4; game rules remain version 6. New catches use
catalog version 5, with the existing cubic curve and whole-gram rounding.

Content validation and all 20 game-rule tests passed, including 100 generated
specimens for every one of the 2,492 legal species/rank combinations. The module
WASM and generated Rust/TypeScript contracts built; Svelte check had zero errors
or warnings; the website production build and both native services built.

`scripts/measurements-proof.ts` published a saved v4 production WASM into a fresh
isolated proof database, created an old catch, additively published v5 and called
the owner-only activation reducer. It proved 251 updated definitions, preservation
of 43 other stored tables, unchanged non-measurement species fields, rejected
non-owner calls, idempotent activation, preservation of the v4 catch and generation
of a fresh v5 catch. The broader integration proof also passed all 20 checks,
including native adapter/reconnect, cast replay, sale protection and record updates.

Stopped the local web/linker/bot, saved all 44 non-timer tables, published v5
additively to the existing live database and activated only measurement metadata.
`scripts/measurement-preservation.mjs after` confirmed all 251 definitions matched
the new CSV while all 43 other tables and non-measurement species fields were
preserved exactly. Restarted services; the Discord gateway reported ready and
the public homepage/account-link readiness returned 200. Public fresh/reconnect
subscription and privacy/route checks passed. Existing records are preserved;
oversized historical catches may therefore remain ahead of the new species limits.

The full table, interpretation of ambiguous species and source calibration are
documented in [species measurements](species-measurements.md). No real player's
cast was invoked for verification; migration casts occurred only in proof DBs.

## Discord salvage cards (October 8)

Generated `assets/item-cards/salvage.png` with built-in imagegen using the original
F-rank frame as the reference. The exact prompt is saved in
`docs/salvage-card-art-prompt.json`. The Discord renderer places the original tin
and scrap sprites into its centered safe box; both credential-free previews in
`output/material-cards` were visually inspected. No rank is assigned to materials.

All six Discord-bot tests passed, including both real material PNGs, preservation
of every background pixel outside their fitted bounds, missing artwork handling,
and all 251 fish across all ten rank frames. The asset manifest and website
production build passed. Gameplay rewards, cooldown and database schemas did not
change. Rebuilt and restarted the services; the Discord gateway reported ready,
the public homepage and account-link readiness returned 200, and the public
1024×1536 salvage PNG matched the local file's SHA-256 hash.
Bot delivery in a real Discord channel still requires a player cast;
this verification does not send guild messages or mutate player inventory.

Following the owner's explicit preview request, both material cards were uploaded
as one bot message to The Magic Tree House's `#fishy-game`. Re-fetching that message
confirmed both image embeds; the Discord-hosted PNGs matched the renderer output
by SHA-256. This checks image delivery without executing `/fish` or granting rewards.

## Licence and bait artwork (October 5)

Generated six licence and five bait icons with the built-in image tool, then
copied the original RGBA PNGs into the asset root. All images were visually
inspected. Decoded pixel samples confirmed transparent outer corners and solid
subjects. The updated manifest records dimensions and SHA-256 hashes.

Svelte check completed with zero errors and warnings, content validation passed,
and the production build passed. Restarted through the existing local-service
launcher. All eleven public image URLs returned PNGs matching the originals by
SHA-256. Live anonymous Trader checks loaded all six licence and six existing
rod images, retained guest purchase gating, and showed no horizontal overflow
at 1440, 390 and 320 pixels. Screenshots are
`outputs/playwright/trader-licences-{1440,390,320}.png`.

The actual Gear component, using an isolated synthetic account, loaded the
Plump Worms coming-soon illustration and retained its noninteractive placeholder
at the same three widths. Screenshots are
`outputs/playwright/bait-placeholder-{1440,390,320}.png`. Desktop and 320-pixel
screenshots were visually inspected. Bait and quality crafting were not
implemented, and no player rewards or purchases were executed by these checks.

## Automated checks

- Content validator: 251 stable named species, ten rank cards, all seven positive
  encounter pools totaling 1,000,000 tickets each, reachable level/gear gates,
  all ten ranks on each of 249 ordinary species, single-rank exceptions,
  exact per-cast global exceptional ordering and both half-probability ratios.
- Fifteen pure rule tests: sampling boundaries/overflow, 50,000 seeded measurement
  samples and final grades/bounds, XP thresholds, record tie ordering, and
  Discord interaction age limits, all 2,492 species/physical-band encounter boundaries
  and measurement bounds, 1.4 million seeded casts classified from actual measurements across seven pools, and the
  full XP curve/rod gates through Abyssal Shelf within the level-60 cap.
- Three OAuth tests: code exchange as form data and authenticated user fetch;
  cookie flags/path/expiry; matching cookie/state and one-use/expired sessions.
- Eighteen local integration checks against a fresh proof database, including
  service grants/revocation, rejection of raw private subscriptions, idempotent
  starter creation, server cooldown/RNG/specimens, durable replay/conflicts,
  concurrent first casts, link proofs/replacement/unlinking, browser reconnect,
  native Rust transport, favorites, confirmed sale replay, and retained progress/records.
  New checks compare every seeded species/biome/rod to content, exercise native
  and browser loadout intent, reject unavailable and locked destinations/rods,
  enforce linked-account/service authority, and prove loadout keeps cast cooldown.
- Native adapter proof also disconnects its connection and recovers the same
  receipt automatically with the same client and interaction ID. Concurrent
  different-player requests cannot cross the selected-player view.
- WASM module builds; Rust and TypeScript bindings regenerate from it.
- Native workspace and WASM Clippy checks pass with warnings denied.
- Svelte/TypeScript checks pass with zero errors/warnings; production build passes.

The local host uses 127.0.0.1:3127 and ignored .local/spacetimedb. Each integration
run publishes a newly named fishbound-proof-* database, never resets an existing
database, and uses separate ephemeral browser/bot/linker identities. Test fixture
credentials remain in ignored .local and are never displayed or committed.

## Browser verification

Playwright CLI with installed Edge inspected the production preview:

- Anonymous welcome and scoped linked-player dashboard.
- Live wallet, XP, cooldown, collection, original sprite images, and record rows.
- Favorite/unfavorite changes persisted and favorites disabled sale selection.
- Sale preview showed the exact catch and full payout; cancel preserved ownership;
  confirmation removed the fish and credited coins while retaining discovery and records.
- Reload reconstructed committed post-sale state.
- A real local server stop cleared private dashboard rows and disabled actions.
  Restarting the same isolated server restored live state automatically.
- Desktop 1440px and mobile 390px layouts inspected visually; no horizontal
  overflow or broken dashboard images. Catalog search found Fihs with UUR lore.
- Healthy browser sessions had no console warnings/errors. Deliberate server
  interruption produced expected connection-refused messages before recovery.

The initial October 4 full-content production preview additionally verified all seven
catalog pool counts (28/31/22/22/52/45/51), exact biome/rank/name filtering,
the original fixed-rank presentation (superseded by the owner correction below),
the 249-entry ordinary completion denominator, locked travel controls at level 1,
seven biome cards, and earned-rod selection. Desktop 1440px and mobile 390px
screenshots show no horizontal overflow or broken displayed images. Browser
console has zero errors/warnings. Local screenshot evidence includes
all-biomes-desktop.png, all-biomes-mobile.png, bonus-book-mobile.png, and
fihs-catalog-mobile.png under output/playwright.

Screenshots are ignored local artifacts under output/playwright. Initial asset
verification is preserved in scaffold-verification.md: all 261 original PNGs
matched ZIP and served-file checksums. Supplied images were not recompressed.

## Limits of this proof

No live Discord command registration, deferral response, attachment delivery,
or OAuth consent occurred: application credentials have not been supplied.
Protocol fixtures and SDK tests are local proofs, not Discord verification.
High-level unlock reachability and every encounter interval are verified by
content/pure-rule checks; integration does not bypass cooldown or grant artificial
XP to catch every species or grind to level 55. Rare waits in world-balance.md
are analytical quantiles, not observed player histories. Measurements, XP, and
economy values remain prototype tuning for real-player balance review.
Long-age receipt/audit expiry, service-token rotation, production recovery,
backup restoration, deployment performance, and full V1 balance still require
staging verification. Scheduled cleanup is implemented with bounded indexed
expiry batches; this session did not simulate seven or thirty days of uptime.

See implementation-status.md and setup.md for the next live checks and setup.

## All-ranks owner correction

Content version 3 removes fixed species ranks. All 249 ordinary species have ten
positive, selectable rank intervals; Fihs has only UUR and the Sock only F. The
validator checks all 2,492 pairs and their exact per-cast probabilities, including
the exceptional hierarchy and unchanged Fihs/Sock odds. Pure boundary tests and
1.4 million seeded casts cover the joint species/rank pools. Integration compares
every seeded rank row to JSON, checks saved receipt/specimen rank consistency,
rank-based XP/value and collection counters, and preserves those fields through
receipt replay, sale, and reconnect. Schema bindings were regenerated from WASM.

The corrected browser preview verified all 249 ordinary species appear at each
of the ten ranks, with exactly one additional eligible exception at F and UUR.
Every Meadow Pond species appears under UUR. Abyssal Shelf shows 49 ordinary
species plus Fihs at UUR, or those same 49 plus the Sock at F. The linked fixture
caught an SSS Tench; inventory showed SSS and its saved sale value, while the
collection marked exactly its SSS rank as caught. Catalog filters showed different
per-cast odds for F and UUR Minnow. Desktop/mobile layouts had no horizontal
overflow and the healthy console had zero warnings/errors. New screenshot evidence
is all-ranks-book-mobile.png and minnow-all-ranks-catalog.png.

## Size-derived ranks owner correction

Content and rules version 4 generate physical measurements before deriving rank.
Both length and weight must meet species-relative minimums; the highest qualifying
rank wins. Weighted size bands preserve the approved probability distribution.
All 2,492 allowed species/bands have nonempty bounds, and 100 seeded measurements
per band classify into the intended rank. The 1.4 million cast simulation follows
the active category/species/physical-band/measurement/classification path and checks
final rank frequencies against the exact ticket probabilities. Boundary tests
require both dimensions, prove monotonicity, cover checked overflow, and show a
trophy minnow outranking a physically larger typical shark. Published JSON cutoffs
match the authoritative Rust constants.

All 15 rules tests and 18 integration checks pass. Integration checks public size
thresholds and independently classifies saved integer measurements, then compares
receipt/specimen rank, rewards, and collection progress. WASM and native Clippy
pass with warnings denied, bindings regenerate from WASM, formatting checks pass,
Svelte checks have zero errors/warnings, and the production build passes.

Playwright with Edge inspected the new preview against a fresh version-4 proof
database. The linked fixture's C Fathead Minnow measured 8.9 cm / 13 g (0.99 times
typical length / 0.93 times typical weight), consistent with C rather than B.
Inventory displays both relative measurements; collection exposes exact tier
minimums. All 249 ordinary fish appear at every rank; F and UUR each add their one
eligible bonus discovery. Meadow UUR shows 28 fish; Abyssal F/UUR each show 50.
The catalog table matches all ten published cutoffs, including F below D in either
measurement, and retains Minnow odds of about 1 in 4,990.5 at UUR and 1 in 69.5 at F.
Desktop 1440px and mobile 390px have no horizontal overflow, inspected dashboard
images are intact, and the healthy browser console has zero warnings/errors.
Local screenshots are size-ranks-desktop.png, size-ranks-book-mobile.png,
size-thresholds-mobile.png, and size-thresholds-desktop.png under output/playwright.

Fihs and Sock probabilities and the 60-second cooldown are unchanged. Existing
player databases and previously saved catch ranks were not rewritten. Live Discord
delivery remains unverified without credentials.

## Public compendium presentation

The owner requested only fish name, biome, rarity, and cast rates on the public
catalog. Fish artwork and search/biome/rank filters remain. The rank-card gallery,
size threshold table, typical measurements, lore, and bonus/fake-fish labels were
removed from that page. Personal catch measurements and collection rank guidance
remain on the dashboard. This supersedes the catalog presentation described above.

Svelte checks pass with zero errors/warnings and the production build passes.
An anonymous Playwright session with empty browser storage sees all 251 fish at
/catalog without linking an account. Name and rank filters preserve Minnow's F/UUR
rates, Fihs remains UUR-only at about 1 in 10,016 accepted casts, and Abyssal F/UUR
each include 50 eligible species. Desktop and mobile screenshots were inspected;
mobile has no horizontal overflow or broken displayed images, and the browser
console has no warnings/errors. Evidence is public-compendium-desktop.png and
public-compendium-mobile.png under output/playwright.

## Fishing-camp visual pass

The dashboard and public compendium now share pixel lettering, wooden navigation,
parchment panels, framed fish slots, and a decorative sunset pond with a dock.
Pond scenery is a local SVG component and uses the original Koi/Minnow sprites.
Pixelify Sans and Nunito 5.3.0 are bundled locally through Fontsource; no runtime
font provider request is needed. The supplied Koi sprite is also the favicon.
Decorative swimming/bobber motion stops when reduced motion is requested.

Final Svelte checks have zero errors/warnings and the production build passes.
Playwright/Edge inspected the welcome screen, linked tackle box, collection,
world cards, and compendium. Both pages fit 320, 390, 768, and 1440px widths.
Displayed images are intact and the healthy console has no warnings/errors.
Favorite protection, unfavorite, exact 15-coin sale preview, and cancel were
exercised; the owned fish remains and its original favorite state was restored.
The 249-entry collection, seven biomes, 251 compendium entries, selected-rank
filters, Fihs odds, and the four-field public catalog presentation are preserved.
An anonymous browser still sees welcome/public content without private inventory.

Evidence under output/playwright: game-camp-desktop.png, game-camp-mobile.png,
game-camp-welcome.png, game-collection-mobile.png, game-compendium-desktop.png,
game-compendium-mobile.png, and game-compendium-slots-mobile.png. This pass changes
the frontend presentation; authoritative rules and stored rewards were not edited.

## Tabbed camp and book pages

The camp uses one persistent shell and database connection with seven views.
Only one tab panel is visible. Filters and inventory selection survive tab switches;
hash links, browser back, and reload select the corresponding view. Keyboard arrows,
Home, and End change/focus tabs, skipping personal tabs for anonymous visitors.
/catalog is a direct entry into the same public Compendium view.

Svelte checks and the production build pass with zero Svelte errors/warnings.
Browser checks traverse every desktop compendium page: 251 unique fish across 21
pages, with 11 on the final page and disabled end controls. Search from the last
page resets to page one, and search/rank filters preserve the published rates.
Meadow collection pages contain 12, 12, and 4 entries with no overlap. Search and
selected inventory IDs survive tab switching. Browser back restores the previous
tab without a document reload. Phone lists contain four entries; the seven-biome
map uses pages of four and three. Resizing resets pages while preserving filters.
Anonymous /catalog loads the compendium and keeps private tabs/catches unavailable.
Widths 320/390/768/1440 fit without horizontal overflow; the healthy console has
no warnings/errors. Screenshots include tabbed-compendium-desktop/mobile.png and
tabbed-collection-desktop/mobile.png under output/playwright.

## Discord catch cards

/fish now attaches one composite PNG: the saved species on the saved rarity's
original card. The renderer trims transparent sprite margins, fits the visible
art proportionally into a centered 70%-width / 34%-height box, and scales with
nearest-neighbor sampling. Rank labels and card borders remain clear. It runs
off the async executor, with two rendering workers and a five-second queue wait.
Artwork or upload failure delivers the committed text result without another cast.

Four renderer tests pass: asymmetric transparent padding, tall proportions and
partial-alpha blending, blank/missing artwork and invalid keys, and placement of
all 251 supplied sprites across all ten card layouts. The preview example uses
the production renderer and successfully encodes/decodes ten 1024x1536 PNGs,
including Minnow, Seahorse, Eel, Sunfish, Ancient Sturgeon, Aurora Frostfin,
Octopus, Fihs, and Nidalees Lost Sock. Outputs are about 1.55-2.23 MB; generation
and preview decoding take about 1.0-1.3 seconds per card in the local debug build.
The contact sheet and full Fihs card were visually inspected for centering,
aspect ratio, crisp pixels, and unobstructed headers/borders.

All 261 original asset SHA-256 hashes still match assets/manifest.json. Native
workspace Clippy passes with warnings denied; Rust formatting passes. Preview
artifacts are under ignored output/catch-cards, including all-ranks-preview.png.
Real Discord upload and embed display still require live application credentials.

## Local service environment

Created ignored root and website .env files targeting a newly published
fishbound-dev-local database on the existing isolated host at 127.0.0.1:3127.
Discord credentials are left for the owner to fill in. Website/callback defaults
are localhost:5173 and localhost:3001/auth/discord/callback. Existing private
bot/linker token files were reused and granted their separate service roles on
the new database. Read-only SDK subscriptions verified both identities have
their active matching grants and can read all 251 species and seven biomes.
Root/web connection settings and callback origin match; Git ignores both .env
files and token files. Svelte checks and the production build pass with this
configuration. Proof databases and player fixtures remain separate.

## ServBay and public Cloudflare host

ServBay MCP created the Fishbound site and its local certificate, then added
fish.ichaa.dev to the existing cloudflared tunnel. Both the new public website
and account-link readiness return 200; test.ichaa.dev still returns 200. Cloudflared
ingress validation passes. Website runtime now uses adapter-node 6 instead of
adapter-auto, with public HTTPS/WSS addresses and local native-service addresses.
Svelte checks and the production Node build pass.

Public SDK proof verified an anonymous fresh connection and a reconnect with its
saved credential: 251 species, seven biomes, and no private player. This exercises
both NGINX WebSocket upgrade and the short-lived token exchange through the
Cloudflare tunnel. Unrelated database subscriptions and raw SQL routes return
404; an unrelated Origin on account linking returns 403. Browser checks show Live
on the public site after reload, with the login button enabled and the compendium
available. Screenshots are servbay-public-camp.png and servbay-public-compendium.png
under output/playwright.

The actual start, close, and restart .cmd launchers were exercised. Repeated Start
reuses the recorded processes; Close/Restart stop only matching tracked bot,
linker, and web processes, preserving the database and shared ServBay services.
The bot token is valid and matches the OAuth client ID; native Gateway logs show
"Discord adapter ready". Command registration stays off as configured. Discord's
registered OAuth redirect list was empty when inspected, so the public callback
still needs registration and full login/live-command verification. A combined
SpaceTimeDB help/Discord command-list lookup was rejected by automatic policy
review; its only stated reason was "blocked by policy". It was not needed for
the public route and runtime checks above.

The owner subsequently added the public callback and set command registration
true. After the actual restart launcher ran, a focused read-only Discord API
check confirmed the callback matches DISCORD_REDIRECT_URI and the global command
list contains exactly /fish, /profile, /inventory, /collection, /biome, /gear,
and /help. The bot reached Gateway readiness again. The website and account-link
readiness still return 200. This focused check succeeded; the earlier combined
lookup remains recorded above. Full user OAuth consent/callback and a real
/fish response still need an interactive player check.

### Discord command visibility follow-up

The owner reported no visible commands. Discord's API confirmed seven global
commands, no guild-scoped commands, and the bot installed in exactly one server:
The Magic Tree House. The command defaults did not restrict member permissions.
Configured DISCORD_GUILD_ID for that server and ran restart-fishbound.cmd. The
read-only API verifier then confirmed all seven commands registered directly in
the guild and the native Gateway reached readiness. This uses Discord's immediate
guild command updates; client visibility and a player invocation remain to be
confirmed. Existing global commands were preserved.

## Records book and angler leaderboards — 2026-10-04

- Added public lifetime standings and first legendary discoveries as new tables;
  existing schemas and private caller-scoped views were preserved. Both bindings
  were regenerated from the release WASM. Record transfers refresh previous and
  new holders in the same catch transaction; receipt replay cannot add points.
- Svelte checks pass with zero errors/warnings; production build, workspace
  formatting, native workspace Clippy, and WASM module Clippy pass.
- The isolated integration runner passed 19 checks against
  fishbound-proof-1791155958914-mciimz. Its 78 test anglers exercised 39 real
  record transfers. Checks compare standings to durable progress/records,
  reject unauthorized backfills, preserve scores after a sale and receipt replay,
  confirm anonymous public subscriptions, and verify repeatable owner backfills.
- Offline browser fixtures used synthetic data only, never written into a game
  database. Checks covered all four categories, shared ranks, an own-position
  outside the first page, highlighted rows, all 249 ordinary species across 21
  pages, independent filters, catch details, legendary finds, guest/empty states,
  keyboard tabs, and 768/390/320px widths without horizontal overflow.
- A separate local QA server start was rejected by automatic approval review
  with only "blocked by policy". Browser fixture verification instead loaded a
  compiled offline bundle into a blank page; no additional service was started.
- Updated fishbound-dev-local with the two-table additive migration, then
  backfilled both existing anglers. Public WSS verified matching public profile
  and standing totals (16 lifetime fish at inspection), 24 current record titles,
  and zero legendary discoveries. New live casts were already increasing totals.
- Public-site browser checks passed for all categories, retained filters across
  camp views, responsive pages, both original legendary sprites, and
  1440/768/390/320px widths. Website and bot were running after the update.
  Screenshots: output/playwright/records-public-leaderboard.png,
  records-public-mobile.png, records-fixture-leaderboard.png, and
  records-fixture-mobile.png. Personal highlighting and populated Legendary
  Finds were verified with fixtures; neither rare fish was fabricated live.

## Unlimited inventory — 2026-10-04

- Removed the pre-cast capacity rejection. Bot /profile and /inventory now show
  fish kept, and the website tackle box shows its count without a denominator.
  Favorites and confirmed sales are unchanged. Updated the design and command
  docs to make unlimited storage the settled rule.
- Kept the legacy inventory_capacity column for compatible updates; it is
  ignored by casting, seeded as zero, and cleared on existing databases by an
  owner-only, repeatable metadata migration. Existing catches are untouched.
- `npx tsx scripts/inventory-hoarding-proof.ts` passed on the isolated
  fishbound-proof-inventory-1791157033862 database: 100 seeded specimens grew to
  103 through normal cast reducers. Exact counts, cooldown rejection, receipt
  replay, and repeated metadata migration all passed. Fixture reducers are
  appended only to a separately named proof WASM; production WASM is unchanged.
- The full integration runner passed 19 checks on
  fishbound-proof-1791156992175-093ibc, including owner-only migration access,
  private views, favorites/sales, and correct record/leaderboard totals. Svelte
  checks and the production build pass, as do native/workspace and WASM Clippy.
- Published the production module to fishbound-dev-local without table changes
  or database clearing, then ran migrate_unlimited_inventory as its owner.
  Live configuration shows inventory_capacity zero and the cooldown still 60
  seconds. Restarted the rebuilt bot and production website; the bot reached
  Gateway readiness and all seven guild commands remain registered.

## Dockside Delivery daily rewards (October 4, 2026)

- All 18 pure rules tests passed, including UTC midnight eligibility, nonconsecutive
  stamps, seven/fourteen-claim totals, and explicit arithmetic overflow handling.
- All 20 integration checks passed against fresh loopback proof database
  `fishbound-proof-1791159142425-j48d9u`. Simultaneous claims in different guilds
  credited exactly 100 coins. Receipt replay and conflicting channel/command IDs,
  expired requests, unauthorized/linker callers, and service revocation were checked.
  Fishing XP, counters, standings, inventory, and cooldown stayed unchanged.
- The native Rust adapter claimed, recovered the same receipt after disconnect,
  and rejected a second grant in another guild. Existing casts, account selection,
  and reconnect tests still passed.
- `scripts/daily-delivery-proof.ts` builds a separately named fixture WASM and fresh
  proof database; fixture reducers are never included in production. It verified
  that a fifteen-day gap preserves six stamps, the seventh/fourteenth award 350,
  the next eligible day starts at stamp one, replay/rejection creates no extra
  ledger entry, and coin overflow rolls back wallet, receipt, and stamps atomically.
- The four catch-card renderer tests, workspace Rust check, both formatting checks,
  Svelte check (zero errors/warnings), production website build, and diff check passed.
- The live database migration added only two private daily tables and one scoped
  adapter view; existing tables and player data were retained.

Discord API verification confirmed all eight commands globally and in the configured
guild, including `/daily`. The rebuilt bot reached Gateway readiness; the public
website and account-link readiness endpoint returned HTTP 200 after restart.

The bot replies ephemerally with committed reward, stamp progress, and a relative
next-reset timestamp. Direct command response delivery still requires a player
invoking `/daily` in Discord; tests did not send Discord messages or claim any live
player's reward. Long-duration receipt pruning remains a staging check.


## Rod artwork, equipped bonuses and tackle-box gear (October 5, 2026)

Duplicate-command correction: Discord API reads confirmed the same nine command
names/types existed globally and separately in both installed servers. The bot
now registers only the global set; configured guild IDs identify legacy cleanup
targets. Startup reads actual global/guild definitions and deletes only matching
name/type copies, preserving guild-only commands. Rust check/build passed. The
rebuilt bot removed 18 guild commands and reported ready. Subsequent API reads
confirmed all nine global commands, zero local commands in both The Magic Tree
House and Kyeri's Cozy Kingdom, and the existing public OAuth callback. No
gameplay state or other applications' commands were changed. Earlier direct
registration evidence later in this document describes historical behavior.

Pond animation follow-up: Windows client-area animations were observed disabled;
the previous pond CSS stopped when prefers-reduced-motion reported reduce. The
browser mismatch was reproduced by emulating that preference; Opera GX itself
was not directly inspected. PondScene now defaults to the system preference but
provides an accessible Animate pond / Pause pond toggle, remembered in local
browser storage. An explicit animation choice overrides the global motion rule
only for the koi, bobber and ripples. Decorative scene art remains aria-hidden;
the control is accessible to keyboard and screen-reader users.

The live browser check verified actual changing transforms under reduced motion,
pause, reload persistence, OS-preference changes, keyboard control and nonoverlap
at 320/390/1440 widths. Svelte check reported zero errors/warnings and the
production build passed. Website, linker and bot restarted successfully.

Seven transparent RGBA rod PNGs were generated with built-in image_gen, copied
to assets/rods, validated for alpha and recorded in the asset manifest. Prompts
are in docs/rod-art-prompts.json; gameplay policy is in docs/rod-progression.md.

Validation passed: 20 pure game-rules tests (including exact rod-adjusted odds in
all seven biomes and every modifier tier), 20 production-WASM integration checks,
the expanded trader proof (equipped Abyssal XP once including discovery; replay
after switching to Twig retains rewards; cooldown unchanged), native Rust
adapter/reconnect checks, Svelte check with zero warnings/errors, workspace Rust
check and production web/native builds.

An isolated fixture rendered the actual Gear, Trader and Junk & Materials Svelte
components with synthetic account state. Browser checks covered gear placement,
reactive rod artwork/stats, inert bait slot, current-biome power restrictions,
equipped comparisons and widths 320/390/768/1440. Desktop/mobile screenshots were
visually inspected. Public-site browser checks loaded all six purchasable rod
images and correct bonuses; all seven image URLs returned HTTP 200. A real
Discord player's private tackle box was not used for UI testing.

The live additive module upgrade created only rod_bonuses, then owner activation
seeded seven rows and inserted rules-version-5 config (60s cooldown, unlimited
inventory, level cap 60). Preservation snapshots matched 14 gameplay tables
exactly, including players, coins, XP, specimens, rod/licence ownership, items,
ledger, collection, profiles, records, standings, legends, daily state and cast
receipts. Services restarted successfully; the Discord adapter reported ready,
public WebSocket reconnect/privacy checks passed, and commands remained present.

## Camp trader, paid rods, and biome licences

- The content validator checks twelve unique positive-price offers, matching
  biome/rod references, levels, and preceding biome gates. Prices are prototype
  tuning in content/trader.json; catch probabilities remain unchanged.
- The complete twenty-check production-module integration proof passed against
  fishbound-proof-1791163327590-yf5ay1, including new ownership/quote privacy,
  unauthorized shop/migration rejection, and existing fishing/daily/link/sale rules.
- scripts/trader-proof.ts compiles a separately named fixture WASM to a fresh
  loopback proof database. It purchases both items at every tier and reaches all
  seven biomes, verifies no free /gear unlock, sequential licences, level/coin
  failures, exact coin deductions, replay, stale prices, expired quotes, wrong
  player/nonce, competing browser/Discord commits, and one ledger credit/debit
  pair per grant. One-time legacy migration preserves reached waters and never
  waives purchases on later reactivation. Unlink clears all personal shop views.
- The native client proof confirms a purchase and recovers the same consumed quote
  after reconnect without spending again. It also rejects a low-level offer.
- Browser UI was tested using the real Trader/TraderStall components with clearly
  labelled synthetic data. Stall entry, both shelves, price previews, cancel,
  confirm, ownership, balance/level gates, and responsive pagination passed.
  Screenshots in output/playwright/trader-fixture-{desktop,mobile}.png were viewed.
  Desktop 1440px plus 768/390/320px checks showed no horizontal overflow.
- All eighteen rules tests and four catch-card tests passed. Workspace Rust check,
  Svelte check (zero errors/warnings), production build, formatting, and diff checks
  passed. Live Discord button response delivery needs a player invoking /shop;
  no live reward/purchase was executed for a player during verification.


Live deployment verification: the migration added only the new trader catalog,
private licences/quotes/migration marker, and scoped views. Existing player and
owned-rod rows matched saved pre-update snapshots exactly, excluding SQL timing
metadata. Owner activation seeded twelve offers and completed preservation once.
Discord's API confirms nine guild/global commands, including optional /shop item_id;
the native bot reached Gateway readiness. The public WebSocket exposes twelve
catalog offers and no anonymous player, rods, licences, or quotes, including after
reconnect. Public Camp/Trader navigation, live shelves, guest purchase gating, and
1440/390/320px layouts passed; screenshots are trader-public-{camp,desktop}.png.
Account-link readiness returned HTTP 200. No live player's coins were spent by tests.


## Two-server Discord command visibility (October 5)

Discord returned all nine global commands and direct commands for The Magic Tree
House, while Kyeri's Cozy Kingdom had no direct registrations. The bot was present
in both; Cozy Kingdom's everyone role allowed application commands, channels had
no explicit denial of that permission, and application-command permission
responses contained no overrides. This supports a global-command/client visibility
issue; the user's Discord picker itself was not inspected.

Added comma-separated DISCORD_GUILD_IDS alongside the supported DISCORD_GUILD_ID.
Startup trims/deduplicates the combined IDs, registers globally, and refreshes
both configured servers. Check, build, formatting, and diff checks passed. After
restarting the rebuilt bot, the Discord API confirmed all nine direct commands in
both servers and the bot reached Gateway readiness. No Discord message was sent
or gameplay state changed during these checks.


## Bait, permanent rod quality and mixed pulls (October 5)

Gameplay rules 6 and trader catalog 2 are published to fishbound-dev-local and
served at https://fish.ichaa.dev. This update adds metadata/private tables and
scoped views without replacing existing player schemas. Owner activation seeds
five bait types, seven qualities and seventeen trader offers; all old biome
power requirements become zero. Before/after snapshots matched every row and
schema in fourteen gameplay tables: player, rods, licences, catches, discoveries,
materials, ledger, public profile, species records, standings, legendary finds,
daily state, daily receipts and cast receipts. No player's rewards or wallet
were used for verification.

Validation completed:

- Content validation, twenty Rust rules tests and four catch-card renderer tests.
  Rules tests cover every biome/family/quality/luck combination, positive integer
  ticket weights, fixed normalization, exact exceptional per-pull ratios, XP
  arithmetic and power bounds. Every supplied fish sprite fits every rank card.
- Existing integration proof: all twenty checks pass, including account linking,
  scoped reads, mutation authority, sale/favorite behavior, records, replay,
  cooldown, reconnect and service revocation. First-cast assertions now validate
  every pull and tolerate the Common Twig's actual bonus-pull chance.
- Trader boundary proof: sequential licences, optional stronger rods, seven-biome
  travel, exact wallet costs, replay, conflicting confirmations, stale prices,
  expiry, privacy and one-time grandfather migration. Equipped XP applies once
  to the combined pulls, and native purchase recovery survives reconnect.
- scripts/crafting-proof.ts builds a separate fixture module with owner-only
  controls in scripts/fixtures/crafting-boundary.rs; these controls are absent
  from production WASM. It checks all six recipes, independent rod qualities,
  guaranteed success, atomic spending, maximum quality, twenty two-pull casts,
  resource bait once per cast, all luck baits, one charge per cast, cooldown
  rejection, replay after gear/bait changes, depletion, XP rounding, saved fish
  IDs, mixed categories, stale/expired quotes, disappearing materials, competing
  browser/Discord confirmations, metadata-only activation and private-view denial.
  Native client proof additionally purchases/equips bait, crafts quality and
  reconnects to recover the same saved rewards without spending twice.
- Browser QA used the actual Gear and Trader components with a clearly labelled
  synthetic account. Six quality upgrades, individual rod permanence, combined
  bait luck, depletion, repeated pack purchases and exact purchase previews pass.
  Desktop 1440px and phones 390/320px have no horizontal overflow. Gear remains
  above Junk & Materials. Screenshots are output/playwright/crafting-gear-*.png
  and crafting-trader-*.png; inspected desktop and narrow-phone renderings.
- Public site QA verified all three Trader shelves, five baits across responsive
  pages, guest purchase gating, artwork loading, updated World/Compendium rules
  and 1440/390/320px layouts. Screenshots are crafting-public-*.png. All eleven
  licence/bait PNGs match local SHA-256 hashes and retain RGBA encoding.
- Svelte check has zero errors/warnings; production build, native workspace checks,
  formatting and diff whitespace checks pass. The rebuilt bot reaches Gateway
  readiness; account linker readiness is HTTP 200. Public WebSocket fresh/reconnect
  subscriptions expose the catalogs and no anonymous bait/loadout/upgrade state.
- Discord API confirms eleven global commands, including /bait [bait_id] and
  /upgrade [rod_id], with zero direct duplicate copies in both installed servers.

Live Discord button delivery and a two-card response have not been invoked with
an actual player. Those handlers compile, native transport is tested against the
isolated server, and the real card renderer passes; tests sent no Discord messages.
Real-player pacing and long-duration retention/restoration remain staging work.

## 2026-10-05: sales, standings, public anglers and cosmetic achievements

- `npm run test:integration`: all 20 trust-boundary checks pass, including
  native reconnect, daily rewards, inventory sales, public record transfers,
  scoped views, unlinking and revoked services.
- `npx tsx scripts/social-proof.ts`: separate fixture WASM and fresh loopback
  database verify exact sale quotes, confirm/replay, replay after quote expiry,
  wrong account/nonce, duplicate and oversized batches, favorites at prepare and
  commit, expired and changed prices, and overlapping website/Discord sales.
  Exactly one overlapping sale succeeds and pays once.
- The social proof exercises all 18 badges, automatic first-cast unlocks,
  retroactive lifetime history including sold fish, independent bonus badges,
  permanent awards and original timestamps, cosmetic-only activation/backfill,
  earned-title selection and clearing, anonymous privacy, raw-private-table
  denial, unlink and service revocation. Native sale transport also commits and
  recovers the same confirmation after reconnect without a second payment.
- `npx tsx scripts/crafting-proof.ts` passes again: recipes, mixed pulls, bait
  charges, XP rounding, ownership, replay and browser/Discord crafting races.
  `cargo test -p discord-bot --locked` passes all five tests, including input
  validation and rendering every supplied sprite on every rarity card.
- Actual Achievements/Anglers components were tested with a labelled synthetic
  account at 1440px, 390px and 320px. Title select/clear/reactivity, name/game-ID
  search, paging, bonus unlocks and private-data omission pass without horizontal
  overflow. Inspected screenshots: `output/playwright/social-achievements-*.png`
  and `social-profile-*.png`. No synthetic account was added to the live database.
- Svelte check has zero errors/warnings and production builds pass. Additive
  publication created only new cosmetic/sale tables and scoped views. Cosmetic
  backfill credited the six existing players. All fourteen gameplay tables match
  their pre-update schemas and rows exactly, including wallet, XP, inventory,
  equipment, materials, collection, records, daily state and receipts.
- The rebuilt bot reaches Gateway readiness. Discord API confirms thirteen
  global handlers, including `/sell`, `/leaderboard` and `/profile [player_id]`,
  with no direct duplicate commands in either installed guild. The real public
  OAuth callback remains registered.
- Public WebSocket fresh/reconnect checks expose 251 fish, seven biomes,
  seventeen trader offers, eighteen badges and public profiles while anonymous
  private progress, ownership, inventory and sale quotes remain empty. Unrelated
  database routes return 404 and unrelated OAuth Origins return 403.
- Live public browser QA verifies retroactive badge display, clickable record
  holders and shareable game-ID profile links without document reloads, retained
  Records/Anglers filters when switching tabs, guest achievement gating, and
  public profiles at 1440/390/320px without horizontal overflow. Screenshots are
  `output/playwright/social-public-profile-*.png`. The exact restart launcher
  succeeds; the public homepage and local account-link readiness both return 200.

Actual Discord command/confirmation delivery and real OAuth consent/callback
remain unverified in this pass: no signed-in Discord browser session was
available. A real-player login/cast/site-update check was requested from the
owner. Isolated protocol/native proofs and registration checks do not replace
that acceptance test. No test sent messages to Discord or spent real players'
coins or fish.

## Discord response performance (2026-10-08)

- Local launchers now run `target/release/discord-bot.exe`; rebuild with
  `cargo build -p discord-bot --release --locked` after bot/client changes.
- Bot attachments are 512 × 768 PNGs. Source artwork and full-resolution preview
  helpers remain unchanged. Pixel comparisons verify the attachment matches
  nearest-neighbor scaling of the original composed card, including the frame.
- Artwork cache: at most 64 entries and 32 MiB, least recently used eviction,
  separate species/rank/material keys, and no cached failures. Tests verify
  memory/entry limits, reuse, distinct keys, and worker permit cleanup.
- Credential-free optimized benchmark: rusted tin 530,351 bytes / 66.60 ms cold,
  scrap 527,137 bytes / 62.35 ms cold, minnow F 447,001 bytes / 42.63 ms cold,
  ancient sturgeon UUR 681,438 bytes / 48.06 ms cold. Cached copies took
  0.02–0.03 ms. The prior two-material debug benchmark took about 2,413 ms and
  produced 1,822,392- and 1,811,958-byte uploads: about 71% fewer bytes now.
- Original layout tests, attachment/cache tests, and strict bot/client Clippy
  checks pass. Optimized salvage/F/UUR previews were visually inspected.
- Timing logs cover acknowledgement, game requests, reply delivery, handler
  completion/failure, rendering/cache hits, database selection waits and request
  round trips. These local rendering numbers are not end-to-end Discord timings.
  Real `/fish` and `/daily` usage will populate delivery measurements; this pass
  sends no test messages and claims no player rewards.

## Size-based service log rotation (2026-10-08)

- A managed Node log writer caps each active stdout/stderr file at exactly
  1,000,000,000 bytes, rotates before additional bytes would exceed the limit,
  and appends across restarts. The launcher continues tracking the actual
  service PID, with a separate runner PID for pipe draining.
- Closed logs compress in background Python processes using XZ preset
  `9 | PRESET_EXTREME` and CRC64. Every decompressed byte is compared with the
  closed source before it is removed. OS file locks prevent concurrent archive
  jobs racing; failed/interrupted jobs retain the source for retry on startup.
- Three isolated Node tests verify exact limits with large UTF-8 writes,
  byte-identical archive recovery, compression failure preservation and retry,
  restart appending, and stdout/stderr draining with the actual child PID.
  Fixtures use 1,024-byte limits rather than allocating 1 GB test files.
- The exact Restart launcher starts the optimized bot through the runner,
  preserves previous log contents, reaches Discord readiness, and returns
  HTTP 200 for both the public site and account-link readiness endpoint.

## Public rod showcase (2026-10-08)

- `/rod [rod_id]` defaults to the caller's equipped rod and permits only an
  owned rod when an ID is supplied. It renders current quality and effective
  power, bonus-pull chance, luck and catch-XP bonuses in a public reply, without
  changing equipment or granting/spending resources.
- Built-in imagegen created `assets/item-cards/rod.png`; the exact prompt is in
  `docs/rod-card-art-prompt.json`. Existing rod sprites are alpha-cropped and
  fitted into the centered display area with quality-colored accents. Source
  files stay unchanged. Attachments use 512 × 768 PNGs and the bounded cache.
- Twelve bot tests and strict Clippy pass. Coverage checks placement/aspect for
  all seven rods, all seven Twig Rod quality variants, preserved header artwork,
  separate quality cache keys, invalid paths/qualities, and original catch cards.
  Common/Prismatic Twig and Abyssal previews were visually inspected.
- The optimized bot was rebuilt and restarted through the managed log runner.
  Discord's API confirms fourteen global slash commands and `/rod` with an
  optional integer `rod_id`. Gateway, public site, and linker readiness pass.
  No test command was sent to a channel in this pass; the owner can now use
  `/rod` for a live delivery check.

## Level 120 and XP slime bait (2026-10-08)

- Rules version 7 raises the cap to 120 with the existing quadratic XP curve;
  thresholds through level 120 and the u64 maximum pass pure-rule tests.
- Eight bait types and twenty trader offers validate. Green/yellow/pink XP
  slime grant +25/+50/+100% additive fishing XP, cost 50/150/400 coins for ten
  uses, and use IDs 6/7/8 (shop IDs 106/107/108).
- Built-in imagegen produced three real RGBA sprites in `assets/baits/`;
  dimensions are 1254 square and alpha spans 0–255. Prompts are saved in
  `docs/xp-slime-art-prompts.json` and the manifest records hashes.
- Thirty-two Rust bot/rules tests, strict Clippy, zero-error/zero-warning
  Svelte checks, content validation and the production frontend build pass.
  The general isolated integration suite passes all twenty checks.
- The extended crafting proof buys all three slime types, forces two pulls
  through fish/junk/treasure, verifies exact additive XP and per-pull sums,
  consumes one use per cast, preserves resources on replay and cooldown
  rejection, and automatically unequips each slime after ten casts. Existing
  quality, resource bait, privacy and native transport checks also pass.
- The old production WASM was published to an isolated database and upgraded
  with the appended default-zero XP column. Owner activation and repeated
  activation pass; anonymous activation is rejected. All forty gameplay tables
  outside config/bait/shop/public-profile metadata stay byte-for-byte identical.
- Live services were stopped around deployment. Before/after snapshots of all
  forty-four nonscheduled tables verify the same forty-table preservation.
  Original config rows remain, rules version 7 has cap 120, and metadata plus
  computed public profile levels match the content. No reset was performed.
- Actual component fixtures verify reactive bait/combined XP and level-120
  progress at desktop and 390/320 phone widths. Screenshots were inspected.
  Anonymous public-site QA loads all three server-backed slime sprites and
  verifies four-item phone pagination without overflow. Screenshots are in
  `output/playwright/xp-slime-*`. No real player purchases or casts were used.
- Updated native bot and linker were rebuilt, desktop Start reaches Discord
  readiness, both public website and linker readiness return HTTP 200, and
  Discord still reports fourteen globally registered commands.

## Favorite saving and refresh persistence (2026-10-09)

- A single original star click persisted in the isolated browser, but rapid
  unfavorite/favorite clicks reproduced `ACTION_RATE_LIMITED`: favorites used
  the two-step sale-preview flow and inherited its one-second limit.
- The website now calls `set_catch_favorite` with an explicit desired state.
  The reducer authenticates the linked browser, checks ownership, and saves
  the catch in one transaction. Repeated identical requests are idempotent.
  Old favorite quotes remain supported for compatibility; sales still require
  a preview/confirmation and recheck favorite protection when committed.
- The twenty-check integration suite passes, including repeated direct saves,
  fresh authenticated reconnect persistence, unauthorized/missing/other-owner
  catches, unchanged player and catch measurements, and protected sales.
- Playwright on the real web app against an isolated proof database passes
  five consecutive star toggles, refresh, Camp/Tackle Box view changes, full
  return navigation, disabled sale selection, mobile rendering and persistent
  unfavoriting. Screenshots are `output/playwright/favorite-persisted-*.png`.
- Svelte reports zero errors/warnings and the production build passes. Both
  binding sets were regenerated. Live publishing adds only a reducer, with no
  table migration and `--delete-data=never`. All forty-four nonscheduled live
  tables match the predeployment snapshot exactly; no real catch was changed
  for testing. Services restarted, the public compiled client uses the direct
  save, and Discord/site/linker readiness pass.

## Permanent sortable journal (2026-10-09)

- A private `journal_entry` snapshot is saved for every authoritative pull after
  XP allocation. Sales, recent-result trimming, and receipt pruning leave it
  intact. Replayed casts do not add entries. Both generated binding sets were
  refreshed; Rust workspace checking and rebuilt bot/linker executables pass.
- The existing twenty-check integration suite passes with journal visibility
  and browser-revocation assertions. The dedicated journal proof passes legacy
  overlap reconciliation, identical double pulls, unknown fields, aggregate XP
  safety, idempotent owner import, ten sort directions, filters, page bounds,
  sales, reconnect, and unlinking. Its 112 permanent entries remain after the
  recent list trims to 100 and the real scheduler prunes aged retry receipts.
- The migration proof publishes the previous production WASM, records a real
  cast, links a browser, upgrades additively, and imports twice. All forty-four
  pre-existing tables stay unchanged.
- Playwright against the actual app and isolated proof backend passes desktop
  twelve-entry and phone four-entry pages, next/previous navigation, filters,
  ascending/descending weight order, rank order, missing-XP labels, no-results
  reset, refresh persistence, artwork loading, and no horizontal overflow.
  Visually reviewed screenshots: `output/playwright/journal-desktop.png` and
  `output/playwright/journal-mobile.png`.
- Svelte reports zero errors and warnings; production web build passes. Live
  publishing adds three private tables and the caller-scoped `my_journal` view
  with `--delete-data=never`. The owner import recovers 174 permanent entries
  across eight players. All forty-four existing live tables match the saved
  predeployment snapshot exactly after import.
- Public site and compiled journal client return HTTP 200; linker readiness
  returns HTTP 200; the restarted Discord adapter reports ready. The isolated
  browser and QA server were closed. See journal.md for retention/import details.

## Compendium pages and fish field notes (2026-10-09)

- The public Compendium now shows 24 fish per page on desktop and phones;
  name/biome/rank filtering and pagination still work. Opening a card reveals
  reference length/weight, actual catchable ranges, per-rank distribution bars,
  length/weight envelopes, base cast odds, and the species-relative rank rules.
  Measurement display uses inches, pounds, and ounces. Fihs and the Sock retain
  their UUR-only and F-only detail rows.
- `npx tsx scripts/compendium-proof.ts` passes all 2,492 rank ranges against the
  real Rust sampler at exact minimum/maximum endpoints, plus 498,400 sampled
  catches. Conditional rank percentages total 100%; approved exceptional cast
  probabilities and all seven normalized biome pools match.
- Playwright passes keyboard card opening, native modal focus containment and
  restoration, background scroll locking/restoration, Escape, close button,
  backdrop dismissal, tab-change dismissal, retained filters/pagination, and
  phone viewport fit. Desktop/mobile field notes and rank rows were visually
  reviewed in `output/playwright/compendium-*.png`.
- Svelte reports zero errors and warnings; production web build passes. Only
  the web service restarted; the running bot and linker were retained. Public
  `/catalog` and its compiled field-notes client return HTTP 200, SSR renders
  24 cards, and opening Ancient Sturgeon on the live site shows the new dialog
  with the verified US measurements. Isolated QA browser/server were closed.
- See compendium.md for the distinction between conditional rank share and
  at-least-one-per-cast probability, and for exact measurement derivation.

## Biome collection achievements (2026-10-09)

- Expanded the catalog from 18 to 113 cosmetic badges: 111 ordinary badges
  and two optional bonus discoveries. The existing Pond badge and title keep
  their ID. Collection criteria cover every ordinary species, each of ten
  ranks, and all ten ranks together, in every biome and across all biomes.
  The final world badge requires 2,490 distinct species/rank pairs.
- `biome-achievements-proof.ts` passes catalog-derived targets, duplicate
  catches, partial and cross-biome boundaries, rank separation, a real
  last-species F cast awarding both badges immediately, idempotent cast replay,
  all 111 ordinary awards without bonus fish, lifetime credit with no specimens
  kept, unchanged player/inventory/history on backfill, new titles and private
  progress/owner access boundaries.
- `biome-achievements-migration-proof.ts` passes an additive upgrade from the
  previous production WASM, preserving all 44 gameplay tables, legacy badge
  names/titles/targets, and original earned dates. Repeated backfill is safe.
  The production migration granted lifetime credit for all eight players and
  passed the same preservation checks. Proof-only fixture controls are absent
  from the deployed WASM.
- The 20-check integration suite and social/native adapter proof pass,
  including sales, title validation, private views, reconnects and revocation.
  Bindings regenerate successfully; Svelte reports zero errors/warnings;
  production web and both native service builds pass. The web build retains
  its existing bundle-size advisory.
- Playwright passes desktop and phone biome/rank/status filters, correct
  species and pair targets, empty incompatible scopes, filter page reset,
  desktop 12-card and phone four-card pages, and title persistence on refresh.
  Screenshots under `output/playwright/biome-achievements-*.png` were reviewed.
- All services restarted successfully. The bot logged readiness; public
  catalog and compiled achievement UI return HTTP 200, linker readiness
  returns HTTP 200, and a fresh anonymous live subscription confirms the
  113-badge/96-collection catalog while unfinished player progress stays private.
  The isolated browser and QA server were closed.

## Generated achievement art: first style batch (2026-10-09)

- Built-in image_gen produced three individual, transparent pixel-art medals:
  Pond collection (10), Meadow Pond UUR collection (109), and the lost Sock (18).
  The first Pond medal was the style/layout reference for the other two. The
  complete 113-asset prompt plan is saved in content/achievement-badge-art.json;
  this sample batch is complete and the remaining 110 images are planned.
- Originals were copied non-destructively into assets/achievements. All three
  are square RGBA PNGs with alpha ranging from zero to 255 and complete opaque
  subjects inside their canvas margins. Their names, dimensions, byte sizes and
  SHA-256 hashes are included in the shared asset manifest.
- The badge book displays generated artwork by stable achievement ID, retains
  existing symbols for other badges, preserves full colors on earned medals,
  and fades locked medals through CSS. No server, achievement or balance rules
  changed. Badge names and ranks remain website text rather than image lettering.
- Playwright passes all three asset loads, unclipped artwork, earned/locked
  styling, filters and phone viewport fit. Desktop/phone screenshots in
  output/playwright/generated-badge-*.png were reviewed. Svelte reports zero
  errors/warnings and the production build passes.
- Only the web process restarted; bot and linker stayed running. The installed
  PNGs return HTTPS 200 and exactly match source hashes, checked with
  `node scripts/achievement-badge-proof.mjs --live`. Public catalog, compiled
  achievement UI and linker readiness return HTTP 200. QA services were closed.

## Generated achievement art: complete medal set (2026-10-09)

- Finished all 113 individual achievement images with the built-in image_gen
  tool: 17 milestone/bonus medals, seven biome families of 12 medals each, and
  12 all-waters medals. The three approved samples were retained. The remaining
  110 generations used the Pond medal as their style/layout reference; Fihs
  additionally referenced its supplied blue/pink/gold fish sprite. All nine
  labelled contact sheets in output/playwright/badge-batch-*.png were reviewed.
- Selected images retain their original generated pixels, dimensions and alpha;
  originals remain in the Codex generated-images directory. The exact prompt
  set is content/achievement-badge-art.json, with local per-generation provenance
  under .local/achievement-badge-generation. No generation failed or remains
  queued. All 113 PNGs have unique source hashes and stable achievement IDs.
- `python scripts/validate-achievement-badge-alpha.py --complete` passes actual
  transparency and complete opaque-subject margins for every square RGBA PNG.
  `node scripts/achievement-badge-proof.mjs --complete --live` passes complete
  manifest coverage, source hashes, HTTP 200 delivery and matching public hashes
  for all 113 images at https://fish.ichaa.dev.
- Playwright traverses all ten desktop pages and verifies every distinct badge
  image loads. Every-rank, biome and bonus filters pass, including locked bonus
  grayscale styling. Phone pagination shows four cards, resets correctly after
  filter changes, and has no horizontal overflow. The check scrolls cards into
  view before awaiting lazily loaded images. Desktop and phone screenshots in
  output/playwright/badge-book-complete-*.png were reviewed.
- Svelte reports zero errors/warnings and the production build passes, retaining
  the existing bundle-size advisory. Only the web service restarted; the bot
  and linker stayed running. Public catalog and the new compiled achievement
  client return HTTP 200, and linker readiness returns HTTP 200. A fresh anonymous
  live subscription confirms the 113-badge/96-collection catalog and 2,490-pair
  world target while unfinished player progress remains private. Both isolated
  QA servers and browser sessions were closed. No gameplay rules changed.

## Discord achievement announcements and badge zoom (2026-10-09)

- Added a read-only native client stream for newly committed earned-badge
  inserts. Both the adapter's own reducers and other clients' transactions emit
  award details; initial subscriptions and reconnect history do not. No database
  schema, reducer, award condition, fishing odds or gameplay data changed.
- All 14 Discord library tests pass, including exact achievement embed data,
  badge URL, disabled mentions, enforced retry nonce, latest-channel routing,
  saved history on restart, database scope and cross-player routing isolation.
  `cargo check --workspace --locked` and the optimized bot build pass.
- The isolated social/native proof passes real first-cast unlock events, exact
  names/descriptions and Discord identities, receipt replay suppression,
  reconnect history suppression, and external transaction awards for a player
  outside the currently selected private view. Existing sale, title, public
  profile privacy, account unlink and service-revocation checks also pass.
  Proof-only fixture controls were never published to the live database.
- Playwright passes desktop/phone badge enlargement, exact requirement text,
  earned/locked previews, keyboard activation, Escape with restored focus,
  close-button and backdrop dismissal, viewport fit, native dialog modality and
  restored background scrolling. Reviewed screenshots are
  output/playwright/badge-preview-pond-desktop.png,
  badge-preview-fihs-desktop.png and badge-preview-world-mobile.png. A separate
  traversal still loads all 113 images and passes filters and pagination.
- Svelte reports zero errors/warnings and the production web build passes,
  retaining its existing bundle-size advisory. Web and bot were restarted;
  the linker and database stayed running. The bot logged fresh readiness.
  Eight existing channel routes were seeded from retained casts with read-only
  owner SQL, preserving exact 64-bit Discord IDs. Public production assets
  contain the clickable badge and preview dialog; catalog, client and linker
  readiness return HTTP 200. No test messages were posted to Discord.
