# Level 120 and experience slime bait

Gameplay rules 10 tune baseline levelling for 25–50 casts per day. Keep lifetime
XP as the source of truth: existing players retain all XP, catches, coins,
equipment and bait stock, and their displayed level can increase on activation.
The cap remains 120 and the first ten level costs are unchanged.

XP needed from level `L` to `L+1`:

```text
L <= 10: 80 + 25*L + 5*L*L
L > 10:  830 + 35*(L - 10)
```

Level 120 now requires **304,395 total XP**, down from 3,032,120. The curve stays
strictly increasing; level 10 costs 830 and level 11 costs 865. Cast reward XP,
slime bonuses, rod bonuses, fish values and material recipes are unchanged.
Licence level requirements remain 1/5/10/18/28/40/55. Rods remain gold purchases
with the previous licence required.

Fresh-start natural-drop simulations put level 55 at about **33 / 16 days** and
level 120 at **89 / 45 days**, at 25 / 50 casts daily. Pink slime reduces the
level-120 medians to about **69 / 35 days**, including its actual gold cost.
These assume immediate fish sales and sequential gear/licence purchases, not
observed player frequencies. The working cap target is roughly 90 fishing days
at 25/day. [Full audit and slime comparison](xp-short-session-audit.md).

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
The owner-only, idempotent `activate_xp_progression` reducer seeds the current bait catalog
definitions, rod bases/bonuses, qualities, 42 family recipes, and 25 shop offers, inserts
current rules version 11 with cap 120 (the same rules-10 XP curve), and
refreshes public profile levels from stored XP. It never changes player XP,
wallets, bait stacks, owned rod quality levels, catches, history, records or cooldowns.

Rules 10 add no table or column. Server gates and client displays use the
subscribed rules version: publishing the module alone leaves rules-9 levels in
place until owner activation. Use `scripts/xp-curve-proof.ts` with a saved rules-9
crafting fixture to verify high-XP retention, old gates before activation, new
levels, owner authorization and idempotence. Use
`scripts/xp-preservation.mjs before|after` to compare the live table snapshot.
The extended crafting proof checks all three prices, additive XP across two
pulls and every reward category, one-use charging, replay, cooldown rejection,
depletion and unauthorized activation.
