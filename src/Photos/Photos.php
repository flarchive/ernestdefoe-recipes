<?php

namespace ErnestDefoe\Recipes\Photos;

use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Database\ConnectionInterface;
use Intervention\Image\ImageManager;
use RuntimeException;

/**
 * Recipe photos members upload, on the extension's own disk
 * (public/assets/recipes unless a storage driver says otherwise).
 *
 * Every photo is decoded and RE-ENCODED as WebP, which drops whatever the
 * original carried besides pixels: a phone photo's location, its camera serial,
 * a polyglot payload hiding behind a valid header. The name is random and ours,
 * never the uploader's.
 *
 * A post refers to a photo by its URL, like any other recipe image, so the
 * card, the directory and the JSON-LD need nothing new. `recipe_photos` records
 * who uploaded which file; `recipe_cards` (the index) says whether any recipe
 * still shows it; `post_id` is the post that brought it in. A photo no recipe
 * shows is deleted (prune()).
 */
class Photos
{
    public const DISK = 'ernestdefoe-recipes';

    public const TABLE = 'recipe_photos';

    /** Whether a recipe may show an image from any web address (on by default). */
    public const ALLOW_URLS = 'ernestdefoe-recipes.image_urls';

    /** Wide enough for a card on a retina screen; nothing a recipe needs is bigger. */
    private const MAX_WIDTH = 1600;

    private const MAX_HEIGHT = 1600;

    /** An unattached upload gets this long to end up in a saved post. */
    public const GRACE_HOURS = 24;

    private Cloud $disk;

    public function __construct(Factory $filesystems, private readonly ImageManager $images, private readonly ConnectionInterface $db)
    {
        $this->disk = $filesystems->disk(self::DISK);
    }

    /** Re-encode an uploaded file and record it; returns the photo's public URL. */
    public function store(string $path, int $userId): string
    {
        $image = $this->images->read($path);
        $image->scaleDown(width: self::MAX_WIDTH, height: self::MAX_HEIGHT);
        $encoded = (string) $image->toWebp(quality: 82, strip: true);

        if ($encoded === '') {
            throw new RuntimeException('The image could not be encoded.');
        }

        $name = bin2hex(random_bytes(16)).'.webp';
        $this->disk->put($name, $encoded);

        try {
            $this->db->table(self::TABLE)->insert(['name' => $name, 'user_id' => $userId, 'created_at' => Carbon::now()]);
        } catch (\Throwable $e) {
            $this->disk->delete($name);

            throw $e;
        }

        return $this->disk->url($name);
    }

    /**
     * The file name, when a URL points at a photo on this disk; null for any
     * other address. Matched on the disk's own URL prefix and our name shape,
     * so `../`, a query string or a look-alike host never count as ours.
     */
    public function nameOf(string $url): ?string
    {
        $prefix = substr($this->disk->url('x.webp'), 0, -strlen('x.webp'));

        if (! str_starts_with($url, $prefix)) {
            return null;
        }

        $name = substr($url, strlen($prefix));

        return preg_match('/^[0-9a-f]{32}\.webp$/D', $name) ? $name : null;
    }

    /**
     * The names of this disk's photos among a post's image URLs.
     *
     * @param iterable<?string> $urls
     * @return list<string>
     */
    public function namesIn(iterable $urls): array
    {
        $names = [];
        foreach ($urls as $url) {
            if ($url !== null && ($name = $this->nameOf($url)) !== null) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * A saved post now shows these photos. Only unclaimed uploads are claimed:
     * a variant showing the original's photo leaves it with the original.
     *
     * @param list<string> $names
     */
    public function attach(int $postId, array $names): void
    {
        if ($names) {
            $this->db->table(self::TABLE)->whereIn('name', $names)->whereNull('post_id')->update(['post_id' => $postId]);
        }
    }

    /**
     * A post was edited or deleted: its photos it no longer shows are pruned.
     *
     * @param list<string> $keep the photos the post still shows
     */
    public function release(int $postId, array $keep = []): void
    {
        $names = $this->db->table(self::TABLE)->where('post_id', $postId)->pluck('name')->all();

        if ($names = array_values(array_diff($names, $keep))) {
            $this->prune($names);
        }
    }

    /**
     * Delete each photo no recipe card shows any more; one still shown by
     * another post (a variant) is handed to that post instead.
     *
     * By file name rather than full URL: a forum that moved to a new address
     * still has the old one in its posts, and a URL comparison would call every
     * one of those photos unused.
     *
     * @param list<string> $names
     */
    public function prune(array $names): int
    {
        $deleted = 0;

        foreach (array_unique($names) as $name) {
            $holder = $this->db->table('recipe_cards')->where('image', 'like', '%/'.$name)->value('post_id');

            if ($holder !== null) {
                $this->db->table(self::TABLE)->where('name', $name)->update(['post_id' => $holder]);
                continue;
            }

            $this->disk->delete($name);
            $this->db->table(self::TABLE)->where('name', $name)->delete();
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Uploads past their grace period that never made it into a saved post (a
     * form closed, a draft abandoned). Bounded per run; the schedule comes back.
     *
     * @param int|null $userId only this member's (the upload endpoint tidies up after its caller)
     */
    public function pruneAbandoned(?int $userId = null, int $limit = 200): int
    {
        $names = $this->db->table(self::TABLE)
            ->whereNull('post_id')
            ->where('created_at', '<', Carbon::now()->subHours(self::GRACE_HOURS))
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('name')
            ->all();

        return $names ? $this->prune($names) : 0;
    }
}
