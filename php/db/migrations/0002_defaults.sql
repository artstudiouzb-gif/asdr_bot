-- Набор правил, подпись и фильтры по умолчанию.

INSERT INTO rule_sets (`id`, `name`, `is_default`, `created_at`) VALUES (1, 'По умолчанию', 1, UTC_TIMESTAMP())
  ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

INSERT INTO rules (`id`, `rule_set_id`, `name`, `type`, `pattern`, `replacement`, `options`, `priority`, `is_active`, `created_at`)
VALUES
 (1, 1, 'Подпись источника (соцсети)', 'SOCIAL_FOOTER', NULL, NULL, '{"domains": ["president.uz", "prezident.uz", "facebook.com", "fb.com", "fb.me", "instagram.com", "youtube.com", "youtu.be", "x.com", "twitter.com", "threads.net", "tiktok.com", "vk.com", "ok.ru", "linkedin.com", "dzen.ru", "rutube.ru", "t.me", "telegram.me"], "labels": ["prezident", "president", "prezidenti", "facebook", "fb", "instagram", "insta", "ig", "youtube", "yt", "x", "twitter", "telegram", "tg", "tiktok", "threads", "vk", "ok", "linkedin", "dzen", "rutube", "web", "sayt", "sayti", "uz", "com", "ru", "net", "org", "me", "info", "www"], "unwrap_links": true}', 10, 1, UTC_TIMESTAMP()),
 (2, 1, 'Призыв подписаться на канал', 'REMOVE_LINE', '(подпис|подпиш|obuna|subscribe|наш\\s+канал|bizning\\s+kanal|kanalimiz|читайте\\s+нас|follow\\s+us)', NULL, '{"case_insensitive":true}', 20, 1, UTC_TIMESTAMP())
  ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

INSERT INTO signatures (`id`, `name`, `content`, `position`, `separator`, `is_active`, `is_default`, `created_at`)
VALUES (1, 'ASR', '<a href="https://asr.gov.uz/">website</a> | <a href="https://www.facebook.com/ASRUzb">facebook</a> | <a href="https://instagram.com/SDA.Uzbekistan">Instagram</a> | <a href="https://www.linkedin.com/company/asr-uzbekistan">linkedIn</a> | <a href="https://www.youtube.com/channel/UC0W8YrxB4TuQIzmbDt7pzDg">youtube</a>', 'append', '\n\n', 1, 1, UTC_TIMESTAMP())
  ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

INSERT INTO filter_sets (`id`, `name`, `created_at`) VALUES (1, 'По умолчанию', UTC_TIMESTAMP())
  ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);
