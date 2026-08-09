# GRRIND — Backend

API du produit GRRIND : une app qui transforme le sport en RPG (XP, niveaux, titres, arbres de
compétences, loot, streak, ligues). Ce dépôt ne contient **que le back**. Le client est une app
SwiftUI (dépôt/dossier séparé).

## Règle n°1 : tout passe par Docker

Il n'y a **pas de PHP, Composer ou Symfony CLI installés sur la machine hôte**. Aucune commande
PHP ne doit être lancée en direct. Tout passe par `make`, qui délègue à `docker compose`.

```bash
make               # liste toutes les cibles
make install       # première installation : images, dépendances, base
make up            # démarre la stack (http://localhost:8080)
make sh            # shell dans le conteneur php
make composer c="require foo/bar"
make console c="debug:router"
make migration     # génère une migration (à relire avant de l'appliquer)
make migrate       # applique les migrations
make test          # phpunit (crée et migre la base de test au passage)
make qa            # phpstan + cs-fixer + deptrac
make down
```

Si une commande manque au Makefile, on l'ajoute au Makefile — on ne contourne pas.

## Stack

| Brique | Choix |
|---|---|
| Runtime | FrankenPHP (PHP 8.4), mode worker en prod |
| Framework | Symfony 7.4 LTS |
| Persistance | PostgreSQL 17 + Doctrine ORM 3 (migrations versionnées, jamais de `schema:update`) |
| Async | Symfony Messenger, transport Doctrine (pattern outbox) |
| Auth | JWT (LexikJWTAuthenticationBundle) + refresh tokens |
| HTTP | Contrôleurs fins + DTO + Serializer. OpenAPI généré (source de vérité du contrat client iOS) |
| Tests | PHPUnit — le moteur de jeu est testable sans aucune infra |
| Qualité | PHPStan niveau max, PHP-CS-Fixer, Deptrac (frontières entre modules) |

## Architecture : monolithe modulaire

Un seul déploiement, des modules à frontières dures. Deptrac interdit les dépendances croisées :
la communication inter-modules se fait par **événements de domaine** ou par des **ports** explicites,
jamais par import direct d'une entité d'un autre module.

```
src/
  Shared/         # Kernel, Clock, UuidV7, types Doctrine, idempotence, ModifierVocabulary
  Identity/       # User, auth, profil, timezone
  Training/       # TrainingSession : déclaration, démarrage, complétion, garde-fous
  Progression/    # Ledger XP, courbe de niveaux, titres, arbres de compétences
  Rewards/        # Tables de loot, tirages, inventaire, équipement
  Engagement/     # Streak, classements, ligues
```

Chaque module suit la même découpe :

```
<Module>/
  Domain/         # entités, VO, règles pures — zéro dépendance framework
  Application/    # commandes/handlers, orchestration, ports
  Infrastructure/ # Doctrine, adapters, implémentations des ports
  UI/Http/        # contrôleurs, DTO requête/réponse
```

## Invariants de conception (à ne jamais casser)

**Le serveur possède l'horloge.** Une session doit être ouverte côté serveur avant d'être fermée.
La durée retenue est calculée à partir des timestamps serveur, jamais de ceux envoyés par le client.
Pas d'antidatage. C'est ce qui rend l'arrivée de Strava indolore : ça restera une simple source
supplémentaire.

**Toute activité est une source attribuée.** Chaque session porte `source`
(`MANUAL_TIMER` en v1, puis `STRAVA`, `HEALTHKIT`) et `trust` (`DECLARED` → `PROVIDER_VERIFIED`).
Le modèle ne bouge pas quand on branche une API tierce.

**L'XP est un ledger append-only.** `xp_transaction` est la vérité ; le niveau est une projection.
On n'incrémente jamais un compteur `xp` en place. Une session invalidée génère une transaction
négative, on ne supprime rien. `progression_snapshot` est un cache reconstructible.

**Le calcul d'XP est une fonction pure et versionnée.** On stocke le montant accordé *et* la version
du ruleset *et* le détail du calcul. On peut rééquilibrer sans corrompre l'historique, et le client
peut afficher « 90 base, +18 streak, +13 bottes ».

**Un seul vocabulaire de modificateurs.** Compétences, objets équipés, streak et ligue produisent
tous des modificateurs typés (`XP_MULTIPLIER`, `LOOT_LUCK`, `STREAK_SHIELD`, `UNLOCK_SESSION_TYPE`).
Un unique `ModifierResolver` calcule l'ensemble actif d'un user. C'est ce qui empêche le moteur de
pourrir.

**Le RNG est serveur et auditable.** Tout tirage de loot stocke le roll, la graine et la version de
la table. Le client ne tire jamais rien.

**Les écritures métier sont idempotentes.** Header `Idempotency-Key` obligatoire sur la complétion
de session — les clients mobiles rejouent leurs requêtes.

**La balance du jeu est du config-as-code.** Courbe de niveaux, coefficients XP, tables de loot,
arbres, catalogue d'objets vivent en YAML versionné sous `config/game/v1/`, chargés et validés au
boot, hashés pour produire le `rulesetVersion`. Rien de tout ça en base en v1.

## Transaction de complétion de session

C'est le cœur du produit — le moment dopamine. Séquence, en une transaction :

```
BEGIN
  verrou pessimiste sur la ligne progression du user
  valider la transition d'état de la session
  résoudre les modificateurs actifs (ModifierResolver)
  calculer l'XP (XpCalculator, pur)
  écrire la/les XpTransaction
  mettre à jour le snapshot, attribuer les points de compétence
  tirer le loot (LootRoller, audité)
  mettre à jour le streak
  écrire les événements de domaine dans l'outbox
COMMIT
→ asynchrone : classements, notifications
→ réponse : RewardSummary
```

Le verrou sérialise les complétions concurrentes. Le `RewardSummary` est un payload unique conçu
pour être **animé séquentiellement** par SwiftUI : gains d'XP détaillés, level ups, titre débloqué,
loot, streak, nouveaux nœuds débloquables.

## Garde-fous anti-abus v1 (sans API tierce)

Ce sont autant des règles de game design que des règles anti-triche :

- une seule session active à la fois, durée plancher et plafond
- rendements décroissants sur la journée (0-60 min ×1, 60-90 ×0.6, 90-120 ×0.3, au-delà ×0)
- plafond d'XP quotidien par discipline
- cooldown entre deux sessions

Les rendements décroissants suppriment l'intérêt de tricher tout en étant une vraie mécanique.

## Conventions

- Identifiants : UUID v7 (triables, générés applicativement).
- Dates : `TIMESTAMPTZ`, stockage UTC. **Le streak se calcule dans le fuseau du user** — le fuseau
  est un attribut de profil, pas une déduction.
- Argent/ratios : jamais de float sur des valeurs de jeu persistées ; entiers ou décimaux.
- Enums PHP natifs, backed, sur toutes les valeurs fermées.
- Migrations Doctrine relues à la main, jamais générées-appliquées à l'aveugle.
- Réponses d'erreur en RFC 7807 (problem+details).

## État d'avancement

**Lot 0 — socle : fait.** FrankenPHP 8.4 + Symfony 7.4 + Postgres 17 sous Docker, Makefile comme
point d'entrée unique, `GET /health` qui sonde la base, PHPStan niveau max, PHP-CS-Fixer,
Deptrac et PHPUnit câblés et verts, workflow CI qui rejoue les mêmes barrières dans la même image.
Aucune entité, aucune migration : `src/Shared/Domain` est vide et sert d'ancrage au mapping Doctrine.

Reste : Lot 1 Identity → Lot 2 Training → Lot 3 moteur Progression → **Lot 4 RewardSummary (premier
jouable)** → Lot 5 Streak → Lot 6 Loot → Lot 7 Arbres → Lot 8 Classements → Lot 9 durcissement.
Strava arrive après, comme simple adapter d'`ActivitySource`.

Le mapping Doctrine n'a **pas** d'`auto_mapping` : chaque module déclare le sien dans
`config/packages/doctrine.yaml` au moment de son lot. Idem pour les layers Deptrac, déjà déclarés
dans `deptrac.yaml` pour les six modules.
