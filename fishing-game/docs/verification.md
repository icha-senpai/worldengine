# Implementation verification — October 3–4, 2026

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
