# Plan — #277 Atelier de game design

Validé avec docs/specs/277-game-design.md ; implémentation en cours.

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
