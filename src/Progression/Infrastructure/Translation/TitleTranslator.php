<?php

declare(strict_types=1);

namespace App\Progression\Infrastructure\Translation;

use App\Progression\Domain\Title;
use App\Progression\Domain\TitleProgress;
use App\Progression\Domain\TitleRequirement;
use App\Shared\Application\GameRulesets;
use App\Shared\Application\PlayerTitle;
use DateTimeImmutable;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Met des mots sur un titre, et rend la forme unique que toute l'API sert.
 *
 * **Le seul endroit qui connaît les clés de traduction.** Elles se déduisent de
 * l'identifiant du titre — `veteran.name`, `veteran.hint` — donc ajouter un titre ne
 * demande rien ici : le YAML d'équilibrage et les deux catalogues de traduction suffisent.
 * `TitleTranslationsTest` refuse une clé manquante, sans quoi le repli du traducteur
 * enverrait `veteran.name` au joueur, en silence et en production.
 *
 * La locale n'est pas un paramètre : le traducteur lit celle de la requête, que le framework
 * négocie sur `Accept-Language` (`set_locale_from_accept_language`). Le serveur n'a donc
 * aucun état de langue à porter, et deux joueurs servis par le même worker reçoivent chacun
 * la sienne.
 */
final class TitleTranslator implements ResetInterface
{
    public const string DOMAIN = 'titles';

    /** @var array<string, array<string, array<string, string>>>|null */
    private ?array $translations = null;

    private ?int $revision = null;

    public function __construct(private readonly TranslatorInterface $translator, private readonly ?GameRulesets $rulesets = null)
    {
    }

    public function nameOf(Title $title): string
    {
        return $this->text($title->id, 'name');
    }

    /**
     * Ce qu'il reste à faire, rédigé.
     *
     * Le seuil est passé en paramètre plutôt qu'écrit dans la phrase : rééquilibrer « 25
     * séances » en « 30 » ne doit pas obliger à rouvrir deux fichiers de traduction, et
     * surtout ne doit pas pouvoir produire une consigne qui ment. `%hours%` est le même
     * seuil en heures, pour les conditions qui se comptent en secondes — le YAML garde
     * l'unité du ledger, le joueur lit la sienne.
     */
    public function hintOf(Title $title): string
    {
        $threshold = $title->condition->threshold;

        return strtr($this->text($title->id, 'hint'), [
            '%threshold%' => (string) $threshold,
            '%hours%' => (string) (TitleRequirement::DisciplineSeconds === $title->condition->requirement
                ? intdiv($threshold, 3600)
                : $threshold),
        ]);
    }

    /** La forme que `GET /api/me` et `GET /api/titles` servent tous les deux. */
    public function describe(TitleProgress $progress, ?DateTimeImmutable $unlockedAt): PlayerTitle
    {
        return new PlayerTitle(
            $progress->title->id,
            $this->nameOf($progress->title),
            $this->hintOf($progress->title),
            $unlockedAt,
            $progress->current,
            $progress->target,
            $progress->unit()->value,
        );
    }

    public function reset(): void
    {
        $this->translations = null;
        $this->revision = null;
    }

    private function text(string $key, string $field): string
    {
        if (null === $this->rulesets) {
            return $this->translator->trans($key.'.'.$field, domain: self::DOMAIN);
        }
        $locale = substr($this->translator->getLocale(), 0, 2);
        $translations = $this->translations();

        return $translations[$key][$locale][$field]
            ?? $translations[$key]['en'][$field]
            ?? $translations[$key]['fr'][$field]
            ?? $key;
    }

    /** @return array<string, array<string, array<string, string>>> */
    private function translations(): array
    {
        \assert(null !== $this->rulesets);
        $revision = $this->rulesets->revision();
        if (null !== $this->translations && $revision === $this->revision) {
            return $this->translations;
        }
        $snapshot = $this->rulesets->snapshot();
        $translations = [];
        $titles = $snapshot['titles'] ?? [];
        \assert(\is_array($titles));
        foreach ($titles as $title) {
            \assert(\is_array($title));
            \assert(\is_string($title['id']));
            \assert(\is_array($title['translations'] ?? null));
            /** @var array<string, array<string, string>> $entry */
            $entry = $title['translations'];
            $translations[$title['id']] = $entry;
        }

        $this->revision = $revision;

        return $this->translations = $translations;
    }
}
