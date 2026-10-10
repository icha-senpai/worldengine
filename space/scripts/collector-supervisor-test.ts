import assert from "node:assert/strict";
import { spawn, execFileSync } from "node:child_process";
import { mkdir, readFile, rm } from "node:fs/promises";
import { resolve } from "node:path";

const service = (action: "Start" | "Stop" | "Restart") =>
  execFileSync(
    "powershell.exe",
    [
      "-NoProfile",
      "-ExecutionPolicy",
      "Bypass",
      "-File",
      resolve("scripts/collector-services.ps1"),
      "-Action",
      action,
      "-Target",
      "Test",
    ],
    { stdio: "inherit", windowsHide: true },
  );
const text = (name: string) =>
  readFile(resolve(".runtime/services", name), "utf8");
const pid = async (name: string) => Number((await text(name)).trim());
const ready = async () => JSON.parse(await text("collector-test.ready.json"));
const sleep = (ms: number) => new Promise((done) => setTimeout(done, ms));
const mainBefore = await text("collector.pid");
const testBefore = await pid("collector-test.pid");
try {
  service("Start");
  assert.equal(
    await pid("collector-test.pid"),
    testBefore,
    "Start adopts the existing test worker.",
  );
  const supervisor = await pid("collector-test.supervisor.pid");
  service("Start");
  assert.equal(
    await pid("collector-test.supervisor.pid"),
    supervisor,
    "Repeated Start does not duplicate supervision.",
  );
  // Simulate an unexpected exit of the verified test worker, leaving main intact.
  execFileSync(
    "powershell.exe",
    [
      "-NoProfile",
      "-Command",
      `Stop-Process -Id ${testBefore} -ErrorAction Stop`,
    ],
    { windowsHide: true },
  );
  let replacement: number | undefined;
  const deadline = Date.now() + 45000;
  while (Date.now() < deadline) {
    try {
      const state = await ready();
      const current = await pid("collector-test.pid");
      if (current !== testBefore && state.pid === current) {
        assert.equal(state.database, "space-bitcraft-checks");
        assert.equal(state.mode, "storage");
        replacement = current;
        break;
      }
    } catch {
      /* Supervisor removes stale readiness during restart. */
    }
    await sleep(300);
  }
  assert.ok(
    replacement,
    "Unexpected exit must produce an authenticated replacement guard.",
  );
  assert.equal(await pid("collector-test.supervisor.pid"), supervisor);
  const exit = JSON.parse(
    await readFile(
      resolve(".runtime/diagnostics/space-bitcraft-checks/exit.json"),
      "utf8",
    ),
  );
  assert.equal(exit.pid, testBefore);
  assert.equal(exit.lastSample.pid, testBefore);
  assert.ok(exit.lastSample.memory.rss > 0);
  assert.match(exit.exitCodeHex, /^0x[0-9A-F]{8}$/);
  assert.ok(exit.recentEvents.length > 0);
  service("Stop");
  await sleep(8000);
  await assert.rejects(text("collector-test.pid"), /ENOENT/);
  await assert.rejects(text("collector-test.ready.json"), /ENOENT/);
  await assert.rejects(text("collector-test.supervisor.pid"), /ENOENT/);
  assert.equal(
    await text("collector.pid"),
    mainBefore,
    "Main worker remains unchanged.",
  );
  // Force a safe preflight error, then remove it and verify the SAME supervisor recovers.
  const pending = resolve(
    ".runtime/diagnostics/space-bitcraft-checks/pending-9999999999999-launch-retry-test",
  );
  const logBefore = await text("collector-test.supervisor.log");
  await mkdir(pending);
  const launched = new Promise<void>((done, fail) => {
    // No captured pipes for a command that starts a persistent supervisor.
    const child = spawn(
      "powershell.exe",
      [
        "-NoProfile",
        "-ExecutionPolicy",
        "Bypass",
        "-File",
        resolve("scripts/collector-services.ps1"),
        "-Action",
        "Start",
        "-Target",
        "Test",
      ],
      { windowsHide: true, stdio: "ignore" },
    );
    const timer = setTimeout(() => {
      child.kill();
      fail(Error("Test startup timed out."));
    }, 100000);
    child.once("error", (error) => {
      clearTimeout(timer);
      fail(error);
    });
    child.once("exit", (code) => {
      clearTimeout(timer);
      if (code === 0) done();
      else fail(Error(`Test startup exited ${code}.`));
    });
  });
  // Observe immediately so a failed subprocess cannot become an unhandled rejection.
  const completion = launched.then(
    (result) => ({ result }),
    (error) => ({ error }),
  );
  let retryObserved = false;
  try {
    const deadline = Date.now() + 30000;
    while (Date.now() < deadline) {
      const log = await text("collector-test.supervisor.log");
      if (log.slice(logBefore.length).includes("Worker launch failed:")) {
        retryObserved = true;
        break;
      }
      await sleep(200);
    }
    assert.ok(
      retryObserved,
      "A failed archive preflight must be recorded and retried.",
    );
  } finally {
    // Exact test-owned directory; no DB data or other diagnostic files are removed.
    await rm(pending, { recursive: true, force: true });
  }
  const completed = await completion;
  if ("error" in completed) throw completed.error;
  assert.equal((await ready()).mode, "storage");
  assert.equal(await text("collector.pid"), mainBefore);
  console.log(
    "Archive preflight retry verified; storage guard recovered without affecting main.",
  );
  console.log(
    "Supervisor verified: adopts existing worker, avoids duplicates, restarts unexpected exit, respects deliberate Stop, preserves main worker.",
  );
} finally {
  service("Start");
}
