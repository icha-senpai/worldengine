import { defineConfig } from "vitest/config";
import vue from "@vitejs/plugin-vue";
import { spacetimedbModuleTestPlugin } from "spacetimedb/server/test-utils/vitest";

export default defineConfig({
  plugins: [vue(), spacetimedbModuleTestPlugin()],
  test: { include: ["tests/**/*.test.{js,ts,mjs}"] },
});
