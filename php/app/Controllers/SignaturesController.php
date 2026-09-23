<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Response;
use App\Services\Processing\TelegramHtml;

/** Свои подписи под постами. */
final class SignaturesController extends Controller
{
    public const SEPARATORS = [
        "\n\n"         => 'пустая строка',
        "\n"           => 'с новой строки',
        "\n\n———\n\n"  => 'линия ———',
        ' '            => 'в той же строке',
    ];

    public function index(): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        $editId = $this->request->int('edit');
        $signatures = Db::all(
            'SELECT s.*, (SELECT COUNT(*) FROM routes r WHERE r.signature_id = s.id) AS routes_count
             FROM signatures s ORDER BY s.is_default DESC, s.name'
        );
        foreach ($signatures as &$signature) {
            $signature['plain'] = TelegramHtml::plain((string)$signature['content']);
        }
        unset($signature);

        return $this->view('signatures', [
            'title'      => 'Подписи',
            'signatures' => $signatures,
            'edit'       => $editId > 0 ? Db::one('SELECT * FROM signatures WHERE id = ?', [$editId]) : null,
            'separators' => self::SEPARATORS,
        ]);
    }

    public function save(): Response
    {
        if ($response = $this->guardPost()) {
            return $response;
        }
        $id = $this->request->int('id');
        $name = $this->request->input('name');
        $content = trim(str_replace("\r\n", "\n", $this->request->raw('content')));
        $back = '/signatures' . ($id > 0 ? '?edit=' . $id : '');

        if ($name === '' || $content === '') {
            return $this->back($back, '', 'Укажите название и текст подписи');
        }
        if ($problems = TelegramHtml::problems($content)) {
            return $this->back($back, '', implode('; ', $problems));
        }
        $separator = (string)($this->request->post['separator'] ?? "\n\n");
        if (!array_key_exists($separator, self::SEPARATORS)) {
            $separator = "\n\n";
        }

        $data = [
            'name'       => mb_substr($name, 0, 128),
            'content'    => $content,
            'position'   => $this->request->input('position') === 'prepend' ? 'prepend' : 'append',
            'separator'  => $separator,
            'is_active'  => $this->request->bool('is_active') ? 1 : 0,
            'is_default' => $this->request->bool('is_default') ? 1 : 0,
        ];
        if ($id > 0) {
            Db::update('signatures', $data, 'id = :id', ['id' => $id]);
        } else {
            $data['created_at'] = Db::now();
            $id = Db::insert('signatures', $data);
        }
        if ($data['is_default'] === 1) {
            Db::run('UPDATE signatures SET is_default = 0 WHERE id <> ?', [$id]);   // по умолчанию — только одна
        }
        return $this->back('/signatures', 'Подпись сохранена');
    }

    public function toggle(array $params): Response
    {
        if ($response = $this->guardPost()) {
            return $response;
        }
        Db::run('UPDATE signatures SET is_active = 1 - is_active WHERE id = ?', [(int)$params['id']]);
        return $this->back('/signatures', 'Статус подписи изменён');
    }

    public function delete(array $params): Response
    {
        if ($response = $this->guardPost()) {
            return $response;
        }
        Db::delete('signatures', 'id = ?', [(int)$params['id']]);
        return $this->back('/signatures', 'Подпись удалена; её маршруты используют подпись по умолчанию');
    }
}
