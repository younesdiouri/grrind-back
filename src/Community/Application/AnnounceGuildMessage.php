<?php

declare(strict_types=1);

namespace App\Community\Application;

/**
 * Prévenir la guilde qu'un message a été écrit (#281).
 *
 * **Un message à part, et non un champ de plus sur {@see ChatChanged}.** Les deux partent
 * du même `COMMIT` et disent le même fait, mais ils ne le disent pas à la même personne :
 * `ChatChanged` réveille un écran déjà ouvert par Mercure, sur un topic qu'un ancien membre
 * peut encore écouter jusqu'à l'expiration de son jeton — c'est pour ça qu'il ne porte
 * aucun contenu, et ce n'est pas une précaution à défaire en y glissant un identifiant de
 * message. Celui-ci ne sort jamais du serveur : il désigne la ligne à relire pour composer
 * un push, dont les destinataires sont revérifiés à la lecture.
 *
 * **Pas de `DelayStamp`, contrairement à {@see AnnounceGuildActivity}.** Le délai, là-bas,
 * n'attend pas d'autres séances : il garantit que l'annonce passe après les séances déjà en
 * file pour le même lot. Ici rien d'autre n'est en file pour ce fait, et surtout une
 * conversation ne se diffère pas — un message qui arrive trois minutes après avoir été
 * écrit a déjà été lu dans l'app.
 */
final readonly class AnnounceGuildMessage
{
    public function __construct(public string $messageId)
    {
    }
}
