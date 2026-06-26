export function AppShell({ title, subtitle, children, actions, onTitleClick }) {
  const titleClass = onTitleClick
    ? "text-lg font-semibold text-left hover:text-slate-200 active:text-slate-300 transition-colors"
    : "text-lg font-semibold";

  return (
    <div className="min-h-screen bg-slate-100">
      <header className="border-b border-slate-200 bg-ink-900 text-white">
        <div className="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-4">
          <div>
            {onTitleClick ? (
              <button type="button" className={titleClass} onClick={onTitleClick} title="Обновить список">
                {title}
              </button>
            ) : (
              <h1 className={titleClass}>{title}</h1>
            )}
            {subtitle && <p className="text-sm text-slate-300">{subtitle}</p>}
          </div>
          {actions && <div className="flex items-center gap-2">{actions}</div>}
        </div>
      </header>
      <main className="mx-auto max-w-5xl px-4 py-6">{children}</main>
    </div>
  );
}

export function Alert({ type = "error", children }) {
  const cls =
    type === "success"
      ? "border-green-200 bg-green-50 text-green-800"
      : "border-red-200 bg-red-50 text-red-800";
  return <div className={`mb-4 rounded-lg border px-4 py-3 text-sm ${cls}`}>{children}</div>;
}
