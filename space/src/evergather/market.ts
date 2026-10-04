import type { DbConnection } from "../bindings/evergather";

export function marketListingsFor(conn: DbConnection | null) {
  if (!conn) return [];
  // Own escrow can also be on the public page. Show each listing once.
  return [
    ...new Map(
      [
        ...conn.db.marketPageListings.iter(),
        ...conn.db.myMarketListings.iter(),
      ].map((row) => [row.id, row] as const),
    ).values(),
  ];
}

export function marketPageInfo(conn: DbConnection | null, requested: number) {
  const summary = conn ? [...conn.db.marketSummary.iter()][0] : undefined;
  const pages = summary?.pages ?? 1;
  return {
    total: summary?.total ?? 0,
    pages,
    page: Math.min(pages, Math.max(1, requested)),
  };
}
