<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Response;
use App\Services\Processing\Html;
use App\Services\Processing\TextPipeline;

/** Проверка правил на своём тексте — без публикации в Telegram. */
final class PreviewController extends Controller
{
    public function index(): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        return $this->render('', null, null);
    }

    public function process(): Response
    {
        if ($redirect = $this->requireAuth()) {
            return $redirect;
        }
        if ($response = $this->requireCsrf()) {
            return $response;
        }

        $sample = $this->request->raw('sample');
        $routeId = $this->request->int('route_id');
        $route = $routeId > 0 ? Db::one('SELECT * FROM routes WHERE id = ?', [$routeId]) : null;
        $hasMedia = $this->request->bool('has_media');

        $result = TextPipeline::forRoute($route)->process($sample, $hasMedia, false);

        return $this->render($sample, $result, $routeId);
    }

    private function render(string $sample, ?array $result, ?int $routeId): Response
    {
        return $this->view('preview', [
            'title'    => 'Предпросмотр обработки',
            'sample'   => $sample,
            'result'   => $result,
            'routeId'  => $routeId,
            'plain'    => $result === null ? '' : trim(Html::visible($result['html'])),
            'routes'   => Db::all(
                'SELECT r.id, s.name AS source_name, d.name AS destination_name
                 FROM routes r JOIN sources s ON s.id = r.source_id
                 JOIN destinations d ON d.id = r.destination_id ORDER BY s.name'
            ),
        ]);
    }
}
