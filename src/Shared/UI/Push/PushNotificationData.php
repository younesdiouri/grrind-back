<?php

declare(strict_types=1);

namespace App\Shared\UI\Push;

use App\Shared\Application\PushNotification;
use App\Shared\Domain\PushRouteType;
use Symfony\Component\Uid\Uuid;

/**
 * La charge utile qu'un push dépose chez le client — `data` côté Expo, et **le seul
 * morceau d'une notification que le client lit comme de la donnée** plutôt que comme du
 * texte. `title` et `body` s'affichent ; ceci se décode.
 *
 * **C'est donc du contrat, au même titre qu'une réponse HTTP.** Le canal n'est pas HTTP,
 * mais l'engagement l'est : un client qui route sur `routeType` doit pouvoir générer
 * l'énumération plutôt que d'en recopier les valeurs — sans quoi le jour où un level-up
 * ajoute un cas, le `switch` du client tombe dans son `default`, ne route nulle part, et
 * personne ne l'apprend (#147).
 *
 * **Pourquoi une classe plutôt qu'un tableau dans {@see \App\Shared\Infrastructure\Notifier\ExpoPushSender}.**
 * `openapi.yaml` est généré : un schéma écrit à la main y recopierait les valeurs de
 * {@see PushRouteType}, c'est-à-dire referait à l'intérieur du contrat la faute qu'on
 * reproche au client. Décrite depuis cette classe, la forme du `data` **est** celle que
 * le sender envoie — {@see self::toArray()} en est l'unique source — et l'énumération
 * sort générée, référencée par `$ref`. La déclaration qui l'amène dans le document vit
 * dans `config/packages/nelmio_api_doc.yaml`, sous `models.names` : le générateur décrit
 * ce modèle-là sans attendre qu'une route le référence, ce qui est exactement le besoin —
 * aucune route ne rend cette charge utile, et en inventer une pour lui donner un point
 * d'accroche serait une route de plus à documenter et à sécuriser.
 *
 * **Ici plutôt que dans `Application`.** {@see PushNotification} dit la notification dans
 * notre vocabulaire — ce qu'un cas d'usage compose ; celle-ci dit ce que le client reçoit
 * sur le fil, au même titre que ce que rend un contrôleur. C'est un `UI`, à côté de
 * `UI/Http`, avec un canal de plus.
 *
 * **Aucune donnée de jeu ne s'y ajoute** — voir {@see \App\Shared\Application\PushRoute} :
 * `routeId` est une clé de ressource à relire, pas une valeur à afficher.
 */
final readonly class PushNotificationData
{
    public function __construct(
        public string $groupingKey,
        public PushRouteType $routeType,
        public Uuid $routeId,
    ) {
    }

    public static function of(PushNotification $notification): self
    {
        return new self(
            $notification->groupingKey,
            $notification->route->type,
            $notification->route->targetId,
        );
    }

    /**
     * Les clés du fil, écrites une seule fois. Le contrat les décrit depuis les propriétés
     * ci-dessus : les deux ne peuvent diverger que si ce corps ment, et
     * `OpenApiContractTest` compare précisément l'un à l'autre.
     *
     * @return array{groupingKey: string, routeType: string, routeId: string}
     */
    public function toArray(): array
    {
        return [
            'groupingKey' => $this->groupingKey,
            'routeType' => $this->routeType->value,
            'routeId' => $this->routeId->toRfc4122(),
        ];
    }
}
