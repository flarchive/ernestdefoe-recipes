<?php

namespace ErnestDefoe\Recipes\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Recipes\Photos\Photos;
use Flarum\Discussion\Discussion;
use Flarum\Extend;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Contracts\Filesystem\Factory;
use Laminas\Diactoros\UploadedFile;
use PHPUnit\Framework\Attributes\Test;

/**
 * Recipe photos: who may upload, what counts as an image, who may put which
 * photo in a post, the "web addresses" switch, and the clean-up.
 */
class PhotosTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-recipes');
        // Core's ten seconds between posts would stop the second reply in every test.
        $this->extend((new Extend\ThrottleApi())->remove('postTimeout'));

        $this->prepareDatabase([
            User::class => [$this->normalUser(), ['id' => 3, 'username' => 'other', 'email' => 'other@machine.local', 'is_email_confirmed' => 1]],
            'group_user' => [['user_id' => 3, 'group_id' => 3]],
            Discussion::class => [['id' => 1, 'title' => 'Food', 'created_at' => Carbon::now()->subDay(), 'user_id' => 2, 'first_post_id' => 1, 'comment_count' => 1, 'last_post_number' => 1]],
            Post::class => [['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => Carbon::now()->subDay(), 'user_id' => 2, 'type' => 'comment', 'content' => '<t>Hello</t>']],
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    private function file(string $bytes, string $type, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'recipes-test-');
        file_put_contents($path, $bytes);
        $this->files[] = $path;

        return new UploadedFile($path, strlen($bytes), UPLOAD_ERR_OK, $name, $type);
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(40, 30);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 80, 20));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    private function upload(?int $as, UploadedFile $file): array
    {
        $request = $this->request('POST', '/api/recipes/photos', $as ? ['authenticatedAs' => $as] : [])
            ->withUploadedFiles(['photo' => $file]);
        $response = $this->send($request);

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    private function reply(int $as, string $image): array
    {
        $response = $this->send($this->request('POST', '/api/posts', [
            'authenticatedAs' => $as,
            'json' => ['data' => ['type' => 'posts', 'attributes' => ['content' => "[recipe]\ntitle: Toast\nimage: $image\n\n[steps]\nToast it.\n[/steps]\n[/recipe]"], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]]]],
        ]));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    private function exists(string $url): bool
    {
        $disk = $this->app()->getContainer()->make(Factory::class)->disk(Photos::DISK);

        return $disk->exists(basename($url));
    }

    #[Test]
    public function a_guest_cannot_upload()
    {
        [$status] = $this->upload(null, $this->file($this->png(), 'image/png', 'a.png'));

        // 400 is the CSRF check, before the permission is even asked.
        $this->assertContains($status, [400, 401, 403]);
    }

    #[Test]
    public function a_member_uploads_a_photo_that_is_re_encoded_under_a_random_name()
    {
        [$status, $body] = $this->upload(2, $this->file($this->png(), 'image/png', '../../evil.php.png'));

        $this->assertSame(201, $status, json_encode($body));
        $this->assertMatchesRegularExpression('#/assets/recipes/[0-9a-f]{32}\.webp$#', $body['url']);
        $this->assertTrue($this->exists($body['url']));
        $this->assertSame(2, (int) $this->database()->table('recipe_photos')->value('user_id'));
    }

    #[Test]
    public function the_content_decides_not_the_claimed_type()
    {
        // An SVG (script inside) claiming to be a JPEG.
        [$status] = $this->upload(2, $this->file('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/jpeg', 'a.jpg'));
        $this->assertSame(422, $status);

        // A real GIF claiming to be a PNG: a valid image, not an allowed one.
        [$status] = $this->upload(2, $this->file(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 'image/png', 'a.png'));
        $this->assertSame(422, $status);

        $this->assertSame(0, $this->database()->table('recipe_photos')->count());
    }

    #[Test]
    public function only_the_uploader_can_put_a_new_photo_in_a_post()
    {
        [, $body] = $this->upload(2, $this->file($this->png(), 'image/png', 'a.png'));

        [$status] = $this->reply(3, $body['url']);
        $this->assertSame(422, $status, 'someone else claimed an unused upload');

        [$status, $post] = $this->reply(2, $body['url']);
        $this->assertSame(201, $status, json_encode($post));
        $this->assertSame((int) $post['data']['id'], (int) $this->database()->table('recipe_photos')->value('post_id'));

        // Once a recipe shows it, anyone may (a remix carries the original's photo).
        [$status] = $this->reply(3, $body['url']);
        $this->assertSame(201, $status);
    }

    #[Test]
    public function a_made_up_upload_address_is_refused()
    {
        $url = $this->app()->getContainer()->make(Factory::class)->disk(Photos::DISK)->url(str_repeat('a', 32).'.webp');

        [$status] = $this->reply(2, $url);

        $this->assertSame(422, $status);
    }

    #[Test]
    public function web_addresses_are_allowed_by_default()
    {
        [$status] = $this->reply(2, 'https://example.com/toast.jpg');
        $this->assertSame(201, $status);
    }

    #[Test]
    public function with_web_addresses_off_only_uploads_and_images_already_here_are_accepted()
    {
        $this->setting(Photos::ALLOW_URLS, '0');
        // A recipe posted before the switch: its image stays, and edits or remixes may keep it.
        $this->prepareDatabase(['recipe_cards' => [['post_id' => 1, 'position' => 0, 'title' => 'Old toast', 'image' => 'https://example.com/toast.jpg']]]);

        [$status] = $this->reply(2, 'https://example.com/other.jpg');
        $this->assertSame(422, $status);

        [$status] = $this->reply(2, 'https://example.com/toast.jpg');
        $this->assertSame(201, $status);

        [, $body] = $this->upload(2, $this->file($this->png(), 'image/png', 'a.png'));
        [$status] = $this->reply(2, $body['url']);
        $this->assertSame(201, $status, 'uploads still work');
    }

    #[Test]
    public function a_photo_edited_out_or_deleted_is_removed_unless_a_variant_shows_it()
    {
        [, $first] = $this->upload(2, $this->file($this->png(), 'image/png', 'a.png'));
        [, $post] = $this->reply(2, $first['url']);
        $id = $post['data']['id'];

        // Edited out: gone.
        $response = $this->send($this->request('PATCH', '/api/posts/'.$id, [
            'authenticatedAs' => 2,
            'json' => ['data' => ['type' => 'posts', 'id' => $id, 'attributes' => ['content' => "[recipe]\ntitle: Toast\n[/recipe]"]]],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertFalse($this->exists($first['url']));
        $this->assertSame(0, $this->database()->table('recipe_photos')->count());

        // Shown by a variant too: the original's deletion hands it over.
        [, $second] = $this->upload(2, $this->file($this->png(), 'image/png', 'b.png'));
        [, $original] = $this->reply(2, $second['url']);
        [, $variant] = $this->reply(3, $second['url']);

        $response = $this->send($this->request('DELETE', '/api/posts/'.$original['data']['id'], ['authenticatedAs' => 1]));
        $this->assertSame(204, $response->getStatusCode(), (string) $response->getBody());
        $this->assertTrue($this->exists($second['url']));
        $this->assertSame((int) $variant['data']['id'], (int) $this->database()->table('recipe_photos')->value('post_id'));
    }

    #[Test]
    public function an_abandoned_upload_is_pruned_after_a_day()
    {
        [, $body] = $this->upload(2, $this->file($this->png(), 'image/png', 'a.png'));
        $this->database()->table('recipe_photos')->update(['created_at' => Carbon::now()->subHours(25)]);

        $this->assertSame(1, $this->app()->getContainer()->make(Photos::class)->pruneAbandoned());
        $this->assertFalse($this->exists($body['url']));
    }
}
