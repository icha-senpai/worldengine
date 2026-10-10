import { subscribeMapDirect } from "./fishing-map-relay-core";
// Large terrain snapshots are decoded away from the browser's interaction thread.
export function subscribeMap(region, queries, callbacks) {
  if (typeof Worker === "undefined")
    return subscribeMapDirect(region, queries, callbacks);
  const worker = new Worker(
    new URL("./fishing-map-relay.worker.js", import.meta.url),
    { type: "module" },
  );
  let stopped = false;
  worker.onmessage = ({ data }) => {
    if (stopped) return;
    if (data.type === "rows") callbacks.rows(data.changes, data.initial);
    else if (data.type === "status")
      callbacks.status?.(data.value, data.message);
  };
  worker.onerror = () => {
    if (!stopped)
      callbacks.status?.(
        "reconnecting",
        "Map worker failed. Reload to reconnect.",
      );
  };
  worker.postMessage({ type: "start", region, queries });
  const stop = () => {
    stopped = true;
    worker.terminate();
  };
  stop.replace = (next) => {
    if (!stopped) worker.postMessage({ type: "queries", queries: next });
  };
  return stop;
}
