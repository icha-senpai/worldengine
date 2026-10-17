import { onScopeDispose } from "vue";
import { bitcraftFetch } from "./api";
import { retryAfterSeconds } from "./trackerPolling";

export function createTrackerRefresh({
  url,
  apply,
  delayed,
  canRead = () => true,
  fetch = bitcraftFetch,
  noCache = false,
}) {
  let disposed = false;
  let generation = 0;
  let controller;
  async function refresh() {
    if (disposed || !canRead()) return;
    const request = ++generation;
    const requestUrl = url();
    const current = () =>
      !disposed && request === generation && requestUrl === url() && canRead();
    controller?.abort();
    controller = new AbortController();
    try {
      const response = await fetch(requestUrl, {
        signal: controller.signal,
        ...(noCache ? { cache: "no-store" } : {}),
        headers: {
          Accept: "application/json",
          "X-Requested-With": "XMLHttpRequest",
          ...(noCache
            ? { "Cache-Control": "no-cache", Pragma: "no-cache" }
            : {}),
        },
      });
      if (!current()) return;
      if (response.status === 429) {
        delayed();
        return retryAfterSeconds(response);
      }
      if (!response.ok)
        throw Error(`Snapshot request failed with ${response.status}`);
      const payload = await response.json();
      if (!current()) return;
      apply(payload);
      return payload.refresh?.retryAfter ?? 0;
    } catch {
      if (current())
        delayed("Tracker refresh paused. Showing the last available data.");
    }
  }
  return {
    refresh,
    stop() {
      disposed = true;
      generation++;
      controller?.abort();
    },
  };
}
export function useTrackerRefresh(options) {
  const reader = createTrackerRefresh(options);
  onScopeDispose(reader.stop);
  return reader.refresh;
}
