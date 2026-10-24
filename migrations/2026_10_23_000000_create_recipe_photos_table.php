<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * Who uploaded which recipe photo (Photos\Photos), and the post that brought
 * it in. A post id, not a foreign key: when the post goes, the photo is pruned
 * by the Deleted listener, or handed to a variant that still shows it.
 */
return Migration::createTable('recipe_photos', function (Blueprint $table) {
    $table->increments('id');
    $table->string('name', 64)->unique();
    $table->unsignedInteger('user_id')->nullable()->index();
    $table->unsignedInteger('post_id')->nullable()->index();
    $table->dateTime('created_at')->index();

    $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
});
