import { spawnSync } from 'node:child_process';
import { cpSync, existsSync, lstatSync, mkdirSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { basename, dirname, join } from 'node:path';
import { projectRoot, requireRustWorkflow } from './rust-build.mjs';

function run(command, args, env, cwd, capture = false) {
  console.log(`[module-openssl] ${command} ${args.map(arg => JSON.stringify(arg)).join(' ')}`);
  const result = spawnSync(command, args, {
    cwd, env, windowsHide: true, encoding: 'utf8',
    stdio: capture ? 'pipe' : 'inherit', maxBuffer: 128 * 1024 * 1024,
  });
  if (result.error) throw result.error;
  if (result.status !== 0) {
    if (capture && result.stderr) process.stderr.write(result.stderr);
    throw new Error(`OpenSSL preparation failed: ${command} exited ${result.status}`);
  }
  return result.stdout;
}

function developerEnvironment(env) {
  const vswhere = join(env['ProgramFiles(x86)'] ?? 'C:/Program Files (x86)',
    'Microsoft Visual Studio', 'Installer', 'vswhere.exe');
  const installation = run(vswhere, [
    '-latest', '-products', '*', '-requires',
    'Microsoft.VisualStudio.Component.VC.Tools.x86.x64', '-property', 'installationPath',
  ], env, projectRoot, true).trim();
  if (!installation) throw new Error('Native module tests require MSVC build tools');
  const script = join(installation, 'Common7', 'Tools', 'VsDevCmd.bat');
  // Capture privately: the full developer environment can contain credentials.
  const result = spawnSync(env.ComSpec ?? 'cmd.exe', [
    '/d', '/s', '/c', `"call "${script}" -no_logo -arch=x64 -host_arch=x64 >nul && set"`,
  ], { env, encoding: 'utf8', windowsHide: true, windowsVerbatimArguments: true });
  if (result.error) throw result.error;
  if (result.status !== 0) throw new Error(`MSVC environment setup failed: ${result.stderr}`);
  const developer = {};
  for (const line of result.stdout.split(/\r?\n/)) {
    const separator = line.indexOf('=');
    if (separator > 0) developer[line.slice(0, separator)] = line.slice(separator + 1);
  }
  if (!developer.VCToolsInstallDir) throw new Error('MSVC environment lacks VCToolsInstallDir');
  return developer;
}

function firstObject(directory) {
  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    const path = join(directory, entry.name);
    if (entry.isFile() && entry.name.endsWith('.obj')) return path;
    if (entry.isDirectory()) {
      const found = firstObject(path);
      if (found) return found;
    }
  }
}

export function prepareModuleOpenSsl(env) {
  requireRustWorkflow();
  if (process.arch !== 'x64') {
    throw new Error('Provide OPENSSL_DIR for native module tests outside Windows x64');
  }
  // The pinned test SDK's prebuilt V8 requests LIBCMT. Match every target crate
  // and C dependency to its static CRT instead of mixing it with Rust's default.
  const rustflags = env.CARGO_ENCODED_RUSTFLAGS !== undefined
    ? env.CARGO_ENCODED_RUSTFLAGS.split('\x1f').filter(Boolean)
    : (env.RUSTFLAGS ?? '').split(/\s+/).filter(Boolean);
  env = { ...env, CARGO_ENCODED_RUSTFLAGS: [...rustflags, '-C', 'target-feature=+crt-static'].join('\x1f') };
  const metadata = JSON.parse(run('cargo', [
    'metadata', '--manifest-path', 'spacetimedb/Cargo.toml', '--features', 'module-tests',
    '--format-version=1', '--filter-platform', 'x86_64-pc-windows-msvc', '--locked', '--offline',
  ], env, projectRoot, true));
  const source = metadata.packages.find(pkg => pkg.name === 'openssl-src' &&
    metadata.resolve.nodes.some(node => node.id === pkg.id));
  if (!source) throw new Error('Locked native module graph lacks openssl-src');
  const features = metadata.resolve.nodes.find(node => node.id === source.id).features;
  if (features.some(feature => !['default', 'legacy'].includes(feature))) {
    throw new Error(`Unsupported OpenSSL source features: ${features.join(', ')}`);
  }
  const configure = join(dirname(source.manifest_path), 'openssl', 'Configure');
  if (!existsSync(configure)) throw new Error('Locked OpenSSL source is not cached');
  const work = join(env.FISHBOUND_RUST_TARGET_DIR, 'openssl-native');
  const build = join(work, 'build');
  const install = join(work, 'install');
  mkdirSync(build, { recursive: true });
  // Match openssl-src's in-source MSVC build, without changing the registry cache.
  cpSync(dirname(configure), build, {
    recursive: true, dereference: true,
    filter: path => basename(path) !== '.git' &&
      !(path.includes('cloudflare-quiche') && lstatSync(path).isSymbolicLink()),
  });
  if (source.version === '300.6.1+3.6.3') {
    // Normalize known diagnostics only in the disposable copy of this release.
    // qsort mutates the pointer arrays, not their const OSSL_PARAM objects.
    const edits = [
      ['providers/common/der/oids_to_c.pm', "        use re 'debugcolor';", ''],
      ['crypto/params_dup.c', 'qsort(list1, list1_sz', 'qsort((void *)list1, list1_sz'],
      ['crypto/params_dup.c', 'qsort(list2, list2_sz', 'qsort((void *)list2, list2_sz'],
      ['crypto/x509/x509_def.c', 'char *ossldir;', 'const char *ossldir;'],
      ['build.info', 'LIBDIR= INCLUDEDIR=include',
        'LIBDIR= libdir=. PKGCONFIGDIR=. CMAKECONFIGDIR=. INCLUDEDIR=include'],
      ['exporters/build.info', '"PREFIX=$(INSTALLTOP)" BINDIR=bin',
        'COMMENT="" "PREFIX=$(INSTALLTOP)" BINDIR=bin'],
    ];
    for (const [file, before, after] of edits) {
      const path = join(build, file);
      const text = readFileSync(path, 'utf8');
      if (text.split(before).length !== 2) throw new Error(`OpenSSL source differs: ${file}`);
      writeFileSync(path, text.replace(before, after), 'utf8');
    }
  }
  const developer = developerEnvironment(env);
  // Neither Cargo's DEBUG value nor GNU make flags configure this explicit build.
  delete developer.DEBUG;
  delete developer.MAKEFLAGS;
  const perl = env.OPENSSL_SRC_PERL ?? env.PERL ?? 'perl';
  const flags = [
    './Configure', 'VC-WIN64A', `--prefix=${install.replaceAll('\\', '/')}`,
    `--openssldir=${install.replaceAll('\\', '/')}/ssl`, '--libdir=lib',
    'no-shared', 'no-module', 'no-tests', 'no-comp', 'no-zlib', 'no-zlib-dynamic',
    'no-ssl3', 'no-md2', 'no-rc5', 'no-weak-ssl-ciphers', 'no-camellia', 'no-idea',
    'no-seed', 'no-capieng', 'no-asm',
  ];
  if (!features.includes('legacy')) flags.push('no-legacy');
  console.log(`[module-openssl] Building locked ${source.name} ${source.version}`);
  run(perl, flags, developer, build);
  const nmake = join(developer.VCToolsInstallDir, 'bin', 'Hostx64', 'x64', 'nmake.exe');
  // Explicit make arguments survive Cargo's subprocess environment handling.
  // /MT matches the SDK and Rust profile; the absolute PDB survives rlib packing.
  const cflags = env.LIB_CFLAGS ??
    '/Zi /FS /Fd"$(MAKEDIR)\\ossl_static.pdb" /MT /O2 /W3 /nologo $(CNF_CFLAGS) $(CFLAGS)';
  developer.LIB_CFLAGS = cflags;
  developer.MAKEFLAGS = 'E';
  run(nmake, ['build_libs', `LIB_CFLAGS=${cflags}`], developer, build);
  const pdb = join(build, 'ossl_static.pdb');
  const object = firstObject(build);
  if (!existsSync(pdb) || !object) throw new Error('OpenSSL did not produce its debug artifacts');
  const contents = readFileSync(object).toString('latin1').toLowerCase();
  if (!contents.includes(pdb.toLowerCase()) ||
      !/\/defaultlib:"?libcmt(?:\.lib)?"?/.test(contents)) {
    throw new Error('OpenSSL object does not reference the expected PDB and static CRT');
  }
  run(nmake, ['install_dev', `LIB_CFLAGS=${cflags}`], developer, build);
  for (const file of ['lib/libcrypto.lib', 'lib/libssl.lib', 'include/openssl/ssl.h']) {
    if (!existsSync(join(install, file))) throw new Error(`OpenSSL install lacks ${file}`);
  }
  return { ...env, OPENSSL_DIR: install, OPENSSL_NO_VENDOR: '1', OPENSSL_STATIC: '1' };
}
