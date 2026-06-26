import React from "react";

const STEPS = [
  {
    title: "Откройте сайт в Chrome",
    text: "Push на Android работает в Chrome, Firefox и Samsung Internet. Лучше всего — Google Chrome.",
  },
  {
    title: "Нажмите Push",
    text: (
      <>
        Вверху страницы нажмите кнопку <strong className="font-semibold">Push</strong>.
      </>
    ),
  },
  {
    title: "Разрешите уведомления",
    text: (
      <>
        Когда браузер спросит — выберите <strong className="font-semibold">Разрешить</strong>. Это нужно сделать{" "}
        <strong className="font-semibold">один раз</strong>.
      </>
    ),
  },
  {
    title: "Иконка на главном экране (необязательно)",
    text: "Меню браузера (три точки) → «Установить приложение» или «Добавить на главный экран». Push работает и без этого шага.",
  },
];

export function AndroidPushGuide({ open, onClose, onEnablePush, enabling }) {
  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <button type="button" className="absolute inset-0 bg-black/60" aria-label="Закрыть" onClick={onClose} />
      <div
        className="relative z-10 flex max-h-[calc(100dvh-2rem)] w-full max-w-lg flex-col overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-black/10"
        role="dialog"
        aria-modal="true"
      >
        <div className="flex items-center justify-between gap-2 border-b border-slate-200 px-4 py-3">
          <div>
            <h2 className="text-base font-semibold text-ink-900">Push на Android</h2>
            <p className="text-xs text-slate-500">Краткая инструкция</p>
          </div>
          <button type="button" className="btn-secondary !px-2 !py-1 text-xs" onClick={onClose}>
            ✕
          </button>
        </div>

        <div className="overflow-y-auto px-4 py-4">
          <ol className="space-y-4">
            {STEPS.map((step, i) => (
              <li key={step.title} className="text-sm text-slate-700">
                <p className="mb-1 font-semibold text-ink-900">
                  {i + 1}. {step.title}
                </p>
                <p className="text-slate-600">{step.text}</p>
              </li>
            ))}
          </ol>
        </div>

        <div className="flex flex-wrap justify-end gap-2 border-t border-slate-200 px-4 py-3">
          <button type="button" className="btn-secondary" onClick={onClose}>
            Закрыть
          </button>
          <button type="button" className="btn-primary" onClick={onEnablePush} disabled={enabling}>
            {enabling ? "Подключение…" : "Включить Push сейчас"}
          </button>
        </div>
      </div>
    </div>
  );
}
