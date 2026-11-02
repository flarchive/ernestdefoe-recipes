<?php

namespace ErnestDefoe\Recipes\Http;

use ErnestDefoe\Recipes\Index\RecipeQuery;
use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The /recipes page as the server sends it: a title, and the newest recipes
 * as plain links, so search engines and readers without JavaScript get the
 * directory too. The browser replaces it with forum/components/RecipesPage.
 */
class RecipesPageContent
{
    public function __construct(
        private readonly RecipeQuery $recipes,
        private readonly UrlGenerator $url,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): Document
    {
        $title = (string) $this->translator->trans('ernestdefoe-recipes.forum.directory.title');
        $document->title = $title;
        $document->canonicalUrl = $this->url->to('forum')->route('recipes');

        $items = $this->recipes->directory(RequestUtil::getActor($request), 0, 50)['items'];

        $html = '<div class="container"><h1>'.e($title).'</h1><ul>';
        foreach ($items as $item) {
            $href = $this->url->to('forum')->route('discussion', ['id' => $item['discussion'], 'near' => $item['number']]);
            $html .= '<li><a href="'.e($href).'">'.e($item['title']).'</a></li>';
        }
        $document->content = $html.'</ul></div>';

        return $document;
    }
}
