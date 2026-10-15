# Stories from the dock

Quests live in the authoritative module and are managed in the website Quests tab.
There is no accept button: lifetime catches, ordinary discoveries,
recovered junk, rod quality, best lengths and rank histories count automatically.
Claiming a story step unlocks the next conversation. Permanent stories never
expire, and selling fish does not erase their progress.

The website puts the daily supply parcel and weekly contracts first under
**Dockside Delivery**, followed by unlimited Dockside Jobs. Story names open compact accordion cards; each part opens
separately inside its story for dialogue, objectives, rewards and actions. One
chain and one part per chain can stay open at a time, keeping mobile scrolling
short. Collapsing a part preserves its current bait and fish selections.

## Permanent stories

Mira's **Making Yourself Useful** introduces three catches, three discoveries,
automatic material recovery from junk, a rod upgrade, and a confirmed pond-fish
delivery. It awards 350 gold, five rusted tin, three scrap, four bait packs in
total, and the **Dock Regular** badge/title.

Rowan's **The One That Got Away** follows the introduction. Discover five river
species, catch a fish longer than that species' typical length, deliver a common
river specimen, and land an S-or-better river fish. Historical progress counts;
the finale has no time limit. Rewards total 650 gold, ten tin, five scrap, three
bait packs, and the **River Storyteller** badge/title.

The narrative/reward catalog is `content/quests.json`. IDs remain stable across
releases. Choose a listed bait when offered; quantities are whole ten-use packs,
not individual casts. XP comes from chosen slime during fishing, not direct quest
XP. The two original stories award 1,000 gold together; all story rewards are one-time.
Badge titles and artwork motifs are cataloged in `content/quest-awards.json`.

## Additional stories

All seven branch from Mira's final step (#105); no river finale is required.
Regional catches naturally require access to their water, but there is no added
quest level gate. Historical discoveries, ranks, rod quality and recorded rank
maxima count. Junk objectives explicitly span every water.

| Chain | Parts | Giver | Final title |
|---|---:|---|---|
| Something in the Reeds | 4 | Nessa, marsh keeper | Reed Whisperer |
| Reflections After Dark | 4 | Elowen, lakeside archivist | Moonlit Chronicler |
| What the Tide Returned | 5 | Bram, beachcomber | Tidekeeper |
| Beneath the Ice | 4 | Tavi, icewater researcher | Icebound Angler |
| Letters from Below | 4 | Orin, harbor postmaster | Deepwater Correspondent |
| The Tinkerer's Trouble | 4 | Pip, dockside tinkerer | Dockside Tinkerer |
| A Collector's Eye | 4 | Sera, collection curator | Keen Eye |

Together with the original stories there are **38 permanent steps across nine
chains**, awarding 4,200 gold, 40 tin, 23 scrap and 21 bait packs in total. Regional finales use S-or-better catches, never a required perfect or
bonus species. Lake objectives do not require a real-world time of day. Pip's
workshop follows Uncommon, Rare and Epic rod quality. Sera asks for ten species,
five different ranks, eight different ranks, then any ordinary species' recorded
rank maximum. That final stretch step rewards only the badge/title and gates
nothing else; an F-rank maximum qualifies too.

## Unlimited Dockside Jobs

| Job | Fresh progress required per run | Reward |
|---|---|---|
| Another Basketful | 10 fish | 100 gold |
| Workshop Supply Run | 5 junk catches | 3 rusted tin and 1 scrap |
| Fresh Variety | 5 different ordinary species | Choose 1 Sticky Chum or Green XP Slime pack |

No daily/weekly completion limit, entry cost, acceptance step, timer or cooldown.
All three jobs track automatically in parallel from their introduction. Claiming
one job resets only its counter and species set; every new run requires fresh
progress after that claim. Species can qualify again on subsequent variety runs.
Progress saturates at the objective, so unattended extra catches do not bank
future claims. Bonus pulls count as catches. There is no specimen consumption.

Each player's job stores one bounded current-run row, not an expanding list of
completions. A server-issued cycle is captured at confirmation: concurrent claims
or an old web/Discord preview cannot claim the following run. Invalid reward
choices roll back both payment and reset. Existing lifetime catches never fill
new jobs retroactively, and weekly jobs remain independent.

The gold job adds 10 gold per qualifying fish. The variety pack is worth at most
50 shop gold per five distinct catches (at most 10 per qualifying fish); together
these add at most 20 gold of coin/bait value per fish before claim rounding.
Material jobs add 0.6 tin and 0.2 scrap per qualifying junk catch. These are
supplementary rewards; fish sale prices and bait costs are unchanged. With no
bonus pulls and Meadow Pond's 86% fish rate, 25/50 casts give an expected 21.5/43
fish, about 2.15/4.3 gold-job runs of progress. Actual claims depend on catches
and visits to the board, and variety usually takes more than five pulls.

## Dockside contracts

The same board retains the existing daily parcel: 100 gold per UTC day, with
250 extra on every seventh claimed day. Website and Discord `/daily` share the
same eligibility and stamp card. Missing a day never resets stamps.

Three optional contracts track in parallel each UTC week:

| Contract | Objective | Reward |
|---|---|---|
| Fish for the Crew | Catch 15 fish | 400 gold |
| Useful Scraps | Recover materials from 3 junk catches | 10 tin and 5 scrap |
| A Varied Haul | Catch 5 distinct ordinary species | Choose 2 Salvage Chum or Yellow XP Slime packs |

Claim **one** contract reward per week. Players can freely change their intended
choice before claiming; there is no accept cost, reroll fee, or streak. Contracts
never consume caught fish. Weeks start Monday at 00:00 UTC. New catches after
quest deployment count, including bonus pulls; old weekly history is not inferred
from incomplete receipts. The current-week row is replaced in place on the next
week's activity, keeping storage bounded.

At 25 casts/day, even Meadow Pond's unboosted 12% junk chance makes three junk
pulls over a week more than 99% likely. Fifteen fish fits a normal short session;
the weekly gold reward averages at most 57 gold/day and does not replace sales or
daily parcels. River rank milestones stay permanent rather than becoming chores.

## Explicit deliveries

Each delivery offers the three highest-encounter-weight ordinary species in its
water (species ID breaks ties). Choose one owned F/D/C specimen. The website
shows the exact catch, species, rank, measurements and reward before
confirmation. Favorites and another player's fish are rejected by the reducer.
The transaction removes exactly that fish and updates kept count; collection,
journal, records, rank maxima and perfect-species history remain intact. A failed
reward selection rolls the whole transaction back. A durable claim prevents
retries from consuming another fish or paying twice.

## Discord

Quest progress, reward choices and fish deliveries are managed on the website.
The retired `/quests` slash command is no longer registered. `/daily` remains
available for Dockside Delivery.

## Implementation and verification

Added tables: public `quest_definition` and `quest_calendar`, private
`quest_claim`, `weekly_quest_progress` and `repeat_quest_progress`. Caller-scoped `my_quests` and
`my_dockside` and service-scoped `adapter_quests` expose only authorized progress.
The calendar updates on actions and on the existing minute maintenance job;
reducers always check the actual current week, including stale previews.

Fresh modules seed the catalog. Existing modules need owner-only
`activate_quests` after a no-delete publish. Activation adds the definitions and
nine achievement entries without awarding anything or changing existing player
state. It is idempotent. Bindings and clients must be rebuilt from the new WASM.

`node scripts/quest-catalog.test.mjs` checks prerequisites, choices and reward
budgets. `npm run rust:script -- scripts/quests-proof.ts` publishes the previous deployed WASM
into a uniquely named loopback proof database, compares every retained table,
then exercises actual claims, ownership, favorites, rollback, weekly tracking, unlimited run resets, stale/concurrent claims, all story
branches and shared daily eligibility. Test-only setup reducers stay in the isolated fixture.
The runner deletes its database and temporary source; `--browser` retains a
temporary preview fixture for UI checks and must be cleaned up afterward.

The nine story medal PNGs are drawn from scratch by
`python scripts/build-quest-badges.py`; rebuild the asset manifest afterward.

## Live deployment

Deployed October 13, 2026 (America/Chicago): module, website, bot and account
linker match the generated contracts. Publication retained 8,363 exact rows
across all 57 pre-existing tables; activation added 44 quest definitions and nine
awards without changing balances, XP, specimens, earned progress or credentials.
Repeated activation was idempotent. Both native executables and all 546 installed
web files were hash-verified. Public HTTP/OAuth health, all nine quest badge
hashes, the public WebSocket subscription and 16 global Discord commands
(including `/quests`) passed. No Discord catch or message was sent as a test.

No deployment backup or dated archive was created. Preservation comparisons
stayed in process memory; the temporary staging build and verification scripts
were removed after deployment. Space and the shared database/tunnel processes
were left running.

## Control theme refinement

Quest objectives and claim confirmations say "this week"; UTC remains in reset
information only. The three catalog objective edits were published with the updated module and
applied through `activate_quests`.

Quests now use the shared parchment panel treatment and gold action buttons.
`apps/web/src/lib/game/theme.css` owns native input/select/textarea surfaces,
checkbox/radio states, focus/disabled treatment and native scrollbar colours.
Page components keep layout rules rather than duplicating control skins.
Dropdown pickers retain native mobile and keyboard behaviour. Text fields have
a minimum 16px font, and forced-colour mode restores native selection controls.
The control theme was checked in the quest board and Compendium at mobile and
desktop sizes, including keyboard selection, indeterminate checks and scrolling.
Deployed October 14, 2026 (America/Chicago). All 8,477 existing rows across 62
tables were preserved except for the three intended objective text changes.
Activation was idempotent, all 546 installed web files matched the candidate,
and public CSS hashes, mobile connection, OAuth health and Discord registration
passed. Temporary deployment scripts were removed; no backups were created.
