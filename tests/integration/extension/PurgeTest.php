<?php

namespace ErnestDefoe\Recipes\Tests\integration\extension;

use ErnestDefoe\Recipes\Photos\Photos;
use Flarum\Extension\ExtensionManager;
use Flarum\Testing\integration\TestCase;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\Attributes\Test;

class PurgeTest extends TestCase
{
    private const PHOTO = '0123456789abcdef0123456789abcdef.webp';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-recipes');

        $disk = $this->app()->getContainer()->make(Factory::class)->disk(Photos::DISK);
        $disk->put(self::PHOTO, 'x');
        $disk->put('not-ours.txt', 'x');

        $this->root = $this->tmpDir().'/public/assets/recipes';
    }

    protected function tearDown(): void
    {
        @unlink($this->root.'/'.self::PHOTO);
        @unlink($this->root.'/not-ours.txt');

        parent::tearDown();
    }

    #[Test]
    public function purging_deletes_the_photos_it_stored_and_nothing_else()
    {
        // The photos table's real down(), run on a throwaway schema: dropping
        // the test database's table would end its transaction on MariaDB.
        $migration = require __DIR__.'/../../../migrations/2026_10_23_000000_create_recipe_photos_table.php';
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $schema = $capsule->getConnection()->getSchemaBuilder();
        $schema->create('recipe_photos', fn ($table) => $table->increments('id'));
        $migration['down']($schema);

        $this->assertFileDoesNotExist($this->root.'/'.self::PHOTO);
        $this->assertFileExists($this->root.'/not-ours.txt', 'A file the extension did not write stays');
    }

    #[Test]
    public function disabling_keeps_the_photos()
    {
        $this->app()->getContainer()->make(ExtensionManager::class)->disable('ernestdefoe-recipes');

        $this->assertFileExists($this->root.'/'.self::PHOTO);
    }
}
