import { useCallback, useEffect, useRef } from "react";

export const MIN_SYNC_INTERVAL_MS = 60_000;
export const IDLE_RESUME_MS = 30_000;

/**
 * Фоновая синхронизация только при открытом приложении (Page Visibility).
 * Не чаще раза в минуту; после длительного idle — сразу при возврате.
 */
export function useVisibleAppSync({ onSync, enabled = true }) {
  const onSyncRef = useRef(onSync);
  const lastSyncRef = useRef(0);
  const hiddenAtRef = useRef(0);

  onSyncRef.current = onSync;

  const maybeSync = useCallback((reason, { forceIdle = false } = {}) => {
    if (!enabled) return;
    if (typeof document !== "undefined" && document.visibilityState !== "visible") return;

    const now = Date.now();
    const isIdleResume = forceIdle;
    if (!isIdleResume && now - lastSyncRef.current < MIN_SYNC_INTERVAL_MS) return;

    lastSyncRef.current = now;
    onSyncRef.current(reason);
  }, [enabled]);

  useEffect(() => {
    if (!enabled) return;

    const onVisibilityChange = () => {
      if (document.visibilityState === "hidden") {
        hiddenAtRef.current = Date.now();
        return;
      }
      const hiddenFor = hiddenAtRef.current ? Date.now() - hiddenAtRef.current : 0;
      hiddenAtRef.current = 0;
      if (hiddenFor >= IDLE_RESUME_MS) {
        maybeSync("idle-resume", { forceIdle: true });
      }
    };

    const onFocus = () => maybeSync("focus");

    document.addEventListener("visibilitychange", onVisibilityChange);
    window.addEventListener("focus", onFocus);

    const timer = window.setInterval(() => {
      if (document.visibilityState === "visible") {
        maybeSync("interval");
      }
    }, MIN_SYNC_INTERVAL_MS);

    return () => {
      document.removeEventListener("visibilitychange", onVisibilityChange);
      window.removeEventListener("focus", onFocus);
      window.clearInterval(timer);
    };
  }, [enabled, maybeSync]);

  return { maybeSync };
}
