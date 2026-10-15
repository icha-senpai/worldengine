import { createHash } from 'node:crypto';

// Conservative dependency groups: a shared change may rebuild extra components.
export const groups = {
  bitcraft: ['space/bitcraft/', 'space/package'],
  evergather: ['space/spacetimedb/', 'space/package'],
  fishModule: ['fishing-game/spacetimedb/', 'fishing-game/crates/game-rules/', 'fishing-game/content/', 'fishing-game/Cargo.', 'fishing-game/rust-toolchain'],
  collector: ['space/scripts/', 'space/src/bindings/bitcraft/', 'space/bitcraft/src/', 'space/package'],
  native: ['fishing-game/crates/', 'fishing-game/content/', 'fishing-game/Cargo.', 'fishing-game/rust-toolchain'],
  spaceWeb: ['space/'],
  fishWeb: ['fishing-game/apps/', 'fishing-game/packages/', 'fishing-game/assets/', 'fishing-game/content/', 'fishing-game/package', 'fishing-game/scripts/asset-manifest.mjs'],
  services: ['deploy/linux/install-user-services.mjs'],
};
export function sourceFingerprint(files, prefixes, extra = '') {
  const hash = createHash('sha256').update(extra);
  for (const [path, bytes] of [...files].sort(([a], [b]) => a.localeCompare(b))) {
    if (prefixes.some(prefix => path.startsWith(prefix))) hash.update(path).update('\0').update(bytes).update('\0');
  }
  return hash.digest('hex');
}
export function deploymentPlan(current, previous = {}, force = false) {
  const plan = Object.fromEntries(Object.keys(groups).map(key => [key, force || current[key] !== previous[key]]));
  return includeDependencies(plan);
}
export function includeDependencies(plan) {
  plan.collector ||= plan.bitcraft;
  plan.native ||= plan.fishModule;
  plan.spaceWeb ||= plan.bitcraft || plan.evergather;
  plan.fishWeb ||= plan.fishModule;
  return plan;
}
export function publishArguments(module) {
  if (!['js', 'wasm'].includes(module.kind) || ![3100, 3127].includes(module.port)) throw new Error('Invalid module target.');
  return ['publish', module.database, module.kind === 'js' ? '--js-path' : '--bin-path', module.artifact,
    '--server', `http://127.0.0.1:${module.port}`, '--delete-data=never',
    '--yes=remote,migrate,break-clients,skip-login', '--no-config'];
}
