<?php

namespace ErnestDefoe\Recipes\Http;

use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Flarum\Http\SlugManager;
use Flarum\Http\UrlGenerator;
use Flarum\Post\Post;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /recipes/from/{id} — where a variant's "Based on" link goes.
 *
 * The variant stores only the original's post id, so the link stays right when
 * the original is moved, renamed or edited. Resolved here, as the visitor: an
 * original they may not see is a 404, never a redirect that names it.
 */
class BasedOnRedirect implements RequestHandlerInterface
{
    public function __construct(private readonly UrlGenerator $url, private readonly SlugManager $slugs)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $route = (array) $request->getAttribute('routeParameters');
        $id = (int) ($route['id'] ?? $request->getQueryParams()['id'] ?? 0);

        $post = $id > 0 ? Post::whereVisibleTo(RequestUtil::getActor($request))->with('discussion')->find($id) : null;

        if (! $post || ! $post->discussion) {
            return new HtmlResponse('Not found', 404);
        }

        return new RedirectResponse($this->url->to('forum')->route('discussion', [
            'id' => $this->slugs->forResource(Discussion::class)->toSlug($post->discussion),
            'near' => $post->number,
        ]));
    }
}
