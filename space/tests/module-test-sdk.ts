import { fileURLToPath } from "node:url";

const sdk = fileURLToPath(
  new URL("../node_modules/spacetimedb/", import.meta.url),
).replaceAll("\\", "/");

// The 2.12 bundled harness embeds its own Range class. Test the SDK sources
// together so its instanceof checks see the same class as the module does.
export const moduleTestSdk = {
  resolve: {
    alias: [
      {
        find: /^spacetimedb\/server\/test-utils$/,
        replacement: `${sdk}src/server/test-utils/index.ts`,
      },
      {
        find: /^spacetimedb\/server$/,
        replacement: `${sdk}src/server/index.ts`,
      },
      { find: /^spacetimedb$/, replacement: `${sdk}src/index.ts` },
    ],
  },
  plugin: {
    name: "module-test-portable-datastore",
    transform(code: string, id: string) {
      if (
        !id
          .replaceAll("\\", "/")
          .endsWith("/server/test-utils/default_wasm_runtime.ts")
      )
        return;
      // npm ships the portable datastore binary beside dist, not src.
      return code.replace(
        /["']\.\/portable-datastore-wasm\/spacetimedb_portable_datastore_wasm\.cjs["']/g,
        JSON.stringify(
          `${sdk}dist/server/test-utils/portable-datastore-wasm/spacetimedb_portable_datastore_wasm.cjs`,
        ),
      );
    },
  },
};
