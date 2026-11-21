<?php

declare(strict_types=1);

namespace App\Community\UI\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RecruitmentPageQuery
{
    public function __construct(
        #[Assert\Range(min: 1, max: 50)]
        public int $limit = 20,
        #[Assert\NotBlank(allowNull: true)]
        #[Assert\Uuid]
        public ?string $cursor = null,
    ) {
    }

    /**
     * @template T of array{id: string}
     *
     * @param list<T> $rows
     *
     * @return array{items: list<T>, nextCursor: ?string}
     */
    public function page(array $rows): array
    {
        $more = \count($rows) > $this->limit;
        $items = \array_slice($rows, 0, $this->limit);

        return ['items' => $items, 'nextCursor' => $more ? $items[$this->limit - 1]['id'] : null];
    }
}
