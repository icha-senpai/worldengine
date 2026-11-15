// Regenerate the reviewed wire contract explicitly after an upstream game update.
import { readFile, writeFile } from "node:fs/promises";
const args = process.argv.slice(2);
const sourceIndex = args.indexOf("--source-schema");
if (sourceIndex >= 0 && !args[sourceIndex + 1])
  throw Error("Missing source schema path");
const source =
  sourceIndex >= 0
    ? JSON.parse(await readFile(args[sourceIndex + 1], "utf8"))
    : null;
const names = [
  "sell_order_state",
  "buy_order_state",
  "marketplace_state",
  "barter_stall_state",
  "building_state",
  "building_nickname_state",
  "trade_order_state",
  "claim_state",
  "claim_member_state",
  "claim_tech_state",
  "claim_tech_desc",
  "building_desc",
  "claim_tile_cost",
  "parameters_desc",
  "claim_local_state",
  "player_username_state",
  "experience_state",
  "player_state",
  "inventory_state",
  "progressive_action_state",
  "passive_craft_state",
];
async function schemaFor(host, database) {
  const schema = await fetch(
    `${host}/v1/database/${database}/schema?version=9`,
    { signal: AbortSignal.timeout(15000) },
  ).then((r) => {
    if (!r.ok) throw Error("Schema unavailable");
    return r.json();
  });
  return schema;
}
function contractFor(schema, tables, canonical = false) {
  function expand(type, depth = 0) {
    if (depth > 30) throw Error("Recursive schema");
    if (type.Ref !== undefined)
      return expand(schema.typespace.types[type.Ref], depth + 1);
    if (type.Product)
      return {
        Product: {
          elements: type.Product.elements.map((e) => ({
            name: e.name.some ?? "",
            type: expand(e.algebraic_type, depth + 1),
          })),
        },
      };
    if (type.Sum)
      return {
        Sum: {
          variants: type.Sum.variants.map((e) => ({
            // The bitsync relay uses lower camel case for canonical Rust enums.
            name: canonical
              ? (e.name.some ?? "").replace(/^[A-Z]/, (c) => c.toLowerCase())
              : (e.name.some ?? ""),
            type: expand(e.algebraic_type, depth + 1),
          })),
        },
      };
    if (type.Array) return { Array: expand(type.Array, depth + 1) };
    return type;
  }
  const contract = Object.fromEntries(
    tables.map((name) => {
      const table = schema.tables.find((t) => t.name === name);
      if (!table) throw Error("Missing " + name);
      return [
        name,
        {
          key: table.primary_key,
          type: expand(schema.typespace.types[table.product_type_ref]),
        },
      ];
    }),
  );
  return contract;
}
const bitsyncOnly = args.includes("--bitsync-only");
// Retain the reviewed legacy contract when updating the active release relay.
const retained = bitsyncOnly
  ? JSON.parse(await readFile("scripts/relay-wire-contract.json", "utf8"))
  : null;
const [bitconnect, bitsync, global] = await Promise.all([
  bitsyncOnly
    ? null
    : schemaFor("https://relay.bitjita.com", "bitcraft-live-8"),
  source ?? schemaFor("https://relay.bitcraftsync.app:3008", "idc-8"),
  schemaFor("https://relay.bitcraftsync.app:3000", "idc-global"),
]);
const contract = {
  bitconnect: bitsyncOnly
    ? retained.bitconnect
    : contractFor(bitconnect, names),
  bitsync: {
    ...contractFor(
      bitsync,
      [...names, "resource_state", "location_state"],
      !!source,
    ),
    ...contractFor(global, ["empire_state", "empire_settlement_state"]),
  },
};
const boss = contractFor(
  bitsync,
  [
    "enemy_state",
    "health_state",
    "contribution_state",
    "player_username_state",
    "attack_event",
  ],
  !!source,
);
for (const [file, data] of [
  ["scripts/relay-wire-contract.json", contract],
  ["scripts/boss-wire-contract.json", boss],
]) {
  const text = JSON.stringify(data, null, 2) + "\n";
  if (args.includes("--check")) {
    if ((await readFile(file, "utf8")).replace(/\r\n/g, "\n") !== text)
      throw Error(`Contract is stale: ${file}`);
  } else await writeFile(file, text);
}
console.log(
  "Pinned",
  names.length,
  "state tables and boss contracts; review this diff before publishing.",
);
