<?php

declare(strict_types=1);

namespace App\Services\Processing;

use App\Core\Db;

/** Полная обработка поста для конкретного маршрута: фильтры → правила → подпись. */
final class TextPipeline
{
    /**
     * @param array<int, array<string, mixed>> $rules
     * @param array<int, array<string, mixed>> $filters
     */
    public function __construct(
        private readonly array $rules,
        private readonly array $filters,
        private readonly ?array $signature,
    ) {
    }

    /** Собирает конвейер по настройкам маршрута (NULL → значения по умолчанию). */
    public static function forRoute(?array $route): self
    {
        $ruleSetId = $route['rule_set_id'] ?? null;
        if ($ruleSetId === null) {
            $ruleSetId = Db::value('SELECT id FROM rule_sets WHERE is_default = 1 ORDER BY id LIMIT 1');
        }
        $rules = $ruleSetId === null
            ? Db::all('SELECT * FROM rules WHERE rule_set_id IS NULL AND is_active = 1')
            : Db::all('SELECT * FROM rules WHERE (rule_set_id = ? OR rule_set_id IS NULL) AND is_active = 1',
                [(int)$ruleSetId]);

        $signatureId = $route['signature_id'] ?? null;
        $signature = (int)($route['no_signature'] ?? 0) === 1
            ? null
            : ($signatureId === null
            ? Db::one('SELECT * FROM signatures WHERE is_default = 1 AND is_active = 1 ORDER BY id LIMIT 1')
            : Db::one('SELECT * FROM signatures WHERE id = ?', [(int)$signatureId]));

        $filterSetId = $route['filter_set_id'] ?? null;
        $filters = $filterSetId === null
            ? []
            : Db::all('SELECT * FROM filters WHERE filter_set_id = ? AND is_active = 1', [(int)$filterSetId]);

        return new self($rules, $filters, $signature);
    }

    /**
     * @return array{skip:?string, html:string, applied:array<int, string>}
     */
    public function process(string $sourceHtml, bool $hasMedia = false, bool $isForward = false): array
    {
        $plain = Html::visible(Html::normalize($sourceHtml));

        $rejected = (new FilterEngine($this->filters))->reject($plain, $hasMedia, $isForward);
        if ($rejected !== null) {
            return ['skip' => $rejected, 'html' => '', 'applied' => []];
        }

        $result = (new RuleEngine($this->rules))->apply($sourceHtml);
        if ($result->dropped) {
            return ['skip' => $result->dropReason ?? 'отсеяно правилом', 'html' => '', 'applied' => $result->applied];
        }

        $html = SignatureBuilder::apply($result->html, $this->signature);
        if (trim(Html::visible($html)) === '' && !$hasMedia) {
            return ['skip' => 'после очистки не осталось текста', 'html' => '', 'applied' => $result->applied];
        }

        return ['skip' => null, 'html' => $html, 'applied' => $result->applied];
    }
}
