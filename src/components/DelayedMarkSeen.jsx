import React, { useEffect, useRef, useState } from "react";

const DELAY_MS = 3000;

export function DelayedMarkSeen({ notificationId, onConfirm, onCancel }) {
  const [progress, setProgress] = useState(0);
  const cancelledRef = useRef(false);
  const onConfirmRef = useRef(onConfirm);

  onConfirmRef.current = onConfirm;

  useEffect(() => {
    cancelledRef.current = false;
    setProgress(0);
    const start = performance.now();
    let raf = 0;

    const tick = (now) => {
      if (cancelledRef.current) return;
      const elapsed = now - start;
      const p = Math.min(1, elapsed / DELAY_MS);
      setProgress(p);
      if (p >= 1) {
        onConfirmRef.current(notificationId);
        return;
      }
      raf = requestAnimationFrame(tick);
    };

    raf = requestAnimationFrame(tick);
    return () => {
      cancelledRef.current = true;
      cancelAnimationFrame(raf);
    };
  }, [notificationId]);

  function cancel() {
    cancelledRef.current = true;
    onCancel();
  }

  return (
    <div className="mt-3 rounded-lg border border-accent/30 bg-blue-50/80 p-3">
      <div className="mb-2 flex items-center justify-between gap-2 text-sm text-slate-700">
        <span>Отметить прочитанным через {Math.max(0, Math.ceil((1 - progress) * DELAY_MS / 1000))} с</span>
        <button
          type="button"
          className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-slate-500 hover:bg-white hover:text-slate-800"
          onClick={cancel}
          aria-label="Отменить отметку прочитанным"
        >
          <i className="fa-solid fa-xmark" aria-hidden="true" />
        </button>
      </div>
      <div className="h-1.5 overflow-hidden rounded-full bg-slate-200">
        <div
          className="h-full w-full origin-left rounded-full bg-accent will-change-transform"
          style={{ transform: `scaleX(${progress})` }}
        />
      </div>
    </div>
  );
}
