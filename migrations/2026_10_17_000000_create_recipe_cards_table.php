<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * One row per recipe block, kept in step with the post that holds it
 * (Index\Indexer). It exists so the directory and the variant links can ask
 * "which posts hold recipes" without reading every post on the forum.
 *
 * It holds only what a card list shows. Where the post lives (discussion,
 * number, author, date) is joined from `posts` at read time, so a post moved
 * to another discussion is never listed under the old one.
 */
return Migration::createTable('recipe_cards', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('post_id');
    $table->unsignedSmallInteger('position');
    $table->string('title', 255);
    $table->string('image', 2000)->nullable();
    $table->unsignedSmallInteger('servings')->nullable();
    $table->unsignedInteger('prep')->nullable();
    $table->unsignedInteger('cook')->nullable();
    // A post id, not a foreign key: a variant outlives its original.
    $table->unsignedInteger('based_on_post_id')->nullable()->index();

    $table->unique(['post_id', 'position']);
    $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
});
