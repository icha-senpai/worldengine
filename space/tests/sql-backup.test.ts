import { describe, expect, it } from "vitest";
import { mkdtemp, readFile, rm } from "node:fs/promises";
import { join } from "node:path";
import { tmpdir } from "node:os";
import { backupHash, saveSqlBackup } from "../scripts/sql-backup";

describe("SQL backup integrity", () => {
  it("hashes the exact saved bytes without rounding large integers", async () => {
    const directory = await mkdtemp(join(tmpdir(), "space-backup-integrity-"));
    try {
      const raw = '[{"rows":[[18446744073709551615]],"schema":{}}]\n';
      const metadata = await saveSqlBackup(directory, "player", raw);
      expect(await readFile(join(directory, "player.json"), "utf8")).toBe(raw);
      expect(metadata).toEqual({ rows: 1, sha256: backupHash(raw) });
      await expect(saveSqlBackup(directory, "player", raw)).rejects.toThrow();
      await expect(
        saveSqlBackup(directory, "../outside", raw),
      ).rejects.toThrow();
    } finally {
      await rm(directory, { recursive: true });
    }
  });
});
