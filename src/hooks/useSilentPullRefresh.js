import { useEffect } from "react";

/** Перезагрузка при оттягивании вниз у верхнего края — без собственного UI (не дублирует iOS). */
export function useSilentPullRefresh(onRefresh, enabled = true) {
  useEffect(() => {
    if (!enabled) return;

    let startY = 0;
    let tracking = false;
    const threshold = 72;

    function onTouchStart(e) {
      if (window.scrollY > 2) return;
      startY = e.touches[0].clientY;
      tracking = true;
    }

    function onTouchEnd(e) {
      if (!tracking) return;
      tracking = false;
      if (window.scrollY > 2) return;
      const dy = e.changedTouches[0].clientY - startY;
      if (dy >= threshold) onRefresh();
    }

    document.addEventListener("touchstart", onTouchStart, { passive: true });
    document.addEventListener("touchend", onTouchEnd, { passive: true });
    return () => {
      document.removeEventListener("touchstart", onTouchStart);
      document.removeEventListener("touchend", onTouchEnd);
    };
  }, [onRefresh, enabled]);
}
