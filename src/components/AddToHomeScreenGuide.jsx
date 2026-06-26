import React, { useState } from "react";
import { assetBaseFromConfig } from "../lib/pwa";

const STEPS = [
  {
    img: 1,
    text: "Найдите внизу страницы рядом с адресом кнопку с тремя точками",
  },
  {
    img: 2,
    text: "Нажмите «Поделиться»",
  },
  {
    img: 3,
    text: "Нажмите «Показать больше»",
  },
  {
    img: 4,
    text: "Нажмите «Добавить на экран Домой»",
  },
  {
    img: 5,
    text: "Нажмите «Добавить»",
  },
  {
    img: 6,
    text: (
      <>
        После запуска приложения «Уведомления» <strong className="font-semibold">один раз</strong> нажмите Push и
        «Разрешить».
      </>
    ),
    alt: "После запуска приложения «Уведомления» один раз нажмите Push и «Разрешить».",
  },
];

export function AddToHomeScreenGuide({ open, onClose, config }) {
  const [step, setStep] = useState(0);
  const base = assetBaseFromConfig(config);
  const current = STEPS[step];

  if (!open) return null;

  function close() {
    setStep(0);
    onClose();
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      <button type="button" className="absolute inset-0 bg-black/60" aria-label="Закрыть" onClick={close} />
      <div
        className="relative z-10 flex max-h-[calc(100dvh-2rem)] w-full max-w-lg flex-col overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-black/10"
        role="dialog"
        aria-modal="true"
      >
        <div className="flex items-center justify-between gap-2 border-b border-slate-200 px-4 py-3">
          <div>
            <h2 className="text-base font-semibold text-ink-900">Добавить на экран «Домой»</h2>
            <p className="text-xs text-slate-500">
              Шаг {step + 1} из {STEPS.length}
            </p>
          </div>
          <button type="button" className="btn-secondary !px-2 !py-1 text-xs" onClick={close}>
            ✕
          </button>
        </div>

        <div className="overflow-y-auto px-4 py-4">
          <p className="mb-3 text-sm text-slate-700">{current.text}</p>
          <img
            src={`${base}/images/ios/add-to-home-screen-${current.img}.png`}
            alt={current.alt || (typeof current.text === "string" ? current.text : `Шаг ${step + 1}`)}
            className="w-full rounded-lg border border-slate-200"
          />
        </div>

        <div className="flex items-center justify-between gap-2 border-t border-slate-200 px-4 py-3">
          <button
            type="button"
            className="btn-secondary"
            disabled={step === 0}
            onClick={() => setStep((s) => Math.max(0, s - 1))}
          >
            Назад
          </button>
          {step < STEPS.length - 1 ? (
            <button type="button" className="btn-primary" onClick={() => setStep((s) => s + 1)}>
              Далее
            </button>
          ) : (
            <button type="button" className="btn-primary" onClick={close}>
              Готово
            </button>
          )}
        </div>
      </div>
    </div>
  );
}
