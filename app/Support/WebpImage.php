<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Turns any uploaded photo (JPG, PNG, GIF, BMP, AVIF, HEIC from iPhones, WebP) into a WebP file,
 * so every product photo is stored as WebP. It also turns phone photos the right way up, removes
 * hidden photo data (camera, GPS location) and shrinks very large photos to MAX_SIDE pixels.
 * Anything that isn't a real image is refused.
 */
class WebpImage
{
    public const MAX_SIDE = 2000;

    public const QUALITY = 82;

    /** Real file types we accept (checked from the file's content, not its name). */
    public const ALLOWED_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/x-ms-bmp',
        'image/avif', 'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence',
    ];

    /**
     * @return string path of a new temporary .webp file (the caller moves or deletes it)
     *
     * @throws InvalidArgumentException when the file is not a readable image
     */
    public static function fromFile(string $source): string
    {
        if (! is_file($source) || filesize($source) === 0) {
            throw new InvalidArgumentException('The photo file is empty or missing.');
        }
        // Never hand anything but a real photo to the image library (no SVG, PDF, scripts...).
        $type = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($source);
        if (! in_array($type, self::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException('This file is not a photo. Use JPG, PNG, WebP, GIF or HEIC.');
        }

        $target = self::tempPath();

        try {
            extension_loaded('imagick') ? self::withImagick($source, $target) : self::withGd($source, $target);
        } catch (InvalidArgumentException $e) {
            @unlink($target);
            throw $e;
        } catch (\Throwable $e) {
            @unlink($target);
            throw new InvalidArgumentException('This file is not a photo we can read. Use JPG, PNG, WebP, GIF or HEIC.', 0, $e);
        }

        if (! is_file($target) || filesize($target) === 0) {
            @unlink($target);
            throw new InvalidArgumentException('This photo could not be converted.');
        }

        return $target;
    }

    /** The file name to store: the original name with a .webp ending. */
    public static function fileName(?string $original): string
    {
        $base = trim((string) pathinfo((string) $original, PATHINFO_FILENAME));
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base) ?: '';
        $base = trim($base, '.-') ?: 'photo';

        return substr($base, 0, 120).'.webp';
    }

    private static function withImagick(string $source, string $target): void
    {
        $im = new \Imagick;
        $im->readImage($source.'[0]'); // first frame only (animated GIFs)
        if (method_exists($im, 'autoOrient')) {
            $im->autoOrient();
        }
        $im->stripImage();
        if (max($im->getImageWidth(), $im->getImageHeight()) > self::MAX_SIDE) {
            $im->thumbnailImage(self::MAX_SIDE, self::MAX_SIDE, true);
        }
        $im->setImageFormat('webp');
        $im->setImageCompressionQuality(self::QUALITY);
        $im->setOption('webp:method', '4');
        $im->writeImage($target);
        $im->clear();
    }

    private static function withGd(string $source, string $target): void
    {
        $bytes = (string) file_get_contents($source);
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            throw new InvalidArgumentException('This file is not a photo we can read. Use JPG, PNG, WebP or GIF.');
        }

        // Phone photos store "which way up" separately; apply it so the photo isn't sideways.
        if (function_exists('exif_read_data') && str_starts_with($bytes, "\xFF\xD8")) {
            $orientation = (int) (@exif_read_data($source)['Orientation'] ?? 1);
            $rotated = match ($orientation) {
                3 => imagerotate($img, 180, 0),
                6 => imagerotate($img, -90, 0),
                8 => imagerotate($img, 90, 0),
                default => null,
            };
            if ($rotated) {
                imagedestroy($img);
                $img = $rotated;
            }
        }

        if (! imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }
        [$w, $h] = [imagesx($img), imagesy($img)];
        if (max($w, $h) > self::MAX_SIDE) {
            $scale = self::MAX_SIDE / max($w, $h);
            $resized = imagescale($img, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)), IMG_BICUBIC);
            imagedestroy($img);
            $img = $resized;
        }
        imagealphablending($img, false);
        imagesavealpha($img, true); // keep see-through backgrounds (PNG)

        imagewebp($img, $target, self::QUALITY);
        imagedestroy($img);
    }

    private static function tempPath(): string
    {
        $base = tempnam(sys_get_temp_dir(), 'pm-webp-');
        @unlink($base);

        return $base.'.webp';
    }
}
