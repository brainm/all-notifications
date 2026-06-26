import React, { useCallback, useEffect, useRef, useState } from "react";
import { createRoot } from "react-dom/client";
import "../style.css";
import { AddToHomeScreenGuide } from "../components/AddToHomeScreenGuide";
import { AndroidPushGuide } from "../components/AndroidPushGuide";
import { DelayedMarkSeen } from "../components/DelayedMarkSeen";
import { AppShell, Alert } from "../components/Layout";
import { useAutoMarkQueue } from "../hooks/useAutoMarkQueue";
import { useNotificationOpen } from "../hooks/useNotificationOpen";
import { useSilentPullRefresh } from "../hooks/useSilentPullRefresh";
import { useVisibleAppSync } from "../hooks/useVisibleAppSync";
import { apiGet, apiPost, isSessionExpiredError, registerPush } from "../lib/api";
import { formatOfflineSavedAt, loadOfflineInbox, saveOfflineInbox } from "../lib/offlineStore";
import { isBrowserOnline, isNetworkError, useOnlineStatus } from "../lib/network";
import {
  dismissAddToHomeScreenGuide,
  dismissAndroidPushGuide,
  hasActivePushSubscription,
  isAddToHomeScreenGuideDismissed,
  isAndroidPushGuideDismissed,
  needsAddToHomeScreenGuide,
  needsAndroidPushGuide,
} from "../lib/pwa";

const DEFAULT_PAGE_SIZE = 10;
const MIN_REFRESH_MS = 1000;

function DashboardApp({ initial }) {
  const pageSize = initial.pageSize || DEFAULT_PAGE_SIZE;
  const [items, setItems] = useState(initial.notifications || []);
  const [hasMore, setHasMore] = useState(!!initial.notificationsHasMore);
  const [offset, setOffset] = useState(items.length);
  const [loadingMore, setLoadingMore] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [highlightId, setHighlightId] = useState(initial.highlightId || 0);
  const [pushMsg, setPushMsg] = useState("");
  const [error, setError] = useState("");
  const showA2hsHint = needsAddToHomeScreenGuide();
  const showAndroidHint = needsAndroidPushGuide();
  const [pushEnabled, setPushEnabled] = useState(false);
  const [pushChecking, setPushChecking] = useState(showAndroidHint);
  const [guideOpen, setGuideOpen] = useState(
    () => showA2hsHint && !isAddToHomeScreenGuideDismissed()
  );
  const [androidGuideOpen, setAndroidGuideOpen] = useState(false);
  const [guideDismissed, setGuideDismissed] = useState(() => isAddToHomeScreenGuideDismissed());
  const [androidGuideDismissed, setAndroidGuideDismissed] = useState(() => isAndroidPushGuideDismissed());
  const [pushEnabling, setPushEnabling] = useState(false);
  const [offlineMode, setOfflineMode] = useState(() => !isBrowserOnline());
  const [offlineSavedAt, setOfflineSavedAt] = useState("");
  const online = useOnlineStatus();
  const userId = initial.user?.id;
  const wasOfflineRef = useRef(!isBrowserOnline());
  const reloadRef = useRef(null);

  const persistInbox = useCallback(
    async (nextItems, nextHasMore) => {
      if (!userId) return;
      const savedAt = new Date().toISOString();
      try {
        await saveOfflineInbox(userId, {
          items: nextItems,
          hasMore: nextHasMore,
          savedAt,
          user: initial.user,
          maxAgeDays: initial.maxAgeDays,
        });
      } catch {
        /* ignore quota / private mode */
      }
      return savedAt;
    },
    [initial.maxAgeDays, initial.user, userId]
  );

  const applyOfflineInbox = useCallback((data) => {
    if (!data?.items?.length) return false;
    setItems(data.items);
    setHasMore(!!data.hasMore);
    setOffset(data.items.length);
    setOfflineMode(true);
    setOfflineSavedAt(data.savedAt || "");
    return true;
  }, []);

  const loadOfflineInboxData = useCallback(async () => {
    if (!userId) return false;
    try {
      const data = await loadOfflineInbox(userId);
      return applyOfflineInbox(data);
    } catch {
      return false;
    }
  }, [applyOfflineInbox, userId]);

  const fetchNotifications = useCallback(
    async (off = 0, append = false) => {
      const data = await apiGet("notifications", { limit: String(pageSize), offset: String(off) });
      const next = data.items || [];
      setItems((prev) => (append ? [...prev, ...next] : next));
      const nextHasMore = !!data.has_more;
      setHasMore(nextHasMore);
      setOffset(off + next.length);
      if (!append) {
        await persistInbox(next, nextHasMore);
        setOfflineMode(false);
        setOfflineSavedAt("");
      }
      return next;
    },
    [pageSize, persistInbox]
  );

  const reload = useCallback(async ({ silent = false } = {}) => {
    if (!online) {
      if (!silent) setRefreshing(true);
      const started = performance.now();
      try {
        const loaded = await loadOfflineInboxData();
        if (!loaded) setError("Нет сети и сохранённых сообщений.");
        else setError("");
      } finally {
        if (!silent) {
          const remaining = MIN_REFRESH_MS - (performance.now() - started);
          if (remaining > 0) {
            await new Promise((resolve) => setTimeout(resolve, remaining));
          }
          setRefreshing(false);
        }
      }
      return;
    }

    if (!silent) setRefreshing(true);
    const started = performance.now();
    try {
      await fetchNotifications(0, false);
      setError("");
    } catch (e) {
      if (isSessionExpiredError(e)) return;
      if (isNetworkError(e)) {
        const loaded = await loadOfflineInboxData();
        if (loaded) setError("");
        else setError("Нет сети.");
      } else {
        setError(e.message);
      }
    } finally {
      if (!silent) {
        const remaining = MIN_REFRESH_MS - (performance.now() - started);
        if (remaining > 0) {
          await new Promise((resolve) => setTimeout(resolve, remaining));
        }
        setRefreshing(false);
      }
    }
  }, [fetchNotifications, loadOfflineInboxData, online]);

  reloadRef.current = reload;

  useSilentPullRefresh(() => reload(), online);

  const markSeen = useCallback(async (id) => {
    if (offlineMode || !online) return;
    const numId = Number(id);
    await apiPost("seen", { id: String(numId) });
    setItems((prev) =>
      prev.map((n) =>
        n.id === numId ? { ...n, seen_at: new Date().toISOString().slice(0, 19).replace("T", " ") } : n
      )
    );
  }, [offlineMode, online]);

  const {
    pendingMarkSeenId,
    startAutoMarkQueue,
    confirmDelayedMarkSeen,
    cancelDelayedMarkSeen,
    markSeenManual,
  } = useAutoMarkQueue({
    online,
    offlineMode,
    setItems,
    markSeen,
  });

  const { checkOpenIntent } = useNotificationOpen({
    enabled: true,
    initialHighlightId: initial.highlightId,
    online,
    onReload: (opts) => reloadRef.current?.(opts),
    setHighlightId,
  });

  const handleAppSync = useCallback(
    (reason) => {
      checkOpenIntent(reason);
      if (online && !offlineMode) {
        reloadRef.current?.({ silent: true });
      }
      if (reason === "idle-resume") {
        startAutoMarkQueue();
      }
    },
    [checkOpenIntent, offlineMode, online, startAutoMarkQueue]
  );

  useVisibleAppSync({
    onSync: handleAppSync,
    enabled: online && !offlineMode,
  });

  useEffect(() => {
    if (!online || offlineMode) return;
    startAutoMarkQueue();
  }, [offlineMode, online, startAutoMarkQueue]);

  useEffect(() => {
    if (!userId || !initial.notifications?.length || !isBrowserOnline()) return;
    persistInbox(initial.notifications, !!initial.notificationsHasMore).catch(() => {});
  }, [initial.notifications, initial.notificationsHasMore, persistInbox, userId]);

  useEffect(() => {
    if (!online) {
      wasOfflineRef.current = true;
      setOfflineMode(true);
      loadOfflineInboxData().catch(() => {});
      return;
    }
    if (wasOfflineRef.current) {
      wasOfflineRef.current = false;
      setOfflineMode(false);
      reload();
    }
  }, [loadOfflineInboxData, online, reload]);

  useEffect(() => {
    if ("serviceWorker" in navigator && initial.config?.swPath) {
      navigator.serviceWorker.register(initial.config.swPath, { scope: initial.config.scope }).catch(() => {});
    }
  }, [initial.config]);

  useEffect(() => {
    if (!showAndroidHint || !initial.config?.scope) {
      setPushChecking(false);
      return;
    }
    let cancelled = false;
    hasActivePushSubscription(initial.config.scope).then((active) => {
      if (!cancelled) {
        setPushEnabled(active);
        setPushChecking(false);
      }
    });
    return () => {
      cancelled = true;
    };
  }, [showAndroidHint, initial.config?.scope]);

  const markSeenWithError = useCallback(
    async (id) => {
      try {
        await confirmDelayedMarkSeen(id);
      } catch (e) {
        if (!isSessionExpiredError(e)) setError(e.message);
      }
    },
    [confirmDelayedMarkSeen]
  );

  const markSeenManualSafe = useCallback(
    async (id) => {
      try {
        await markSeenManual(id);
      } catch (e) {
        if (!isSessionExpiredError(e)) setError(e.message);
      }
    },
    [markSeenManual]
  );

  async function loadMore() {
    if (loadingMore || !hasMore || offlineMode || !online) return;
    setLoadingMore(true);
    try {
      await fetchNotifications(offset, true);
    } catch (e) {
      if (!isSessionExpiredError(e)) setError(e.message);
    } finally {
      setLoadingMore(false);
    }
  }

  async function enablePush() {
    if (!online) {
      setError("Push можно включить только при подключении к сети.");
      return;
    }
    setPushMsg("");
    setError("");
    if (showA2hsHint) {
      setGuideOpen(true);
      return;
    }
    setPushEnabling(true);
    try {
      await registerPush(initial.config);
      setPushEnabled(true);
      setPushMsg("Push включён для этого устройства.");
      setAndroidGuideOpen(false);
    } catch (e) {
      if (!isSessionExpiredError(e)) setError(e.message);
      if (showA2hsHint) setGuideOpen(true);
      if (showAndroidHint) setAndroidGuideOpen(true);
    } finally {
      setPushEnabling(false);
    }
  }

  function closeGuide(persistDismiss = false) {
    if (persistDismiss) {
      dismissAddToHomeScreenGuide();
      setGuideDismissed(true);
    }
    setGuideOpen(false);
  }

  function closeAndroidGuide(persistDismiss = false) {
    if (persistDismiss) {
      dismissAndroidPushGuide();
      setAndroidGuideDismissed(true);
    }
    setAndroidGuideOpen(false);
  }

  const showAndroidBanner =
    showAndroidHint && !pushChecking && !pushEnabled && !androidGuideOpen && !androidGuideDismissed;

  return (
    <AppShell
      title="Входящие"
      subtitle={`${initial.user.login} · ${initial.user.email}`}
      onTitleClick={reload}
      actions={
        <>
          <button
            type="button"
            className="btn-secondary !h-9 !px-3 !py-0 !text-sm !text-white !border-slate-600 !bg-ink-800 hover:!bg-ink-800"
            onClick={enablePush}
            disabled={pushEnabling}
          >
            Push
          </button>
          <button
            type="button"
            className="btn-secondary !h-9 !px-3 !py-0 !text-sm !text-white !border-slate-600 !bg-ink-800 hover:!bg-ink-800"
            onClick={reload}
            disabled={refreshing}
            title="Обновить"
            aria-label="Обновить"
          >
            <i className="fa-solid fa-arrows-rotate" aria-hidden="true" />
          </button>
          <a
            href="logout.php"
            className="btn-secondary !h-9 !px-3 !py-0 !text-sm !text-white !border-slate-600 !bg-ink-800 hover:!bg-ink-800"
            title="Выход"
            aria-label="Выход"
          >
            <i className="fa-solid fa-right-from-bracket" aria-hidden="true" />
          </a>
        </>
      }
    >
      {showAndroidBanner && (
        <div className="card mb-4 border-accent/30 bg-blue-50/60">
          <p className="mb-3 text-sm text-slate-700">
            На Android уведомления включаются кнопкой <strong className="font-semibold">Push</strong> и
            разрешением в браузере. Установка на главный экран не обязательна.
          </p>
          <div className="flex flex-wrap gap-2">
            <button type="button" className="btn-primary" onClick={() => setAndroidGuideOpen(true)}>
              Как включить Push
            </button>
            <button type="button" className="btn-secondary" onClick={enablePush} disabled={pushEnabling}>
              {pushEnabling ? "Подключение…" : "Включить Push"}
            </button>
            {!androidGuideDismissed && (
              <button type="button" className="btn-secondary" onClick={() => closeAndroidGuide(true)}>
                Не показывать снова
              </button>
            )}
          </div>
        </div>
      )}

      {showA2hsHint && !guideOpen && (
        <div className="card mb-4 border-accent/30 bg-blue-50/60">
          <p className="mb-3 text-sm text-slate-700">
            Вы открыли сайт в Safari, а не с экрана «Домой». Push-уведомления на iPhone работают только из
            добавленной иконки.
          </p>
          <div className="flex flex-wrap gap-2">
            <button type="button" className="btn-primary" onClick={() => setGuideOpen(true)}>
              Как добавить на экран «Домой»
            </button>
            {!guideDismissed && (
              <button type="button" className="btn-secondary" onClick={() => closeGuide(true)}>
                Не показывать снова
              </button>
            )}
          </div>
        </div>
      )}

      {offlineMode && (
        <div className="card mb-4 border-amber-300 bg-amber-50/80">
          <p className="text-sm text-amber-900">
            Нет сети
            {offlineSavedAt ? ` · сохранённые сообщения от ${formatOfflineSavedAt(offlineSavedAt)}` : ""}
          </p>
        </div>
      )}

      {error && <Alert>{error}</Alert>}
      {pushMsg && <Alert type="success">{pushMsg}</Alert>}

      {refreshing && (
        <div className="mb-4 flex items-center justify-center gap-2 py-8 text-sm text-slate-500" role="status" aria-live="polite">
          <i className="fa-solid fa-arrows-rotate fa-spin text-accent" aria-hidden="true" />
          <span>Обновление…</span>
        </div>
      )}

      {!refreshing && items.length === 0 ? (
        <p className="text-center text-slate-500">
          {offlineMode ? "Нет сохранённых уведомлений." : `Нет уведомлений за последние ${initial.maxAgeDays} дн.`}
        </p>
      ) : !refreshing && items.length > 0 ? (
        <ul className="space-y-3">
          {items.map((n) => {
            const isNew = !n.seen_at;
            const highlight = highlightId === Number(n.id);
            const pendingMark = pendingMarkSeenId === Number(n.id);
            return (
              <li
                id={`notification-${n.id}`}
                key={n.id}
                className={`card border-l-4 ${isNew ? "border-l-accent" : "border-l-slate-200"} ${highlight ? "ring-2 ring-accent/30" : ""}`}
              >
                <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                  <span className="font-semibold text-ink-900">{n.rule_name}</span>
                  <time className="text-xs text-slate-500">{n.created_at}</time>
                </div>
                <pre className="whitespace-pre-wrap break-words text-sm text-slate-700">{n.message_text}</pre>
                <div className="mt-3">
                  {pendingMark ? (
                    <DelayedMarkSeen
                      notificationId={n.id}
                      onConfirm={markSeenWithError}
                      onCancel={cancelDelayedMarkSeen}
                    />
                  ) : isNew ? (
                    offlineMode || !online ? (
                      <span className="text-xs text-slate-500">Новое · отметка «Прочитано» доступна онлайн</span>
                    ) : (
                      <button type="button" className="btn-primary" onClick={() => markSeenManualSafe(n.id)}>
                        Прочитано
                      </button>
                    )
                  ) : (
                    <span className="text-xs text-slate-500">Прочитано: {n.seen_at}</span>
                  )}
                </div>
              </li>
            );
          })}
        </ul>
      ) : null}

      {hasMore && !refreshing && !offlineMode && online && (
        <div className="mt-6 flex justify-center">
          <button type="button" className="btn-secondary" onClick={loadMore} disabled={loadingMore}>
            {loadingMore ? "Загрузка…" : "Загрузить ещё"}
          </button>
        </div>
      )}

      <AddToHomeScreenGuide open={guideOpen} onClose={() => closeGuide(false)} config={initial.config} />
      <AndroidPushGuide
        open={androidGuideOpen}
        onClose={() => closeAndroidGuide(false)}
        onEnablePush={enablePush}
        enabling={pushEnabling}
      />
    </AppShell>
  );
}

const initial = window.__INITIAL__ || {};
createRoot(document.getElementById("app")).render(<DashboardApp initial={initial} />);
