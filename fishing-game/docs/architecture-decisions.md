# Architecture decisions

1. Follow the asynchronous collection-game architecture: Rust authority, Rust
   Discord adapter, SvelteKit companion. A canvas engine is unnecessary.
2. Pin the CLI, module crate, native SDK, and npm SDK to SpaceTimeDB 2.10.2.
   Build the module separately for WASM; native checks cover rules and services.
3. Use Serenity 0.12.5 with Poise 0.7.0. Poise's optional cache/chrono defaults
   are disabled; panic handling is enabled. Read interaction IDs directly from
   application-command context. Register only implemented slash handlers, and
   only when DISCORD_REGISTER_COMMANDS=true in the configured service.
4. Use a separate Axum account-link service. Discord secrets never reach PUBLIC_
   settings. Request only identify, exchange the code server-side, fetch /users/@me,
   then pass the verified user ID to the restricted module linker.
5. Capture the publishing principal from init's sender as deployment owner.
   Scheduled reducers instead require sender == database_identity. These are
   distinct identities. Owner-controlled DiscordAdapter and AccountLinker grants
   are separate; ordinary clients cannot create their own role.
6. Keep raw accounts, wallet, identities, inventory, receipts, nonce proofs, and
   service grants private. Public caller-scoped views use sender identity and
   indexed player lookups. Public records omit raw Discord/guild identifiers.
7. Generate TypeScript in packages/generated and Rust in crates/game-client from
   the built WASM. The SDK skips raw private tables while generating scoped views
   and needed types. Never hand-edit generated bindings.
8. TypeScript reducer calls return awaitable promises. Native 2.10.2 transport
   uses generated *_then callbacks with nested completion results. Read confirmed
   result rows rather than trusting transport submission or inventing outcomes.
9. Adapter views select one player/request per service identity. Shared native
   transport serializes selection, mutation, and snapshot reading. Commands cap
   pending work at 32 and bound queue/reducer waits. Reconnect preserves service
   credentials and recovers casts through the same retained interaction ID.
10. Check a retained receipt before interaction freshness/cooldown. Canonical
    request mismatches reject; fresh new requests allow a five-minute age window
    and five seconds of clock skew. Accepted casts share a 60-second server cooldown.
11. Use server context RNG, unbiased integer weighted sampling, fixed-point u128
    intermediates, and final integer record comparison. Size grades follow final
    clamped measurements; condition changes weight. Stored specimens never reroll.
12. Browser challenges carry a random private u128 proof. OAuth binds a random
    state to a separate HTTP-only session cookie. Both expire in ten minutes.
    Only one browser identity maps to a player; verified replacement removes the
    old mapping and nonce. Matching completed links recover idempotently.
13. Inventory actions receive a module-issued two-minute quote bound to identity,
    player, operation, exact IDs, and payout. One quote per browser, one-second
    issuance limit, maximum 50 IDs, no duplicates. Commit rechecks all rows and
    favorite protection atomically. Sale retries cannot credit twice. Collection
    and historical record snapshots survive disposition.
14. Keep original artwork once in root assets; SvelteKit serves that static root.
    Bot responses attach original files. All 251 names/keys come from the sprite
    catalog; exceptional rank and rarity constraints override illustrative spec names.
15. All 251 species have one home biome across the seven specified destinations.
    Level cap is 60 so Glacial Reach and Abyssal Shelf are reachable. Seven rods
    are earned free at biome unlock levels and claimed once when equipped.
    Travel/equip and casts all check level and power; loadout changes keep cooldown.
16. Sample category, species, then a weighted physical size band. Each pool totals 1,000,000.
    All 249 ordinary species support every rank; exceptions permit only UUR/F.
    Public rank definitions total 2,492 physical bands. Draw integer length and weight,
    then classify the highest rank whose species-relative minimums both hold.
    Version 4 thresholds live in size-rules.json and public rarity definitions;
    UUR needs 1.85 times typical length and 6.331625 times typical weight.
    Weighted physical bands preserve the approved odds without an independent rank roll.
    Store the measured rank on each
    specimen, receipt, recent result, and record; species progress has ten counters.
    Rank rows determine XP and base value; discovery XP is once per species.
    Ordinary Abyssal UUR tickets are 256 each; Abyssal Fihs has 128 and F-rank Sock 64.
    Exact per-cast comparisons for every species/rank pair across every biome prove second-rarest/rarest order
    and both half-probability ratios. The reference tier table is historical.
    Both exceptional entries are bonus discoveries outside the 249-entry book.
    See world-balance.md for analytic wait quantiles and current prototype tuning.
17. SvelteKit 3 declares public runtime variables through src/env.ts and imports
    them through $app/env/public. Only service URLs/database name are public;
    TLS is required outside loopback development.
18. Every integration run publishes a fresh fishbound-proof-* database on the
    isolated 127.0.0.1:3127 instance. Existing databases and sibling servers remain
    untouched. Indexed scheduled cleanup handles bounded retention work; long-age
    expiry and restore correctness still need staging verification.

The owner expanded the original scaffold request into autonomous implementation.
All content and local progression selection are verified; production and complete V1 are not claimed.
