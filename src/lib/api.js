const API = "api.php";

const NO_AUTH_REDIRECT_ACTIONS = new Set(["login", "register_info", "register_complete", "admin_login"]);

function redirectOnUnauthorized(action) {
  if (NO_AUTH_REDIRECT_ACTIONS.has(action)) return;
  if (action.startsWith("admin_")) {
    window.location.replace("admin.php?expired=1");
    return;
  }
  window.location.replace("login.php?expired=1");
}

async function handleApiResponse(res, action) {
  const data = await res.json().catch(() => ({}));
  if (res.status === 401) {
    redirectOnUnauthorized(action);
    throw new Error("SESSION_EXPIRED");
  }
  if (!res.ok) throw new Error(data.error || res.statusText);
  return data;
}

export async function apiGet(action, params = {}) {
  const qs = new URLSearchParams({ action, ...params });
  const res = await fetch(`${API}?${qs}`, { credentials: "same-origin" });
  return handleApiResponse(res, action);
}

export async function apiPost(action, body, asJson = false) {
  const url = `${API}?action=${encodeURIComponent(action)}`;
  const opts = { method: "POST", credentials: "same-origin" };
  if (asJson) {
    opts.headers = { "Content-Type": "application/json" };
    opts.body = JSON.stringify(body);
  } else {
    opts.headers = { "Content-Type": "application/x-www-form-urlencoded" };
    opts.body = new URLSearchParams(body);
  }
  const res = await fetch(url, opts);
  return handleApiResponse(res, action);
}

export function urlBase64ToUint8Array(base64String) {
  const padding = "=".repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, "+").replace(/_/g, "/");
  const raw = atob(base64);
  const arr = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
  return arr;
}

import {
  dismissAddToHomeScreenGuide,
  dismissAndroidPushGuide,
  isAddToHomeScreenGuideDismissed,
  isAndroid,
  isAndroidPushGuideDismissed,
  needsAddToHomeScreenGuide,
  needsAndroidPushGuide,
} from "../lib/pwa";

function pushUnsupportedMessage() {
  if (!("serviceWorker" in navigator)) {
    return "Service Worker недоступен в этом браузере";
  }
  if (!("PushManager" in window)) {
    if (needsAddToHomeScreenGuide()) {
      return "На iPhone push работает только с экрана «Домой». Откройте инструкцию ниже.";
    }
    if (isAndroid()) {
      return "Откройте сайт в Chrome — в этом браузере push недоступен.";
    }
    return "Браузер не поддерживает Web Push";
  }
  return null;
}

export async function registerPush(config) {
  const unsupported = pushUnsupportedMessage();
  if (unsupported) {
    throw new Error(unsupported);
  }
  const perm = await Notification.requestPermission();
  if (perm !== "granted") throw new Error("Разрешение не выдано");

  const reg = await navigator.serviceWorker.register(config.swPath, { scope: config.scope });
  await navigator.serviceWorker.ready;

  let sub = await reg.pushManager.getSubscription();
  if (!sub) {
    sub = await reg.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(config.vapidPublicKey),
    });
  }
  await apiPost("subscribe", sub.toJSON(), true);
  return true;
}

export function isSessionExpiredError(err) {
  return err?.message === "SESSION_EXPIRED";
}
