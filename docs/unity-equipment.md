# Équipements Unity — catalogue serveur (#313)

Les sept objets sont des équipements COMMON achetables dès le niveau 1. Les couleurs ne
changent ni rareté ni bonus. Aucun attribut sportif brut n'est ajouté : les taux modifient
le combattant après dérivation, avec les plafonds existants. `30` millièmes = **+3 points de
pourcentage**, par exemple 10 % de critique devient 13 %. L'API `statistics.fighter` rend
les pourcentages résolus ; les `modifiers` conservent les unités du moteur.

| Clé stable | Nom FR / EN | Slot | Achat / revente | Bonus |
| --- | --- | --- | --- | --- |
| `GOLDEN_BOXING_GLOVES` | Gants de boxe dorés / Golden Boxing Gloves | WEAPON | 20 / 10 | `CRITICAL_CHANCE_BONUS: 30` |
| `LES_GUINZZ_PURPLE` | Les Guinzz — Violet / Purple | LEGS | 15 / 7 | `DODGE_BONUS: 30` |
| `LES_GUINZZ_CYAN` | Les Guinzz — Cyan | LEGS | 15 / 7 | `DODGE_BONUS: 30` |
| `LES_GUINZZ_CORAL` | Les Guinzz — Corail / Coral | LEGS | 15 / 7 | `DODGE_BONUS: 30` |
| `SPORT_TSHIRT_SLATE` | T-shirt sportif — Ardoise / Sport T-shirt — Slate | CHEST | 10 / 5 | `GUARD_BONUS: 30` |
| `SPORT_TSHIRT_CORAL` | T-shirt sportif — Corail / Sport T-shirt — Coral | CHEST | 10 / 5 | `GUARD_BONUS: 30` |
| `LE_POIDS_LOURD` | Le Poids Lourd / The Heavyweight | WEAPON | 25 / 12 | `DAMAGE_BONUS: 2` |

Les pièces des séances débutantes sont de l'ordre de 5–15 ; ces prix restent en dessous des
30 pièces des anciens équipements d'entrée. HALTER_EGO est également conservé : son aperçu Unity bleu utilise le modèle du Poids Lourd. Le prix de revente est la moitié entière du prix d'achat initial.

## Loot et publication

Chaque nouvel objet rejoint `WOODEN_CHEST` avec un poids **5**. Depuis le nettoyage #315,
les deux anciens équipements ont été retirés de cette table. Les pièces et les poids des
entrées conservées ne changent pas. Sur une installation neuve après nettoyage, sans chance
de loot :

| Résultat | Poids | Probabilité |
| --- | --- | --- |
| Aucun objet | 55 | 61,1111 % |
| Chacun des sept nouveaux objets | 5 | 5,5556 % |
| Total | 90 | 100 % |

Les nouveaux objets représentent ensemble 35/90, soit 38,8889 %. Le coffre garde ses
10–25 pièces. `LOOT_LUCK` et les réglages administrateur peuvent changer ces probabilités.

La migration historique #313 ajoute les sept objets séparément dans les tables d'édition et dans le snapshot
publié, puis les sept entrées au coffre de chacune des deux versions. Elle incrémente la
version de loot, les révisions et l'historique de publication. Elle ne publie aucun autre
brouillon, même si celui-ci modifie déjà les prix ou la table du coffre. Les possessions et
les anciens snapshots ne sont pas réécrits.

## Icônes et provisionnement

Les sept PNG transparents 512 × 512 de `resources/game-images/unity-equipment/` sont des
rendus Blender 4.5 des modèles de l'app. Le manifeste donne la correspondance clé → nom
SHA-1 du fichier. La migration fige cette même correspondance dans `image_path`.

Sources dans `grrind-app/spikes/art-source/` :

- `golden-boxing-gloves/PR_GoldenBoxingGloves_master.blend`, paire masculine ;
- `les-guinzz/LesGuinzz.blend`, mesh `Male_LesGuinzz`, trois teintes ;
- `tshirt-cotton/PR_TshirtCotton_baked.blend`, mesh `PR_TshirtCotton_Male_LOD0`, textures coton
  de `textures/`, teinte blanche ou multiplicateur corail `(1.35, .82, .66)` ;
- `le-poids-lourd/PR_LePoidsLourd_master.blend`, mesh `PR_LePoidsLourd_LOD0`.

Provenance des modèles, références ImageGen/Meshy et licences Quaternius CC0 : conservées
dans les dossiers source de l'app. Les icônes restent des images d'inventaire, sans calques
2D, calibration d'avatar ou nouveau pack graphique.

En local, `make game-images` copie les PNG dans `GAME_IMAGES_DIR` (défaut `var/game-images`),
sans écraser les images présentes. `make install` et `make test` l'exécutent aussi.
Sur Fly, après le déploiement et **avant la migration**, copier depuis l'image vers le
volume de la machine du groupe `app` :

```sh
flyctl ssh console -a grrind-back --machine <APP_MACHINE_ID> -C "sh -c 'mkdir -p /data/game-images && cp -n /app/resources/game-images/unity-equipment/*.png /data/game-images/'"
```

Jouer ensuite les migrations via le skill deploy et vérifier les sept URLs publiques
`/game-images/{nom SHA-1}.png`. Une migration ne modifie jamais le filesystem ; le volume
et la route publique existants continuent de servir les images. Les icônes incluses dans
l'image permettent le même provisionnement sur un environnement neuf.

## Passage de relais Unity

| Clés serveur | Configuration locale actuelle |
| --- | --- |
| `GOLDEN_BOXING_GLOVES` | `GoldenBoxingGloves`, paire ajustée au corps |
| `LES_GUINZZ_PURPLE` | `LesGuinzz`, `pantsColor = violet` |
| `LES_GUINZZ_CYAN` | `LesGuinzz`, `pantsColor = cyan` |
| `LES_GUINZZ_CORAL` | `LesGuinzz`, `pantsColor = coral` |
| `SPORT_TSHIRT_SLATE` | tenue sport, `shirtColor = slate` (tint blanc par défaut) |
| `SPORT_TSHIRT_CORAL` | tenue sport, `shirtColor = coral` |
| `LE_POIDS_LOURD` | `LePoidsLourd`, mode inspection uniquement |

`BattleEquipment` suffit : chaque référence figée porte `key` et `artworkVersion: null`.
Aucune version de calques 2D n'est inventée pour Unity. Le détail PvE/PvP et le rejeu gardent
ces clés après remplacement/retrait des objets ; l'app doit les raccorder à ses assets 3D.
Ajouter les objets au catalogue n'active pas leur rendu Unity.

Le Poids Lourd donne ses dégâts côté serveur, mais ses animations restent limitées à
l'inspection. Depuis #315, les gants, Le Poids Lourd et HALTER_EGO occupent tous WEAPON :
un seul de ces objets est équipé à la fois. HANDS ne fait plus partie du domaine actif.
Corps, cheveux et personnalisation restent dans #308.

## Retrait des équipements sans modèle 3D (#315)

Le nettoyage est une commande Symfony explicite, sans nouvelle migration de données :

```sh
make console c="app:equipment:retire-legacy"          # simulation, transaction annulée
make console c="app:equipment:retire-legacy --apply"  # application explicite
```

Elle supprime les 17 clés historiques nommées dans la commande, leurs piles d'inventaire,
leurs entrées de loot et les recettes concernées. Elle retire ces clés des profils du
laboratoire et désactive leurs packs graphiques EQUIPMENT. Les huit équipements Unity,
les quatre coffres et NAFS_ESSENCE sont conservés. Une future clé ajoutée par l'admin
n'est pas supprimée par une relance.

Les anciennes possessions sont retirées sans remboursement. Les gants portés en HANDS
passent en WEAPON si celui-ci est libre après retrait des anciens objets ; sinon ils
restent possédés dans le sac. Les profils du laboratoire gardent également leur arme
préexistante ; les gants sortent de la tenue simulée si nécessaire. Les autres références
HANDS sont retirées de la tenue.
XP, pièces, tirages audités, combats et publications historiques ne sont pas réécrits.

Publié et brouillon sont nettoyés séparément : un prix ou un poids en attente reste en
attente. Le nettoyage publié est validé puis tracé dans une nouvelle publication ; version
de loot, révision et cache sont mis à jour. Une relance sans changement ne crée aucune
publication. Une table sans entrée restante devient un tirage « rien » de poids 1, avec
sa bande de pièces conservée.

Pour Fly : déployer depuis main, vérifier la machine app démarrée, puis exécuter la
simulation et l'application via `flyctl ssh console -a grrind-back --machine <APP_ID>`
avec `-C "php bin/console app:equipment:retire-legacy [--apply]"`. Vérifier ensuite une
relance sans changement, les six slots de l'inventaire, les huit équipements en catalogue,
un achat/équipement/retrait, le refus de HANDS et d'une ancienne clé, et la santé HTTP.

Une installation locale neuve (`make db-reset`, donc `make install`) applique cette même
commande après les migrations historiques. Les tests du moteur utilisent des fixtures
explicites conservant leurs scénarios de rareté, de niveau et d'XP ; leurs armes utilisent
les six slots actuels. Le nettoyage est testé séparément contre ces données complètes,
sans ajouter de compatibilité HANDS au domaine métier.
