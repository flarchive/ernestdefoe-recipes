<?php

namespace ErnestDefoe\Recipes\Api;

use ErnestDefoe\Recipes\Index\RecipeQuery;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/recipes
 *
 *   ?filter[q]=soup&filter[tag]=baking&page[offset]=20&page[limit]=20   the directory
 *   ?filter[related]=12,15                                                variants of, and originals of, posts on a page
 *
 * Read-only and visibility-scoped (RecipeQuery), so there is nothing here a
 * visitor could change and nothing they could not already open.
 */
class ListRecipesController implements RequestHandlerInterface
{
    public function __construct(private readonly RecipeQuery $recipes)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $params = $request->getQueryParams();
        $filter = is_array($params['filter'] ?? null) ? $params['filter'] : [];
        $page = is_array($params['page'] ?? null) ? $params['page'] : [];

        if (isset($filter['related'])) {
            $ids = array_map('intval', explode(',', (string) $filter['related']));

            return new JsonResponse(['data' => $this->recipes->related($actor, $ids)]);
        }

        $result = $this->recipes->directory(
            $actor,
            (int) ($page['offset'] ?? 0),
            (int) ($page['limit'] ?? 20),
            is_string($filter['q'] ?? null) ? $filter['q'] : null,
            is_string($filter['tag'] ?? null) ? $filter['tag'] : null,
        );

        return new JsonResponse(['data' => $result['items'], 'more' => $result['more']]);
    }
}
