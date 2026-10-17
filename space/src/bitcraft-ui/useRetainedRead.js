import { onScopeDispose } from "vue";
import {
  isConnectionReadError,
  useBitcraftReadRecovery,
} from "./useBitcraftReadRecovery";

// Data remains owned by the caller and is replaced only by a successful read.
export function createRetainedRead({
  loading,
  error,
  read,
  apply,
  canRead = () => true,
  fallback,
  failed = () => {},
  succeeded = () => {},
  settled = () => {},
}) {
  let generation = 0;
  let disposed = false;
  let latestArgs = [];
  async function run(...args) {
    if (disposed || !canRead()) return;
    latestArgs = args;
    const current = ++generation;
    loading.value = true;
    error.value = "";
    try {
      const value = await read(...args);
      if (disposed || current !== generation || !canRead()) return;
      apply(value, ...args);
      succeeded();
    } catch (cause) {
      if (!disposed && current === generation) {
        error.value = cause.message || fallback;
        failed(cause);
      }
    } finally {
      if (!disposed && current === generation) {
        loading.value = false;
        settled();
      }
    }
  }
  return {
    run,
    retry: () => run(...latestArgs),
    invalidate() {
      generation++;
      loading.value = false;
    },
    dispose() {
      disposed = true;
      generation++;
    },
  };
}
export function useRetainedRead(options) {
  let reader;
  const recovery = useBitcraftReadRecovery(
    () => reader.retry(),
    () => !options.loading.value && (options.canRead?.() ?? true),
  );
  reader = createRetainedRead({
    ...options,
    failed: (error) => {
      if (isConnectionReadError(error)) recovery.failed();
    },
    succeeded: recovery.succeeded,
    settled: recovery.schedule,
  });
  onScopeDispose(reader.dispose);
  return reader;
}
