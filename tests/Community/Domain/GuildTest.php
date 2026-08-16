<?php

declare(strict_types=1);

namespace App\Tests\Community\Domain;

use App\Community\Domain\Exception\GuildIsFull;
use App\Community\Domain\Exception\PlayerAlreadyInAGuild;
use App\Community\Domain\Exception\PlayerIsNotAMember;
use App\Community\Domain\Guild;
use App\Community\Domain\GuildRole;
use App\Community\Domain\GuildRules;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * L'agrégat sans aucune infrastructure : ni base, ni conteneur, ni HTTP. Ce qui se
 * démontre ici, ce sont les seules règles que la guilde peut prononcer seule — le
 * plafond et « on n'entre pas deux fois ».
 *
 * La règle « un joueur n'appartient qu'à *une* guilde » n'y est pas, et c'est délibéré :
 * une guilde ne voit pas les autres. C'est l'index unique qui la tient, donc c'est contre
 * une vraie base qu'elle se prouve — voir {@see \App\Tests\Community\GuildMembershipUniquenessTest}.
 */
final class GuildTest extends TestCase
{
    private const string NOW = '2026-08-16T10:00:00+00:00';

    public function testFoundingAGuildMakesItsFounderAMember(): void
    {
        $founder = Uuid::v7();

        $guild = Guild::found('Les Lève-Tôt', $founder, self::at(self::NOW));

        self::assertSame(1, $guild->size(), 'Fonder une guilde, c\'est y entrer.');
        self::assertTrue($guild->hasMember($founder));
        self::assertTrue($guild->isFoundedBy($founder));
        self::assertSame(GuildRole::Founder, $guild->membershipOf($founder)?->role());
        self::assertSame($founder->toRfc4122(), $guild->createdBy()->toRfc4122());
    }

    public function testAnAdmittedPlayerIsAPlainMember(): void
    {
        $guild = Guild::found('Les Lève-Tôt', Uuid::v7(), self::at(self::NOW));
        $newcomer = Uuid::v7();

        $membership = $guild->admit($newcomer, new GuildRules(30, 48), self::at('2026-08-16T11:00:00+00:00'));

        self::assertSame(GuildRole::Member, $membership->role());
        self::assertFalse($membership->isFounder());
        self::assertFalse($guild->isFoundedBy($newcomer));
        self::assertSame(2, $guild->size());
    }

    public function testRefusesAMemberBeyondTheCapacity(): void
    {
        // Le fondateur occupe déjà une place : à trois de plafond, il reste deux entrées.
        $rules = new GuildRules(3, 48);
        $guild = Guild::found('Les Lève-Tôt', Uuid::v7(), self::at(self::NOW));

        $guild->admit(Uuid::v7(), $rules, self::at(self::NOW));
        $guild->admit(Uuid::v7(), $rules, self::at(self::NOW));

        $this->expectException(GuildIsFull::class);

        $guild->admit(Uuid::v7(), $rules, self::at(self::NOW));
    }

    public function testTheFounderCountsAgainstTheCapacity(): void
    {
        $guild = Guild::found('Les Lève-Tôt', Uuid::v7(), self::at(self::NOW));

        $guild->admit(Uuid::v7(), new GuildRules(2, 48), self::at(self::NOW));

        $this->expectException(GuildIsFull::class);

        $guild->admit(Uuid::v7(), new GuildRules(2, 48), self::at(self::NOW));
    }

    public function testRefusesAPlayerWhoIsAlreadyIn(): void
    {
        $guild = Guild::found('Les Lève-Tôt', Uuid::v7(), self::at(self::NOW));
        $member = Uuid::v7();
        $guild->admit($member, new GuildRules(30, 48), self::at(self::NOW));

        $this->expectException(PlayerAlreadyInAGuild::class);

        $guild->admit($member, new GuildRules(30, 48), self::at(self::NOW));
    }

    public function testTheFounderCannotBeAdmittedAgain(): void
    {
        $founder = Uuid::v7();
        $guild = Guild::found('Les Lève-Tôt', $founder, self::at(self::NOW));

        $this->expectException(PlayerAlreadyInAGuild::class);

        $guild->admit($founder, new GuildRules(30, 48), self::at(self::NOW));
    }

    public function testAFullGuildRefusesEvenBeforeLookingAtTheNewcomer(): void
    {
        $rules = new GuildRules(2, 48);
        $guild = Guild::found('Les Lève-Tôt', Uuid::v7(), self::at(self::NOW));
        $guild->admit(Uuid::v7(), $rules, self::at(self::NOW));

        // Une guilde pleine reste pleine : le refus ne doit pas laisser d'adhésion derrière lui.
        try {
            $guild->admit(Uuid::v7(), $rules, self::at(self::NOW));
        } catch (GuildIsFull) {
        }

        self::assertSame(2, $guild->size());
    }

    public function testListsTheFounderFirstThenByJoinDate(): void
    {
        $founder = Uuid::v7();
        $guild = Guild::found('Les Lève-Tôt', $founder, self::at('2026-08-16T10:00:00+00:00'));

        // Admis dans le désordre : c'est la date d'entrée qui doit gouverner, pas l'ordre
        // des appels — un import ou une reprise peut les présenter autrement.
        $late = Uuid::v7();
        $early = Uuid::v7();
        $guild->admit($late, new GuildRules(30, 48), self::at('2026-08-18T10:00:00+00:00'));
        $guild->admit($early, new GuildRules(30, 48), self::at('2026-08-17T10:00:00+00:00'));

        $order = array_map(
            static fn ($membership): string => $membership->playerId()->toRfc4122(),
            $guild->members(),
        );

        self::assertSame([$founder->toRfc4122(), $early->toRfc4122(), $late->toRfc4122()], $order);
    }

    public function testTheFounderComesFirstEvenWhenHeIsNotTheOldest(): void
    {
        // Le cas qui arrive après une succession (#118) : le fondateur du jour est entré
        // après quelqu'un qui est encore là.
        $guild = Guild::found('Les Lève-Tôt', Uuid::v7(), self::at('2026-08-20T10:00:00+00:00'));
        $older = Uuid::v7();
        $guild->admit($older, new GuildRules(30, 48), self::at('2026-08-10T10:00:00+00:00'));

        self::assertTrue($guild->members()[0]->isFounder());
    }

    public function testAPlainMemberLeavesWithoutTouchingTheRest(): void
    {
        $founder = Uuid::v7();
        $guild = Guild::found('Les Lève-Tôt', $founder, self::at(self::NOW));
        $member = Uuid::v7();
        $guild->admit($member, new GuildRules(30, 48), self::at(self::NOW));

        self::assertFalse($guild->part($member), 'Il reste le fondateur : rien à dissoudre.');
        self::assertFalse($guild->hasMember($member));
        self::assertTrue($guild->isFoundedBy($founder));
        self::assertSame(1, $guild->size());
    }

    /**
     * La règle du #118 : une seule, et aucune désignation manuelle. C'est ce qui rend le
     * départ du fondateur possible sans qu'il emporte la guilde avec lui.
     */
    public function testTheFounderHandsOverToTheOldestMember(): void
    {
        $guild = Guild::found('Les Lève-Tôt', $founder = Uuid::v7(), self::at('2026-08-16T10:00:00+00:00'));

        $late = Uuid::v7();
        $early = Uuid::v7();
        $guild->admit($late, new GuildRules(30, 48), self::at('2026-08-18T10:00:00+00:00'));
        $guild->admit($early, new GuildRules(30, 48), self::at('2026-08-17T10:00:00+00:00'));

        self::assertFalse($guild->part($founder));

        self::assertTrue($guild->isFoundedBy($early), 'Le doyen des restants hérite, quel que soit l\'ordre des arrivées.');
        self::assertFalse($guild->isFoundedBy($late));
        self::assertSame(2, $guild->size());
    }

    /**
     * L'ordre est ce qui fait la différence : promouvoir avant de retirer ferait du
     * partant le doyen à succéder à lui-même.
     */
    public function testTheDepartingFounderIsNeverHisOwnSuccessor(): void
    {
        // Le fondateur est le plus ancien de tous — le cas nominal, puisqu'il a fondé.
        $guild = Guild::found('Les Lève-Tôt', $founder = Uuid::v7(), self::at('2026-08-01T10:00:00+00:00'));
        $member = Uuid::v7();
        $guild->admit($member, new GuildRules(30, 48), self::at('2026-08-10T10:00:00+00:00'));

        $guild->part($founder);

        self::assertFalse($guild->hasMember($founder));
        self::assertTrue($guild->isFoundedBy($member));
    }

    public function testTheLastMemberLeavingEmptiesTheGuild(): void
    {
        $founder = Uuid::v7();
        $guild = Guild::found('Les Lève-Tôt', $founder, self::at(self::NOW));

        self::assertTrue($guild->part($founder), 'Une guilde vide n\'a personne pour la dissoudre : c\'est au partant de le dire.');
        self::assertSame(0, $guild->size());
    }

    public function testAStrangerCannotBePushedOut(): void
    {
        $guild = Guild::found('Les Lève-Tôt', Uuid::v7(), self::at(self::NOW));

        $this->expectException(PlayerIsNotAMember::class);

        $guild->part(Uuid::v7());
    }

    /** Une place libérée est une place reprise : le plafond compte les présents. */
    public function testLeavingReopensASeat(): void
    {
        $rules = new GuildRules(2, 48);
        $guild = Guild::found('Les Lève-Tôt', Uuid::v7(), self::at(self::NOW));
        $member = Uuid::v7();
        $guild->admit($member, $rules, self::at(self::NOW));

        $guild->part($member);
        $guild->admit(Uuid::v7(), $rules, self::at(self::NOW));

        self::assertSame(2, $guild->size());
    }

    public function testRenamingTrimsWithoutTouchingTheMembers(): void
    {
        $guild = Guild::found('Les Lève-Tôt', Uuid::v7(), self::at(self::NOW));

        $guild->rename('  Les Couche-Tard  ');

        self::assertSame('Les Couche-Tard', $guild->name());
        self::assertSame(1, $guild->size());
    }

    private static function at(string $instant): DateTimeImmutable
    {
        return new DateTimeImmutable($instant);
    }
}
