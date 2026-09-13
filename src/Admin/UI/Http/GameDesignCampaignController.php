<?php

declare(strict_types=1);

namespace App\Admin\UI\Http;

use App\Admin\Domain\GameDesignCampaign;
use App\Admin\Domain\GameDesignCombat;
use App\Admin\Domain\GameDesignProfile;
use App\Admin\Domain\GameDesignProfileGenerator;
use App\Admin\Domain\GameDesignRun;
use App\Admin\Domain\GameDesignRunKind;
use App\Admin\Infrastructure\GameDesignWorkspace;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;
use OverflowException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\ClickableInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Formulaires Symfony avec CSRF : prévisualiser ne persiste rien ; enregistrer fige le lot.
 * https://symfony.com/doc/current/forms.html#handling-multiple-submit-buttons.
 *
 * @phpstan-import-type ProfileInput from GameDesignProfile
 */
#[IsGranted('ROLE_ADMIN')]
final class GameDesignCampaignController extends AbstractController
{
    #[Route('/admin/game-design/campaigns', name: 'admin_game_design_campaigns', methods: ['GET', 'POST'])]
    public function index(Request $request, GameDesignProfileGenerator $generator, EntityManagerInterface $manager): Response
    {
        $form = $this->createFormBuilder(['name' => 'Exploration', 'count' => 20, 'total' => 10000, 'seed' => 42])
            ->add('name', TextType::class, ['label' => 'Nom du lot', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 80)]])
            ->add('count', IntegerType::class, ['label' => 'Nombre de profils (1–50)', 'constraints' => [new Assert\NotNull(), new Assert\Range(min: 1, max: 50)]])
            ->add('total', IntegerType::class, ['label' => 'Budget d’attributs par profil', 'constraints' => [new Assert\NotNull(), new Assert\Range(min: 1, max: 100000000)], 'help' => 'Force + endurance + mobilité + dextérité : même total pour chaque profil. Équipement vide, vitalité calculée.'])
            ->add('seed', IntegerType::class, ['label' => 'Graine de génération', 'constraints' => [new Assert\NotNull(), new Assert\Range(min: 0, max: 2000000000)]])
            ->add('preview', SubmitType::class, ['label' => 'Prévisualiser les profils'])
            ->add('save', SubmitType::class, ['label' => 'Enregistrer ce lot'])
            ->getForm();
        $form->handleRequest($request);
        $profiles = [];
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{name:string,count:int,total:int,seed:int} $data */
            $data = $form->getData();
            $profiles = $generator->generate($data['count'], $data['total'], $data['seed']);
            $save = $form->get('save');
            if ($save instanceof ClickableInterface && $save->isClicked()) {
                $run = new GameDesignRun(GameDesignRunKind::Cohort, ['profile' => ['name' => $data['name']], ...$data], ['published' => [], 'draft' => []], ['profiles' => $profiles]);
                $manager->persist($run);
                $manager->flush();

                return $this->redirectToRoute('admin_game_design_cohort', ['id' => $run->getId()]);
            }
        }

        return $this->render('admin/game_design/campaigns.html.twig', ['form' => $form, 'profiles' => $profiles, 'runs' => $manager->getRepository(GameDesignRun::class)->findBy(['kind' => [GameDesignRunKind::Cohort, GameDesignRunKind::Campaign]], ['createdAt' => 'DESC'], 50)]);
    }

    #[Route('/admin/game-design/cohorts/{id}', name: 'admin_game_design_cohort', methods: ['GET', 'POST'])]
    public function cohort(Request $request, GameDesignRun $run, GameDesignWorkspace $workspace, GameDesignCampaign $campaign, EntityManagerInterface $manager): Response
    {
        if (GameDesignRunKind::Cohort !== $run->kind()) {
            throw $this->createNotFoundException();
        }
        /** @var list<ProfileInput> $profiles */
        $profiles = $run->result()['profiles'];
        try {
            $preview = $workspace->preview();
        } catch (InvalidArgumentException|LogicException $exception) {
            return $this->render('admin/game_design/error.html.twig', ['error' => $exception->getMessage()], new Response(status: 422));
        }
        $snapshots = ['published' => $preview['published'], 'draft' => $preview['draft']];
        $choices = [];
        foreach ($snapshots as $snapshot) {
            /** @var array{combat:array{enemies:list<array{key:string}>,bosses:list<array{key:string}>}} $snapshot */
            $definitions = [...$snapshot['combat']['enemies'], ...$snapshot['combat']['bosses']];
            foreach ($definitions as $enemy) {
                $choices[$enemy['key']] = $enemy['key'];
            }
        }
        $maximum = GameDesignCampaign::maximumRepetitions($snapshots, \count($profiles), 1);
        $form = $this->createFormBuilder(['enemies' => \array_slice(array_values($choices), 0, 1), 'repetitions' => max(1, min(20, $maximum)), 'seed' => 42, 'target' => null, 'revision' => (string) $preview['revision']])
            ->add('revision', HiddenType::class)
            ->add('enemies', ChoiceType::class, ['label' => 'Adversaires (1–10)', 'choices' => $choices, 'multiple' => true, 'expanded' => true, 'constraints' => [new Assert\Count(min: 1, max: 10)]])
            ->add('repetitions', IntegerType::class, ['label' => 'Combats par profil et adversaire', 'constraints' => [new Assert\NotNull(), new Assert\Range(min: 1, max: 1000)]])
            ->add('seed', IntegerType::class, ['label' => 'Graine des combats', 'constraints' => [new Assert\NotNull(), new Assert\Range(min: 0, max: 2000000000)]])
            ->add('target', IntegerType::class, ['label' => 'Cible indicative de victoire (%)', 'required' => false, 'constraints' => [new Assert\Range(min: 0, max: 100)], 'help' => 'Facultative et globale : choisissez votre intention de design. Aucun taux n’est supposé idéal pour tous les ennemis.'])
            ->add('launch', SubmitType::class, ['label' => 'Lancer la campagne'])
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{enemies:list<string>,repetitions:int,seed:int,target:?int,revision:string} $data */
            $data = $form->getData();
            try {
                if ($data['revision'] !== (string) $preview['revision']) {
                    throw new LogicException('Les règles ont changé depuis l’aperçu. Rechargez cette page avant de lancer la campagne.');
                }
                $result = $campaign->run($snapshots, $profiles, $data['enemies'], $data['repetitions'], $data['seed'], $data['target']);
                $experiment = new GameDesignRun(GameDesignRunKind::Campaign, ['profile' => ['name' => $run->input()['name']], 'cohortId' => $run->getId()->toRfc4122(), 'profiles' => $profiles, ...$data], $snapshots, $result);
                $manager->persist($experiment);
                $manager->flush();

                return $this->redirectToRoute('admin_game_design_campaign', ['id' => $experiment->getId()]);
            } catch (InvalidArgumentException|LogicException|OverflowException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('admin/game_design/cohort.html.twig', ['run' => $run, 'profiles' => $profiles, 'form' => $form, 'pairCost' => GameDesignCampaign::costPerPair($snapshots), 'maxAttempts' => GameDesignCombat::MAX_ATTEMPTS, 'maxDuels' => GameDesignCampaign::MAX_DUELS]);
    }

    #[Route('/admin/game-design/campaigns/{id}', name: 'admin_game_design_campaign', methods: ['GET'])]
    public function result(GameDesignRun $run): Response
    {
        if (GameDesignRunKind::Campaign !== $run->kind()) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/game_design/campaign.html.twig', ['run' => $run]);
    }

    #[Route('/admin/game-design/campaigns/{id}/cells/{cell}', name: 'admin_game_design_campaign_detail', methods: ['POST'], requirements: ['cell' => '[0-9]+'])]
    public function detail(Request $request, GameDesignRun $run, int $cell, GameDesignCombat $combat, EntityManagerInterface $manager): Response
    {
        if (GameDesignRunKind::Campaign !== $run->kind() || !$this->isCsrfTokenValid('campaign-detail-'.$run->getId()->toRfc4122(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (GameDesignRun::ENGINE_VERSION !== $run->engineVersion()) {
            return $this->render('admin/game_design/error.html.twig', ['error' => 'Cette campagne utilise une ancienne version du moteur. Son archive conserve les résultats ; le moteur courant ne garantit pas son rejeu.'], new Response(status: 422));
        }
        /** @var list<array{profileIndex:int,enemyIndex:int,seed:int}> $cells */
        $cells = $run->result()['cells'];
        $selected = $cells[$cell] ?? throw $this->createNotFoundException();
        /** @var array{profiles:list<ProfileInput>,enemies:list<string>} $input */
        $input = $run->input();
        $profile = $input['profiles'][$selected['profileIndex']];
        $enemy = $input['enemies'][$selected['enemyIndex']];
        $snapshots = $run->snapshots();
        $result = $combat->compare($snapshots['published'], $snapshots['draft'], $profile, $enemy, null, 1, $selected['seed']);
        $detail = new GameDesignRun(GameDesignRunKind::Combat, ['profile' => $profile, 'enemyKey' => $enemy, 'customEnemy' => null, 'seed' => $selected['seed'], 'samples' => 1, 'campaignId' => $run->getId()->toRfc4122(), 'cell' => $cell], $snapshots, $result);
        $manager->persist($detail);
        $manager->flush();

        return $this->redirectToRoute('admin_game_design_run', ['id' => $detail->getId()]);
    }
}
