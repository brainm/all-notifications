export const AUTO_MARK_SNOOZE_KEY = "notifications-auto-mark-snoozed-until";
export const AUTO_MARK_SNOOZE_MS = 60 * 60 * 1000;

export function isAutoMarkSnoozed() {
  try {
    const until = Number(localStorage.getItem(AUTO_MARK_SNOOZE_KEY) || 0);
    return until > Date.now();
  } catch {
    return false;
  }
}

export function snoozeAutoMark() {
  try {
    localStorage.setItem(AUTO_MARK_SNOOZE_KEY, String(Date.now() + AUTO_MARK_SNOOZE_MS));
  } catch {
    /* ignore */
  }
}

export function mergeNotifications(existing, incoming) {
  const byId = new Map(existing.map((item) => [Number(item.id), item]));
  for (const item of incoming) {
    byId.set(Number(item.id), item);
  }
  return [...byId.values()].sort((a, b) => {
    const ta = new Date(String(a.created_at).replace(" ", "T")).getTime();
    const tb = new Date(String(b.created_at).replace(" ", "T")).getTime();
    if (tb !== ta) return tb - ta;
    return Number(b.id) - Number(a.id);
  });
}
