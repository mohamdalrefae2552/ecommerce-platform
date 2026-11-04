<?php

declare(strict_types=1);

namespace App\Modules\Products\Images;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Exceptions\DecoderException;
use Intervention\Image\ImageManager;

/**
 * Product images (docs/02 Products): every upload is decoded and re-encoded
 * to WebP, which strips EXIF and neutralises polyglot files, then stored
 * under a random ULID name on the public disk (the product-images volume)
 * that edge serves at /storage/products/{ulid}.webp. Bytes that pass the
 * header checks but cannot be decoded are rejected as a validation error.
 */
final readonly class ProductImageStore
{
    private const string DIRECTORY = 'products';

    public function store(UploadedFile $upload): string
    {
        try {
            $webp = (new ImageManager(new Driver))->read($upload->getRealPath())->toWebp(quality: 82);
        } catch (DecoderException) {
            throw ValidationException::withMessages(['image' => 'تعذّر قراءة الصورة؛ الملف تالف أو غير صالح.']);
        }

        $name = Str::ulid().'.webp';

        $this->disk()->put(self::DIRECTORY.'/'.$name, (string) $webp);

        return $name;
    }

    public function delete(string $name): void
    {
        $this->disk()->delete(self::DIRECTORY.'/'.$name);
    }

    public function url(string $name): string
    {
        return rtrim(Config::string('app.url'), '/').'/storage/'.self::DIRECTORY.'/'.$name;
    }

    private function disk(): Filesystem
    {
        return Storage::disk('public');
    }
}
