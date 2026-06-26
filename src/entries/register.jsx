import React, { useEffect, useState } from "react";
import { createRoot } from "react-dom/client";
import "../style.css";
import { Alert } from "../components/Layout";
import { apiGet, apiPost } from "../lib/api";

function RegisterApp({ initial }) {
  const [login, setLogin] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [password2, setPassword2] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const [checking, setChecking] = useState(true);

  useEffect(() => {
    if (!initial.token) {
      setError("Ссылка приглашения недействительна");
      setChecking(false);
      return;
    }
    apiGet("register_info", { token: initial.token })
      .then((data) => setLogin(data.login || ""))
      .catch((e) => setError(e.message === "already registered" ? "Регистрация уже завершена" : "Ссылка приглашения недействительна или истекла"))
      .finally(() => setChecking(false));
  }, [initial.token]);

  async function onSubmit(e) {
    e.preventDefault();
    setError("");
    if (password.length < 6) {
      setError("Пароль не менее 6 символов");
      return;
    }
    if (password !== password2) {
      setError("Пароли не совпадают");
      return;
    }
    setLoading(true);
    try {
      await apiPost("register_complete", {
        token: initial.token,
        email,
        password,
        password2,
      });
      window.location.href = "login.php";
    } catch (err) {
      setError(err.message || "Ошибка регистрации");
    } finally {
      setLoading(false);
    }
  }

  if (checking) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-100 p-4">
        <p className="text-slate-500">Проверка ссылки…</p>
      </div>
    );
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-gradient-to-br from-ink-950 via-ink-900 to-slate-900 p-4">
      <div className="card w-full max-w-md shadow-xl">
        <h1 className="mb-1 text-2xl font-bold text-ink-900">Завершение регистрации</h1>
        <p className="mb-6 text-sm text-slate-500">
          Логин: <span className="font-medium text-ink-900">{login || "—"}</span>
        </p>
        {error && <Alert>{error}</Alert>}
        {login && (
          <form onSubmit={onSubmit} className="space-y-4">
            <label className="block text-sm font-medium text-slate-700">
              Email
              <input
                className="input mt-1"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
                autoComplete="email"
              />
            </label>
            <label className="block text-sm font-medium text-slate-700">
              Пароль
              <input
                className="input mt-1"
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                minLength={6}
                autoComplete="new-password"
              />
            </label>
            <label className="block text-sm font-medium text-slate-700">
              Пароль ещё раз
              <input
                className="input mt-1"
                type="password"
                value={password2}
                onChange={(e) => setPassword2(e.target.value)}
                required
                minLength={6}
                autoComplete="new-password"
              />
            </label>
            <button type="submit" className="btn-primary w-full" disabled={loading}>
              {loading ? "Сохранение…" : "Завершить регистрацию"}
            </button>
          </form>
        )}
        <p className="mt-4 text-center text-sm text-slate-500">
          <a href="login.php" className="text-accent hover:underline">
            Уже зарегистрированы? Войти
          </a>
        </p>
      </div>
    </div>
  );
}

const initial = window.__INITIAL__ || {};
createRoot(document.getElementById("app")).render(<RegisterApp initial={initial} />);
