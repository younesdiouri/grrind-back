<?php

declare(strict_types=1);

namespace App\Combat\UI\Http;

use App\Combat\Domain\Battle;
use App\Combat\Domain\Exception\BattleNotFound;
use App\Combat\Infrastructure\Doctrine\BattleRepository;
use App\Combat\Infrastructure\Security\BattleVoter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsTargetedValueResolver;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Charge le combat d'une route `{id}` et refuse de le rendre à qui ne l'a pas mené.
 *
 * Même geste que {@see \App\Community\UI\Http\VisibleGuildResolver}, pour la même raison :
 * **les trois refus ne font qu'une réponse, 404.** Identifiant malformé, combat inconnu,
 * combat qui appartient à quelqu'un d'autre — le client reçoit la même chose. Un 403
 * confirmerait qu'un combat porte cet UUID, et les UUID v7 se devinent par plage temporelle.
 *
 * @see https://symfony.com/doc/current/controller/value_resolver.html
 */
#[AsTargetedValueResolver]
final readonly class VisibleBattleResolver implements ValueResolverInterface
{
    public function __construct(
        private BattleRepository $battles,
        private AuthorizationCheckerInterface $authorization,
    ) {
    }

    /**
     * @return iterable<Battle>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if (Battle::class !== $argument->getType()) {
            return [];
        }

        $id = $request->attributes->get('id');

        // Un `{id}` qui n'est pas un UUID n'a jamais désigné un combat. Le même 404 que pour
        // un combat inconnu, plutôt qu'un 400 qui renseignerait sur la forme attendue.
        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new BattleNotFound();
        }

        $battle = $this->battles->ofId(Uuid::fromString($id));

        if (null === $battle || !$this->authorization->isGranted(BattleVoter::VIEW, $battle)) {
            throw new BattleNotFound();
        }

        return [$battle];
    }
}
