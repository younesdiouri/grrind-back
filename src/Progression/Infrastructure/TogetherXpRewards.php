<?php

declare(strict_types=1);

namespace App\Progression\Infrastructure;

use App\Progression\Application\TitleUnlocker;
use App\Progression\Domain\LevelCurve;
use App\Progression\Domain\TogetherBonusReceipt;
use App\Progression\Domain\XpAward;
use App\Progression\Domain\XpBreakdown;
use App\Progression\Domain\XpBreakdownLine;
use App\Progression\Domain\XpBreakdownSource;
use App\Progression\Domain\XpReason;
use App\Progression\Domain\XpTransaction;
use App\Progression\Infrastructure\Doctrine\ProgressionSnapshotRepository;
use App\Progression\Infrastructure\Doctrine\XpTransactionRepository;
use App\Progression\Infrastructure\Translation\TitleTranslator;
use App\Shared\Application\TogetherRewards;
use App\Shared\Domain\Activity\AttributeGains;
use App\Shared\Domain\Activity\AttributeSplit;
use App\Shared\Domain\Activity\Vitality;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** Le supplément réutilise le crédit figé, jamais les modificateurs du jour de la détection. */
final readonly class TogetherXpRewards implements TogetherRewards
{
    public function __construct(private EntityManagerInterface $em, private XpTransactionRepository $ledger, private ProgressionSnapshotRepository $snapshots, private LevelCurve $curve, private Vitality $vitality, private TitleUnlocker $titles, private TitleTranslator $translator, private ClockInterface $clock)
    {
    }

    public function grant(Uuid $workoutId, int $multiplier, string $detectionVersion): void
    {
        $this->em->wrapInTransaction(function () use ($workoutId, $multiplier, $detectionVersion): void {
            $credit = $this->ledger->recordedFor($workoutId, XpReason::SessionCompleted);
            if (null === $credit || null !== $this->ledger->recordedFor($workoutId, XpReason::SessionInvalidated) || 0 === $credit->amount()) {
                return;
            }
            $snapshot = $this->snapshots->lockFor($credit->userId(), $this->curve, $this->vitality);
            $receipts = $this->em->getRepository(TogetherBonusReceipt::class)->findBy(['workoutId' => $workoutId], ['multiplier' => 'DESC'], 1);
            $previous = $receipts[0]->multiplier ?? 1;
            if ($multiplier <= $previous) {
                return;
            }
            $split = array_change_key_case($credit->attributePercentages(), \CASE_LOWER);
            if ([] === $split) {
                throw new LogicException('Le crédit ne porte pas de répartition figée pour Sport ensemble.');
            }
            $discipline = $credit->discipline();
            /** @var array{strength: int, endurance: int, mobility: int, dexterity: int} $split */
            $distribution = new AttributeSplit([['discipline' => $discipline->value, ...$split]], [['discipline' => $discipline->value]]);
            $beforeGains = $distribution->distribute($discipline, $credit->amount() * $previous)->toArray();
            $afterGains = $distribution->distribute($discipline, $credit->amount() * $multiplier)->toArray();
            $gains = new AttributeGains(...array_map(static fn (int $after, int $before): int => $after - $before, array_values($afterGains), array_values($beforeGains)));
            $amount = $credit->amount() * ($multiplier - $previous);
            $award = new XpAward(new XpBreakdown(new XpBreakdownLine(XpBreakdownSource::Together, $amount)), $gains, $credit->rulesetVersion());
            $id = Uuid::v7();
            $this->ledger->add(XpTransaction::togetherFor($credit, $id, $award));
            $this->ledger->commit();
            $before = $snapshot->standing();
            $attributesBefore = $snapshot->attributes()->toArray();
            $vitalityBefore = $snapshot->vitality();
            $now = $this->clock->now();
            $reached = $snapshot->retotal($this->ledger->totalOf($credit->userId()), $this->ledger->attributeTotalsOf($credit->userId()), $this->curve, $this->vitality, $now);
            $titles = $this->titles->unlock($credit->userId(), $now);
            $attributes = [];
            foreach ($gains->toArray() as $attribute => $gain) {
                $attributes[$attribute] = ['gained' => $gain, 'before' => $attributesBefore[$attribute], 'after' => $snapshot->attributes()->toArray()[$attribute]];
            }
            $attributes['vitality'] = ['before' => $vitalityBefore, 'after' => $snapshot->vitality()];
            $this->em->persist(new TogetherBonusReceipt($id, $credit->userId(), $workoutId, $multiplier, [
                'id' => $id->toRfc4122(), 'workoutId' => $workoutId->toRfc4122(), 'occurredAt' => $credit->occurredAt()->format(\DATE_ATOM), 'createdAt' => $now->format(\DATE_ATOM),
                'previousMultiplier' => $previous, 'multiplier' => $multiplier, 'soloXp' => $credit->amount(), 'bonusXp' => $amount, 'totalBonusXp' => $credit->amount() * ($multiplier - 1), 'workoutTotalXp' => $credit->amount() * $multiplier,
                'xp' => ['awarded' => $amount, 'breakdown' => [['source' => 'TOGETHER', 'amount' => $amount]]], 'attributes' => $attributes,
                'level' => ['before' => $before->level, 'xpIntoLevelBefore' => $before->xpIntoLevel, 'xpToNextLevelBefore' => $before->xpToNextLevel, 'after' => $snapshot->level(), 'reached' => $reached, 'totalXp' => $snapshot->totalXp(), 'xpIntoLevel' => $snapshot->xpIntoLevel(), 'xpToNextLevel' => $snapshot->xpToNextLevel()],
                'skillPointsGranted' => $snapshot->earnedSkillPoints() - $before->earnedSkillPoints,
                'titlesUnlocked' => array_map(fn ($title): array => $this->translator->describe(\App\Progression\Domain\TitleProgress::completed($title), $now)->toArray(), $titles),
                'rulesetVersion' => $credit->rulesetVersion(), 'detectionRulesetVersion' => $detectionVersion,
            ]));
            $this->em->flush();
        });
    }
}
