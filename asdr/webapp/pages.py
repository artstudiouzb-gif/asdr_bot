"""HTML веб-панели. Без шаблонизаторов и внешних зависимостей —
на шаред-хостинге чем меньше пакетов, тем меньше поводов для сюрпризов."""

from __future__ import annotations

from html import escape as e

CSS = """
:root{
  --bg:#f4f6fa; --card:#ffffff; --text:#111827; --muted:#6b7280; --line:#e5e7eb;
  --accent:#2481cc; --accent-text:#ffffff; --ok:#15803d; --err:#b91c1c; --code:#f3f4f6;
}
@media (prefers-color-scheme: dark){
  :root{ --bg:#0f1420; --card:#171d2b; --text:#e8eaf0; --muted:#9aa3b2; --line:#26304a;
         --accent:#3a9ae0; --accent-text:#0b1220; --ok:#4ade80; --err:#f87171; --code:#0e1626; }
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);
     font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
.wrap{max-width:900px;margin:0 auto;padding:24px 16px 64px}
header{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;
       margin-bottom:20px}
h1{font-size:20px;margin:0}
h2{font-size:16px;margin:0 0 4px}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px;margin-bottom:16px}
.card p.hint{color:var(--muted);font-size:13px;margin:0 0 14px}
label{display:block;font-weight:600;font-size:13px;margin:14px 0 6px}
label:first-of-type{margin-top:0}
input[type=text],input[type=number],textarea,select{
  width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:9px;
  background:var(--bg);color:var(--text);font:inherit}
textarea{min-height:90px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px}
small{display:block;color:var(--muted);font-size:12px;margin-top:4px}
.row{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0 16px}
.check{display:flex;align-items:center;gap:8px;margin:10px 0;font-size:14px}
.check input{width:16px;height:16px}
button{background:var(--accent);color:var(--accent-text);border:0;border-radius:9px;
       padding:10px 16px;font:inherit;font-weight:600;cursor:pointer}
button.ghost{background:transparent;color:var(--accent);border:1px solid var(--accent)}
.actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px}
.flash{padding:11px 14px;border-radius:10px;margin-bottom:16px;font-size:14px}
.flash.ok{background:rgba(34,197,94,.12);color:var(--ok)}
.flash.err{background:rgba(239,68,68,.12);color:var(--err)}
pre{background:var(--code);border:1px solid var(--line);border-radius:10px;padding:12px;
    overflow:auto;font-size:12.5px;margin:0;white-space:pre-wrap;word-break:break-word}
.user{display:flex;align-items:center;gap:10px;color:var(--muted);font-size:14px}
.stat{display:flex;gap:24px;flex-wrap:wrap;margin-bottom:10px}
.stat div b{display:block;font-size:19px}
.stat div span{color:var(--muted);font-size:12px}
a{color:var(--accent)}
footer{color:var(--muted);font-size:12px;text-align:center;margin-top:24px}
"""


def layout(title: str, body: str, user: dict | None = None) -> str:
    head = ""
    if user:
        name = e(user.get("name") or user.get("username") or user.get("id", ""))
        head = (
            f'<div class="user">{name}'
            f'<form method="post" action="/logout" style="display:inline">'
            f'<button class="ghost" type="submit">Выйти</button></form></div>'
        )
    return f"""<!doctype html>
<html lang="ru"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title>{e(title)}</title><style>{CSS}</style></head>
<body><div class="wrap">
<header><h1>{e(title)}</h1>{head}</header>
{body}
<footer>asdr_bot — репостер Telegram-каналов</footer>
</div></body></html>"""


def flash(message: str, kind: str = "ok") -> str:
    return f'<div class="flash {kind}">{e(message)}</div>' if message else ""


def login_page(bot_username: str, message: str = "", kind: str = "err", auth_url: str = "") -> str:
    if bot_username:
        widget = f"""
<script async src="https://telegram.org/js/telegram-widget.js?22"
  data-telegram-login="{e(bot_username)}"
  data-size="large"
  data-userpic="false"
  data-auth-url="{e(auth_url)}"
  data-request-access="write"></script>"""
        hint = (
            "Кнопка работает, если в @BotFather для бота задан домен этого сайта: "
            "<code>/setdomain</code>."
        )
    else:
        widget = "<p><b>Не задан BOT_USERNAME</b> в .env — кнопка входа не появится.</p>"
        hint = "Укажите BOT_USERNAME (без @) и BOT_TOKEN в .env, затем обновите страницу."

    body = f"""{flash(message, kind)}
<div class="card">
  <h2>Вход в панель</h2>
  <p class="hint">Доступ только для администраторов из списка ADMIN_IDS.</p>
  {widget}
  <small>{hint}</small>
</div>"""
    return layout("asdr_bot — вход", body)


def _text(name: str, label: str, value: str, hint: str = "", kind: str = "text") -> str:
    return (
        f'<label for="{name}">{e(label)}</label>'
        f'<input type="{kind}" id="{name}" name="{name}" value="{e(str(value))}">'
        + (f"<small>{hint}</small>" if hint else "")
    )


def _area(name: str, label: str, value: str, hint: str = "") -> str:
    return (
        f'<label for="{name}">{e(label)}</label>'
        f'<textarea id="{name}" name="{name}">{e(value or "")}</textarea>'
        + (f"<small>{hint}</small>" if hint else "")
    )


def _check(name: str, label: str, value: bool) -> str:
    checked = " checked" if value else ""
    return (
        f'<div class="check"><input type="checkbox" id="{name}" name="{name}" value="1"{checked}>'
        f'<label for="{name}" style="margin:0;font-weight:400">{e(label)}</label></div>'
    )


def _truthy(value: str) -> bool:
    return str(value).strip().lower() in {"1", "true", "yes", "on", "да"}


def admin_page(ctx: dict) -> str:
    env = ctx["env"]
    csrf = f'<input type="hidden" name="csrf" value="{e(ctx["csrf"])}">'
    stats = ctx["stats"]
    watching = ctx.get("watching")
    watch_state = "работает" if watching else "остановлено"
    watch_action = "watch_stop" if watching else "watch_start"
    watch_button = "Остановить слежение" if watching else "Включить слежение"
    cursors = "".join(
        f"<div><b>{e(str(v))}</b><span>{e(k)}</span></div>" for k, v in stats["cursors"].items()
    ) or '<div><span>каналы ещё не читались</span></div>'

    body = [flash(ctx.get("message", ""), ctx.get("message_kind", "ok"))]

    body.append(f"""
<div class="card">
  <h2>Состояние</h2>
  <div class="stat">
    <div><b>{stats['posted']}</b><span>опубликовано постов</span></div>
    {cursors}
  </div>
  <p class="hint">Слежение в реальном времени: {watch_state}</p>
  <form method="post" action="/admin/run">{csrf}
    <div class="actions">
      <button type="submit" name="action" value="{watch_action}">{watch_button}</button>
      <button class="ghost" type="submit" name="action" value="run">Забрать новое сейчас</button>
      <button class="ghost" type="submit" name="action" value="dry">Пробный прогон</button>
      <button class="ghost" type="submit" name="action" value="check">Проверить доступы</button>
    </div>
  </form>
  {f'<p></p><pre>{e(ctx["output"])}</pre>' if ctx.get("output") else ''}
</div>""")

    body.append(f"""
<form method="post" action="/admin">{csrf}
<div class="card">
  <h2>Каналы</h2>
  <p class="hint">Аккаунт из SESSION должен видеть источники, а бот (или тот же аккаунт) —
     иметь право публиковать в целевой канал.</p>
  {_text("SOURCES", "Источники", env.get("SOURCES", ""), "через запятую: @channel, t.me/channel или -100…")}
  {_text("TARGET", "Куда публикуем", env.get("TARGET", ""), "@my_channel или -100…")}
  <label for="MODE">Режим</label>
  <select id="MODE" name="MODE">
    <option value="copy"{" selected" if env.get("MODE", "copy") != "forward" else ""}>copy — пересобрать пост (очистка работает)</option>
    <option value="forward"{" selected" if env.get("MODE") == "forward" else ""}>forward — обычная пересылка</option>
  </select>
  {_check("ADD_SOURCE_LINK", "Добавлять ссылку на источник", _truthy(env.get("ADD_SOURCE_LINK", "")))}
  {_check("SILENT", "Публиковать без звука", _truthy(env.get("SILENT", "")))}
</div>

<div class="card">
  <h2>Очистка подписей</h2>
  <p class="hint">Строка-подпись источника (Prezident.uz | Facebook | …) вырезается целиком,
     вместо неё в конец поста добавляется ваша подпись.</p>
  {_area("FOOTER_HTML", "Ваша подпись (HTML)", ctx["footer"],
         'например: &lt;a href="https://asr.gov.uz/"&gt;website&lt;/a&gt; | &lt;a href="…"&gt;facebook&lt;/a&gt;')}
  {_text("BLOCKED_DOMAINS", "Домены-подписи", env.get("BLOCKED_DOMAINS", ""),
         "ссылки на эти домены считаются подписью источника")}
  {_check("UNWRAP_BLOCKED_LINKS", "Убирать такие ссылки и внутри текста (текст остаётся)", _truthy(env.get("UNWRAP_BLOCKED_LINKS", "true")))}
  {_check("DROP_SUBSCRIBE_LINES", "Удалять строки «подпишитесь на канал»", _truthy(env.get("DROP_SUBSCRIBE_LINES", "true")))}
  {_check("DROP_HASHTAGS", "Удалять строки, состоящие только из хештегов", _truthy(env.get("DROP_HASHTAGS", "")))}
  {_area("DROP_LINES", "Удалять строки по шаблонам", ctx["drop_lines"], "по одной регулярке в строке, # — комментарий")}
  {_area("REMOVE_PATTERNS", "Вырезать фрагменты по шаблонам", ctx["remove_patterns"], "по одной регулярке в строке")}
</div>

<div class="card">
  <h2>Фильтры</h2>
  <div class="row">
    {_text("INCLUDE_KEYWORDS", "Публиковать только со словами", env.get("INCLUDE_KEYWORDS", ""), "через запятую, пусто — без ограничений")}
    {_text("EXCLUDE_KEYWORDS", "Пропускать со словами", env.get("EXCLUDE_KEYWORDS", ""), "через запятую")}
    {_text("MIN_TEXT_LENGTH", "Минимальная длина текста", env.get("MIN_TEXT_LENGTH", "0"), "символов, 0 — без ограничения", "number")}
  </div>
  {_check("SKIP_WITHOUT_TEXT", "Пропускать посты без текста", _truthy(env.get("SKIP_WITHOUT_TEXT", "")))}
  {_check("SKIP_MEDIA", "Пропускать посты с медиа", _truthy(env.get("SKIP_MEDIA", "")))}
  {_check("SKIP_FORWARDS", "Пропускать пересланные посты", _truthy(env.get("SKIP_FORWARDS", "")))}
</div>

<div class="card">
  <h2>Лимиты запуска</h2>
  <p class="hint">Защита от того, что шаред-хостинг убьёт долгий процесс: остаток заберётся
     следующим запуском cron.</p>
  <div class="row">
    {_text("POLL_LIMIT", "Сообщений за проход", env.get("POLL_LIMIT", "20"), "", "number")}
    {_text("MAX_POSTS_PER_RUN", "Публикаций за проход", env.get("MAX_POSTS_PER_RUN", "10"), "", "number")}
    {_text("MAX_RUNTIME", "Максимум секунд на проход", env.get("MAX_RUNTIME", "120"), "", "number")}
    {_text("DELAY_BETWEEN_POSTS", "Пауза между постами, сек", env.get("DELAY_BETWEEN_POSTS", "3"), "", "number")}
    {_text("BACKFILL_ON_FIRST_RUN", "Забрать при первом запуске", env.get("BACKFILL_ON_FIRST_RUN", "0"), "0 — начать с текущего момента", "number")}
  </div>
</div>

<div class="card">
  <h2>Доступ</h2>
  <div class="row">
    {_text("ADMIN_IDS", "Telegram ID администраторов", env.get("ADMIN_IDS", ""), "через запятую; ваш ID: " + e(str(ctx.get("user_id", ""))))}
    {_text("BOT_TOKEN_NEW", "Новый токен бота", "", "сейчас: " + (e(ctx["bot_token_masked"]) or "не задан") + "; оставьте пустым, чтобы не менять")}
    {_text("WEB_SECRET_NEW", "Новый ключ для cron по URL", "", "сейчас: " + (e(ctx["web_secret_masked"]) or "не задан"))}
  </div>
  <small>API_ID, API_HASH и SESSION меняются только в .env — через браузер они не передаются.</small>
</div>

<div class="actions"><button type="submit">Сохранить настройки</button></div>
</form>""")

    body.append(f"""
<div class="card">
  <h2>Проверка очистки</h2>
  <p class="hint">Вставьте текст поста (можно с HTML-ссылками) и посмотрите, что уйдёт в канал.</p>
  <form method="post" action="/admin/test">{csrf}
    {_area("sample", "Исходный пост", ctx.get("sample", ""))}
    <div class="actions"><button class="ghost" type="submit">Показать результат</button></div>
  </form>
  {f'<p></p><pre>{e(ctx["preview"])}</pre>' if ctx.get("preview") else ''}
</div>

<div class="card">
  <h2>Журнал</h2>
  <pre>{e(ctx.get("log", "") or "пока пусто")}</pre>
</div>""")

    return layout("asdr_bot — настройки", "\n".join(body), ctx.get("user"))
