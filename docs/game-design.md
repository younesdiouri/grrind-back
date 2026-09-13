# Atelier de game design

L’atelier est intégré à l’administration du backend : `/admin/game-design`.
Un compte avec mot de passe et `ROLE_ADMIN` suffit. L’attribution du rôle passe par
`rtk make console c="app:user:grant-admin adresse@example.com"`.
Le rôle autorise aussi la publication ; il n’existe pas encore de rôle distinct de designer.

## Préparer puis publier

Les formulaires EasyAdmin sauvegardent un **brouillon partagé**, sans modifier le jeu
des joueurs. Cela concerne les catalogues, niveaux, formules, gains sportifs et tous les
réglages administrables, y compris communauté et notifications. Une sauvegarde peut
laisser une référence provisoirement incomplète : le constructeur commun du snapshot
la signalera avant simulation ou publication.

Ouvrir « Game design », examiner les valeurs différentes, puis « Publier ce brouillon ».
La publication valide l’ensemble, remplace le snapshot en une transaction et conserve
la révision, l’auteur, la date et les règles dans `game_publication`. Le cache est invalidé
après le commit. Un formulaire ouvert avant une modification concurrente est refusé :
actualiser la page avant de reprendre la saisie.

Les opérations de jeu commencées gardent leur snapshot jusqu’à leur fin. Les images
référencées par le publié restent sur disque après remplacement au brouillon.
Il n’y a pas de bouton de retour arrière : une correction se prépare et se publie
comme un nouveau brouillon.

## Régler les formules

Dans les réglages de combat, chaque statistique choisit une source, ou deux sources
distinctes : force, endurance, mobilité, dextérité ou vitalité. La combinaison peut être
la somme, la moyenne arithmétique ou la moyenne géométrique. Les arrondis sont entiers
vers le bas ; la moyenne géométrique utilise la racine entière exacte.

Le score obtenu utilise les coefficients existants :

- PV et dégâts : socle + coefficient × score / 1000 ;
- taux : plafond × score / (score + seuil de demi-saturation) ;
- bonus d’attributs avant combinaison, bonus directs après conversion, puis plafonds
  des taux et plancher des dégâts.

Les intitulés historiques `hp_per_1000_vitality` et `damage_per_1000_strength` désignent
désormais le coefficient du **score configuré**, même si ses sources changent.
Un exemple chiffré sans équipement est affiché dans l’atelier.
Aucune expression PHP ou formule libre n’est exécutée. Les valeurs initiales migrées
reproduisent les onze statistiques du moteur précédent.

## Profils et combats

Créer un profil nommé dans « Profils et expériences ». Ses quatre attributs constituent
son XP initiale ; le niveau est calculé depuis la courbe choisie. Un objet par emplacement
peut être équipé. La vitalité imposée est facultative et ne s’applique qu’au combat isolé.

Lancer « Combats », choisir un ennemi présent dans les deux versions ou un adversaire
personnalisé. Les valeurs personnalisées sont des effets directs, sans attributs sources.
Une clé d’ennemi ou d’objet absente produit une erreur ; aucun remplacement silencieux.
Les objets désactivés mais encore présents restent utilisables comme un équipement
historique du jeu.

La comparaison emploie les mêmes graines sur le publié et le brouillon. Le combat
d’indice zéro utilise la graine saisie, le suivant la graine + 1, etc.
Le premier combat conserve sa timeline complète, consultable par pages de 100 événements.
Les séries donnent victoires, défaites, limites atteintes, durée virtuelle et PV moyens,
ainsi qu’une distribution des PV restants. Les ticks sont du temps de jeu virtuel.

## Campagnes d’équilibrage

L’entrée **Campagnes d’équilibrage** est visible dans le menu d’administration,
l’accueil de l’atelier et les profils. Prévisualiser un lot, l’enregistrer, choisir les
ennemis puis lancer la campagne. La prévisualisation n’écrit rien ; le lot enregistré
est immuable et réutilisable. Il ne crée aucun compte ni profil de joueur.

Le générateur propose 20 profils, 10000 points chacun et la graine 42. Choisir entre
1 et 50 profils et un budget total entre 1 et 100000000. Chaque répartition conserve
exactement force + endurance + mobilité + dextérité. Trois profils sur quatre utilisent
des coupures aléatoires du budget ; le quatrième concentre 70–95 % sur un attribut,
en alternant les quatre spécialités. Ce mélange explore volontairement les extrêmes :
il ne représente pas la population des joueurs. Même graine, taille et budget donnent
le même lot. Les graphiques affichent les quatre attributs et leur total.

Le lot combat sans équipement, sans override de vitalité et sans programme sportif.
Les ennemis sélectionnés doivent exister dans les deux snapshots. Tous les profils,
objets éventuels et ennemis sont validés avant le premier duel ; aucune cellule n’est
exclue silencieusement. Chaque paire profil/ennemi utilise ses propres graines, communes
au publié et au brouillon. Le résultat conserve le lot, les snapshots et les graines.
L’historique affiche les 50 derniers lots et campagnes ; les anciennes adresses et
archives restent accessibles. « Premier duel » ouvre le détail avec les règles figées,
même après édition ou publication. Un changement de version du moteur bloque ce rejeu,
mais pas la lecture de l’archive.

La carte de chaleur compare les taux publié/brouillon et leur delta. Les classements
comportent des barres comparatives ; les distributions regroupent les taux de victoire
par profil et les PV restants du joueur en dix classes, normalisées en pourcentage.
Les PV moyens du joueur **et** de l’ennemi sont rapportés à leurs PV initiaux respectifs.
Les nombres et tableaux constituent l’alternative textuelle aux graphiques HTML/CSS.
Les taux globaux comptent les combats réellement exécutés. Durée virtuelle moyenne,
médiane, p90, taux de limite d’attaques et dispersion des taux entre profils complètent
ces indicateurs. Les quantiles utilisent le rang supérieur.

Chaque cellule expose son effectif et son intervalle de Wilson à 95 %, y compris pour
0 % ou 100 % de victoires. Ces intervalles décrivent l’aléa des combats pour un scénario
fixé : ils ne prouvent pas un équilibre universel, ni la représentativité du lot.
Les comparaisons multiples et les graines appariées ne sont pas un test de significativité
du delta. Une alerte signale moins de 30 répétitions par cellule ; au-delà, la précision
reste à apprécier avec les intervalles. Une cible globale de victoire peut être saisie
entre 0 et 100 %, sans valeur imposée. Les écarts à cette cible sont indicatifs : des
ennemis de difficultés différentes ne devraient pas nécessairement avoir le même taux.

Le budget **global**, pour P profils et E ennemis (1–10), limite les répétitions à :

`min(1000, floor(500000 / (P × E × (maxAttacksPublié + maxAttacksBrouillon))), floor(20000 / (2 × P × E)))`.

Le formulaire affiche duels, maximum de répétitions et tentatives avant lancement,
et les recalcule à la sélection des ennemis. Le serveur applique les mêmes limites.
Le défaut est 20 profils × 1 ennemi × 20 répétitions × 2 versions = **800 duels** ;
les répétitions sont automatiquement abaissées à l’ouverture si les règles coûtent plus
cher. Ajouter des ennemis peut imposer de réduire les répétitions. Les campagnes ne
conservent pas toutes les timelines ; leur premier duel est recalculable à la demande.

Mesure locale du 13 septembre 2026 : 50 profils × 10 ennemis × 1 répétition × 2,
500000 tentatives effectivement atteintes : **608 ms** de calcul et sauvegarde,
**52 ms** pour rendre les 500 cellules, **64,5 MiB** au pic. Ce plafond permet le calcul
synchrone borné ; il ne constitue pas un SLA de production.

Les tests couvrent génération, conservation et diversité, Wilson, agrégations pondérées,
parité avec le moteur, budget combiné, préflight, CSRF, isolation des tables métier,
parcours HTTP et rejeu après publication. Le rendu serveur des graphiques est vérifié.
La vérification visuelle en navigateur de cette extension reste à faire : aucune surface
navigateur CUA disponible et permissions macOS Chrome en attente lors de cette session.

## Programmes sportifs

Créer un programme nommé avec une date de départ, un fuseau IANA, 1 à 52 semaines et
au plus 14 séances répétées par semaine. Les jours sont ceux du calendrier local ;
une semaine de simulation couvre sept jours depuis la date choisie. Chaque séance
précise jour, heure, sport, durée en secondes, distance et dénivelé facultatifs.
Ajouter/retirer une séance utilise le bouton du formulaire, sans JSON à saisir.

L’énergie est celle de la **journée entière**, en kcal actives, y compris le repos.
Elle ne s’ajoute pas aux calories d’une séance. Avant le départ, les journées comptent
pour zéro. La moyenne glissante inclut le jour courant et divise par la fenêtre entière.
Au changement d’heure, les horaires restent locaux et la durée représente des secondes
réellement écoulées entre les deux bornes.

Le laboratoire réutilise l’arbitrage de chevauchement de l’import, le minimum et
l’écrêtage de durée, les rendements décroissants, les plafonds XP, la répartition
d’attributs, les niveaux et la vitalité. La charge en secondes cumule tous les sports
crédités d’une journée ; le plafond XP reste propre au sport. Une discipline sans XP
n’alimente pas cette charge. Les refus et les lignes de calcul restent visibles.
La fenêtre d’antériorité d’un import réel est exclue de ce calendrier fictif.

Le résultat donne les valeurs de chaque séance et de chaque semaine, plus trois
courbes comparatives. « Tester en combat » réutilise les attributs et la vitalité de
la semaine sélectionnée. Les **deux snapshots de l’expérience d’origine** sont utilisés,
même après une nouvelle publication. La même vitalité obtenue devient alors un override
explicite pour comparer les combattants à état identique.

Hypothèses visibles : équipement fixe ; bonus de série égal à zéro, car la mécanique
de streak n’est pas implémentée dans le jeu ; guildes, compétences, ligues, économie,
achats, loot et acquisition de contenus ne sont pas simulés.

## Archivage et reproduction

`game_design_profile`, `game_design_program` et `game_design_run` sont les seules tables
écrites par les expériences. Aucun compte, workout, XP, inventaire, bataille, loot,
événement outbox ou notification réel n’est créé. Les tests comparent les comptes
de **toutes** les autres tables avant/après les parcours HTTP.

Chaque résultat est immuable et téléchargeable : entrées complètes, deux snapshots,
résultats et identifiant de moteur `gd2-combat2-mt19937` (les archives `gd1` restent consultables). Modifier le profil ou le
programme n’altère pas ses expériences précédentes. La garantie de reproduction
porte sur cette même version du moteur. Si un calcul partagé ou le RNG change,
incrémenter `GameDesignRun::ENGINE_VERSION` ; les résultats déjà enregistrés restent
consultables, sans promettre qu’un nouveau moteur reproduira leurs sorties.

## Budget et validation

Le plafond de série est
`min(1000, floor(500000 / (maxAttacksPublié + maxAttacksBrouillon)))`.
Avec 10000 tentatives maximales par combat, il est donc de 25 combats par version.
L’interface annonce cette borne et le serveur la contrôle. Seule la première timeline
est conservée, et son rendu est paginé ; le calcul et l’archivage gardent le détail entier.

Mesures du 13 septembre 2026, Docker local, PHP 8.4.25, tests Symfony avec base réelle :

| Scénario au plafond | Mesure |
|---|---:|
| Moteur pur, 500000 tentatives effectivement atteintes | 473–535 ms |
| Moteur pur, deux fois 52 × 14 séances | 167–199 ms |
| HTTP comparaison combats au plafond + sauvegarde | 535 ms |
| HTTP consultation des combats paginés | 41 ms |
| HTTP progression 52 × 14 + sauvegarde | 229–242 ms |
| HTTP consultation de la progression entière | 105–137 ms |

Pic isolé du test des moteurs : 48 MiB. Les parcours HTTP restent sous 162 MiB dans
la suite complète mesurée. Cela permet le calcul borné dans la requête pour cette
première version. Ces mesures locales ne sont pas un SLA de production ; recontrôler
la latence et la concurrence sur la machine cible après déploiement.

Vérifications reproductibles :

```sh
rtk make test c="tests/Admin"
rtk make test c="tests/Admin/GameDesignBudgetTest.php tests/Admin/GameDesignLimitsHttpTest.php"
rtk make test
rtk make qa
rtk make openapi
rtk make build-prod
```

La parité inclut un vrai import multi-sport, chevauchements et énergie journalière ;
les tests purs couvrent aussi le changement d’heure, l’override ignoré et les limites.
Les tests HTTP couvrent brouillon → différences → publication, révisions périmées,
CSRF, programme → semaine → combat et archive. Le navigateur local vérifie le formulaire
dynamique, le rendu des courbes et le lancement depuis une semaine.

Les migrations ajoutent révision de brouillon, historique, formules par défaut et
tables du laboratoire. La migration des formules complète le snapshot déjà publié
avec les valeurs équivalentes sans publier les modifications du brouillon. Elle est
irréversible : revenir en arrière demande une nouvelle migration explicite.
