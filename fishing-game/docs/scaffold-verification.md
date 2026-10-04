# Scaffold verification — October 3, 2026

Historical record of initial scaffold checks. Subsequent implementation and
current proof results are in [verification.md](verification.md). Statements
below about missing gameplay describe that earlier scaffold only.

- `npm install`: dependencies installed; audit reported zero vulnerabilities.
- `npm run content:validate`: ten tiers in the specified order; weights sum to 1,000,000.
- `npm run check`: zero errors, zero warnings, including the generated TypeScript export.
- `npm run build`: production build succeeded. Adapter-auto awaits a deployment target.
- `cargo check --workspace --locked`: native crates and generated Rust client compiled.
- `npm run module:build`: Rust module compiled for wasm32-unknown-unknown.
- `npm run bindings`: real Rust and TypeScript contracts generated from compiled WASM.
- Rust formatting checks passed with the pinned toolchain.
- All 251 sprites and 10 cards match their original ZIP entries by SHA-256.
- The initial copied design specification matched the supplied file by SHA-256.
  The local copy was subsequently updated to the owner's 60-second cooldown;
  the original Desktop file remains unchanged.
- Production preview returned HTTP 200 for the starter page and all 261 PNGs;
  image content types and served SHA-256 hashes matched the manifest.

These checks verify scaffolding and asset delivery. No gameplay, authentication,
private-view authorization, or replay/concurrency acceptance tests exist yet.
No live database was published and no Discord commands were registered.
