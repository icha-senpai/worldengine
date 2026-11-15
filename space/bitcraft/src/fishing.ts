export const SPECIES = [
  ["Briny Linus", 1110002, 1110003],
  ["Muddy Oncor", 2110002, 2110003],
  ["Greenhorn Gorbu", 3110002, 3110003],
  ["Azure Centro", 4110002, 4110003],
  ["Rocky Aulono", 5110002, 5110003],
  ["Vibrant Janus", 6110002, 6110003],
  ["Mossy Amia", 5045122, 826362353],
  ["Murky Oxy", 2390533, 509854054],
  ["Abyssal Gladius", 1812221896, 1141184831],
  ["Divine Selachii", 424796674, 1006230316],
].flatMap(([name, ordinary, chummed], index) => [
  { name, tier: index + 1, id: ordinary, chummed: false },
  { name, tier: index + 1, id: chummed, chummed: true },
]);
export const SCHOOL_BY_ID = new Map(SPECIES.map((s) => [s.id, s]));

export function fishQueries() {
  const condition = SPECIES.map(
    (s) => `resource_state.resource_id = ${s.id}`,
  ).join(" OR ");
  return [
    `SELECT resource_state.* FROM resource_state WHERE ${condition}`,
    `SELECT location_state.* FROM location_state JOIN resource_state ON location_state.entity_id = resource_state.entity_id WHERE ${condition}`,
  ];
}
