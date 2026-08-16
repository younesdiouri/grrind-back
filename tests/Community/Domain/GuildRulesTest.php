<?php

declare(strict_types=1);

namespace App\Tests\Community\Domain;

use App\Community\Domain\GuildRules;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * L'équilibrage se valide au démarrage, pas à la première requête d'un joueur : c'est
 * cette classe que {@see \App\Community\Infrastructure\Config\CommunitySection} fait
 * rejouer à la compilation du conteneur.
 */
final class GuildRulesTest extends TestCase
{
    public function testAcceptsAUsableCapacity(): void
    {
        self::assertSame(30, new GuildRules(30, 48)->maximumMembers);
        self::assertSame(48, new GuildRules(30, 48)->inviteCodeLifetimeHours);
    }

    /**
     * Deux est le vrai plancher : le fondateur occupe une place, donc à un seul membre
     * la guilde est pleine dès sa création et personne ne peut jamais la rejoindre.
     */
    public function testRefusesAGuildNobodyCouldEverJoin(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GuildRules(1, 48);
    }

    public function testRefusesANonsensicalCapacity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GuildRules(0, 48);
    }

    /**
     * Un code qui expire à l'instant où il naît ne peut pas circuler : il se partage hors
     * de l'app, et le temps de l'envoyer est déjà passé.
     */
    public function testRefusesAnInviteCodeThatCouldNotCirculate(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GuildRules(30, 0);
    }

    public function testTheLifetimeIsCountedOnTheCalendar(): void
    {
        // Un `DateInterval` et non des secondes : l'addition passe par le calendrier, donc
        // un changement d'heure ne raccourcit ni n'allonge la validité d'un code.
        $lifetime = new GuildRules(30, 48)->inviteCodeLifetime();

        self::assertSame(48, $lifetime->h);
        self::assertSame(0, $lifetime->d);
    }
}
