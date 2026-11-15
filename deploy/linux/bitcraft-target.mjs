// The old world remains a separate, read-only deployment target. A verified
// provisioning result opts the managed updater into the release-world database.
export function withBitcraftTarget(release, target) {
  if (!target) return release;
  if (target.database !== 'space-bitcraft-release' || target.source !== 'space-bitcraft-tools' ||
      target.world !== 'release' || !/^[a-f0-9]{64}$/.test(target.identity))
    throw new Error('Invalid verified BitCraft release target.');
  const original = release.modules.find(module => module.key === 'bitcraft');
  if (!original || original.database !== target.source) throw new Error('Unexpected legacy BitCraft target.');
  return { ...release, modules: release.modules.flatMap(module => module.key === 'bitcraft'
    ? [{ ...module, database: target.database, identity: target.identity },
       { ...module, key: 'bitcraftLegacy', archived: true, hooks: [] }]
    : [module]) };
}
