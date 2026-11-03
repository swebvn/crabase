import { defineConfig, loadEnv } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";
// Same port variables as the PHP server, read from the repository .env.
const env = loadEnv("", process.cwd(), "CRABASE_");
const httpPort = env.CRABASE_HTTP_PORT || "8787";
const wsPort = env.CRABASE_WS_PORT || "8788";
export default defineConfig({
  root: "web",
  plugins: [react(), tailwindcss()],
  resolve: { dedupe: ["react", "react-dom"] },
  // Prebundle lazy editor entries with the app so they share the same React runtime.
  optimizeDeps: { include: ["@pierre/diffs/react", "@pierre/diffs/edit", "@pierre/trees/react"] },
  build: { outDir: "../server/public", emptyOutDir: true },
  server: {
    port: 5173,
    strictPort: true,
    proxy: {
      "/auth": { target: `http://127.0.0.1:${httpPort}` },
      "/files": { target: `http://127.0.0.1:${httpPort}` },
      "/ws": { target: `ws://127.0.0.1:${wsPort}`, ws: true },
    },
  },
});
