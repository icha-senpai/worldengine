import { defineConfig } from "vitest/config";
import { spacetimedbModuleTestPlugin } from "spacetimedb/server/test-utils/vitest";
import { moduleTestSdk } from "./tests/module-test-sdk.ts";

export default defineConfig({
  resolve: moduleTestSdk.resolve,
  plugins: [spacetimedbModuleTestPlugin(), moduleTestSdk.plugin],
  test: {
    benchmark: { include: ["tests/module-profile.bench.ts"] },
  },
});
