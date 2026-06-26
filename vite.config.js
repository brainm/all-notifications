import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import { resolve } from "path";
import { copyFileSync, mkdirSync, existsSync, cpSync, readFileSync, writeFileSync } from "fs";
import { glob } from "glob";

function writeSwPrecache() {
  return {
    name: "write-sw-precache",
    writeBundle() {
      const distDir = resolve(__dirname, "dist");
      const manifestPath = resolve(distDir, ".vite", "manifest.json");
      const urls = new Set([
        "./apple-touch-icon.png",
        "./favicon-96x96.png",
        "./favicon.ico",
        "./site.webmanifest",
        "./web-app-manifest-192x192.png",
        "./web-app-manifest-512x512.png",
      ]);

      if (existsSync(manifestPath)) {
        const manifest = JSON.parse(readFileSync(manifestPath, "utf8"));
        for (const entry of Object.values(manifest)) {
          if (entry.file) urls.add(`./${entry.file}`);
          if (entry.css) entry.css.forEach((file) => urls.add(`./${file}`));
          if (entry.assets) entry.assets.forEach((file) => urls.add(`./${file}`));
        }
      }

      writeFileSync(
        resolve(distDir, "sw-precache.js"),
        `self.PRECACHE_URLS=${JSON.stringify([...urls])};\n`
      );
    },
  };
}

function copyDeployFiles() {
  return {
    name: "copy-deploy-files",
    writeBundle() {
      const distDir = resolve(__dirname, "dist");
      const srcDir = resolve(__dirname, "src");
      const rootDir = __dirname;

      if (!existsSync(distDir)) {
        mkdirSync(distDir, { recursive: true });
      }

      // PHP из src/ → dist/ (включая config.php — источник правды)
      glob.sync("**/*.php", { cwd: srcDir }).forEach((file) => {
        const srcPath = resolve(srcDir, file);
        const distPath = resolve(distDir, file);
        mkdirSync(resolve(distPath, ".."), { recursive: true });
        copyFileSync(srcPath, distPath);
      });

      if (existsSync(resolve(srcDir, "cron.sh"))) {
        copyFileSync(resolve(srcDir, "cron.sh"), resolve(distDir, "cron.sh"));
      }

      if (existsSync(resolve(rootDir, "composer.json"))) {
        copyFileSync(resolve(rootDir, "composer.json"), resolve(distDir, "composer.json"));
      }
      if (existsSync(resolve(rootDir, "vendor"))) {
        cpSync(resolve(rootDir, "vendor"), resolve(distDir, "vendor"), { recursive: true });
      }
    },
  };
}

export default defineConfig({
  base: "./",
  plugins: [react(), writeSwPrecache(), copyDeployFiles()],
  build: {
    outDir: "dist",
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: {
        login: resolve(__dirname, "src/entries/login.jsx"),
        dashboard: resolve(__dirname, "src/entries/dashboard.jsx"),
        admin: resolve(__dirname, "src/entries/admin.jsx"),
        register: resolve(__dirname, "src/entries/register.jsx"),
      },
      output: {
        entryFileNames: "assets/[name]-[hash].js",
        chunkFileNames: "assets/[name]-[hash].js",
        assetFileNames: "assets/[name]-[hash].[ext]",
        manualChunks: (id) => {
          if (id.includes("node_modules/react-dom")) return "react-dom";
          if (id.includes("node_modules/react")) return "react";
          if (id.includes("node_modules")) return "vendor";
        },
      },
    },
    copyPublicDir: true,
    assetsDir: "assets",
  },
  publicDir: "public",
  resolve: {
    alias: {
      "@": resolve(__dirname, "src"),
    },
  },
});
