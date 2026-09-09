<?php

declare(strict_types=1);

namespace App\Community\Application;

/**
 * Les messages conservent une clé portable, jamais un chemin local. Ce port permet le
 * remplacement du volume Fly par S3 sans modifier les messages ni les routes privées.
 * Les contenus réencodés sont bornés à 5 Mio ; la lecture mémoire reste donc bornée.
 */
interface ChatImageStorage
{
    public function store(string $contents): string;

    public function read(string $key): string;

    public function delete(string $key): void;

    /** @return iterable<string> */
    public function keys(): iterable;
}
