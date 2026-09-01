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
 * La forme retenue (piste 2 du ticket) : un jeton déjà consommé n'est traité comme une
 * rotation perdue — famille épargnée, nouvelle paire émise — que si son successeur direct
 * n'a **jamais servi** (`RefreshToken::wasRotated()` + `successorId()`). C'est la signature
 * exacte d'une réponse jamais reçue : un successeur qui a lui-même tourné signifie que
 * quelqu'un s'en est servi, donc que la présentation de l'ancien est une vraie copie qui
 * circule — la famille saute, comme avant #250.
 *
 * Faiblesse assumée, pas ignorée : entre l'instant où le vrai client obtient son
 * successeur et celui où il s'en sert, un voleur qui rejouerait l'ancien passerait aussi.
 * On ne l'écarte pas parce qu'aucune des deux autres pistes ne fait mieux sans coûter plus
 * cher : une fenêtre de grâce temporelle est mal calibrée pour la même raison que ci-dessus,
 * et une clé d'idempotence sur la rotation exige un contrat client à refaire pour un
 * scénario mesuré une fois en seize jours. La ligne rouge tient : un rejeu toléré ne rend
 * jamais un secret déjà émis — la récupération réutilise `rotate()` telle quelle, qui ne
 * connaît que des hachages, et rend toujours une paire neuve.
 */
final readonly class RefreshSessionHandler
{
    public function __construct(
        private RefreshTokenRepository $refreshTokens,
        private UserDeviceRepository $devices,
        private JWTTokenManagerInterface $jwt,
        private ClockInterface $clock,
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
            $recovered = $this->recoverLostRotation($presented, $now);

            if (null === $recovered) {
                // Voir le docblock de la classe : impossible de distinguer le voleur du
                // vrai client au-delà du cas couvert par la récupération, donc on coupe
                // la famille entière — et l'appareil qu'elle portait, même transaction
                // (#136, même geste que LogOutHandler).
                $familyId = $presented->familyId();

                $this->refreshTokens->transactional(function () use ($familyId, $now): void {
                    $this->refreshTokens->revokeFamily($familyId, $now);
                    $this->devices->discardFamily($familyId);
                });

                throw new InvalidRefreshToken();
            }

            $presented = $recovered;
        } elseif (!$presented->isUsable($now)) {
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
     * `null` fait tomber l'appelant sur le vrai rejeu : famille révoquée. Un résultat non
     * `null` est le successeur à faire tourner à la place de `$presented` — voir le
     * docblock de la classe pour la mesure qui a validé cette piste.
     */
    private function recoverLostRotation(RefreshToken $presented, DateTimeImmutable $now): ?RefreshToken
    {
        if (!$presented->wasRotated()) {
            return null;
        }

        $successorId = $presented->successorId();

        if (null === $successorId) {
            return null;
        }

        $successor = $this->refreshTokens->ofId($successorId);

        // `isUsable()` couvre les trois façons dont ce successeur aurait cessé d'être la
        // signature d'une rotation perdue : consommé (quelqu'un s'en est servi), révoqué
        // (la famille est tombée entre-temps), ou expiré.
        if (null === $successor || !$successor->isUsable($now)) {
            return null;
        }

        return $successor;
    }
}
