import { readFileSync, writeFileSync, existsSync, mkdirSync } from "fs";
import { resolve, dirname } from "path";
import { fileURLToPath } from "url";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const distDir = resolve(root, "dist");
const envPath = resolve(root, ".env");

function parseEnv(content) {
  const vars = {};
  for (const line of content.split(/\r?\n/)) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith("#")) continue;
    const eq = trimmed.indexOf("=");
    if (eq === -1) continue;
    const key = trimmed.slice(0, eq).trim();
    let value = trimmed.slice(eq + 1).trim();
    if (
      value.length >= 2 &&
      ((value.startsWith('"') && value.endsWith('"')) ||
        (value.startsWith("'") && value.endsWith("'")))
    ) {
      value = value.slice(1, -1);
    }
    vars[key] = value;
  }
  return vars;
}

function quoteEnv(value) {
  return `"${String(value).replace(/\\/g, "\\\\").replace(/"/g, '\\"')}"`;
}

if (!existsSync(envPath)) {
  console.error("write-dist-env: .env не найден в корне проекта");
  process.exit(1);
}

const vars = parseEnv(readFileSync(envPath, "utf8"));
const login = vars.ADMIN_LOGIN;
const password = vars.ADMIN_PASSWORD;
const vapidPublic = vars.WEB_PUSH_PUBLIC_KEY;
const vapidPrivate = vars.WEB_PUSH_PRIVATE_KEY;

if (!login || !password) {
  console.error("write-dist-env: в .env нужны ADMIN_LOGIN и ADMIN_PASSWORD");
  process.exit(1);
}
if (!vapidPublic || !vapidPrivate) {
  console.error("write-dist-env: в .env нужны WEB_PUSH_PUBLIC_KEY и WEB_PUSH_PRIVATE_KEY");
  process.exit(1);
}

if (!existsSync(distDir)) {
  mkdirSync(distDir, { recursive: true });
}

const lines = [
  `ADMIN_LOGIN=${quoteEnv(login)}`,
  `ADMIN_PASSWORD=${quoteEnv(password)}`,
  `WEB_PUSH_PUBLIC_KEY=${quoteEnv(vapidPublic)}`,
  `WEB_PUSH_PRIVATE_KEY=${quoteEnv(vapidPrivate)}`,
];
if (vars.APP_DEBUG) {
  lines.push(`APP_DEBUG=${quoteEnv(vars.APP_DEBUG)}`);
}
const content = lines.join("\n") + "\n";
writeFileSync(resolve(distDir, ".env"), content, { mode: 0o644 });
console.log("write-dist-env: dist/.env создан");
