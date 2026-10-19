<?php

namespace Tests\Feature\Api;

use App\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class MediaUploadTest extends ApiTestCase
{
    private const URL = '/wp-json/wp/v2/media';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /** What the app sends: multipart "file", no token; it reads source_url from a 201. */
    public function test_an_anonymous_image_upload_returns_its_source_url(): void
    {
        $response = $this->post(self::URL, ['file' => UploadedFile::fake()->image('IMG_0042.jpg', 600, 400)], ['Accept' => 'application/json'])
            ->assertStatus(201)
            ->assertJsonPath('media_type', 'image')
            ->assertJsonPath('mime_type', 'image/jpeg');

        $media = Media::sole();
        $this->assertSame($media->url(), $response->json('source_url'));
        Storage::disk('public')->assertExists($media->path);

        // Unguessable: the client's file name is not in the URL.
        $this->assertStringNotContainsString('IMG_0042', $response->json('source_url'));
        $this->assertMatchesRegularExpression('#uploads/\d{4}/\d{2}/[A-Za-z0-9]{40}\.jpg$#', $media->path);
        $this->assertSame('IMG_0042.jpg', $media->original_name);
    }

    public function test_the_type_comes_from_the_content_not_the_name(): void
    {
        // A PNG named .jpg is stored as what it is.
        $png = UploadedFile::fake()->image('photo.png');
        $renamed = new UploadedFile($png->getRealPath(), 'photo.jpg', 'image/jpeg', null, true);
        $this->post(self::URL, ['file' => $renamed], ['Accept' => 'application/json'])->assertStatus(201)->assertJsonPath('mime_type', 'image/png');

        // A script named .jpg is refused.
        $script = UploadedFile::fake()->createWithContent('id.jpg', '<?php system($_GET["c"]);');
        $this->post(self::URL, ['file' => $script], ['Accept' => 'application/json'])
            ->assertStatus(500)->assertJsonPath('code', 'rest_upload_sideload_error');
        $this->assertSame(1, Media::count());
    }

    public function test_no_file_and_too_big(): void
    {
        $this->post(self::URL, [], ['Accept' => 'application/json'])
            ->assertStatus(400)->assertExactJson(['code' => 'rest_upload_no_data', 'message' => 'No data supplied.', 'data' => ['status' => 400]]);

        config(['medvault.uploads.max_kb' => 1]);
        $this->post(self::URL, ['file' => UploadedFile::fake()->image('big.jpg', 2000, 2000)], ['Accept' => 'application/json'])
            ->assertStatus(400)->assertJsonPath('code', 'rest_upload_file_too_big');
    }

    public function test_uploads_are_rate_limited_per_address(): void
    {
        config(['medvault.uploads.per_minute' => 2]);

        for ($i = 0; $i < 2; $i++) {
            $this->post(self::URL, ['file' => UploadedFile::fake()->image("p{$i}.jpg")], ['Accept' => 'application/json'])->assertStatus(201);
        }
        $this->post(self::URL, ['file' => UploadedFile::fake()->image('p3.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(429)->assertJsonPath('code', 'rest_too_many_requests');
    }

    public function test_only_post_exists_in_wp_v2(): void
    {
        $this->getJson(self::URL)->assertStatus(404)->assertJsonPath('code', 'rest_no_route');
        $this->getJson('/wp-json/wp/v2/users')->assertStatus(404)->assertJsonPath('code', 'rest_no_route');
    }
}
