<?php

declare(strict_types=1);

namespace App\Community\Domain;

use App\Shared\Application\GameRulesets;
use App\Shared\Domain\RuntimeRuleset;
use DateInterval;
use InvalidArgumentException;

/**
 * Ce qu'une guilde peut contenir. De l'équilibrage, pas une constante de classe : la
 * bonne taille d'un groupe est une question de produit qui bougera après les premiers
 * joueurs, et elle se règle dans `config/game/v1/community.yaml` sans toucher au code.
 *
 * L'objet existe pour que le domaine reçoive une valeur *validée* plutôt qu'un entier
 * nu venu d'un YAML : la cohérence se dit une seule fois, ici, et
 * {@see \App\Community\Infrastructure\Config\CommunitySection} la fait rejouer à la
 * compilation du conteneur.
 */
final class GuildRules
{
    use RuntimeRuleset;

    public function __construct(
        public int $maximumMembers,
        public int $inviteCodeLifetimeHours,
        ?GameRulesets $rulesets = null,
    ) {
        $this->useRuntimeRulesets($rulesets);
        // Une guilde d'un seul membre est un profil avec plus d'étapes : le fondateur
        // occupe déjà une place, donc en dessous de deux personne ne peut le rejoindre.
        if ($maximumMembers < 2) {
            throw new InvalidArgumentException(\sprintf('Une guilde doit pouvoir accueillir au moins deux membres, %d demandé.', $maximumMembers));
        }

        // Un code qui expire à l'instant où il est créé ne peut pas circuler : il se
        // partage hors de l'app, et le temps de l'envoyer est déjà passé.
        if ($inviteCodeLifetimeHours < 1) {
            throw new InvalidArgumentException(\sprintf('Un code d\'invitation doit vivre au moins une heure, %d demandée(s).', $inviteCodeLifetimeHours));
        }
    }

    public static function runtime(GameRulesets $rulesets): self
    {
        return self::fromSnapshot($rulesets->snapshot(), $rulesets);
    }

    public function maximumMembers(): int
    {
        return $this->isRuntimeRuleset() ? $this->runtimeValue()->maximumMembers() : $this->maximumMembers;
    }

    public function inviteCodeLifetimeHours(): int
    {
        return $this->isRuntimeRuleset() ? $this->runtimeValue()->inviteCodeLifetimeHours() : $this->inviteCodeLifetimeHours;
    }

    /**
     * La durée sous la forme que {@see GuildInviteCode::issueFor()} consomme. Un
     * `DateInterval` et non un nombre de secondes : l'addition passe par le calendrier,
     * donc un changement d'heure ne raccourcit ni n'allonge la validité d'un code.
     */
    public function inviteCodeLifetime(): DateInterval
    {
        return new DateInterval(\sprintf('PT%dH', $this->inviteCodeLifetimeHours()));
    }

    /** @param array<string, mixed> $snapshot */
    private static function fromSnapshot(array $snapshot, ?GameRulesets $rulesets = null): self
    {
        /** @var array{maximum_members: int, invite_code_lifetime_hours: int} $community */
        $community = $snapshot['community'];

        return new self($community['maximum_members'], $community['invite_code_lifetime_hours'], $rulesets);
    }
}
