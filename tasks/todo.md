# Tâches — #277

Périmètre validé ; chaque ligne est un incrément à tester avant le suivant. Les fichiers indiqués
sont les zones probables, à limiter à environ cinq fichiers par incrément lors de l'exécution.

- [x] Extraire la préparation et validation de snapshot sans publication.
  - Acceptation : snapshot validable sans changement de révision publiée.
  - Zones : GameRulesetPublisher, nouveau service, tests/Admin.
  - Vérifier : tests de validation et absence de mutation ; dépendance : aucune.
- [x] Transformer les sauvegardes CRUD en brouillon avec révision attendue.
  - Acceptation : sauvegarde invisible au runtime, édition périmée refusée.
  - Zones : GameCrudController, entité de révision, migration, tests/Admin.
  - Vérifier : tests HTTP et concurrence ; dépendance : préparation snapshot.
- [x] Ajouter aperçu des différences et action de publication.
  - Acceptation : publication atomique de la révision examinée, auteur/date visibles.
  - Zones : contrôleur publication, publisher, template, tests/Admin.
  - Vérifier : CSRF, rollback, conflit et cache ; dépendance : brouillon.
- [x] Garantir les références et images du publié pendant les éditions.
  - Acceptation : supprimer/remplacer au brouillon ne casse pas le jeu publié.
  - Zones : guard, gestion images, tests/Admin et lecteurs identifiés.
  - Vérifier : scénarios images et références ; dépendance : brouillon.
- [x] Définir et tester les formules structurées de statistiques.
  - Acceptation : opérations explicites, arithmétique bornée et résultats actuels reproductibles.
  - Zones : Combat/Domain, tests/Combat/Domain.
  - Vérifier : cas tabulés et valeurs limites ; dépendance : aucune.
- [x] Brancher les formules sur les combattants réels et migrer les valeurs initiales.
  - Acceptation : parité avant/après, formule publiée modifiée prise en compte par le jeu.
  - Zones : FighterFactory, snapshot, migration, tests/Combat.
  - Vérifier : intégration runtime et migration ; dépendance : formules et snapshot.
- [x] Éditer les formules dans l'administration.
  - Acceptation : choix d'attributs/opérations, coefficients et exemples sans JSON manuel.
  - Zones : formulaire, SettingsCrudController, template, tests/Admin.
  - Vérifier : formulaire valide/invalide ; dépendance : formules intégrées.
- [x] Ajouter sauvegarde et édition de profils fictifs.
  - Acceptation : profil nommé cohérent, références validées, aucun compte de jeu créé.
  - Zones : entité, migration, formulaire, CRUD, tests/Admin.
  - Vérifier : persistance et validation ; dépendance : snapshot.
- [x] Exécuter et afficher un combat détaillé sur snapshot choisi.
  - Acceptation : mêmes règles que le jeu, graine reproductible, aucun effet métier.
  - Zones : service simulation, contrôleur, formulaire, template, tests.
  - Vérifier : parité, isolation et HTTP ; dépendance : profils et dérivation.
- [x] Comparer des séries de combats et conserver les paramètres des résultats.
  - Acceptation : deux snapshots figés, graines communes, statistiques et budget de travail.
  - Zones : service séries, résultat, migration, template, tests.
  - Vérifier : reproductibilité, publication concurrente, charge ; dépendance : combat détaillé.
- [x] Sauvegarder un programme sportif répétable.
  - Acceptation : jours/heures/sport/métriques/énergie/fuseau validés, limites affichées.
  - Zones : entité programme, migration, formulaire, contrôleur, tests.
  - Vérifier : calendrier et données invalides ; dépendance : profils.
- [x] Extraire les calculs communs nécessaires à la progression fictive.
  - Acceptation : aucune duplication des règles XP, durée, série ou vitalité.
  - Zones : Progression, Training et services de bonus existants ; subdiviser par calcul.
  - Vérifier : tests actuels et cas de parité ; dépendance : snapshot explicite.
- [x] Exécuter chronologiquement le programme avec un état fictif.
  - Acceptation : jours de repos, plafonds, séries et bonus traités sans écriture métier.
  - Zones : orchestrateur, état fictif, résultats, tests progression.
  - Vérifier : scénarios multi-semaines et isolation ; dépendance : calculs communs/programme.
- [x] Afficher et comparer la progression, puis utiliser une semaine en combat.
  - Acceptation : tableaux/courbes XP/niveaux/attributs, hypothèses visibles, lien opérationnel.
  - Zones : contrôleur, présentation résultats, templates, tests HTTP.
  - Vérifier : parcours web complet ; dépendance : progression et combats.
- [x] Finaliser migrations, documentation et validation globale.
  - Acceptation : critères de la spec vérifiés, tests/QA réussis, coût mesuré.
  - Zones : README, ARCHITECTURE, documentation dédiée, contrat si modifié.
  - Vérifier : make test, make qa, make openapi, make build-prod via rtk.
- [x] Committer, pousser et ouvrir la PR selon AGENTS.md ; rendre preuves et limitations.
  - Acceptation : PR ouverte, aucune fusion ; dépendance : validation globale réussie.

Constat validé avec l’architecte : aucune mécanique de série/streak n’existe encore dans le jeu.
Le laboratoire affiche donc un bonus nul ; il ne crée pas une règle absente du runtime.

## Preuves de livraison

- `rtk make test` : 1347 tests, 24737 assertions, sans échec.
- `rtk make qa` : PHPStan maximal, CS et Deptrac sans erreur.
- `rtk make openapi` : contrat régénéré, inchangé pour l’API mobile.
- `rtk make build-prod` : image de production construite.
- Navigateur local : profil, ajout dynamique de séance, programme, courbes et semaine vers combat.
- HTTP : publication, conflits, CSRF, archives, bornes et absence d’écritures métier.
- Mesures aux plafonds documentées dans docs/game-design.md.

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
