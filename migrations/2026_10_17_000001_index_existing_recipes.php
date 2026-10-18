<?php

use ErnestDefoe\Recipes\Index\Indexer;
use Illuminate\Database\Schema\Builder;

/*
 * Index the recipes posted before 1.1.0. Chunked by id, and only posts whose
 * stored XML contains a RECIPE tag are read at all.
 */
return [
    'up' => function (Builder $schema) {
        Indexer::backfill($schema->getConnection());
    },
    'down' => function (Builder $schema) {
        // The table's own migration drops it.
    },
];
