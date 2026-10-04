# Phase 0 proof status

The local trust-boundary proof, all 251 fish, and all seven biomes are implemented.
Live Discord delivery and real OAuth authorization remain exit checks.

- [x] Private player state and caller-scoped profile/inventory/collection/history.
- [x] Deployment-owner service grants with separate adapter/linker roles and audits.
- [x] Context RNG with checked integer sampling and fixed-point specimens.
- [x] Durable receipts, replay conflicts, concurrent first-cast and cooldown rejection.
- [x] Native Rust adapter and browser subscriptions use the same authoritative state.
- [x] Saved-result recovery after native reconnect; automatic reconnect on dropped transport.
- [x] Discord command deferral implemented before database calls.
- [x] OAuth code exchange, verified identity fetch, browser proof, CSRF cookie/state,
  expiry, one-use sessions, replacement/revocation, and unlinking implemented.
- [x] Private subscriptions reject unrelated callers; reconnect restores committed state.
- [ ] Verify actual Discord deferral, attachments, registration, and delivery retries.
- [ ] Verify actual Discord OAuth consent/callback with application credentials.

The last two checks require Discord credentials. Local OAuth HTTP fixtures and
native/module tests cover the protocol implementation but do not count as real
Discord verification. No commands have been registered against Discord.

See [verification](verification.md) and [setup](setup.md). Broader progression,
production launch, restore proof, and full V1 balancing remain later work.
