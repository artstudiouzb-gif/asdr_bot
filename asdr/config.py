"""Конфигурация из .env / переменных окружения."""

from __future__ import annotations

import os
from dataclasses import dataclass, field
from pathlib import Path

from .cleaner import DEFAULT_BLOCKED_DOMAINS, CleanRules

BASE_DIR = Path(__file__).resolve().parent.parent


def _load_dotenv() -> None:
    try:
        from dotenv import load_dotenv
    except ImportError:  # на шаред-хостинге пакета может не быть — читаем сами
        env_file = BASE_DIR / ".env"
        if env_file.exists():
            for raw in env_file.read_text(encoding="utf-8").splitlines():
                line = raw.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                key, _, value = line.partition("=")
                os.environ[key.strip()] = value.strip().strip("'\"")
        return
    load_dotenv(BASE_DIR / ".env", override=True)


def _get(name: str, default: str = "") -> str:
    return (os.getenv(name) or default).strip()


def _get_bool(name: str, default: bool) -> bool:
    value = _get(name)
    if not value:
        return default
    return value.lower() in {"1", "true", "yes", "on", "да"}


def _get_int(name: str, default: int) -> int:
    value = _get(name)
    try:
        return int(value)
    except ValueError:
        return default


def _get_list(name: str, default: tuple[str, ...] = ()) -> tuple[str, ...]:
    value = _get(name)
    if not value:
        return tuple(default)
    parts = [p.strip() for p in value.replace("\n", ",").split(",")]
    return tuple(p for p in parts if p)


def _read_patterns(path: Path) -> tuple[str, ...]:
    """Файл с регулярками: одна на строку, # — комментарий."""
    if not path.exists():
        return ()
    lines = []
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if line and not line.startswith("#"):
            lines.append(line)
    return tuple(lines)


def _read_footer() -> str:
    """Своя подпись в конце поста.

    Приоритет у patterns/footer.html — там удобно держать многострочный HTML
    со ссылками; FOOTER в .env остаётся как запасной вариант.
    """
    path = BASE_DIR / "patterns" / "footer.html"
    if path.exists():
        lines = [
            line for line in path.read_text(encoding="utf-8").splitlines()
            if not line.strip().startswith("#")
        ]
        text = "\n".join(lines).strip()
        if text:
            return text
    return _get("FOOTER").replace("\\n", "\n")


def _normalize_source(value: str) -> str | int:
    value = value.strip()
    if not value:
        return value
    if value.lstrip("-").isdigit():
        return int(value)
    for prefix in ("https://t.me/", "http://t.me/", "t.me/", "@"):
        if value.lower().startswith(prefix.lower()):
            value = value[len(prefix):]
            break
    return value.split("/", 1)[0].lstrip("@")


@dataclass
class Config:
    api_id: int
    api_hash: str
    session: str
    sources: tuple[str | int, ...]
    target: str | int
    bot_token: str = ""
    bot_username: str = ""
    admin_ids: tuple[str, ...] = ()
    mode: str = "copy"                 # copy | forward
    data_dir: Path = BASE_DIR / "data"
    # что и как публикуем
    include_keywords: tuple[str, ...] = ()
    exclude_keywords: tuple[str, ...] = ()
    min_text_length: int = 0
    skip_without_text: bool = False
    skip_media: bool = False
    skip_forwards: bool = False
    add_source_link: bool = False
    silent: bool = False
    # ограничители для шаред-хостинга
    poll_limit: int = 20               # сколько сообщений тянуть за один запуск
    max_posts_per_run: int = 10
    max_runtime: int = 120             # секунд; дальше выходим до kill'а хостинга
    delay_between_posts: float = 3.0
    backfill_on_first_run: int = 0     # 0 — стартуем с текущего момента
    web_secret: str = ""               # токен для запуска по URL
    rules: CleanRules = field(default_factory=CleanRules)

    @property
    def db_path(self) -> Path:
        return self.data_dir / "asdr.db"

    @property
    def lock_path(self) -> Path:
        return self.data_dir / "asdr.lock"

    @property
    def watch_pid_path(self) -> Path:
        return self.data_dir / "asdr-watch.pid"

    @property
    def session_path(self) -> Path:
        return self.data_dir / "asdr.session"

    @staticmethod
    def credentials() -> tuple[int, str]:
        """Только API_ID/API_HASH — нужно команде login до настройки каналов."""
        _load_dotenv()
        api_id, api_hash = _get_int("API_ID", 0), _get("API_HASH")
        if not api_id or not api_hash:
            raise SystemExit(
                "Не заданы API_ID/API_HASH в .env. "
                "Получите их на https://my.telegram.org -> API development tools."
            )
        return api_id, api_hash

    @classmethod
    def load(cls) -> "Config":
        _load_dotenv()

        missing = [k for k in ("API_ID", "API_HASH") if not _get(k)]
        if missing:
            raise SystemExit(
                "Не заданы " + ", ".join(missing) + " в .env. "
                "Получите их на https://my.telegram.org -> API development tools."
            )

        sources = tuple(_normalize_source(s) for s in _get_list("SOURCES"))
        if not sources:
            raise SystemExit("Не задан SOURCES в .env (каналы-источники через запятую).")
        target_raw = _get("TARGET")
        if not target_raw:
            raise SystemExit("Не задан TARGET в .env (куда публикуем).")

        data_dir = Path(_get("DATA_DIR") or (BASE_DIR / "data"))
        if not data_dir.is_absolute():
            data_dir = BASE_DIR / data_dir

        rules = CleanRules(
            blocked_domains=_get_list("BLOCKED_DOMAINS", DEFAULT_BLOCKED_DOMAINS),
            drop_line_patterns=_read_patterns(BASE_DIR / "patterns" / "drop_lines.txt")
            + _get_list("DROP_LINE_PATTERNS"),
            remove_patterns=_read_patterns(BASE_DIR / "patterns" / "remove.txt")
            + _get_list("REMOVE_PATTERNS"),
            unwrap_blocked_links=_get_bool("UNWRAP_BLOCKED_LINKS", True),
            drop_subscribe_lines=_get_bool("DROP_SUBSCRIBE_LINES", True),
            drop_hashtags=_get_bool("DROP_HASHTAGS", False),
            footer=_read_footer(),
        )

        return cls(
            api_id=_get_int("API_ID", 0),
            api_hash=_get("API_HASH"),
            session=_get("SESSION"),
            sources=sources,
            target=_normalize_source(target_raw) if not target_raw.lstrip("-").isdigit() else int(target_raw),
            bot_token=_get("BOT_TOKEN"),
            bot_username=_get("BOT_USERNAME").lstrip("@"),
            admin_ids=_get_list("ADMIN_IDS"),
            mode=_get("MODE", "copy").lower(),
            data_dir=data_dir,
            include_keywords=_get_list("INCLUDE_KEYWORDS"),
            exclude_keywords=_get_list("EXCLUDE_KEYWORDS"),
            min_text_length=_get_int("MIN_TEXT_LENGTH", 0),
            skip_without_text=_get_bool("SKIP_WITHOUT_TEXT", False),
            skip_media=_get_bool("SKIP_MEDIA", False),
            skip_forwards=_get_bool("SKIP_FORWARDS", False),
            add_source_link=_get_bool("ADD_SOURCE_LINK", False),
            silent=_get_bool("SILENT", False),
            poll_limit=_get_int("POLL_LIMIT", 20),
            max_posts_per_run=_get_int("MAX_POSTS_PER_RUN", 10),
            max_runtime=_get_int("MAX_RUNTIME", 120),
            delay_between_posts=float(_get("DELAY_BETWEEN_POSTS", "3") or 3),
            backfill_on_first_run=_get_int("BACKFILL_ON_FIRST_RUN", 0),
            web_secret=_get("WEB_SECRET"),
            rules=rules,
        )
