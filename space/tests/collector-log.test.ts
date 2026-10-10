import { expect, test } from "vitest";
import { mkdtemp, readFile, readdir, rm, stat } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { gunzipSync } from "node:zlib";
import { CollectorLog } from "../scripts/collector-log";
import { waitForDiagnosticArchives } from "../scripts/diagnostic-archives";
test("rotation preserves all writes across closed compressed segments", async () => {
  const dir = await mkdtemp(join(tmpdir(), "collector-log-"));
  try {
    const log = new CollectorLog(dir, "worker.log", 1000);
    const input = Buffer.from("first\n".repeat(500) + "second\n".repeat(500));
    log.write(input.subarray(0, 3000));
    log.write(input.subarray(3000));
    await waitForDiagnosticArchives();
    const names = (await readdir(dir)).filter((n) => n.endsWith(".gz"));
    const segments = await Promise.all(
      names.map(async (name) => ({
        name,
        meta: JSON.parse(await readFile(join(dir, name) + ".json", "utf8")),
        bytes: gunzipSync(await readFile(join(dir, name))),
      })),
    );
    // UUID filenames can share a millisecond; source names carry segment order.
    segments.sort((a, b) => a.meta.source.localeCompare(b.meta.source));
    const tail = await readFile(join(dir, "worker.log"));
    expect((await stat(join(dir, "worker.log"))).size).toBeLessThanOrEqual(
      1000,
    );
    expect(Buffer.concat([...segments.map((s) => s.bytes), tail])).toEqual(
      input,
    );
  } finally {
    await waitForDiagnosticArchives();
    await rm(dir, { recursive: true, force: true });
  }
});
test("a failed log destination does not fail the worker write", () => {
  const log = new CollectorLog(
    join(tmpdir(), "missing-parent-collector-test", "nested"),
    "worker.log",
  );
  let error: unknown;
  log.write("still running", (result) => (error = result));
  expect(error).toBeUndefined();
});
