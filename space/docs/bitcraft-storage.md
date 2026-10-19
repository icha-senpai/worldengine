# Keeping BitCraft on SpacetimeDB within a small disk budget

## Calendar XP

The XP tracker shows Today, Yesterday and This Week in America/Chicago time,
with Monday-start weeks. Selected characters retain an eight-day collection
lease after their last visit; the native collector's 30-character admission
limit is unchanged. This does not subscribe to every player's XP.

Calendar baselines use `collection_sample` rows under a separate `dailyXp|`
scope, storing opening/latest per-skill XP once per minute, one row per observed
character/day. They are retained for 35 days within the existing 6,000-row cap;
short rate samples are evicted before calendar rows under pressure. Existing
maintenance export/restore includes them without a schema or binding change.
The six-hour rate-history window remains unchanged.

First observations and unobserved day boundaries are marked Partial. Adjacent
observations within two minutes of Central midnight split at that observation,
so covered periods are approximate, not exact midnight measurements. Backward,
missing-skill and out-of-order snapshots cannot increase recorded gains.
No historical full-day values are fabricated. Normal collection must remain
available; an expired lease or admission limit can leave periods partial.

Updated October 14, 2026. SpacetimeDB 2.12.0 remains the only application database. No SQLite service or secondary database was introduced.

Boss opts out of automatic deployment backups and retained rollback sets. Git provides source history. Routine work leaves no dated backups, previous builds or ad hoc audit reports. Database maintenance uses a temporary verified export to transfer live application state; successful restoration removes it. An unfinished rebuild retains its required export until recovery succeeds. Current bindings, sealed module bundles, collector identities, bounded service logs and health/crash status are operational files and remain protected.

The incident was primarily accumulated transaction history: about 61.5 GB of commit logs and 7.9 GB of snapshots in the BitCraft replica. Deleting expired application rows does not reclaim that history. [SpacetimeDB documents its uncompacted commit log here](https://spacetimedb.com/docs/reference/internals/commitlog/).

## What is stored

| Data | Retention |
| --- | --- |
| Boss encounter details | Up to 30 days / 20,000 encounters / 32 MB conservative accounting; all four boss tables are checkpointed and verified during maintenance |
| Boss leaderboard totals and coverage | Compact totals survive encounter expiry; player/boss scores share a 40,000-row budget, with recording paused visibly if exhausted. See [boss recording](boss-recording.md) |
| Guides, drafts, guide metadata, administrator grants | Preserved and verified during every rebuild |
| Widget profiles, ownership and settings | Preserved and verified, including existing share links |
| Private provider configuration | Preserved; excluded from public status output |
| XP samples | Existing six-hour pruning and 6,000-row shared capacity; preserved during rebuilds |
| XP watches and latest XP feeds | Preserved so collection can resume for players with background tabs |
| Market item directory, item/cargo books, summaries | Latest state checkpointed and verified during maintenance; regional sources reconcile afterward |
| Regional market books and barter stalls | Individual order/exchange records; small book/stall headers and shared claim/owner labels. Updates send changed orders and cancellations. Latest state is checkpointed. |
| Inventory, craft and API response caches | Existing bounded scopes and TTLs; rebuilt as needed |
| Raw relay tables and frames | Memory only; no archived raw frame history |
| Claim ownership/member/supply events | Up to 30 days, newest 50,000 events globally, and 50 million payload characters; preserved in maintenance checkpoints |
| Claim baseline and coverage | Current qualifying owned claims in the nine active regions; at most 20,000 rows / 20 million payload characters; preserved in maintenance checkpoints |
| Claim supply status | One compact current supply/upkeep record per tracked claim, capped at 20,000 rows; preserved in maintenance checkpoints |
| Old collector-side checkpoints | Removed; latest market preservation uses the verified maintenance checkpoint instead |

Market filtering, sorting before pagination, targeted item refreshes and regional coverage continue to use the same frontend contracts. A whole-region publication is required once per subscription generation. Subsequent publications contain changes and cancellations. Merely changing source row iteration order does not produce a publication. Directory membership uses a stable generation marker: the five-minute directory check changes existing item records only when their data changes.

## Disk management

### Claim history

`/bitcraft/claim-history` records meaningful changes only for **T4+ claims with both a regular waystone and a functional bank**. Town Banks and Ancient Banks qualify; ruined waystones and bank decorations do not. Tier and building changes explicitly start or stop tracking, including on reconnect, without inventing claim creation/removal. The final transaction of a qualifying claim remains evidence even if that transaction removes a required building. Neutral structures and removed island regions are excluded.

The relay still supplies discovery state so newly eligible claims can enter automatically. Unqualified claims do not receive persistent baselines or new history. Lower-tier/unequipped metadata is sent once during reconnect reconciliation to distinguish a downgrade from disappearance. Building subscriptions are filtered to the three qualifying building definitions. Existing history is preserved under its original retention limits; older events appear for claims whose qualification is established by the new baseline or retained eligibility evidence. Legacy baselines without structure counts gain the new criteria without fabricating hundreds of tracking-start events. The collector compares whole transactions, does not merge successive changes, and retains no raw frame archive. Routine supply decreases replace only the compact current supply status; they do not create timeline events. Unchanged status does not write another row.

The tracked list shows current supplies, hourly upkeep, remaining time and an estimated run-out date, sorted by expiry by default. Filters cover region, claim/owner, low supplies, observed empty, stale readings and unknown estimates. Clicking a claim opens tabs for all history, ownership, members, supplies, claim changes and recording gaps; Recent changes retains access to claims that left tracking. Upkeep uses the native tile-cost thresholds, claim shape, building maintenance and upkeep interval. Run-out uses learned regional decay timing when available, with the hourly-rate estimate as a fallback described below. A passed estimate does not prove the claim expired. Missing inputs remain unknown; disconnected or unreconciled generations are marked stale. Supply status is removed when its claim leaves tracking.

The first snapshot establishes a baseline, not invented historical claim creations. Every later connection generation records a coverage gap and reconciles saved rosters/owners. Reconciled changes have uncertain timing and never receive a possible outsider takeover flag. Live flags require a changed owner absent from the preceding roster and known non-positive supplies. They do not establish intent; member departures do not distinguish leaving from removal. Times are observation times, and preceding roster evidence contains bounded samples rather than unlimited full copies.

History is private at the table level and read through a bounded, paginated public procedure. Only authorized collectors with a valid storage lease can ingest it. Sequence checks reject missing/out-of-order batches and deduplicate retries. Each regional queue is capped at 16 MB / 2,000 batches; publication failure retires the collection cycle so the next baseline exposes the gap. Collection heartbeats prune expired history even when the region has no meaningful changes. Age, count, or payload limits can shorten the available history; the UI reports discarded events per region.

All three `claim_history_*` tables and `claim_supply_status` are included in the existing chunked maintenance checkpoint and explicit table policy. The logical limits are not physical disk quotas; transaction logs and snapshots remain under the existing guard below. The October 14 source check found 823 qualifying claims among 1,829 owned claims, with upkeep available for every qualifying claim. That is a starting-state measurement, not a long-term growth forecast.

Use `npm run bitcraft:claims-status` for recording coverage, `npm run test:claims-live` for a read-only source/schema check, and `npm run test:claims-server` for isolated additive migration and maintenance preservation checks. Browser fixture databases are disposable and must be removed with the existing identity-verified test cleanup helper immediately after verification.

Personal claim stars are browser-local preferences, capped at 200 per database. The public `read_starred_claims` procedure reads only the supplied shortlist, current status and the latest retained event for each selected claim. It writes no tables, skips regional recording events for update badges, and returns unavailable claims without silently dropping their stars. Current-value differences and events newer than the user's last viewed marker produce update indicators while the page polls every 15 seconds. The shortlist does not expand history collection beyond the qualifying claim rule.

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
8. Update the replica binding, remove completed retained-state exports and reconnect the collector. Latest market orders and barter remain available; relays reconnect and reconcile. Other rebuildable feeds reload. Recovery removes its temporary export after the same preservation checks succeed.

Updates pause during this process. Existing market and barter records are restored before collection resumes. Coverage reports reconnecting until new regional generations reconcile; retained records remain available throughout that gap. XP history is retained, but a new source generation still follows the existing continuity rules; it must not fabricate gains across a gap.

This is a monitored application budget with substantial headroom, **not a native filesystem quota**. SpacetimeDB has no total-disk ceiling setting. In-flight transactions and engine snapshots can consume headroom between measurements; a literal physical guarantee against every fault requires an OS-enforced quota or fixed-capacity volume. The manager stops renewing writes rather than continuing collection after a failed preservation/recovery step. Evergather, unmanaged databases and pre-existing whole-server backups are outside these BitCraft budgets and are not reset by them.

### Regional decay estimates

Regional timing is learned from upkeep-sized supply decreases across at least three distinct tracked claims within 90 seconds. Refills, changed upkeep/intervals, delayed observations, duplicates and reconnect snapshots cannot confirm a tick. One private `claim_decay_region` row per current region retains the last confirmed observation and at most 32 candidate claim IDs. Existing subscriptions already observe tracked claims; learning adds no polling or relay connections, and ordinary drops still do not add history events.

Confirmed timing aligns run-out to whole decay ticks, accounting for rounded per-tick upkeep. Treasury-only updates within a decay interval do not slide the run-out time forward. Timing must match the current recording generation and interval, with a fresh heartbeat and a confirmation no older than two intervals plus 90 seconds. Otherwise estimates use observation time plus supplies divided by hourly upkeep. The claim details show the next estimated tick and corroborating claim count when usable. Delivery delay and future upkeep changes still make these estimates approximate. `claim_decay_region` is included in the managed storage policy and chunked maintenance checkpoints.

Run `npm run test:claim-decay-server` after building/generating the module. It uses the existing sealed module and a disposable database to verify the additive migration, every retained field, native learning and maintenance restoration; successful checks remove their temporary database and artifacts.

## Main and test protection

### Folder cleanup and temporary databases

The October 12 folder audit found an old whole-server upgrade backup, timestamped Evergather test databases, orphan replicas, generated builds and old module versions outside the two BitCraft budgets. Cleanup retained all six persistent database identities and the October 3 whole-server rollback backup. It removed 25 temporary databases, seven replicas whose identities returned HTTP 404, generated frontend output and temporary tools/module sources. Module-file pruning was performed with the owned Space server stopped, retaining the initial and current module references for every persistent database and verifying retained file hashes before restart. Do not treat an owner database list alone as proof that an arbitrary replica or module is unused: all physical replicas must also be accounted for. Do not manually remove logs or snapshots belonging to a retained database.

The gameplay, catalog, economy migration and load-test scripts now use `runTemporaryDatabaseTest` from `scripts/temporary-database.ts`. It requires an unused timestamped test name, wraps setup as well as execution in cleanup, deletes through the database API/CLI, verifies HTTP 404, then removes only replicas with the matching snapshot identity. Persistent database names, mismatched identities and linked paths are rejected. Owned temporary module-source folders are also removed. Forced process termination can still bypass JavaScript cleanup, so periodic orphan audits remain useful. Historical published module files require a separate reference audit and offline cleanup; there is no automatic whole-folder size ceiling.

`scripts/sql-backup.ts` preserves exact CLI JSON bytes when an export is explicitly requested, avoiding formatting mismatches and large-integer rounding. Old Evergather exports and completed maintenance exports are disposable after their checks finish. The legacy economy migration test reconstructs its temporary source fixture from Git history and removes it afterward.

Preview Space's scoped artifact cleanup with `powershell -NoProfile -ExecutionPolicy Bypass -File scripts/cleanup-local-artifacts.ps1`. Stop only the owned main/test collectors with `scripts/collector-services.ps1` before using `-Apply`, then start them again. Cleanup refuses pending maintenance, linked paths and files referenced by active processes. It removes old backup folders, completed state exports, retired diagnostic archives, one-off runtime scripts/reports and browser/performance scratch. It preserves active database files, current builds, installed tools, credentials, identities, sealed modules, dependencies and requested design/PDF/research outputs. It saves no cleanup report.

`space-bitcraft-tools` runs the full relay/API collector. `space-bitcraft-checks`, used by the frontend on port 5181, runs the same storage monitor and verified maintenance code in `--storage-only` mode. That test worker measures storage and renews its short write lease, but does not establish duplicate global relay subscriptions or poll the market. Test UI requests can update the test database while its guard is healthy. If either worker stops, ordinary writes to its own database become blocked when the 20-second lease expires.

The workers have separate PID files, readiness files, logs, replica bindings and temporary maintenance exports. The Start/Stop/Restart launchers manage both. Publishing one BitCraft module stops and resumes only that database's worker, then seals the corresponding module artifact.

Manage just the test guard without starting a frontend:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/collector-services.ps1 -Action Start -Target Test
```

Use `Stop` or `Restart` for the other actions. Test module updates use `npm run bitcraft:publish-test`; they preserve application records. The test guard must be registered to the verified test replica before its first start. That registration is already installed locally. Both guards are ordinary local processes: a PC reboot stops them, and the launchers must be run again.

### Worker supervision

The existing collector service launcher starts a hidden supervisor for each target. If the worker exits unexpectedly, the supervisor records its PID and exit code and restarts it after five seconds. Repeated short failures back off to 10, 20, 40 and then 60 seconds; a worker that runs for at least two minutes resets the delay. Repeated Start calls reuse the same supervisor and worker, and can adopt a previously started owned worker. Test supervision retains `--storage-only`; it does not start another world collector.

Stop shuts down the supervisor before the worker, so a deliberate stop stays stopped. Restart uses the same sequence before starting a replacement. Process ownership checks and per-target mutexes prevent duplicate supervisors and avoid stopping unrelated processes. Restarted workers still perform the existing database identity, storage budget and lease checks before reopening writes. A pending rebuild remains blocked for manual recovery; supervision never attempts to bypass or repair it automatically.

Supervisor events are in `.runtime/services/collector.supervisor.log` and `collector-test.supervisor.log`, with bounded retention. Worker output still uses the timestamped collector logs. This covers worker exits, not hung workers or termination of the supervisor itself. It does not install a Windows boot service; after reboot, use the normal Start launcher.

`npm run test:collector-supervisor` checks adoption, repeated Start, an unexpected test-worker exit, authenticated replacement, deliberate Stop without respawn, and isolation from the main worker. `npm run test:storage-guards` checks that stopping supervision still lets the storage lease expire and blocks writes while retaining readable data.

### Collector crash diagnostics

Each worker writes under ignored `.runtime/diagnostics/<database>/`. `latest.json` records a 30-second aggregate sample: process/runtime versions, Windows release, RSS/heap/external memory, heap limit, available system memory, active resource counts, current collection/storage phases and event counters. `events.jsonl` records timestamped startup, failures, maintenance and trading publication activity. Player IDs, usernames, order bodies, API responses, credentials and arbitrary error messages are excluded; error summaries contain only type, safe code and stack locations.

The event log rotates across three files of approximately 2 MB each, archiving retired segments. On unexpected exit, the supervisor retains `exit.json` and two previous exit records, including the exit code in decimal/hex, the last sample for that PID and the last 20 event lines. Node fatal-error reporting is enabled before the worker loads, with environment and network-interface diagnostics excluded. Its fixed `fatal-report.json` filename avoids generating unlimited files; startup retains at most two previous raw reports and queues retired reports or reports exceeding 8 MB for verified compression. These reports contain runtime stacks/statistics, not full heap dumps. A report can temporarily exceed that limit while being written; report retention is enforced on restart.

Diagnostic files are included in each database's monitored storage usage. Logging failures do not replace the collector's existing error handling, and uncaught-exception monitoring does not suppress process termination. A native fast-fail or forced kill can bypass Node's report generation: the supervisor exit record and previously saved samples remain available, but a native stack is not guaranteed. These diagnostics do not alter relay subscriptions, reconnect payloads or database heartbeat intervals.

Focused tests cover redaction, log/report rotation, a real fatal heap failure producing a report, and preservation of diagnostic evidence through an unexpected supervised worker exit.

### Collector lifecycle safeguards

On October 11, 2026, an isolated localhost HTTP reproduction crashed the installed Node 24.15.0 executable with `0xC0000409`, subcode `FAST_FAIL_STACK_COOKIE_CHECK_FAILURE`. The dump, symbolized with the matching official Node PDB, traced `TCPWrap::Connect<sockaddr_in>` through `uv_tcp_connect` / `uv__tcp_connect` to `__report_gsfailure`. This matches [Node issue 63620](https://github.com/nodejs/node/issues/63620) and its linked [libuv initialization fix](https://github.com/libuv/libuv/commit/aabb7651de). The production collector's historical exits have the same exit code but no native stack, so the reproduction establishes a defect in its exact runtime, not retrospective proof of every collector exit.

ServBay's Node 24 package was updated to **24.21.0** (libuv 1.52.1), and both collectors were restarted using its normal `packages/node/current/node.exe` path. There is no collector-specific runtime override. ServBay initially failed on the locked executable; the old file was preserved at `C:\ServBay\backup\node-24.15.0-pre-update-20261011.exe` before the successful ServBay install. Other already-running Node processes can retain the old executable until their own restart.

The updated runtime passed the 34 focused collector/storage tests and a 60-second run of the isolated reproduction without a native crash. That load run exhausted local connection capacity (13,758 completed requests, 34,962 connection errors); further repetitions were deliberately stopped, so this is crash-regression evidence rather than a throughput or long-term stability result. Live checks afterward confirmed nine regional feeds and fresh XP. The native dump and symbolized stack are private, ignored artifacts under `.runtime/diagnostics/collector-crash-probe/`.

A one-shot ProcDump monitor is attached to the current main worker after the update. `.runtime/diagnostics/space-bitcraft-tools/native-capture.json` records its PID and target. It captures one triage dump on an unhandled exception or termination, with a 10-second capture timeout, then exits; it does not follow subsequent worker restarts or write full-heap dumps. The dump is inside the managed diagnostics directory. Cancel an active capture before a deliberate worker stop with `.runtime/tools/procdump/procdump64.exe -cancel <verified-worker-pid>`. A termination dump alone does not establish a crash. Continue checking supervisor exits and long-run stability.

Native player admission retains at most 30 active players including pending discovery. Discovery has a 15-second deadline, expires with its watch, and cannot create a socket after cancellation. A discovery timeout retires the database connection through the normal reconnect cycle because the SDK cannot cancel an individual pending procedure. Native publication uses the collection cycle's deadlines and cancellation, including failure handling. Historical scheduler keys are pruned, and stopping releases subscriptions and cached tables.

Relay sockets use `ws` with a 64 MB transport frame ceiling, no per-message compression, and WebSocket ping/pong checks every 30 seconds. A missing pong closes the connection on the next check; quiet scopes that answer pings remain connected. These transport control frames do not call database reducers or change database heartbeat intervals. Snapshot frames are limited to 300,000 operations and five million decoded values; conservative wire-size accounting limits a scope to 256 MB and aggregate cached plus staged data to 768 MB. These are accounting limits, not an exact process heap quota. Over-budget frames are rejected before staging a complete decoded snapshot, and invalid caches are released.

The worker owns `worker.stdout.log` and `worker.stderr.log` in its diagnostics directory. It discards closed retired 2 MB segments and keeps current output bounded. It never truncates a live redirected output file. Legacy service output is pruned only before worker launch. Diagnostic cleanup is independent of the storage lease monitor, which tolerates replica files disappearing during normal retirement. Local authorization also has a 15-second CLI timeout.

The supervisor backs off and retries transient worker-launch/archive-preflight failures. Incomplete database rebuilds still block collection pending explicit recovery. Regression coverage includes admission changes, expired/hung discovery, late publication failures, lossless log rotation, healthy quiet sockets, dead transports, oversized/malformed frames, and a real failed test-worker preflight followed by recovery.

### Bounded diagnostics without history archives

Diagnostic logs rotate at their existing small limits (2 MB event logs and 2 MB worker output). Current event files, aggregate health and the small recent crash-report set remain available. Retired event segments, reports and worker output are discarded without creating compressed copies or sidecars.

Legacy `archive-*.gz` pairs are removed on worker startup, along with closed pending segments and abandoned temporary archive outputs. The compatibility archive helper retains zero archive pairs. Active SpaceTimeDB transaction logs are never compressed, moved or pruned by this feature.

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

API collection feeds remain valid for at least their configured source-cache duration, measured from the original upstream observation time. This prevents the hourly regions/levels checks from marking a valid 24-hour cache stale after three hours. The existing three-poll grace period still applies when it is longer; native relay freshness, collector heartbeat checks, failure reporting and polling intervals are unchanged. Cache reuse never advances the fetch timestamp.

Always-on API seeds are regions, empires and levels at their normal collection intervals. Worldwide claim and stall page scans are no longer seeds. Searches and fallback UI requests still create bounded watches; the collector refreshes those requested scopes. Existing old directory watches expire after their final consumer request. Claim/stall fallback API responses remain transient cache data rather than part of the maintenance checkpoint.

The market API directory is reduced to display metadata and order counts before caching. `market_meta` retains a small directory marker rather than another full response. Native market books store individual order rows in `trade_record`; claim labels/coordinates and owner names use shared `trade_reference` rows. Merged global books are reconstructed for reads instead of retaining a second native order union once all regions are present. Barter legs retain catalog IDs and quantities, and reconstruct descriptions from the bundled catalog.

Current payload ceilings are 8 million characters per API response, 32 million aggregate characters each for response caches and collected feeds, 64 million for large collection projections, and 64 million aggregate characters for canonical trading records/references. Unicode may consume more than one byte per character; these bounds supplement the independently measured physical disk budget. Individual trading records are limited to 64,000 characters and each trading table to 250,000 rows. Cache eviction excludes an in-progress refresh. Budget failures preserve the prior committed state.

Maintenance exports application state plus owner-only paginated latest-market tables. Pages target one million characters / 2,000 rows; an existing larger row can occupy its own page, bounded at eight million characters. The whole checkpoint is limited to 250 MB of serialized UTF-8 and 250,000 rows per table. Raw frames, API response caches and stale relay readiness are excluded. Unreferenced shared labels are omitted from checkpoints. Backup hashing, frozen writes, full retained-field comparison and interruption recovery cover both application and market data. A partial restoration remains paused; the original replica is preserved until all verification succeeds.

Validation includes `npm run test:refresh-cache-server` (uses the live HTTPS regions API), `npm run test:relay-server`, `npm run test:market-server`, and `npm run test:storage-server`. The cache check asserts unchanged payload rows and small actual commitlog growth; the storage check preserves a populated market and barter dataset through an interrupted rebuild.

### Observed validation on 8 October 2026

The main database completed a real maintenance rebuild with exact retained-state verification. The rebuild and verification stage took 38.9 seconds; export and service restart add to the total pause. It preserved 9,100 market items, 56,193 native orders, 16,887 barter orders across 1,304 stalls, all nine regional datasets, and the retained application records. Live checks afterward confirmed regional/global browsing, targeted item refresh, barter reads and fresh XP observations.

A six-minute live sample recorded 20.6 MB of transaction-log growth, including several larger bursts. This is a short operational observation, not a matched-activity before/after benchmark or a long-term growth guarantee. At the final audit, managed main-database storage was approximately 442 MB including retained backups. The 2 GB maintenance target and 3 GB monitored budget remain safeguards; they are not a native filesystem quota. Aggregate diagnostics are in `output/relay-storage-audit-20261008.json`, `output/relay-growth-steady-after-efficiency-20261008.json` and `output/relay-maintenance-after-efficiency-20261008.json`.
