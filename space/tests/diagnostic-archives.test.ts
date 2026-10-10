import { afterEach, expect, test } from "vitest";
import {
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
  existsSync,
  readdirSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { gunzipSync } from "node:zlib";
import {
  archiveDiagnosticFile,
  pruneDiagnosticArchives,
} from "../scripts/diagnostic-archives";
const paths: string[] = [];
const directory = () => {
  const path = mkdtempSync(join(tmpdir(), "diagnostic-archive-"));
  paths.push(path);
  return path;
};
afterEach(() => {
  for (const path of paths.splice(0))
    rmSync(path, { recursive: true, force: true });
});
test("maximum gzip compression round-trips log content before removing the source", async () => {
  const dir = directory(),
    source = join(dir, "events.jsonl");
  const text = '{"event":"sample","rss":1000000}\n'.repeat(100000);
  writeFileSync(source, text);
  const archive = (await archiveDiagnosticFile(source, dir))!;
  expect(existsSync(source)).toBe(false);
  expect(gunzipSync(readFileSync(archive)).toString()).toBe(text);
  const meta = JSON.parse(readFileSync(archive + ".json", "utf8"));
  expect(meta.originalBytes).toBe(Buffer.byteLength(text));
  expect(meta.compressedBytes).toBeLessThan(meta.originalBytes / 100);
  expect(meta.compression).toBe("gzip-level-9");
  expect(meta.sha256).toMatch(/^[a-f0-9]{64}$/);
});
test("active logs remain available and retention prunes oldest archive pairs", async () => {
  const dir = directory(),
    source = join(dir, "worker.log");
  writeFileSync(source, "initial log\n".repeat(10000));
  for (let i = 0; i < 3; i++) await archiveDiagnosticFile(source, dir, false);
  expect(existsSync(source)).toBe(true);
  const before = readdirSync(dir)
    .filter((n) => n.endsWith(".gz"))
    .sort();
  await pruneDiagnosticArchives(dir, 250000000, 2);
  expect(
    readdirSync(dir)
      .filter((n) => n.endsWith(".gz"))
      .sort(),
  ).toEqual(before.slice(-2));
  expect(existsSync(join(dir, before[0]!) + ".json")).toBe(false);
  await pruneDiagnosticArchives(dir, 1, 100);
  expect(readdirSync(dir).filter((n) => n.endsWith(".gz"))).toHaveLength(0);
});
