<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\AlamNarrationSource;
use App\Community\Domain\AlamRun;
use App\Community\Infrastructure\Narration\OpenAiAlamNarrator;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/** La mémoire se relit dans les faits persistés, jamais dans le texte du modèle. Appel réseau hors transaction. */
#[AsMessageHandler]
final readonly class NarrateAlamHandler
{
    public function __construct(private EntityManagerInterface $em, private OpenAiAlamNarrator $narrator)
    {
    }

    public function __invoke(NarrateAlam $message): void
    {
        $run = $this->em->find(AlamRun::class, Uuid::fromString($message->runId));
        if (null === $run || null === $run->resolvedAt || AlamNarrationSource::OpenAi->value === ($run->result['narrationSource'] ?? null)) {
            return;
        }
        /** @var array{memory_limit: int} $rules */
        $rules = $run->rules['alam'];
        /** @var list<AlamRun> $previous */
        $previous = $this->em->createQueryBuilder()->select('r')->from(AlamRun::class, 'r')->where('r.guildId = :guild AND r.id < :id AND r.resolvedAt IS NOT NULL')->setParameter('guild', $run->guildId->toRfc4122())->setParameter('id', $run->id->toRfc4122())->orderBy('r.id', 'DESC')->setMaxResults($rules['memory_limit'])->getQuery()->getResult();
        $memories = array_map(self::facts(...), $previous);
        $texts = $this->narrator->narrate(self::facts($run), $memories);
        if (null === $texts) {
            return;
        }
        $this->em->wrapInTransaction(function () use ($run, $texts): void {
            $this->em->refresh($run, LockMode::PESSIMISTIC_WRITE);
            if (AlamNarrationSource::OpenAi->value === ($run->result['narrationSource'] ?? null)) {
                return;
            }
            /** @var list<array<string, mixed>> $encounters */
            $encounters = $run->result['encounters'];
            foreach ($encounters as $index => &$encounter) {
                $encounter['narration'] = $texts[$index];
            }
            unset($encounter);
            $run->result['encounters'] = $encounters;
            $run->result['narrationSource'] = AlamNarrationSource::OpenAi->value;
        });
    }

    /** @return array<string, mixed> */
    private static function facts(AlamRun $run): array
    {
        /** @var list<array{index: int, won: bool, drops: list<array{playerId: string, itemKey: string, quantity: int}>}> $encounters */
        $encounters = $run->result['encounters'] ?? [];
        /** @var list<array{playerId: string, displayName: string, contribution: int, activitySummary?: list<array{discipline: string, sessions: int, durationSeconds: int}>}> $participants */
        $participants = $run->result['participants'] ?? [];

        $names = array_column($participants, 'displayName', 'playerId');

        return ['participants' => array_map(static fn (array $player): array => ['name' => $player['displayName'], 'contribution' => $player['contribution'], 'sports' => $player['activitySummary'] ?? []], $participants), 'encounters' => array_map(static fn (array $encounter): array => ['index' => $encounter['index'], 'won' => $encounter['won'], 'drops' => array_map(static fn (array $drop): array => ['player' => $names[$drop['playerId']] ?? 'Aventurier', 'item' => $drop['itemKey'], 'quantity' => $drop['quantity']], $encounter['drops'])], $encounters)];
    }
}
