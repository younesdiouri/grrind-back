<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\Risala;
use App\Community\Domain\RisalaRules;
use App\Community\Infrastructure\Doctrine\RisalaRepository;
use App\Shared\Application\ModifierContributor;
use App\Shared\Domain\Modifier\Modifier;
use App\Shared\Domain\Modifier\ModifierSource;
use App\Shared\Domain\Modifier\ModifierType;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Uid\Uuid;

/**
 * Ce que les Risālāt de sa guilde valent à un joueur : **+150 % au destinataire, +50 % à
 * l'expéditeur**, sur la discipline demandée et sur elle seule.
 *
 * ## La guilde entre dans le moteur par la porte prévue
 *
 * Le docblock de {@see \App\Community\Domain\Guild} l'écrivait avant que ce soit vrai : « le
 * jour où elle produira une valeur de jeu, ce sera par le vocabulaire de modificateurs
 * existant — un `ModifierContributor` de plus — et pas par une porte dérobée qui lui serait
 * propre ». C'est ce jour-là, et rien du moteur ne s'ouvre pour l'accueillir : cette classe
 * est taguée par la seule vertu d'implémenter l'interface, personne ne la câble, et
 * `XpCalculator` ne sait pas qu'elle existe.
 *
 * ## Les deux taux, et pourquoi ils ne sont pas égaux
 *
 * Un expéditeur aussi bien servi que ceux qu'il défie choisirait le sport qu'il pratique
 * déjà, et la Risāla cesserait de faire découvrir quoi que ce soit. À +50 %, il n'y gagne
 * vraiment qu'en emmenant les autres avec lui. {@see RisalaRules} refuse de démarrer sur un
 * barème qui inverserait ça.
 *
 * ## À la date du sport, jamais à celle de la synchronisation
 *
 * C'est la première source réellement bornée dans le temps, et c'est elle qui a motivé le
 * #190 : sans la date, un rattrapage de dix jours verrait toutes ses séances bonifiées par la
 * Risāla du jour de la synchronisation. Ici, chaque séance est jugée sur la fenêtre qui la
 * couvrait vraiment.
 *
 * **L'appartenance, elle, se lit au présent.** Un joueur qui a quitté sa guilde ne touche plus
 * rien, même sur une séance datée du temps où il en était membre. C'est cohérent avec tout le
 * module — la guilde d'un joueur n'a jamais d'historique — et c'est le seul choix qui ne
 * demande pas de conserver une adhésion révolue uniquement pour arbitrer un bonus.
 */
final readonly class RisalaModifiers implements ModifierContributor
{
    public function __construct(
        private RisalaRepository $risalat,
        private RisalaRules $rules,
    ) {
    }

    public function modifiersOf(Uuid $userId, DateTimeImmutable $occurredAt): array
    {
        return array_map(
            fn (Risala $risala): Modifier => new Modifier(
                ModifierType::XpMultiplier,
                $risala->senderId()->equals($userId) ? $this->rules->senderBonusPercent : $this->rules->recipientBonusPercent,
                ModifierSource::Guild,
                // Une Risāla révélée porte toujours sa discipline — `Risala::seal()` efface
                // celle d'un tour manqué et ne révèle jamais sans elle. Un `??` silencieux
                // rendrait un modificateur global, donc un bonus sur *toutes* les
                // disciplines : mieux vaut refuser la séance que de créditer ça au ledger.
                $risala->discipline() ?? throw new LogicException(\sprintf('Risāla %s révélée sans discipline.', $risala->id())),
            ),
            $this->risalat->liveForPlayer($userId, $occurredAt),
        );
    }
}
