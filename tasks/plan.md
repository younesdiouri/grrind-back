# Plan — #277 Atelier de game design

Validé avec docs/specs/277-game-design.md ; implémentation et validations terminées le 13 septembre 2026.

## Ordre et dépendances

1. Séparer préparation/validation de snapshot et publication ; rendre le brouillon explicite.
2. Terminer le parcours de publication avec concurrence, différences et ressources publiées.
3. Rendre la dérivation des statistiques configurable ; migrer les formules actuelles.
4. Ajouter profils persistants, combat détaillé et comparaison de séries.
5. Ajouter programmes sportifs et progression fictive utilisant les règles communes.
6. Relier les états hebdomadaires aux combats ; vérifier UX, performance et documentation.

Chaque étape se décompose dans tasks/todo.md ; aucun chantier parallèle sur le même périmètre.
Les étapes 3 et 4 exigent la sélection explicite de snapshot introduite en 1.
La progression exige les profils de 4 et les règles de dérivation de 3.

## Points de contrôle

- Après publication : les joueurs ne voient jamais une sauvegarde de brouillon.
- Après combats : mêmes entrées et moteur donnent les mêmes sorties ; aucun effet métier.
- Après progression : comparaison avec les calculs réels et passage vers un combat.
- Avant PR : tests complets, QA, runtime web, budget de calcul, documentation et migrations.

## Risques à traiter tôt

- Des lecteurs contournent le snapshot : inventorier et supprimer ces lectures runtime.
- Images et identifiants encore référencés : conserver les ressources publiées et valider
  les suppressions sans abîmer l'historique des joueurs.
- Calculs divergents : extraire les fonctions communes avant d'écrire l'orchestration fictive.
- Publication et éditions concurrentes : révisions attendues et verrou transactionnel commun.
- Séries trop coûteuses : borne globale de travail et mesure avant de figer le plafond HTTP.

La mise en œuvre est confiée entièrement à developer après validation du périmètre écrit.

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
