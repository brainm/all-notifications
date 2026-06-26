import { useEffect, useState } from "react";

export function isBrowserOnline() {
  return typeof navigator === "undefined" ? true : navigator.onLine;
}

export function isNetworkError(err) {
  if (!isBrowserOnline()) return true;
  if (err?.name === "TypeError" && /fetch|network|load failed/i.test(String(err.message))) {
    return true;
  }
  return false;
}

export function useOnlineStatus() {
  const [online, setOnline] = useState(() => isBrowserOnline());

  useEffect(() => {
    const onOnline = () => setOnline(true);
    const onOffline = () => setOnline(false);
    window.addEventListener("online", onOnline);
    window.addEventListener("offline", onOffline);
    return () => {
      window.removeEventListener("online", onOnline);
      window.removeEventListener("offline", onOffline);
    };
  }, []);

  return online;
}
