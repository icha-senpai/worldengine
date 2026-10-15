import type { DbConnection } from "../bindings/evergather";
import { Identity, Timestamp } from "spacetimedb";
import type { MarketPageListing } from "../bindings/evergather/types";

const pages = new WeakMap<
  DbConnection,
  {
    rows: MarketPageListing[];
    revision: string;
    cursors: Map<number, string>;
    request: number;
  }
>();
export function decodeMarketPage(payload: string) {
  const page = JSON.parse(payload);
  return {
    ...page,
    rows: page.rows.map((row: any) => ({
      ...row,
      id: BigInt(row.id),
      quantity: BigInt(row.quantity),
      unitPrice: BigInt(row.unitPrice),
      toolId: BigInt(row.toolId),
      seller: Identity.fromString(row.seller),
      createdAt: new Timestamp(BigInt(row.createdAt)),
    })) as MarketPageListing[],
  };
}
export async function loadMarketPage(conn: DbConnection, requested: number) {
  const state = pages.get(conn) ?? {
    rows: [],
    revision: "",
    cursors: new Map<number, string>(),
    request: 0,
  };
  pages.set(conn, state);
  const request = ++state.request;
  for (let attempt = 0; attempt < 3; attempt++) {
    const revision = String(conn.db.marketRevision.id.find(1)?.revision ?? 0n);
    if (revision !== state.revision) state.cursors.clear();
    const response = decodeMarketPage(
      await conn.procedures.readMarketListings({
        page: requested,
        after: state.cursors.get(requested) ?? "",
        revision,
      }),
    );
    if (request !== state.request) return null;
    if (
      BigInt(response.revision) <
      (conn.db.marketRevision.id.find(1)?.revision ?? 0n)
    )
      continue;
    if (response.revision !== state.revision) state.cursors.clear();
    state.rows = response.rows;
    state.revision = response.revision;
    state.cursors.set(response.page + 1, response.next);
    return response;
  }
  throw Error("The market is changing quickly. Please reload this page.");
}

export function marketListingsFor(conn: DbConnection | null) {
  if (!conn) return [];
  // Own escrow can also be on the public page. Show each listing once.
  return [
    ...new Map(
      [
        ...(pages.get(conn)?.rows ?? [...conn.db.marketPageListings.iter()]),
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
