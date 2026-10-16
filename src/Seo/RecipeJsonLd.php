<?php

namespace ErnestDefoe\Recipes\Seo;

use ErnestDefoe\Recipes\Duration;
use ErnestDefoe\Recipes\Formatter\Blocks;
use ErnestDefoe\Recipes\Formatter\Labels;
use ErnestDefoe\Recipes\Recipe;
use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Flarum\Post\CommentPost;
use Flarum\Post\Post;
use Psr\Http\Message\ServerRequestInterface;

/**
 * schema.org Recipe data for a discussion whose first post holds a recipe.
 *
 * 🚨 Only the Recipe, never the page. FoF SEO already describes the discussion
 * (DiscussionForumPosting) and the trail (BreadcrumbList); Waymark adds its own
 * BreadcrumbList only when FoF SEO is off. Neither says anything about a
 * recipe, so this is the one block on the page that does — a second
 * DiscussionForumPosting or breadcrumb from here would give search engines two
 * answers to one question. And if something else on the page already
 * published a Recipe, we stand down rather than make it two.
 *
 * Only the FIRST post counts: a recipe someone shares in reply 40 is not what
 * the page is about, and search engines expect one main recipe per page.
 *
 * No query on a discussion page whose first post is in the page and has no
 * card; one indexed query otherwise; nothing on any other page.
 */
class RecipeJsonLd
{
    public function __construct(private readonly Labels $labels)
    {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        if ($request->getAttribute('routeName') !== 'discussion') {
            return;
        }

        $parameters = (array) $request->getAttribute('routeParameters');
        $id = (int) explode('-', (string) ($parameters['id'] ?? ''), 2)[0];
        if ($id <= 0) {
            return;
        }

        try {
            $this->write($document, $request, $id);
        } catch (\Throwable) {
            // Structured data is a nicety. It never costs a visitor the page.
        }
    }

    private function write(Document $document, ServerRequestInterface $request, int $id): void
    {
        foreach ($document->head as $tag) {
            if (is_string($tag) && preg_match('/"@type"\s*:\s*"Recipe"/', $tag)) {
                return;
            }
        }

        // The usual page already carries the first post, rendered. No card in it
        // means no recipe, and the discussion page pays nothing at all.
        if ($this->firstPostHasNoCard($document)) {
            return;
        }

        /** @var CommentPost|null $post */
        $post = Post::whereVisibleTo(RequestUtil::getActor($request))
            ->where('discussion_id', $id)
            ->where('number', 1)
            ->where('type', CommentPost::$type)
            ->first();

        if (! $post) {
            return;
        }

        $recipes = Blocks::recipes((string) $post->getParsedContentAttribute());
        if (! $recipes) {
            return;
        }

        $author = $this->authorFromPage($document, (int) $post->user_id) ?? $post->user?->display_name;
        $published = $post->created_at?->toAtomString();

        foreach ($recipes as $recipe) {
            $data = $this->schema($recipe, $author, $published, (string) ($document->title ?? ''));

            // JSON_HEX_TAG: a step that says "</script>" cannot end the block early.
            $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

            if ($json !== false) {
                $document->head[] = '<script type="application/ld+json">'.$json.'</script>';
            }
        }
    }

    private function firstPostHasNoCard(Document $document): bool
    {
        foreach ($this->included($document) as $resource) {
            if (($resource->type ?? null) === 'posts' && (int) ($resource->attributes->number ?? 0) === 1) {
                $html = $resource->attributes->contentHtml ?? null;

                return is_string($html) && ! str_contains($html, 'class="Recipe');
            }
        }

        return false;
    }

    /** @return list<object> */
    private function included(Document $document): array
    {
        $api = $document->payload['apiDocument'] ?? null;
        $included = is_object($api) && isset($api->included) && is_array($api->included) ? $api->included : [];

        return array_values(array_filter($included, 'is_object'));
    }

    /**
     * The author is almost always in the page's own API document already — the
     * discussion's users are included for the post stream. Reading the name
     * from there saves a query on every recipe page; the lookup above is only
     * for a deep link whose page of posts does not carry them.
     */
    private function authorFromPage(Document $document, int $userId): ?string
    {
        foreach ($this->included($document) as $resource) {
            if (($resource->type ?? null) === 'users' && (string) ($resource->id ?? '') === (string) $userId) {
                $name = $resource->attributes->displayName ?? null;

                return is_string($name) && $name !== '' ? $name : null;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function schema(Recipe $recipe, ?string $author, ?string $published, string $fallbackName): array
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Recipe',
            'name' => $recipe->title !== '' ? $recipe->title : $fallbackName,
        ];

        if ($recipe->image) {
            $data['image'] = [$recipe->image];
        }
        if ($recipe->description !== '') {
            $data['description'] = $recipe->description;
        }
        if ($author) {
            $data['author'] = ['@type' => 'Person', 'name' => $author];
        }
        if ($published) {
            $data['datePublished'] = $published;
        }
        if ($recipe->servings !== null) {
            $data['recipeYield'] = (string) $recipe->servings;
        }
        if ($recipe->prep !== null) {
            $data['prepTime'] = Duration::iso($recipe->prep);
        }
        if ($recipe->cook !== null) {
            $data['cookTime'] = Duration::iso($recipe->cook);
        }
        if (($total = $recipe->total()) !== null) {
            $data['totalTime'] = Duration::iso($total);
        }

        $ingredients = [];
        foreach ($recipe->ingredients as $item) {
            if (isset($item['text'])) {
                $ingredients[] = $item['text'];
            }
        }
        if ($ingredients) {
            $data['recipeIngredient'] = $ingredients;
        }

        $steps = [];
        $section = null;
        foreach ($recipe->steps as $item) {
            if (isset($item['heading'])) {
                // "For the sauce:" → a HowToSection, which is how schema.org groups steps.
                $steps[] = ['@type' => 'HowToSection', 'name' => $item['heading'], 'itemListElement' => []];
                $section = array_key_last($steps);
                continue;
            }

            $text = '';
            foreach ($item['parts'] as $part) {
                // The timer reads as the reader sees it on the card: "Bake for 25 min".
                $text .= $part['text'] ?? $this->labels->duration($part['timer']);
            }
            $step = ['@type' => 'HowToStep', 'text' => trim((string) preg_replace('/\s+/', ' ', $text))];

            if ($section !== null) {
                $steps[$section]['itemListElement'][] = $step;
            } else {
                $steps[] = $step;
            }
        }
        if ($steps) {
            $data['recipeInstructions'] = $steps;
        }

        return $data;
    }
}
