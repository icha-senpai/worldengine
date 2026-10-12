import { describe, expect, it } from "vitest";
import {
  assertTemporaryDatabase,
  removeOwnedDirectory,
} from "../scripts/temporary-database";
import { mkdtemp, readFile, rm, symlink, writeFile } from "node:fs/promises";
import { join, resolve } from "node:path";
import { tmpdir } from "node:os";

describe("temporary database cleanup boundaries", () => {
  it("rejects every persistent database and malformed temporary name", () => {
    for (const name of [
      "space-evergather",
      "space-evergather-test",
      "space-evergather-checks",
      "space-bitcraft",
      "space-bitcraft-tools",
      "space-bitcraft-checks",
      "space-evergather-checks-../1",
      "space-evergather-checks-1-extra",
    ])
      expect(() => assertTemporaryDatabase(name)).toThrow();
    for (const name of [
      "checks",
      "loadtest",
      "catalog-checks",
      "economy-checks",
      "migration-checks",
    ])
      expect(() =>
        assertTemporaryDatabase(`space-evergather-${name}-123`),
      ).not.toThrow();
  });
  it("refuses a directory outside the verified runtime target", async () => {
    const outside = await mkdtemp(join(tmpdir(), "space-cleanup-boundary-"));
    try {
      await writeFile(join(outside, "keep"), "retained");
      await expect(removeOwnedDirectory(outside, outside)).rejects.toThrow();
      expect(await readFile(join(outside, "keep"), "utf8")).toBe("retained");
    } finally {
      await rm(outside, { recursive: true });
    }
  });
  it("refuses linked children without deleting their destination", async () => {
    const root = await mkdtemp(resolve(".runtime/economy-checks-module-"));
    const destination = await mkdtemp(
      resolve(".runtime/economy-checks-module-"),
    );
    try {
      await writeFile(join(destination, "keep"), "retained");
      await symlink(destination, join(root, "link"), "junction");
      await expect(removeOwnedDirectory(root, root)).rejects.toThrow("Linked");
      expect(await readFile(join(destination, "keep"), "utf8")).toBe(
        "retained",
      );
    } finally {
      await rm(join(root, "link"));
      await rm(root, { recursive: true });
      await rm(destination, { recursive: true });
    }
  });
});
