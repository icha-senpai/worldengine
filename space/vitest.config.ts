import { defineConfig } from "vitest/config";
import vue from "@vitejs/plugin-vue";
import { spacetimedbModuleTestPlugin } from "spacetimedb/server/test-utils/vitest";
import { moduleTestSdk } from "./tests/module-test-sdk.ts";

export default defineConfig({
  resolve: moduleTestSdk.resolve,
  plugins: [vue(), spacetimedbModuleTestPlugin(), moduleTestSdk.plugin],
  test: { include: ["tests/**/*.test.{js,ts,mjs}"] },
});
