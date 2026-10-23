# Équipements Unity — catalogue serveur (#313)

Les sept objets sont des équipements COMMON achetables dès le niveau 1. Les couleurs ne
changent ni rareté ni bonus. Aucun attribut sportif brut n'est ajouté : les taux modifient
le combattant après dérivation, avec les plafonds existants. `30` millièmes = **+3 points de
pourcentage**, par exemple 10 % de critique devient 13 %. L'API `statistics.fighter` rend
les pourcentages résolus ; les `modifiers` conservent les unités du moteur.

| Clé stable | Nom FR / EN | Slot | Achat / revente | Bonus |
| --- | --- | --- | --- | --- |
| `GOLDEN_BOXING_GLOVES` | Gants de boxe dorés / Golden Boxing Gloves | HANDS | 20 / 10 | `CRITICAL_CHANCE_BONUS: 30` |
| `LES_GUINZZ_PURPLE` | Les Guinzz — Violet / Purple | LEGS | 15 / 7 | `DODGE_BONUS: 30` |
| `LES_GUINZZ_CYAN` | Les Guinzz — Cyan | LEGS | 15 / 7 | `DODGE_BONUS: 30` |
| `LES_GUINZZ_CORAL` | Les Guinzz — Corail / Coral | LEGS | 15 / 7 | `DODGE_BONUS: 30` |
| `SPORT_TSHIRT_SLATE` | T-shirt sportif — Ardoise / Sport T-shirt — Slate | CHEST | 10 / 5 | `GUARD_BONUS: 30` |
| `SPORT_TSHIRT_CORAL` | T-shirt sportif — Corail / Sport T-shirt — Coral | CHEST | 10 / 5 | `GUARD_BONUS: 30` |
| `LE_POIDS_LOURD` | Le Poids Lourd / The Heavyweight | WEAPON | 25 / 12 | `DAMAGE_BONUS: 2` |

Les pièces des séances débutantes sont de l'ordre de 5–15 ; ces prix restent en dessous des
30 pièces des anciens équipements d'entrée. Les objets existants et leurs bonus restent
inchangés. Le prix de revente est la moitié entière du prix d'achat initial.

## Loot et publication

Chaque objet rejoint `WOODEN_CHEST` avec un poids **5**. La migration conserve les anciennes
entrées dans leur ordre, les pièces, l'activation et les autres réglages de chaque version.
Sur une installation neuve, sans chance de loot :

| Résultat | Poids | Probabilité |
| --- | --- | --- |
| Aucun objet | 55 | 40,7407 % |
| Baskets usées | 25 | 18,5185 % |
| Gantelets de fer | 20 | 14,8148 % |
| Chacun des sept nouveaux objets | 5 | 3,7037 % |
| Total | 135 | 100 % |

Les nouveaux objets représentent ensemble 35/135, soit 25,9259 %. Le coffre garde ses
10–25 pièces. `LOOT_LUCK` et les réglages administrateur peuvent changer ces probabilités.

La migration ajoute les sept objets séparément dans les tables d'édition et dans le snapshot
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

Le Poids Lourd donne ses dégâts côté serveur, mais ses animations ne sont pas validées pour
le combat. L'atelier rend actuellement gants et haltère exclusifs visuellement ; le serveur
permet de porter simultanément HANDS et WEAPON. Cette contrainte de l'atelier ne devient
pas une interdiction métier. Corps, cheveux et personnalisation restent dans #308.
