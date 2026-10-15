# Boss recording and leaderboard

The public page is `/bitcraft/bosses`. The managed main BitCraft collector records continuously while the app's services are running, independently of browser tabs. The storage-only test worker does not open duplicate world subscriptions.

Tracked definitions are Feral Sentinel (32, T3 dungeon), Giant Skitch (36, T1 dungeon), and Araknir Nest (40, T7 dungeon). Dungeon trash types are excluded. Generic creature `tier: 1` values are not used to decide dungeon tier.

## What counts

One regional v2 SpacetimeDB subscription receives `enemy_state`, zero-health `health_state` rows, `contribution_state`, `player_username_state`, and insert-only `attack_event` rows. The official SDK applies complete transaction updates. A new boss zero-health row with a positive server `died_timestamp` confirms the defeat. Its cumulative, positive contribution entries are frozen at that transaction. Each contributor receives one participation, regardless of how many damage updates occurred. A confirmed player final blow requires the matching positive, non-dodged attack in that same transaction; the top contributor or last earlier attacker is never substituted for missing evidence.

Existing corpses in an initial/reconnect snapshot are excluded. Death identity is `(region, enemy instance, server death timestamp)`. The database makes retries idempotent and rejects conflicting evidence. Contribution resets and despawns alone award no defeats. The page reports observed participation, final blows and contribution as distinct metrics; contribution is game credit rather than raw damage or a percent.

The source schema is checked against `scripts/boss-wire-contract.json`. The SDK consumes the v2 event protocol; the v1 market/claim readers remain unchanged. The socket uses uncompressed frames, a 64 MB frame cap and ping/pong checks. Each region has a 200,000-row client-cache cap, and a bounded publication queue of 1,000 encounters / 8 MB. Raw combat frames, attacks, usernames and mob caches are not archived. Future schema changes require reviewing and regenerating the five selected contracts; do not disable compatibility checks to resume recording.

## Coverage and attribution

The same nine current mainland regions as the other collectors are used. Retired islands are excluded. Coverage is refreshed every 15 seconds and becomes stale after 70 seconds. Disconnects reset transient combat state, resnapshot before resuming, and expose recording gaps. Confirmed encounters already queued are retained across source reconnects until their database write is acknowledged. A process crash or unavailable collector can miss encounters; no lifetime or gap-free history is claimed. The relay has no historical event replay, and existing external contribution totals are not imported as confirmed boss defeats.

The leaderboard refreshes every ten seconds while its page is visible. Participant names are snapshots from the death record; a newer observed participation updates that player's displayed leaderboard name. No external account identity or alt-account inference is stored.

## Retention and maintenance

Private encounter details retain at most 30 days, 20,000 deaths, and 32 MB of conservatively accounted payloads (including a per-row allowance), whichever limit is reached first. Pruning runs hourly or when ingest crosses capacity. Compact all-boss/per-boss player totals survive detail expiry. Totals have a 40,000-row shared player/boss budget; hitting it stops publication with a visible recording error rather than silently dropping scores. Public rows contain only compact totals and regional recording status. Paginated procedures serve rankings and recent encounter summaries; full participants load on opening one encounter.

New packets may be at most one hour behind current server time. Records at or before the pruned-detail watermark are rejected, preventing replay from re-awarding totals after deduplication rows expire. A delayed recorder therefore exposes a gap rather than claiming complete history.

`boss_defeat`, `boss_score`, `boss_totals`, and `boss_region` are included in the existing paginated maintenance checkpoint and explicit managed-table retention policy. Restore converts persisted timestamps/counters back to their declared integer types. The existing 2 GB maintenance target, 3 GB monitored storage budget and disk-free guard remain enabled. Table bounds do not replace transaction-log maintenance.

## Verification and deployment

- `npx vitest run tests/boss-projection.test.ts tests/boss-history.test.ts`
- `npm run typecheck:scripts` and `npm run build`
- `npx tsx scripts/boss-server-test.ts` creates and removes its own disposable database. It checks additive migration from the currently sealed module, private/widget preservation, authorization, leases, duplicate retry, T3/T7 attribution and complete maintenance restoration. `--ui` additionally serves a ten-minute disposable fixture at port 5182; writing `.runtime/boss-ui-proof.stop` ends it and removes its database and marker files.
- `npx tsx scripts/boss-publish.ts` publishes only managed main/test BitCraft modules, pauses/resumes their existing workers, compares every retained field and preserves database identities. It seals the current module for maintenance without retaining routine deployment backups. Evergather is not published.

Browser fixture scores belong only to the disposable database. Production rankings start empty and accumulate observed boss defeats.
