<?php

declare(strict_types=1);

namespace App\Admin\UI\Http;

use App\Admin\Domain\GameDesignCombat;
use App\Admin\Domain\GameDesignProfile;
use App\Admin\Domain\GameDesignProgram;
use App\Admin\Domain\GameDesignProgression;
use App\Admin\Domain\GameDesignRun;
use App\Admin\Domain\GameDesignRunKind;
use App\Admin\Domain\GameItem;
use App\Admin\Infrastructure\GameDesignWorkspace;
use App\Admin\UI\Form\GameDesignEnemyType;
use App\Admin\UI\Form\GameDesignProfileType;
use App\Admin\UI\Form\GameDesignProgramType;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use LogicException;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;

/** Les seules écritures du laboratoire concernent ses profils et ses expériences immuables. */
#[IsGranted('ROLE_ADMIN')]
final class GameDesignLabController extends AbstractController
{
    #[Route('/admin/game-design/profiles', name: 'admin_game_design_profiles', methods: ['GET'])]
    public function profiles(EntityManagerInterface $manager): Response
    {
        return $this->render('admin/game_design/profiles.html.twig', ['profiles' => $manager->getRepository(GameDesignProfile::class)->findBy([], ['name' => 'ASC']), 'programs' => $manager->getRepository(GameDesignProgram::class)->findBy([], ['name' => 'ASC']), 'runs' => $manager->getRepository(GameDesignRun::class)->findBy([], ['createdAt' => 'DESC'], 30)]);
    }

    #[Route('/admin/game-design/profiles/new', name: 'admin_game_design_profile_new', methods: ['GET', 'POST'])]
    #[Route('/admin/game-design/profiles/{id}', name: 'admin_game_design_profile_edit', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function profile(Request $request, EntityManagerInterface $manager, ?GameDesignProfile $profile = null): Response
    {
        $profile ??= new GameDesignProfile();
        $choices = [];
        $slots = [];
        foreach ($manager->getRepository(GameItem::class)->findAll() as $item) {
            if (null === $item->getSlot()) {
                continue;
            }
            $slots[$item->getKey()] = $item->getSlot();
            $choices[$item->getKey().' · '.$item->getSlot()] = $item->getKey();
        }
        foreach ($profile->getEquipment() as $key) {
            $choices[$key] = $key;
        }
        $form = $this->createForm(GameDesignProfileType::class, $profile, ['equipment_choices' => $choices]);
        $form->add('save', SubmitType::class, ['label' => 'Enregistrer le profil']);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $used = [];
            foreach ($profile->getEquipment() as $key) {
                $slot = $slots[$key] ?? null;
                if (null === $slot || isset($used[$slot])) {
                    $form->get('equipment')->addError(new FormError('Choisissez un objet existant par emplacement.'));
                    break;
                }
                $used[$slot] = true;
            }
        }
        if ($form->isSubmitted() && $form->isValid()) {
            $manager->persist($profile);
            $manager->flush();

            return $this->redirectToRoute('admin_game_design_profiles');
        }

        return $this->render('admin/game_design/form.html.twig', ['title' => 'Profil fictif', 'help' => 'L’XP et le niveau découlent des quatre attributs. Les bonus d’équipement s’appliquent ensuite avec les règles choisies.', 'form' => $form]);
    }

    #[Route('/admin/game-design/profiles/{id}/combat', name: 'admin_game_design_combat', methods: ['GET', 'POST'])]
    public function combat(Request $request, GameDesignProfile $profile, GameDesignWorkspace $workspace, GameDesignCombat $combat, EntityManagerInterface $manager): Response
    {
        try {
            $preview = $workspace->preview();
        } catch (InvalidArgumentException|LogicException $exception) {
            return $this->render('admin/game_design/error.html.twig', ['error' => $exception->getMessage()], new Response(status: 422));
        }

        return $this->combatForm($request, $profile->input(), ['published' => $preview['published'], 'draft' => $preview['draft']], $combat, $manager);
    }

    #[Route('/admin/game-design/programs/new', name: 'admin_game_design_program_new', methods: ['GET', 'POST'])]
    #[Route('/admin/game-design/programs/{id}', name: 'admin_game_design_program_edit', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-f-]{36}'])]
    public function program(Request $request, EntityManagerInterface $manager, ?GameDesignProgram $program = null): Response
    {
        $program ??= new GameDesignProgram();
        $form = $this->createForm(GameDesignProgramType::class, $program);
        $form->add('save', SubmitType::class, ['label' => 'Enregistrer le programme']);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $manager->persist($program);
            $manager->flush();

            return $this->redirectToRoute('admin_game_design_profiles');
        }

        return $this->render('admin/game_design/program.html.twig', ['form' => $form]);
    }

    #[Route('/admin/game-design/programs/{id}/progression', name: 'admin_game_design_progression', methods: ['GET', 'POST'])]
    public function progression(Request $request, GameDesignProgram $program, EntityManagerInterface $manager, GameDesignWorkspace $workspace, GameDesignProgression $progression): Response
    {
        $form = $this->createFormBuilder()
            ->add('profile', EntityType::class, ['class' => GameDesignProfile::class, 'choice_label' => 'name', 'label' => 'Profil initial'])
            ->add('simulate', SubmitType::class, ['label' => 'Comparer la progression'])
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $profile = $form->get('profile')->getData();
            if (!$profile instanceof GameDesignProfile) {
                throw $this->createNotFoundException('Profil absent.');
            }
            try {
                $preview = $workspace->preview();
                $snapshots = ['published' => $preview['published'], 'draft' => $preview['draft']];
                $result = $progression->compare($snapshots['published'], $snapshots['draft'], $profile->input(), $program->input());
                $run = new GameDesignRun(GameDesignRunKind::Progression, ['profile' => $profile->input(), 'program' => $program->input()], $snapshots, $result);
                $manager->persist($run);
                $manager->flush();

                return $this->redirectToRoute('admin_game_design_run', ['id' => $run->getId()]);
            } catch (InvalidArgumentException|LogicException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('admin/game_design/form.html.twig', ['title' => 'Progression · '.$program->getName(), 'help' => 'Même profil initial et programme sur les deux versions. La vitalité imposée est ignorée ; les séances et l’énergie déterminent l’évolution.', 'form' => $form]);
    }

    #[Route('/admin/game-design/runs/{id}/weeks/{week}/{side}/combat', name: 'admin_game_design_week_combat', methods: ['GET', 'POST'], requirements: ['week' => '[1-9][0-9]*', 'side' => 'published|draft'])]
    public function weekCombat(Request $request, GameDesignRun $run, int $week, string $side, GameDesignCombat $combat, EntityManagerInterface $manager): Response
    {
        if (GameDesignRunKind::Progression !== $run->kind()) {
            throw $this->createNotFoundException();
        }
        /** @var array<string, array{weeks: list<array{state: array{profile: array{name: string, strength: int, endurance: int, mobility: int, dexterity: int, vitalityOverride: ?int, equipment: list<string>}}}>}> $result */
        $result = $run->result();
        $profile = $result[$side]['weeks'][$week - 1]['state']['profile'] ?? null;
        if (null === $profile) {
            throw $this->createNotFoundException();
        }
        $profile['name'] .= ' · semaine '.$week.' · '.('draft' === $side ? 'brouillon' : 'publié');

        return $this->combatForm($request, $profile, $run->snapshots(), $combat, $manager);
    }

    #[Route('/admin/game-design/runs/{id}', name: 'admin_game_design_run', methods: ['GET'])]
    public function run(Request $request, GameDesignRun $run): Response
    {
        if (GameDesignRunKind::Campaign === $run->kind()) {
            return $this->redirectToRoute('admin_game_design_campaign', ['id' => $run->getId()]);
        }
        if (GameDesignRunKind::Cohort === $run->kind()) {
            return $this->redirectToRoute('admin_game_design_cohort', ['id' => $run->getId()]);
        }
        $page = max(1, min(201, $request->query->getInt('page', 1)));

        return $this->render('admin/game_design/run.html.twig', ['run' => $run, 'eventPage' => $page, 'eventOffset' => ($page - 1) * 100]);
    }

    #[Route('/admin/game-design/runs/{id}/archive', name: 'admin_game_design_run_archive', methods: ['GET'])]
    public function archive(GameDesignRun $run): Response
    {
        $response = $this->json(['id' => $run->getId()->toRfc4122(), 'engineVersion' => $run->engineVersion(), 'createdAt' => $run->createdAt()->format(\DATE_ATOM), 'kind' => $run->kind()->value, 'input' => $run->input(), 'snapshots' => $run->snapshots(), 'result' => $run->result()]);
        $response->headers->set('Content-Disposition', 'attachment; filename="game-design-'.$run->getId()->toRfc4122().'.json"');

        return $response;
    }

    /**
     * @param array{published: array<string, mixed>, draft: array<string, mixed>}                                                                $snapshots
     * @param array{name: string, strength: int, endurance: int, mobility: int, dexterity: int, vitalityOverride: ?int, equipment: list<string>} $profile
     */
    private function combatForm(Request $request, array $profile, array $snapshots, GameDesignCombat $combat, EntityManagerInterface $manager): Response
    {
        $choices = ['Adversaire personnalisé' => '__custom'];
        /** @var array{combat: array{enemies: list<array{key: string}>, bosses: list<array{key: string}>}} $snapshot */
        foreach ($snapshots as $snapshot) {
            foreach ([...$snapshot['combat']['enemies'], ...$snapshot['combat']['bosses']] as $enemy) {
                $choices[$enemy['key']] = $enemy['key'];
            }
        }
        $maximum = GameDesignCombat::maximumSamples($snapshots['published'], $snapshots['draft']);
        $custom = array_fill_keys(['mitigationPermille', 'comboPermille', 'dodgePermille', 'maintenancePermille', 'criticalChancePermille', 'guardPermille', 'criticalResistancePermille', 'cooldownReductionPermille', 'precisionPermille'], 0);
        $form = $this->createFormBuilder(['enemy' => '__custom', 'samples' => min(100, $maximum), 'seed' => 42, 'custom' => ['hp' => 100, 'damage' => 10, ...$custom]])
            ->add('enemy', ChoiceType::class, ['label' => 'Adversaire', 'choices' => $choices, 'help' => 'Un adversaire du catalogue doit exister dans les deux versions.'])
            ->add('custom', GameDesignEnemyType::class, ['label' => 'Statistiques personnalisées', 'help' => 'Utilisées seulement avec « Adversaire personnalisé ».'])
            ->add('samples', IntegerType::class, ['label' => 'Nombre de combats par version', 'constraints' => [new Assert\Range(min: 1, max: $maximum)], 'help' => 'Maximum pour ces règles : '.$maximum.'.'])
            ->add('seed', IntegerType::class, ['label' => 'Graine reproductible', 'constraints' => [new Assert\Range(min: 0, max: 2147482000)]])
            ->add('simulate', SubmitType::class, ['label' => 'Comparer les combats'])
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{enemy: string, custom: array{hp: int, damage: int, mitigationPermille: int, comboPermille: int, dodgePermille: int, maintenancePermille: int, criticalChancePermille: int, guardPermille: int, criticalResistancePermille: int, cooldownReductionPermille: int, precisionPermille: int}, samples: int, seed: int} $data */
            $data = $form->getData();
            try {
                $enemy = '__custom' === $data['enemy'] ? $data['custom'] : null;
                $result = $combat->compare($snapshots['published'], $snapshots['draft'], $profile, $data['enemy'], $enemy, $data['samples'], $data['seed']);
                $run = new GameDesignRun(GameDesignRunKind::Combat, ['profile' => $profile, 'enemyKey' => $data['enemy'], 'customEnemy' => $enemy, 'samples' => $data['samples'], 'seed' => $data['seed']], $snapshots, $result);
                $manager->persist($run);
                $manager->flush();

                return $this->redirectToRoute('admin_game_design_run', ['id' => $run->getId()]);
            } catch (InvalidArgumentException|LogicException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }
        }

        return $this->render('admin/game_design/form.html.twig', ['title' => 'Combats · '.$profile['name'], 'help' => 'Les deux versions partagent les mêmes graines. La première rencontre est détaillée ; la série mesure les distributions sans toucher aux joueurs.', 'form' => $form]);
    }
}
