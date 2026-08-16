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
        self::assertSame(30, new GuildRules(30)->maximumMembers);
    }

    /**
     * Deux est le vrai plancher : le fondateur occupe une place, donc à un seul membre
     * la guilde est pleine dès sa création et personne ne peut jamais la rejoindre.
     */
    public function testRefusesAGuildNobodyCouldEverJoin(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GuildRules(1);
    }

    public function testRefusesANonsensicalCapacity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new GuildRules(0);
    }
}
