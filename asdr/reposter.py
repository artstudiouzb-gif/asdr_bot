"""Ядро: читает новые посты из каналов-источников, чистит и публикует в целевой."""

from __future__ import annotations

import asyncio
import logging
import tempfile
import time
from pathlib import Path
from typing import Sequence

from telethon import TelegramClient
from telethon.errors import ChannelPrivateError, FloodWaitError, RPCError
from telethon.extensions import html as tg_html
from telethon.sessions import StringSession
from telethon.tl.custom.message import Message
from telethon.tl.types import MessageMediaWebPage

from .cleaner import clean_html, split_caption
from .config import Config
from .storage import Storage, body_hash

log = logging.getLogger("asdr")

CAPTION_LIMIT = 1024
MESSAGE_LIMIT = 4096


def build_client(cfg: Config, *, bot: bool = False) -> TelegramClient:
    """Клиент Telegram. SESSION-строка предпочтительнее файла: на шаред-хостинге
    файл сессии легко теряется/дублируется между запусками cron."""
    if bot:
        return TelegramClient(StringSession(), cfg.api_id, cfg.api_hash)
    if cfg.session:
        return TelegramClient(StringSession(cfg.session), cfg.api_id, cfg.api_hash)
    cfg.data_dir.mkdir(parents=True, exist_ok=True)
    return TelegramClient(str(cfg.session_path), cfg.api_id, cfg.api_hash)


def message_html(message: Message) -> str:
    """Текст сообщения в HTML (со всеми ссылками и форматированием)."""
    text = message.message or ""
    if not text:
        return ""
    return tg_html.unparse(text, message.entities or [])


def source_key(source) -> str:
    return str(source).lower()


class Reposter:
    def __init__(self, cfg: Config, storage: Storage, client: TelegramClient):
        self.cfg = cfg
        self.storage = storage
        self.client = client
        self.bot_client: TelegramClient | None = None
        self._target_entity = None
        self._deadline = time.monotonic() + cfg.max_runtime

    # --- жизненный цикл -----------------------------------------------------
    async def prepare(self) -> None:
        if not await self.client.is_user_authorized():
            raise SystemExit(
                "Сессия не авторизована. Запустите `python -m asdr login` "
                "и положите полученную строку в SESSION= в .env"
            )
        self._target_entity = await self.client.get_entity(self.cfg.target)
        if self.cfg.bot_token:
            self.bot_client = build_client(self.cfg, bot=True)
            await self.bot_client.start(bot_token=self.cfg.bot_token)

    async def close(self) -> None:
        if self.bot_client:
            await self.bot_client.disconnect()
        await self.client.disconnect()

    @property
    def out_of_time(self) -> bool:
        return time.monotonic() >= self._deadline

    # --- основной цикл ------------------------------------------------------
    async def poll_once(self, *, dry_run: bool = False, limit: int | None = None) -> int:
        published = 0
        for source in self.cfg.sources:
            if self.out_of_time or published >= self.cfg.max_posts_per_run:
                log.info("Останавливаюсь: лимит времени/постов за запуск")
                break
            try:
                published += await self._process_source(
                    source, dry_run=dry_run, limit=limit, budget=self.cfg.max_posts_per_run - published
                )
            except ChannelPrivateError:
                log.error("Нет доступа к каналу %s (приватный или аккаунт не подписан)", source)
            except FloodWaitError as exc:
                log.warning("FloodWait %sс на канале %s — выходим до следующего запуска", exc.seconds, source)
                break
            except RPCError as exc:
                log.error("Ошибка Telegram на канале %s: %s", source, exc)
        return published

    async def _process_source(self, source, *, dry_run: bool, limit: int | None, budget: int) -> int:
        key = source_key(source)
        last_id = self.storage.get_last_id(key)
        entity = await self.client.get_entity(source)

        if last_id == 0:
            # первый запуск: не заливаем канал историей
            backfill = self.cfg.backfill_on_first_run
            if backfill <= 0:
                newest = await self.client.get_messages(entity, limit=1)
                start_id = newest[0].id if newest else 0
                self.storage.set_last_id(key, start_id)
                log.info("Канал %s: старт с сообщения %s", key, start_id)
                return 0
            messages = list(await self.client.get_messages(entity, limit=backfill))
            messages.reverse()
        else:
            fetch_limit = limit or self.cfg.poll_limit
            messages = list(
                await self.client.get_messages(entity, limit=fetch_limit, min_id=last_id)
            )
            messages.reverse()

        published = 0
        for group in self._group_albums(messages):
            if self.out_of_time or published >= budget:
                break
            head = group[0]
            if self.storage.is_posted(key, head.id):
                self.storage.set_last_id(key, group[-1].id)
                continue
            ok = await self._publish_group(key, group, dry_run=dry_run)
            self.storage.set_last_id(key, group[-1].id)
            if ok:
                published += 1
                if not dry_run and self.cfg.delay_between_posts:
                    await asyncio.sleep(self.cfg.delay_between_posts)
        return published

    @staticmethod
    def _group_albums(messages: Sequence[Message]) -> list[list[Message]]:
        """Сообщения одного альбома приходят по одному — склеиваем обратно."""
        groups: list[list[Message]] = []
        by_group: dict[int, list[Message]] = {}
        for message in messages:
            gid = getattr(message, "grouped_id", None)
            if gid is None:
                groups.append([message])
                continue
            if gid in by_group:
                by_group[gid].append(message)
            else:
                bucket = [message]
                by_group[gid] = bucket
                groups.append(bucket)
        return groups

    # --- фильтры и публикация ----------------------------------------------
    def _should_skip(self, group: list[Message], text: str) -> str | None:
        cfg = self.cfg
        has_media = any(m.media and not isinstance(m.media, MessageMediaWebPage) for m in group)
        plain = " ".join((m.message or "") for m in group)

        if cfg.skip_forwards and any(m.fwd_from for m in group):
            return "репост из другого канала"
        if cfg.skip_media and has_media:
            return "сообщение с медиа"
        if not text and not has_media:
            return "пустое сообщение после очистки"
        if cfg.skip_without_text and not text:
            return "нет текста"
        if cfg.min_text_length and len(plain) < cfg.min_text_length:
            return f"текст короче {cfg.min_text_length} символов"
        lowered = plain.lower()
        if cfg.include_keywords and not any(k.lower() in lowered for k in cfg.include_keywords):
            return "нет ни одного ключевого слова из INCLUDE_KEYWORDS"
        if cfg.exclude_keywords and any(k.lower() in lowered for k in cfg.exclude_keywords):
            return "найдено слово из EXCLUDE_KEYWORDS"
        return None

    async def _publish_group(self, key: str, group: list[Message], *, dry_run: bool) -> bool:
        head = next((m for m in group if m.message), group[0])
        raw_html = message_html(head)
        text = clean_html(raw_html, self.cfg.rules)

        if self.cfg.add_source_link and text:
            link = f"https://t.me/{key}/{head.id}" if not str(key).lstrip("-").isdigit() else ""
            if link:
                text = f'{text}\n\n<a href="{link}">Источник</a>'

        reason = self._should_skip(group, text)
        if reason:
            log.info("Пропуск %s/%s: %s", key, head.id, reason)
            return False

        digest = body_hash(tg_html.parse(text)[0] if text else "")
        if digest and self.storage.seen_hash(digest):
            log.info("Пропуск %s/%s: такой пост уже публиковался", key, head.id)
            self.storage.mark_posted(key, head.id, None, digest)
            return False

        if dry_run:
            print(f"\n--- {key}/{head.id} ---\n{text}\n")
            return True

        try:
            target_id = await self._send(group, text)
        except FloodWaitError as exc:
            log.warning("FloodWait %sс при публикации — ждём следующего запуска", exc.seconds)
            raise
        self.storage.mark_posted(key, head.id, target_id, digest)
        log.info("Опубликовано %s/%s -> %s", key, head.id, target_id)
        return True

    async def _send(self, group: list[Message], text: str) -> int | None:
        if self.cfg.mode == "forward":
            sent = await self.client.forward_messages(self._target_entity, group)
            first = sent[0] if isinstance(sent, list) else sent
            return getattr(first, "id", None)

        media = [m for m in group if m.media and not isinstance(m.media, MessageMediaWebPage)]
        if self.bot_client:
            return await self._send_via_bot(media, text)
        return await self._send_via_user(media, text)

    async def _send_via_user(self, media: list[Message], text: str) -> int | None:
        client, target = self.client, self._target_entity
        if not media:
            return await self._send_text(client, target, text)

        caption, rest = split_caption(text, CAPTION_LIMIT)
        files = [m.media for m in media]
        sent = await client.send_file(
            target,
            files if len(files) > 1 else files[0],
            caption=caption,
            parse_mode="html",
            silent=self.cfg.silent,
        )
        first = sent[0] if isinstance(sent, list) else sent
        if rest:
            await self._send_text(client, target, rest, reply_to=getattr(first, "id", None))
        return getattr(first, "id", None)

    async def _send_via_bot(self, media: list[Message], text: str) -> int | None:
        """Публикация от имени бота: медиа скачиваем юзер-клиентом и заливаем заново."""
        assert self.bot_client is not None
        target = await self.bot_client.get_entity(self.cfg.target)
        if not media:
            return await self._send_text(self.bot_client, target, text)

        with tempfile.TemporaryDirectory(prefix="asdr-") as tmp:
            paths = []
            for message in media:
                path = await self.client.download_media(message, file=str(Path(tmp)))
                if path:
                    paths.append(path)
            if not paths:
                return await self._send_text(self.bot_client, target, text)

            caption, rest = split_caption(text, CAPTION_LIMIT)
            sent = await self.bot_client.send_file(
                target,
                paths if len(paths) > 1 else paths[0],
                caption=caption,
                parse_mode="html",
                silent=self.cfg.silent,
            )
            first = sent[0] if isinstance(sent, list) else sent
            if rest:
                await self._send_text(self.bot_client, target, rest, reply_to=getattr(first, "id", None))
            return getattr(first, "id", None)

    async def _send_text(self, client: TelegramClient, target, text: str, reply_to=None) -> int | None:
        first_id = None
        chunk, rest = split_caption(text, MESSAGE_LIMIT)
        while chunk:
            sent = await client.send_message(
                target,
                chunk,
                parse_mode="html",
                link_preview=False,
                silent=self.cfg.silent,
                reply_to=reply_to,
            )
            first_id = first_id or sent.id
            reply_to = None
            chunk, rest = split_caption(rest, MESSAGE_LIMIT) if rest else ("", "")
        return first_id
