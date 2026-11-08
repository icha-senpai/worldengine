import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createRequire } from 'node:module';
import { resolve, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { test } from 'node:test';
import ts from 'typescript';
import { compileModule } from 'svelte/compiler';
import { equippedLoadout } from '../apps/web/src/lib/game/compendium-loadouts.ts';
import { ServerClock } from '../apps/web/src/lib/game/server-clock.ts';
import { tideforgeFingerprint } from '../apps/web/src/lib/game/tideforge-intents.ts';
import { coreQueries, tideforgeCoreQueries, tideforgeViewQueries, gearNameQueries, viewQueries } from '../apps/web/src/lib/game/subscriptions.ts';

const gamePath = fileURLToPath(new URL('../apps/web/src/lib/game/game.svelte.ts', import.meta.url));
const requireFromGame = createRequire(gamePath);
const source = await readFile(gamePath, 'utf8');
const js = ts.transpileModule(source, { compilerOptions: { target: ts.ScriptTarget.ESNext, module: ts.ModuleKind.ESNext } }).outputText;
const compiled = compileModule(js, { filename: 'game.svelte.js', generate: 'server' }).js.code;
const resolved = compiled.replace(/(from\s+['"])([^'"]+)(['"])/g, (_match, prefix, specifier, suffix) => {
  if (specifier === './connection-diagnostics') {
    return `${prefix}data:text/javascript,export%20const%20connectionDiagnostics%20%3D%20%7Brecord()%7B%7D%2Cfailure()%7B%7D%7D%3B${suffix}`;
  }
  const path = specifier.startsWith('.')
    ? resolve(dirname(gamePath), `${specifier}.ts`)
    : requireFromGame.resolve(specifier);
  return `${prefix}${pathToFileURL(path).href}${suffix}`;
});
const { Game } = await import(`data:text/javascript;base64,${Buffer.from(resolved).toString('base64')}`);

test('notice expiry preserves receipts and pending state, and replay does not revive', t => {
  t.mock.timers.enable({ apis: ['setTimeout'] });
  const game = new Game();
  const receipt = { identity: { toHexString: () => 'owner' }, playerId: 9n, nonce: 10n, summaryJson: '{}' };
  const pending = { quote: { nonce: 11n }, playerId: 9n, identity: 'owner', fingerprint: 'pending' };
  game.pendingTideforgeCommit = pending;
  game.recordTideforgeResult(receipt);
  assert.equal(game.tideforgeNoticeVisible, true);
  t.mock.timers.tick(6000);
  assert.equal(game.tideforgeNoticeVisible, false);
  assert.equal(game.tideforgeResult, receipt);
  assert.equal(game.pendingTideforgeCommit, pending);
  game.recordTideforgeResult(receipt);
  assert.equal(game.tideforgeNoticeVisible, false);
  game.recordTideforgeResult({ ...receipt, nonce: 11n });
  assert.equal(game.tideforgeNoticeVisible, true);
  game.tideforgeNotice.dismiss();
  game.recordTideforgeResult({ ...receipt, nonce: 11n });
  assert.equal(game.tideforgeNoticeVisible, false);
  game.recordTideforgeResult({ ...receipt, playerId: 10n, nonce: 11n });
  assert.equal(game.tideforgeNoticeVisible, true);
  t.mock.timers.tick(5000);
  game.recordTideforgeResult({ ...receipt, identity: { toHexString: () => 'other' }, playerId: 10n, nonce: 11n });
  t.mock.timers.tick(1000);
  assert.equal(game.tideforgeNoticeVisible, true);
  t.mock.timers.tick(5000);
  assert.equal(game.tideforgeNoticeVisible, false);
  game.clearPrivate();
  assert.equal(game.tideforgeNoticeVisible, false);
  game.recordTideforgeResult(receipt);
  assert.equal(game.tideforgeNoticeVisible, true);
  game.stop();
  assert.equal(game.tideforgeNoticeVisible, false);
});

test('expired success still acknowledges the saved receipt when the commit reply is lost', async t => {
  t.mock.timers.enable({ apis: ['setTimeout'] });
  const game = new Game(), calls = [], rows = [];
  let rejectReply;
  const pending = { identity: 'owner', playerId: 9n, quote: { nonce: 12n }, fingerprint: 'same' };
  game.identityKey = 'owner'; game.player = { playerId: 9n }; game.ready = true; game.coreReady = true; game.tideforgeCoreReady = true;
  game.pendingTideforgeCommit = pending;
  game.connection = {
    isActive: true,
    db: new Proxy({}, { get: (_target, name) => ({ iter: () => name === 'myTideforgeEconomyReceipts' ? rows : [] }) }),
    reducers: { commitTideforgeAction: ({ nonce }) => {
      calls.push(nonce);
      return new Promise((_resolve, reject) => { rejectReply = reject; });
    } },
  };
  const dispatch = game.dispatchTideforgeCommit(pending);
  const receipt = { identity: { toHexString: () => 'owner' }, playerId: 9n, nonce: 12n, summaryJson: '{}' };
  rows.push(receipt);
  game.refreshTideforge(game.connection);
  assert.equal(game.pendingTideforgeCommit, null);
  assert.equal(game.tideforgeNoticeVisible, true);
  t.mock.timers.tick(6000);
  assert.equal(game.tideforgeNoticeVisible, false);
  assert.equal(game.tideforgeResult, receipt);
  rejectReply(Object.assign(new Error('lost reply'), { name: 'UnknownCallResultError' }));
  assert.equal(await dispatch, true);
  assert.equal(game.tideforgeResult, receipt);
  assert.deepEqual(calls, [12n]);
});

test('raid success keys use request identity and warnings remain persistent', t => {
  t.mock.timers.enable({ apis: ['setTimeout'] });
  const game = new Game();
  game.identityKey = 'owner'; game.player = { playerId: 9n };
  game.setRaidActionMessage('Done', 1n);
  assert.equal(game.raidActionMessageIsSuccess, true);
  t.mock.timers.tick(5999);
  game.setRaidActionMessage('Done', 1n);
  t.mock.timers.tick(1);
  assert.equal(game.raidNoticeVisible, false);
  game.setRaidActionMessage('Done', 1n);
  assert.equal(game.raidNoticeVisible, false);
  game.setRaidActionMessage('Done', 2n);
  assert.equal(game.raidNoticeVisible, true);
  t.mock.timers.tick(5999);
  assert.equal(game.raidNoticeVisible, true);
  game.setRaidActionMessage('The authenticated identity changed.');
  assert.equal(game.raidActionMessageIsSuccess, false);
  assert.equal(game.raidNoticeVisible, false);
  t.mock.timers.tick(12000);
  assert.equal(game.raidActionMessage, 'The authenticated identity changed.');
  game.setRaidActionMessage('Done', 3n);
  game.clearPrivate();
  assert.equal(game.raidNoticeVisible, false);
  assert.equal(game.raidActionMessage, '');
  game.player = { playerId: 9n };
  game.setRaidActionMessage('Done', 3n);
  assert.equal(game.raidNoticeVisible, true);
  game.stop();
  assert.equal(game.raidNoticeVisible, false);
});

test('account change clears notice timers and retains its identity warning', t => {
  t.mock.timers.enable({ apis: ['setTimeout'] });
  const game = new Game();
  let calls = 0;
  let selectedPlayerId = 10n;
  game.identityKey = 'owner'; game.player = { playerId: 9n }; game.coreReady = true; game.ready = true;
  const now = performance.now(); game.serverClock.synchronize(Date.now(), now, now);
  game.connection = {
    isActive: false,
    db: new Proxy({}, { get: (_target, name) => ({ iter: () => name === 'myPlayer' ? [{ playerId: selectedPlayerId }] : [] }) }),
    reducers: new Proxy({}, { get: () => () => { calls++; } }),
    disconnect() {},
  };
  game.recordTideforgeResult({ identity: { toHexString: () => 'owner' }, playerId: 9n, nonce: 1n, summaryJson: '{}' });
  game.setRaidActionMessage('Done', 7n);
  game.pendingRaidIntent = { playerId: 9n, requestId: 7n, fingerprint: 'pending' };
  game.refresh();
  assert.equal(game.tideforgeNoticeVisible, false);
  assert.equal(game.raidNoticeVisible, false);
  assert.equal(game.raidActionMessageIsSuccess, false);
  assert.match(game.raidActionMessage, /different linked angler/);
  assert.equal(game.canDismissError, false);
  t.mock.timers.tick(12000);
  assert.match(game.raidActionMessage, /different linked angler/);
  game.error = 'Ordinary error';
  assert.equal(game.canDismissError, true);
  game.setRaidActionMessage('Persistent warning');
  selectedPlayerId = 11n;
  game.refresh();
  assert.equal(game.raidActionMessage, 'Persistent warning');
  assert.equal(game.raidActionMessageIsSuccess, false);
  assert.equal(calls, 0);
  game.stop();
});

test('private feature data and pending state clear on unlink/account reset', () => {
  const game = new Game();
  for (const name of ['gearInstances', 'gearInstanceNames', 'gearFamilies', 'tideforgeGear', 'tideforgeReceipts', 'tideforgeVault',
    'tideforgeCodex', 'tideforgeLibrary', 'tideforgeSpecimenModifiers', 'tideforgeCredits', 'gearActivitySnapshots']) game[name] = [{}];
  for (const name of ['gearLoadout', 'tideforgeLoadout', 'tideforgeWallet', 'tideforgeQuote', 'pendingTideforgeCommit', 'tideforgeResult']) game[name] = {};
  game.clearPrivate();
  for (const name of ['gearInstances', 'gearInstanceNames', 'gearFamilies', 'tideforgeGear', 'tideforgeReceipts', 'tideforgeVault',
    'tideforgeCodex', 'tideforgeLibrary', 'tideforgeSpecimenModifiers', 'tideforgeCredits', 'gearActivitySnapshots']) assert.deepEqual(game[name], []);
  for (const name of ['gearLoadout', 'tideforgeLoadout', 'tideforgeWallet', 'tideforgeQuote', 'pendingTideforgeCommit', 'tideforgeResult']) assert.equal(game[name], null);
  assert.equal(game.gearNamesAvailability, 'loading');
  assert.equal(game.gearNameError, '');
});

test('Shop and Tideforge share optional subscriptions and retain details across navigation', () => {
  const game = new Game(), subscriptions = [];
  const rows = { myTideforgeVault: [{ playerId: 9n, affixId: 'fortune', count: 5n }] };
  const connection = {
    isActive: true,
    db: new Proxy({}, { get: (_target, name) => ({ iter: () => rows[name] ?? [] }) }),
    subscriptionBuilder() {
      const entry = {};
      const builder = {
        onApplied(callback) { entry.applied = callback; return builder; },
        onError(callback) { entry.error = callback; return builder; },
        subscribe(queries) { entry.queries = queries; subscriptions.push(entry); return { unsubscribe() { entry.unsubscribed = true; } }; },
      };
      return builder;
    },
  };
  game.connection = connection; game.coreReady = true; game.tideforgeCoreReady = true; game.player = { playerId: 9n };
  game.identityKey = '0xabc'; game.tideforgeCoreKey = 'owner'; game.selectedView = 'trader';
  game.syncTideforgeView();
  const view = subscriptions[0];
  assert.deepEqual(view.queries, tideforgeViewQueries);
  game.selectedView = 'tideforge';
  game.syncTideforgeView();
  assert.equal(subscriptions.length, 1);
  assert.equal(view.unsubscribed, undefined);
  view.applied();
  assert.equal(game.tideforgeViewReady, true);
  assert.equal(game.tideforgeVault[0].count, 5n);
  game.selectedView = 'trader';
  game.syncTideforgeView();
  assert.equal(subscriptions.length, 1);
  assert.equal(game.tideforgeVault[0].count, 5n);
  for (const name of ['trader', 'tideforge']) {
    assert(viewQueries(name, 9n, 17).includes('SELECT * FROM my_inventory'));
    assert(viewQueries(name, 9n, 17).includes('SELECT * FROM rod_upgrade_recipe'));
  }
  game.selectedView = 'camp';
  game.syncTideforgeView();
  assert.equal(view.unsubscribed, true);
  assert.deepEqual(game.tideforgeVault, []);
  game.selectedView = 'tideforge';
  game.syncTideforgeView();
  const next = subscriptions[1];
  game.selectedView = 'trader';
  game.syncTideforgeView();
  assert.equal(subscriptions.length, 2);
  next.applied();
  assert.equal(game.tideforgeViewReady, true);
  assert.equal(game.tideforgeVault[0].count, 5n);
});

test('missing optional table fails only Tideforge and supports retry', () => {
  const game = new Game();
  let errorCallback;
  let subscribeCount = 0;
  const connection = {
    isActive: true,
    subscriptionBuilder() {
      const builder = {
        onApplied() { return builder; },
        onError(callback) { errorCallback = callback; return builder; },
        subscribe(queries) {
          assert.deepEqual(queries, ['SELECT * FROM tideforge_economy_state']);
          subscribeCount++;
          return { unsubscribe() {} };
        },
      };
      return builder;
    },
  };
  game.connection = connection; game.coreReady = true; game.ready = true; game.error = '';
  assert.equal(game.equipmentInformationUnavailable, true);
  game.gearInstances = [{}];
  game.probeTideforge(connection);
  errorCallback();
  assert.equal(game.tideforgeAvailability, 'unavailable');
  assert.equal(game.equipmentInformationUnavailable, false);
  assert.equal(game.ready, true);
  assert.equal(game.error, '');
  assert.deepEqual(game.gearInstances, []);
  game.retryTideforge();
  assert.equal(subscribeCount, 2);
});

test('unknown commit retains the same nonce; late account-change callback is ignored', async () => {
  const game = new Game(), calls = [];
  const error = Object.assign(new Error('connection lost'), { name: 'UnknownCallResultError' });
  let mode = 'unknown', settle;
  const connection = {
    isActive: true,
    reducers: { commitTideforgeAction: async ({ nonce }) => {
      calls.push(nonce);
      if (mode === 'unknown') throw error;
      if (mode === 'late') await new Promise(resolve => { settle = resolve; });
    } },
  };
  const pending = { identity: '0xabc', playerId: 9n, quote: { nonce: 12345678901234567890n }, fingerprint: 'same' };
  game.connection = connection; game.identityKey = pending.identity; game.player = { playerId: pending.playerId };
  game.ready = true; game.tideforgeCoreReady = true; game.pendingTideforgeCommit = pending;
  assert.equal(await game.retryTideforgeCommit(), false);
  assert.equal(game.pendingTideforgeCommit, pending);
  assert.equal(game.tideforgeError, 'We have not confirmed the last action yet. Check again safely; you will not pay twice.');
  assert.deepEqual(calls, [pending.quote.nonce]);
  mode = 'late';
  const result = game.retryTideforgeCommit();
  assert.deepEqual(calls, [pending.quote.nonce, pending.quote.nonce]);
  game.clearPrivate();
  settle();
  assert.equal(await result, false);
  assert.equal(game.pendingTideforgeCommit, null);
  assert.equal(game.tideforgeResult, null);
});

test('optional subscriptions filter cached owners and ignore old account callbacks', () => {
  const game = new Game(), subscriptions = [];
  const rows = {
    tideforgeEconomyState: [{ version: 1, catalogVersion: 1 }],
    myGearInstances: [{ playerId: 9n, instanceId: 1n }, { playerId: 10n, instanceId: 2n }],
    myTideforgeEconomyQuote: [
      { playerId: 9n, identity: { toHexString: () => '0xold' }, nonce: 1n },
      { playerId: 9n, identity: { toHexString: () => '0xnew' }, nonce: 2n },
    ],
  };
  const connection = {
    isActive: true,
    db: new Proxy({}, { get: (_target, name) => ({ iter: () => rows[name] ?? [] }) }),
    subscriptionBuilder() {
      const entry = {};
      const builder = {
        onApplied(callback) { entry.applied = callback; return builder; },
        onError(callback) { entry.error = callback; return builder; },
        subscribe(queries) { entry.queries = queries; subscriptions.push(entry); return { unsubscribe() { entry.unsubscribed = true; } }; },
      };
      return builder;
    },
  };
  game.connection = connection; game.coreReady = true; game.identityKey = '0xold'; game.player = { playerId: 9n };
  const subscription = query => subscriptions.findLast(entry => entry.queries.includes(query));
  game.probeTideforge(connection);
  subscription('SELECT * FROM tideforge_economy_state').applied();
  const oldCore = subscription('SELECT * FROM my_gear_instances');
  oldCore.applied();
  const oldNames = subscription('SELECT * FROM my_gear_instance_names');
  assert.deepEqual(game.gearInstances.map(row => row.instanceId), [1n]);
  assert.equal(game.tideforgeQuote.nonce, 1n);
  game.selectedView = 'tideforge';
  game.syncTideforgeView();
  const oldView = subscription('SELECT * FROM my_gear_activity_snapshots');
  assert(oldView);
  game.identityKey = '0xnew';
  game.updateTideforgeCapability(connection);
  assert.equal(oldCore.unsubscribed, true);
  assert.equal(oldView.unsubscribed, true);
  assert.equal(oldNames.unsubscribed, true);
  oldView.applied();
  oldCore.error();
  oldNames.applied();
  oldNames.error();
  assert.equal(game.tideforgeViewReady, false);
  assert.equal(game.tideforgeAvailability, 'active');
  subscription('SELECT * FROM my_gear_instances').applied();
  assert.equal(game.tideforgeQuote.nonce, 2n);
});

function namesHarness() {
  const game = new Game(), subscriptions = [], calls = [];
  const instanceId = 123456789012345678901234567890n;
  const rows = {
    myGearInstances: [{ playerId: 9n, instanceId }, { playerId: 10n, instanceId: 2n }],
    myGearInstanceNames: [
      { playerId: 9n, instanceId, name: 'Before' },
      { playerId: 9n, instanceId: 3n, name: 'Not owned' },
      { playerId: 10n, instanceId: 2n, name: 'Other owner' },
      { playerId: 10n, instanceId, name: 'Wrong owner' },
    ],
  };
  const connection = {
    isActive: true,
    db: new Proxy({}, { get: (_target, name) => ({ iter: () => rows[name] ?? [] }) }),
    reducers: {
      renameGearInstance(args) { return new Promise((resolve, reject) => calls.push({ args, resolve, reject })); },
      setGearInstanceFavorite: async () => {},
    },
    subscriptionBuilder() {
      const entry = {};
      const builder = {
        onApplied(callback) { entry.applied = callback; return builder; },
        onError(callback) { entry.error = callback; return builder; },
        subscribe(queries) { entry.queries = queries; subscriptions.push(entry); return { unsubscribe() { entry.unsubscribed = true; } }; },
      };
      return builder;
    },
  };
  const subscription = query => subscriptions.findLast(entry => entry.queries.includes(query));
  game.connection = connection; game.coreReady = true; game.ready = true; game.identityKey = '0xold';
  game.player = { playerId: 9n }; game.tideforgeAvailability = 'active';
  game.syncTideforgeCore(connection);
  subscription('SELECT * FROM my_gear_instances').applied();
  return { game, connection, subscriptions, subscription, rows, calls, instanceId };
}

test('old servers can reject the independent names query without affecting gear or actions', async () => {
  assert.deepEqual(gearNameQueries, ['SELECT * FROM my_gear_instance_names']);
  for (const queries of [coreQueries, tideforgeCoreQueries, tideforgeViewQueries, viewQueries('tideforge')]) {
    assert(!queries.includes(gearNameQueries[0]));
  }
  const { game, connection, subscriptions, subscription, instanceId } = namesHarness();
  const names = subscription(gearNameQueries[0]);
  assert.equal(game.gearNamesAvailability, 'loading');
  names.error();
  assert.equal(game.gearNamesAvailability, 'unavailable');
  assert.equal(game.tideforgeAvailability, 'active');
  assert.equal(game.ready, true);
  assert.equal(game.error, '');
  assert.equal(game.gearInstances[0].instanceId, instanceId);
  game.refresh = () => {};
  assert.equal(await game.setGearInstanceFavorite(instanceId, true), true);
  game.syncGearNames(connection);
  assert.equal(subscriptions.filter(entry => entry.queries.includes(gearNameQueries[0])).length, 1);
  game.retryGearNames();
  const retry = subscription(gearNameQueries[0]);
  assert.notEqual(retry, names);
  names.applied(); names.error();
  assert.equal(game.gearNamesAvailability, 'loading');
  retry.applied();
  assert.equal(game.gearNamesAvailability, 'ready');
  assert.deepEqual(game.gearInstanceNames.map(row => row.name), ['Before']);
});

test('renames send normalized bigint once, wait for authority, and explain failures safely', async () => {
  const { game, connection, subscription, rows, calls, instanceId } = namesHarness();
  subscription(gearNameQueries[0]).applied();
  const pending = game.renameGearInstance(instanceId, '\u0085  Old Faithful \u2003');
  assert.deepEqual(calls.map(call => call.args), [{ instanceId, name: 'Old Faithful' }]);
  assert.equal(game.busy, true);
  assert.equal(game.gearInstanceNames[0].name, 'Before');
  rows.myGearInstanceNames[0] = { playerId: 9n, instanceId, name: 'Old Faithful' };
  calls[0].resolve();
  assert.equal(await pending, true);
  assert.equal(game.busy, false);
  assert.equal(game.gearInstanceNames[0].name, 'Old Faithful');
  assert.equal(await game.renameGearInstance(instanceId, '🎣'.repeat(33)), false);
  assert.match(game.gearNameError, /32 characters/);
  assert.equal(calls.length, 1);
  for (const [reason, expected] of [
    ['GEAR_NAME_INVALID', /cannot contain/],
    ['GEAR_NAME_TOO_LONG', /32 characters/],
    ['GEAR_INSTANCE_UNAVAILABLE', /no longer available/],
    ['SENSITIVE_INTERNAL_ERROR', /could not be saved/],
  ]) {
    const attempt = game.renameGearInstance(instanceId, 'Valid');
    calls.at(-1).reject(new Error(reason));
    assert.equal(await attempt, false);
    assert.match(game.gearNameError, expected);
    assert.doesNotMatch(game.gearNameError, /SENSITIVE_INTERNAL_ERROR/);
  }
  const uncertain = game.renameGearInstance(instanceId, 'Valid');
  calls.at(-1).reject(Object.assign(new Error('lost'), { name: 'UnknownCallResultError' }));
  assert.equal(await uncertain, false);
  assert.match(game.gearNameError, /could not confirm.*Reconnect/);
  const lost = game.renameGearInstance(instanceId, 'Valid');
  connection.isActive = false;
  calls.at(-1).reject(new Error('connection lost'));
  assert.equal(await lost, false);
  assert.match(game.gearNameError, /could not confirm.*Reconnect/);
  connection.isActive = true;
  assert.equal(await game.renameGearInstance(99n, 'Valid'), false);
  assert.match(game.gearNameError, /no longer available/);
});

test('name callbacks, pending actions and replay are isolated by owner and generation', async () => {
  const { game, connection, subscription, rows, calls, instanceId } = namesHarness();
  const oldNames = subscription(gearNameQueries[0]);
  oldNames.applied();
  const oldAction = game.renameGearInstance(instanceId, 'Old request');
  game.identityKey = '0xnew'; game.player = { playerId: 10n };
  game.syncTideforgeCore(connection);
  assert.equal(oldNames.unsubscribed, true);
  assert.deepEqual(game.gearInstanceNames, []);
  assert.equal(game.busy, false);
  subscription('SELECT * FROM my_gear_instances').applied();
  const newNames = subscription(gearNameQueries[0]);
  newNames.applied();
  assert.deepEqual(game.gearInstanceNames.map(row => row.name), ['Other owner']);
  const newAction = game.renameGearInstance(2n, 'New owner');
  assert.equal(game.busy, true);
  calls[0].reject(new Error('GEAR_NAME_INVALID'));
  oldNames.applied(); oldNames.error();
  assert.equal(await oldAction, false);
  assert.equal(game.busy, true);
  assert.equal(game.gearNameError, '');
  rows.myGearInstanceNames[2] = { playerId: 10n, instanceId: 2n, name: 'New owner' };
  calls[1].resolve();
  assert.equal(await newAction, true);
  assert.equal(game.gearInstanceNames[0].name, 'New owner');
  game.clearGearNames();
  assert.deepEqual(game.gearInstanceNames, []);
  game.syncGearNames(connection);
  subscription(gearNameQueries[0]).applied();
  assert.deepEqual(game.gearInstanceNames.map(row => row.name), ['New owner']);
  game.gearNameError = 'old error';
  game.clearPrivate();
  assert.deepEqual(game.gearInstanceNames, []);
  assert.equal(game.gearNameError, '');
  assert.equal(game.gearNamesAvailability, 'loading');
});

test('reconnected pending action survives same owner but is discarded for a different player', () => {
  const pending = { identity: '0xabc', playerId: 9n, quote: { nonce: 77n }, fingerprint: 'same' };
  for (const playerId of [9n, 10n]) {
    const game = new Game();
    game.identityKey = pending.identity;
    game.pendingTideforgeCommit = pending;
    game.coreReady = true;
    game.connection = {
      isActive: false,
      db: new Proxy({}, { get: (_target, name) => ({ iter: () => name === 'myPlayer' ? [{ playerId }] : [] }) }),
    };
    game.refresh();
    assert.equal(game.pendingTideforgeCommit, playerId === pending.playerId ? pending : null);
  }
});

test('only an authoritative same-owner same-nonce receipt clears pending', () => {
  const game = new Game(), identity = '0xabc';
  const pending = { identity, playerId: 9n, quote: { nonce: 77n }, fingerprint: 'same' };
  const rows = [];
  game.identityKey = identity; game.player = { playerId: 9n };
  game.coreReady = true; game.tideforgeCoreReady = true; game.pendingTideforgeCommit = pending;
  game.connection = {
    db: new Proxy({}, { get: (_target, name) => ({ iter: () => name === 'myTideforgeEconomyReceipts' ? rows : [] }) }),
  };
  rows.push({ playerId: 9n, nonce: 78n, identity: { toHexString: () => identity } });
  rows.push({ playerId: 9n, nonce: 77n, identity: { toHexString: () => '0xother' } });
  game.refreshTideforge(game.connection);
  assert.equal(game.pendingTideforgeCommit, pending);
  const receipt = { playerId: 9n, nonce: 77n, identity: { toHexString: () => identity } };
  rows.push(receipt);
  game.refreshTideforge(game.connection);
  assert.equal(game.pendingTideforgeCommit, null);
  assert.equal(game.tideforgeResult, receipt);
});

test('a disconnected confirmation never creates a phantom pending commit', async () => {
  const game = new Game();
  game.ready = false;
  game.preparedTideforge = { identity: '0xabc', playerId: 9n, quote: { nonce: 77n }, fingerprint: 'same' };
  assert.equal(await game.commitTideforge({ nonce: 77n }), false);
  assert.equal(game.pendingTideforgeCommit, null);
});

test('server rejections explain common guards without exposing unknown error codes', () => {
  const game = new Game();
  assert.match(game.tideforgeActionError('TIDEFORGE_GEAR_PROTECTED'), /protected/);
  assert.match(game.tideforgeActionError('GEAR_INSTANCE_UNAVAILABLE'), /no longer available/);
  assert.equal(game.tideforgeActionError('TIDEFORGE_SOMETHING_NEW'), 'This action could not be completed. Check your gear and materials, then try again.');
});

test('public prepare and simple actions surface closed friendly errors', async () => {
  const game = new Game();
  const now = performance.now();
  game.serverClock.synchronize(Date.now(), now, now);
  game.identityKey = '0xabc'; game.player = { playerId: 9n }; game.ready = true; game.tideforgeCoreReady = true;
  game.connection = { isActive: true, reducers: {
    prepareTideforgeAction: async () => { throw new Error('TIDEFORGE_UNKNOWN_SECRET'); },
    equipGearInstance: async () => { throw new Error('TIDEFORGE_GEAR_PROTECTED'); },
  } };
  assert.equal(await game.prepareTideforge({ action: 'camp_copy', kind: 'rod', definition_id: 2, secondary_biome_id: 1 }), null);
  assert.equal(game.tideforgeError, 'This action could not be completed. Check your gear and materials, then try again.');
  assert.equal(await game.prepareTideforge({ action: 'camp_copy', kind: 'rod', definition_id: 2, secondary_biome_id: 1, hostile_field: 'secret' }), null);
  assert.equal(game.tideforgeError, 'Review your selection and try again.');
  assert.equal(await game.equipGearInstance(4n), false);
  assert.match(game.tideforgeError, /protected/);
  assert.doesNotMatch(game.tideforgeError, /TIDEFORGE_/);
});

test('unknown server time blocks dispatch and deadline uses fresh monotonic time', async () => {
  const game = new Game(), calls = [];
  let monotonic = 100;
  game.serverClock = new ServerClock(() => monotonic);
  game.identityKey = '0xabc'; game.player = { playerId: 9n }; game.ready = true; game.tideforgeCoreReady = true;
  game.connection = { isActive: true, reducers: {
    prepareTideforgeAction: async () => calls.push('prepare'),
    commitTideforgeAction: async () => calls.push('commit'),
  } };
  const intent = { action: 'camp_copy', kind: 'rod', definition_id: 2, secondary_biome_id: 1 };
  assert.equal(await game.prepareTideforge(intent), null);
  assert.equal(game.tideforgeError, 'Syncing server time…');
  assert.deepEqual(calls, []);
  const quote = {
    nonce: 3n, identity: { toHexString: () => '0xabc' }, playerId: 9n, catalogVersion: 1, consumed: false,
    intentJson: JSON.stringify(intent),
    previewJson: JSON.stringify({ ...intent, affix_count: 1, coins_before: 500, coins_cost: 10, coins_after: 490,
      marks_before: 10, marks_cost: 0, marks_after: 10 }),
    createdAt: { microsSinceUnixEpoch: 1_000_000_000n },
    expiresAt: { microsSinceUnixEpoch: 1_000_500_000n },
  };
  game.tideforgeQuote = quote;
  game.preparedTideforge = { quote, identity: '0xabc', playerId: 9n, fingerprint: tideforgeFingerprint(intent) };
  assert.equal(await game.commitTideforge(quote), false);
  assert.equal(game.tideforgeError, 'Syncing server time…');
  game.serverClock.synchronize(1_000_000, monotonic, monotonic);
  monotonic += 501;
  assert.equal(await game.commitTideforge(quote), false);
  assert.equal(game.tideforgeError, 'This preview is no longer current. Open a new preview.');
  assert.deepEqual(calls, []);
});

test('definite commit rejection is safe and confirmed pending status avoids receipt jargon', async () => {
  const game = new Game(), pending = { identity: '0xabc', playerId: 9n, quote: { nonce: 77n }, fingerprint: 'same' };
  let fail = true;
  game.identityKey = pending.identity; game.player = { playerId: pending.playerId };
  game.ready = true; game.tideforgeCoreReady = true; game.pendingTideforgeCommit = pending;
  game.connection = { isActive: true, reducers: { commitTideforgeAction: async () => { if (fail) throw new Error('TIDEFORGE_UNEXPECTED_SECRET'); } } };
  game.refreshTideforge = () => {};
  assert.equal(await game.retryTideforgeCommit(), false);
  assert.equal(game.pendingTideforgeCommit, null);
  assert.equal(game.tideforgeError, 'This action could not be completed. Check your gear and materials, then try again.');
  game.pendingTideforgeCommit = pending; fail = false;
  assert.equal(await game.retryTideforgeCommit(), true);
  assert.equal(game.pendingTideforgeCommit, pending);
  assert.equal(game.tideforgeError, 'Action confirmed. Updating your gear and materials…');
});

test('known-active gear core failure protects original upgrades; absent feature does not', () => {
  const game = new Game(), subscriptions = [];
  const connection = {
    isActive: true,
    db: new Proxy({}, { get: (_target, name) => ({ iter: () => name === 'tideforgeEconomyState' ? [{ version: 1, catalogVersion: 1 }] : [] }) }),
    subscriptionBuilder() {
      const entry = {};
      const builder = {
        onApplied(callback) { entry.applied = callback; return builder; },
        onError(callback) { entry.error = callback; return builder; },
        subscribe(queries) { entry.queries = queries; subscriptions.push(entry); return { unsubscribe() {} }; },
      };
      return builder;
    },
  };
  game.connection = connection; game.coreReady = true; game.identityKey = '0xabc'; game.player = { playerId: 9n };
  const subscription = query => subscriptions.findLast(entry => entry.queries.includes(query));
  game.probeTideforge(connection);
  subscription('SELECT * FROM tideforge_economy_state').applied();
  assert.equal(game.equipmentInformationUnavailable, true);
  subscription('SELECT * FROM my_gear_instances').error();
  assert.equal(game.tideforgeAvailability, 'unavailable');
  assert.equal(game.equipmentInformationUnavailable, true);
  game.retryTideforge();
  subscription('SELECT * FROM tideforge_economy_state').error();
  assert.equal(game.tideforgeAvailability, 'unavailable');
  assert.equal(game.equipmentInformationUnavailable, true);
});

for (const disconnected of [false, true]) {
  test(`saved receipt beats ${disconnected ? 'disconnect and late callback' : 'unknown transport result'}`, async () => {
    const game = new Game(), rows = [];
    const identity = '0xabc', pending = { identity, playerId: 9n, quote: { nonce: 77n }, fingerprint: 'same' };
    let reject;
    const connection = {
      isActive: true,
      db: new Proxy({}, { get: (_target, name) => ({ iter: () => name === 'myTideforgeEconomyReceipts' ? rows : [] }) }),
      reducers: { commitTideforgeAction: () => new Promise((_resolve, fail) => { reject = fail; }) },
    };
    game.connection = connection; game.identityKey = identity; game.player = { playerId: 9n };
    game.ready = true; game.coreReady = true; game.tideforgeCoreReady = true; game.pendingTideforgeCommit = pending;
    const result = game.retryTideforgeCommit();
    const receipt = { playerId: 9n, nonce: 77n, identity: { toHexString: () => identity } };
    rows.push(receipt);
    game.refreshTideforge(connection);
    assert.equal(game.pendingTideforgeCommit, null);
    if (disconnected) { game.generation++; game.tideforgeDispatch = null; }
    const newer = { ...pending, quote: { nonce: 78n } };
    game.pendingTideforgeCommit = newer;
    reject(Object.assign(new Error('lost reply'), { name: 'UnknownCallResultError' }));
    assert.equal(await result, true);
    assert.equal(game.tideforgeResult, receipt);
    assert.equal(game.pendingTideforgeCommit, newer);
    assert.equal(game.tideforgeError, '');
  });
}

test('selected copy stats and compendium use its own quality and reinforcement', () => {
  const game = new Game();
  game.ready = true; game.tideforgeAvailability = 'active'; game.tideforgeCoreReady = true; game.player = { playerId: 9n, equippedRodId: 2 };
  game.rods = [{ rodId: 2, name: 'River Rod', power: 10 }, { rodId: 3, name: 'Lake Rod', power: 15 }];
  game.rodBonuses = [{ rodId: 2, luckBp: 100, xpBonusBp: 100 }, { rodId: 3, luckBp: 200, xpBonusBp: 200 }];
  game.qualities = [{ qualityLevel: 0, name: 'Common', powerBonus: 0, luckBp: 0, xpBonusBp: 0 },
    { qualityLevel: 2, name: 'Rare', powerBonus: 4, luckBp: 500, xpBonusBp: 300 }];
  game.ownedRods = [{ rodId: 2, upgradeLevel: 2 }, { rodId: 3, upgradeLevel: 2 }];
  game.rodReinforcements = [{ rodId: 2, level: 3 }, { rodId: 3, level: 4 }];
  game.haulEquipment = [{ equipmentId: 12, kind: 'boat', luckBp: 50, xpBonusBp: 70, qualityBonusMultiplier: 2 },
    { equipmentId: 13, kind: 'boat', luckBp: 80, xpBonusBp: 90, qualityBonusMultiplier: 2 }];
  game.ownedHaulEquipment = [{ equipmentId: 12, upgradeLevel: 2 }, { equipmentId: 13, upgradeLevel: 2 }];
  game.gearInstances = [
    { instanceId: 1n, playerId: game.player.playerId, kind: 'rod', definitionId: 2, qualityLevel: 0, reinforcementLevel: 0 },
    { instanceId: 2n, kind: 'boat', definitionId: 12, qualityLevel: 0, reinforcementLevel: 0 },
  ];
  game.gearLoadout = { rodInstanceId: 1n, boatInstanceId: 2n, netInstanceId: 3n };
  assert.equal(game.rodStats(2).quality.name, 'Common');
  assert.equal(game.rodStats(2).reinforcementLevel, 0);
  assert.equal(game.rodStats(3).quality.name, 'Rare');
  assert.equal(game.rodStats(3).reinforcementLevel, 4);
  assert.equal(game.haulStats(12).quality.name, 'Common');
  assert.equal(game.haulStats(13).quality.name, 'Rare');
  const loadout = equippedLoadout(game);
  assert.match(loadout.name, /^Common River Rod/);
  assert.equal(loadout.reinforcementLevel, 0);
  assert.equal(loadout.power, 10);
  assert.equal(loadout.luckBp, 100);
  game.gearInstanceNames = [{ instanceId: 1n, playerId: game.player.playerId, name: 'Old Faithful' }];
  const named = equippedLoadout(game);
  assert.match(named.name, /^Common Old Faithful · River Rod/);
  assert.deepEqual({ ...named, name: '' }, { ...loadout, name: '' });
});
