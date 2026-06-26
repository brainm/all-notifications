import React, { useState } from "react";
import { createRoot } from "react-dom/client";
import "../style.css";
import { apiPost } from "../lib/api";

function LoginApp() {
  const [login, setLogin] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const sessionExpired = new URLSearchParams(window.location.search).get("expired") === "1";

  async function onSubmit(e) {
    e.preventDefault();
    setError("");
    setLoading(true);
    try {
      const data = await apiPost("login", { login, password });
      window.location.href = data.role === "admin" ? "admin.php" : "dashboard.php";
    } catch (err) {
      if (err.message === "SESSION_EXPIRED") return;
      setError(err.message === "unauthorized" ? "Неверный логин или пароль" : err.message);
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-gradient-to-br from-ink-950 via-ink-900 to-slate-900 p-4">
      <div className="card w-full max-w-md shadow-xl">
        <h1 className="mb-1 text-2xl font-bold text-ink-900">Уведомления</h1>
        <p className="mb-6 text-sm text-slate-500">Войдите по логину или email.</p>
        {sessionExpired && (
          <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            Сессия истекла. Войдите снова, чтобы продолжить.
          </div>
        )}
        {error && <div className="mb-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>}
        <form onSubmit={onSubmit} className="space-y-4">
          <label className="block text-sm font-medium text-slate-700">
            Логин или email
            <input className="input mt-1" value={login} onChange={(e) => setLogin(e.target.value)} required autoComplete="username" placeholder="ivan или ivan@example.com" />
          </label>
          <label className="block text-sm font-medium text-slate-700">
            Пароль
            <input className="input mt-1" type="password" value={password} onChange={(e) => setPassword(e.target.value)} required autoComplete="current-password" />
          </label>
          <button type="submit" className="btn-primary w-full" disabled={loading}>
            {loading ? "Вход…" : "Войти"}
          </button>
        </form>
      </div>
    </div>
  );
}

createRoot(document.getElementById("app")).render(<LoginApp />);
