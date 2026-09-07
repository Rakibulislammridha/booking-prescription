<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Render;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

/**
 * Turns an `uploads`-disk object key (handwriting sheets, §4.12; the drawing PNG, §4.11) into a data URI so the
 * sheet is self-contained: Browsershot renders an HTML string with no base URL and no session, so an <img src>
 * pointing at the authorised panel route would come back blank in the PDF.
 *
 * The scope is deliberately narrow and is the ONLY I/O the render layer performs: private image objects belonging
 * to this tenant's own prescription, size-capped, never the `catalog` connection and never a re-query of drug
 * tables (invariant I6 / BRIEF §8). Logo and signature are already inlined into the snapshot at issue.
 */
final class PrintImageInliner
{
    private const MAX_BYTES = 6_000_000;

    private const ALLOWED = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];

    /** @var array<string, string|null> */
    private array $memo = [];

    public function __construct(private readonly FilesystemFactory $filesystems) {}

    public function dataUri(?string $path): ?string
    {
        if ($path === null || $path === '' || str_contains($path, '..')) {
            return null;
        }

        if (array_key_exists($path, $this->memo)) {
            return $this->memo[$path];
        }

        $mime = self::ALLOWED[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;

        if ($mime === null) {
            return $this->memo[$path] = null;
        }

        try {
            $disk = $this->filesystems->disk((string) config('prescription.uploads_disk', 'uploads'));

            if (! $disk->exists($path) || ($disk->size($path) ?: 0) > self::MAX_BYTES) {
                return $this->memo[$path] = null;
            }

            $bytes = $disk->get($path);

            return $this->memo[$path] = $bytes === null ? null : "data:{$mime};base64,".base64_encode($bytes);
        } catch (\Throwable) {
            return $this->memo[$path] = null;
        }
    }

    /**
     * @param  list<string>  $paths
     * @return list<string> only the pages that actually resolved — a missing sheet must not print an empty page
     */
    public function dataUris(array $paths): array
    {
        return array_values(array_filter(array_map($this->dataUri(...), $paths), fn (?string $uri) => $uri !== null));
    }
}
