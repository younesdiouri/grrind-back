<?php

declare(strict_types=1);

namespace App\Shared\UI\Http;

use Symfony\Component\HttpFoundation\Response;

/**
 * Un problem details RFC 9457. Les quatre membres standard sont typés ; les extensions
 * sont libres mais ne peuvent pas les écraser.
 */
final readonly class ProblemDetails
{
    private const string TYPE_PREFIX = 'https://grrind.app/problems/';

    /**
     * @param array<string, mixed> $extensions
     */
    public function __construct(
        public string $type,
        public int $status,
        public string $detail,
        private array $extensions = [],
    ) {
    }

    /**
     * @param array<string, mixed> $extensions
     */
    public static function of(string $type, int $status, string $detail, array $extensions = []): self
    {
        return new self(self::TYPE_PREFIX.$type, $status, $detail, $extensions);
    }

    /**
     * Type dérivé du statut : « Not Found » → « not-found ».
     *
     * @param array<string, mixed> $extensions
     */
    public static function ofStatus(int $status, ?string $detail = null, array $extensions = []): self
    {
        $title = self::titleOf($status);

        return self::of(
            strtolower(str_replace(' ', '-', $title)),
            $status,
            null !== $detail && '' !== $detail ? $detail : $title,
            $extensions,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'title' => self::titleOf($this->status),
            'status' => $this->status,
            'detail' => $this->detail,
        ] + $this->extensions;
    }

    private static function titleOf(int $status): string
    {
        return Response::$statusTexts[$status] ?? 'Error';
    }
}
