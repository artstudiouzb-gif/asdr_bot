"""WSGI-приложение: панель настроек с входом через Telegram + запуск по URL для cron.

Роуты:
    /              — панель (или страница входа)
    /login, /auth  — вход через Telegram Login Widget
    /logout
    /admin         — сохранение настроек
    /admin/run     — запустить проход / пробный прогон / проверку доступов
    /admin/test    — предпросмотр очистки текста
    /run?key=…     — запуск из cron по URL (без авторизации, по секретному ключу)
    /status?key=…
"""

from __future__ import annotations

import json
import os
import subprocess
import sys
from http.cookies import SimpleCookie
from pathlib import Path
from urllib.parse import parse_qs, quote

from ..cleaner import clean_html
from ..config import BASE_DIR, Config
from . import pages
from .auth import (
    COOKIE_NAME,
    SESSION_TTL,
    AuthError,
    check_csrf,
    csrf_token,
    is_admin,
    make_session,
    read_session,
    secret_key,
    verify_telegram_auth,
)
from .envstore import (
    EDITABLE_KEYS,
    mask,
    read_env,
    read_text_file,
    write_env,
    write_text_file,
)

ENV_PATH = BASE_DIR / ".env"
FOOTER_PATH = BASE_DIR / "patterns" / "footer.html"
DROP_PATH = BASE_DIR / "patterns" / "drop_lines.txt"
REMOVE_PATH = BASE_DIR / "patterns" / "remove.txt"
MAX_BODY = 512 * 1024

BOOL_FIELDS = (
    "ADD_SOURCE_LINK", "SILENT", "UNWRAP_BLOCKED_LINKS", "DROP_SUBSCRIBE_LINES",
    "DROP_HASHTAGS", "SKIP_WITHOUT_TEXT", "SKIP_MEDIA", "SKIP_FORWARDS",
)
NUMBER_FIELDS = (
    "MIN_TEXT_LENGTH", "POLL_LIMIT", "MAX_POSTS_PER_RUN", "MAX_RUNTIME",
    "DELAY_BETWEEN_POSTS", "BACKFILL_ON_FIRST_RUN",
)


# --- мелкие WSGI-помощники -------------------------------------------------
def _respond(start_response, status: str, body: str, content_type: str, cookies=()):
    data = body.encode("utf-8")
    headers = [
        ("Content-Type", f"{content_type}; charset=utf-8"),
        ("Content-Length", str(len(data))),
        ("Cache-Control", "no-store"),
        ("X-Content-Type-Options", "nosniff"),
        ("Referrer-Policy", "no-referrer"),
    ]
    headers += [("Set-Cookie", c) for c in cookies]
    start_response(status, headers)
    return [data]


def _html(start_response, body: str, status: str = "200 OK", cookies=()):
    return _respond(start_response, status, body, "text/html", cookies)


def _json_out(start_response, status: str, payload: dict):
    return _respond(start_response, status, json.dumps(payload, ensure_ascii=False, indent=2),
                    "application/json")


def _redirect(start_response, location: str, cookies=()):
    headers = [("Location", location), ("Content-Length", "0"), ("Cache-Control", "no-store")]
    headers += [("Set-Cookie", c) for c in cookies]
    start_response("303 See Other", headers)
    return [b""]


def _form(environ) -> dict[str, str]:
    try:
        length = min(int(environ.get("CONTENT_LENGTH") or 0), MAX_BODY)
    except ValueError:
        length = 0
    raw = environ["wsgi.input"].read(length).decode("utf-8", "replace") if length else ""
    return {k: v[0] for k, v in parse_qs(raw, keep_blank_values=True).items()}


def _cookie(environ, name: str) -> str:
    jar = SimpleCookie()
    jar.load(environ.get("HTTP_COOKIE", ""))
    return jar[name].value if name in jar else ""


def _is_https(environ) -> bool:
    return (environ.get("HTTP_X_FORWARDED_PROTO") or environ.get("wsgi.url_scheme")) == "https"


def _set_cookie(value: str, environ, max_age: int = SESSION_TTL) -> str:
    parts = [f"{COOKIE_NAME}={value}", "Path=/", f"Max-Age={max_age}", "HttpOnly", "SameSite=Lax"]
    if _is_https(environ):
        parts.append("Secure")
    return "; ".join(parts)


def _base_url(environ) -> str:
    scheme = "https" if _is_https(environ) else "http"
    host = environ.get("HTTP_HOST") or environ.get("SERVER_NAME", "")
    return f"{scheme}://{host}"


# --- запуск команд бота ----------------------------------------------------
def _run_cli(args: list[str], wait: bool, timeout: int = 180) -> dict:
    cmd = [sys.executable, "-m", "asdr", *args]
    env = {**os.environ, "PYTHONPATH": str(BASE_DIR)}
    if not wait:
        subprocess.Popen(cmd, cwd=str(BASE_DIR), env=env, stdout=subprocess.DEVNULL,
                         stderr=subprocess.DEVNULL, start_new_session=True)
        return {"ok": True, "started": True, "output": "Запущено в фоне."}
    try:
        proc = subprocess.run(cmd, cwd=str(BASE_DIR), env=env, capture_output=True,
                              text=True, timeout=timeout)
    except subprocess.TimeoutExpired:
        return {"ok": False, "output": "Превышено время ожидания."}
    return {"ok": proc.returncode == 0, "code": proc.returncode,
            "output": ((proc.stdout or "") + (proc.stderr or "")).strip() or "(без вывода)"}


def _watch_pid(cfg: Config) -> int:
    """pid работающего демона слежения, 0 — если не запущен."""
    from ..storage import _process_alive

    path = cfg.watch_pid_path
    if not path.exists():
        return 0
    try:
        pid = int(path.read_text().strip() or 0)
    except (ValueError, OSError):
        return 0
    return pid if _process_alive(pid) else 0


def _stop_watch(cfg: Config) -> str:
    import signal

    pid = _watch_pid(cfg)
    if not pid:
        return "Слежение и так не запущено."
    try:
        os.kill(pid, signal.SIGTERM)
    except OSError as exc:
        return f"Не смог остановить процесс {pid}: {exc}"
    return f"Слежение остановлено (pid {pid})."


def _tail(path: Path, lines: int = 40) -> str:
    if not path.exists():
        return ""
    try:
        content = path.read_text(encoding="utf-8", errors="replace").splitlines()
    except OSError:
        return ""
    return "\n".join(content[-lines:])


def _stats(cfg: Config) -> dict:
    from ..storage import Storage

    if not cfg.db_path.exists():
        return {"posted": 0, "cursors": {}}
    storage = Storage(cfg.db_path)
    try:
        return storage.stats()
    finally:
        storage.close()


# --- страницы --------------------------------------------------------------
def _context(cfg: Config, session: dict, cookie_value: str, key: bytes, **extra) -> dict:
    env = read_env(ENV_PATH)
    ctx = {
        "env": env,
        "user": session,
        "user_id": session.get("id", ""),
        "csrf": csrf_token(cookie_value, key),
        "footer": read_text_file(FOOTER_PATH).strip(),
        "drop_lines": read_text_file(DROP_PATH),
        "remove_patterns": read_text_file(REMOVE_PATH),
        "bot_token_masked": mask(env.get("BOT_TOKEN", "")),
        "web_secret_masked": mask(env.get("WEB_SECRET", "")),
        "stats": _stats(cfg),
        "log": _tail(cfg.data_dir / "asdr.log"),
        "watching": bool(_watch_pid(cfg)),
    }
    ctx.update(extra)
    return ctx


def _save_settings(form: dict, current_user_id: str) -> str:
    updates: dict[str, str] = {}
    for key in EDITABLE_KEYS:
        if key in ("BOT_TOKEN", "WEB_SECRET"):
            continue
        if key in BOOL_FIELDS:
            updates[key] = "true" if form.get(key) else "false"
        elif key in NUMBER_FIELDS:
            value = (form.get(key, "") or "0").strip().replace(",", ".")
            try:
                updates[key] = str(float(value)) if key == "DELAY_BETWEEN_POSTS" else str(int(float(value)))
            except ValueError:
                updates[key] = "0"
        elif key in form:
            updates[key] = form[key].strip()

    # защита от самоблокировки: свой id всегда остаётся в списке админов
    admins = [a.strip() for a in updates.get("ADMIN_IDS", "").split(",") if a.strip()]
    if current_user_id and current_user_id not in admins:
        admins.append(current_user_id)
    updates["ADMIN_IDS"] = ",".join(admins)

    if form.get("MODE") not in ("copy", "forward"):
        updates["MODE"] = "copy"

    for form_key, env_key in (("BOT_TOKEN_NEW", "BOT_TOKEN"), ("WEB_SECRET_NEW", "WEB_SECRET")):
        value = form.get(form_key, "").strip()
        if value:
            updates[env_key] = value

    write_env(ENV_PATH, updates)
    write_text_file(FOOTER_PATH, form.get("FOOTER_HTML", ""))
    write_text_file(DROP_PATH, form.get("DROP_LINES", ""))
    write_text_file(REMOVE_PATH, form.get("REMOVE_PATTERNS", ""))
    return "Настройки сохранены."


# --- роутер ----------------------------------------------------------------
def application(environ, start_response):
    path = environ.get("PATH_INFO", "/").rstrip("/") or "/"
    method = environ.get("REQUEST_METHOD", "GET").upper()
    query = parse_qs(environ.get("QUERY_STRING", ""))

    try:
        cfg = Config.load()
    except SystemExit as exc:
        if path in ("/run", "/status"):
            return _json_out(start_response, "500 Internal Server Error", {"ok": False, "error": str(exc)})
        return _html(start_response, pages.login_page("", str(exc)), "500 Internal Server Error")

    # --- cron по URL: без cookie, по секретному ключу ---
    if path in ("/run", "/status"):
        key = (query.get("key") or [""])[0]
        if not cfg.web_secret or key != cfg.web_secret:
            return _json_out(start_response, "403 Forbidden", {"ok": False, "error": "неверный key"})
        wait = (query.get("wait") or ["0"])[0] in {"1", "true", "yes"}
        args = ["poll"] if path == "/run" else ["status"]
        return _json_out(start_response, "200 OK", _run_cli(args, wait or path == "/status", timeout=600))

    key = secret_key(cfg)
    cookie_value = _cookie(environ, COOKIE_NAME)
    session = read_session(cookie_value, key)
    if session and not is_admin(session.get("id", ""), cfg.admin_ids):
        session = None  # id убрали из ADMIN_IDS — доступ снимается сразу

    auth_url = _base_url(environ) + "/auth"

    if path == "/auth":
        data = {k: v[0] for k, v in query.items()}
        try:
            user = verify_telegram_auth(data, cfg.bot_token)
        except AuthError as exc:
            return _html(start_response, pages.login_page(cfg.bot_username, str(exc), auth_url=auth_url),
                         "403 Forbidden")
        if not is_admin(user["id"], cfg.admin_ids):
            message = (
                f"Вход запрещён. Ваш Telegram ID: {user['id']} — "
                "добавьте его в ADMIN_IDS в файле .env и войдите снова."
            )
            return _html(start_response, pages.login_page(cfg.bot_username, message, auth_url=auth_url),
                         "403 Forbidden")
        cookie = _set_cookie(make_session(user, key), environ)
        return _redirect(start_response, "/admin", cookies=[cookie])

    if path == "/logout":
        return _redirect(start_response, "/login", cookies=[_set_cookie("", environ, max_age=0)])

    if path == "/login" or (path == "/" and not session):
        message = (query.get("e") or [""])[0]
        return _html(start_response, pages.login_page(cfg.bot_username, message, auth_url=auth_url))

    if not session:
        return _redirect(start_response, "/login")

    if path == "/":
        return _redirect(start_response, "/admin")

    if path.startswith("/admin"):
        if method == "POST":
            form = _form(environ)
            if not check_csrf(form.get("csrf", ""), cookie_value, key):
                return _html(start_response, pages.login_page(cfg.bot_username,
                             "Форма устарела, войдите заново", auth_url=auth_url), "403 Forbidden")

            if path == "/admin":
                message = _save_settings(form, str(session.get("id", "")))
                return _redirect(start_response, "/admin?m=" + quote(message))

            if path == "/admin/run":
                action = form.get("action", "run")
                if action == "watch_start":
                    result = _run_cli(["watch"], False)
                    result["output"] = "Слежение запущено."
                elif action == "watch_stop":
                    result = {"ok": True, "output": _stop_watch(cfg)}
                elif action == "dry":
                    result = _run_cli(["poll", "--dry-run"], True)
                elif action == "check":
                    result = _run_cli(["check"], True)
                else:
                    result = _run_cli(["poll"], False)
                ctx = _context(cfg, session, cookie_value, key, output=result.get("output", ""),
                               message="" if result.get("ok") else "Команда завершилась с ошибкой",
                               message_kind="err")
                return _html(start_response, pages.admin_page(ctx))

            if path == "/admin/test":
                sample = form.get("sample", "")
                ctx = _context(cfg, session, cookie_value, key, sample=sample,
                               preview=clean_html(sample, cfg.rules) or "(пусто — пост был бы пропущен)")
                return _html(start_response, pages.admin_page(ctx))

        if path == "/admin" and method == "GET":
            ctx = _context(cfg, session, cookie_value, key,
                           message=(query.get("m") or [""])[0])
            return _html(start_response, pages.admin_page(ctx))

    return _html(start_response, pages.layout("Не найдено", "<div class='card'>Страница не найдена. "
                 "<a href='/admin'>В панель</a></div>", session), "404 Not Found")
