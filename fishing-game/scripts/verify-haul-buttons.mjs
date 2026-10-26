import { requireRustWorkflow, runBuildCommand, rustTargetPath, stageRustArtifacts } from './rust-build.mjs';

requireRustWorkflow();
for (const args of [
  ['fmt', '--all'],
  ['test', '-p', 'discord-bot', '--release', '--locked'],
  ['build', '-p', 'discord-bot', '-p', 'account-link', '--release', '--locked'],
]) {
  const code = await runBuildCommand('cargo', args);
  if (code !== 0) throw new Error(`Cargo ${args[0]} failed (${code})`);
}
const suffix = process.platform === 'win32' ? '.exe' : '';
stageRustArtifacts(rustTargetPath(), [`release/discord-bot${suffix}`, `release/account-link${suffix}`]);
