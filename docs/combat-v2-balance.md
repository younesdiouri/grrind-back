# Combat v2 — calibration initiale (#269)

Commande : `rtk make combat-balance SAMPLES=100`. Le CSV brut est dans
[combat-v2-balance.csv](combat-v2-balance.csv). 36 300 duels : 11 × 11 profils,
3 budgets, 100 graines entières (0..99) par duel orienté, symétriques compris.
Aucun équipement ni bonus d’énergie ; Vitality conserve sa formule publiée.
Snapshot mesuré : `v1-3f2da0de4bed`. Les paramètres du ticket sont conservés : ce rapport
constate leur effet, il ne valide pas leur équilibre.

| Budget d’attributs | Limites / 12 100 | Ticks moyens | Tentatives moyennes | Plus longue chaîne |
|---:|---:|---:|---:|---:|
| 4,000 | 0 | 10900.6 | 23.6 | 4 |
| 40,000 | 23 | 19609.8 | 44.1 | 10 |
| 400,000 | 3700 | 28982.6 | 68.0 | 11 |

La longueur moyenne d’une chaîne (coup initial inclus) figure par duel dans le CSV ;
une action qui ne déclenche aucun Combo a une longueur de 1. Les ticks ne sont pas des
millisecondes : le client choisit librement la durée des animations.

## Victoires par profil

Chaque valeur agrège 1 100 duels où le profil occupe le côté joueur. Cela inclut la
règle de départage favorable au joueur à la limite, et ne doit pas être lu comme un
classement PvP neutre. S/E/M/D : Strength/Endurance/Mobility/Dexterity ; chaque paire
répartit le budget moitié-moitié, SEMD en quatre parts égales.

| Profil | 4 000 | 40 000 | 400 000 |
|---|---:|---:|---:|
| S | 81.6 % | 89.2 % | 67.6 % |
| E | 37.7 % | 20.5 % | 32.5 % |
| M | 26.1 % | 14.5 % | 12.5 % |
| D | 15.5 % | 4.0 % | 4.4 % |
| SE | 80.7 % | 90.6 % | 90.9 % |
| SM | 70.4 % | 70.3 % | 71.3 % |
| SD | 66.7 % | 64.4 % | 55.4 % |
| EM | 32.1 % | 48.8 % | 48.6 % |
| ED | 37.8 % | 42.4 % | 42.1 % |
| MD | 21.3 % | 26.5 % | 25.6 % |
| SEMD | 95.5 % | 95.5 % | 95.6 % |

## Ce que les résultats indiquent

- Le profil équilibré domine cette matrice (~95,5 %), notamment grâce aux PV issus de
  Vitality. La symétrie des six paires ne produit donc pas un équilibre des builds.
- Sans Strength, les dégâts de base restent 16 à tous les budgets : les autres effets
  plafonnent tandis que les PV montent. À 400 000, 3 700 combats sur 12 100 atteignent
  la limite (30,6 %), sans butin même si le résultat est VICTORY. Les duels symétriques
  E/M/D/EM/ED/MD/SEMD atteignent tous cette limite à ce budget.
- L’alternance de priorité des égalités n’annule pas l’avantage d’ordre. Les duels
  symétriques déterministes S ont 0 %, 100 %, puis 0 % de victoires joueur selon le
  budget : la position du coup fatal dans l’ordre P,E,E,P,P,E compte. SE a 100 % aux
  trois budgets. Pour les profils probabilistes, les écarts sur 100 graines ne prouvent
  pas un avantage stable ; comparer les deux orientations et augmenter l’échantillon.
- Combo et cooldown s’amplifient mutuellement ; les plafonds initiaux limitent leur
  facteur moyen combiné théorique à environ 2,20 avant fatigue, critique et défenses.

## Scénario de difficulté antérieur conservé

Le même outil rejoue exactement `veteran-1` à `veteran-12` (SHA-256 binaire), joueur
équilibré à 75 460 attributs, contre le palier 50 et le boss final :

| Ennemi | Victoires | Limites | Ticks moyens | Tentatives moyennes |
|---|---:|---:|---:|---:|
| ASH_TITAN | 12 / 12 | 0 | 30 485,00 | 91,67 |
| CINDER_SOVEREIGN | 7 / 12 | 0 | 55 428,00 | 173,08 |

L’ancien test exigeait au moins une défaite contre ASH_TITAN : cette difficulté v1
n’est plus conservée par les formules v2. Il teste maintenant déterminisme et terminaison,
avec le scénario inchangé ; ses résultats sont ici explicitement rapportés. Le boss final
n’est pas une victoire automatique sur ces douze graines. Aucun coefficient ou ennemi
n’a été changé pour forcer un taux de victoire.

## Limites et suite produit

Ce sont des adversaires synthétiques sans équipements ni distribution réelle des sports.
Avant de déclarer l’équilibrage prêt, mesurer les profils sportifs et équipements réels,
recalibrer le rapport dégâts/PV et la durée des combats à haut budget, puis la difficulté
par palier. Les réglages sont administrables ; aucune de ces décisions n’est masquée dans
le code. Le client React Native doit adopter COMBO, les six nouveaux effets, les compteurs,
les ticks, les flags critique/Guard et les raisons de fin. Aucun déploiement inclus.
