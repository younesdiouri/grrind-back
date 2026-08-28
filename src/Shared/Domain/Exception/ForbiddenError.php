<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * L'appelant est identifié, il voit la ressource, et il n'a pas le droit de faire *ça*.
 *
 * **Le pendant domaine des voters, pas leur remplaçant.** Un voter répond « cet utilisateur
 * a-t-il ce droit sur cet objet », et c'est lui qu'on garde partout où la question se pose
 * avant d'agir. Cette erreur-ci sert le cas inverse : la règle ne se lit pas sur l'objet que
 * le contrôleur a en main mais sur un état que seul le handler connaît — le tour de rotation
 * ouvert d'une guilde (#193), qu'il faut charger pour savoir à qui il appartient.
 *
 * **Elle ne doit jamais servir à protéger l'existence d'une ressource.** C'est la règle du
 * projet et elle ne bouge pas : une ressource qu'on n'a pas le droit de *voir* rend 404, pas
 * 403, parce qu'un 403 confirmerait qu'elle existe et que les UUID v7 se devinent par plage
 * temporelle. Un 403 ne se prononce que devant quelqu'un qui sait déjà — un membre de la
 * guilde, ici — et à qui l'on refuse une action, pas une lecture.
 */
abstract class ForbiddenError extends DomainError
{
}
