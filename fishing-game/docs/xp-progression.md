# Level 120 and experience slime bait

Gameplay rules version 7 raises the angler cap from 60 to 120. Lifetime XP stays
the source of truth; existing players retain their XP, catches, coins, equipment
and bait stock. The curve remains `80 + 25*level + 5*level*level` XP per level.
Level 120 requires 3,032,120 total XP. XP earned while previously capped counts
immediately toward the expanded progression. Existing biome and rod gates stay
at their current levels.

All three slime baits are available at the Camp Trader from level 1. Each pack
contains ten uses, and repeated purchases add uses to the same bait stack.

| Bait | Bait ID | Shop item ID | Fishing XP | Pack price |
|---|---:|---:|---:|---:|
| Green XP Slime | 6 | 106 | +25% | 50 coins |
| Yellow XP Slime | 7 | 107 | +50% | 150 coins |
| Pink XP Slime | 8 | 108 | +100% | 400 coins |

These starting prices are tunable. They offer a choice alongside resource and
luck bait: only one bait type can occupy the slot, and slime grants no extra
luck, power or guaranteed material. All eight baits work in every licensed biome.

XP bonuses add: rod family + permanent quality + equipped slime. Apply the
combined percentage once to the complete cast's base XP, including discoveries,
fish, junk, treasure and both pulls, then round down. Distribute saved per-pull
XP so it sums exactly to the cast reward. Maximum Prismatic Abyssal plus pink
slime grants +200% XP (3x base XP). Daily rewards and sales receive no slime bonus.

One accepted cast consumes one bait use even with two pulls. Rejected casts and
receipt replays consume nothing; depletion unequips bait automatically. Saved
equipment receipts retain the combined XP bonus for the original cast.

## Assets

Transparent pixel art generated with the built-in imagegen tool is saved in
`assets/baits/green-slime.png`, `yellow-slime.png` and `pink-slime.png`. Exact
prompts and mode are recorded in [xp-slime-art-prompts.json](xp-slime-art-prompts.json).
Both the Trader and Tackle Box consume the real sprites and server metadata.

## Additive activation

Append `bait_definition.xp_bonus_bp` with default zero, regenerate both bindings,
rebuild native services, and publish with `--delete-data=never`. SpaceTimeDB
[supports appended columns with defaults](https://spacetimedb.com/docs/tables/default-values/).
The owner-only, idempotent `activate_xp_progression` reducer seeds eight bait
definitions and twenty shop offers, inserts rules version 7 with cap 120, and
refreshes public profile levels from stored XP. It never changes player XP,
wallets, bait stacks, quality, catches, history, records or cooldowns.

Use `scripts/xp-migration-proof.ts` with the saved old WASM to verify migration,
and `scripts/xp-preservation.mjs before|after` to compare the live table snapshot.
The extended crafting proof checks all three prices, additive XP across two
pulls and every reward category, one-use charging, replay, cooldown rejection,
depletion and unauthorized activation.
