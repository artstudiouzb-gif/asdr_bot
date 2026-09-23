<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Response;
use App\Services\Processing\FooterDetector;
use App\Services\Processing\RuleEngine;
use App\Services\Processing\TelegramHtml;

/** Правила очистки текста и их наборы. */
final class RulesController extends Controller
{
    public const TYPES = [
        'SOCIAL_FOOTER' => 'Подпись источника (соцсети)',
        'REMOVE_LINE'   => 'Удалить строку',
        'REMOVE_BLOCK'  => 'Удалить блок',
        'REMOVE_URL'    => 'Удалить ссылки',
        'REGEX_REPLACE' => 'Замена по шаблону',
        'REPLACE_TEXT'  => 'Замена текста',
        'PREPEND_TEXT'  => 'Добавить текст в начало',
        'APPEND_TEXT'   => 'Добавить текст в конец',
        'DROP_MESSAGE'  => 'Не публиковать пост',
    ];

    private const REGEX_TYPES = ['REMOVE_LINE', 'REMOVE_BLOCK', 'REGEX_REPLACE', 'DROP_MESSAGE'];

    public function index(): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        $sets = Db::all(
            'SELECT rs.*, (SELECT COUNT(*) FROM rules r WHERE r.rule_set_id = rs.id) AS rules_count,
                    (SELECT COUNT(*) FROM routes ro WHERE ro.rule_set_id = rs.id) AS routes_count
             FROM rule_sets rs ORDER BY rs.is_default DESC, rs.name'
        );
        $setId = $this->request->int('set') ?: (int)($sets[0]['id'] ?? 0);
        $editId = $this->request->int('edit');
        $edit = $editId > 0 ? Db::one('SELECT * FROM rules WHERE id = ?', [$editId]) : null;
        if ($edit !== null) {
            $setId = (int)($edit['rule_set_id'] ?? $setId);
            $edit['options'] = json_decode((string)($edit['options'] ?? '{}'), true) ?: [];
        }

        return $this->view('rules', [
            'title'   => 'Правила очистки',
            'sets'    => $sets,
            'setId'   => $setId,
            'rules'   => $setId > 0
                ? Db::all('SELECT * FROM rules WHERE rule_set_id = ? ORDER BY priority, id', [$setId])
                : [],
            'global'  => Db::all('SELECT * FROM rules WHERE rule_set_id IS NULL ORDER BY priority, id'),
            'edit'    => $edit,
            'types'   => self::TYPES,
            'defaultDomains' => implode("\n", FooterDetector::DEFAULT_DOMAINS),
            'defaultLabels'  => implode(', ', FooterDetector::DEFAULT_LABELS),
        ]);
    }

    public function save(): Response
    {
        if ($response = $this->guardPost()) {
            return $response;
        }
        $id = $this->request->int('id');
        $setId = $this->request->int('rule_set_id');
        $type = $this->request->input('type');
        $name = $this->request->input('name');
        $pattern = $this->request->raw('pattern');
        $replacement = $this->request->raw('replacement');
        $back = '/rules?set=' . $setId . ($id > 0 ? '&edit=' . $id : '');

        if ($name === '' || !isset(self::TYPES[$type])) {
            return $this->back($back, '', 'Укажите название и тип правила');
        }
        if (in_array($type, self::REGEX_TYPES, true) && trim($pattern) === '') {
            return $this->back($back, '', 'Для этого типа нужен шаблон');
        }
        if (in_array($type, self::REGEX_TYPES, true) && !RuleEngine::isValidPattern($pattern)) {
            return $this->back($back, '', 'Шаблон не является корректным регулярным выражением');
        }
        if (in_array($type, ['PREPEND_TEXT', 'APPEND_TEXT'], true)) {
            if (trim($replacement) === '') {
                return $this->back($back, '', 'Укажите добавляемый текст');
            }
            if ($problems = TelegramHtml::problems($replacement)) {
                return $this->back($back, '', implode('; ', $problems));
            }
        }

        $options = ['case_insensitive' => $this->request->bool('case_insensitive')];
        if ($type === 'SOCIAL_FOOTER') {
            $options['unwrap_links'] = $this->request->bool('unwrap_links');
            $options['domains'] = $this->lines($this->request->raw('domains'));
            $options['labels'] = array_values(array_filter(array_map(
                static fn(string $label): string => mb_strtolower(trim($label)),
                preg_split('/[\s,]+/u', $this->request->raw('labels')) ?: []
            )));
        }

        $data = [
            'rule_set_id' => $setId > 0 ? $setId : null,
            'name'        => mb_substr($name, 0, 128),
            'type'        => $type,
            'pattern'     => $pattern === '' ? null : $pattern,
            'replacement' => $replacement === '' ? null : $replacement,
            'options'     => json_encode($options, JSON_UNESCAPED_UNICODE),
            'priority'    => max(0, min($this->request->int('priority', 100), 9999)),
            'is_active'   => $this->request->bool('is_active') ? 1 : 0,
        ];
        if ($id > 0) {
            Db::update('rules', $data, 'id = :id', ['id' => $id]);
        } else {
            $data['created_at'] = Db::now();
            Db::insert('rules', $data);
        }
        return $this->back('/rules?set=' . $setId, 'Правило сохранено. Проверьте его на странице «Предпросмотр»');
    }

    public function toggle(array $params): Response
    {
        if ($response = $this->guardPost()) {
            return $response;
        }
        $rule = Db::one('SELECT rule_set_id FROM rules WHERE id = ?', [(int)$params['id']]);
        Db::run('UPDATE rules SET is_active = 1 - is_active WHERE id = ?', [(int)$params['id']]);
        return $this->back('/rules?set=' . (int)($rule['rule_set_id'] ?? 0), 'Статус правила изменён');
    }

    public function delete(array $params): Response
    {
        if ($response = $this->guardPost()) {
            return $response;
        }
        $rule = Db::one('SELECT rule_set_id FROM rules WHERE id = ?', [(int)$params['id']]);
        Db::delete('rules', 'id = ?', [(int)$params['id']]);
        return $this->back('/rules?set=' . (int)($rule['rule_set_id'] ?? 0), 'Правило удалено');
    }

    public function saveSet(): Response
    {
        if ($response = $this->guardPost()) {
            return $response;
        }
        $name = $this->request->input('name');
        if ($name === '') {
            return $this->back('/rules', '', 'Укажите название набора');
        }
        if (Db::one('SELECT id FROM rule_sets WHERE name = ?', [$name]) !== null) {
            return $this->back('/rules', '', 'Набор с таким названием уже есть');
        }
        $id = Db::insert('rule_sets', ['name' => mb_substr($name, 0, 128), 'is_default' => 0, 'created_at' => Db::now()]);

        // новый набор начинается с копии правил выбранного — править проще, чем собирать с нуля
        $copyFrom = $this->request->int('copy_from');
        if ($copyFrom > 0) {
            foreach (Db::all('SELECT * FROM rules WHERE rule_set_id = ?', [$copyFrom]) as $rule) {
                unset($rule['id']);
                $rule['rule_set_id'] = $id;
                $rule['created_at'] = Db::now();
                Db::insert('rules', $rule);
            }
        }
        return $this->back('/rules?set=' . $id, 'Набор создан');
    }

    public function makeDefault(array $params): Response
    {
        if ($response = $this->guardPost()) {
            return $response;
        }
        Db::run('UPDATE rule_sets SET is_default = (id = ?)', [(int)$params['id']]);
        return $this->back('/rules?set=' . (int)$params['id'], 'Набор назначен набором по умолчанию');
    }

    public function deleteSet(array $params): Response
    {
        if ($response = $this->guardPost()) {
            return $response;
        }
        $set = Db::one('SELECT * FROM rule_sets WHERE id = ?', [(int)$params['id']]);
        if ($set === null) {
            return $this->back('/rules', '', 'Набор не найден');
        }
        if ((int)$set['is_default'] === 1) {
            return $this->back('/rules?set=' . (int)$set['id'], '',
                'Набор по умолчанию удалить нельзя — сначала назначьте другой');
        }
        Db::delete('rule_sets', 'id = ?', [(int)$set['id']]);   // маршруты с ним перейдут на набор по умолчанию
        return $this->back('/rules', 'Набор удалён; его маршруты используют набор по умолчанию');
    }

    /** @return array<int, string> */
    private function lines(string $text): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn(string $line): string => strtolower(trim($line, " \t\r\n,")),
            preg_split('/[\r\n,]+/', $text) ?: []
        ))));
    }
}
