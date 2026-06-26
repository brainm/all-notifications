import { useCallback, useEffect, useRef, useState } from "react";
import { apiGet } from "../lib/api";
import { isAutoMarkSnoozed, mergeNotifications, snoozeAutoMark } from "../lib/autoMarkSeen";
import { scrollToNotification } from "../lib/notificationOpen";

export function useAutoMarkQueue({ online, offlineMode, setItems, markSeen }) {
  const [pendingMarkSeenId, setPendingMarkSeenId] = useState(null);
  const queueRef = useRef([]);
  const activeRef = useRef(false);
  const pendingRef = useRef(null);

  pendingRef.current = pendingMarkSeenId;

  const processNext = useCallback(() => {
    if (isAutoMarkSnoozed() || !online || offlineMode) {
      queueRef.current = [];
      activeRef.current = false;
      pendingRef.current = null;
      setPendingMarkSeenId(null);
      return;
    }
    if (pendingRef.current) return;

    const nextId = queueRef.current[0];
    if (!nextId) {
      activeRef.current = false;
      return;
    }

    pendingRef.current = nextId;
    setPendingMarkSeenId(nextId);
    scrollToNotification(nextId);
  }, [offlineMode, online]);

  const startAutoMarkQueue = useCallback(async () => {
    if (isAutoMarkSnoozed() || !online || offlineMode || activeRef.current || pendingRef.current) {
      return;
    }

    try {
      const data = await apiGet("notifications", { unread: "1", limit: "100" });
      const unread = data.items || [];
      if (!unread.length) return;

      queueRef.current = unread.map((item) => Number(item.id));
      activeRef.current = true;
      setItems((prev) => mergeNotifications(prev, unread));
      processNext();
    } catch {
      /* ignore — повторим при следующем запуске */
    }
  }, [offlineMode, online, processNext, setItems]);

  const confirmDelayedMarkSeen = useCallback(
    async (id) => {
      const numId = Number(id);
      await markSeen(numId);
      queueRef.current = queueRef.current.filter((itemId) => itemId !== numId);
      pendingRef.current = null;
      setPendingMarkSeenId(null);
      window.setTimeout(() => processNext(), 0);
    },
    [markSeen, processNext]
  );

  const markSeenManual = useCallback(
    async (id) => {
      const numId = Number(id);
      queueRef.current = queueRef.current.filter((itemId) => itemId !== numId);
      const wasPending = pendingRef.current === numId;
      if (wasPending) {
        pendingRef.current = null;
        setPendingMarkSeenId(null);
      }
      await markSeen(numId);
      if (wasPending) {
        window.setTimeout(() => processNext(), 0);
      }
    },
    [markSeen, processNext]
  );

  const cancelDelayedMarkSeen = useCallback(() => {
    snoozeAutoMark();
    queueRef.current = [];
    activeRef.current = false;
    pendingRef.current = null;
    setPendingMarkSeenId(null);
  }, []);

  useEffect(() => {
    if (!online || offlineMode) {
      queueRef.current = [];
      activeRef.current = false;
      pendingRef.current = null;
      setPendingMarkSeenId(null);
    }
  }, [offlineMode, online]);

  return {
    pendingMarkSeenId,
    startAutoMarkQueue,
    confirmDelayedMarkSeen,
    cancelDelayedMarkSeen,
    markSeenManual,
  };
}
