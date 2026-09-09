# Chat de guilde — contrat et exploitation (#275)

Le chat vit dans Community et PostgreSQL. Mercure intégré à FrankenPHP transporte
uniquement `{"type":"chat.changed"}` sur un topic privé. Le contenu passe toujours
par une route API qui revalide l'adhésion actuelle. Un nouvel adhérent lit tout
l'historique ; un départ ou une exclusion retire immédiatement l'accès API.

## Contrat mobile

Toutes les routes ci-dessous prennent le JWT GRRIND habituel dans `Authorization:
Bearer …`. Une guilde absente ou invisible rend 404, un JWT absent/invalide 401.
Les erreurs applicatives suivent `application/problem+json` et `openapi.yaml`.

### Envoyer

`POST /api/guilds/{id}/chat/messages`

- Texte seul : JSON `{"clientId":"<UUID généré une fois côté client>","text":"Bonjour"}`.
- Image, seule ou accompagnée : `multipart/form-data` avec `clientId`, `text`
  facultatif et un fichier `image`. Ne pas fixer la boundary à la main : laisser
  le client HTTP composer son Content-Type.
- Au moins un contenu, une seule image. Aucun chemin local, URL externe ou tableau
  d'images accepté. Les noms de fichier envoyés par le téléphone ne sont pas conservés.
- Texte brut UTF-8, sans interprétation HTML/Markdown ; espaces de bord retirés.
  Afficher comme texte, jamais dans une WebView qui interprète du HTML.
- JPEG, PNG, WebP statiques réellement décodables. Sortie WebP, sans métadonnées.
  L’orientation EXIF des JPEG est appliquée aux pixels avant retrait des métadonnées.
  Le mobile doit convertir les sources HEIC en un format accepté.

Réponse 201, également lors d'un rejeu identique :

```json
{
  "id": "<UUID serveur>",
  "clientId": "<UUID client>",
  "cursor": "42",
  "authorId": "<UUID joueur>",
  "text": "Bonjour",
  "createdAt": "2026-09-09T16:00:00+00:00",
  "imageUrl": "/api/guilds/<guilde>/chat/messages/<message>/image"
}
```

`imageUrl` vaut null sans image. L'auteur reste identifiable après son départ ;
le client ne doit pas dépendre de `GET /api/players/{id}` pour afficher ces anciens
messages, cette route ne donne plus accès à son profil hors de la guilde.

Conserver le même `clientId` **et les mêmes octets du fichier original** pour tous
les essais d'un envoi. L'empreinte porte sur le texte normalisé et l'upload original,
pas sur le résultat du codec. Réutiliser l'identifiant avec un contenu différent
rend 409. L'unicité est `(guilde, auteur, clientId)`, conservée avec l'historique.
Validation invalide : 422 (400 pour une forme de requête malformée).

### Historique et rattrapage

`GET /api/guilds/{id}/chat/messages?limit=50`

Réponse `{ "messages": [...], "nextCursor": "... ou null" }`. La première page
est décroissante, plus récent d'abord. Pour remonter le passé, reprendre
`?before=<nextCursor>`. `limit` est compris entre 1 et 100.

Pour rattraper une coupure : `?after=<dernier curseur reçu>&limit=50`, ordre
croissant. Tant que `nextCursor` est non nul, continuer avec `after=nextCursor`.
Le nouveau point de rattrapage est le curseur du dernier message de cette suite ;
une page vide ne le change pas. Sur une guilde vide, commencer par `after=0`.
`before` et `after` sont exclusifs. Le curseur est une chaîne serveur à restituer
telle quelle, jamais une date de téléphone ou un identifiant client.

Les positions sont attribuées sous le verrou de guilde qui couvre le COMMIT :
une transaction lente ne peut pas apparaître derrière un curseur déjà consommé.
Dédupliquer l'affichage par `id` ; ne pas déduire le nombre de messages du nombre
de signaux Mercure.

### Images et temps réel

`GET <imageUrl>` exige le JWT GRRIND et l'adhésion actuelle. Réponse `image/webp`,
`Cache-Control: private, no-store`, `X-Content-Type-Options: nosniff`. Ne pas
persister l'image dans un cache partagé ; purger l'état local au départ/exclusion.
Le backend ne peut pas révoquer une copie déjà téléchargée par un ancien membre.

`POST /api/guilds/{id}/chat/subscription` rend `{url, topic, token, expiresAt}`.
Ouvrir un SSE sur `url?topic=<topic encodé>` avec **ce jeton Mercure** dans le
header Authorization. Il ne donne aucun droit de publication, expire après cinq
minutes et ne contient pas le texte, les images ou l'identité du joueur.

S'abonner puis charger/rattraper depuis l'API évite le trou entre chargement et
connexion. À chaque signal, relancer le rattrapage. Au retour d'arrière-plan ou
après coupure, renouveler le jeton via l'API et rattraper même sans signal.
Une panne temporaire du hub n'empêche ni l'envoi HTTP ni l'historique ; retenter
le temps réel et continuer à rattraper depuis l'API.

Après exclusion, l'ancienne connexion peut encore recevoir **le signal minimal**
jusqu'à son expiration. Elle ne peut pas obtenir les nouveaux contenus, ni un
nouveau jeton. Mercure accepte une connexion authentifiée demandant un autre topic,
mais filtre tous ses événements privés : le refus porte sur les données, pas
nécessairement sur l'établissement de la connexion HTTP.

À vérifier dans le dépôt mobile : header Authorization SSE sur iOS/Android,
renouvellement, réseau intermittent, retour d'arrière-plan, ordre/déduplication,
upload multipart et orientation, purge du cache d'images, anciens auteurs.
Ces validations ne sont pas prétendues acquises par les tests backend.

## Configuration et exploitation

| Réglage | Défaut / usage |
|---|---|
| `CHAT_TEXT_MAX_LENGTH` | 4 000 caractères UTF-8 |
| `CHAT_IMAGE_MAX_BYTES` | 5 242 880 octets, entrée **et sortie** |
| `CHAT_IMAGE_MAX_PIXELS` | 20 000 000, contrôlés avant décodage |
| `CHAT_IMAGES_DIR` | `var/chat-images` en dev, `.chat-private` sur le volume Fly |
| `MERCURE_URL` | URL de publication accessible **au worker** |
| `MERCURE_PUBLIC_URL` | URL de souscription accessible au mobile |
| `MERCURE_JWT_SECRET` | Secret HS256, au moins 32 octets ; environnement uniquement |

`config/packages/chat.yaml` fixe 30 tentatives d'envoi par minute/joueur, rejeux
compris, et 10 demandes de jeton par minute/joueur. Les limiteurs Symfony rendent
429 + Retry-After. Ces plafonds couvrent une conversation humaine tout en bornant
les tentatives de décodage ; ils ne remplacent pas une protection réseau.
PHP accepte 6 Mio par upload et 7 Mio par requête ; Caddy borne les corps de la
route d'envoi à 7 MB. Relever les limites métier nécessite de vérifier également
ces plafonds, la mémoire PHP et le volume.

Local : `make mercure-secrets`, `make build`, `make migrate`, `make up` puis
`make mercure-smoke`. Le fichier ignoré `.env.mercure.local` passe la clé à Caddy ;
`.env.local` et `.env.test.local` la passent à Symfony. Garder ces valeurs identiques.
Le smoke utilise des topics aléatoires sans compte ni conversation réelle :
publication/réception privée, jetons absent/invalide/expiré, interdiction de publier
avec un jeton d'abonné et absence d'événements sur un topic non autorisé.

Fly : fournir `MERCURE_JWT_SECRET` par les secrets, jamais dans `fly.toml`.
La publication du worker utilise l'URL HTTPS publique de `grrind-back` : sa machine
distincte ne porte pas le hub. L'outbox Doctrine est écrite dans la transaction
du message ; les échecs de publication suivent les retries puis `failed` existants.
Surveiller/rejouer cette file avec les commandes Messenger existantes. PostgreSQL,
et non l'historique du hub, reste la source du rattrapage.

### Fichiers, nettoyage et migration

**Une seule machine web** tant que les images sont locales. Le volume existant
est monté sur `/data/game-images`, le chat utilise uniquement son sous-répertoire
privé `.chat-private`. La route publique ne matche ni ces chemins ni leurs noms.
Le worker Messenger n'a pas besoin du volume et n'en assure pas le nettoyage.

Exécuter `make chat-cleanup` en local ; sur Fly, exécuter
`php bin/console app:chat:cleanup` **dans la machine web qui porte le volume**,
après une dissolution ou un échec d'envoi, et périodiquement lors des sessions
de dev. La V1 ne programme pas de cron distant. Les fichiers deviennent
inaccessibles dès la dissolution, leur suppression physique attend cette commande.

Le nettoyage retire seulement les clés sans référence dans `community_guild_message`.
Il prend le même verrou Flock que l'envoi, tenu jusqu'au COMMIT : un fichier en
cours de validation n'est jamais pris pour un orphelin. Cela couvre aussi un crash
entre stockage et transaction. En cas de panne de base, il échoue sans considérer
les fichiers comme orphelins. Un second passage ne supprime rien de plus.

Le volume n'est pas une sauvegarde. Les photos de dev sont jetables à ce stade ;
si elles doivent être conservées, sauvegarder le répertoire privé **et** PostgreSQL
avec une politique de rétention avant de leur donner de la valeur. La commande de
nettoyage ne sait pas recréer un fichier référencé mais perdu.

Avant plusieurs machines web ou le passage AWS : copier les objets vers S3 en
conservant leurs clés UUID relatives, remplacer `ChatImageStorage` et le verrou
local/nettoyage par une stratégie adaptée au stockage objet, puis basculer les
lectures après vérification des copies. Aucun domaine Fly ni chemin absolu n'est
persisté dans les messages. S3 et ce déploiement restent hors du ticket.

Sources : [Mercure Symfony](https://symfony.com/doc/current/mercure.html),
[FrankenPHP Mercure](https://frankenphp.dev/docs/mercure/),
[Image Validator](https://symfony.com/doc/current/reference/constraints/Image.html),
[Symfony Lock](https://symfony.com/doc/current/lock.html).
