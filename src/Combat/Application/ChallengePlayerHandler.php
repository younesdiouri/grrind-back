<?php

declare(strict_types=1);

namespace App\Combat\Application;

use App\Combat\Domain\Battle;
use App\Combat\Domain\BattleSimulator;
use App\Combat\Domain\Exception\CannotChallengeYourself;
use App\Combat\Domain\Exception\OpponentNotFound;
use App\Combat\Infrastructure\Doctrine\BattleRepository;
use App\Shared\Application\GameRulesets;
use App\Shared\Application\PlayerProfiles;
use App\Shared\Application\PlayerProgressions;
use App\Shared\Application\Teammates;
use App\Shared\Domain\Appearance;
use Psr\Clock\ClockInterface;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Symfony\Component\Uid\Uuid;

/**
 * Un défi PvP, de bout en bout : vérifier qu'on a le droit de défier, dériver les deux
 * combattants, jouer, écrire la ligne (#283).
 *
 * ## Le moteur n'a pas bougé, et c'est tout l'intérêt
 *
 * {@see BattleSimulator::fight()} oppose deux {@see \App\Combat\Domain\Fighter} et ne sait
 * pas d'où ils viennent. Le défieur prend la place du joueur, le défié celle de l'ennemi :
 * `Actor::Enemy` désigne l'adversaire, `BattleResult` reste rendu du point de vue du
 * défieur. Rien à généraliser, rien à paramétrer — un second handler de soixante lignes
 * plutôt qu'un `if` de plus dans {@see FightBattleHandler}, dont le pipeline PvE (choix de
 * l'ennemi au palier, tirage de récompense sous transaction) n'a aucun équivalent ici.
 *
 * ## Les deux combattants passent par la même porte
 *
 * {@see FighterFactory::forPlayer()} pour l'un comme pour l'autre : équipement, compétences,
 * Vitality, modificateurs actifs. Le défié n'a rien à faire et n'a pas à être connecté —
 * son combattant est dérivé de son état à l'instant du défi, exactement comme il le serait
 * s'il lançait lui-même un combat PvE.
 *
 * `$this->clock->now()` n'est appelée **qu'une fois**, comme dans `FightBattleHandler` : le
 * même instant sert de date de résolution des modificateurs des deux joueurs et de
 * `$foughtAt`. Deux appels pourraient diverger d'une milliseconde et faire résoudre les deux
 * camps sous des états différents.
 *
 * `PlayerProgressions::of()` est appelé **une fois pour les deux** : le port est batch par
 * construction précisément pour interdire la boucle qui ferait deux requêtes.
 *
 * ## Qui peut défier qui, et pourquoi c'est un 404
 *
 * Co-équipier, rien d'autre en v1 — la même règle que
 * {@see \App\Community\Infrastructure\Security\PlayerVoter} applique pour *regarder* un
 * profil, posée ici par le port {@see Teammates} parce que Deptrac interdit à `Combat` de
 * connaître `Community`.
 *
 * **Un étranger, un compte inconnu et un UUID malformé rendent la même chose : 404.** Un 403
 * confirmerait qu'un compte porte cet UUID, et les UUID v7 se devinent par plage temporelle.
 * Se défier soi-même est la seule exception, en 422 : l'UUID est celui de l'appelant, il n'y
 * a rien à cacher — voir {@see CannotChallengeYourself}.
 *
 * L'ordre des trois contrôles n'est pas indifférent : l'auto-défi **d'abord**, parce que
 * {@see Teammates::share()} rendrait `true` pour deux fois le même joueur (voir son docblock)
 * et qu'un joueur sans guilde pourrait alors se défier lui-même, là où un membre ne le
 * pourrait pas.
 *
 * ## Aucune récompense, et c'est une décision
 *
 * Ni objet, ni pièce, ni XP : `Battle::duel()` écrit la forme vide. Sans cooldown ni
 * finalité — ligue, tournoi, matchmaking, rien n'est tranché — défier un co-équipier en
 * boucle serait la meilleure source de revenu du jeu, strictement meilleure que le PvE
 * puisque l'adversaire ne se choisit pas par palier et que `BattleDrops` n'a aucune table à
 * lui opposer. La question se rouvrira avec le mode de jeu, sur des chiffres.
 *
 * Il n'y a donc **rien à mettre sous transaction** : une seule ligne est écrite, et
 * `commit()` la pose. Le `transactional()` de `FightBattleHandler` existe pour que le loot
 * et le combat tombent ensemble ; ici il n'y a pas de second écrit à perdre.
 */
final readonly class ChallengePlayerHandler
{
    public function __construct(
        private PlayerProgressions $progressions,
        private PlayerProfiles $profiles,
        private Teammates $teammates,
        private FighterFactory $fighters,
        private BattleSimulator $simulator,
        private BattleRepository $battles,
        private string|GameRulesets $rulesetVersion,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ChallengePlayer $command): Battle
    {
        if ($command->challengerId->equals($command->opponentId)) {
            throw new CannotChallengeYourself();
        }

        if (!$this->teammates->share($command->challengerId, $command->opponentId)) {
            throw new OpponentNotFound();
        }

        // Un co-équipier sans compte serait une adhésion orpheline — impossible aujourd'hui.
        // Le même 404 reste la seule réponse qui n'en dise pas plus que les autres.
        $profiles = $this->profiles->of([$command->challengerId, $command->opponentId]);
        $opponent = $profiles[$command->opponentId->toRfc4122()] ?? throw new OpponentNotFound();

        $now = $this->clock->now();

        $progressions = $this->progressions->of([$command->challengerId, $command->opponentId]);

        $challengerFighter = $this->fighters->forPlayer(
            $progressions[$command->challengerId->toRfc4122()],
            $command->challengerId,
            $now,
        );
        $opponentFighter = $this->fighters->forPlayer(
            $progressions[$command->opponentId->toRfc4122()],
            $command->opponentId,
            $now,
        );

        // Exactement 32 octets, jamais un hash d'une chaîne — voir le docblock de `Battle`.
        $seed = random_bytes(32);
        $outcome = $this->simulator->fight($challengerFighter, $opponentFighter, new Randomizer(new Xoshiro256StarStar($seed)));

        $challenger = $progressions[$command->challengerId->toRfc4122()];

        $battle = Battle::duel(
            Uuid::v7(),
            $command->challengerId,
            $challenger->attributes,
            $challenger->vitality,
            $challengerFighter,
            $command->opponentId,
            $opponent->displayName,
            $opponentFighter,
            $outcome,
            $seed,
            \is_string($this->rulesetVersion) ? $this->rulesetVersion : $this->rulesetVersion->version(),
            $now,
            $profiles[$command->challengerId->toRfc4122()]->appearance ?? Appearance::default(),
            $opponent->appearance,
        );

        $this->battles->add($battle);
        $this->battles->commit();

        return $battle;
    }
}
