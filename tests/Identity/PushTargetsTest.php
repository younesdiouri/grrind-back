<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Identity\Domain\DeviceEnvironment;
use App\Identity\Infrastructure\Doctrine\UserDeviceRepository;
use App\Shared\Application\PushTargets;
use App\Shared\Domain\NotificationCategory;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\SpyingLogger;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le câblage du port {@see PushTargets} : c'est le seul test qui le prouve, comme
 * `DailyLoadTest` est le seul à prouver celui de `PlayerTimezones`.
 *
 * Ce qui est vérifié ici précisément : filtrer par environnement est fait une fois, dans
 * l'implémentation, et non laissé à la charge d'un futur consommateur — voir le docblock
 * du port pour le pourquoi. Depuis le #132, la préférence du compte est le même genre de
 * règle de plateforme : tranchée dans l'implémentation, pas chez l'appelant.
 *
 * **#149 : la suite vise `PUSH_TARGET_ENVIRONMENT`, le même réglage que la prod.** Rien
 * dans `.env.test` ne le surcharge — c'est `EXPO_DSN=null://null`, forcé sans condition
 * dans `config/packages/notifier.yaml` et prouvé par `PushSenderWiringTest`, qui garantit
 * qu'aucune suite n'appelle Expo, pas ce filtre-ci. `.env` fixe `PUSH_TARGET_ENVIRONMENT`
 * à `PRODUCTION`, donc c'est ce que ce test vise.
 */
final class PushTargetsTest extends ApiTestCase
{
    public function testOnlyRendersTokensOfTheTargetedEnvironment(): void
    {
        $bob = $this->openAccount();

        $this->post('/api/devices', [
            'pushToken' => 'dev-token',
            'platform' => 'IOS',
            'environment' => 'DEVELOPMENT',
        ], $bob->headers);

        $this->post('/api/devices', [
            'pushToken' => 'prod-token',
            'platform' => 'IOS',
            'environment' => 'PRODUCTION',
        ], $bob->headers);

        $targets = self::getContainer()->get(PushTargets::class);
        self::assertInstanceOf(PushTargets::class, $targets);

        // `PUSH_TARGET_ENVIRONMENT` vaut `PRODUCTION` (`.env`), donc seul `prod-token`
        // doit revenir — jamais les deux, et jamais celui de l'environnement non visé.
        self::assertSame(['prod-token'], $targets->of($bob->id, NotificationCategory::GuildActivity));
    }

    public function testRendersNothingForAPlayerWithNoDevice(): void
    {
        $bob = $this->openAccount();

        $targets = self::getContainer()->get(PushTargets::class);
        self::assertInstanceOf(PushTargets::class, $targets);

        self::assertSame([], $targets->of($bob->id, NotificationCategory::GuildActivity));
    }

    /**
     * Le lien que le #132 pose explicitement : un joueur qui a coupé la catégorie n'est
     * pas une cible qu'on filtre à l'envoi, il n'en est simplement pas une.
     */
    public function testRendersNothingForAPlayerWhoDisabledTheCategory(): void
    {
        $bob = $this->openAccount();

        $this->post('/api/devices', [
            'pushToken' => 'prod-token',
            'platform' => 'IOS',
            'environment' => 'PRODUCTION',
        ], $bob->headers);

        $response = $this->send('PATCH', '/api/me', [
            'notificationPreferences' => [
                ['category' => 'GUILD_ACTIVITY', 'enabled' => false],
            ],
        ], $bob->headers);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $targets = self::getContainer()->get(PushTargets::class);
        self::assertInstanceOf(PushTargets::class, $targets);

        self::assertSame([], $targets->of($bob->id, NotificationCategory::GuildActivity));
    }

    /**
     * Le `warning` du #149 : un joueur a des appareils, mais aucun dans l'environnement
     * visé — exactement le bug constaté sur un vrai iPhone qui a ouvert ce ticket, jamais
     * confondu avec « aucun appareil » ou « catégorie coupée », qui restent en `info`.
     *
     * Construit son propre `UserDeviceRepository` visant `DEVELOPMENT` plutôt que de
     * tordre l'environnement du conteneur : le joueur enregistre un appareil `PRODUCTION`
     * (la route par défaut), qui devient alors l'appareil « dans le mauvais
     * environnement » pour ce repository-ci.
     */
    public function testMissingEnvironmentTargetLogsAWarning(): void
    {
        $bob = $this->openAccount();

        $this->post('/api/devices', [
            'pushToken' => 'prod-token',
            'platform' => 'IOS',
            'environment' => 'PRODUCTION',
        ], $bob->headers);

        $registry = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);

        $logger = new SpyingLogger();
        $targets = new UserDeviceRepository($registry, DeviceEnvironment::Development, $logger);

        self::assertSame([], $targets->of($bob->id, NotificationCategory::GuildActivity));

        $warnings = array_values(array_filter($logger->records, static fn (array $record): bool => 'warning' === $record['level']));
        self::assertCount(1, $warnings, 'Exactement un warning : ni un par appareil trouvé, ni un silence.');

        $warning = $warnings[0];
        self::assertSame($bob->id->toRfc4122(), $warning['context']['userId']);
        self::assertSame(NotificationCategory::GuildActivity->value, $warning['context']['category']);
        self::assertSame(DeviceEnvironment::Development->value, $warning['context']['targetEnvironment']);
        self::assertSame([DeviceEnvironment::Production->value], $warning['context']['foundEnvironments']);
    }
}
