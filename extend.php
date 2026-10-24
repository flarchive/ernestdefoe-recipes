<?php

/*
 * Recipes — structured recipe blocks for Flarum posts.
 *
 * The SERVER marks a recipe in the post and, at render time, reads it into a
 * card (Formatter\Render) and into schema.org data (Seo\RecipeJsonLd). The
 * BROWSER only adds what needs a reader: the servings stepper, timers and the
 * print view. Every editor writes the same text — see src/Recipe.php.
 */

use ErnestDefoe\Recipes\Api\ListRecipesController;
use ErnestDefoe\Recipes\Api\UploadPhotoController;
use ErnestDefoe\Recipes\Console\PrunePhotosCommand;
use ErnestDefoe\Recipes\Console\ReindexCommand;
use ErnestDefoe\Recipes\Formatter\Configure;
use ErnestDefoe\Recipes\Http\BasedOnRedirect;
use ErnestDefoe\Recipes\Http\RecipesPageContent;
use ErnestDefoe\Recipes\Index\KeepIndexCurrent;
use ErnestDefoe\Recipes\Formatter\Render;
use ErnestDefoe\Recipes\Fields;
use ErnestDefoe\Recipes\Photos\CheckImages;
use ErnestDefoe\Recipes\Photos\Photos;
use ErnestDefoe\Recipes\Seo\RecipeJsonLd;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Post\Event\Saving;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        /*
         * 🚨 Not optional. The composer form, the editor node and the preview
         * are dynamic imports, so a reader who never posts a recipe pays only
         * for the card. Flarum does not publish an extension's chunk files
         * unless their directory is declared; without this the chunks 404 and
         * the Recipe button silently does nothing.
         */
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->content(RecipeJsonLd::class)
        ->route('/recipes', 'recipes', RecipesPageContent::class),

    (new Extend\Routes('forum'))
        ->get('/recipes/from/{id:\d+}', 'recipes.from', BasedOnRedirect::class),

    (new Extend\Routes('api'))
        ->get('/recipes', 'recipes.index', ListRecipesController::class)
        ->post('/recipes/photos', 'recipes.photos.upload', UploadPhotoController::class),

    (new Extend\Event())
        ->subscribe(KeepIndexCurrent::class)
        ->listen(Saving::class, CheckImages::class),

    (new Extend\Console())
        ->command(ReindexCommand::class)
        ->command(PrunePhotosCommand::class)
        ->schedule(PrunePhotosCommand::class, function (ScheduledEvent $event): void {
            $event->daily();
        }),

    /*
     * Uploaded photos (Photos\Photos): re-encoded WebP with random names, on
     * a disk of their own so a storage driver can move them like any other.
     */
    (new Extend\Filesystem())
        ->disk(Photos::DISK, Photos::config(...)),

    // Whether this visitor may upload: the form's drop zone. Permissions are already loaded, so no query.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('canUploadRecipePhotos')
                ->get(fn ($forum, Context $context) => $context->getActor()->can(UploadPhotoController::PERMISSION)),
        ]),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    /*
     * The admin's fields, for the recipe form. Normalised on the way out
     * (Fields::normalize), so the browser only ever sees valid definitions,
     * and small: at most 20 short entries, on every page.
     */
    (new Extend\Settings())
        ->default(Fields::SETTING, '[]')
        ->default(Photos::ALLOW_URLS, true)
        ->serializeToForum('recipesImageUrls', Photos::ALLOW_URLS, 'boolval')
        ->serializeToForum('recipesFields', Fields::SETTING, fn ($value) => Fields::normalize((string) $value)),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Formatter())
        ->configure(Configure::class)
        ->render(Render::class),
];
