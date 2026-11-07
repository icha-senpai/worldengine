import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { projectRoot, withRustBuild } from '../scripts/rust-build.mjs';

await withRustBuild(async ({ env }) => {
  const result = spawnSync(process.execPath, [
    '--import', 'tsx', fileURLToPath(new URL('./live-calendar-proof.ts', import.meta.url)),
  ], { cwd: projectRoot, env, stdio: 'inherit', windowsHide: true });
  if (result.error) throw result.error;
  process.exitCode = result.status ?? 1;
});
