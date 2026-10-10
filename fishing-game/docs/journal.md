# Permanent fishing journal

The Journal tab now keeps individual fish, rusted tin, and treasure results permanently, including fish that have been sold. It defaults to newest first and has date, rank, weight, length, and XP sorting in both directions, with name, biome, rank, and outcome filters. Length and weight use inches, pounds, and ounces. Pages contain 12 entries on desktop and 4 on phones.

`JournalEntry` is private, indexed by player, and separate from `OwnedSpecimen`, `RecentCatch`, and the seven-day retry receipts. Casting saves one immutable entry per pull after the full cast's XP has been allocated across pulls, in the same transaction as all catch rewards. Stable pull keys and the existing interaction replay guard prevent duplicate history. Sales and maintenance do not delete journal entries. Bait material bonuses are part of the cast's rewards rather than independent catch results.

`select_journal` validates a linked caller's selection. `my_journal` exposes only that caller's bounded page and totals. It never exposes another player's history or downloads the whole lifetime journal into the browser. Page sizes are capped at 50; search is capped at 80 characters. Sorting uses the actual rank order F through UUR and integer measurements, with stable ties and unknown values last in either direction. Non-fish outcomes have no length, weight, or fish rank. Out-of-range pages are clamped.

## Historical import

The owner-only `backfill_journal(player_id)` reconciles retained cast pulls, command receipts, recent results, and owned fish. It matches overlaps as a multiset, preserving identical double pulls. A per-player import marker makes repeated calls harmless. The import only changes journal tables: XP, balances, inventory, collection, records, cooldowns, and equipment stay untouched.

Old command receipts may hold aggregate XP for a multi-pull cast, so their XP is not substituted for an individual result's reward. Exact per-pull/recent XP is used when available. Inventory-only legacy fish show “Not recorded” for XP; recent-only entries without a biome snapshot show “Biome not recorded.” Already-pruned results and deleted sold fish cannot be reconstructed reliably from lifetime counters.

After publishing the additive module update, run `node scripts/backfill-journal.mjs fishbound-dev-local`. The script imports all current players. New casts are recorded immediately whether or not the old-history import has run.

## Verification

- `npm run test:integration` covers the existing gameplay contract plus journal privacy and revocation.
- `npx tsx scripts/journal-proof.ts` exercises legacy overlaps, duplicate pulls, unknown fields, exact per-pull XP, replay, sales, ten sort directions, filters, bounded pages, and history beyond the recent-result cap. It ages retry receipts and waits for the real production scheduler to prune them while the permanent journal remains intact.
- `npx tsx scripts/journal-migration-proof.ts` publishes the previous production artifact from `.local/pre-journal.wasm`, casts and links a browser, upgrades to the new module, imports history twice, and compares all 44 pre-existing tables.
- `node scripts/journal-preservation.mjs before fishbound-dev-local` / `after` checks that deployment and import preserve existing live gameplay data.

All gameplay tests use separate `fishbound-proof-*` databases. Test fixture reducers are never part of production WASM.
