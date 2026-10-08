import { orderDelta } from "../bitcraft/src/trade-delta";
type Trading = { books: Record<string, any>; stalls: any[] };
function normalize(data: Trading): Trading {
  const ordered = (rows: any[]) =>
    [...rows].sort((a, b) =>
      String(a.entityId).localeCompare(String(b.entityId)),
    );
  return {
    books: Object.fromEntries(
      Object.entries(data.books).map(([key, book]) => [
        key,
        {
          ...book,
          ...Object.fromEntries(
            [
              "sellOrders",
              "buyOrders",
              "packageSellOrders",
              "packageBuyOrders",
            ].map((field) => [field, ordered(book[field] ?? [])]),
          ),
        },
      ]),
    ),
    stalls: ordered(
      data.stalls.map((stall) => ({ ...stall, orders: ordered(stall.orders) })),
    ),
  };
}
export function tradingDelta(previous: Trading | undefined, raw: Trading) {
  const next = normalize(raw),
    oldStalls = new Map(
      previous?.stalls.map((stall) => [stall.entityId, stall]) ?? [],
    );
  const books = Object.fromEntries(
    Object.entries(next.books)
      .filter(
        ([key, book]) =>
          JSON.stringify(previous?.books[key]) !== JSON.stringify(book),
      )
      .map(([key, book]) => [key, orderDelta(previous?.books[key], book)]),
  );
  const stalls = next.stalls
    .filter(
      (stall) =>
        JSON.stringify(oldStalls.get(stall.entityId)) !== JSON.stringify(stall),
    )
    .map((stall) => orderDelta(oldStalls.get(stall.entityId), stall, true));
  const removedBooks = Object.keys(previous?.books ?? {}).filter(
    (key) => !next.books[key],
  );
  const ids = new Set(next.stalls.map((stall) => stall.entityId));
  const removedStalls = [...oldStalls.keys()].filter((id) => !ids.has(id));
  return {
    next,
    patch: {
      delta: true,
      reset: !previous,
      books,
      stalls,
      removedBooks,
      removedStalls,
    },
    changed:
      !previous ||
      Boolean(
        Object.keys(books).length ||
        stalls.length ||
        removedBooks.length ||
        removedStalls.length,
      ),
  };
}
