<?php

declare(strict_types=1);

namespace App\Admin\UI\Http;

use App\Admin\Domain\GamePublication;
use App\Admin\Domain\GameRulesetDiff;
use App\Admin\Infrastructure\GameDesignWorkspace;
use App\Admin\Infrastructure\GameDraft;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class GameDesignController extends AbstractController
{
    #[Route('/admin/game-design', name: 'admin_game_design', methods: ['GET', 'POST'])]
    public function index(Request $request, GameDesignWorkspace $workspace, GameDraft $draft, EntityManagerInterface $manager): Response
    {
        $form = $this->createFormBuilder()
            ->add('revision', HiddenType::class, ['data' => (string) $draft->revision()])
            ->add('publish', SubmitType::class, ['label' => 'Publier ce brouillon'])
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $revision = $form->get('revision')->getData();
            try {
                if (!\is_string($revision) || !ctype_digit($revision)) {
                    throw new LogicException('La révision du brouillon est invalide.');
                }
                $workspace->publish((int) $revision, $this->getUser()?->getUserIdentifier() ?? 'admin');
                $this->addFlash('success', 'Le brouillon est maintenant publié.');

                return $this->redirectToRoute('admin_game_design');
            } catch (InvalidArgumentException|LogicException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }
        $preview = null;
        $error = null;
        try {
            $preview = $workspace->preview();
        } catch (InvalidArgumentException|LogicException $exception) {
            $error = $exception->getMessage();
        }

        return $this->render('admin/game_design/index.html.twig', [
            'form' => $form,
            'preview' => $preview,
            'error' => $error,
            'changes' => null === $preview ? [] : GameRulesetDiff::between($preview['published'], $preview['draft']),
            'publications' => $manager->getRepository(GamePublication::class)->findBy([], ['publishedAt' => 'DESC'], 20),
        ]);
    }
}
