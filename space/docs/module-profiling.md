# Module tests and subscription profiling

Run from `space`:

```powershell
npm test -- tests/collection-runtime.test.ts tests/evergather-market-runtime.test.ts
npm run typecheck:module-tests
npm run profile:modules
npm run profile:subscriptions -- 15
npm run test:read-model-server
```

The runtime tests use SpacetimeDB 2.12's native in-memory datastore and a deterministic clock. Upstream HTTP is mocked. They exercise real procedures, authorization, retry cooldowns, original fetch timestamps, unchanged payload rows, collector heartbeat expiry, market fees, escrow conservation, rejected writes, pagination and separate tab scopes. They create no server databases. The test TypeScript configuration resolves one SDK copy, matching the module-test plugin; the modules also have their own installed SDK copies for publishing.

`profile:modules` measures exported reads over synthetic in-memory state, with fixture setup outside the timed section. The market procedure includes its JSON serialization; view calls do not include subscription dependency tracking or transport serialization. None includes client rendering, network latency or fan-out. Its short benchmark windows are diagnostic samples, not capacity promises or pass/fail thresholds. Fixtures include 100/1,000 claims across nine regions and 100/1,000/5,000 market listings with the current catalog. Large market fixtures intentionally stress reads and are inserted directly into the disposable harness; they do not represent normal gameplay setup.

`profile:subscriptions` opens only public subscriptions against the existing local main BitCraft and Evergather databases. It calls no reducers or player-data procedures and creates no collection watches. Three sequential initial snapshots expose warm-up effects; measurements include a bounded 5–60 second observation window per database. Metrics are selected by verified database identity and discarded if the database program changes during measurement. View activity includes other connected clients. Decoded JSON byte counts are estimates of expanded data, not compressed WebSocket traffic. Connections close on completion or failure. Both commands print to the console and save no reports or fixture archives.

## Observations on October 15, 2026

These are local observations on the running 2.12 server, not a comparison against 1.12.

| Surface | Measurement | Implication |
| --- | --- | --- |
| BitCraft `claim_changes` | 90 evaluations / 245 ms computation during a 15-second window; initial snapshots 1.15–4.78 ms, nine rows / 1,478 decoded JSON bytes | The transmitted result is already small; repeated server scans are the useful optimization target. |
| Evergather core definitions | 164 rows / 102,249 decoded JSON bytes; initial snapshots 3.86–14.98 ms | Keep these shared and independent of player quantity updates. |
| Evergather inventory guide | 4,636 rows / 2,931,122 decoded JSON bytes; initial snapshots 56.4–127.06 ms | This is the largest observed snapshot. Keep it loaded only on demand; consider filtering/page contracts if real usage warrants it. |
| Evergather market | Current live market was empty | Synthetic large-market measurements below are separate from current activity. |

Short synthetic view-function benchmarks averaged approximately:

| View | Fixture size | Mean milliseconds |
| --- | --- | --- |
| `claim_changes` | 100 claims | 0.48 |
| `claim_changes` | 1,000 claims | 3.57 |
| `market_page_listings` | 100 / 1,000 / 5,000 listings | 0.76 / 7.64 / 34.35 |
| `market_summary` | 100 / 1,000 / 5,000 listings | 0.19 / 1.64 / 8.10 |
| `inventory_guide_definitions` | Current catalog | 23–25 |

### Applied read changes

The measurements above are the baseline before these changes. The local main/test BitCraft modules and Evergather module now use these read paths:

- `claim_changes` reads compact `claim_revision` records. Initial population is owner-only and repeatable; actual claim/supply edits, removals, reconnects and history pruning invalidate the affected region. Quiet recording heartbeats leave the feed alone. Checkpoint/restore preserves the projection alongside its source rows.
- Market totals use the datastore count API. New clients subscribe to a singleton `market_revision` and request only 50 listing records plus metadata through `read_market_listings`. A private B-tree ordering table supports newest-first cursor reads without shifting every listing's page rank. Cursor reuse requires the same revision; a stale cursor or direct page jump scans the ordering index to the requested offset. Existing listing records and legacy page views keep their contracts. Old clients retain their old view costs until reloaded.
- The Bag subscribes to an 838-item directory, searches the complete directory and progressively renders 100 matches at a time. `read_inventory_details` supplies selected sources, uses, metadata and recursive ingredient routes. Repair/tier-up recovery uses the same detail API, including newly selected nested ingredients, plus the shared commission feed for its Earn gold helper. Loading, errors and retry remain visible; quantity updates do not restart a detail request.

After activation, a separate 15-second live window recorded 94 `claim_changes` evaluations / 33.51 ms total computation (about 0.36 ms each), compared with the earlier 90 / 245 ms (about 2.72 ms each). These were separate activity windows, not a controlled load comparison. The inventory directory contained 838 rows / 233,133 decoded JSON bytes, about 92% smaller than the previous 2,931,122-byte guide snapshot. Initial snapshots took 6.02–29.47 ms, including warm-up. Market invalidation plus totals contained two rows / 54 decoded JSON bytes on the currently empty live market.

The populated server proof creates an owned temporary database from Git revision `06591f2e1b4464cb70d058d644d4499dbec65749`, seeds 5,000 listings (including timestamp ties), publishes the current module with `--delete-data=never`, and activates the index twice. It compares every original field across 18 tables and the database identity, verifies all 100 pages without duplicates/gaps, checks stale cursors and clamping, and reads nested inventory inputs. The latest local run measured 4.72 ms median / 7.05 ms p95 for repeated first-page procedure requests. The old initial 50-row subscription snapshot took 99.92 ms; setup and repeated requests measure different work and are not a like-for-like speedup ratio. Fixtures and their exact owned replicas are deleted in `finally`, including setup failures. Optional `--browser` holds the fixture for up to 15 minutes for a local UI check.

The native tests resolve the SDK sources together because the 2.12 published harness bundle embeds a separate `Range` class. Its portable datastore binary still comes from the installed package. This prevents false empty range scans caused by cross-bundle `instanceof` checks; the host proof independently verifies the real compiled module.

Short post-change native benchmarks measured approximately 0.04 ms for the nine-row claim feed, 0.02–0.04 ms for market totals, and 1.10 / 1.40 / 2.88 ms for the indexed first page at 100 / 1,000 / 5,000 listings. These remain synthetic diagnostics with the limitations described above.

Live activation compared canonical retained fields before and after while the two owned collectors were paused. Both BitCraft identities/replicas and the Evergather identity remained unchanged; all retained-field hashes matched. Only derived read records were added. The Evergather comparison excluded ephemeral per-connection UI scopes/sessions. The collectors resumed after verification. No deployment backup or dated diagnostic archive was retained.
