// A background resume may be only a short browser maintenance window. Wait for
// visibility before reopening a connection that was paused for suspension.
export function connectionLifecycle(
  pause: () => void,
  reconnect: () => void,
  page: Pick<Document, 'visibilityState' | 'addEventListener' | 'removeEventListener'> = document,
  host: Pick<Window, 'addEventListener' | 'removeEventListener'> = window,
) {
  let suspended = false;
  let disposed = false;
  const suspend = () => {
    if (disposed || suspended) return;
    suspended = true;
    pause();
  };
  const resume = () => {
    if (disposed || !suspended || page.visibilityState !== 'visible') return;
    suspended = false;
    reconnect();
  };
  const pagehide = (event: Event) => {
    if ((event as PageTransitionEvent).persisted) suspend();
  };
  page.addEventListener('freeze', suspend);
  page.addEventListener('resume', resume);
  page.addEventListener('visibilitychange', resume);
  host.addEventListener('pageshow', resume);
  host.addEventListener('pagehide', pagehide);
  host.addEventListener('focus', resume);
  return {
    suspend,
    dispose() {
      disposed = true;
      page.removeEventListener('freeze', suspend);
      page.removeEventListener('resume', resume);
      page.removeEventListener('visibilitychange', resume);
      host.removeEventListener('pageshow', resume);
      host.removeEventListener('pagehide', pagehide);
      host.removeEventListener('focus', resume);
    },
  };
}
