<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Storage;

use App\Community\Application\ChatImageStorage;
use InvalidArgumentException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/** Répertoire hors public/, dédié au chat : les illustrations du jeu n'y donnent pas accès. */
final readonly class LocalChatImageStorage implements ChatImageStorage
{
    public function __construct(private string $chatImageDirectory, private Filesystem $filesystem)
    {
    }

    public function store(string $contents): string
    {
        $key = Uuid::v7()->toRfc4122().'.webp';
        $this->filesystem->dumpFile($this->path($key), $contents);

        return $key;
    }

    public function read(string $key): string
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            throw new NotFoundHttpException();
        }

        return $this->filesystem->readFile($path);
    }

    public function delete(string $key): void
    {
        $this->filesystem->remove($this->path($key));
    }

    public function keys(): iterable
    {
        if (!is_dir($this->chatImageDirectory)) {
            return;
        }
        foreach (new Finder()->files()->in($this->chatImageDirectory)->depth('== 0')->name('/^[a-f0-9-]{36}\.webp$/D') as $file) {
            yield $file->getFilename();
        }
    }

    private function path(string $key): string
    {
        if (1 !== preg_match('/^[a-f0-9-]{36}\.webp$/D', $key)) {
            throw new InvalidArgumentException('Clé de pièce jointe invalide.');
        }

        return $this->chatImageDirectory.\DIRECTORY_SEPARATOR.$key;
    }
}
