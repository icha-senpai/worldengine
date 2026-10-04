# Generated bindings

Run `npm run bindings` from the repository root after every schema change.
It builds the WASM module and regenerates TypeScript here and Rust in
`crates/game-client/src/module_bindings`. Do not edit generated source by hand.
CLI, module crate, Rust SDK, and TypeScript SDK are pinned to 2.10.2.
