<?php

namespace ErnestDefoe\Recipes\Console;

use ErnestDefoe\Recipes\Index\Indexer;
use Flarum\Console\AbstractCommand;
use Illuminate\Database\ConnectionInterface;

/**
 * Rebuild the recipe index from the posts themselves. Never needed in normal
 * running (it is kept current as posts are saved); here for after a restore or
 * an import that wrote posts without Flarum's events.
 */
class ReindexCommand extends AbstractCommand
{
    public function __construct(private readonly ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('recipes:reindex')->setDescription('Rebuild the recipe directory index from the posts.');
    }

    protected function fire(): int
    {
        $this->db->table('recipe_cards')->delete();
        $this->info('Indexed '.Indexer::backfill($this->db).' posts with recipes.');

        return 0;
    }
}
