# Species measurements — catalog 5

All 251 sprite keys have explicit measurements in
`content/species-measurements.csv`. These are chosen game baselines: real fish
use coherent adult/body-shape scales, while fantasy creatures have deliberately
designed dimensions chosen after reviewing every supplied sprite. “Typical” is
the game's reference specimen, not a scientific population average. The shared
23 cm / 240 g fallback and historical starter overrides are no longer active.

The bot and website display inches and pounds/ounces. The reference table below
uses the same display formatting. CSV, database and game calculations retain
integer millimeters and grams so conversion never changes rarity or rewards.

## Calibration and assumptions

The cubic length/weight curve and ±12% condition interval remain unchanged.
Dimensions stay correlated, and rarity still requires both species-relative
thresholds. Rank-threshold version 4 and gameplay-rules version 6 remain in use;
new catches receive content version 5. Tiny specimens still use whole grams,
which limits weight precision at the smallest sizes.

- Largemouth-style bass use 40 cm / 1.1 kg and 50 cm / 2.1 kg. These closely
  track the [Texas Parks and Wildlife average length-weight table](https://tpwd.texas.gov/fishboat/fish/recreational/catchrelease/bass_length_weight.phtml)
  across the supported game sizes, with the usual condition variation.
- Carp, walleye and bluefin tuna were checked against species-specific
  [FishBase length-weight relationships](https://www.fishbase.se/manual/English/fishbasethe_length_weight_table.htm).
  [Common carp](https://www.fishbase.org/summary/1450), [walleye](https://www.fishbase.se/summary/3516)
  and [Atlantic bluefin tuna](https://www.fishbase.se/summary/SpeciesSummary.php?AT=bluefin+tuna&ID=147)
  supply scale anchors; the runtime keeps its cubic approximation rather than
  importing species-specific exponents into the rank system.
- Stickleback is treated as threespine-like, with a 5 cm / 2 g reference.
  [FishBase](https://www.fishbase.se/summary/2420) gives common length 5.1 cm.
  Fathead minnows use 4 cm / 1 g; their 1.9× upper game size is 7.6 cm, close to
  the [Missouri Department of Conservation size reference](https://mdc.mo.gov/discover-nature/field-guide/fathead-minnow).
- Ambiguous ordinary names receive a declared game interpretation: bass is
  largemouth-like, bream is freshwater bream, perch is freshwater yellow/European
  perch-like, dorado is mahi-mahi-like, tuna is bluefin-like, and generic shark is
  a medium coastal shark. Catfish represents a larger freshwater catfish than
  the separately named channel-catfish; gar is a longnose-like game fish.
- Crabs use carapace width, rays use disc width, jellyfish use bell diameter,
  and seahorses use height. Squid use approximate mantle-plus-head length;
  octopus uses approximate arm spread, and sea turtles use shell length.
  The UI's shared “length” label represents that item's chosen major dimension.
  Their weights are whole-creature game measurements, including shells.
- Aquarium, marine, freshwater and invertebrate profiles are designed
  approximations, not claims that every entry has an individually measured
  biological sample or research-backed weight coefficient.
- Fantasy profiles are original game choices. Small “fry”, guppy and minnow
  creatures stay small; armored, molten, ancient and titanic creatures have
  progressively larger or denser bodies. Fihs is 65 cm / 5.5 kg at its reference
  size and draws only its UUR band (roughly 120.3–123.5 cm). Nidalees Lost Sock
  uses a 28 cm / 70 g wet-cloth reference and remains F-only. Their encounter
  scarcity and 2:1 probability relationship remain unchanged.

## Maintenance and safe activation

Edit the CSV, run `node scripts/build-world-content.mjs`, and run
`npm run content:validate` plus `cargo test -p game-rules --locked`. Missing,
duplicate, invalid or extra keys fail validation. Regeneration preserves
explicit base prices instead of deriving prices from weight, so measurements
do not silently rebalance XP, sale values or encounter tickets.

For an existing deployment, publish additively and call the owner-only
`activate_species_measurements` reducer. It updates only the six measurement
fields on each species definition. It leaves every catch's measured size,
saved rarity, size grade, sale value and content/rules versions intact, along
with players, progress, records, achievements, equipment and all other tables.
It is idempotent and rejects non-owner callers.

The historical eight-species `meadow-pond.json` remains a phase-0 reference,
with no active override of the reviewed CSV.

## Complete reference table

| Species key | Typical length | Typical weight | Profile |
| --- | ---: | ---: | --- |
| anchovy | 4.3 in | 0.35 oz | marine |
| ancient-iced-coelacanth | 63.0 in | 187 lb 6.29 oz | fantasy |
| ancient-sturgeon | 70.9 in | 88 lb 2.96 oz | fantasy |
| angelfish | 4.7 in | 1.41 oz | aquarium |
| ash-trout | 16.5 in | 2 lb 1.51 oz | fantasy |
| aurora-frostfin | 27.6 in | 14 lb 5.28 oz | fantasy |
| aurora-fry | 2.4 in | 0.14 oz | fantasy |
| basalt-blowfish | 11.0 in | 1 lb 6.93 oz | fantasy |
| bass | 15.7 in | 2 lb 6.80 oz | largemouth |
| batfin | 15.0 in | 2 lb 6.80 oz | fantasy |
| betta-fish | 2.2 in | 0.11 oz | aquarium |
| big-mouth-bass | 19.7 in | 4 lb 10.08 oz | largemouth |
| bitterling | 2.2 in | 0.11 oz | freshwater |
| blaze-goby | 3.3 in | 0.32 oz | fantasy |
| blazing-coelacanth | 51.2 in | 99 lb 3.33 oz | fantasy |
| blenny | 3.7 in | 0.42 oz | marine |
| blobfish | 9.8 in | 1 lb 1.64 oz | marine |
| blowfish | 8.7 in | 10.58 oz | marine |
| blue-crab | 5.9 in | 6.35 oz | invertebrate |
| blue-discus | 5.5 in | 3.17 oz | aquarium |
| blue-marlin | 94.5 in | 242 lb 8.14 oz | marine |
| blue-slimefish | 12.6 in | 1 lb 13.98 oz | fantasy |
| blue-tang | 7.1 in | 5.29 oz | marine |
| bluegill | 7.1 in | 4.94 oz | freshwater |
| blueglass | 7.9 in | 5.29 oz | fantasy |
| bowfin | 21.7 in | 5 lb 4.66 oz | freshwater |
| bream | 11.8 in | 15.87 oz | freshwater |
| brook-trout | 9.8 in | 6.35 oz | freshwater |
| brown-ray | 25.6 in | 7 lb 11.46 oz | marine |
| brown-trout | 15.7 in | 1 lb 12.22 oz | freshwater |
| bullhead | 9.8 in | 10.58 oz | freshwater |
| burbot | 19.7 in | 2 lb 10.33 oz | freshwater |
| burnfish | 7.1 in | 3.17 oz | fantasy |
| burning-bluegil | 9.1 in | 10.58 oz | fantasy |
| burning-slimefish | 14.2 in | 3 lb 1.38 oz | fantasy |
| candycorn-minnow | 5.1 in | 1.23 oz | fantasy |
| carp | 17.7 in | 3 lb 8.44 oz | carp |
| catfish | 25.6 in | 8 lb 13.10 oz | freshwater |
| channel-catfish | 19.7 in | 3 lb 8.44 oz | freshwater |
| chillscale-fry | 2.6 in | 0.18 oz | fantasy |
| chromis | 3.5 in | 0.53 oz | marine |
| cinder-loach | 5.9 in | 1.41 oz | fantasy |
| cinderling-guppy | 2.8 in | 0.21 oz | fantasy |
| cinderling | 4.7 in | 1.41 oz | fantasy |
| cloudfish | 9.8 in | 12.35 oz | fantasy |
| clownfish | 3.3 in | 0.53 oz | marine |
| cobweb-carp | 25.6 in | 10 lb 9.32 oz | fantasy |
| cod | 25.6 in | 7 lb 0.88 oz | marine |
| coelacanth | 47.2 in | 77 lb 2.59 oz | marine |
| coldmist | 7.1 in | 3.17 oz | fantasy |
| coral-fish | 9.4 in | 10.58 oz | fantasy |
| corecracker-carp | 27.6 in | 14 lb 5.28 oz | fantasy |
| crackle-perch | 11.0 in | 12.35 oz | fantasy |
| crappie | 9.8 in | 10.58 oz | freshwater |
| creek-chub | 4.7 in | 0.71 oz | freshwater |
| croaker | 11.8 in | 14.11 oz | marine |
| crucean-carp | 9.8 in | 12.35 oz | freshwater |
| crystal-eel | 35.4 in | 3 lb 15.49 oz | fantasy |
| crystalstinger | 21.7 in | 5 lb 8.18 oz | fantasy |
| dace | 4.7 in | 0.63 oz | freshwater |
| damselfish | 3.9 in | 0.71 oz | marine |
| deep-anglerfish | 13.8 in | 1 lb 10.46 oz | marine |
| deepwinter-catfish | 43.3 in | 39 lb 10.93 oz | fantasy |
| dorado | 39.4 in | 22 lb 0.74 oz | marine |
| dragonfish | 33.5 in | 22 lb 0.74 oz | fantasy |
| drum | 21.7 in | 5 lb 8.18 oz | freshwater |
| dungeness-crab | 7.1 in | 1 lb 12.22 oz | invertebrate |
| duskbite | 23.6 in | 3 lb 15.49 oz | fantasy |
| eel | 27.6 in | 1 lb 8.69 oz | freshwater |
| ember-minnow | 3.9 in | 0.63 oz | fantasy |
| ember-platy | 2.8 in | 0.28 oz | fantasy |
| fallfish | 7.9 in | 3.17 oz | freshwater |
| fathead-minnow | 1.6 in | 0.04 oz | fathead |
| fihs | 25.6 in | 12 lb 2.01 oz | fantasy |
| fire-tuna | 66.9 in | 187 lb 6.29 oz | fantasy |
| firefish | 11.8 in | 1 lb 3.40 oz | fantasy |
| fish-toy | 3.5 in | 0.88 oz | fantasy |
| flamejaw-oarfish | 189.0 in | 396 lb 13.31 oz | fantasy |
| flounder | 13.8 in | 1 lb 5.16 oz | marine |
| flowstone-muskellunge | 55.1 in | 55 lb 1.85 oz | fantasy |
| frostbelle | 8.7 in | 8.11 oz | fantasy |
| frostbite | 21.7 in | 4 lb 13.60 oz | fantasy |
| frostcloak-squid | 25.6 in | 8 lb 13.10 oz | fantasy |
| frostfang | 47.2 in | 33 lb 1.11 oz | fantasy |
| frostfin-minnow | 4.3 in | 0.78 oz | fantasy |
| frostglass-koi | 25.6 in | 9 lb 14.73 oz | fantasy |
| frostjaw-bass | 21.7 in | 7 lb 11.46 oz | fantasy |
| frostveil-betta | 4.7 in | 1.23 oz | fantasy |
| frosty-nyan-fish | 19.7 in | 7 lb 0.88 oz | fantasy |
| frozen-fangling | 11.8 in | 15.87 oz | fantasy |
| frozen-loach | 7.1 in | 1.94 oz | fantasy |
| furnace-koi | 23.6 in | 9 lb 4.15 oz | fantasy |
| galaxyscale | 35.4 in | 35 lb 4.38 oz | fantasy |
| gar | 39.4 in | 14 lb 5.28 oz | freshwater |
| ghost-shiner | 5.9 in | 1.76 oz | fantasy |
| giant-catfish | 86.6 in | 165 lb 5.55 oz | freshwater |
| giant-crab | 19.7 in | 39 lb 10.93 oz | invertebrate |
| glaciel-sturgeon | 86.6 in | 165 lb 5.55 oz | fantasy |
| glacier-guppy | 3.0 in | 0.25 oz | fantasy |
| glacier-maw | 94.5 in | 308 lb 10.35 oz | fantasy |
| glacier-puffer | 17.7 in | 7 lb 11.46 oz | fantasy |
| glimmer-eel | 33.5 in | 3 lb 8.44 oz | fantasy |
| golden-shiner | 4.7 in | 0.71 oz | freshwater |
| goldfish | 4.7 in | 1.59 oz | aquarium |
| goliath-grouper | 63.0 in | 220 lb 7.40 oz | marine |
| great-hellfin-shark | 216.5 in | 2425 lb 1.36 oz | fantasy |
| great-white-shark | 157.5 in | 1322 lb 12.38 oz | marine |
| green-tropic-fish | 5.5 in | 2.29 oz | fantasy |
| grunt | 9.8 in | 10.58 oz | marine |
| gudgeon | 4.3 in | 0.53 oz | freshwater |
| guppy | 1.6 in | 0.04 oz | aquarium |
| haddock | 17.7 in | 1 lb 15.75 oz | marine |
| hail-pike | 43.3 in | 26 lb 7.29 oz | fantasy |
| hailstinger | 21.7 in | 5 lb 4.66 oz | fantasy |
| hailstripe | 9.4 in | 12.35 oz | fantasy |
| hake | 21.7 in | 2 lb 13.86 oz | marine |
| halibut | 39.4 in | 30 lb 13.84 oz | marine |
| heated-goliath-grouper | 82.7 in | 440 lb 14.79 oz | fantasy |
| hermit-crab | 2.0 in | 0.88 oz | invertebrate |
| herring | 9.8 in | 5.29 oz | marine |
| honey-carp | 21.7 in | 6 lb 9.82 oz | fantasy |
| icebarb-sturgeon | 74.8 in | 121 lb 4.07 oz | fantasy |
| icechip-dace | 5.5 in | 1.06 oz | fantasy |
| icefeather-lionfish | 23.6 in | 9 lb 14.73 oz | fantasy |
| icefish | 5.9 in | 1.41 oz | marine |
| icey-blueegill | 8.7 in | 8.82 oz | fantasy |
| icicle-goby | 3.9 in | 0.53 oz | fantasy |
| icicle-seahorse | 6.7 in | 1.41 oz | fantasy |
| ignis-catfish | 39.4 in | 30 lb 13.84 oz | fantasy |
| jack-o-fish | 15.7 in | 3 lb 15.49 oz | fantasy |
| jellyfish | 9.8 in | 2 lb 3.27 oz | invertebrate |
| kelpfin | 11.8 in | 15.87 oz | fantasy |
| kelpscale | 7.1 in | 4.23 oz | fantasy |
| killifish | 2.4 in | 0.14 oz | aquarium |
| koi | 17.7 in | 3 lb 11.97 oz | aquarium |
| lava-dragonfish | 43.3 in | 48 lb 8.03 oz | fantasy |
| lava-eel | 39.4 in | 6 lb 2.77 oz | fantasy |
| lava-shiner | 5.1 in | 1.23 oz | fantasy |
| lavafish | 13.8 in | 1 lb 13.98 oz | fantasy |
| leaffish | 11.8 in | 1 lb 6.93 oz | fantasy |
| lightning-loach | 19.7 in | 2 lb 3.27 oz | fantasy |
| lil-blue-slimefish | 4.7 in | 1.59 oz | fantasy |
| lil-green-slimefish | 4.3 in | 1.23 oz | fantasy |
| lil-molten-slime | 5.1 in | 2.29 oz | fantasy |
| lil-pink-slimefish | 3.9 in | 1.06 oz | fantasy |
| lionfish | 9.8 in | 10.58 oz | marine |
| mackerel | 11.8 in | 8.82 oz | marine |
| magma-carp | 25.6 in | 12 lb 2.01 oz | fantasy |
| magma-danio | 3.1 in | 0.32 oz | fantasy |
| magma-sardine | 7.1 in | 2.47 oz | fantasy |
| manta-ray | 118.1 in | 661 lb 6.19 oz | marine |
| minnow | 2.8 in | 0.14 oz | freshwater |
| molten-angelfish | 9.8 in | 12.35 oz | fantasy |
| molten-giant-catfish | 129.9 in | 551 lb 2.49 oz | fantasy |
| molten-piranha | 11.8 in | 1 lb 12.22 oz | fantasy |
| moon-fish | 27.6 in | 22 lb 0.74 oz | fantasy |
| moon-jelly | 7.9 in | 1 lb 1.64 oz | invertebrate |
| moonice-wrasse | 17.7 in | 3 lb 4.91 oz | fantasy |
| mosquitofish | 1.2 in | 0.04 oz | aquarium |
| mudminnow | 3.0 in | 0.18 oz | freshwater |
| mullet | 13.8 in | 1 lb 3.40 oz | marine |
| muskellunge | 39.4 in | 17 lb 10.19 oz | freshwater |
| mussels | 2.4 in | 0.88 oz | invertebrate |
| nebula-minnow | 5.9 in | 1.94 oz | fantasy |
| nidalees-lost-sock | 11.0 in | 2.47 oz | artifact |
| nomura-s-jellyfish | 51.2 in | 143 lb 4.81 oz | invertebrate |
| nyan-fish | 17.7 in | 5 lb 8.18 oz | fantasy |
| oarfish | 137.8 in | 121 lb 4.07 oz | marine |
| octopus | 23.6 in | 5 lb 8.18 oz | invertebrate |
| pea-crab | 0.4 in | 0.04 oz | invertebrate |
| pearl-wisp | 3.9 in | 0.42 oz | fantasy |
| perch | 9.1 in | 6.35 oz | freshwater |
| petal-koi | 23.6 in | 8 lb 6.04 oz | fantasy |
| phantom-ray | 59.1 in | 77 lb 2.59 oz | fantasy |
| pink-salmon | 19.7 in | 3 lb 4.91 oz | freshwater |
| pink-slimefish | 11.8 in | 1 lb 10.46 oz | fantasy |
| plaice | 11.8 in | 14.11 oz | marine |
| polar-shiner | 5.1 in | 1.06 oz | fantasy |
| pollock | 25.6 in | 5 lb 8.18 oz | marine |
| pumpkin-koi | 21.7 in | 8 lb 13.10 oz | fantasy |
| pumpkin-puffer | 15.7 in | 5 lb 8.18 oz | fantasy |
| pumpkinseed | 5.9 in | 2.65 oz | freshwater |
| pyro-discus | 8.7 in | 12.35 oz | fantasy |
| rainbow-trout | 13.8 in | 1 lb 1.64 oz | freshwater |
| red-king-crab | 8.7 in | 7 lb 11.46 oz | invertebrate |
| red-reef-fish | 6.3 in | 3.17 oz | fantasy |
| rift-bitterling | 4.3 in | 0.88 oz | fantasy |
| roach | 7.9 in | 4.23 oz | freshwater |
| rock-bass | 7.1 in | 5.29 oz | freshwater |
| rudd | 8.7 in | 6.35 oz | freshwater |
| ruffe | 4.7 in | 0.78 oz | freshwater |
| runefin | 11.0 in | 1 lb 1.64 oz | fantasy |
| sandbar-shark | 59.1 in | 88 lb 2.96 oz | marine |
| sardine | 5.9 in | 1.06 oz | marine |
| sea-turtle | 31.5 in | 154 lb 5.18 oz | marine |
| seahorse | 4.7 in | 0.53 oz | marine |
| seared-fry | 2.6 in | 0.18 oz | fantasy |
| searfin | 11.8 in | 1 lb 5.16 oz | fantasy |
| searing-bass | 19.7 in | 6 lb 9.82 oz | fantasy |
| shad | 13.8 in | 1 lb 5.16 oz | freshwater |
| shadow-koi | 21.7 in | 7 lb 0.88 oz | fantasy |
| shark | 86.6 in | 198 lb 6.66 oz | marine |
| shiner | 3.1 in | 0.18 oz | freshwater |
| silver-perch | 9.8 in | 8.47 oz | freshwater |
| silverflake | 7.1 in | 2.82 oz | fantasy |
| silverscale | 9.8 in | 7.76 oz | fantasy |
| skullscale | 17.7 in | 4 lb 13.60 oz | fantasy |
| slush-minnow | 3.9 in | 0.63 oz | fantasy |
| smelt | 5.9 in | 0.88 oz | marine |
| snow-crab | 5.5 in | 1 lb 8.69 oz | invertebrate |
| snow-puff | 7.1 in | 5.29 oz | fantasy |
| snowcap | 6.7 in | 4.23 oz | fantasy |
| snowdrift | 6.3 in | 3.53 oz | fantasy |
| snowflake-guppy | 2.6 in | 0.18 oz | fantasy |
| snowmantle-plaice | 15.7 in | 2 lb 10.33 oz | fantasy |
| snowpike | 39.4 in | 19 lb 13.47 oz | fantasy |
| spottail-shiner | 3.5 in | 0.21 oz | freshwater |
| squid | 15.7 in | 1 lb 5.16 oz | invertebrate |
| starfish | 5.9 in | 3.53 oz | invertebrate |
| stickleback | 2.0 in | 0.07 oz | stickleback |
| stingray | 31.5 in | 22 lb 0.74 oz | marine |
| stone-loach | 3.9 in | 0.42 oz | freshwater |
| stormscale | 13.8 in | 2 lb 3.27 oz | fantasy |
| sturddlefish | 59.1 in | 55 lb 1.85 oz | freshwater |
| suckerfish | 13.8 in | 1 lb 6.93 oz | freshwater |
| sun-fish | 25.6 in | 19 lb 13.47 oz | fantasy |
| sunfish | 7.1 in | 4.59 oz | freshwater |
| sunray-fish | 11.8 in | 15.87 oz | fantasy |
| swordfish | 78.7 in | 187 lb 6.29 oz | marine |
| tench | 13.8 in | 1 lb 15.75 oz | freshwater |
| thornscale | 11.0 in | 14.11 oz | fantasy |
| threadfin-shad | 4.3 in | 0.42 oz | freshwater |
| tidebloom | 11.8 in | 1 lb 3.40 oz | fantasy |
| tilapia | 11.8 in | 1 lb 8.69 oz | freshwater |
| titanic-frost-catfish | 141.7 in | 661 lb 6.19 oz | fantasy |
| triggerfish | 11.8 in | 1 lb 10.46 oz | marine |
| tuna | 55.1 in | 83 lb 12.41 oz | bluefin |
| void-fish | 39.4 in | 55 lb 1.85 oz | fantasy |
| volcanic-ray | 51.2 in | 66 lb 2.22 oz | fantasy |
| volcanic-sturgeon | 78.7 in | 143 lb 4.81 oz | fantasy |
| walleye | 17.7 in | 2 lb 3.27 oz | walleye |
| warmouth | 6.3 in | 3.17 oz | freshwater |
| white-bass | 11.0 in | 14.11 oz | freshwater |
| whitefish | 15.7 in | 1 lb 8.69 oz | freshwater |
| whiteout-shark | 126.0 in | 485 lb 0.27 oz | fantasy |
| whiting | 11.8 in | 8.82 oz | marine |
| winter-sprat | 5.5 in | 0.88 oz | fantasy |
| wintertide-octopus | 35.4 in | 17 lb 10.19 oz | fantasy |
| witchlight-betta | 6.3 in | 3.17 oz | fantasy |
| wrasse | 7.9 in | 6.35 oz | marine |
| yellow-perch | 8.7 in | 5.29 oz | freshwater |
