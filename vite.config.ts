import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";
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
      "/auth": { target: "http://127.0.0.1:8787" },
      "/files": { target: "http://127.0.0.1:8787" },
      "/ws": { target: "ws://127.0.0.1:8788", ws: true },
    },
  },
});
