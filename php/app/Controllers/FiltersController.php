<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Response;
use App\Services\Processing\RuleEngine;

/** Наборы фильтров: какие посты публиковать, а какие пропускать. */
final class FiltersController extends Controller
{
    public const KINDS = [
        'include_keyword' => 'Публиковать, только если есть слово',
        'exclude_keyword' => 'Не публиковать, если есть слово',
        'include_regex'   => 'Публиковать, только если совпал шаблон',
        'exclude_regex'   => 'Не публиковать, если совпал шаблон',
        'min_length'      => 'Минимальная длина текста',
        'max_length'      => 'Максимальная длина текста',
        'require_media'   => 'Только посты с медиа',
        'skip_media'      => 'Пропускать посты с медиа',
        'skip_forwards'   => 'Пропускать пересланные посты',
    ];

    private const NEEDS_VALUE = ['include_keyword', 'exclude_keyword', 'include_regex', 'exclude_regex',
                                 'min_length', 'max_length'];

    public function index(): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        $sets = Db::all(
            'SELECT fs.*, (SELECT COUNT(*) FROM filters f WHERE f.filter_set_id = fs.id) AS filters_count,
                    (SELECT COUNT(*) FROM routes r WHERE r.filter_set_id = fs.id) AS routes_count
             FROM filter_sets fs ORDER BY fs.name'
        );
        $setId = $this->request->int('set') ?: (int)($sets[0]['id'] ?? 0);
        return $this->view('filters', [
            'title'   => 'Фильтры',
            'sets'    => $sets,
            'setId'   => $setId,
            'filters' => $setId > 0 ? Db::all('SELECT * FROM filters WHERE filter_set_id = ? ORDER BY kind, id', [$setId]) : [],
            'kinds'   => self::KINDS,
        ]);
    }

    public function save(): Response
    {
        if ($response = $this->guardPost()) {
            return $response;
        }
        $setId = $this->request->int('filter_set_id');
        $kind = $this->request->input('kind');
        $back = '/filters?set=' . $setId;

        if ($setId <= 0 || !isset(self::KINDS[$kind])) {
            return $this->back($back, '', 'Выберите набор и тип фильтра');
        }

        // «экономика, инвестиции, реформы» — сразу несколько слов одним вводом
        $values = [''];
        if (in_array($kind, self::NEEDS_VALUE, true)) {
            $raw = $this->request->raw('value');
            $values = in_array($kind, ['include_keyword', 'exclude_keyword'], true)
                ? array_values(array_filter(array_map('trim', explode(',', $raw))))
                : [trim($raw)];
            if ($values === [] || $values === ['']) {
                return $this->back($back, '', 'Укажите значение фильтра');
            }
        }
        foreach ($values as $value) {
            if (in_array($kind, ['include_regex', 'exclude_regex'], true) && !RuleEngine::isValidPattern($value)) {
                return $this->back($back, '', 'Шаблон не является корректным регулярным выражением');
            }
            if (in_array($kind, ['min_length', 'max_length'], true) && !ctype_digit($value)) {
                return $this->back($back, '', 'Длина — целое число символов');
            }
        }

        foreach ($values as $value) {
            Db::insert('filters', [
                'filter_set_id'    => $setId,
                'kind'             => $kind,
                'value'            => $value === '' ? null : mb_substr($value, 0, 512),
                'case_insensitive' => $this->request->bool('case_insensitive') ? 1 : 0,
                'is_active'        => 1,
            ]);
        }
        return $this->back($back, count($values) > 1 ? 'Добавлено фильтров: ' . count($values) : 'Фильтр добавлен');
    }

    public function toggle(array $params): Response
    {
        if ($response = $this->guardPost()) {
            return $response;
        }
        $filter = Db::one('SELECT filter_set_id FROM filters WHERE id = ?', [(int)$params['id']]);
        Db::run('UPDATE filters SET is_active = 1 - is_active WHERE id = ?', [(int)$params['id']]);
        return $this->back('/filters?set=' . (int)($filter['filter_set_id'] ?? 0), 'Статус фильтра изменён');
    }

    public function delete(array $params): Response
    {
        if ($response = $this->guardPost()) {
            return $response;
        }
        $filter = Db::one('SELECT filter_set_id FROM filters WHERE id = ?', [(int)$params['id']]);
        Db::delete('filters', 'id = ?', [(int)$params['id']]);
        return $this->back('/filters?set=' . (int)($filter['filter_set_id'] ?? 0), 'Фильтр удалён');
    }

    public function saveSet(): Response
    {
        if ($response = $this->guardPost()) {
            return $response;
        }
        $name = $this->request->input('name');
        if ($name === '') {
            return $this->back('/filters', '', 'Укажите название набора');
        }
        $id = Db::insert('filter_sets', ['name' => mb_substr($name, 0, 128), 'created_at' => Db::now()]);
        return $this->back('/filters?set=' . $id, 'Набор создан. Назначьте его маршруту на странице «Маршруты»');
    }

    public function deleteSet(array $params): Response
    {
        if ($response = $this->guardPost()) {
            return $response;
        }
        Db::delete('filter_sets', 'id = ?', [(int)$params['id']]);
        return $this->back('/filters', 'Набор удалён; его маршруты публикуют без фильтров');
    }
}
