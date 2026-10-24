<?php

namespace ErnestDefoe\Recipes\Api;

use ErnestDefoe\Recipes\Photos\Photos;
use ErnestDefoe\Recipes\Photos\PhotoValidator;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\Post\Exception\FloodingException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/recipes/photos   (multipart, field `photo`)  →  { "url": "…" }
 *
 * For the recipe form: the photo is stored now, and the post that shows it is
 * checked when it is SAVED (CheckImages), because the post does not exist yet.
 * A photo that never makes it into a post is deleted after a day.
 */
class UploadPhotoController implements RequestHandlerInterface
{
    public const PERMISSION = 'ernestdefoe-recipes.uploadPhotos';

    public function __construct(
        private readonly Photos $photos,
        private readonly PhotoValidator $validator,
        private readonly Cache $cache,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(self::PERMISSION);

        // Each upload is a decode and an encode: ten a minute and a hundred a day is plenty for cooking.
        if (! $actor->isAdmin() && (! $this->spend("recipes.photos.$actor->id.m", 10, 60)
            || ! $this->spend("recipes.photos.$actor->id.d", 100, 86400))) {
            throw new FloodingException();
        }

        $file = Arr::get($request->getUploadedFiles(), 'photo');

        if (! $file instanceof UploadedFileInterface) {
            throw new ValidationException(['photo' => $this->translator->trans('validation.required', ['attribute' => 'photo'])]);
        }

        $this->validator->assertImageValid('photo', $file);

        $url = $this->photos->store((string) $file->getStream()->getMetadata('uri'), (int) $actor->id);

        // Tidy up after this member: forums without a scheduler still lose abandoned uploads.
        $this->photos->pruneAbandoned((int) $actor->id, 20);

        return new JsonResponse(['url' => $url], 201);
    }

    /** A window that starts with the first use, so no clock boundary resets it early. */
    private function spend(string $counter, int $limit, int $ttl): bool
    {
        $this->cache->add($counter, 0, $ttl);

        return $this->cache->increment($counter) <= $limit;
    }
}
