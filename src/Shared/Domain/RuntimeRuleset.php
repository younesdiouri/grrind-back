<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use App\Shared\Application\GameRulesets;

/**
 * Porte le cache d'un objet de règles reconstruit depuis le snapshot publié.
 *
 * `DatabaseGameRulesets` conserve le snapshot ouvert pendant l'opération courante ; la
 * révision ne sert ici qu'à reconstruire la valeur typée au début de l'opération suivante.
 */
trait RuntimeRuleset
{
    private ?GameRulesets $rulesets = null;

    private ?self $runtimeValue = null;

    private ?int $runtimeRevision = null;

    private ?string $runtimeVersion = null;

    private function useRuntimeRulesets(?GameRulesets $rulesets): void
    {
        $this->rulesets = $rulesets;
    }

    private function isRuntimeRuleset(): bool
    {
        return null !== $this->rulesets;
    }

    /** @return self valeur typée du snapshot ouvert pour l'opération */
    private function runtimeValue(): self
    {
        \assert(null !== $this->rulesets);
        $revision = $this->rulesets->revision();
        $version = $this->rulesets->version();
        if ($this->runtimeRevision === $revision && $this->runtimeVersion === $version && null !== $this->runtimeValue) {
            return $this->runtimeValue;
        }

        $snapshot = $this->rulesets->snapshot();
        $value = self::fromSnapshot($snapshot);
        $this->runtimeValue = $value;
        $this->runtimeRevision = $revision;
        $this->runtimeVersion = $version;

        return $value;
    }
}
