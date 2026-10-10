# Equipped rods and tackle-box gear

Gameplay rules version 7 includes bait, rod quality, mixed pulls and level 120;
species/content is version 5 and physical rank thresholds remain version 4.
Each new rod improves all three stats. [XP slime rules](xp-progression.md) add
three purchasable baits with +25%, +50% and +100% fishing XP.

| Rod | Power | Luck | Fishing XP | Level |
|---|---:|---:|---:|---:|
| Twig | 1 | +0% | +0% | 1 |
| River | 10 | +5% | +5% | 5 |
| Marsh | 20 | +10% | +8% | 10 |
| Moonwood | 35 | +15% | +12% | 18 |
| Tide | 50 | +20% | +16% | 28 |
| Glacial | 65 | +25% | +20% | 40 |
| Abyssal | 85 | +30% | +25% | 55 |

Only the equipped, owned rod applies. Each owned rod keeps a separate permanent
quality, from Common through Prismatic. Quality bonuses are added to the family
base; older quality rows do not stack. Full recipes and bonuses are in
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
call owner-only `activate_crafting`. It seeds metadata, inserts rules version 6,
and clears old power gates; it does not grant, spend or rewrite player resources,
owned quality, catches, records or receipts. Existing historical receipts retain
their versions. Regenerate Rust/TypeScript bindings and rebuild clients before
restarting services. Earlier `activate_rod_bonuses` remains for historical version-5
setup; use `activate_crafting` for the current rules.
