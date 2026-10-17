<?php

namespace ErnestDefoe\Recipes\Index;

use ErnestDefoe\Recipes\Formatter\Blocks;
use Illuminate\Database\ConnectionInterface;

/**
 * Keeps `recipe_cards` in step with the posts that hold recipes.
 *
 * A post's rows are replaced wholesale whenever its content is saved: the
 * recipes in it are read by the same Blocks/Recipe code that renders them, so
 * the directory can never list a title the card itself would not show.
 *
 * Through the query builder throughout, so the table prefix is applied.
 */
final class Indexer
{
    public static function sync(ConnectionInterface $db, int $postId, ?string $xml): void
    {
        $rows = [];

        foreach ($xml !== null ? Blocks::recipes($xml) : [] as $position => $recipe) {
            $rows[] = [
                'post_id' => $postId,
                'position' => $position,
                'title' => mb_substr($recipe->title, 0, 255),
                'image' => $recipe->image,
                'servings' => $recipe->servings,
                'prep' => $recipe->prep,
                'cook' => $recipe->cook,
                // A variant of itself is not a variant.
                'based_on_post_id' => $recipe->basedOn !== $postId ? $recipe->basedOn : null,
            ];
        }

        $db->transaction(function () use ($db, $postId, $rows) {
            $db->table('recipe_cards')->where('post_id', $postId)->delete();

            if ($rows) {
                $db->table('recipe_cards')->insert($rows);
            }
        });
    }

    /** Every recipe posted before the index existed. */
    public static function backfill(ConnectionInterface $db): int
    {
        $count = 0;

        $db->table('posts')
            ->select(['id', 'content'])
            ->where('type', 'comment')
            ->where('content', 'like', '%<RECIPE%')
            ->orderBy('id')
            ->chunkById(200, function ($posts) use ($db, &$count) {
                foreach ($posts as $post) {
                    self::sync($db, (int) $post->id, (string) $post->content);
                    $count++;
                }
            });

        return $count;
    }
}
