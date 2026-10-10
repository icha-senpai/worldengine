import { afterEach, expect, test } from "vitest";
import {
  mkdtempSync,
  readFileSync,
  readdirSync,
  rmSync,
  statSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { execFileSync } from "node:child_process";
import { resolve } from "node:path";
import {
  CollectorDiagnostics,
  safeError,
} from "../scripts/collector-diagnostics";
import { waitForDiagnosticArchives } from "../scripts/diagnostic-archives";

const directories: string[] = [];
const directory = () => {
  const path = mkdtempSync(join(tmpdir(), "collector-diagnostics-"));
  directories.push(path);
  return path;
};
afterEach(async () => {
  await waitForDiagnosticArchives();
  for (const path of directories.splice(0))
    rmSync(path, { recursive: true, force: true });
});
test("diagnostics retain aggregate state and stack locations, never arbitrary error data", () => {
  const path = directory();
  const diagnostics = new CollectorDiagnostics("space-bitcraft-checks", {
    directory: path,
    hooks: false,
  });
  diagnostics.phase("market-refresh");
  const error = Object.assign(new Error("secret-token player-id payload"), {
    code: "EIO",
    payload: "private-data",
  });
  diagnostics.event("cycle-error", error);
  diagnostics.sample();
  const latest = JSON.parse(readFileSync(join(path, "latest.json"), "utf8"));
  expect(latest.phase).toBe("market-refresh");
  expect(latest.memory.rss).toBeGreaterThan(0);
  expect(latest.node).toBe(process.version);
  const events = readFileSync(join(path, "events.jsonl"), "utf8");
  expect(events).toContain('"code":"EIO"');
  expect(events).not.toMatch(/secret-token|player-id|private-data|payload/);
  expect(safeError("secret-token").name).toBe("Error");
});
test("event logs rotate to three bounded files and reports retain two previous generations", () => {
  const path = directory();
  const diagnostics = new CollectorDiagnostics("space-bitcraft-checks", {
    directory: path,
    hooks: false,
    logBytes: 2048,
  });
  for (let i = 0; i < 100; i++)
    diagnostics.event("relay-error", new Error("secret"), 9);
  const files = readdirSync(path).filter((name) =>
    name.startsWith("events.jsonl"),
  );
  expect(files.length).toBe(3);
  for (const file of files)
    expect(statSync(join(path, file)).size).toBeLessThanOrEqual(2048);
  for (let i = 0; i < 4; i++) {
    writeFileSync(
      join(path, "fatal-report.json"),
      JSON.stringify({ generation: i }),
    );
    new CollectorDiagnostics("space-bitcraft-checks", {
      directory: path,
      hooks: false,
    });
  }
  expect(
    JSON.parse(readFileSync(join(path, "report-1.json"), "utf8")).generation,
  ).toBe(3);
  expect(
    JSON.parse(readFileSync(join(path, "report-2.json"), "utf8")).generation,
  ).toBe(2);
  expect(readdirSync(path).filter((name) => /^report-/.test(name)).length).toBe(
    2,
  );
});
test("a real fatal heap failure produces a report without environment variables", () => {
  const path = directory();
  let exited = false;
  try {
    execFileSync(
      process.execPath,
      [
        "--max-old-space-size=16",
        "--report-on-fatalerror",
        "--report-exclude-env",
        "--report-exclude-network",
        `--report-directory=${path}`,
        "--report-filename=fatal-report.json",
        "-e",
        "const a=[];while(true)a.push(new Array(100000).fill(1));",
      ],
      {
        timeout: 15000,
        windowsHide: true,
        stdio: ["ignore", "pipe", "pipe"],
        env: { ...process.env, COLLECTOR_DIAGNOSTIC_SECRET: "must-not-appear" },
      },
    );
  } catch {
    exited = true;
  }
  expect(exited).toBe(true);
  const text = readFileSync(join(path, "fatal-report.json"), "utf8");
  const report = JSON.parse(text);
  expect(report.nativeStack.length).toBeGreaterThan(0);
  expect(report.javascriptHeap.memoryLimit).toBeGreaterThan(0);
  expect(report.environmentVariables).toBeUndefined();
  expect(report.header.networkInterfaces).toBeUndefined();
  expect(text).not.toContain("must-not-appear");
});
test("uncaught exception monitoring saves evidence and preserves failing exit", () => {
  const path = directory();
  const fixture = join(path, "uncaught.ts");
  const module = resolve("scripts/collector-diagnostics.ts").replaceAll(
    "\\",
    "/",
  );
  writeFileSync(
    fixture,
    `import { CollectorDiagnostics } from ${JSON.stringify(module)}; new CollectorDiagnostics('space-bitcraft-checks', {directory: ${JSON.stringify(path)}}); throw new Error('private-player-and-token');`,
  );
  let exitCode: number | null = null;
  try {
    execFileSync(process.execPath, ["--import", "tsx", fixture], {
      timeout: 15000,
      windowsHide: true,
      stdio: ["ignore", "pipe", "pipe"],
    });
  } catch (error: any) {
    exitCode = error.status;
  }
  expect(exitCode).toBe(1);
  const events = readFileSync(join(path, "events.jsonl"), "utf8");
  expect(events).toContain('"event":"uncaught-exception"');
  expect(events).not.toContain("private-player-and-token");
  expect(
    JSON.parse(readFileSync(join(path, "latest.json"), "utf8")).exitCode,
  ).toBe(1);
});
