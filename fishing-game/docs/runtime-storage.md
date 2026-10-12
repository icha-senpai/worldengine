# Runtime programs and disposable files

Fishbound starts the Discord bot and account linker from `.runtime/bin`, using
the existing project working directory, environment files and credentials.
`scripts/install-service-binaries.ps1` copies built programs, checks their hashes,
and records them in `.runtime/bin/installed.json`. It refuses to overwrite an
installed program while that program is running.

After native source changes:

```powershell
cargo build -p discord-bot -p account-link --release --locked
.\close-fishbound.cmd
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/install-service-binaries.ps1
.\start-fishbound.cmd
```

`target`, `spacetimedb/target` and isolated native build targets are rebuildable
caches. They are not service installation directories. Removing them makes the
next build slower, but does not break the installed services. The website still
runs from `apps/web/build`; its dependencies remain in `node_modules`.

Keep these paths:

- `.local/spacetimedb`: the live database server's storage and keys.
- Root and website `.env`, service token files, achievement notification routes,
  current process/runner records and active logs.
- `.runtime/bin`, the deployed WASM in `.runtime/module`, and maintenance evidence.
- The latest two verified rollback sets in `.local/deployments`, with matching
  previous native programs, previous module and `previous-web-build`.
- Small historical migration inputs (`.local/pre-*`) needed by migration proofs.

Database backups can be compressed with
`python scripts/archive-deployment-backup.py <deployment-directory>`. It writes
`cold-data.partial.zip`, verifies every archived file against the original SHA256
manifest, and records `archive-verification.json`. Only after verification may
cleanup rename it to `cold-data.zip` and remove the uncompressed copy.

To restore a compressed rollback set, stop the owned Fishbound services and
database, extract `cold-data.zip` into a separate directory, verify extracted
files against `cold-data-manifest.json`, and restore that directory with its
matching previous module/client/website artifacts. Preserve JWT keys and record
the recovery window; restoring an older backup discards progress after its date.
Do not merge old storage into the current live directory.

`scripts/cleanup-local-artifacts.ps1` previews scoped cleanup. `-Apply` removes
confirmed workspace caches, scratch files, expired deployment copies and stopped
restore-test copies. It checks installed/running program identity, protects live
storage/configuration/dependencies, retains the newest two verified rollback
sets, preserves small verification reports, and refuses targets referenced by
active processes. Newly generated older-version fixtures should use `pre-*`
names if they must survive cleanup.

`scripts/cleanup-proof-databases.ps1` previews disposable `fishbound-proof-*`
databases on **3127 only**. `-Apply` deletes them through SpaceTimeDB's supported
command after checking each identity against the live identity. Capture and
compare live state around this maintenance while gameplay clients are stopped.
Neither helper operates on the neighboring Space server on 3100.

Both cleanup commands require explicit `-Apply`; ordinary Start, Close and
Restart never delete data or build caches. Maintenance evidence is recorded in
`.runtime/maintenance`. Source, original artwork, generated contracts and
non-scratch product outputs are retained.
