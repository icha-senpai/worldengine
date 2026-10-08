# Fish length and weight audit — October 8, 2026

The findings below describe catalog 4 before the correction. Catalog 5 resolves
the shared fallback with 251 explicit baselines; see
[current species measurements](species-measurements.md) for the replacement data
and safe activation procedure.

The runtime measurement math is consistent. The species definitions need a proper
content pass: 115 of 251 species currently use the generic
23 cm / 240 g fallback, and the catalog has only 29 unique baseline pairs.
This audit does not change gameplay, stored catches, rewards or database content.

## Scope and evidence

- Read every catalog baseline and compared it with the live species definitions;
  there were no mismatches.
- Checked all 56 currently owned fish against the current cubic
  length/weight curve, integer rounding and species weight bounds. None fell
  outside the expected range. All saved ranks matched the current relative
  length/weight thresholds.
- The median actual weight was 100.9% of the
  unmodified predicted weight. These catches show no systematic downward drift.
  The snapshot covers retained inventory, not every historical cast or an
  unbiased estimate of encounter probabilities.
- Seven focused measurement tests passed, including 100 generated specimens for
  each of all 2,492 supported species/rank combinations.
- Discord and the website correctly convert millimeters to centimeters by
  dividing by 10, and grams to kilograms by dividing by 1,000.

## Current formula

`weight = typicalWeight × (length / typicalLength)³ × condition`

Condition normally ranges from 0.88 to 1.12. The generator samples an integer
gram interval around that curve and raises its minimum to the selected band's
weight threshold when necessary. Whole-gram precision matters for tiny fish:
the percentage difference can exceed 12% even when the saved gram value is
correct. The minimum and maximum species weights also clamp the result.

At 67% of a species' typical length, the unmodified predicted weight is 30.1%
of its typical weight. Length and weight percentages should not match. A
half-length fish weighs approximately one eighth as much at the same condition.

FishBase describes species-specific relationships of the form W=aL^b, with
centimeters and grams. A cubic relationship is a reasonable game approximation;
using a single prototype baseline across unrelated species is the larger content
problem. [FishBase length-weight documentation](https://www.fishbase.se/manual/English/fishbasethe_length_weight_table.htm).

## Observed catch examples

| Species | Rank | Length | Saved weight | Predicted weight before condition |
| --- | --- | ---: | ---: | ---: |
| fathead-minnow | F | 6.7 cm | 5 g | 5.8 g |
| killifish | D | 17.5 cm | 114 g | 105.7 g |
| stickleback | B | 24.4 cm | 266 g | 286.5 g |
| blue-discus | F | 16.3 cm | 93 g | 85.4 g |
| stickleback | C | 21.9 cm | 195 g | 207.2 g |
| shiner | B | 9.1 cm | 14 g | 14.5 g |
| petal-koi | D | 32.3 cm | 734 g | 720.5 g |
| fathead-minnow | B | 9.3 cm | 14 g | 15.4 g |
| crucean-carp | B | 48.2 cm | 2262 g | 2394.3 g |
| killifish | C | 22.7 cm | 235 g | 230.7 g |

These are anonymous examples from retained live inventory. No player IDs or
Discord identities are included.

## Content findings

1. The generic fallback covers 115 entries, including tiny real fish,
   larger game fish, reef fish and fantasy creatures. Killifish, stickleback,
   tench, walleye, dorado, blobfish and Fihs all receive 23 cm / 240 g.
   This is a placeholder, not individually reviewed species data.
2. Name matching groups unlike fish together. Every non-overridden bass uses
   55 cm / 2.5 kg; trout, salmon and burbot share 45 cm / 1.2 kg; sturgeon and
   muskellunge share 150 cm / 18 kg. Fantasy variants often inherit exactly
   the ordinary family's baseline despite their distinctive art.
3. Eight starter species overwrite the family's defaults from
   `content/meadow-pond.json`. Recalibrating only the family builder will not
   update those species; both sources need deliberate handling.
4. Some real fish are too long rather than too light. The observed stickleback
   is 24.4 cm / 266 g, but FishBase reports common length 5.1 cm and maximum
   11 cm for the threespine species. The generic game name does not establish
   an exact biological species; this reference is a useful scale check.
   [FishBase threespine stickleback](https://www.fishbase.se/summary/2420).
5. Some larger fish may benefit from a modest weight correction. As a
   largemouth reference check, the game's baseline curve predicts 1.970,
   2.622 and 3.404 kg at 50.8, 55.88 and 60.96 cm (20, 22 and 24 inches).
   Texas Parks and Wildlife's average conversion table gives approximately
   2.123, 2.903 and 3.865 kg at those lengths: the game curve is about 7–12%
   lighter. This does not establish the proper adjustment for other bass
   species or other families.
   [Texas Parks and Wildlife largemouth length-weight table](https://tpwd.texas.gov/fishboat/fish/recreational/catchrelease/bass_length_weight.phtml).

## Recommended correction

Replace the broad name-based fallback with explicit reviewed baselines for all
251 stable species IDs. Pick coherent length/weight pairs for real fish, with
documented species assumptions for ambiguous names. Give fantasy species their
own deliberate sizes based on the artwork and intended creature scale. Keep
Fihs and the Sock's exceptional encounter/rank rules intact.

Keep the correlated cubic model and established rarity bands for the initial
content pass. Do not multiply all weights globally. Version future catches;
preserve existing measurements, saved ranks, coins, XP and records. Recheck all
supported rank bands after changing baselines. Keep encounter tickets and economy
values explicit: rebuilding the current catalog can otherwise recompute default
base values from the changed typical weights.

## Complete baseline inventory

These are current game prototypes, not a claim that all 251 are biologically
validated. “Generic fallback” marks missing deliberate species measurements.
Other rows identify the shared name-family rule or one of the eight original
starter overrides.

| Sprite/species key | Typical length | Typical weight | Current source |
| --- | ---: | ---: | --- |
| anchovy | 9 cm | 14 g | minnow, shiner, dace, sardine, anchovy, goby, loach, bitterling |
| ancient-iced-coelacanth | 110 cm | 22000 g | coelacanth, grouper |
| ancient-sturgeon | 150 cm | 18000 g | sturgeon, muskellunge |
| angelfish | 23 cm | 240 g | generic fallback |
| ash-trout | 45 cm | 1200 g | trout, salmon, burbot |
| aurora-frostfin | 23 cm | 240 g | generic fallback |
| aurora-fry | 4.5 cm | 3 g | seahorse, guppy, fry |
| basalt-blowfish | 23 cm | 240 g | generic fallback |
| bass | 55 cm | 2500 g | bass, pike, gar, bowfin |
| batfin | 23 cm | 240 g | generic fallback |
| betta-fish | 23 cm | 240 g | generic fallback |
| big-mouth-bass | 55 cm | 2500 g | bass, pike, gar, bowfin |
| bitterling | 9 cm | 14 g | minnow, shiner, dace, sardine, anchovy, goby, loach, bitterling |
| blaze-goby | 9 cm | 14 g | minnow, shiner, dace, sardine, anchovy, goby, loach, bitterling |
| blazing-coelacanth | 110 cm | 22000 g | coelacanth, grouper |
| blenny | 23 cm | 240 g | generic fallback |
| blobfish | 23 cm | 240 g | generic fallback |
| blowfish | 23 cm | 240 g | generic fallback |
| blue-crab | 15 cm | 350 g | crab |
| blue-discus | 23 cm | 240 g | generic fallback |
| blue-marlin | 300 cm | 85000 g | marlin, swordfish, oarfish |
| blue-slimefish | 23 cm | 240 g | generic fallback |
| blue-tang | 23 cm | 240 g | generic fallback |
| bluegill | 18 cm | 140 g | starter override |
| blueglass | 23 cm | 240 g | generic fallback |
| bowfin | 55 cm | 2500 g | bass, pike, gar, bowfin |
| bream | 20 cm | 200 g | perch, bluegill, bluegil, blueegill, sunfish, bream, crappie |
| brook-trout | 45 cm | 1200 g | trout, salmon, burbot |
| brown-ray | 100 cm | 14000 g | ray |
| brown-trout | 45 cm | 1200 g | trout, salmon, burbot |
| bullhead | 23 cm | 240 g | generic fallback |
| burbot | 45 cm | 1200 g | trout, salmon, burbot |
| burnfish | 23 cm | 240 g | generic fallback |
| burning-bluegil | 20 cm | 200 g | perch, bluegill, bluegil, blueegill, sunfish, bream, crappie |
| burning-slimefish | 23 cm | 240 g | generic fallback |
| candycorn-minnow | 9 cm | 14 g | minnow, shiner, dace, sardine, anchovy, goby, loach, bitterling |
| carp | 43 cm | 1700 g | starter override |
| catfish | 65 cm | 4000 g | starter override |
| channel-catfish | 65 cm | 4000 g | catfish |
| chillscale-fry | 4.5 cm | 3 g | seahorse, guppy, fry |
| chromis | 23 cm | 240 g | generic fallback |
| cinder-loach | 9 cm | 14 g | minnow, shiner, dace, sardine, anchovy, goby, loach, bitterling |
| cinderling-guppy | 4.5 cm | 3 g | seahorse, guppy, fry |
| cinderling | 23 cm | 240 g | generic fallback |
| cloudfish | 23 cm | 240 g | generic fallback |
| clownfish | 23 cm | 240 g | generic fallback |
| cobweb-carp | 43 cm | 1700 g | carp, koi |
| cod | 65 cm | 3800 g | cod, haddock, halibut, hake |
| coelacanth | 110 cm | 22000 g | coelacanth, grouper |
| coldmist | 23 cm | 240 g | generic fallback |
| coral-fish | 23 cm | 240 g | generic fallback |
| corecracker-carp | 43 cm | 1700 g | carp, koi |
| crackle-perch | 20 cm | 200 g | perch, bluegill, bluegil, blueegill, sunfish, bream, crappie |
| crappie | 20 cm | 200 g | perch, bluegill, bluegil, blueegill, sunfish, bream, crappie |
| creek-chub | 23 cm | 240 g | generic fallback |
| croaker | 23 cm | 240 g | generic fallback |
| crucean-carp | 43 cm | 1700 g | carp, koi |
| crystal-eel | 70 cm | 1300 g | eel |
| crystalstinger | 23 cm | 240 g | generic fallback |
| dace | 9 cm | 14 g | minnow, shiner, dace, sardine, anchovy, goby, loach, bitterling |
| damselfish | 23 cm | 240 g | generic fallback |
| deep-anglerfish | 23 cm | 240 g | generic fallback |
| deepwinter-catfish | 65 cm | 4000 g | catfish |
| dorado | 23 cm | 240 g | generic fallback |
| dragonfish | 23 cm | 240 g | generic fallback |
| drum | 23 cm | 240 g | generic fallback |
| dungeness-crab | 15 cm | 350 g | crab |
| duskbite | 23 cm | 240 g | generic fallback |
| eel | 70 cm | 1300 g | eel |
| ember-minnow | 9 cm | 14 g | minnow, shiner, dace, sardine, anchovy, goby, loach, bitterling |
| ember-platy | 23 cm | 240 g | generic fallback |
| fallfish | 23 cm | 240 g | generic fallback |
| fathead-minnow | 9 cm | 14 g | minnow, shiner, dace, sardine, anchovy, goby, loach, bitterling |
| fihs | 23 cm | 240 g | generic fallback |
| fire-tuna | 140 cm | 45000 g | tuna |
| firefish | 23 cm | 240 g | generic fallback |
| fish-toy | 23 cm | 240 g | generic fallback |
| flamejaw-oarfish | 300 cm | 85000 g | marlin, swordfish, oarfish |
| flounder | 23 cm | 240 g | generic fallback |
| flowstone-muskellunge | 150 cm | 18000 g | sturgeon, muskellunge |
| frostbelle | 23 cm | 240 g | generic fallback |
| frostbite | 23 cm | 240 g | generic fallback |
| frostcloak-squid | 40 cm | 1500 g | jelly, octopus, squid |
| frostfang | 23 cm | 240 g | generic fallback |
| frostfin-minnow | 9 cm | 14 g | minnow, shiner, dace, sardine, anchovy, goby, loach, bitterling |
| frostglass-koi | 43 cm | 1700 g | carp, koi |
| frostjaw-bass | 55 cm | 2500 g | bass, pike, gar, bowfin |
| frostveil-betta | 23 cm | 240 g | generic fallback |
| frosty-nyan-fish | 23 cm | 240 g | generic fallback |
| frozen-fangling | 23 cm | 240 g | generic fallback |
| frozen-loach | 9 cm | 14 g | minnow, shiner, dace, sardine, anchovy, goby, loach, bitterling |
| furnace-koi | 43 cm | 1700 g | carp, koi |
| galaxyscale | 23 cm | 240 g | generic fallback |
| gar | 55 cm | 2500 g | bass, pike, gar, bowfin |
| ghost-shiner | 9 cm | 14 g | minnow, shiner, dace, sardine, anchovy, goby, loach, bitterling |
| giant-catfish | 240 cm | 80000 g | giant-catfish |
| giant-crab | 15 cm | 350 g | crab |
| glaciel-sturgeon | 150 cm | 18000 g | sturgeon, muskellunge |
| glacier-guppy | 4.5 cm | 3 g | seahorse, guppy, fry |
| glacier-maw | 23 cm | 240 g | generic fallback |
| glacier-puffer | 23 cm | 240 g | generic fallback |
| glimmer-eel | 70 cm | 1300 g | eel |
| golden-shiner | 9 cm | 14 g | minnow, shiner, dace, sardine, anchovy, goby, loach, bitterling |
| goldfish | 12 cm | 70 g | starter override |
| goliath-grouper | 220 cm | 180000 g | goliath-grouper |
| great-hellfin-shark | 480 cm | 700000 g | great-.*shark |
| great-white-shark | 480 cm | 700000 g | great-.*shark |
| green-tropic-fish | 23 cm | 240 g | generic fallback |
| grunt | 23 cm | 240 g | generic fallback |
| gudgeon | 23 cm | 240 g | generic fallback |
| guppy | 4.5 cm | 3 g | starter override |
| haddock | 65 cm | 3800 g | cod, haddock, halibut, hake |
| hail-pike | 55 cm | 2500 g | bass, pike, gar, bowfin |
| hailstinger | 23 cm | 240 g | generic fallback |
| hailstripe | 23 cm | 240 g | generic fallback |
| hake | 65 cm | 3800 g | cod, haddock, halibut, hake |
| halibut | 65 cm | 3800 g | cod, haddock, halibut, hake |
| heated-goliath-grouper | 220 cm | 180000 g | goliath-grouper |
| hermit-crab | 15 cm | 350 g | crab |
| herring | 23 cm | 240 g | generic fallback |
| honey-carp | 43 cm | 1700 g | carp, koi |
| icebarb-sturgeon | 150 cm | 18000 g | sturgeon, muskellunge |
| icechip-dace | 9 cm | 14 g | minnow, shiner, dace, sardine, anchovy, goby, loach, bitterling |
| icefeather-lionfish | 23 cm | 240 g | generic fallback |
| icefish | 23 cm | 240 g | generic fallback |
| icey-blueegill | 20 cm | 200 g | perch, bluegill, bluegil, blueegill, sunfish, bream, crappie |
| icicle-goby | 9 cm | 14 g | minnow, shiner, dace, sardine, anchovy, goby, loach, bitterling |
| icicle-seahorse | 4.5 cm | 3 g | seahorse, guppy, fry |
| ignis-catfish | 65 cm | 4000 g | catfish |
| jack-o-fish | 23 cm | 240 g | generic fallback |
| jellyfish | 40 cm | 1500 g | jelly, octopus, squid |
| kelpfin | 23 cm | 240 g | generic fallback |
| kelpscale | 23 cm | 240 g | generic fallback |
| killifish | 23 cm | 240 g | generic fallback |
| koi | 43 cm | 1700 g | starter override |
| lava-dragonfish | 23 cm | 240 g | generic fallback |
| lava-eel | 70 cm | 1300 g | eel |
| lava-shiner | 9 cm | 14 g | minnow, shiner, dace, sardine, anchovy, goby, loach, bitterling |
| lavafish | 23 cm | 240 g | generic fallback |
| leaffish | 23 cm | 240 g | generic fallback |
| lightning-loach | 9 cm | 14 g | minnow, shiner, dace, sardine, anchovy, goby, loach, bitterling |
| lil-blue-slimefish | 23 cm | 240 g | generic fallback |
| lil-green-slimefish | 23 cm | 240 g | generic fallback |
| lil-molten-slime | 23 cm | 240 g | generic fallback |
| lil-pink-slimefish | 23 cm | 240 g | generic fallback |
| lionfish | 23 cm | 240 g | generic fallback |
| mackerel | 23 cm | 240 g | generic fallback |
| magma-carp | 43 cm | 1700 g | carp, koi |
| magma-danio | 23 cm | 240 g | generic fallback |
| magma-sardine | 9 cm | 14 g | minnow, shiner, dace, sardine, anchovy, goby, loach, bitterling |
| manta-ray | 380 cm | 260000 g | manta |
| minnow | 9 cm | 14 g | starter override |
| molten-angelfish | 23 cm | 240 g | generic fallback |
| molten-giant-catfish | 320 cm | 160000 g | titanic, molten-giant |
| molten-piranha | 23 cm | 240 g | generic fallback |
| moon-fish | 23 cm | 240 g | generic fallback |
| moon-jelly | 40 cm | 1500 g | jelly, octopus, squid |
| moonice-wrasse | 23 cm | 240 g | generic fallback |
| mosquitofish | 23 cm | 240 g | generic fallback |
| mudminnow | 9 cm | 14 g | minnow, shiner, dace, sardine, anchovy, goby, loach, bitterling |
| mullet | 23 cm | 240 g | generic fallback |
| muskellunge | 150 cm | 18000 g | sturgeon, muskellunge |
| mussels | 10 cm | 60 g | mussel, starfish |
| nebula-minnow | 9 cm | 14 g | minnow, shiner, dace, sardine, anchovy, goby, loach, bitterling |
| nidalees-lost-sock | 28 cm | 45 g | sock |
| nomura-s-jellyfish | 180 cm | 120000 g | nomura |
| nyan-fish | 23 cm | 240 g | generic fallback |
| oarfish | 300 cm | 85000 g | marlin, swordfish, oarfish |
| octopus | 40 cm | 1500 g | jelly, octopus, squid |
| pea-crab | 15 cm | 350 g | crab |
| pearl-wisp | 23 cm | 240 g | generic fallback |
| perch | 23 cm | 240 g | starter override |
| petal-koi | 43 cm | 1700 g | carp, koi |
| phantom-ray | 100 cm | 14000 g | ray |
| pink-salmon | 45 cm | 1200 g | trout, salmon, burbot |
| pink-slimefish | 23 cm | 240 g | generic fallback |
| plaice | 23 cm | 240 g | generic fallback |
| polar-shiner | 9 cm | 14 g | minnow, shiner, dace, sardine, anchovy, goby, loach, bitterling |
| pollock | 23 cm | 240 g | generic fallback |
| pumpkin-koi | 43 cm | 1700 g | carp, koi |
| pumpkin-puffer | 23 cm | 240 g | generic fallback |
| pumpkinseed | 23 cm | 240 g | generic fallback |
| pyro-discus | 23 cm | 240 g | generic fallback |
| rainbow-trout | 45 cm | 1200 g | trout, salmon, burbot |
| red-king-crab | 15 cm | 350 g | crab |
| red-reef-fish | 23 cm | 240 g | generic fallback |
| rift-bitterling | 9 cm | 14 g | minnow, shiner, dace, sardine, anchovy, goby, loach, bitterling |
| roach | 23 cm | 240 g | generic fallback |
| rock-bass | 55 cm | 2500 g | bass, pike, gar, bowfin |
| rudd | 23 cm | 240 g | generic fallback |
| ruffe | 23 cm | 240 g | generic fallback |
| runefin | 23 cm | 240 g | generic fallback |
| sandbar-shark | 240 cm | 90000 g | shark |
| sardine | 9 cm | 14 g | minnow, shiner, dace, sardine, anchovy, goby, loach, bitterling |
| sea-turtle | 90 cm | 75000 g | sea-turtle |
| seahorse | 4.5 cm | 3 g | seahorse, guppy, fry |
| seared-fry | 4.5 cm | 3 g | seahorse, guppy, fry |
| searfin | 23 cm | 240 g | generic fallback |
| searing-bass | 55 cm | 2500 g | bass, pike, gar, bowfin |
| shad | 23 cm | 240 g | generic fallback |
| shadow-koi | 43 cm | 1700 g | carp, koi |
| shark | 240 cm | 90000 g | shark |
| shiner | 9 cm | 14 g | minnow, shiner, dace, sardine, anchovy, goby, loach, bitterling |
| silver-perch | 20 cm | 200 g | perch, bluegill, bluegil, blueegill, sunfish, bream, crappie |
| silverflake | 23 cm | 240 g | generic fallback |
| silverscale | 23 cm | 240 g | generic fallback |
| skullscale | 23 cm | 240 g | generic fallback |
| slush-minnow | 9 cm | 14 g | minnow, shiner, dace, sardine, anchovy, goby, loach, bitterling |
| smelt | 23 cm | 240 g | generic fallback |
| snow-crab | 15 cm | 350 g | crab |
| snow-puff | 23 cm | 240 g | generic fallback |
| snowcap | 23 cm | 240 g | generic fallback |
| snowdrift | 23 cm | 240 g | generic fallback |
| snowflake-guppy | 4.5 cm | 3 g | seahorse, guppy, fry |
| snowmantle-plaice | 23 cm | 240 g | generic fallback |
| snowpike | 55 cm | 2500 g | bass, pike, gar, bowfin |
| spottail-shiner | 9 cm | 14 g | minnow, shiner, dace, sardine, anchovy, goby, loach, bitterling |
| squid | 40 cm | 1500 g | jelly, octopus, squid |
| starfish | 10 cm | 60 g | mussel, starfish |
| stickleback | 23 cm | 240 g | generic fallback |
| stingray | 100 cm | 14000 g | ray |
| stone-loach | 9 cm | 14 g | minnow, shiner, dace, sardine, anchovy, goby, loach, bitterling |
| stormscale | 23 cm | 240 g | generic fallback |
| sturddlefish | 23 cm | 240 g | generic fallback |
| suckerfish | 23 cm | 240 g | generic fallback |
| sun-fish | 23 cm | 240 g | generic fallback |
| sunfish | 20 cm | 200 g | perch, bluegill, bluegil, blueegill, sunfish, bream, crappie |
| sunray-fish | 100 cm | 14000 g | ray |
| swordfish | 300 cm | 85000 g | marlin, swordfish, oarfish |
| tench | 23 cm | 240 g | generic fallback |
| thornscale | 23 cm | 240 g | generic fallback |
| threadfin-shad | 23 cm | 240 g | generic fallback |
| tidebloom | 23 cm | 240 g | generic fallback |
| tilapia | 23 cm | 240 g | generic fallback |
| titanic-frost-catfish | 320 cm | 160000 g | titanic, molten-giant |
| triggerfish | 23 cm | 240 g | generic fallback |
| tuna | 140 cm | 45000 g | tuna |
| void-fish | 23 cm | 240 g | generic fallback |
| volcanic-ray | 100 cm | 14000 g | ray |
| volcanic-sturgeon | 150 cm | 18000 g | sturgeon, muskellunge |
| walleye | 23 cm | 240 g | generic fallback |
| warmouth | 23 cm | 240 g | generic fallback |
| white-bass | 55 cm | 2500 g | bass, pike, gar, bowfin |
| whitefish | 23 cm | 240 g | generic fallback |
| whiteout-shark | 240 cm | 90000 g | shark |
| whiting | 23 cm | 240 g | generic fallback |
| winter-sprat | 23 cm | 240 g | generic fallback |
| wintertide-octopus | 40 cm | 1500 g | jelly, octopus, squid |
| witchlight-betta | 23 cm | 240 g | generic fallback |
| wrasse | 23 cm | 240 g | generic fallback |
| yellow-perch | 20 cm | 200 g | perch, bluegill, bluegil, blueegill, sunfish, bream, crappie |
