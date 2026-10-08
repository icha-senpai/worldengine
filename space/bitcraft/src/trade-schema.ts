import { table, t } from "spacetimedb/server";

export const tradeRecord = table(
  { name: "trade_record" },
  {
    key: t.string().primaryKey(),
    scope: t.string().index("btree"),
    payload: t.string(),
  },
);
export const tradeReference = table(
  { name: "trade_reference" },
  {
    key: t.string().primaryKey(),
    payload: t.string(),
  },
);
