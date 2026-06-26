import React, { useCallback, useEffect, useState } from "react";
import { createRoot } from "react-dom/client";
import "../style.css";
import { AppShell, Alert } from "../components/Layout";
import { Modal } from "../components/Modal";
import { apiGet, apiPost, isSessionExpiredError } from "../lib/api";

const EMPTY_CREATE_FORM = {
  email: "",
  login: "",
  password: "",
  password2: "",
  enabled: true,
};

const EMPTY_INVITE_FORM = {
  login: "",
  enabled: true,
};

function InviteLinkDialog({ open, url, onClose }) {
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    if (!open) setCopied(false);
  }, [open]);

  if (!open) return null;

  async function copy() {
    try {
      const full = url.startsWith("http") ? url : new URL(url, window.location.href).href;
      await navigator.clipboard.writeText(full);
      setCopied(true);
    } catch {
      setCopied(false);
    }
  }

  const displayUrl = url.startsWith("http") ? url : new URL(url, window.location.href).href;

  return (
    <Modal open title="Ссылка для регистрации" onClose={onClose}>
      <p className="mb-3 text-sm text-slate-600">
        Отправьте пользователю эту ссылку. По ней он задаст email и пароль. Срок действия — 7 дней.
      </p>
      <input className="input mb-3 text-xs" readOnly value={displayUrl} onFocus={(e) => e.target.select()} />
      <div className="flex justify-end gap-2">
        <button type="button" className="btn-secondary" onClick={onClose}>
          Закрыть
        </button>
        <button type="button" className="btn-primary" onClick={copy}>
          {copied ? "Скопировано" : "Копировать"}
        </button>
      </div>
    </Modal>
  );
}

function AdminLogin({ onSuccess }) {
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const sessionExpired = new URLSearchParams(window.location.search).get("expired") === "1";

  async function onSubmit(e) {
    e.preventDefault();
    setLoading(true);
    setError("");
    try {
      await apiPost("admin_login", { username, password });
      onSuccess();
    } catch (err) {
      if (isSessionExpiredError(err)) return;
      setError("Неверные учётные данные");
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-900 p-4">
      <div className="card w-full max-w-md">
        <h1 className="mb-6 text-xl font-bold">Admin</h1>
        {sessionExpired && (
          <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            Сессия истекла. Войдите снова.
          </div>
        )}
        {error && <Alert>{error}</Alert>}
        <form onSubmit={onSubmit} className="space-y-4">
          <label className="block text-sm font-medium">
            Логин
            <input className="input mt-1" value={username} onChange={(e) => setUsername(e.target.value)} required />
          </label>
          <label className="block text-sm font-medium">
            Пароль
            <input className="input mt-1" type="password" value={password} onChange={(e) => setPassword(e.target.value)} required />
          </label>
          <button type="submit" className="btn-primary w-full" disabled={loading}>
            Войти
          </button>
        </form>
      </div>
    </div>
  );
}

function CreateUserDialog({ open, onClose, onSaved }) {
  const [form, setForm] = useState(EMPTY_CREATE_FORM);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (!open) return;
    setError("");
    setForm(EMPTY_CREATE_FORM);
  }, [open]);

  function setField(name, value) {
    setForm((prev) => ({ ...prev, [name]: value }));
  }

  async function onSubmit(e) {
    e.preventDefault();
    setError("");
    if (form.password.length < 6) {
      setError("Пароль не менее 6 символов");
      return;
    }
    if (form.password !== form.password2) {
      setError("Пароли не совпадают");
      return;
    }
    setLoading(true);
    try {
      await apiPost("admin_create", {
        email: form.email,
        login: form.login,
        password: form.password,
        enabled: form.enabled ? "1" : "0",
      });
      onSaved();
      onClose();
    } catch (err) {
      setError(err.message || "Ошибка сохранения");
    } finally {
      setLoading(false);
    }
  }

  return (
    <Modal open={open} title="Создать пользователя" onClose={onClose}>
      {error && <Alert>{error}</Alert>}
      <form onSubmit={onSubmit} className="space-y-4">
        <label className="block text-sm font-medium">
          Email
          <input
            className="input mt-1"
            type="email"
            value={form.email}
            onChange={(e) => setField("email", e.target.value)}
            required
          />
        </label>
        <label className="block text-sm font-medium">
          Логин
          <input
            className="input mt-1"
            value={form.login}
            onChange={(e) => setField("login", e.target.value)}
            required
            autoComplete="off"
          />
        </label>
        <label className="block text-sm font-medium">
          Пароль
          <input
            className="input mt-1"
            type="password"
            value={form.password}
            onChange={(e) => setField("password", e.target.value)}
            minLength={6}
            required
            autoComplete="new-password"
          />
        </label>
        <label className="block text-sm font-medium">
          Пароль ещё раз
          <input
            className="input mt-1"
            type="password"
            value={form.password2}
            onChange={(e) => setField("password2", e.target.value)}
            minLength={6}
            required
            autoComplete="new-password"
          />
        </label>
        <label className="flex items-center gap-2 text-sm font-medium">
          <input
            type="checkbox"
            className="h-4 w-4 rounded border-slate-300 text-accent focus:ring-accent/30"
            checked={form.enabled}
            onChange={(e) => setField("enabled", e.target.checked)}
          />
          Включён
        </label>
        <div className="flex justify-end gap-2 pt-2">
          <button type="button" className="btn-secondary" onClick={onClose} disabled={loading}>
            Отмена
          </button>
          <button type="submit" className="btn-primary" disabled={loading}>
            {loading ? "Создание…" : "Создать"}
          </button>
        </div>
      </form>
    </Modal>
  );
}

function InviteUserDialog({ open, onClose, onSaved }) {
  const [form, setForm] = useState(EMPTY_INVITE_FORM);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (!open) return;
    setError("");
    setForm(EMPTY_INVITE_FORM);
  }, [open]);

  function setField(name, value) {
    setForm((prev) => ({ ...prev, [name]: value }));
  }

  async function onSubmit(e) {
    e.preventDefault();
    setError("");
    if (!form.login.trim()) {
      setError("Укажите логин");
      return;
    }
    setLoading(true);
    try {
      const data = await apiPost("admin_create", {
        login: form.login,
        enabled: form.enabled ? "1" : "0",
        invite: "1",
      });
      onSaved({ invite_url: data.invite_url });
      onClose();
    } catch (err) {
      setError(err.message || "Ошибка сохранения");
    } finally {
      setLoading(false);
    }
  }

  return (
    <Modal open={open} title="Пригласить пользователя" onClose={onClose}>
      <p className="mb-4 text-sm text-slate-600">
        Будет создан пользователь только с логином. Email и пароль он задаст сам по ссылке-приглашению.
      </p>
      {error && <Alert>{error}</Alert>}
      <form onSubmit={onSubmit} className="space-y-4">
        <label className="block text-sm font-medium">
          Логин
          <input
            className="input mt-1"
            value={form.login}
            onChange={(e) => setField("login", e.target.value)}
            required
            autoComplete="off"
          />
        </label>
        <label className="flex items-center gap-2 text-sm font-medium">
          <input
            type="checkbox"
            className="h-4 w-4 rounded border-slate-300 text-accent focus:ring-accent/30"
            checked={form.enabled}
            onChange={(e) => setField("enabled", e.target.checked)}
          />
          Включён
        </label>
        <div className="flex justify-end gap-2 pt-2">
          <button type="button" className="btn-secondary" onClick={onClose} disabled={loading}>
            Отмена
          </button>
          <button type="submit" className="btn-primary" disabled={loading}>
            {loading ? "Создание…" : "Пригласить"}
          </button>
        </div>
      </form>
    </Modal>
  );
}

function EditUserDialog({ user, open, onClose, onSaved }) {
  const [form, setForm] = useState(EMPTY_CREATE_FORM);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);
  const pendingEdit = user?.pending_registration;

  useEffect(() => {
    if (!open || !user) return;
    setError("");
    setForm({
      email: user.email || "",
      login: user.login || "",
      password: "",
      password2: "",
      enabled: !!user.enabled,
    });
  }, [open, user]);

  function setField(name, value) {
    setForm((prev) => ({ ...prev, [name]: value }));
  }

  async function onSubmit(e) {
    e.preventDefault();
    setError("");
    if (form.password !== form.password2) {
      setError("Пароли не совпадают");
      return;
    }
    if (form.password && form.password.length < 6) {
      setError("Пароль не менее 6 символов");
      return;
    }
    setLoading(true);
    try {
      const payload = {
        user_id: String(user.id),
        login: form.login,
        enabled: form.enabled ? "1" : "0",
      };
      if (!pendingEdit) {
        payload.email = form.email;
        if (form.password) payload.password = form.password;
      }
      await apiPost("admin_update", payload);
      onSaved();
      onClose();
    } catch (err) {
      setError(err.message || "Ошибка сохранения");
    } finally {
      setLoading(false);
    }
  }

  return (
    <Modal open={open} title="Редактировать пользователя" onClose={onClose}>
      {error && <Alert>{error}</Alert>}
      <form onSubmit={onSubmit} className="space-y-4">
        {!pendingEdit && (
          <label className="block text-sm font-medium">
            Email
            <input
              className="input mt-1"
              type="email"
              value={form.email}
              onChange={(e) => setField("email", e.target.value)}
              required
            />
          </label>
        )}
        <label className="block text-sm font-medium">
          Логин
          <input
            className="input mt-1"
            value={form.login}
            onChange={(e) => setField("login", e.target.value)}
            required
            autoComplete="off"
          />
        </label>
        {!pendingEdit && (
          <>
            <label className="block text-sm font-medium">
              Пароль
              <input
                className="input mt-1"
                type="password"
                value={form.password}
                onChange={(e) => setField("password", e.target.value)}
                autoComplete="new-password"
                placeholder="Оставьте пустым, чтобы не менять"
              />
            </label>
            <label className="block text-sm font-medium">
              Пароль ещё раз
              <input
                className="input mt-1"
                type="password"
                value={form.password2}
                onChange={(e) => setField("password2", e.target.value)}
                autoComplete="new-password"
              />
            </label>
          </>
        )}
        <label className="flex items-center gap-2 text-sm font-medium">
          <input
            type="checkbox"
            className="h-4 w-4 rounded border-slate-300 text-accent focus:ring-accent/30"
            checked={form.enabled}
            onChange={(e) => setField("enabled", e.target.checked)}
          />
          Включён
        </label>
        <div className="flex justify-end gap-2 pt-2">
          <button type="button" className="btn-secondary" onClick={onClose} disabled={loading}>
            Отмена
          </button>
          <button type="submit" className="btn-primary" disabled={loading}>
            {loading ? "Сохранение…" : "Сохранить"}
          </button>
        </div>
      </form>
    </Modal>
  );
}

function UserDetail({ userId, onBack, onFlash, onEdit, onInviteLink, refreshToken = 0 }) {
  const [detail, setDetail] = useState(null);

  const load = useCallback(async () => {
    const data = await apiGet("admin_user", { user_id: String(userId) });
    setDetail(data);
  }, [userId]);

  useEffect(() => {
    load().catch((e) => onFlash(e.message, "error"));
  }, [load, onFlash, refreshToken]);

  if (!detail) return <p className="text-slate-500">Загрузка…</p>;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap gap-2">
        <button type="button" className="btn-secondary" onClick={onBack}>
          ← К списку
        </button>
        {!detail.user.deleted_at && (
          <button type="button" className="btn-primary" onClick={() => onEdit(detail.user)}>
            Редактировать
          </button>
        )}
      </div>
      <div className="card">
        <h2 className="text-lg font-semibold">
          {detail.user.login} (#{detail.user.id})
        </h2>
        <p className="text-sm text-slate-500">{detail.user.email || "—"}</p>
        <p className="mt-1 text-sm text-slate-500">
          Статус:{" "}
          {detail.user.deleted_at
            ? "удалён"
            : detail.user.pending_registration
              ? "ожидает регистрации"
              : detail.user.enabled
                ? "активен"
                : "блок"}
        </p>
        {detail.user.pending_registration && !detail.user.deleted_at && (
          <button
            type="button"
            className="btn-primary mt-3"
            onClick={async () => {
              try {
                const data = await apiPost("admin_regenerate_invite", { user_id: String(userId) });
                onInviteLink(data.invite_url);
              } catch (e) {
                onFlash(e.message, "error");
              }
            }}
          >
            Новая ссылка для регистрации
          </button>
        )}
      </div>

      <section className="card">
        <h3 className="mb-3 font-semibold">Push-подписки</h3>
        {detail.subscriptions?.length === 0 ? (
          <p className="text-sm text-slate-500">Нет</p>
        ) : (
          <ul className="space-y-2 text-sm">
            {detail.subscriptions?.map((s) => (
              <li key={s.id} className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 py-2">
                <span className="truncate text-slate-600">
                  #{s.id} {s.endpoint?.slice(0, 48)}…
                </span>
                <button
                  type="button"
                  className="btn-danger !py-1 !px-2 text-xs"
                  onClick={async () => {
                    await apiPost("admin_delete_subscription", { subscription_id: String(s.id) });
                    onFlash("Подписка удалена", "success");
                    load();
                  }}
                >
                  Отозвать
                </button>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className="card">
        <h3 className="mb-3 font-semibold">Inbox</h3>
        <div className="max-h-96 space-y-2 overflow-y-auto">
          {detail.notifications?.map((n) => (
            <div
              key={n.id}
              className={`rounded-lg border p-3 text-sm ${!n.seen_at ? "border-accent/40 bg-blue-50/50" : "border-slate-200"}`}
            >
              <div className="mb-1 flex justify-between gap-2 font-medium">
                <span>{n.rule_name}</span>
                <span className="text-xs text-slate-500">{n.created_at}</span>
              </div>
              <pre className="whitespace-pre-wrap text-slate-700">{n.message_text}</pre>
            </div>
          ))}
        </div>
      </section>
    </div>
  );
}

function AdminPanel() {
  const [users, setUsers] = useState([]);
  const [selectedId, setSelectedId] = useState(0);
  const [flash, setFlash] = useState(null);
  const [dialog, setDialog] = useState(null); // null | 'create' | 'invite' | { edit: user }
  const [inviteUrl, setInviteUrl] = useState("");
  const [detailRefresh, setDetailRefresh] = useState(0);

  const showFlash = useCallback((msg, type = "success") => setFlash({ msg, type }), []);

  const loadUsers = useCallback(async () => {
    const data = await apiGet("admin_users");
    setUsers(data.users || []);
  }, []);

  useEffect(() => {
    loadUsers().catch((e) => showFlash(e.message, "error"));
  }, [loadUsers, showFlash]);

  function openCreate() {
    setDialog("create");
  }

  function openInvite() {
    setDialog("invite");
  }

  function openEdit(user) {
    setDialog({ edit: user });
  }

  function handleSaved(result) {
    if (result?.invite_url) {
      setInviteUrl(result.invite_url);
      showFlash("Приглашение создано. Скопируйте ссылку для регистрации.");
    } else if (dialog === "create") {
      showFlash("Пользователь создан");
    } else {
      showFlash("Пользователь сохранён");
    }
    loadUsers();
    if (selectedId > 0) setDetailRefresh((n) => n + 1);
  }

  function closeDialog() {
    setDialog(null);
  }

  function renderDialogs() {
    return (
      <>
        <CreateUserDialog open={dialog === "create"} onClose={closeDialog} onSaved={() => handleSaved()} />
        <InviteUserDialog open={dialog === "invite"} onClose={closeDialog} onSaved={handleSaved} />
        {dialog?.edit && (
          <EditUserDialog user={dialog.edit} open onClose={closeDialog} onSaved={() => handleSaved()} />
        )}
        <InviteLinkDialog open={!!inviteUrl} url={inviteUrl} onClose={() => setInviteUrl("")} />
      </>
    );
  }

  if (selectedId > 0) {
    return (
      <AppShell
        title="Admin"
        actions={
          <a href="admin_logout.php" className="btn-secondary !text-white !border-slate-600 !bg-ink-800">
            Выход
          </a>
        }
      >
        {flash && <Alert type={flash.type}>{flash.msg}</Alert>}
        <UserDetail
          userId={selectedId}
          onBack={() => setSelectedId(0)}
          onFlash={showFlash}
          onEdit={openEdit}
          onInviteLink={setInviteUrl}
          refreshToken={detailRefresh}
        />
        {renderDialogs()}
      </AppShell>
    );
  }

  return (
    <AppShell
      title="Пользователи"
      actions={
        <>
          <button type="button" className="btn-primary" onClick={openCreate}>
            Создать пользователя
          </button>
          <button type="button" className="btn-secondary !text-white !border-slate-600 !bg-ink-800" onClick={openInvite}>
            Пригласить пользователя
          </button>
          <a href="admin_logout.php" className="btn-secondary !text-white !border-slate-600 !bg-ink-800">
            Выход
          </a>
        </>
      }
    >
      {flash && <Alert type={flash.type}>{flash.msg}</Alert>}

      <section className="card overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead>
            <tr className="border-b text-slate-500">
              <th className="py-2 pr-4">ID</th>
              <th className="py-2 pr-4">Login</th>
              <th className="py-2 pr-4">Email</th>
              <th className="py-2 pr-4">Статус</th>
              <th className="py-2">Действия</th>
            </tr>
          </thead>
          <tbody>
            {users.map((u) => (
              <tr key={u.id} className={`border-b border-slate-100 ${u.deleted_at ? "opacity-50" : ""}`}>
                <td className="py-2 pr-4">{u.id}</td>
                <td className="py-2 pr-4 font-medium">{u.login}</td>
                <td className="py-2 pr-4">{u.email || "—"}</td>
                <td className="py-2 pr-4">
                  {u.deleted_at ? "удалён" : u.pending_registration ? "ожидает регистрации" : u.enabled ? "активен" : "блок"}
                </td>
                <td className="py-2">
                  <div className="flex flex-wrap gap-1">
                    <button type="button" className="btn-secondary !py-1 !px-2 text-xs" onClick={() => setSelectedId(u.id)}>
                      Inbox
                    </button>
                    {!u.deleted_at && (
                      <>
                        <button type="button" className="btn-secondary !py-1 !px-2 text-xs" onClick={() => openEdit(u)}>
                          Изменить
                        </button>
                        <button
                          type="button"
                          className="btn-danger !py-1 !px-2 text-xs"
                          onClick={async () => {
                            if (!confirm("Удалить пользователя?")) return;
                            await apiPost("admin_delete", { user_id: String(u.id) });
                            showFlash("Пользователь удалён");
                            loadUsers();
                          }}
                        >
                          Удалить
                        </button>
                      </>
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>

      {renderDialogs()}
    </AppShell>
  );
}

function AdminApp() {
  const [authed, setAuthed] = useState(window.__ADMIN_AUTH__ === true);

  if (!authed) {
    return <AdminLogin onSuccess={() => setAuthed(true)} />;
  }
  return <AdminPanel />;
}

createRoot(document.getElementById("app")).render(<AdminApp />);
