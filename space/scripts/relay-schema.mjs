// Regenerate the reviewed wire contract explicitly after an upstream game update.
import { writeFile } from "node:fs/promises";
const names = [
  "sell_order_state",
  "buy_order_state",
  "marketplace_state",
  "barter_stall_state",
  "building_state",
  "building_nickname_state",
  "trade_order_state",
  "claim_state",
  "claim_local_state",
  "player_username_state",
  "experience_state",
  "player_state",
  "inventory_state",
  "progressive_action_state",
  "passive_craft_state",
];
async function contractFor(host) {
  const schema = await fetch(
    `${host}/v1/database/bitcraft-live-8/schema?version=9`,
  ).then((r) => {
    if (!r.ok) throw Error("Schema unavailable");
    return r.json();
  });
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
            name: e.name.some ?? "",
            type: expand(e.algebraic_type, depth + 1),
          })),
        },
      };
    if (type.Array) return { Array: expand(type.Array, depth + 1) };
    return type;
  }
  const contract = Object.fromEntries(
    names.map((name) => {
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
const contract = {
  bitconnect: await contractFor("https://relay.bitjita.com"),
  bitsync: await contractFor("https://relay.bitcraftsync.app:3008"),
};
await writeFile(
  "scripts/relay-wire-contract.json",
  JSON.stringify(contract, null, 2) + "\n",
);
console.log(
  "Pinned",
  names.length,
  "state tables; review this diff before publishing.",
);
