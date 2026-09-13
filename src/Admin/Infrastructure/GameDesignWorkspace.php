<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure;

use App\Admin\Domain\GameRuleset;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Throwable;

/** Lecture et publication partagent le verrou du brouillon, avant tout accès aux catalogues. */
final readonly class GameDesignWorkspace
{
    public function __construct(private EntityManagerInterface $manager, private GameDraft $draft, private GameRulesetPublisher $publisher)
    {
    }

    /** @return array{revision: int, published: array<string, mixed>, draft: array<string, mixed>, publishedRevision: int} */
    public function preview(): array
    {
        $revision = $this->draft->revision();

        return $this->manager->getConnection()->transactional(function () use ($revision): array {
            $this->draft->lock($revision);
            $ruleset = $this->manager->find(GameRuleset::class, 1);
            if (!$ruleset instanceof GameRuleset) {
                throw new LogicException('Le snapshot publié est absent.');
            }
            $this->manager->refresh($ruleset);

            return ['revision' => $revision, 'published' => $ruleset->snapshot(), 'draft' => $this->publisher->prepare($this->manager), 'publishedRevision' => $ruleset->revision()];
        });
    }

    public function publish(int $revision, string $author): void
    {
        try {
            $this->manager->getConnection()->transactional(function () use ($revision, $author): void {
                $this->draft->lock($revision);
                $this->publisher->publish($this->manager, $author);
                $this->draft->advance();
            });
        } catch (Throwable $exception) {
            $this->manager->clear();
            throw $exception;
        }
        $this->publisher->invalidateAfterCommit();
    }
}
