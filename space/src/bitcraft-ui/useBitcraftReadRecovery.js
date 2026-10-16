import { onScopeDispose, watch } from "vue";
import { bitcraftConnected } from "../bitcraft";
import { createConnectionRecovery } from "../connectionRecovery";

export function isConnectionReadError(error) {
  return (
    ["DisconnectedError", "UnknownCallResultError"].includes(error?.name) ||
    /^(Not connected to SpacetimeDB|Live data is still reconnecting\.|The live data connection (?:was replaced|is unavailable|closed|was closed|timed out)\.)/.test(
      error?.message ?? "",
    )
  );
}

// For failed reads/subscription setup only. Never replay a save or game action.
export function useBitcraftReadRecovery(retry, canRetry = () => true) {
  let failed = false;
  let stopped = false;
  const recovery = createConnectionRecovery(
    () => {
      void retry();
    },
    () => !stopped && failed && bitcraftConnected.value && canRetry(),
  );
  const stopWatch = watch(bitcraftConnected, (connected) => {
    if (connected) recovery.schedule();
  });
  onScopeDispose(() => {
    stopped = true;
    stopWatch();
    recovery.stop();
  });
  return {
    failed() {
      failed = true;
      recovery.schedule();
    },
    succeeded() {
      failed = false;
      recovery.reset();
    },
    schedule: recovery.schedule,
  };
}
