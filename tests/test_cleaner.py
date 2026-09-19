import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from asdr.cleaner import CleanRules, clean_html, split_caption

FOOTER_HTML = (
    '<a href="https://president.uz/">Prezident.uz</a>|'
    '<a href="https://www.facebook.com/Mirziyoyev/">Facebook</a>|'
    '<a href="https://www.instagram.com/mirziyoyev_sh/">Instagram</a>|'
    '<a href="https://www.youtube.com/channel/UC61Jnumjuz8NXhSuLoZD2xg">YouTube</a>|'
    '<a href="https://x.com/president_uz">X</a>'
)


def test_removes_anchor_footer():
    post = f"Президент принял участие в церемонии.\n\n{FOOTER_HTML}"
    assert clean_html(post) == "Президент принял участие в церемонии."


def test_removes_plaintext_footer():
    post = (
        "Новость дня.\n\n"
        "Prezident.uz (https://president.uz/)|Facebook (https://www.facebook.com/Mirziyoyev/)|"
        "Instagram (https://www.instagram.com/mirziyoyev_sh/)|"
        "YouTube (https://www.youtube.com/channel/UC61Jnumjuz8NXhSuLoZD2xg)|X (https://x.com/president_uz)"
    )
    assert clean_html(post) == "Новость дня."


def test_removes_footer_split_across_lines():
    post = (
        "Текст поста.\n"
        '<a href="https://president.uz/">Prezident.uz</a>\n'
        '<a href="https://x.com/president_uz">X</a>'
    )
    assert clean_html(post) == "Текст поста."


def test_keeps_meaningful_links_in_body():
    post = 'Подробности в <a href="https://gov.uz/news/1">постановлении</a>.'
    assert clean_html(post) == post


def test_keeps_sentence_that_mentions_social_link():
    post = 'Трансляция идёт на <a href="https://youtube.com/live">канале</a> в прямом эфире.'
    cleaned = clean_html(post)
    assert "Трансляция идёт" in cleaned
    assert "в прямом эфире" in cleaned


def test_unwrap_blocked_links_keeps_text_without_href():
    post = 'Смотрите <a href="https://youtube.com/live">здесь</a>.'
    assert clean_html(post) == "Смотрите здесь."
    keep = CleanRules(unwrap_blocked_links=False)
    assert clean_html(post, keep) == post


def test_drops_subscribe_call():
    post = "Новость.\n\nПодписывайтесь на наш канал @somechannel"
    assert clean_html(post) == "Новость."


def test_custom_drop_pattern():
    rules = CleanRules(drop_line_patterns=(r"^\s*реклама",))
    assert clean_html("Текст\nРеклама: купите слона", rules) == "Текст"


def test_custom_remove_pattern_and_footer():
    rules = CleanRules(remove_patterns=(r"\s*erid:\s*\S+",), footer="@my_channel")
    assert clean_html("Новость erid: 12345", rules) == "Новость\n\n@my_channel"


def test_collapses_blank_lines():
    assert clean_html("А\n\n\n\nБ") == "А\n\nБ"


def test_empty_post_stays_empty():
    assert clean_html(FOOTER_HTML) == ""


def test_split_caption_on_line_break():
    head, tail = split_caption("первая строка\nвторая строка", 20)
    assert head == "первая строка"
    assert tail == "вторая строка"


def test_split_caption_short_text_untouched():
    assert split_caption("коротко", 100) == ("коротко", "")
