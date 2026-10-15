import { access, mkdir, readFile, writeFile } from 'node:fs/promises';
import { execFileSync } from 'node:child_process';
import { homedir } from 'node:os';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { installUpdateCommand } from './install-update.mjs';

if (process.platform !== 'linux') throw new Error('Linux services only.');
const root = process.env.DATAVERSE_CHECKOUT || resolve(dirname(fileURLToPath(import.meta.url)), '../..');
if (/\s/.test(root) || /\s/.test(process.execPath)) throw new Error('Deployment paths must not contain whitespace.');
const space = resolve(root, 'space'), fish = resolve(root, 'fishing-game');
const binary = '/stdb/bin/2.12.0/spacetimedb-standalone';
await access(binary);
const keys = resolve(homedir(), '.config/spacetime-production');
const directory = resolve(homedir(), '.config/systemd/user');
await mkdir(directory, { recursive: true });
const header = '# Managed Dataverse user service.\n';
const unit = (description, working, command, { after = '', environment = '', condition = '' } = {}) => `${header}[Unit]
Description=${description}
${after ? `Requires=${after}\nAfter=${after}` : ''}
${condition ? `ConditionPathExists=${condition}` : ''}

[Service]
Type=simple
WorkingDirectory=${working}
ExecStart=${command}
${environment ? `EnvironmentFile=${environment}` : ''}
Environment=PATH=${dirname(process.execPath)}:/usr/local/bin:/usr/bin:/bin
Restart=on-failure
RestartSec=5
TimeoutStopSec=45
KillMode=control-group
UMask=0077

[Install]
WantedBy=default.target
`;
const units = {
  'dataverse-space-db.service': unit('Space production database', space,
    `${binary} start --data-dir=${space}/.runtime/data --jwt-key-dir=${keys} --listen-addr=127.0.0.1:3100`,
    { condition: `${space}/.runtime/data/control-db` }),
  'dataverse-space-collector.service': unit('Space BitCraft collector and storage guard', space,
    `${process.execPath} --import tsx scripts/bitcraft-collector.ts`,
    { after: 'dataverse-space-db.service', environment: `${space}/.runtime/production-collector.env` }),
  'dataverse-fish-db.service': unit('Fishbound production database', fish,
    `${binary} start --data-dir=${fish}/.local/spacetimedb --jwt-key-dir=${keys} --listen-addr=127.0.0.1:3127`,
    { condition: `${fish}/.local/spacetimedb/control-db` }),
  'dataverse-fish-web.service': unit('Fishbound production website', `${fish}/.runtime/published-web`,
    `${process.execPath} index.js`,
    { after: 'dataverse-fish-db.service', environment: `${fish}/.runtime/published-web/.env` }),
  'dataverse-fish-linker.service': unit('Fishbound Discord account linker', fish,
    `${fish}/.runtime/bin/account-link`,
    { after: 'dataverse-fish-db.service', environment: `${fish}/.local/production-services.env` }),
  'dataverse-fish-bot.service': unit('Fishbound Discord bot', fish,
    `${fish}/.runtime/bin/discord-bot`,
    { after: 'dataverse-fish-db.service', environment: `${fish}/.local/production-services.env` }),
};
for (const [name, text] of Object.entries(units)) {
  const path = resolve(directory, name);
  try {
    if (!(await readFile(path, 'utf8')).startsWith(header)) throw new Error(`Unmanaged unit preserved: ${name}`);
  } catch (error) { if (error.code !== 'ENOENT') throw error; }
  await writeFile(path, text, { mode: 0o644 });
}
const env = { ...process.env, XDG_RUNTIME_DIR: `/run/user/${process.getuid()}`,
  DBUS_SESSION_BUS_ADDRESS: `unix:path=/run/user/${process.getuid()}/bus` };
execFileSync('systemctl', ['--user', 'daemon-reload'], { env, stdio: 'inherit' });
await installUpdateCommand(root);
console.log('Installed six user services. No services were started or enabled.');
