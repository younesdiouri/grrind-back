<?php

declare(strict_types=1);

namespace App\Progression\UI\Console;

use App\Progression\Application\RebuildReport;
use App\Progression\Application\RebuildSnapshots;
use App\Progression\Application\RebuildSnapshotsHandler;
use App\Progression\Domain\SnapshotDivergence;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Rejoue le ledger et remet `progression_snapshot` d'accord avec lui.
 *
 * **`--dry-run` est le mode qui sert.** Il ne répare rien : il dit s'il y a quelque chose à
 * réparer, et c'est là toute la valeur. Un cache qui ne sait pas se comparer à sa source
 * n'est pas un cache, c'est une seconde vérité qui diverge sans que personne le sache
 * jusqu'à ce qu'un joueur lise un mauvais niveau.
 *
 * D'où le **code de sortie 1 quand `--dry-run` trouve un écart** : la commande est faite
 * pour tourner en tâche planifiée, et une sonde qui rend toujours zéro ne sonde rien.
 * Hors `--dry-run`, réparer *est* le travail : la sortie est zéro même quand il a fallu
 * réécrire.
 *
 * Commande invocable — `#[AsCommand]` sur la classe, `__invoke()` avec ses options en
 * attributs, `SymfonyStyle` injecté comme argument. C'est la forme courante du composant :
 * https://symfony.com/doc/current/console.html
 */
#[AsCommand(
    name: 'app:progression:rebuild',
    description: 'Compare les snapshots de progression au ledger, et les réécrit',
    help: <<<'HELP'
        Le ledger d'XP est la vérité ; <info>progression_snapshot</info> n'en est qu'une
        projection. Cette commande rejoue la somme du ledger, reprojette la courbe de
        niveaux, et signale — ou corrige — tout ce qui ne correspond pas.

        Auditer toute la base sans rien écrire :
            <info>%command.full_name% --dry-run</info>

        Réparer un compte dont on a déjà le signalement :
            <info>%command.full_name% --user=0198b0e1-... </info>
        HELP,
)]
final readonly class RebuildProgressionCommand
{
    public function __construct(private RebuildSnapshotsHandler $rebuild)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Compare et signale, sans rien réécrire')]
        bool $dryRun = false,
        #[Option(description: 'Ne traiter que ce compte, plutôt que toute la base')]
        ?string $user = null,
    ): int {
        if (null !== $user && !Uuid::isValid($user)) {
            $io->error(\sprintf('« %s » n\'est pas un identifiant de compte.', $user));

            return Command::INVALID;
        }

        $io->title($dryRun ? 'Vérification des snapshots de progression' : 'Reconstruction des snapshots de progression');

        $report = ($this->rebuild)(new RebuildSnapshots(
            null === $user ? null : Uuid::fromString($user),
            $dryRun,
        ));

        return self::reportTo($io, $report);
    }

    private static function reportTo(SymfonyStyle $io, RebuildReport $report): int
    {
        if ($report->isCoherent()) {
            $io->success(\sprintf('%d comptes vérifiés, aucun écart.', $report->checked));

            return Command::SUCCESS;
        }

        // Le détail avant le verdict : ce qui intéresse l'opérateur est *quelle* colonne a
        // dérivé, parce que c'est elle qui désigne le bout de code à aller lire.
        $io->table(
            ['Compte', 'Colonne', 'En base', 'Attendu'],
            iterator_to_array(self::rowsOf($report->samples), false),
        );

        if ($report->diverged > \count($report->samples)) {
            $io->comment(\sprintf('… et %d autres comptes en écart, non détaillés.', $report->diverged - \count($report->samples)));
        }

        if (!$report->hasUnrepairedDivergences()) {
            $io->success(\sprintf('%d comptes vérifiés, %d réécrits.', $report->checked, $report->repaired));

            return Command::SUCCESS;
        }

        $io->error(\sprintf(
            '%d comptes vérifiés, %d en écart. Relancer sans --dry-run pour réécrire.',
            $report->checked,
            $report->diverged,
        ));

        return Command::FAILURE;
    }

    /**
     * Une ligne par colonne en écart, et non par compte : un compte dont trois colonnes ont
     * dérivé a trois choses à dire.
     *
     * @param list<SnapshotDivergence> $divergences
     *
     * @return iterable<array{string, string, string, string}>
     */
    private static function rowsOf(array $divergences): iterable
    {
        foreach ($divergences as $divergence) {
            foreach ($divergence->fields as $name => $values) {
                yield [
                    $divergence->userId->toRfc4122(),
                    $name,
                    self::show($values['stored']),
                    self::show($values['expected']),
                ];
            }
        }
    }

    /** `null` en base veut dire « la ligne n'existe pas » ; sur la courbe, « niveau maximum ». */
    private static function show(?int $value): string
    {
        return null === $value ? '—' : (string) $value;
    }
}
