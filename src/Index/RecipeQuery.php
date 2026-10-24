<?php

namespace ErnestDefoe\Recipes\Index;

use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Http\SlugManager;
use Flarum\Post\Post;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Recipes a given person may see, as plain arrays for the API and the page.
 *
 * 🚨 Visibility is the posts' own: `Post::whereVisibleTo()` already folds in
 * the discussion's visibility (private discussions, restricted tags, hidden
 * and unapproved posts). The index is never trusted to know who may see what.
 *
 * Four queries for any page size: the rows, then their posts, discussions and
 * authors in one batch each.
 */
class RecipeQuery
{
    public const MAX_LIMIT = 50;
    public const MAX_IDS = 50;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ExtensionManager $extensions,
        private readonly SlugManager $slugs,
    ) {
    }

    /**
     * The directory: newest first, optionally by tag slug and title words.
     *
     * @return array{items: list<array<string, mixed>>, more: bool}
     */
    public function directory(User $actor, int $offset = 0, int $limit = 20, ?string $search = null, ?string $tag = null): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $query = $this->visible($actor);

        $search = trim((string) $search);
        if ($search !== '') {
            /*
             * 🚨 One expression for every database. LIKE is case-SENSITIVE on
             * PostgreSQL ("vegan" missed "Vegan soup" there), and SQLite has no
             * default escape character, so `\%` matched a backslash. `!` as the
             * escape is a single character in every dialect's string literal.
             * The column goes through the grammar, so the table prefix applies.
             */
            $term = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(mb_substr($search, 0, 100)));
            $column = $query->getGrammar()->wrap('recipe_cards.title');
            $query->whereRaw("LOWER($column) LIKE ? ESCAPE '!'", ['%'.$term.'%']);
        }

        if ($tag !== null && $tag !== '') {
            if (! $this->tagsOn() || ! ($tagId = $this->visibleTagId($actor, $tag))) {
                return ['items' => [], 'more' => false];
            }

            $query->whereIn('recipe_cards.post_id', function (Builder $q) use ($tagId) {
                $q->select('posts.id')->from('posts')
                    ->join('discussion_tag', 'discussion_tag.discussion_id', '=', 'posts.discussion_id')
                    ->where('discussion_tag.tag_id', $tagId);
            });
        }

        $rows = $query->orderByDesc('recipe_cards.post_id')->orderBy('recipe_cards.position')
            ->offset(max(0, $offset))->limit($limit + 1)->get()->all();

        $more = count($rows) > $limit;

        return ['items' => $this->present(array_slice($rows, 0, $limit)), 'more' => $more];
    }

    /**
     * For cards on a page: the recipes in these posts' variants, and the
     * recipes these posts are based on — one request answers both.
     *
     * @param list<int> $postIds
     * @return list<array<string, mixed>>
     */
    public function related(User $actor, array $postIds): array
    {
        $ids = array_slice(array_values(array_unique(array_filter($postIds, fn ($id) => $id > 0))), 0, self::MAX_IDS);
        if (! $ids) {
            return [];
        }

        $rows = $this->visible($actor)
            ->where(fn (Builder $q) => $q->whereIn('recipe_cards.post_id', $ids)->orWhereIn('recipe_cards.based_on_post_id', $ids))
            ->orderBy('recipe_cards.post_id')->orderBy('recipe_cards.position')
            ->limit(200)->get()->all();

        return $this->present($rows);
    }

    private function visible(User $actor): Builder
    {
        $posts = Post::whereVisibleTo($actor)->where('posts.type', 'comment')->select('posts.id')->toBase();

        return $this->db->table('recipe_cards')->select('recipe_cards.*')->whereIn('recipe_cards.post_id', $posts);
    }

    /**
     * @param list<object> $rows
     * @return list<array<string, mixed>>
     */
    private function present(array $rows): array
    {
        if (! $rows) {
            return [];
        }

        $posts = Post::query()->whereIn('id', array_map(fn ($r) => (int) $r->post_id, $rows))
            ->with(['discussion', 'user'])->get()->keyBy('id');

        $out = [];
        foreach ($rows as $row) {
            $post = $posts->get((int) $row->post_id);
            $discussion = $post?->discussion;
            if (! $post || ! $discussion) {
                continue;
            }

            $user = $post->user;

            $out[] = [
                'postId' => (int) $row->post_id,
                'position' => (int) $row->position,
                'title' => $row->title !== '' ? $row->title : $discussion->title,
                'image' => $row->image,
                'servings' => $row->servings === null ? null : (int) $row->servings,
                'prep' => $row->prep === null ? null : (int) $row->prep,
                'cook' => $row->cook === null ? null : (int) $row->cook,
                'basedOn' => $row->based_on_post_id === null ? null : (int) $row->based_on_post_id,
                'number' => (int) $post->number,
                'discussion' => $this->slugs->forResource(Discussion::class)->toSlug($discussion),
                'discussionTitle' => $discussion->title,
                'createdAt' => $post->created_at?->toAtomString(),
                'author' => $user ? ['username' => $user->username, 'displayName' => $user->display_name] : null,
            ];
        }

        return $out;
    }

    private function tagsOn(): bool
    {
        return $this->extensions->isEnabled('flarum-tags') && class_exists(\Flarum\Tags\Tag::class);
    }

    private function visibleTagId(User $actor, string $slug): ?int
    {
        $id = \Flarum\Tags\Tag::whereVisibleTo($actor)->where('slug', $slug)->value('id');

        return $id === null ? null : (int) $id;
    }
}
