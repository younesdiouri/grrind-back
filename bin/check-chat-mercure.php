<?php

declare(strict_types=1);

use App\Community\Application\ChatChanged;
use App\Community\Application\ChatChangedHandler;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mercure\Hub;
use Symfony\Component\Mercure\Jwt\DefaultClaimsTokenFactory;
use Symfony\Component\Mercure\Jwt\FactoryTokenProvider;
use Symfony\Component\Mercure\Jwt\Grant;
use Symfony\Component\Mercure\Jwt\LcobucciFactory;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Uid\Uuid;

require dirname(__DIR__).'/vendor/autoload.php';
new Dotenv()->bootEnv(dirname(__DIR__).'/.env');

// Des topics aléatoires sans conversation réelle : aucun compte ni message de dev modifié.
$url = $_ENV['MERCURE_URL'];
// Même protocole et claims que config/packages/mercure.yaml ; l'audience est l'URL publique.
$factory = new DefaultClaimsTokenFactory(new LcobucciFactory($_ENV['MERCURE_JWT_SECRET'], protocolVersion: ProtocolVersion::V1), [
    'iss' => 'grrind-back',
    'aud' => $_ENV['MERCURE_PUBLIC_URL'],
    'sub' => 'grrind-back',
    'client_id' => 'grrind-back',
]);
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

foreach ([
    'absent' => null,
    'signature invalide' => $token.'invalid',
    'expiré' => $factory->create($grants, ['exp' => new DateTimeImmutable('-1 minute')]),
    'issuer inconnu' => $factory->create($grants, ['iss' => 'other-back']),
    'mauvaise audience' => $factory->create($grants, ['aud' => 'https://other-hub.example/.well-known/mercure']),
] as $scenario => $badToken) {
    $response = $client->request('GET', $url, ['query' => ['match' => $topic], 'auth_bearer' => $badToken]);
    $check(401 === $response->getStatusCode(), 'Le hub accepte un jeton : '.$scenario.'.');
    $response->cancel();
}
$publish = $client->request('POST', $url, ['auth_bearer' => $token, 'body' => ['topic' => $topic, 'data' => '{}', 'private' => 'on']]);
$check(403 === $publish->getStatusCode(), 'La publication par un abonné ne renvoie pas 403.');
$publish->cancel();

$legacy = $client->request('GET', $url, ['query' => ['topic' => $topic], 'auth_bearer' => $token]);
$check(400 === $legacy->getStatusCode(), 'Le hub accepte encore un abonnement avec topic au lieu de match.');
$legacy->cancel();

$allowed = $client->request('GET', $url, ['query' => ['match' => $topic], 'auth_bearer' => $token, 'buffer' => false]);
$other = $client->request('GET', $url, ['query' => ['match' => $otherTopic], 'auth_bearer' => $token, 'buffer' => false]);
$check(200 === $allowed->getStatusCode(), 'Abonnement autorisé refusé.');
// Le matcher choisit les topics écoutés ; le jeton filtre les événements privés reçus.
$check(200 === $other->getStatusCode(), 'Connexion du scénario inter-guildes impossible.');
$hub = new Hub($url, new FactoryTokenProvider($factory, [new Grant([Grant::ACTION_PUBLISH], ['*'])]), $factory, protocolVersion: ProtocolVersion::V1);
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
echo "Mercure moderne : publication/réception privées OK ; jetons absents/invalides/expirés/issuer inconnu/mauvaise audience refusés ; publication abonné 403 ; ancien topic 400 ; aucun signal inter-guildes.\n";
