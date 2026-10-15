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
test("closed retired segments are discarded without saving archive copies", async () => {
  const dir = directory(),
    source = join(dir, "events.jsonl");
  const text = '{"event":"sample","rss":1000000}\n'.repeat(100000);
  writeFileSync(source, text);
  await archiveDiagnosticFile(source, dir);
  expect(existsSync(source)).toBe(false);
  expect(readdirSync(dir)).toEqual([]);
});
test("active logs remain available without archive copies", async () => {
  const dir = directory(),
    source = join(dir, "worker.log");
  writeFileSync(source, "initial log\n".repeat(10000));
  for (let i = 0; i < 3; i++) await archiveDiagnosticFile(source, dir, false);
  expect(existsSync(source)).toBe(true);
  expect(readFileSync(source, "utf8")).toBe("initial log\n".repeat(10000));
  expect(readdirSync(dir)).toEqual(["worker.log"]);
});
test("legacy archive pairs are pruned while current status survives", async () => {
  const dir = directory();
  for (const name of [
    "archive-123-old.gz",
    "archive-123-old.gz.json",
    "latest.json",
    "worker.log",
  ])
    writeFileSync(join(dir, name), "retained");
  await pruneDiagnosticArchives(dir);
  expect(readdirSync(dir).sort()).toEqual(["latest.json", "worker.log"]);
  await expect(archiveDiagnosticFile(dir, dir)).rejects.toThrow("regular file");
});
