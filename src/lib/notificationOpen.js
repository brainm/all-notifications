export const OPEN_INTENT_KEY = "notifications-open-intent";
const INTENT_TTL_MS = 10 * 60 * 1000;

export function parseOpenIntentFromUrl(url = window.location.href) {
  const u = new URL(url, window.location.origin);
  const id = Number(u.searchParams.get("id")) || 0;
  if (!id) return null;
  return {
    id,
    fromPush: u.searchParams.get("from") === "push",
    ts: Date.now(),
  };
}

export function saveOpenIntent(intent) {
  if (!intent?.id) return;
  try {
    sessionStorage.setItem(
      OPEN_INTENT_KEY,
      JSON.stringify({ ...intent, id: Number(intent.id), ts: Date.now() })
    );
  } catch {
    /* private mode */
  }
}

export function readOpenIntent() {
  try {
    const raw = sessionStorage.getItem(OPEN_INTENT_KEY);
    if (!raw) return null;
    const data = JSON.parse(raw);
    if (!data?.id || Date.now() - (data.ts || 0) > INTENT_TTL_MS) {
      sessionStorage.removeItem(OPEN_INTENT_KEY);
      return null;
    }
    return data;
  } catch {
    return null;
  }
}

export function clearOpenIntent() {
  try {
    sessionStorage.removeItem(OPEN_INTENT_KEY);
  } catch {
    /* ignore */
  }
}

export function resolveOpenIntent(initialHighlightId = 0) {
  const urlIntent = parseOpenIntentFromUrl();
  if (urlIntent?.id) {
    saveOpenIntent(urlIntent);
    return urlIntent;
  }
  const stored = readOpenIntent();
  if (stored?.id) return stored;
  if (initialHighlightId) {
    return { id: Number(initialHighlightId), fromPush: false, ts: Date.now() };
  }
  return null;
}

export function cleanOpenParams() {
  const url = new URL(window.location.href);
  if (!url.searchParams.has("id") && !url.searchParams.has("from")) return;
  url.searchParams.delete("id");
  url.searchParams.delete("from");
  window.history.replaceState({}, "", url.pathname + url.search + url.hash);
}

export function scrollToNotification(id, maxAttempts = 12) {
  const numId = Number(id);
  if (!numId) return Promise.resolve(false);

  return new Promise((resolve) => {
    let attempts = 0;
    const tryScroll = () => {
      const el = document.getElementById(`notification-${numId}`);
      if (el) {
        el.scrollIntoView({ behavior: "smooth", block: "center" });
        resolve(true);
        return;
      }
      attempts += 1;
      if (attempts < maxAttempts) {
        requestAnimationFrame(tryScroll);
      } else {
        resolve(false);
      }
    };
    requestAnimationFrame(tryScroll);
  });
}
