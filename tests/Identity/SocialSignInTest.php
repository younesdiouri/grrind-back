<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Tests\Support\ApiTestCase;
use App\Tests\Support\StubSocialProfileResolver as Stub;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'échange OAuth lui-même est bouchonné — voir StubSocialProfileResolver. Ce qui
 * est vérifié ici, c'est la partie qui nous appartient : reconnaître, relier, créer,
 * et refuser.
 */
final class SocialSignInTest extends ApiTestCase
{
    private const string PASSWORD = 'un-mot-de-passe-assez-long';

    #[DataProvider('providers')]
    public function testCreatesAnAccountOnFirstSignIn(string $provider): void
    {
        $response = $this->signIn($provider, Stub::codeFor('sub-1', 'bob@grrind.app', displayName: 'Bob'));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $body = self::decode($response);
        self::assertIsArray($body['user']);
        self::assertIsArray($body['tokens']);

        self::assertSame('bob@grrind.app', $body['user']['email']);
        self::assertSame('Bob', $body['user']['displayName']);
        self::assertSame('Europe/Paris', $body['user']['timezone']);
        self::assertNotEmpty($body['tokens']['accessToken']);
        self::assertNotEmpty($body['tokens']['refreshToken']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function providers(): iterable
    {
        yield 'google' => ['google'];
        yield 'apple' => ['apple'];
    }

    public function testTheSecondSignInFindsTheSameAccount(): void
    {
        $code = Stub::codeFor('sub-1', 'bob@grrind.app', displayName: 'Bob');

        $first = self::decode($this->signIn('google', $code));
        $second = self::decode($this->signIn('google', $code));

        self::assertIsArray($first['user']);
        self::assertIsArray($second['user']);
        self::assertSame($first['user']['id'], $second['user']['id']);
    }

    public function testANewAddressOnAKnownSubjectStillFindsTheSameAccount(): void
    {
        // Le joueur a changé d'adresse chez le fournisseur. Le `sub` n'a pas bougé :
        // c'est le même compte, pas un nouveau.
        $first = self::decode($this->signIn('apple', Stub::codeFor('sub-1', 'bob@grrind.app')));
        $second = self::decode($this->signIn('apple', Stub::codeFor('sub-1', 'robert@grrind.app')));

        self::assertIsArray($first['user']);
        self::assertIsArray($second['user']);
        self::assertSame($first['user']['id'], $second['user']['id']);
        self::assertSame('bob@grrind.app', $second['user']['email'], 'Le fournisseur ne réécrit pas le profil.');
    }

    public function testLinksAVerifiedAddressToAnExistingPasswordAccount(): void
    {
        $registered = self::decode($this->register('bob@grrind.app'));
        self::assertIsArray($registered['user']);

        $signedIn = self::decode($this->signIn('google', Stub::codeFor('sub-1', 'bob@grrind.app')));
        self::assertIsArray($signedIn['user']);

        // Sans ça, le joueur inscrit par mot de passe qui revient par Google se
        // retrouverait avec deux comptes et une progression coupée en deux.
        self::assertSame($registered['user']['id'], $signedIn['user']['id']);
    }

    public function testRefusesToLinkAnUnverifiedAddress(): void
    {
        $this->register('bob@grrind.app');

        $response = $this->signIn('google', Stub::codeFor('sub-1', 'bob@grrind.app', emailVerified: false));

        // Relier sans preuve de possession offrirait le compte à quiconque sait
        // créer une adresse chez le fournisseur.
        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame(
            'https://grrind.app/problems/email-belongs-to-another-account',
            self::decode($response)['type'],
        );
    }

    public function testTwoProvidersOnTheSameVerifiedAddressShareTheAccount(): void
    {
        $google = self::decode($this->signIn('google', Stub::codeFor('google-sub', 'bob@grrind.app')));
        $apple = self::decode($this->signIn('apple', Stub::codeFor('apple-sub', 'bob@grrind.app')));

        self::assertIsArray($google['user']);
        self::assertIsArray($apple['user']);
        self::assertSame($google['user']['id'], $apple['user']['id']);
    }

    public function testTheSameSubjectOnTwoProvidersIsTwoDifferentPeople(): void
    {
        $google = self::decode($this->signIn('google', Stub::codeFor('collision', 'alice@grrind.app')));
        $apple = self::decode($this->signIn('apple', Stub::codeFor('collision', 'bob@grrind.app')));

        // Le `sub` n'est unique que chez son fournisseur : la clé est le couple.
        self::assertIsArray($google['user']);
        self::assertIsArray($apple['user']);
        self::assertNotSame($google['user']['id'], $apple['user']['id']);
    }

    public function testAnAccountCreatedThisWayCannotLogInWithAPassword(): void
    {
        $this->signIn('apple', Stub::codeFor('sub-1', 'bob@grrind.app'));

        $response = $this->post('/api/auth/login', ['email' => 'bob@grrind.app', 'password' => self::PASSWORD]);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/invalid-credentials', self::decode($response)['type']);
    }

    public function testTheSessionOpenedThisWayWorksOnAuthenticatedRoutes(): void
    {
        $tokens = self::decode($this->signIn('google', Stub::codeFor('sub-1', 'bob@grrind.app', displayName: 'Bob')))['tokens'];
        self::assertIsArray($tokens);
        self::assertIsString($tokens['accessToken']);

        $response = $this->get('/api/me', ['Authorization' => 'Bearer '.$tokens['accessToken']]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('bob@grrind.app', self::decode($response)['email']);
    }

    public function testFallsBackOnTheLocalPartWhenTheProviderGivesNoName(): void
    {
        // Apple ne donne le nom qu'à la toute première autorisation, jamais ensuite.
        $body = self::decode($this->signIn('apple', Stub::codeFor('sub-1', 'bob.martin@grrind.app')));

        self::assertIsArray($body['user']);
        self::assertSame('bob.martin', $body['user']['displayName']);
    }

    public function testRefusesAProfileWithoutAnyAddress(): void
    {
        $response = $this->signIn('apple', Stub::codeFor('sub-1', email: null));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame(
            'https://grrind.app/problems/social-profile-incomplete',
            self::decode($response)['type'],
        );
    }

    public function testRefusesACodeTheProviderRejects(): void
    {
        $response = $this->signIn('google', 'ce-code-a-expire');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame(
            'https://grrind.app/problems/social-sign-in-rejected',
            self::decode($response)['type'],
        );
    }

    public function testRejectsAnUnknownProvider(): void
    {
        $response = $this->signIn('facebook', Stub::codeFor('sub-1', 'bob@grrind.app'));

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    public function testRejectsAMissingCode(): void
    {
        $response = $this->post('/api/auth/social/google', [
            'redirectUri' => 'app.grrind://auth/google',
            'timezone' => 'Europe/Paris',
        ]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertContains('code', array_column((array) self::decode($response)['violations'], 'field'));
    }

    private function signIn(string $provider, string $code): Response
    {
        return $this->post('/api/auth/social/'.$provider, [
            'code' => $code,
            'redirectUri' => 'app.grrind://auth/'.$provider,
            'timezone' => 'Europe/Paris',
        ]);
    }

    private function register(string $email): Response
    {
        return $this->post('/api/auth/register', [
            'email' => $email,
            'password' => self::PASSWORD,
            'displayName' => 'Bob',
            'timezone' => 'Europe/Paris',
        ]);
    }
}
