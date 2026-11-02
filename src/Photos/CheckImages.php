<?php

namespace ErnestDefoe\Recipes\Photos;

use ErnestDefoe\Recipes\Formatter\Blocks;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Saving;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * The server's say on a recipe's image, when a post is SAVED — whoever wrote
 * the text: the form, a hand-written block, an API client.
 *
 * An image already shown by a recipe on this forum is always fine: an edit
 * that keeps its photo, a remix carrying the original's. Otherwise:
 *
 * - one of our uploads must exist and have been uploaded by the person saving,
 *   so nobody can claim a file someone else uploaded and has not used yet;
 * - any other address is refused when the admin has turned web addresses off.
 *
 * Recipes posted before that switch keep their images: nothing is rewritten,
 * and turning it off must not silently break old posts.
 *
 * Costs nothing on a post whose content did not change or has no recipe image.
 */
class CheckImages
{
    public function __construct(
        private readonly Photos $photos,
        private readonly ConnectionInterface $db,
        private readonly SettingsRepositoryInterface $settings,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function handle(Saving $event): void
    {
        $post = $event->post;

        if (! $post instanceof CommentPost || ! $post->isDirty('content')) {
            return;
        }

        $images = [];
        foreach (Blocks::recipes((string) $post->getParsedContentAttribute()) as $recipe) {
            if ($recipe->image !== null) {
                $images[] = $recipe->image;
            }
        }

        if (! $images = array_values(array_unique($images))) {
            return;
        }

        $known = $this->db->table('recipe_cards')->whereIn('image', $images)->pluck('image')->all();
        $new = array_values(array_diff($images, $known));

        $names = $this->photos->namesIn($new);
        if ($names) {
            $owned = $this->db->table(Photos::TABLE)->whereIn('name', $names)->where('user_id', $event->actor->id)->count();
            if ($owned !== count($names)) {
                $this->fail('photo_unavailable');
            }
        }

        if (count($names) < count($new) && ! $this->settings->get(Photos::ALLOW_URLS)) {
            $this->fail('image_urls_off');
        }
    }

    private function fail(string $key): never
    {
        throw new ValidationException(['content' => $this->translator->trans('ernestdefoe-recipes.api.'.$key)]);
    }
}
