<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Tests\Support\ApiTestCase;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * #250 : la famille tombe dans les deux cas — voir le docblock de `RefreshSessionHandler`
 * pour pourquoi la détection de rejeu ne s'assouplit pas. Ce qui change, c'est que le
 * `warning` qui accompagne la révocation dit maintenant *pourquoi* : reconstruire la chaîne
 * à la main en SQL après coup, comme il a fallu le faire le 2026-09-01, ne doit plus être
 * nécessaire.
 *
 * Même montage que `DomainErrorLogLevelTest` : un `TestHandler` poussé sur le canal par
 * défaut (`monolog.logger`, celui qu'obtient `Psr\Log\LoggerInterface` sans channel
 * explicite) à l'exécution, plutôt que déclaré dans `monolog.yaml` — un handler de capture
 * sous `when@test` n'existerait pas dans le conteneur `dev`, contre lequel PHPStan analyse
 * `tests/`.
 */
final class RefreshTokenReplayLoggingTest extends ApiTestCase
{
    private const string EMAIL = 'bob@grrind.app';
    private const string PASSWORD = 'un-mot-de-passe-assez-long';

    public function testTheWarningNamesTheSuccessorNeverServedVerdict(): void
    {
        $records = $this->captureLogs();

        $first = $this->openSession();

        // Une seule rotation, puis le rejeu immédiat de l'original : le successeur n'a
        // jamais servi.
        $this->post('/api/auth/refresh', ['refreshToken' => $first]);
        $replay = $this->post('/api/auth/refresh', ['refreshToken' => $first]);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $replay->getStatusCode());
        $this->assertVerdictWasLogged($records, 'successeur jamais consommé');
    }

    public function testTheWarningNamesTheSuccessorAlreadyServedVerdict(): void
    {
        $records = $this->captureLogs();

        $first = $this->openSession();

        $second = self::decode($this->post('/api/auth/refresh', ['refreshToken' => $first]))['tokens'];
        self::assertIsArray($second);
        self::assertIsString($second['refreshToken']);

        // Le successeur de $first a lui-même déjà servi à rotater une deuxième fois.
        $this->post('/api/auth/refresh', ['refreshToken' => $second['refreshToken']]);
        $replay = $this->post('/api/auth/refresh', ['refreshToken' => $first]);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $replay->getStatusCode());
        $this->assertVerdictWasLogged($records, 'successeur déjà consommé');
    }

    /**
     * Ligne rouge du ticket : jamais le secret du jeton dans le journal, ni en clair, ni
     * tronqué, ni haché — seuls des identifiants de lignes et le verdict.
     */
    public function testTheWarningNeverCarriesTheTokenSecret(): void
    {
        $records = $this->captureLogs();

        $first = $this->openSession();
        $this->post('/api/auth/refresh', ['refreshToken' => $first]);
        $this->post('/api/auth/refresh', ['refreshToken' => $first]);

        $carriesTheSecret = $records->hasWarningThatPasses(
            static function (LogRecord $record) use ($first): bool {
                if (str_contains($record->message, $first)) {
                    return true;
                }

                foreach ($record->context as $value) {
                    if (\is_string($value) && str_contains($value, $first)) {
                        return true;
                    }
                }

                return false;
            },
        );

        self::assertFalse($carriesTheSecret, 'Le refresh token ne doit jamais apparaître dans le journal.');
    }

    private function captureLogs(): TestHandler
    {
        $records = new TestHandler();
        self::getContainer()->get('monolog.logger')->pushHandler($records);

        // Sans ça, le navigateur de test redémarre le noyau entre deux requêtes et
        // jetterait le handler qu'on vient de poser.
        $this->client->disableReboot();

        return $records;
    }

    private function assertVerdictWasLogged(TestHandler $records, string $verdict): void
    {
        $matched = $records->hasWarningThatPasses(
            static function (LogRecord $record) use ($verdict): bool {
                if ('Rejeu détecté sur un refresh token : famille révoquée.' !== $record->message) {
                    return false;
                }

                $context = $record->context;
                $presentedTokenId = $context['presentedTokenId'] ?? null;
                $familyId = $context['familyId'] ?? null;

                return ($context['verdict'] ?? null) === $verdict
                    && \is_string($presentedTokenId) && Uuid::isValid($presentedTokenId)
                    && \is_string($familyId) && Uuid::isValid($familyId);
            },
        );

        self::assertTrue($matched, "Le journal aurait dû porter le verdict « {$verdict} ».");
    }

    private function openSession(): string
    {
        $this->register();

        return $this->logIn();
    }

    private function register(): void
    {
        $this->post('/api/auth/register', [
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
            'displayName' => 'Bob',
            'timezone' => 'Europe/Paris',
        ]);
    }

    private function logIn(): string
    {
        $tokens = self::decode($this->post('/api/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD]))['tokens'];
        self::assertIsArray($tokens);
        self::assertIsString($tokens['refreshToken']);

        return $tokens['refreshToken'];
    }
}
