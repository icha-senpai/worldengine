import {
  appendFileSync,
  existsSync,
  mkdirSync,
  renameSync,
  rmSync,
  statSync,
  writeFileSync,
} from "node:fs";
import { join, resolve } from "node:path";
import { release, freemem } from "node:os";
import { getHeapStatistics } from "node:v8";
import { randomUUID } from "node:crypto";
import { queueDiagnosticArchive } from "./diagnostic-archives";

export type CollectorPhase =
  | "startup"
  | "storage-check"
  | "maintenance"
  | "storage-lease"
  | "storage-monitor"
  | "database-connect"
  | "ready"
  | "relay-tick"
  | "market-refresh"
  | "api-refresh"
  | "idle"
  | "cycle-ended"
  | "stopping";
export type DiagnosticEvent =
  | "startup"
  | "sample"
  | "ready"
  | "cycle-error"
  | "storage-error"
  | "maintenance-start"
  | "maintenance-end"
  | "relay-error"
  | "relay-publication"
  | "signal"
  | "uncaught-exception"
  | "exit";
export function safeError(error: unknown) {
  // Never serialize messages, causes or arbitrary fields: upstream errors can contain payloads.
  const value = error instanceof Error ? error : undefined;
  return {
    name: value?.name.match(/^[A-Za-z]+Error$/)?.[0] ?? "Error",
    code:
      typeof (value as any)?.code === "string" &&
      /^[A-Z_0-9]{1,60}$/.test((value as any).code)
        ? (value as any).code
        : undefined,
    stack: value?.stack
      ?.split("\n")
      .filter((line) => /^\s+at /.test(line))
      .slice(0, 12)
      .map((line) => line.slice(0, 300)),
  };
}
export class CollectorDiagnostics {
  private phaseName: CollectorPhase = "startup";
  private phaseAt = Date.now();
  private storagePhase: CollectorPhase = "startup";
  private storageAt = Date.now();
  private timer?: ReturnType<typeof setInterval>;
  private lastSample?: Record<string, unknown>;
  private events: Record<string, number> = {};
  readonly directory: string;
  constructor(
    readonly database: string,
    options: {
      directory?: string;
      hooks?: boolean;
      intervalMs?: number;
      logBytes?: number;
    } = {},
  ) {
    if (!/^[a-z0-9]+(-[a-z0-9]+)*$/.test(database))
      throw Error("Invalid diagnostics database.");
    this.directory =
      options.directory ?? resolve(".runtime/diagnostics", database);
    this.logBytes = Math.min(options.logBytes ?? 2_000_000, 1_000_000_000);
    try {
      mkdirSync(this.directory, { recursive: true, mode: 0o700 });
      this.rotateReport();
    } catch {
      console.error(
        "Collector diagnostics unavailable; collection keeps its existing safety checks.",
      );
    }
    this.event("startup");
    this.sample();
    if (options.hooks !== false) {
      process.report.directory = this.directory;
      process.report.filename = "fatal-report.json";
      process.report.excludeEnv = true;
      (
        process.report as typeof process.report & { excludeNetwork: boolean }
      ).excludeNetwork = true;
      process.report.reportOnFatalError = true;
      // Observe without replacing Node's default crash/exit behavior.
      process.on("uncaughtExceptionMonitor", this.onException);
      process.on("exit", this.onExit);
      this.timer = setInterval(
        () => this.sample(),
        options.intervalMs ?? 30000,
      );
      this.timer.unref();
    }
  }
  private logBytes: number;
  phase(phase: CollectorPhase) {
    this.phaseName = phase;
    this.phaseAt = Date.now();
  }
  storage(phase: CollectorPhase) {
    this.storagePhase = phase;
    this.storageAt = Date.now();
  }
  event(event: DiagnosticEvent, error?: unknown, region?: number) {
    this.events[event] = (this.events[event] ?? 0) + 1;
    this.append({
      at: new Date().toISOString(),
      pid: process.pid,
      event,
      phase: this.phaseName,
      region: Number.isInteger(region) ? region : undefined,
      error: error === undefined ? undefined : safeError(error),
    });
  }
  sample() {
    const now = Date.now();
    this.lastSample = {
      at: new Date(now).toISOString(),
      pid: process.pid,
      database: this.database,
      node: process.version,
      v8: process.versions.v8,
      uv: process.versions.uv,
      platform: process.platform,
      osRelease: release(),
      arch: process.arch,
      phase: this.phaseName,
      phaseAgeMs: now - this.phaseAt,
      storagePhase: this.storagePhase,
      storagePhaseAgeMs: now - this.storageAt,
      uptimeSeconds: Math.round(process.uptime()),
      memory: process.memoryUsage(),
      heapLimitBytes: getHeapStatistics().heap_size_limit,
      freeSystemMemoryBytes: freemem(),
      resources: process
        .getActiveResourcesInfo()
        .reduce<Record<string, number>>((counts, name) => {
          counts[name] = (counts[name] ?? 0) + 1;
          return counts;
        }, {}),
      events: { ...this.events },
    };
    this.append({ event: "sample", ...this.lastSample });
    this.persist();
    return this.lastSample;
  }
  private persist() {
    try {
      const path = join(this.directory, "latest.json");
      writeFileSync(path + ".tmp", JSON.stringify(this.lastSample), {
        mode: 0o600,
      });
      renameSync(path + ".tmp", path);
    } catch {
      /* Diagnostics must not interrupt collection or storage guards. */
    }
  }
  private append(row: unknown) {
    try {
      const path = join(this.directory, "events.jsonl");
      const line = JSON.stringify(row) + "\n";
      if (
        existsSync(path) &&
        statSync(path).size + Buffer.byteLength(line) > this.logBytes
      ) {
        this.archivePending(path + ".2");
        if (existsSync(path + ".1")) renameSync(path + ".1", path + ".2");
        renameSync(path, path + ".1");
      }
      appendFileSync(path, line, { mode: 0o600 });
    } catch {
      /* Keep the worker's original failure behavior. */
    }
  }
  private rotateReport() {
    // Fixed filename bounds generation; retain two previous reports, each <=8 MB.
    for (const name of ["fatal-report.json", "report-1.json", "report-2.json"])
      if (
        existsSync(join(this.directory, name)) &&
        statSync(join(this.directory, name)).size > 8_000_000
      )
        this.archivePending(join(this.directory, name));
    const current = join(this.directory, "fatal-report.json");
    if (existsSync(current)) {
      this.archivePending(join(this.directory, "report-2.json"));
      if (existsSync(join(this.directory, "report-1.json")))
        renameSync(
          join(this.directory, "report-1.json"),
          join(this.directory, "report-2.json"),
        );
      renameSync(current, join(this.directory, "report-1.json"));
    }
  }
  private archivePending(path: string) {
    if (!existsSync(path)) return;
    const pending = join(
      this.directory,
      `pending-${Date.now()}-${randomUUID()}-${path.split(/[\\/]/).at(-1)}`,
    );
    renameSync(path, pending);
    void queueDiagnosticArchive(pending, this.directory);
  }
  private onException = (error: Error) => {
    this.event("uncaught-exception", error);
    this.sample();
  };
  private onExit = (code: number) => {
    this.event("exit");
    this.lastSample = {
      ...this.lastSample,
      exitedAt: new Date().toISOString(),
      exitCode: code,
    };
    this.persist();
  };
  close() {
    if (this.timer) clearInterval(this.timer);
    process.off("uncaughtExceptionMonitor", this.onException);
    process.off("exit", this.onExit);
  }
}
