# Equipped rods and tackle-box gear

Gameplay rules version 10 includes bait, rod quality, mixed pulls and level 120;
species/content is version 5 and physical rank thresholds remain version 4.
Each new rod improves all three stats. [XP slime rules](xp-progression.md) add
three purchasable baits with +25%, +50% and +100% fishing XP.

| Rod | Power | Luck | Fishing XP | Gold | Previous licence |
|---|---:|---:|---:|---:|---|
| Twig | 1 | +0% | +0% | Free | Starter |
| River | 28 | +10.5% | +14.5% | 500 | Meadow Pond |
| Marsh | 55 | +21% | +29% | 15,000 | Whispering River |
| Moonwood | 82 | +31.5% | +43.5% | 16,000 | Hollow Marsh |
| Tide | 109 | +42% | +58% | 33,000 | Moonlit Lake |
| Glacial | 136 | +52.5% | +72.5% | 74,000 | None |
| Abyssal | 163 | +63% | +87% | 130,000 | None |

Rods have no angler-level purchase or equipment gate. They require gold and the
previous biome licence, except Glacial and Abyssal, which require coins only.
Biome licence levels remain 1, 5, 10, 18, 28, 40, and 55;
licences are bought in order, and any owned rod can fish licensed waters.
Purchases still start Common and never equip automatically. Rod ownership is
not an additional shop prerequisite, so players can skip families if their
licences and wallet permit; the pacing audit assumes sequential purchases.

The following rod-only audit records rules 9 before the subsequent
[XP tuning in rules 10](xp-short-session-audit.md); its exact timings are historical.

The October 11 rules-9 shorter-session tuning includes River and Marsh and
uses **25 and 50 casts per day** as the primary schedules; 100 is an upper-bound
sensitivity. River is a short starter purchase (median ten casts). From River
onward, the gold ladder aims for Mythic before replacing a rod, with Prismatic
material readiness near the next purchase. With natural drops and banking at
Mythic, 93.6–100% reached Mythic and 44.6–52.3% had the Prismatic recipe ready
across the River-to-Abyssal transitions at the primary schedules. These are
probabilities, not mandatory quality requirements or guaranteed deadlines.

Median cumulative casts to buy Abyssal are 1,441 at 25 casts/day (57.6 fishing-day
equivalents) and 1,463 at 50/day (29.3); the 90th-percentile waits are 62.6 and
31.8 days. Choosing Prismatic along the way changes those medians only slightly.
Natural drops suffice; material bait is optional. Skipping all daily deliveries
raises the natural-banking median to 1,483 casts, only 1.4% above the 50/day case.
Missed days extend elapsed calendar time without losing XP, quality or materials,
and delivery stamps do not reset. No requirement to fish for multiple hours per
day has been added. Ten casts/day is also reported and still takes about 138
days for the full ladder; this tuning does not make all play schedules equally
fast.

Inputs: fresh player, immediate fish sales, sequential licences and rods, daily
claims on played days, no gifts or other income/spending. These are simulated
strategies, not observed player frequencies. Full results, per-rod intervals,
recipe totals and reproduction commands are in the
[short-session audit](rod-short-session-audit.md).

Only the equipped, owned rod applies. Each owned rod keeps a separate permanent
quality, from Common through Prismatic. Quality bonuses are added to the family
base; older quality rows do not stack. Every new Common rod strictly exceeds
the previous Prismatic rod in power,
luck, and XP bonus. Family steps are +27 power, +10.5% luck, and +14.5% XP.
Quality adds up to 22 power, +7% luck, and +13% XP. Each family has its own
material recipes; a banked Prismatic recipe from River onward cannot fully fund
all upgrades to Mythic on the next family.
Full recipes and bonuses are in
[bait-and-crafting-plan.md](bait-and-crafting-plan.md) and `content/crafting.json`.

Power gives 0.5 percentage points of bonus-pull chance per point, with at most
one additional independent fish, junk or treasure pull. Prismatic Abyssal has
185 power, giving a 92.5% bonus-pull chance. Biome access requires angler level
and the sequential purchased licence; any owned rod can fish licensed waters.
Both pulls share one sixty-second cooldown and one bait charge.

Luck combines family, quality and equipped bait, up to +85%. It shifts the
physical size bands toward higher ranks. The saved rank still follows actual
species-relative length and weight. For base weights `w_i`, score `r_i` (F..UUR
ordinal 0..9, using 9 for the exceptional F-rank Sock), `T=sum(w_i)`,
`M=sum(w_i*r_i)`, `D=9*T-M`, and luck basis points `L`, use:

`w'_i = w_i * (10000*D + L*(r_i*T-M))`

The total stays `T*10000*D`. UUR and both exceptions gain exactly `1+L/10000`
times their base per-pull probability. Fihs is half the rarest ordinary UUR,
and the Sock half Fihs, per pull and expected catch counts. At-least-one-per-cast
probability is `(1+q)*p-q*p*p`, so two independent pulls slightly alter those
ratios for that distinct measure. With maximum gear, Fihs averages roughly
2,813 casts and the Sock 5,625; these are averages without a guarantee.

Fishing XP combines rod family, quality and slime bonuses additively, then applies once to the combined raw rewards, including first discoveries,
then rounds down. Per-pull saved XP shares sum to the cast total. Daily delivery
and sales receive no gear XP bonus. Saved pull and equipment receipts preserve
actual quality, bait effect and remaining uses; replay after changing gear returns
the original rewards without charging bait or applying bonuses again.

The Tackle box places illustrated rod and bait slots above Junk & Materials.
Owned rods show quality, total stats, next recipe and confirmed crafting. Bait
packs contain ten uses; repeated purchases accumulate uses, and empty bait
unequips automatically. Resource bait adds exactly one tin or scrap per cast,
on top of either pull's normal rewards. `/gear`, `/bait`, `/upgrade` and `/shop`
expose the same authoritative state on Discord.

## Assets and deployment

Seven original transparent pixel-art PNGs live in `assets/rods/`, served through
the existing asset root. They were generated using built-in `image_gen` and
copied into the project without changing their pixels or alpha. Exact prompts
are preserved in `docs/rod-art-prompts.json`; the asset manifest records hashes
and dimensions. `build-world-content.mjs` retains modifiers and image paths.

Six licence icons in `assets/licences/` and all eight bait icons in `assets/baits/`
appear in the real Trader and Tackle box. `content/equipment-art.json` maps their
visuals; prompts remain in `docs/licence-and-bait-art-prompts.json`.

Publish additive schema updates without resetting the player database, then
call owner-only `activate_xp_progression`. It refreshes rod base definitions,
rod bonuses, quality definitions and 42 rod recipes (version 3), bait and shop metadata, inserts
rules version 10, and refreshes profile levels from stored XP. It preserves
owned rod quality, XP, coins, materials, catches, records, cooldowns, and saved
receipts. The operation is idempotent; historical receipts keep their original
rule/equipment snapshots. This update adds the public `rod_upgrade_recipe` metadata table; regenerate
both client bindings and rebuild the website and native services before deployment. Publish with `--delete-data=never` and compare player
state using `scripts/xp-preservation.mjs before|after`.
