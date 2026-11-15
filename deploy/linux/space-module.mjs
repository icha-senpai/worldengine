// Invoked with Space as cwd and its configured production environment.
import { pathToFileURL } from 'node:url';
import { resolve } from 'node:path';
const { readBinding, sealPublishedModule, storageUsage, STORAGE_TARGET } = await import(
  pathToFileURL(resolve('scripts/bitcraft-storage.ts')).href);
const { bitcraftDatabase } = await import(pathToFileURL(resolve('scripts/spacetime-config.ts')).href);

if (process.argv[2] !== 'seal') throw new Error('Choose seal.');
const database = bitcraftDatabase;
const binding = await readBinding(database);
if ((await storageUsage(binding)).bytes >= STORAGE_TARGET) throw new Error('Storage guard maintenance must finish before module activation.');
await sealPublishedModule(database);
console.log('BitCraft maintenance artifact sealed; database identity and storage budget verified.');
