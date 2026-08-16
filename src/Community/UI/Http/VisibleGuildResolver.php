<?php

declare(strict_types=1);

namespace App\Community\UI\Http;

use App\Community\Domain\Exception\GuildNotFound;
use App\Community\Domain\Guild;
use App\Community\Infrastructure\Doctrine\GuildRepository;
use App\Community\Infrastructure\Security\GuildVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsTargetedValueResolver;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Charge la guilde d'une route `{id}` et refuse de la rendre à qui n'en est pas membre.
 *
 * **Les trois refus ne font qu'une réponse : 404.** Un identifiant mal formé, une guilde
 * qui n'existe pas, une guilde dont l'appelant n'est pas membre — le client reçoit la
 * même chose, parce que les distinguer ferait de la route un test d'existence : « 403 »
 * sur un UUID au hasard dirait « cette guilde existe », et les UUID v7 se devinent par
 * plage temporelle.
 *
 * Le 403 n'est pas perdu pour autant, il est simplement réservé à celui qui *est* membre
 * et n'a pas le droit demandé — il sait déjà que la guilde existe, il en fait partie. La
 * séparation est nette : **le resolver rend le 404, `#[IsGranted]` rend le 403.**
 *
 * Un resolver et non un contrôle recopié dans chaque contrôleur : le module a sept routes
 * en `{id}`, et la seule qui oublierait le contrôle serait la faille. Ici, un contrôleur
 * qui écrit `#[VisibleGuild] Guild $guild` ne *peut pas* recevoir une guilde qu'il n'a pas
 * le droit de lire.
 *
 * @see https://symfony.com/doc/current/controller/value_resolver.html
 */
#[AsTargetedValueResolver]
final readonly class VisibleGuildResolver implements ValueResolverInterface
{
    public function __construct(
        private GuildRepository $guilds,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    /**
     * @return iterable<Guild>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if (Guild::class !== $argument->getType()) {
            return [];
        }

        $id = $request->attributes->get('id');

        // Un `{id}` qui n'est pas un UUID n'a jamais désigné une guilde. On rend le même
        // 404 que pour une guilde inconnue plutôt que de laisser passer un 400 : la forme
        // de l'identifiant ne doit pas non plus renseigner sur ce qui existe.
        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new GuildNotFound();
        }

        $guild = $this->guilds->ofId(Uuid::fromString($id));

        if (null === $guild || !$this->authorization->isGranted(GuildVoter::VIEW, $guild)) {
            throw new GuildNotFound();
        }

        return [$guild];
    }
}
