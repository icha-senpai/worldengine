// Disconnecting the SDK does not necessarily settle an outstanding reducer or
// procedure promise. A collection cycle must be interruptible independently so
// maintenance can reach the next preflight instead of waiting on that promise.
export class CollectorCycle {
  private controller = new AbortController();

  get signal() {
    return this.controller.signal;
  }

  get stopped() {
    return this.signal.aborted;
  }

  stop(message: string) {
    if (!this.stopped) this.controller.abort(new Error(message));
  }

  wait<T>(operation: () => Promise<T>, timeoutMs = 45000): Promise<T> {
    if (this.stopped) return Promise.reject(this.signal.reason);
    return new Promise<T>((resolve, reject) => {
      const cleanup = () => {
        clearTimeout(timer);
        this.signal.removeEventListener("abort", interrupted);
      };
      const interrupted = () => {
        cleanup();
        reject(this.signal.reason);
      };
      const timer = setTimeout(
        () => this.stop("Collector operation timed out; reconnecting."),
        timeoutMs,
      );
      this.signal.addEventListener("abort", interrupted, { once: true });
      Promise.resolve()
        .then(() => {
          if (this.stopped) throw this.signal.reason;
          return operation();
        })
        .then(
          (result) => {
            cleanup();
            resolve(result);
          },
          (error) => {
            cleanup();
            reject(error);
          },
        );
    });
  }
}
