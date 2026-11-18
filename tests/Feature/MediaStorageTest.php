<?php

namespace Tests\Feature;

use App\Services\Marketing\Social\SocialPublisher;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaStorageTest extends TestCase
{
    public function test_public_files_stay_on_this_server_unless_r2_is_switched_on(): void
    {
        $this->assertSame('local', config('filesystems.disks.public.driver'));
        $this->assertSame('local', config('filesystems.disks.public-local.driver'));
        $this->assertSame(config('filesystems.disks.public.root'), config('filesystems.disks.public-local.root'));
    }

    public function test_r2_switch_builds_a_cloud_disk_with_the_media_address(): void
    {
        putenv('MEDIA_DRIVER=r2');
        putenv('MEDIA_URL=https://media.example.com/');
        try {
            $disks = (require config_path('filesystems.php'))['disks'];
        } finally {
            putenv('MEDIA_DRIVER');
            putenv('MEDIA_URL');
        }

        $this->assertSame('s3', $disks['public']['driver']);
        $this->assertSame('https://media.example.com', $disks['public']['url']);
        $this->assertTrue($disks['public']['use_path_style_endpoint']);
        $this->assertSame('local', $disks['public-local']['driver']);
    }

    public function test_local_images_are_used_in_place_without_a_temporary_copy(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('social/1/a.png', 'png-bytes');

        $file = SocialPublisher::localFile('public', 'social/1/a.png');

        $this->assertFalse($file['temp']);
        $this->assertSame('png-bytes', file_get_contents($file['path']));
    }

    public function test_copy_command_refuses_while_files_are_still_local(): void
    {
        $this->artisan('storage:copy-public-to-cloud')->assertFailed();
    }
}
