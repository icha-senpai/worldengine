import { subscribeMapDirect } from "./fishing-map-relay-core";
let stop;
self.onmessage = ({ data }) => {
  if (data.type === "queries") {
    stop?.replace(data.queries);
    return;
  }
  if (data.type !== "start") return;
  stop?.();
  stop = subscribeMapDirect(data.region, data.queries, {
    rows(changes, initial) {
      self.postMessage({ type: "rows", changes, initial });
    },
    status(value, message) {
      self.postMessage({ type: "status", value, message });
    },
  });
};
