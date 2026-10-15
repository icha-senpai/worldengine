import { execFileSync } from 'node:child_process';
import { lstat, unlink } from 'node:fs/promises';
import { resolve } from 'node:path';

// Code generators write into their usual paths. Compare to the pushed contracts
// and always return those owned paths to Git's original content, even on failure.
export async function checkedBindings(root, directories, generate) {
  const git = args => execFileSync('git', args, { cwd: root, encoding: 'utf8' });
  if (git(['status', '--porcelain', '--untracked-files=all', '--', ...directories]).trim()) {
    throw new Error('Generated client directories have local changes; preserving them.');
  }
  try {
    await generate();
    if (git(['diff', '--name-only', '--', ...directories]).trim()) {
      throw new Error('Generated clients differ from Git. Regenerate and commit the bindings locally, then push and run dataverse-update again.');
    }
  } finally {
    const created = git(['ls-files', '--others', '--exclude-standard', '-z', '--', ...directories]).split('\0').filter(Boolean);
    git(['restore', '--source=HEAD', '--worktree', '--', ...directories]);
    for (const name of created) {
      if (!directories.some(directory => name.startsWith(directory + '/'))) throw new Error('Unexpected generated cleanup path.');
      const file = resolve(root, name);
      const info = await lstat(file);
      if (!info.isFile() || info.isSymbolicLink()) throw new Error('Linked generated file refused.');
      await unlink(file);
    }
  }
}
