# #277 — Atelier de game design

Statut : validé par le porteur du projet ; implémentation et validations terminées le 13 septembre 2026.
Ticket : https://github.com/younesdiouri/grrind-back/issues/277

## Objectif et décisions confirmées

Le game designer rejoint l'équipe et doit équilibrer le jeu depuis une interface web,
tester ses hypothèses puis publier un ensemble cohérent de règles. Le projet est en
développement : les refontes et changements cassants nécessaires sont acceptés.

Décisions confirmées dans la conversation :
- Intégrer l'atelier au backend existant.
- Préparer les règles en brouillon, simuler, puis publier explicitement.
- Configurer les attributs sources, leurs combinaisons et coefficients.
- Sauvegarder des profils fictifs ; comparer combats détaillés et séries de combats.
- Simuler plusieurs semaines d'activité sportive : XP, niveaux et statistiques.

## Parcours proposé

1. Ouvrir « Game design » dans l'administration ; voir la version publiée et le brouillon.
2. Modifier ennemis, équipements, niveaux, gains sportifs et règles de combat dans des
   formulaires lisibles, avec unités, aide et erreurs au niveau des champs.
3. Créer un profil fictif et un programme sportif hebdomadaire.
4. Comparer brouillon et publié sur les mêmes entrées : combat, série ou progression.
5. Examiner les différences de configuration, puis publier le brouillon complet.

## Choix proposés pour la première version

- Un brouillon partagé par l'équipe, sauvegardé ; pas de branches d'équilibrage concurrentes.
- Accès réservé au rôle administrateur existant, publication comprise. Un rôle dédié
  avec permissions différenciées pourra être ajouté ensuite si nécessaire.
- Profils et programmes nommés persistants ; résultats consultables avec les entrées,
  instantanés de règles et graines employés. La reproduction garantit le même résultat
  pour la même version du moteur ; conserver aussi un identifiant de version du moteur.
- Limites initiales : 1 000 combats par côté d'une comparaison, 52 semaines et
  14 séances par semaine. Ajouter une borne globale du nombre de tentatives de combat,
  car le produit « nombre de combats × maxAttacks » peut être trop coûteux.
- Calcul borné dans la requête pour commencer, à vérifier par mesure représentative.
  Si les plafonds retenus dépassent le budget HTTP, réduire explicitement les plafonds
  présentés ou exécuter les séries en tâche asynchrone ; ne pas livrer un parcours qui expire.

## Brouillon et publication

Les tables de configuration modifiables deviennent le brouillon partagé. Le runtime
continue à lire uniquement l'instantané publié. Sauvegarder un formulaire, un upload
ou une activation ne déclenche plus de publication implicite.

Réutiliser le constructeur et la validation de snapshot aujourd'hui présents dans
GameRulesetPublisher ; simulation et publication valident le même format. Le brouillon
peut être temporairement incomplet entre deux formulaires, mais une simulation ou une
publication doit signaler précisément toute référence invalide nécessaire au snapshot.

La publication est transactionnelle : verrouillage du brouillon, validation complète,
snapshot immuable, nouvelle révision, auteur et date ; invalidation du cache après commit.
Une erreur laisse le publié intact. Les formulaires utilisent une révision attendue pour
refuser une sauvegarde périmée ; une publication refuse un brouillon modifié depuis l'aperçu.
Une opération de jeu en cours garde son snapshot jusqu'à sa fin.

Les ressources référencées par le publié restent disponibles même si elles sont supprimées
du brouillon, notamment les images. Auditer les lectures directes des tables éditables :
aucune ne doit exposer un brouillon aux joueurs. Préserver les invariants des identifiants
référencés par inventaires et historique lors de la publication.

Afficher les différences entre publié et brouillon avant publication. Le publié ne doit
jamais contenir les profils ou résultats de simulation. Les notifications et paramètres
d'exploitation ne sont pas une nouvelle surface de simulation ; préserver leur comportement
et rendre explicite leur inclusion éventuelle dans la publication globale existante.

## Formules de combat configurables

Décrire chaque statistique dérivée par des données validées et éditables :
- Source : un attribut, ou deux attributs distincts parmi force, endurance, mobilité,
  dextérité et vitalité.
- Combinaison : attribut simple, somme, moyenne arithmétique ou moyenne géométrique.
- Conversion : linéaire (socle + coefficient) pour PV/dégâts ; rendement décroissant
  (plafond et seuil de demi-saturation) pour probabilités/réductions.
- Conserver les bonus directs, planchers et plafonds dans un ordre explicite partagé
  par les personnages réels et les simulations.

Les valeurs par défaut reproduisent exactement les formules actuelles. Les arrondis,
bornes techniques et dépassements sont définis et testés. L'interface affiche un exemple
calculé et une explication de la formule. Pas de PHP ou d'expression libre saisie par
l'utilisateur ; ajouter un mécanisme entièrement nouveau reste du développement.

Les réglages XP, courbe de niveaux, répartition des gains et vitalité déjà configurables
restent éditables ; le ticket n'introduit pas un langage universel pour toutes les règles.

## Profils et combats

Un profil contient un nom, les attributs de départ, l'équipement choisi dans le catalogue
et les données nécessaires aux bonus simulés. L'XP initiale doit être cohérente avec les
gains d'attributs ; afficher le niveau dérivé, plutôt qu'un niveau indépendant contradictoire.
Pour un test de combat isolé, permettre une vitalité imposée clairement identifiée comme
override ; en progression, calculer la vitalité avec les règles et l'activité simulées.

Choisir un ennemi du catalogue ou définir un adversaire fictif avec ses statistiques.
Un ennemi indisponible dans une des versions doit être signalé, jamais remplacé silencieusement.

- Combat détaillé : statistiques résolues des deux combattants, explication des bonus,
  événements chronologiques, victoire/défaite, motif d'arrêt, durée virtuelle et PV restants.
- Série : victoires/défaites, taux de victoire, durée et PV restants moyens, distribution
  simple et nombre de combats arrêtés par la limite de tentatives.
- Comparaison : mêmes profils et graines par combat sur deux snapshots figés ; identifier
  les versions. Une publication simultanée ne change pas les entrées de la série en cours.

Utiliser BattleSimulator et la même dérivation de Fighter que le jeu. N'appeler aucun
handler qui crée une bataille réelle, attribue du loot ou publie des événements métier.

## Progression sportive

Définir une date de départ, un fuseau, une durée en semaines et des séances répétées
(jour, heure, sport, durée, distance et dénivelé facultatifs). Saisir l'énergie active
journalière nécessaire au bonus de vitalité, y compris les jours de repos.

Dérouler les séances chronologiquement sur une horloge simulée. Réutiliser les règles
de durée admissible, rendements décroissants, plafonds quotidiens par discipline,
calcul d'XP, répartition des attributs, courbe de niveaux et vitalité. La fenêtre
d'antériorité d'un import réel ne doit pas rejeter artificiellement les semaines simulées.
Les bonus de série existants doivent évoluer sur l'historique fictif, avec les mêmes
conditions d'éligibilité que dans le jeu ; les bonus d'équipement sont identiques au runtime.

Afficher les résultats par séance et semaine : XP gagnée/cumulée, niveau et seuil suivant,
quatre attributs, vitalité et statistiques de combat dérivées. Comparer publié/brouillon
avec le même état initial et le même programme ; signaler les séances non créditées.
Permettre d'utiliser l'état d'une semaine comme entrée du simulateur de combat.

La première version utilise un équipement fixé par le scénario. Elle ne simule pas
l'économie, les achats, les tirages de loot, les guildes ou l'acquisition automatique de
tous les contenus. Les hypothèses sur les bonus non simulés sont visibles dans les résultats.

## Architecture et code

Stack existante : PHP >=8.4, Symfony 8.1, Doctrine ORM 3, EasyAdmin 5, PostgreSQL.
Pas de backend séparé. L'UI vit dans Admin ; calculs réutilisables dans leurs modules métier.
Respecter Deptrac : interfaces partagées si nécessaire, pas d'accès transversal aux repositories.

- src/Admin/{Domain,Infrastructure,UI} : brouillon, publication, profils, formulaires, résultats.
- src/Combat/{Domain,Application} : dérivation configurable commune et simulation existante.
- src/Progression et src/Training : règles pures réutilisables pour l'évolution fictive.
- templates/admin : pages d'atelier et comparaison.
- migrations : transition de la configuration et nouvelles données de laboratoire.
- tests/Admin, tests/Combat, tests/Progression : validation, parité, isolation et HTTP.

Suivre les objets typés et les fonctions pures existantes, par exemple :

```php
public function fight(Fighter $player, Fighter $enemy, Randomizer $rng): BattleOutcome
```

Les docblocks expliquent les décisions et invariants ; éviter une seconde implémentation
des mêmes règles dans les contrôleurs, les templates ou le simulateur de progression.

## Vérification et commandes

Toutes les commandes PHP passent par Docker via Make :

```sh
rtk make test c="tests/Admin"
rtk make test c="tests/Combat"
rtk make test c="tests/Progression"
rtk make test
rtk make qa
rtk make openapi
rtk make build-prod
```

Tests requis : brouillon sans effet runtime ; publication atomique ; conflits concurrents ;
cache ; migration avec valeurs actuelles ; image encore publiée ; formules par défaut
identiques ; formules modifiées réellement utilisées dans le jeu ; résultats reproductibles ;
aucune écriture de compte, bataille, inventaire, ledger, outbox ou notification lors d'un essai ;
progression comparable au parcours réel sur scénarios représentatifs (repos, série, plafond,
changement de jour et fuseau). Vérifier authentification, CSRF, bornes et références invalides.
Vérifier le parcours web complet et mesurer le coût des séries aux limites acceptées.

## Frontières et livraison

Toujours : préserver les changements utilisateur, consulter la documentation officielle
avant le code Symfony, tester les migrations et les invariants de simulation, documenter
le fonctionnement pour le game designer et les changements de contrats éventuels.

À faire valider : changement des choix produit ci-dessus ou réduction du périmètre confirmé.
Ne jamais : modifier la production réelle durant le développement, fusionner la PR,
réécrire l'historique des joueurs, exécuter du code saisi dans une formule, exposer des secrets.

Après validation de cette proposition, déléguer l'implémentation complète à l'agent developer
selon AGENTS.md. Il prend en charge tests, QA, commits, push et PR ; pas de fusion automatique.

## Extension validée — campagnes d’équilibrage

Extension explicitement autorisée : lots reproductibles à budget d’attributs constant,
profils diversifiés et aperçu avant sauvegarde, campagnes multi-profils/multi-ennemis,
comparaison sur snapshots et graines identiques, cible globale facultative, carte de
chaleur, classements et distributions avec alternatives textuelles. Les effectifs,
Wilson 95 %, PV normalisés, durées et limites restent visibles, sans score universel
d’équilibre. Budget combiné : 500000 tentatives et 20000 duels maximum, 50 profils,
10 ennemis, 1000 répétitions au plus. Défaut : 20 profils et jusqu’à 20 répétitions.

- [x] Générateur déterministe avec conservation exacte et profils spécialistes.
- [x] Agrégations partagées, parité moteur, Wilson et bornes globales testés.
- [x] Navigation visible et parcours aperçu → lot → campagne → détail/archives.
- [x] KPI comparatifs, F/E/M/D et budget, incertitude et cible indicative.
- [x] Isolation HTTP et conservation des snapshots après publication.
- [x] Mesure de la matrice maximale : 608 ms, rendu 52 ms, pic 64,5 MiB.
- [x] Validation globale et livraison sur la PR #278.

Limite de validation : rendu serveur testé ; vérification visuelle de l’extension
en navigateur indisponible dans cette session (CUA sans navigateur, permissions
macOS Chrome en attente). Aucune fusion ni déploiement.

Preuves extension : suite complète 1355 tests / 25260 assertions ; après correction
de typage limitée au test, test ciblé 5 tests / 441 assertions. QA complète (PHPStan
maximal, CS, Deptrac), génération OpenAPI sans modification du contrat mobile et
build de production réussis. Aucun changement de code métier après la suite complète.
