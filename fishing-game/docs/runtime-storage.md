# Runtime programs and disposable files

Fishbound starts the Discord bot and account linker from `.runtime/bin`, using
the existing project working directory, environment files and credentials.
`scripts/install-service-binaries.ps1` copies built programs, checks their hashes,
and records them in `.runtime/bin/installed.json`. It refuses to overwrite an
installed program while that program is running.

After native source changes:

```powershell
npm run rust:build
.\close-fishbound.cmd
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/install-service-binaries.ps1
.\start-fishbound.cmd
```

Rust build and test commands now run through `scripts/rust-workflow.mjs`. Each
workflow gets its own `.local/rust-build-*` target directory, with incremental
compilation and Rust debug information disabled. The wrapper waits until the
whole workflow has finished using its output, then runs Cargo cleanup in a
`finally` block. Failure and handled interruption also clean up. Concurrent
workflows use separate directories.

Use `npm run rust:build` for service binaries, `npm run module:build` for WASM,
`npm run bindings` for both generated contracts, and the normal `rust:check`,
`rust:test`, `test:module`, `test:integration` and `test:quests` commands. Other
Cargo commands use `npm run rust:run -- <cargo-command> <args>`. Rust proof scripts
use `npm run rust:script -- scripts/<name>.ts`; they reject a direct unwrapped
invocation before creating build output. Nested proof/codegen steps share their
parent workflow's target until all consumers finish.

Only installable outputs survive: a successful service build replaces the two
candidate executables in `.local/rust-artifacts`, and a module/bindings build
replaces `fishing_game_module.wasm` there. These are bounded build products, not
backup sets. The installer reads those candidate executables by default. Build
commands do not install, publish, restart services or change the live database.
Migration proofs read the current candidate WASM, so run `npm run module:build`
or `npm run bindings` before using a proof that needs the current module.

Direct Cargo commands can still create `target`/`spacetimedb/target`; use the npm
entry points to get cleanup. An OS crash or forced termination of the wrapper
can leave an isolated target behind for the scoped cleanup helper to review.
MSVC may emit temporary linker PDBs even with Rust debug information disabled;
those are removed with the rest of the target. The website still runs from
`apps/web/build`, and its dependencies remain in `node_modules`.

Keep these paths:

- `.local/spacetimedb`: the live database server's storage and keys.
- Root and website `.env`, service token files, achievement notification routes,
  current process/runner records and active logs.
- `.runtime/bin` and the currently deployed WASM in `.runtime/module`.
- `.local/rust-artifacts`: the latest small candidates awaiting install/publication.
- Small historical migration inputs (`.local/pre-*`) needed by migration proofs.

Boss has opted out of automatic deployment backups. Git provides source history.
Do not retain deployment folders, previous builds, database copies, recovery
archives or ad hoc deployment reports. Create a backup only when explicitly
requested. Git does not contain the runtime database's player progress.
Use temporary verification files only when needed and remove them afterward.

`scripts/cleanup-local-artifacts.ps1` previews scoped cleanup. `-Apply` removes
confirmed workspace caches, scratch files, expired deployment copies and stopped
restore-test copies. It checks installed/running program identity, protects live
storage/configuration/dependencies and refuses targets referenced by active
processes. Deployment copies and ad hoc maintenance reports are disposable.
Preserve test fixtures that existing migration proofs require; do not use
`pre-*` names to retain new deployment backups.

Cleanup protects the database supervisor's config and process state alongside
the website, linker and bot. It reviews individual files before deletion,
rejects filesystem links and changed files, and removes only empty directories.
Run `powershell -NoProfile -File scripts/cleanup-local-artifacts.test.ps1` to
exercise these guards without touching deployment or database data.

`scripts/cleanup-proof-databases.ps1` previews disposable `fishbound-proof-*`
databases on **3127 only**. `-Apply` deletes them through SpaceTimeDB's supported
command after checking each identity against the live identity. Capture and
compare live state around this maintenance while gameplay clients are stopped.
Neither helper operates on the neighboring Space server on 3100.

Both cleanup commands require explicit `-Apply`; ordinary Start, Close and
Restart never delete data or build caches. The cleanup preview is temporary and
is removed after successful application. Source, original artwork, generated contracts and
non-scratch product outputs are retained.
