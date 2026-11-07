import assert from 'node:assert/strict';
import { test } from 'node:test';
import { deadlineExpired, observeServerClock, ServerClock } from '../apps/web/src/lib/game/server-clock.ts';

test('clock observer calibrates, resumes safely, aborts obsolete requests and cleans up', async () => {
  const originals = Object.fromEntries(['document', 'window', 'fetch', 'performance', 'setTimeout', 'clearTimeout', 'setInterval', 'clearInterval']
    .map(key => [key, Object.getOwnPropertyDescriptor(globalThis, key)]));
  let now = 0, nextId = 1, changed = 0;
  const timers = new Map(), requests = [];
  const document = new EventTarget(), window = new EventTarget();
  document.visibilityState = 'visible';
  const schedule = (fn, delay, repeat) => {
    const id = nextId++;
    timers.set(id, { fn, at: now + delay, delay, repeat });
    return id;
  };
  const advance = ms => {
    const end = now + ms;
    while (true) {
      const due = [...timers.entries()].filter(([, timer]) => timer.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
      if (!due) break;
      const [id, timer] = due;
      now = timer.at;
      if (timer.repeat) timer.at += timer.delay; else timers.delete(id);
      timer.fn();
    }
    now = end;
  };
  const flush = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); };
  for (const [key, value] of Object.entries({
    document, window, performance: { now: () => now },
    fetch: (url, options) => new Promise(resolve => requests.push({ url, options, resolve })),
    setTimeout: (fn, delay) => schedule(fn, delay, false),
    setInterval: (fn, delay) => schedule(fn, delay, true),
    clearTimeout: id => timers.delete(id), clearInterval: id => timers.delete(id),
  })) Object.defineProperty(globalThis, key, { value, configurable: true, writable: true });
  try {
    const clock = new ServerClock(() => now);
    const stop = observeServerClock(clock, () => changed++);
    assert.equal(clock.now(), null);
    assert.equal(deadlineExpired(1n, clock.now()), true);
    assert.equal(requests.length, 1);
    assert.equal(requests[0].url, '/api/time');
    assert.equal(requests[0].options.cache, 'no-store');
    requests[0].resolve({ ok: true, json: async () => ({ serverTimeMs: 1_000_000 }) });
    await flush();
    assert.equal(clock.now(), 1_000_000);
    assert.equal(deadlineExpired(1_001_000_000n, clock.now()), false);
    advance(60_000);
    assert.equal(requests.length, 2);
    document.visibilityState = 'hidden'; document.dispatchEvent(new Event('visibilitychange'));
    document.visibilityState = 'visible'; document.dispatchEvent(new Event('visibilitychange'));
    assert.equal(requests[1].options.signal.aborted, true);
    assert.equal(clock.now(), null);
    assert.equal(requests.length, 3);
    requests[1].resolve({ ok: true, json: async () => ({ serverTimeMs: 9_000_000 }) });
    await flush();
    assert.equal(clock.now(), null);
    requests[2].resolve({ ok: true, json: async () => ({ serverTimeMs: 2_000_000 }) });
    await flush();
    assert.equal(clock.now(), 2_000_000);
    const pageShow = new Event('pageshow');
    Object.defineProperty(pageShow, 'persisted', { value: true });
    window.dispatchEvent(pageShow);
    assert.equal(clock.now(), null);
    assert.equal(requests.length, 4);
    advance(5000);
    assert.equal(requests[3].options.signal.aborted, true);
    assert.equal(clock.now(), null);
    requests[3].resolve({ ok: true, json: async () => ({ serverTimeMs: 8_000_000 }) });
    await flush();
    assert.equal(clock.now(), null);
    advance(55_000);
    assert.equal(requests.length, 5);
    requests[4].resolve({ ok: false });
    await flush();
    assert.equal(clock.now(), null);
    advance(60_000);
    assert.equal(requests.length, 6);
    requests[5].resolve({ ok: true, json: async () => ({ serverTimeMs: 3_000_000 }) });
    await flush();
    assert.equal(clock.now(), 3_000_000);
    advance(60_000);
    assert.equal(requests.length, 7);
    document.dispatchEvent(new Event('freeze'));
    assert.equal(requests[6].options.signal.aborted, true);
    assert.equal(clock.now(), null);
    document.dispatchEvent(new Event('resume'));
    assert.equal(requests.length, 8);
    requests[6].resolve({ ok: true, json: async () => ({ serverTimeMs: 9_000_000 }) });
    await flush();
    assert.equal(clock.now(), null);
    requests[7].resolve({ ok: true, json: async () => ({ serverTimeMs: 4_000_000 }) });
    await flush();
    assert.equal(clock.now(), 4_000_000);
    window.dispatchEvent(new Event('focus'));
    assert.equal(clock.now(), null);
    assert.equal(requests.length, 9);
    requests[8].resolve({ ok: true, json: async () => ({ serverTimeMs: 5_000_000 }) });
    await flush();
    assert.equal(clock.now(), 5_000_000);
    document.visibilityState = 'hidden'; document.dispatchEvent(new Event('visibilitychange'));
    document.dispatchEvent(new Event('freeze'));
    document.dispatchEvent(new Event('resume'));
    window.dispatchEvent(new Event('focus'));
    assert.equal(clock.now(), null);
    assert.equal(requests.length, 9);
    document.visibilityState = 'visible'; document.dispatchEvent(new Event('visibilitychange'));
    assert.equal(requests.length, 10);
    requests[9].resolve({ ok: true, json: async () => ({ serverTimeMs: 6_000_000 }) });
    await flush();
    assert.equal(clock.now(), 6_000_000);
    window.dispatchEvent(new Event('focus'));
    assert.equal(requests.length, 11);
    assert(changed > 0);
    const priorChanges = changed;
    stop();
    assert.equal(requests[10].options.signal.aborted, true);
    requests[10].resolve({ ok: true, json: async () => ({ serverTimeMs: 7_000_000 }) });
    await flush();
    assert.equal(clock.now(), null);
    advance(120_000);
    document.visibilityState = 'hidden'; document.dispatchEvent(new Event('visibilitychange'));
    document.visibilityState = 'visible'; document.dispatchEvent(new Event('visibilitychange'));
    window.dispatchEvent(pageShow);
    document.dispatchEvent(new Event('freeze'));
    document.dispatchEvent(new Event('resume'));
    window.dispatchEvent(new Event('focus'));
    assert.equal(changed, priorChanges);
    assert.equal(requests.length, 11);
    assert.equal(timers.size, 0);
    clock.reset();
    assert.equal(clock.now(), null);
  } finally {
    for (const [key, descriptor] of Object.entries(originals)) {
      if (descriptor) Object.defineProperty(globalThis, key, descriptor);
      else delete globalThis[key];
    }
  }
});
