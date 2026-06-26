import { useCallback, useEffect, useRef } from "react";
import {
  cleanOpenParams,
  clearOpenIntent,
  resolveOpenIntent,
  saveOpenIntent,
  scrollToNotification,
} from "../lib/notificationOpen";

const RETRY_MS = 15_000;

export function useNotificationOpen({
  enabled,
  initialHighlightId,
  online,
  onReload,
  setHighlightId,
}) {
  const onReloadRef = useRef(onReload);
  const lastHandledRef = useRef(new Map());

  onReloadRef.current = onReload;

  const checkOpenIntent = useCallback(
    async (source) => {
      if (!enabled) return;

      const intent = resolveOpenIntent(initialHighlightId);
      if (!intent?.id) return;

      const id = Number(intent.id);
      const prev = lastHandledRef.current.get(id);
      const now = Date.now();
      if (prev?.ok && now - prev.ts < RETRY_MS && source !== "mount") return;
      if (prev && !prev.ok && now - prev.ts < 2000) return;

      setHighlightId(id);
      saveOpenIntent(intent);

      if (online) {
        try {
          await onReloadRef.current({ silent: true });
        } catch {
          /* список может быть из кэша */
        }
      }

      const scrolled = await scrollToNotification(id);
      lastHandledRef.current.set(id, { ok: scrolled, ts: now });

      if (scrolled || source === "mount" || source === "idle-resume" || source === "sw-message") {
        cleanOpenParams();
        clearOpenIntent();
      }
    },
    [enabled, initialHighlightId, online, setHighlightId]
  );

  useEffect(() => {
    checkOpenIntent("mount");
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (!enabled || !("serviceWorker" in navigator)) return;

    const onMessage = (event) => {
      if (event.data?.type !== "notification-open" || !event.data.id) return;
      saveOpenIntent({ id: event.data.id, fromPush: true });
      checkOpenIntent("sw-message");
    };

    navigator.serviceWorker.addEventListener("message", onMessage);
    return () => navigator.serviceWorker.removeEventListener("message", onMessage);
  }, [checkOpenIntent, enabled]);

  return { checkOpenIntent };
}
