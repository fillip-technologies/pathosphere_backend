<?php

namespace App\Modules\Shared\Files;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reads and writes health and identity files in storage/app/private, never
 * under public/ (spec §3). Controllers must check the caller's scope and log
 * the access before calling download().
 */
final class PrivateFileStore
{
    private const DISK = 'local';

    /**
     * Writes a new file. Existing files are never overwritten: released
     * reports and signed agreements are legal records (spec §10.6).
     */
    public function putNew(string $path, string $contents): StoredFile
    {
        $this->assertSafePath($path);

        if ($this->disk()->exists($path)) {
            throw new LogicException("Refusing to overwrite private file {$path}.");
        }

        $this->disk()->put($path, $contents);

        return new StoredFile($path, hash('sha256', $contents), strlen($contents));
    }

    public function exists(string $path): bool
    {
        $this->assertSafePath($path);

        return $this->disk()->exists($path);
    }

    public function sha256(string $path): string
    {
        $this->assertSafePath($path);

        return hash('sha256', (string) $this->disk()->get($path));
    }

    public function download(string $path, string $downloadName): StreamedResponse
    {
        $this->assertSafePath($path);

        return $this->disk()->download($path, $downloadName);
    }

    private function assertSafePath(string $path): void
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Private file paths must be relative and stay inside private storage.');
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }
}
