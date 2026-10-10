import { Console } from "node:console";
import { Writable } from "node:stream";
import { appendFileSync, existsSync, renameSync, statSync } from "node:fs";
import { join } from "node:path";
import { randomUUID } from "node:crypto";
import { queueDiagnosticArchive } from "./diagnostic-archives";

// Own the writer so rotation closes a segment before compression reads it.
export class CollectorLog extends Writable {
  private sequence = 0;
  constructor(
    readonly directory: string,
    readonly name: string,
    readonly limit = 2_000_000,
  ) {
    super();
  }
  _write(
    chunk: Buffer,
    _encoding: string,
    done: (error?: Error | null) => void,
  ) {
    try {
      const path = join(this.directory, this.name);
      for (let offset = 0; offset < chunk.length;) {
        let size = existsSync(path) ? statSync(path).size : 0;
        if (size >= this.limit) {
          const pending = join(
            this.directory,
            `pending-${Date.now()}-${String(++this.sequence).padStart(12, "0")}-${randomUUID()}-${this.name}`,
          );
          renameSync(path, pending);
          void queueDiagnosticArchive(pending, this.directory);
          size = 0;
        }
        const end = Math.min(chunk.length, offset + this.limit - size);
        appendFileSync(path, chunk.subarray(offset, end), { mode: 0o600 });
        offset = end;
      }
    } catch {
      // A logging failure must never stop collection or lease renewal.
    }
    done();
  }
}
export function installCollectorConsole(directory: string) {
  globalThis.console = new Console(
    new CollectorLog(directory, "worker.stdout.log"),
    new CollectorLog(directory, "worker.stderr.log"),
  );
}
