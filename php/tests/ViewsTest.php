<?php

declare(strict_types=1);

namespace Tests;

use App\Controllers\FiltersController;
use App\Controllers\RulesController;
use App\Controllers\SignaturesController;
use App\Core\View;
use App\Services\Processing\FooterDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Каждая страница панели рендерится с правдоподобными данными — в пустом и
 * заполненном состоянии, с формой редактирования. Опечатка в имени переменной
 * или ключа превращается здесь в исключение, а не в поломку на хостинге.
 */
final class ViewsTest extends TestCase
{
    private const NOW = '2026-09-23 08:15:00';

    private static function layout(): array
    {
        return [
            'title' => 'Страница', 'user' => ['username' => 'admin'], 'csrf' => '<input type="hidden" name="csrf" value="x">',
            'activePath' => '/', 'flash' => 'Сохранено', 'flashError' => '',
        ];
    }

    private static function account(): array
    {
        return ['id' => 1, 'label' => 'Читатель', 'username' => 'reader', 'last_error' => null,
                'phone' => '+998901234567', 'status' => 'active', 'last_check_at' => self::NOW];
    }

    private static function publication(string $status): array
    {
        return ['id' => 7, 'created_at' => self::NOW, 'published_at' => $status === 'PUBLISHED' ? self::NOW : null,
                'source_name' => 'Пресс-служба', 'source_peer' => 'shmirziyoyev', 'source_message_id' => 35508,
                'media_kind' => 'album', 'destination_name' => 'ASR', 'dest_message_id' => 120, 'status' => $status,
                'attempts' => 2, 'skip_reason' => $status === 'SKIPPED' ? 'стоп-слово' : null,
                'last_error' => $status === 'ERROR' ? 'FLOOD_WAIT' : null];
    }

    public static function pages(): array
    {
        $route = ['id' => 3, 'source_id' => 1, 'destination_id' => 2, 'source_name' => 'Пресс-служба',
                  'source_peer' => 'shmirziyoyev', 'destination_name' => 'ASR', 'destination_peer' => 'asr_uz',
                  'delay_seconds' => 60, 'rule_set_id' => 1, 'rule_set_name' => 'По умолчанию', 'signature_id' => null,
                  'signature_name' => null, 'no_signature' => 1, 'filter_set_id' => null, 'filter_set_name' => null,
                  'media_mode' => 'all', 'is_active' => 1];
        $source = ['id' => 1, 'name' => 'Пресс-служба', 'last_error' => 'Канал приватный', 'tg_identifier' => 'shmirziyoyev',
                   'tg_peer_id' => -1001234567890, 'last_message_id' => 35508, 'account_label' => null,
                   'routes_count' => 1, 'is_active' => 1, 'account_id' => null, 'fetch_limit' => 20];
        $destination = ['id' => 2, 'name' => 'ASR', 'last_error' => null, 'tg_identifier' => 'asr_uz',
                        'tg_peer_id' => null, 'publish_as' => 'user', 'account_label' => 'Читатель', 'account_id' => 1,
                        'rate_limit_per_min' => 15, 'is_active' => 0, 'routes_count' => 1];
        $rule = ['id' => 1, 'rule_set_id' => 1, 'priority' => 10, 'name' => 'Подпись источника', 'type' => 'SOCIAL_FOOTER',
                 'pattern' => null, 'replacement' => null, 'is_active' => 1,
                 'options' => ['domains' => ['president.uz'], 'labels' => ['facebook'], 'unwrap_links' => true]];
        $signature = ['id' => 1, 'name' => 'ASR', 'is_default' => 1, 'routes_count' => 2, 'plain' => 'website | facebook',
                      'content' => '<a href="https://asr.gov.uz/">website</a>', 'position' => 'append',
                      'separator' => "\n\n", 'is_active' => 1];

        return [
            'обзор, пусто' => ['dashboard', [
                'stats' => array_fill_keys(['sourcesActive', 'sources', 'routes', 'messages', 'published', 'skipped', 'queue', 'errors'], 0),
                'lastRun' => null, 'cronAgeMinutes' => null, 'recent' => [], 'problems' => []]],
            'обзор, данные' => ['dashboard', [
                'stats' => array_fill_keys(['sourcesActive', 'sources', 'routes', 'messages', 'published', 'skipped', 'queue', 'errors'], 3),
                'lastRun' => ['started_at' => self::NOW, 'ingested' => 2, 'published' => 1, 'skipped' => 1, 'errors' => 0, 'duration_ms' => 4200],
                'cronAgeMinutes' => 12,
                'recent' => [self::publication('PUBLISHED'), self::publication('ERROR')],
                'problems' => [['created_at' => self::NOW, 'level' => 'error', 'component' => 'publish', 'message' => 'сбой']]]],
            'аккаунты' => ['accounts', [
                'apiReady' => false,
                'accounts' => [self::account(), ['status' => 'awaiting_code'] + self::account(), ['status' => 'awaiting_password'] + self::account()],
                'commands' => [['created_at' => self::NOW, 'command' => 'login_start', 'status' => 'done', 'result' => 'Код отправлен']]]],
            'источники' => ['sources', ['sources' => [$source], 'accounts' => [self::account()], 'edit' => $source]],
            'источники, пусто' => ['sources', ['sources' => [], 'accounts' => [], 'edit' => null]],
            'назначения' => ['destinations', ['destinations' => [$destination], 'accounts' => [self::account()], 'edit' => $destination]],
            'маршруты' => ['routes', [
                'routes' => [$route], 'sources' => [['id' => 1, 'name' => 'Пресс-служба']],
                'destinations' => [['id' => 2, 'name' => 'ASR']], 'ruleSets' => [['id' => 1, 'name' => 'По умолчанию']],
                'signatures' => [['id' => 1, 'name' => 'ASR']], 'filterSets' => [], 'edit' => $route]],
            'предпросмотр, до' => ['preview', ['sample' => '', 'result' => null, 'routeId' => null, 'plain' => '', 'routes' => []]],
            'предпросмотр, после' => ['preview', [
                'sample' => 'Текст', 'routeId' => 3, 'plain' => 'Текст',
                'result' => ['skip' => null, 'html' => 'Текст', 'applied' => ['Подпись источника']],
                'routes' => [['id' => 3, 'source_name' => 'Пресс-служба', 'destination_name' => 'ASR']]]],
            'журнал' => ['logs', [
                'rows' => [self::publication('PUBLISHED'), self::publication('SKIPPED'), self::publication('ERROR')],
                'sources' => [['id' => 1, 'name' => 'Пресс-служба']], 'destinations' => [['id' => 2, 'name' => 'ASR']],
                'filters' => ['status' => 'ERROR', 'source_id' => 1, 'destination_id' => 0, 'from' => '2026-09-01', 'to' => '', 'message_id' => 0],
                'page' => 2, 'pages' => 3, 'total' => 120,
                'events' => [['created_at' => self::NOW, 'level' => 'warning', 'component' => 'publish', 'message' => 'FLOOD_WAIT']]]],
            'правила' => ['rules', [
                'sets' => [['id' => 1, 'name' => 'По умолчанию', 'is_default' => 1, 'rules_count' => 2, 'routes_count' => 1],
                           ['id' => 2, 'name' => 'Второй', 'is_default' => 0, 'rules_count' => 0, 'routes_count' => 0]],
                'setId' => 1, 'rules' => [$rule, ['id' => 2, 'type' => 'REMOVE_LINE', 'pattern' => '^Фото:'] + $rule],
                'global' => [$rule], 'edit' => $rule, 'types' => RulesController::TYPES,
                'defaultDomains' => implode("\n", FooterDetector::DEFAULT_DOMAINS),
                'defaultLabels' => implode(', ', FooterDetector::DEFAULT_LABELS)]],
            'правила, неосновной набор' => ['rules', [
                'sets' => [['id' => 2, 'name' => 'Второй', 'is_default' => 0, 'rules_count' => 0, 'routes_count' => 0]],
                'setId' => 2, 'rules' => [], 'global' => [], 'edit' => null, 'types' => RulesController::TYPES,
                'defaultDomains' => '', 'defaultLabels' => '']],
            'подписи' => ['signatures', ['signatures' => [$signature], 'edit' => $signature,
                                         'separators' => SignaturesController::SEPARATORS]],
            'фильтры' => ['filters', [
                'sets' => [['id' => 1, 'name' => 'Экономика', 'filters_count' => 1, 'routes_count' => 0]], 'setId' => 1,
                'filters' => [['id' => 1, 'kind' => 'include_keyword', 'value' => 'экономика', 'case_insensitive' => 1, 'is_active' => 1]],
                'kinds' => FiltersController::KINDS]],
            'настройки' => ['settings', [
                'settings' => ['retry_max' => '4', 'backfill_on_first_run' => '0', 'publish_batch' => '20'],
                'cronLine' => '/usr/bin/php /home/u/bin/cron.php', 'cronUrl' => 'https://bot.asdr.uz/cron.php?key=k',
                'timezone' => 'Asia/Tashkent', 'apiReady' => true,
                'lastRun' => ['started_at' => self::NOW, 'status' => 'ok'],
                'sessions' => [['id' => 'abc', 'ip' => '1.2.3.4', 'user_agent' => 'Firefox', 'created_at' => self::NOW, 'last_seen_at' => self::NOW],
                               ['id' => 'def', 'ip' => null, 'user_agent' => null, 'created_at' => self::NOW, 'last_seen_at' => self::NOW]],
                'currentSession' => 'abc']],
        ];
    }

    #[DataProvider('pages')]
    public function testPageRenders(string $template, array $data): void
    {
        $html = View::render($template, $data + self::layout());
        self::assertStringContainsString('</html>', $html);
        self::assertStringNotContainsString('Warning:', $html);
        self::assertStringNotContainsString('Notice:', $html);
    }

    public function testUserTextIsEscaped(): void
    {
        $html = View::render('sources', [
            'sources' => [['id' => 1, 'name' => '<script>alert(1)</script>', 'last_error' => null, 'tg_identifier' => 'x',
                           'tg_peer_id' => null, 'last_message_id' => 0, 'account_label' => null, 'routes_count' => 0, 'is_active' => 1]],
            'accounts' => [], 'edit' => null,
        ] + self::layout());
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }
}
