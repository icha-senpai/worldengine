# Keeping BitCraft on SpacetimeDB within a small disk budget

Updated October 8, 2026. SpacetimeDB 2.10.2 remains the only application database. No SQLite service or secondary database was introduced.

The incident was primarily accumulated transaction history: about 61.5 GB of commit logs and 7.9 GB of snapshots in the BitCraft replica. Deleting expired application rows does not reclaim that history. [SpacetimeDB documents its uncompacted commit log here](https://spacetimedb.com/docs/reference/internals/commitlog/).

## What is stored

| Data | Retention |
| --- | --- |
| Guides, drafts, guide metadata, administrator grants | Preserved and verified during every rebuild |
| Widget profiles, ownership and settings | Preserved and verified, including existing share links |
| Private provider configuration | Preserved; excluded from public status output |
| XP samples | Existing six-hour pruning and 6,000-row shared capacity; preserved during rebuilds |
| XP watches and latest XP feeds | Preserved so collection can resume for players with background tabs |
| Market item directory, item/cargo books, summaries | Latest state checkpointed and verified during maintenance; regional sources reconcile afterward |
| Regional market books and barter stalls | Individual order/exchange records; small book/stall headers and shared claim/owner labels. Updates send changed orders and cancellations. Latest state is checkpointed. |
| Inventory, craft and API response caches | Existing bounded scopes and TTLs; rebuilt as needed |
| Raw relay tables and frames | Memory only; no archived raw frame history |
| Old collector-side checkpoints | Removed; latest market preservation uses the verified maintenance checkpoint instead |

Market filtering, sorting before pagination, targeted item refreshes and regional coverage continue to use the same frontend contracts. A whole-region publication is required once per subscription generation. Subsequent publications contain changes and cancellations. Merely changing source row iteration order does not produce a publication. Directory membership uses a stable generation marker: the five-minute directory check changes existing item records only when their data changes.

## Disk management

The manager measures each managed BitCraft database's active replica (logs, snapshots and module logs), its retained-state backups, sealed module artifacts and any old collector checkpoints. The budget is **3,000,000,000 bytes per database**, with maintenance triggered at **2,000,000,000 bytes per database**. These are decimal GB. Main and test files are counted independently. Each worker's service logs are separately trimmed to sixteen files of at most approximately 1 MB each.

The collector checks size every second, serializes checks, and renews an owner-issued **20-second write lease** every five seconds. Every ordinary mutating BitCraft reducer and every mutating procedure transaction requires a current lease. If the collector/monitor exits or hangs, the lease expires and further writes fail. Read-only market, barter, guide and widget operations remain available. Low free disk space also pauses updates.

Maintenance explicitly interrupts outstanding collector requests. Disconnecting an SDK connection alone can leave a request promise pending, so the collector races requests against cycle cancellation and a 45-second timeout. It drains any active storage check before rebuilding, preventing an old monitor from renewing writes during maintenance. Late responses cannot continue publishing from the stopped cycle.

At the maintenance threshold:

1. Close the write lease and disconnect collection.
2. Check that every application table has an explicit retention policy. Unknown future tables prevent a reset.
3. Export retained records to a private file and verify its SHA-256 hash.
4. Publish the sealed module using SpacetimeDB's supported database reset. The database identity and name remain stable.
5. Restore application records in an owner-only atomic transaction, then restore the latest market checkpoint in bounded, idempotent owner-only chunks while ordinary writes remain paused.
6. Compare every retained field with the backup, including ownership, timestamps and private configuration.
7. Remove only the exact retired replica directory after successful verification. SpacetimeDB's reset creates a replacement replica but leaves the old files behind.
8. Keep the two latest retained-state exports, update the replica binding and reconnect the collector. Latest market orders and barter remain available; relays reconnect and reconcile. Other rebuildable feeds reload.

Updates pause during this process. Existing market and barter records are restored before collection resumes. Coverage reports reconnecting until new regional generations reconcile; retained records remain available throughout that gap. XP history is retained, but a new source generation still follows the existing continuity rules; it must not fabricate gains across a gap.

This is a monitored application budget with substantial headroom, **not a native filesystem quota**. SpacetimeDB has no total-disk ceiling setting. In-flight transactions and engine snapshots can consume headroom between measurements; a literal physical guarantee against every fault requires an OS-enforced quota or fixed-capacity volume. The manager stops renewing writes rather than continuing collection after a failed preservation/recovery step. Evergather, unmanaged databases and pre-existing whole-server backups are outside these BitCraft budgets and are not reset by them.

## Main and test protection

`space-bitcraft-tools` runs the full relay/API collector. `space-bitcraft-checks`, used by the frontend on port 5181, runs the same storage monitor and verified maintenance code in `--storage-only` mode. That test worker measures storage and renews its short write lease, but does not establish duplicate global relay subscriptions or poll the market. Test UI requests can update the test database while its guard is healthy. If either worker stops, ordinary writes to its own database become blocked when the 20-second lease expires.

The workers have separate PID files, readiness files, logs, replica bindings and backups. The Start/Stop/Restart launchers manage both. Publishing one BitCraft module stops and resumes only that database's worker, then seals the corresponding module artifact.

Manage just the test guard without starting a frontend:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/collector-services.ps1 -Action Start -Target Test
```

Use `Stop` or `Restart` for the other actions. Test module updates use `npm run bitcraft:publish-test`; they preserve application records. The test guard must be registered to the verified test replica before its first start. That registration is already installed locally. Both guards are ordinary local processes: a PC reboot stops them, and the launchers must be run again.

## Commands and recovery

Run from the `space` project:

```powershell
npm run bitcraft:storage -- status
npm run bitcraft:storage -- status space-bitcraft-checks
```

Normal Start/Restart launchers start the storage-aware collector. Module publication through `npm run bitcraft:publish` updates the sealed artifact after a successful publish. The launchers preserve the runtime directory. The storage manager may perform maintenance during startup if the measured threshold has already been reached.

For a deliberate rebuild, stop the collector first, run the rebuild, then restart it:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/collector-services.ps1 -Action Stop
npm run bitcraft:storage -- rebuild
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/collector-services.ps1 -Action Start
```

Private files live under ignored `.runtime/bitcraft-storage`. The database binding records its identity, active replica, artifact hash and any pending rebuild. Do not edit replica IDs or delete a binding to bypass a pending recovery. A failed restore preserves the original replica and the verified export. With collection stopped, run `npm run bitcraft:storage -- recover`. It verifies the backup hash and replacement identity, restores and compares retained records, finishes retirement, and leaves writes paused until collection restarts. It supports interruption after reset, after restore, or during retirement. Credentials, widget tokens and provider configuration must never be copied into diagnostics or committed.

When adding a durable table, add it to the retained-state exporter/restorer and the manager's table policy before publishing. The checks intentionally block automatic cleanup when an unknown table appears.

Validation includes unit checks for safe replica paths and minimal deltas, plus host tests for closed/expired leases, unauthorized exports, private-guide preservation, widget ownership after reset, stable database identity, exact state comparison, atomic failed restoration and actual disk reclamation. Relay host tests exercise patch updates, cancellations and malformed-patch rollback.

## Refresh and collection efficiency

Refresh leases, timestamps, retry state and feed failures use the small private `refresh_state` table. Unchanged successful API responses leave the public response payload row untouched. The collector hashes successful feeds and sends `touch_collection` for unchanged content, rather than submitting the full payload again. Reads combine payload and freshness, so cache expiry still advances. Native XP sampling intentionally continues to produce small observations for continuity.

Always-on API seeds are regions, empires and levels at their normal collection intervals. Worldwide claim and stall page scans are no longer seeds. Searches and fallback UI requests still create bounded watches; the collector refreshes those requested scopes. Existing old directory watches expire after their final consumer request. Claim/stall fallback API responses remain transient cache data rather than part of the maintenance checkpoint.

The market API directory is reduced to display metadata and order counts before caching. `market_meta` retains a small directory marker rather than another full response. Native market books store individual order rows in `trade_record`; claim labels/coordinates and owner names use shared `trade_reference` rows. Merged global books are reconstructed for reads instead of retaining a second native order union once all regions are present. Barter legs retain catalog IDs and quantities, and reconstruct descriptions from the bundled catalog.

Current payload ceilings are 8 million characters per API response, 32 million aggregate characters each for response caches and collected feeds, 64 million for large collection projections, and 64 million aggregate characters for canonical trading records/references. Unicode may consume more than one byte per character; these bounds supplement the independently measured physical disk budget. Individual trading records are limited to 64,000 characters and each trading table to 250,000 rows. Cache eviction excludes an in-progress refresh. Budget failures preserve the prior committed state.

Maintenance exports application state plus owner-only paginated latest-market tables. Pages target one million characters / 2,000 rows; an existing larger row can occupy its own page, bounded at eight million characters. The whole checkpoint is limited to 250 MB of serialized UTF-8 and 250,000 rows per table. Raw frames, API response caches and stale relay readiness are excluded. Unreferenced shared labels are omitted from checkpoints. Backup hashing, frozen writes, full retained-field comparison and interruption recovery cover both application and market data. A partial restoration remains paused; the original replica is preserved until all verification succeeds.

Validation includes `npm run test:refresh-cache-server` (uses the live HTTPS regions API), `npm run test:relay-server`, `npm run test:market-server`, and `npm run test:storage-server`. The cache check asserts unchanged payload rows and small actual commitlog growth; the storage check preserves a populated market and barter dataset through an interrupted rebuild.

### Observed validation on 8 October 2026

The main database completed a real maintenance rebuild with exact retained-state verification. The rebuild and verification stage took 38.9 seconds; export and service restart add to the total pause. It preserved 9,100 market items, 56,193 native orders, 16,887 barter orders across 1,304 stalls, all nine regional datasets, and the retained application records. Live checks afterward confirmed regional/global browsing, targeted item refresh, barter reads and fresh XP observations.

A six-minute live sample recorded 20.6 MB of transaction-log growth, including several larger bursts. This is a short operational observation, not a matched-activity before/after benchmark or a long-term growth guarantee. At the final audit, managed main-database storage was approximately 442 MB including retained backups. The 2 GB maintenance target and 3 GB monitored budget remain safeguards; they are not a native filesystem quota. Aggregate diagnostics are in `output/relay-storage-audit-20261008.json`, `output/relay-growth-steady-after-efficiency-20261008.json` and `output/relay-maintenance-after-efficiency-20261008.json`.
