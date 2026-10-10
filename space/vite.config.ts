import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";
import tailwindcss from "@tailwindcss/vite";

const proxy = {
  "/v1": {
    target: "http://127.0.0.1:3100",
    ws: true,
    changeOrigin: true,
  },
};

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue(), tailwindcss()],
  server: {
    host: "127.0.0.1",
    port: 5180,
    strictPort: true,
    allowedHosts: ["space.test", "space.ichaa.dev"],
    proxy,
  },
  preview: {
    host: "127.0.0.1",
    port: 5180,
    strictPort: true,
    allowedHosts: ["space.test", "space.ichaa.dev"],
    proxy,
  },
});
