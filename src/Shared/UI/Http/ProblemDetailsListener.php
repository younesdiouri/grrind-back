<?php

declare(strict_types=1);

namespace App\Shared\UI\Http;

use App\Shared\Domain\Exception\ConflictError;
use App\Shared\Domain\Exception\DomainError;
use App\Shared\Domain\Exception\NotFoundError;
use App\Shared\Domain\Exception\RuleViolationError;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

/**
 * Toute erreur sort en `application/problem+json` (RFC 9457). L'API est JSON-only :
 * aucun cas ne justifie une page d'erreur HTML.
 *
 * Priorité -64 : après le log des exceptions (0), avant l'ErrorListener de Symfony
 * (-128) qui produirait sinon la réponse par défaut. `setResponse()` arrête la
 * propagation, donc l'ErrorListener ne repasse pas derrière.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: -64)]
final readonly class ProblemDetailsListener
{
    public function __construct(private bool $debug)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        // Le profiler et la barre de debug servent du HTML : on ne s'en mêle pas.
        if (str_starts_with($event->getRequest()->getPathInfo(), '/_')) {
            return;
        }

        $throwable = $event->getThrowable();
        $violations = self::violations($throwable);

        $problem = match (true) {
            [] !== $violations => ProblemDetails::of(
                'validation-failed',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'La requête est bien formée mais son contenu est refusé.',
                ['violations' => $violations],
            ),
            $throwable instanceof DomainError => ProblemDetails::of(
                $throwable->type(),
                self::statusOf($throwable),
                $throwable->getMessage(),
                $throwable->context(),
            ),
            // Le message d'une HttpException est écrit pour être lu et ne contient
            // rien de sensible ; s'il est vide, le titre du statut suffit.
            $throwable instanceof HttpExceptionInterface => ProblemDetails::ofStatus(
                $throwable->getStatusCode(),
                $throwable->getMessage(),
            ),
            // Hors debug, rien ne fuit : la trace est dans les logs, pas dans la réponse.
            default => ProblemDetails::of(
                'internal-error',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                $this->debug
                    ? $throwable::class.' : '.$throwable->getMessage()
                    : 'Une erreur interne est survenue.',
            ),
        };

        $headers = $throwable instanceof HttpExceptionInterface ? $throwable->getHeaders() : [];
        $headers['Content-Type'] = 'application/problem+json';

        $event->setResponse(new JsonResponse($problem->toArray(), $problem->status, $headers));
    }

    private static function statusOf(DomainError $error): int
    {
        return match (true) {
            $error instanceof NotFoundError => Response::HTTP_NOT_FOUND,
            $error instanceof ConflictError => Response::HTTP_CONFLICT,
            $error instanceof RuleViolationError => Response::HTTP_UNPROCESSABLE_ENTITY,
            default => Response::HTTP_BAD_REQUEST,
        };
    }

    /**
     * Remonte la chaîne des exceptions : `#[MapRequestPayload]` enveloppe la
     * ValidationFailedException dans une HttpException.
     *
     * @return list<array{field: string, message: string}>
     */
    private static function violations(Throwable $throwable): array
    {
        for ($current = $throwable; null !== $current; $current = $current->getPrevious()) {
            if (!$current instanceof ValidationFailedException) {
                continue;
            }

            $violations = [];
            foreach ($current->getViolations() as $violation) {
                $violations[] = [
                    'field' => $violation->getPropertyPath(),
                    'message' => (string) $violation->getMessage(),
                ];
            }

            return $violations;
        }

        return [];
    }
}
