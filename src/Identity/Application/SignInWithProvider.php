<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\SocialProvider;
use App\Shared\Domain\Locale;

final readonly class SignInWithProvider
{
    public function __construct(
        public SocialProvider $provider,
        public string $code,
        public string $redirectUri,
        public ?string $codeVerifier,
        /**
         * Le fuseau de l'appareil, comme à l'inscription classique. Aucun
         * fournisseur ne le donne, et il conditionne le calcul du streak : on le
         * demande au client plutôt que de le deviner.
         */
        public string $timezone,
        public Locale $locale,
    ) {
    }
}
