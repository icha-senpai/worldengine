import { copyFile, lstat, mkdir, readFile, writeFile } from 'node:fs/promises';
import { homedir } from 'node:os';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const source = dirname(fileURLToPath(import.meta.url));
const header = '# Managed Dataverse update command.\n';
export async function installUpdateCommand(root) {
  if (process.platform !== 'linux' || /[\s'"`$\\]/.test(root)) throw new Error('Expected a simple Linux checkout path.');
  const active = resolve(homedir(), '.local/share/dataverse-update');
  await mkdir(active, { recursive: true, mode: 0o700 });
  if ((await lstat(active)).isSymbolicLink()) throw new Error('Linked updater directory refused.');
  const marker = resolve(active, '.managed');
  try {
    if (await readFile(marker, 'utf8') !== header) throw new Error('Unmanaged updater installation preserved.');
  } catch (error) { if (error.code !== 'ENOENT') throw error; }
  await writeFile(marker, header, { mode: 0o600 });
  // This is the single active installer, not a release archive. It bootstraps
  // the command before these source files have been committed and pushed.
  for (const name of ['update.sh', 'update.mjs', 'update-plan.mjs', 'bindings.mjs', 'publish.mjs', 'database.py',
    'release.json', 'space-module.mjs', 'install-update.mjs', 'install-user-services.mjs']) {
    const target = resolve(active, name);
    try { if ((await lstat(target)).isSymbolicLink()) throw new Error('Linked updater file refused.'); }
    catch (error) { if (error.code !== 'ENOENT') throw error; }
    if (resolve(source, name) !== target) await copyFile(resolve(source, name), target);
  }
  const bin = resolve(homedir(), '.local/bin');
  await mkdir(bin, { recursive: true });
  const command = resolve(bin, 'dataverse-update');
  try {
    if ((await lstat(command)).isSymbolicLink() || !(await readFile(command, 'utf8')).includes(header)) throw new Error('Unmanaged update command preserved.');
  } catch (error) { if (error.code !== 'ENOENT') throw error; }
  await writeFile(command, `#!/usr/bin/env bash\n${header}export DATAVERSE_CHECKOUT='${root}'\nscript="$DATAVERSE_CHECKOUT/deploy/linux/update.sh"\n[[ -f "$script" ]] || script="$HOME/.local/share/dataverse-update/update.sh"\nexec bash "$script" "$@"\n`, { mode: 0o700 });
  console.log('Installed ~/.local/bin/dataverse-update.');
}
if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  await installUpdateCommand(process.env.DATAVERSE_CHECKOUT || resolve(source, '../..'));
}
