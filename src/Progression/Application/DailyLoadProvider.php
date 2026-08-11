<?php

declare(strict_types=1);

namespace App\Progression\Application;

use App\Progression\Domain\DailyLoad;
use App\Progression\Infrastructure\Doctrine\XpTransactionRepository;
use App\Shared\Application\PlayerTimezones;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\LocalDay;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Assemble le contexte du jour qu'attend `XpCalculator` : trouve le fuseau du joueur,
 * délimite sa journée, interroge le ledger.
 *
 * Toute l'impureté du calcul d'XP tient dans cette classe — c'est délibéré. Le calculateur
 * reste une fonction, et ce qui doit lire l'horloge, la base et le profil est isolé ici,
 * où l'on peut le remplacer dans un test sans rien simuler du calcul lui-même.
 *
 * `$now` est un paramètre et non une horloge injectée : la transaction de complétion
 * (#21) date déjà la séance, et deux lectures d'horloge dans la même transaction
 * pourraient tomber de part et d'autre de minuit — c'est rare, et c'est exactement le
 * genre de bug qu'on ne reproduit jamais.
 */
final readonly class DailyLoadProvider
{
    public function __construct(
        private XpTransactionRepository $ledger,
        private PlayerTimezones $timezones,
    ) {
    }

    public function of(Uuid $userId, Discipline $discipline, DateTimeImmutable $now): DailyLoad
    {
        return $this->ledger->dailyLoadOf(
            $userId,
            $discipline,
            LocalDay::containing($now, $this->timezones->of($userId)),
        );
    }
}
