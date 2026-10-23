import assert from 'node:assert/strict';
import { connectionLifecycle } from '../../shared/connection-lifecycle.ts';

class Page extends EventTarget { visibilityState = 'visible'; }
const page = new Page(), host = new EventTarget();
let pauses = 0, reconnects = 0;
const lifecycle = connectionLifecycle(() => pauses++, () => reconnects++, page, host);
page.visibilityState = 'hidden';
page.dispatchEvent(new Event('visibilitychange'));
assert.equal(pauses, 0, 'Hiding a healthy tab does not disconnect it');
page.dispatchEvent(new Event('freeze'));
assert.equal(pauses, 1);
for (let i = 0; i < 20; i++) {
  page.dispatchEvent(new Event('resume'));
  page.dispatchEvent(new Event('freeze'));
  host.dispatchEvent(new Event('focus'));
  lifecycle.suspend();
}
assert.equal(pauses, 1, 'Repeated freezes and background connection losses pause only once');
assert.equal(reconnects, 0, 'Background resumes must not create a reconnect loop');
page.visibilityState = 'visible';
page.dispatchEvent(new Event('visibilitychange'));
page.dispatchEvent(new Event('resume'));
host.dispatchEvent(new Event('focus'));
host.dispatchEvent(new Event('pageshow'));
assert.equal(reconnects, 1, 'Returning opens exactly one connection');
page.visibilityState = 'hidden';
lifecycle.suspend();
assert.equal(pauses, 2, 'A hidden transport failure can pause without a freeze event');
page.visibilityState = 'visible';
host.dispatchEvent(new Event('pageshow'));
assert.equal(reconnects, 2);
host.dispatchEvent(Object.assign(new Event('pagehide'), { persisted: true }));
assert.equal(pauses, 3, 'Entering the back/forward cache pauses the connection');
host.dispatchEvent(new Event('pageshow'));
assert.equal(reconnects, 3);
lifecycle.dispose();
page.dispatchEvent(new Event('freeze'));
lifecycle.suspend();
host.dispatchEvent(new Event('focus'));
assert.equal(pauses, 3, 'Unmounted games have no remaining lifecycle listeners');
assert.equal(reconnects, 3);
console.log('PASS frozen-tab pauses, hidden resumes, visible recovery, bfcache and cleanup.');
