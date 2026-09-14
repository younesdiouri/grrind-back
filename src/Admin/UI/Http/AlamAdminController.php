<?php

declare(strict_types=1);

namespace App\Admin\UI\Http;

use App\Community\Application\AlamRuns;
use App\Community\Domain\AlamRun;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[IsGranted('ROLE_ADMIN')]
final class AlamAdminController extends AbstractController
{
    #[Route('/admin/alam', name: 'admin_alam', methods: ['GET', 'POST'])]
    public function index(Request $request, AlamRuns $runs, EntityManagerInterface $em): Response
    {
        $form = $this->createFormBuilder()->add('guildId', TextType::class, ['label' => 'UUID de la guilde', 'constraints' => [new Assert\Uuid()]])->add('launch', SubmitType::class, ['label' => 'Lancer une édition manuelle', 'disabled' => !$runs->manualEnabled])->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $guildId = $form->get('guildId')->getData();
            \assert(\is_string($guildId));
            $run = $runs->manualForGuild(Uuid::fromString($guildId));
            $this->addFlash('success', 'Édition créée : '.$run->id->toRfc4122());

            return $this->redirectToRoute('admin_alam');
        }
        /** @var list<AlamRun> $history */
        $history = $em->getRepository(AlamRun::class)->findBy([], ['id' => 'DESC'], 50);

        return $this->render('admin/alam.html.twig', ['form' => $form, 'runs' => array_map($runs->resource(...), $history)]);
    }
}
