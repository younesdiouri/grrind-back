<?php

declare(strict_types=1);

namespace App\Admin\UI\Console;

use DateTimeImmutable;
use DateTimeInterface;
use LogicException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Démo locale explicite : seules les API métier créent comptes, guilde et séances fictives.
 * Aucun crédit direct de jauge/loot ; le raid lancé depuis l'app reste le véritable arbitre.
 * Les identifiants générés sont écrits dans var, jamais affichés ni versionnés.
 */
#[AsCommand(name: 'app:alam:demo', description: 'Crée deux comptes de démonstration locaux avec une guilde et des séances variées.')]
final class AlamDemoCommand extends Command
{
    public function __construct(private readonly HttpClientInterface $http, #[Autowire('%kernel.environment%')] private readonly string $environment, #[Autowire('%kernel.project_dir%')] private readonly string $projectDirectory)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ('dev' !== $this->environment) {
            throw new LogicException('Cette démonstration est réservée à l’environnement dev local.');
        }
        $file = $this->projectDirectory.'/var/alam-demo-credentials.json';
        if (is_file($file)) {
            $output->writeln('Une démonstration existe déjà : var/alam-demo-credentials.json.');

            return Command::SUCCESS;
        }
        $now = new DateTimeImmutable();
        $suffix = bin2hex(random_bytes(4));
        $accounts = [];
        $tokens = [];
        $filesystem = new Filesystem();
        foreach (['A', 'B'] as $index => $name) {
            $email = 'alam-demo-'.strtolower($name).'-'.$suffix.'@example.test';
            $password = bin2hex(random_bytes(16));
            $registered = $this->request('POST', '/api/auth/register', ['email' => $email, 'password' => $password, 'displayName' => 'Démo Alam '.$name, 'timezone' => 'Europe/Paris']);
            /** @var array{user: array{id: string}, tokens: array{accessToken: string}} $registered */
            $accounts[] = ['email' => $email, 'password' => $password, 'id' => $registered['user']['id'], 'accessToken' => $registered['tokens']['accessToken']];
            $tokens[$index] = $registered['tokens']['accessToken'];
        }
        $filesystem->dumpFile($file, json_encode(['baseUrl' => 'http://localhost:8080', 'accounts' => $accounts], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));
        $filesystem->chmod($file, 0o600);
        $guild = $this->request('POST', '/api/guilds', ['name' => 'Alam Démo '.$suffix], $tokens[0]);
        $guildId = $guild['id'];
        \assert(\is_string($guildId));
        $invite = $this->request('POST', '/api/guilds/'.$guildId.'/invite-code', [], $tokens[0]);
        $this->request('POST', '/api/guilds/join', ['code' => $invite['code']], $tokens[1]);
        foreach ($tokens as $token) {
            $workouts = [];
            foreach (['hiking', 'traditionalStrengthTraining', 'running', 'yoga', 'tennis'] as $index => $activity) {
                $start = $now->modify('-9 hours')->modify('+'.($index * 95).' minutes');
                $workouts[] = ['externalId' => 'alam-demo-'.Uuid::v7()->toRfc4122(), 'source' => 'APPLE_HEALTH', 'activityType' => $activity, 'startedAt' => $start->format(DateTimeInterface::ATOM), 'endedAt' => $start->modify('+90 minutes')->format(DateTimeInterface::ATOM), 'distanceMeters' => 'hiking' === $activity ? 10000 : null, 'elevationGainMeters' => 'hiking' === $activity ? 2000 : null];
            }
            $this->request('POST', '/api/workouts/import', ['workouts' => $workouts], $token);
        }
        $alam = $this->request('GET', '/api/guild/alam', [], $tokens[0]);
        $filesystem->dumpFile($file, json_encode(['baseUrl' => 'http://localhost:8080', 'accounts' => $accounts, 'guildId' => $guildId, 'alam' => $alam], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));
        $filesystem->chmod($file, 0o600);
        $output->writeln('Démonstration prête : identifiants privés dans var/alam-demo-credentials.json.');

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $payload = [], ?string $token = null): array
    {
        $options = ['headers' => ['Accept' => 'application/json', 'Idempotency-Key' => Uuid::v7()->toRfc4122()], 'timeout' => 30, 'max_redirects' => 0];
        if (null !== $token) {
            $options['auth_bearer'] = $token;
        }
        if ('GET' !== $method) {
            $options['json'] = $payload;
        }
        $response = $this->http->request($method, 'http://php'.$path, $options);
        if ($response->getStatusCode() >= 300) {
            throw new LogicException('Échec de la démo sur '.$path.' (HTTP '.$response->getStatusCode().').');
        }

        /** @var array<string, mixed> */
        return $response->toArray();
    }
}
