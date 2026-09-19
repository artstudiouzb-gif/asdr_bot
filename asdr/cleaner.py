"""Очистка текста поста: убирает подписи-футеры вида

    Prezident.uz|Facebook|Instagram|YouTube|X

(ссылки могут быть как HTML-якорями, так и голым текстом "Label (https://...)"),
а также произвольные строки по регуляркам из конфига.

Модуль намеренно не зависит от Telethon: на вход подаётся HTML-представление
сообщения (telethon.extensions.html.unparse), на выходе — такой же HTML.
Так правила легко тестировать и править без Telegram.
"""

from __future__ import annotations

import html as html_lib
import re
from dataclasses import dataclass, field
from typing import Iterable, Sequence

# Домены, ссылки на которые считаются "подписью"/соцсетями источника.
DEFAULT_BLOCKED_DOMAINS: tuple[str, ...] = (
    "president.uz",
    "prezident.uz",
    "facebook.com",
    "fb.com",
    "fb.me",
    "instagram.com",
    "youtube.com",
    "youtu.be",
    "x.com",
    "twitter.com",
    "threads.net",
    "threads.com",
    "tiktok.com",
    "vk.com",
    "ok.ru",
    "linkedin.com",
    "dzen.ru",
    "rutube.ru",
    "t.me",
    "telegram.me",
)

# Слова, из которых состоят строки-подписи. Если после удаления ссылок в строке
# остались только они (и знаки препинания) — строка считается футером.
DEFAULT_FOOTER_LABELS: tuple[str, ...] = (
    "prezident", "president", "prezidenti", "prezidentuz",
    "facebook", "fb", "instagram", "insta", "ig", "youtube", "yt",
    "x", "twitter", "telegram", "tg", "tiktok", "threads", "vk",
    "ok", "odnoklassniki", "linkedin", "dzen", "rutube", "web", "sayt", "sayti",
    "uz", "com", "ru", "net", "org", "me", "info", "www",
)

# Строки-призывы подписаться (удаляются, если drop_subscribe_lines=True).
SUBSCRIBE_RE = re.compile(
    r"(подпис|подпиш|подписы|obuna|obuna\s*bo|subscribe|join|наш\s+канал|"
    r"bizning\s+kanal|kanalimiz|канал[ае]?\s*:?\s*@|читайте\s+нас|follow\s+us)",
    re.IGNORECASE,
)

ANCHOR_RE = re.compile(r"<a\s+href=[\"']([^\"']*)[\"'][^>]*>(.*?)</a>", re.IGNORECASE | re.DOTALL)
TAG_RE = re.compile(r"<[^>]+>")
URL_RE = re.compile(r"(?:https?://|www\.)[^\s<>()\[\]\"']+", re.IGNORECASE)
WORD_RE = re.compile(r"[^\W\d_]+", re.UNICODE)
MULTI_NL_RE = re.compile(r"\n{3,}")


def _compile(patterns: Iterable[str]) -> list[re.Pattern]:
    return [p if isinstance(p, re.Pattern) else re.compile(p, re.IGNORECASE) for p in patterns]


@dataclass
class CleanRules:
    """Набор правил очистки. Всё настраивается из .env / файлов patterns/."""

    blocked_domains: tuple[str, ...] = DEFAULT_BLOCKED_DOMAINS
    footer_labels: tuple[str, ...] = DEFAULT_FOOTER_LABELS
    drop_line_patterns: Sequence[str] = field(default_factory=tuple)
    remove_patterns: Sequence[str] = field(default_factory=tuple)
    #  оставшиеся внутри текста ссылки на заблокированные домены разворачивать в
    #  обычный текст (True) или оставлять кликабельными (False)
    unwrap_blocked_links: bool = True
    drop_subscribe_lines: bool = True
    drop_hashtags: bool = False
    footer: str = ""

    def __post_init__(self) -> None:
        self._drop_res = _compile(self.drop_line_patterns)
        self._remove_res = _compile(self.remove_patterns)
        self._labels = {w.lower() for w in self.footer_labels}
        self._domains = tuple(d.lower().lstrip(".") for d in self.blocked_domains)

    # --- вспомогательное ---------------------------------------------------
    def is_blocked_url(self, url: str) -> bool:
        host = _host_of(url)
        if not host:
            return False
        return any(host == d or host.endswith("." + d) for d in self._domains)

    def is_label_only(self, text: str) -> bool:
        """В тексте остались только слова-метки соцсетей (или ничего)."""
        words = WORD_RE.findall(text)
        return all(w.lower() in self._labels for w in words)


def _host_of(url: str) -> str:
    u = url.strip().lower()
    u = re.sub(r"^[a-z]+://", "", u)
    u = u.split("/", 1)[0].split("?", 1)[0].split("#", 1)[0]
    u = u.split("@")[-1].split(":")[0]
    return u[4:] if u.startswith("www.") else u


def _visible(line_html: str) -> str:
    """HTML строки -> видимый текст."""
    return html_lib.unescape(TAG_RE.sub("", line_html))


def _is_footer_line(line_html: str, rules: CleanRules) -> bool:
    anchors = ANCHOR_RE.findall(line_html)
    visible = _visible(line_html)
    bare_urls = URL_RE.findall(visible)

    urls = [href for href, _ in anchors] + bare_urls
    blocked = [u for u in urls if rules.is_blocked_url(u)]

    # текст без URL-ов — то, что реально читает человек
    residual = URL_RE.sub(" ", visible)
    words = WORD_RE.findall(residual)

    if blocked and rules.is_label_only(residual):
        # "Prezident.uz|Facebook|Instagram|YouTube|X" — в любом виде
        return True
    if not urls and len(words) >= 2 and rules.is_label_only(residual) and "|" in visible:
        # тот же футер, но ссылки уже потерялись при пересылке
        return True
    return False


def _strip_blocked_anchors(text_html: str, rules: CleanRules) -> str:
    def repl(m: re.Match) -> str:
        href, inner = m.group(1), m.group(2)
        if rules.is_blocked_url(href):
            return inner  # оставляем только подпись, без ссылки
        return m.group(0)

    return ANCHOR_RE.sub(repl, text_html)


def clean_html(text_html: str, rules: CleanRules | None = None) -> str:
    """Главная функция: HTML сообщения -> очищенный HTML."""
    rules = rules or CleanRules()
    if not text_html:
        return ""

    text_html = text_html.replace("\r\n", "\n").replace("\r", "\n")
    text_html = re.sub(r"<br\s*/?>", "\n", text_html, flags=re.IGNORECASE)

    # 1. построчная фильтрация
    kept: list[str] = []
    for line in text_html.split("\n"):
        stripped = line.strip()
        if stripped:
            if _is_footer_line(stripped, rules):
                continue
            visible = _visible(stripped)
            if rules.drop_subscribe_lines and SUBSCRIBE_RE.search(visible) and len(visible) <= 200:
                continue
            if any(p.search(visible) for p in rules._drop_res):
                continue
            if rules.drop_hashtags and visible.lstrip().startswith("#") and rules.is_label_only(
                re.sub(r"#\S+", " ", visible)
            ):
                continue
        kept.append(line)

    out = "\n".join(kept)

    # 2. точечные удаления по регуляркам (применяются ко всему тексту)
    for pattern in rules._remove_res:
        out = pattern.sub("", out)

    # 3. оставшиеся ссылки на соцсети источника
    if rules.unwrap_blocked_links:
        out = _strip_blocked_anchors(out, rules)

    # 4. косметика
    out = "\n".join(l.rstrip() for l in out.split("\n"))
    out = MULTI_NL_RE.sub("\n\n", out).strip()

    if rules.footer and out:
        out = f"{out}\n\n{rules.footer}"
    elif rules.footer:
        out = rules.footer
    return out


def split_caption(text: str, limit: int) -> tuple[str, str]:
    """Режет текст под лимит Telegram (1024 для подписи, 4096 для сообщения)."""
    if len(text) <= limit:
        return text, ""
    cut = text.rfind("\n", 0, limit)
    if cut < limit * 0.5:
        cut = text.rfind(" ", 0, limit)
    if cut <= 0:
        cut = limit
    return text[:cut].rstrip(), text[cut:].lstrip()
