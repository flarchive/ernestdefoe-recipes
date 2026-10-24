<?php

namespace ErnestDefoe\Recipes\Console;

use ErnestDefoe\Recipes\Photos\Photos;
use Flarum\Console\AbstractCommand;

/**
 * Delete recipe photos uploaded more than a day ago that no saved post shows.
 * Scheduled daily; each member's own leftovers also go when they next upload.
 */
class PrunePhotosCommand extends AbstractCommand
{
    public function __construct(private readonly Photos $photos)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('recipes:prune-photos')->setDescription('Delete uploaded recipe photos that no post uses.');
    }

    protected function fire(): int
    {
        $total = 0;
        // Bounded batches until a batch comes back short.
        do {
            $total += $deleted = $this->photos->pruneAbandoned(null, 200);
        } while ($deleted === 200);

        $this->info("Deleted $total unused recipe photos.");

        return 0;
    }
}
