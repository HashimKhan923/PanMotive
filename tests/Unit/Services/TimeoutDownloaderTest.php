<?php

namespace Tests\Unit\Services;

use App\Support\Media\TimeoutDownloader;
use Illuminate\Support\Facades\Http;
use Spatie\MediaLibrary\MediaCollections\Exceptions\UnreachableUrl;
use Tests\TestCase;

class TimeoutDownloaderTest extends TestCase
{
    public function test_downloads_a_reachable_url_to_a_temp_file(): void
    {
        Http::fake(['*' => Http::response('fake-bytes', 200)]);

        $path = (new TimeoutDownloader)->getTempFile('https://example.com/photo.jpg');

        $this->assertFileExists($path);
        $this->assertSame('fake-bytes', file_get_contents($path));
        @unlink($path);
    }

    public function test_a_failing_response_throws_unreachable_url_instead_of_hanging(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);

        $this->expectException(UnreachableUrl::class);
        (new TimeoutDownloader)->getTempFile('https://example.com/dead.jpg');
    }

    public function test_a_connection_error_throws_unreachable_url(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out'));

        $this->expectException(UnreachableUrl::class);
        (new TimeoutDownloader)->getTempFile('https://example.com/slow.jpg');
    }
}
