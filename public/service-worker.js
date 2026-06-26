try {
  importScripts("./sw-precache.js");
} catch {
  /* dev / first deploy without build artifact */
}

const CACHE_SHELL = "notifications-shell-v1";
const CACHE_ASSETS = "notifications-assets-v1";

const STATIC_FALLBACK_URLS = [
  "./apple-touch-icon.png",
  "./favicon-96x96.png",
  "./favicon.ico",
  "./site.webmanifest",
  "./web-app-manifest-192x192.png",
  "./web-app-manifest-512x512.png",
];

self.addEventListener("install", (event) => {
  const urls = [...STATIC_FALLBACK_URLS, ...(self.PRECACHE_URLS || [])];
  event.waitUntil(
    caches
      .open(CACHE_ASSETS)
      .then((cache) =>
        Promise.allSettled(
          urls.map((url) => cache.add(new Request(url, { credentials: "same-origin" })))
        )
      )
      .then(() => self.skipWaiting())
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(
          keys
            .filter((key) => key !== CACHE_SHELL && key !== CACHE_ASSETS)
            .map((key) => caches.delete(key))
        )
      )
      .then(() => self.clients.claim())
  );
});

function isNavigationRequest(request) {
  return (
    request.mode === "navigate" ||
    (request.method === "GET" && (request.headers.get("accept") || "").includes("text/html"))
  );
}

function isDashboardPath(pathname) {
  return pathname.endsWith("/dashboard.php") || pathname.endsWith("dashboard.php");
}

function isStaticAssetPath(pathname) {
  return (
    pathname.includes("/assets/") ||
    /\.(js|css|woff2?|ttf|png|svg|ico|webp)$/i.test(pathname)
  );
}

async function networkFirstShell(request) {
  const cache = await caches.open(CACHE_SHELL);
  try {
    const response = await fetch(request);
    if (response.ok) {
      cache.put(request, response.clone());
    }
    return response;
  } catch {
    const cached = await cache.match(request);
    if (cached) return cached;
    return new Response(
      "<!DOCTYPE html><html lang=\"ru\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>Офлайн</title><style>body{font-family:system-ui,sans-serif;margin:2rem;color:#1a1a2e}</style></head><body><h1>Нет сети</h1><p>Откройте приложение снова после подключения к интернету — сохранённые сообщения появятся автоматически.</p></body></html>",
      { headers: { "Content-Type": "text/html; charset=utf-8" } }
    );
  }
}

async function cacheFirstAsset(request) {
  const cache = await caches.open(CACHE_ASSETS);
  const cached = await cache.match(request);
  if (cached) {
    fetch(request)
      .then((response) => {
        if (response.ok) cache.put(request, response.clone());
      })
      .catch(() => {});
    return cached;
  }
  try {
    const response = await fetch(request);
    if (response.ok) cache.put(request, response.clone());
    return response;
  } catch {
    return cached || new Response("", { status: 503, statusText: "Offline" });
  }
}

self.addEventListener("fetch", (event) => {
  if (event.request.method !== "GET") return;
  const url = new URL(event.request.url);
  if (url.origin !== self.location.origin) return;

  if (isNavigationRequest(event.request) && isDashboardPath(url.pathname)) {
    event.respondWith(networkFirstShell(event.request));
    return;
  }

  if (isStaticAssetPath(url.pathname)) {
    event.respondWith(cacheFirstAsset(event.request));
  }
});

self.addEventListener("push", (event) => {
  let data = { title: "Уведомление", body: "", url: "./dashboard.php" };
  try {
    if (event.data) {
      data = Object.assign(data, event.data.json());
    }
  } catch (e) {
    /* ignore */
  }
  event.waitUntil(
    self.registration.showNotification(data.title || "Уведомление", {
      body: data.body || "",
      icon: "./apple-touch-icon.png",
      badge: "./favicon-96x96.png",
      data: { url: data.url || "./dashboard.php", id: data.id || null },
    })
  );
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const data = event.notification.data || {};
  const url = data.url || "./dashboard.php";
  const id = data.id || null;
  event.waitUntil(
    clients.matchAll({ type: "window", includeUncontrolled: true }).then((list) => {
      const dashboardClients = list.filter((client) => client.url.includes("dashboard.php"));
      if (dashboardClients.length) {
        for (const client of dashboardClients) {
          client.postMessage({ type: "notification-open", id, url });
          if ("navigate" in client) {
            client.navigate(url).catch(() => {});
          }
        }
        return dashboardClients[0].focus();
      }
      if (clients.openWindow) {
        return clients.openWindow(url);
      }
    })
  );
});
