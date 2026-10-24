<?php

use ErnestDefoe\Recipes\Photos\Photos;
use Flarum\Database\Migration;
use Illuminate\Container\Container;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Who uploaded which recipe photo (Photos\Photos), and the post that brought
 * it in. A post id, not a foreign key: when the post goes, the photo is pruned
 * by the Deleted listener, or handed to a variant that still shows it.
 *
 * Purging the extension takes the photos' files with the table.
 */
$migration = Migration::createTable('recipe_photos', function (Blueprint $table) {
    $table->increments('id');
    $table->string('name', 64)->unique();
    $table->unsignedInteger('user_id')->nullable()->index();
    $table->unsignedInteger('post_id')->nullable()->index();
    $table->dateTime('created_at')->index();

    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
});

$drop = $migration['down'];
$migration['down'] = function (Builder $schema) use ($drop) {
    Photos::purge(Container::getInstance());
    $drop($schema);
};

return $migration;
