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
- [x] Verify registration: thirteen global commands, no duplicate server copies.
- [ ] Verify actual Discord deferral, attachments, button delivery and retries.
- [ ] Verify actual Discord OAuth consent/callback with application credentials.

The bot credentials are configured, the public OAuth callback is registered,
and the bot reaches Gateway readiness. The remaining checks require a real
signed-in player to invoke Discord and complete OAuth consent. Local HTTP and
native/module proofs do not count as that live acceptance test.

See [verification](verification.md) and [setup](setup.md). Broader progression,
production launch, restore proof, and full V1 balancing remain later work.
