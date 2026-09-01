<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Exception\InvalidRefreshToken;
use App\Identity\Domain\RefreshToken;
use App\Identity\Domain\RefreshTokenSecret;
use App\Identity\Infrastructure\Doctrine\RefreshTokenRepository;
use App\Identity\Infrastructure\Doctrine\UserDeviceRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * #250 : entre `rotate()` + `commit()` côté serveur et l'écriture du secret neuf dans le
 * trousseau du client, il y a le trajet retour du réseau. Si le client meurt pendant ce
 * trajet, le successeur existe en base sans avoir jamais servi, et le client — n'ayant
 * jamais eu d'autre choix — représente l'ancien jeton à sa prochaine tentative. Mesuré en
 * production le 2026-09-01 : 49 minutes entre le `COMMIT` et le rejeu, causées par un
 * réveil en arrière-plan tué par iOS avant d'avoir écrit le successeur (grrind-app#140).
 * Ce n'est donc pas un aléa réseau de quelques millisecondes — une fenêtre de grâce
 * temporelle sur le rejeu n'aurait pas couvert ce cas sans devenir absurdement longue.
 *
 * **Le serveur reste strict.** Une piste a été tentée puis retirée : tolérer le rejeu quand
 * le successeur direct n'a jamais servi. Elle paraissait sûre, mais elle s'auto-entretient —
 * chaque tolérance `rotate()` le successeur et fabrique donc un nouveau successeur inutilisé,
 * qui autorise la tolérance suivante. Un voleur et le vrai client qui alternent ne présentent
 * jamais un jeton dont le successeur a servi : `revokeFamily()` ne serait plus jamais atteint,
 * et le voleur resterait authentifié indéfiniment sans qu'aucun signal ne le trahisse. Ce
 * n'est pas rattrapable en resserrant la condition : côté serveur, une rotation perdue et un
 * vol produisent **exactement les mêmes octets** — un jeton consommé présenté par quelqu'un
 * qui n'a pas le successeur. On ne peut pas distinguer le voleur du vrai client qui a été
 * doublé, et c'est la phrase d'origine du module, pas une régression de #250.
 *
 * La seule tolérance serveur qui resterait envisageable est la piste 3 du ticket — une clé
 * d'idempotence sur la rotation, générée et persistée côté client *avant* l'appel — parce
 * qu'elle seule porte une preuve qu'un voleur n'a pas : un secret que le client a créé et
 * gardé, pas un état de la lignée qu'il observe autant que lui. Non implémentée ici ; elle
 * demande un contrat client à part.
 *
 * Ce qui reste de cette PR : `successorId` sur `RefreshToken`, et le journal ci-dessous. Le
 * successeur ne sert plus à décider, seulement à raconter *pourquoi* une famille est tombée —
 * distinguer, dans le `warning`, une rotation qui a plausiblement été perdue en vol (successeur
 * jamais consommé) d'une copie qui a vraiment servi deux fois (successeur déjà consommé), sans
 * changer l'issue : la famille saute dans les deux cas.
 */
final readonly class RefreshSessionHandler
{
    public function __construct(
        private RefreshTokenRepository $refreshTokens,
        private UserDeviceRepository $devices,
        private JWTTokenManagerInterface $jwt,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private int $accessTokenTtl,
    ) {
    }

    /**
     * @throws InvalidRefreshToken
     */
    public function __invoke(RefreshSession $command): AuthenticatedUser
    {
        $now = $this->clock->now();
        $presented = $this->find($command->refreshToken);

        if (null === $presented) {
            throw new InvalidRefreshToken();
        }

        if ($presented->isReplay()) {
            // Voir le docblock de la classe : impossible de distinguer le voleur du vrai
            // client, donc on coupe la famille entière — et l'appareil qu'elle portait, même
            // transaction (#136, même geste que LogOutHandler).
            $familyId = $presented->familyId();

            // Identifiants de lignes et verdict seulement — jamais le secret du jeton, ni en
            // clair, ni tronqué, ni haché : c'est la session elle-même.
            $this->logger->warning('Rejeu détecté sur un refresh token : famille révoquée.', [
                'presentedTokenId' => $presented->id()->toRfc4122(),
                'familyId' => $familyId->toRfc4122(),
                'successorId' => $presented->successorId()?->toRfc4122(),
                'verdict' => $this->replayVerdict($presented, $now),
            ]);

            $this->refreshTokens->transactional(function () use ($familyId, $now): void {
                $this->refreshTokens->revokeFamily($familyId, $now);
                $this->devices->discardFamily($familyId);
            });

            throw new InvalidRefreshToken();
        }

        if (!$presented->isUsable($now)) {
            throw new InvalidRefreshToken();
        }

        $secret = RefreshTokenSecret::generate();
        $rotated = $presented->rotate($secret, $now);

        $this->refreshTokens->add($rotated);
        // Consommation de l'ancien et écriture du nouveau dans le même flush.
        $this->refreshTokens->commit();

        $user = $presented->user();

        return new AuthenticatedUser($user, new TokenPair(
            $this->jwt->createFromPayload($user, ['fid' => $rotated->familyId()->toRfc4122()]),
            $this->accessTokenTtl,
            $secret->value,
            $rotated->expiresAt(),
        ));
    }

    private function find(string $value): ?RefreshToken
    {
        try {
            return $this->refreshTokens->ofSecret(RefreshTokenSecret::fromString($value));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Diagnostic seul — n'influence plus la décision, voir le docblock de la classe.
     * C'est la donnée qui a manqué le 2026-09-01 : reconstruire cette distinction a demandé
     * une requête SQL à la main après coup, pour un incident déjà passé.
     */
    private function replayVerdict(RefreshToken $presented, DateTimeImmutable $now): string
    {
        $successorId = $presented->successorId();

        if (null === $successorId) {
            return 'aucun successeur : jamais rotaté avant ce rejeu';
        }

        $successor = $this->refreshTokens->ofId($successorId);

        if (null !== $successor && $successor->isUsable($now)) {
            return 'successeur jamais consommé';
        }

        return 'successeur déjà consommé';
    }
}
