<?php

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\Timezone;
use Symfony\Component\Uid\Uuid;

/**
 * Le fuseau d'un joueur, pour les modules qui doivent savoir quand commence sa journée.
 *
 * **Pourquoi un port, alors que la règle n°0 dit d'en écrire le moins possible.** Le fuseau
 * est un attribut de profil : il vit dans `Identity`. Le plafond d'XP quotidien et le
 * streak se calculent dans ce fuseau — c'est un invariant de CLAUDE.md — mais ni
 * `Progression` ni `Engagement` n'ont le droit d'importer une entité d'`Identity`. Aucun
 * composant Symfony ne répond à ça : c'est une frontière de *notre* découpage.
 *
 * Le contrat est volontairement minuscule — un UUID entre, un fuseau sort. Il vit dans
 * `Shared` pour que les deux côtés n'en dépendent que par là : `Identity` l'implémente,
 * `Progression` le consomme, et aucune flèche ne va de l'un à l'autre.
 *
 * L'autre chemin — une copie du fuseau répliquée par événement dans chaque module — a été
 * écarté : la propagation est asynchrone, et un joueur qui change de fuseau puis clôture
 * une séance dans la seconde serait compté sur l'ancien.
 */
interface PlayerTimezones
{
    /**
     * Le fuseau du joueur, ou `UTC` s'il est inconnu.
     *
     * Pas de `null` ni d'exception : un compte introuvable n'est pas un cas que le calcul
     * d'un plafond quotidien sache traiter, et le faire échouer ferait perdre une séance
     * pour une raison qui n'a rien à voir. `UTC` est le pire découpage possible pour le
     * joueur, jamais le plus avantageux — il ne se triche donc pas.
     */
    public function of(Uuid $userId): Timezone;
}
