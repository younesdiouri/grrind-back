<?php

declare(strict_types=1);

use App\Community\Application\ChatChanged;
use App\Community\Application\ChatChangedHandler;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mercure\Hub;
use Symfony\Component\Mercure\Jwt\FactoryTokenProvider;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__).'/vendor/autoload.php';
new Dotenv()->bootEnv(dirname(__DIR__).'/.env');

// Des topics aléatoires sans conversation réelle : aucun compte ni message de dev modifié.
$url = $_ENV['MERCURE_URL'];
$factory = new LcobucciFactory($_ENV['MERCURE_JWT_SECRET']);
$guild = Uuid::v7()->toRfc4122();
$otherGuild = Uuid::v7()->toRfc4122();
$topic = ChatChangedHandler::topic($guild);
$otherTopic = ChatChangedHandler::topic($otherGuild);
$grants = [new Grant([Grant::ACTION_SUBSCRIBE], [$topic])];
$token = $factory->create($grants);
$client = HttpClient::create(['timeout' => 3, 'max_duration' => 10]);
$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

foreach ([null, 'invalid', $factory->create($grants, ['exp' => new DateTimeImmutable('-1 minute')])] as $badToken) {
    $response = $client->request('GET', $url, ['query' => ['topic' => $topic], 'auth_bearer' => $badToken]);
    $check(401 === $response->getStatusCode(), 'Le hub accepte un jeton absent, invalide ou expiré.');
    $response->cancel();
}
$publish = $client->request('POST', $url, ['auth_bearer' => $token, 'body' => ['topic' => $topic, 'data' => '{}', 'private' => 'on']]);
$check(401 === $publish->getStatusCode(), 'Un abonné peut publier.');
$publish->cancel();

$allowed = $client->request('GET', $url, ['query' => ['topic' => $topic], 'auth_bearer' => $token, 'buffer' => false]);
$other = $client->request('GET', $url, ['query' => ['topic' => $otherTopic], 'auth_bearer' => $token, 'buffer' => false]);
$check(200 === $allowed->getStatusCode(), 'Abonnement autorisé refusé.');
// Mercure 0.x accepte la connexion à un autre topic mais filtre ses événements privés.
$check(200 === $other->getStatusCode(), 'Connexion du scénario inter-guildes impossible.');
$hub = new Hub($url, new FactoryTokenProvider($factory, [new Grant([Grant::ACTION_PUBLISH], ['*'])]), $factory);
$handler = new ChatChangedHandler($hub);
$handler(new ChatChanged($guild));
$handler(new ChatChanged($otherGuild));
$received = '';
$leaked = '';
try {
    foreach ($client->stream([$allowed, $other], 1) as $response => $chunk) {
        if ($chunk->isTimeout()) {
            break;
        }
        if ($response === $allowed) {
            $received .= $chunk->getContent();
        } else {
            $leaked .= $chunk->getContent();
        }
    }
} finally {
    $allowed->cancel();
    $other->cancel();
}
$check(str_contains($received, 'data: {"type":"chat.changed"}'), 'Le signal privé n’a pas été reçu.');
$check(!str_contains($leaked, 'data:'), 'Un abonné a reçu un signal privé hors de sa guilde.');
echo "Mercure réel : publication/réception privées OK ; anonyme/invalide/expiré/publication interdits ; aucun signal inter-guildes.\n";
