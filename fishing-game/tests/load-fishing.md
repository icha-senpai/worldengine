# Fishing load proof

Run from `fishing-game`:

```sh
npm run test:load
# Optional bounded smoke run:
npm run test:load -- --stages=25
```

The runner builds the current WASM module and optimized Rust load driver through
the existing managed Rust workflow. It creates a new `fishbound-proof-load-*`
database on **127.0.0.1:3127**, refuses an existing database, and deletes only that
proof on completion or handled failure. It does not load production settings,
connect to Discord, copy player data, change cooldowns, or publish a website.
Managed build caches are cleaned afterward. One latest result replaces
`.local/load-test-results.json`; failures retain bounded diagnostic results in
that file rather than leaving a test database running.

## Workload

For 25, 100 and 250 distinct anglers, run a simultaneous burst and a second
scenario at ten requests per second. Each scenario uses new, linked accounts.
Every angler has an independent TypeScript SDK connection with the website's
actual core queries and one of camp, inventory, records or angler-directory
queries. All initial subscriptions and account links finish before measurement.

The native driver shares the production bot's admission limit and uses the real
`game-client::Client::player`, portrait sync, private player-selection lock,
reducers, receipt snapshots, catch-art worker pool/cache and rank-maximum
artwork. Game permits are released before artwork, as in the live handler.
Artwork failures are counted separately because the real bot can send a text
fallback after a committed catch. No PNG files or real Discord messages are
created. The 50ms acknowledgement and 250ms delivery delays are explicitly
synthetic; they do not simulate Discord rate-limit buckets.

The report distinguishes successful commands, expected busy responses, game
errors, artwork fallbacks, unexpected browser disconnects, and view mismatches.
It reports latency percentiles for game processing, artwork, overall synthetic
delivery, and request-to-private-player-view updates. That website measurement
includes the bot's selection queue and network/subscription processing, but not
DOM rendering. Windows runs also sample database/driver working sets and the
generator's memory/event-loop delay.

After each scenario, matching website accounts and inventory counts are checked.
Up to three accepted casts are replayed, then raced against two new requests;
the replay must preserve the catch and both new requests must reject on cooldown.
At completion, lifetime cast totals, command-receipt counts and permanent journal
pull counts must reconcile with the accepted results, without duplicate keys.

## Interpretation and limits

The 32-request cap intentionally rejects excess simultaneous game requests; a
busy response is capacity protection, not a lost or duplicated accepted catch.
Game errors, account mismatches, view failures or reconciliation failures require
investigation. Percentiles describe this workload and machine, not a production
capacity guarantee.

This is a fresh-account, first-cast workload with growing public directories.
It includes first-discovery and first-achievement work, but does not represent
mature inventories, every biome/gear combination, long-running retention, OAuth
provider latency, shared production traffic or an overnight soak. The SDK clients
are real subscribers, not 250 rendered browser tabs. Discord gateway traffic,
HTTP uploads, announcements and rate limiting require separate observations or
an explicitly authorized Discord test environment; they are not inferred from
the synthetic delays.
