<?php

namespace Tests\Support\Architecture;

/**
 * A PHP source file with comments stripped, so rules only match real code.
 */
final class SourceFile
{
    public readonly string $code;

    public function __construct(public readonly string $path, string $contents)
    {
        $this->code = self::stripComments($contents);
    }

    public static function fromPath(string $path): self
    {
        return new self($path, (string) file_get_contents($path));
    }

    /**
     * @return list<self>
     */
    public static function allUnder(string $directory, string $extension = 'php'): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.'.$extension)) {
                $files[] = self::fromPath($file->getPathname());
            }
        }

        return $files;
    }

    /** Module name for files under app/Modules/<Module>/, or null. */
    public function module(): ?string
    {
        return preg_match('#/Modules/([A-Za-z]+)/#', $this->path, $match) === 1 ? $match[1] : null;
    }

    public function isUnder(string $pathFragment): bool
    {
        return str_contains($this->path, $pathFragment);
    }

    private static function stripComments(string $contents): string
    {
        $code = '';

        foreach (token_get_all($contents) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
