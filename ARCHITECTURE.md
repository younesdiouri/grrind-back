# Le back de GRRIND, en schémas

Ce fichier existe pour être **regardé**, pas lu en entier. Il répond à une seule question :
« qu'est-ce qui se passe dans ce back, et dans quel ordre ? »

Ce qu'il ne porte pas, et où ça vit :

| Ce que vous cherchez | Où c'est |
|---|---|
| Ce qui reste à faire | le [tableau GitHub](https://github.com/users/younesdiouri/projects/1) |
| Le périmètre exact d'une feature | son ticket |
| Pourquoi tel arbitrage a été tranché | la PR qui l'a introduit, et le docblock du fichier concerné |
| Les règles de travail et les invariants | [CLAUDE.md](CLAUDE.md) |

Les schémas sont en Mermaid : GitHub les rend nativement dans cette page.

---

## 1. La carte

Un seul déploiement, six modules à frontières dures. **Un module ne connaît que `Shared`** —
Deptrac le vérifie en CI, et le build casse sur une flèche interdite.

```mermaid
flowchart TB
    ios["📱 App SwiftUI<br/><i>dépôt séparé</i>"]

    subgraph api["API — un seul déploiement, FrankenPHP en mode worker"]
        direction TB
        identity["<b>Identity</b><br/>comptes · JWT · refresh tokens<br/>profil · fuseau"]
        training["<b>Training</b><br/>séances · horloge serveur<br/>garde-fous"]
        progression["<b>Progression</b><br/>ledger XP · niveaux<br/>titres · arbres"]
        rewards["<b>Rewards</b><br/>loot · inventaire<br/><i>pas encore écrit</i>"]
        engagement["<b>Engagement</b><br/>streak · ligues<br/><i>pas encore écrit</i>"]
        shared["<b>Shared</b><br/>vocabulaire d'activité · événements de domaine<br/>ports · idempotence · horloge"]
    end

    ios --> identity
    ios --> training
    ios --> progression

    identity --> shared
    training --> shared
    progression --> shared
    rewards -.-> shared
    engagement -.-> shared

    classDef todo stroke-dasharray:4 4,opacity:0.5
    class rewards,engagement todo
```

**À retenir.** Il n'y a **aucune flèche entre deux modules métier**. Quand l'un a besoin de
l'autre, il passe par l'un des deux seuls chemins autorisés :

```mermaid
flowchart LR
    subgraph ev["1 · Événement de domaine — asynchrone, sans retour"]
        direction LR
        t["Training"] -->|"TrainingSessionCompleted"| out[("outbox")]
        out --> p1["Progression"]
        out -.-> r1["Rewards"]
        out -.-> e1["Engagement"]
    end

    subgraph po["2 · Port dans Shared — synchrone, contrat minuscule"]
        direction LR
        i2["Identity"] -->|"implémente<br/>PlayerTimezones"| s2{{"Shared"}}
        s2 -->|"consommé par"| p2["Progression"]
        p3["Progression"] -->|"implémente<br/>PlayerTitles"| s3{{"Shared"}}
        s3 -->|"consommé par"| i3["Identity"]
    end

    classDef todo stroke-dasharray:4 4,opacity:0.5
    class r1,e1 todo
```

L'événement quand le destinataire peut attendre et n'a rien à répondre. Le port quand la
réponse est nécessaire tout de suite — le fuseau du joueur ne peut pas arriver en différé,
sinon un changement de fuseau suivi d'une séance compterait sur l'ancien.

Un port se justifie **un par un** dans son docblock. Il y en a quatre en tout, et c'est
volontaire : `PlayerTimezones`, `PlayerTitles`, `SocialProfileResolver` (aucun test ne peut
appeler Google), et `ModifierContributor`.

Ce dernier est le seul **en éventail** — plusieurs implémentations, un seul consommateur :

```mermaid
flowchart LR
    p["Progression<br/><i>compétences</i>"] -.->|"implémente<br/>ModifierContributor"| tag{{"tag<br/>app.modifier_contributor"}}
    r["Rewards<br/><i>objets équipés</i>"] -.-> tag
    e["Engagement<br/><i>streak · ligue</i>"] -.-> tag
    tag -->|"AutowireIterator"| res["<b>ModifierResolver</b><br/><i>agrège, ordonne, ne compose rien</i>"]
    res --> xp["XpCalculator"]
    res -.-> loot["LootRoller"]

    classDef todo stroke-dasharray:4 4,opacity:0.5
    class p,r,e,loot todo
```

C'est ce qui tient l'invariant **« un seul vocabulaire de modificateurs »** : compétences,
objets, streak et ligue ne produisent pas chacun leur bonus maison, ils produisent tous un
`Modifier` typé. Ouvrir une source, c'est une classe qui implémente le port — rien à câbler,
personne à prévenir, aucune branche de plus dans le calcul d'XP.

Le resolver **n'additionne rien** : filtrer par discipline et par type sont des décisions de
consommateur, et elles diffèrent — `XpCalculator` groupe par source pour son détail animé, le
`LootRoller` ne lira que `LOOT_LUCK`. Il garantit une seule chose en plus de l'agrégation :
l'ordre, tiré de `ModifierSource` et non de l'ordre dans lequel le conteneur a rangé les
services. Un ensemble résolu finit dans un breakdown affiché et dans un tirage audité ; le
faire dépendre d'un ordre de compilation, c'est accepter que deux calculs identiques laissent
deux traces différentes.

**Aucune source ne contribue encore** — le streak arrive au Lot 5, les objets au Lot 6, les
compétences au Lot 7. Le resolver rend donc un ensemble vide, et c'est le branchement qui est
éprouvé en test : un tag mal orthographié ne casse rien, il rend un ensemble vide, et le
joueur serait sous-payé sans que personne le voie.

---

## 2. La vie d'une séance

C'est le parcours central du produit. **Le serveur possède l'horloge** : le client n'envoie
jamais de date, il annonce seulement une intention.

```mermaid
sequenceDiagram
    autonumber
    participant C as 📱 Client
    participant T as Training
    participant DB as PostgreSQL
    participant OB as outbox
    participant P as Progression

    C->>T: POST /api/training/sessions — discipline
    T->>DB: INSERT training_session — ACTIVE, startedAt = horloge serveur
    Note over T,DB: index unique partiel : une seule séance ACTIVE par compte.<br/>Deux requêtes simultanées, une seule passe.
    T-->>C: 201 — la séance

    Note over C: le joueur s'entraîne

    C->>T: POST /sessions/id/complete + Idempotency-Key
    T->>DB: réserve la clé d'idempotence
    alt clé déjà vue
        DB-->>T: réponse figée
        T-->>C: la même réponse qu'au premier appel
    else première fois
        T->>T: valide la transition · refuse sous le plancher · écrête au plafond
        rect rgb(222,240,222)
            T->>DB: UPDATE session — COMPLETED, durée retenue
            T->>OB: TrainingSessionCompleted
        end
        Note over T,OB: un seul COMMIT. La séance et son événement,<br/>ou ni l'un ni l'autre.
        T-->>C: 200
    end

    OB->>P: consommé en asynchrone par le worker
```

Trois choses que ce schéma dit et qu'il faut avoir en tête :

- **La durée retenue n'est pas `endedAt - startedAt`.** Le plafond écrête : un chronomètre
  oublié rend quatre heures créditées au lieu de tout perdre. C'est la durée retenue qui fait foi.
- **L'`Idempotency-Key` est obligatoire** sur les écritures métier. Les clients mobiles rejouent
  leurs requêtes ; sans elle, une requête perdue en route puis renvoyée afficherait un échec
  pour une action réussie.
- **Un abandon ne produit pas d'événement.** Il n'y a rien à apprendre à quiconque d'une
  séance qui ne compte pas.

Les états d'une séance sont sans retour :

```mermaid
stateDiagram-v2
    [*] --> ACTIVE : POST /sessions
    ACTIVE --> COMPLETED : /complete — au-delà du plancher
    ACTIVE --> ABANDONED : /abandon
    ACTIVE --> ACTIVE : /complete sous le plancher → 422, la séance continue
    COMPLETED --> [*]
    ABANDONED --> [*]
```

Rien ne ramène une séance dans la course. Une erreur se corrige par une transaction d'XP
négative, jamais par une réécriture d'historique.

---

## 3. Ce qui se passe quand l'XP tombe

Le cœur du produit — le moment dopamine. Tout se joue **dans une seule transaction**, et
l'ordre n'est pas négociable.

```mermaid
flowchart TB
    start(["BEGIN"]) --> lock

    lock["🔒 <b>verrou pessimiste</b> sur la ligne<br/>de progression du joueur"]
    lock --> load["lire la charge du jour<br/><i>après le verrou, donc en voyant<br/>ce que la requête concurrente a écrit</i>"]
    load --> mods["résoudre les <b>modificateurs actifs</b><br/><i>après le verrou pour la même raison :<br/>le streak change dans cette transaction-là</i>"]
    mods --> calc["<b>XpCalculator</b> — fonction pure<br/>socle → rendements décroissants<br/>→ bonus → plafond quotidien"]
    calc --> ledger["écrire l'<b>XpTransaction</b><br/><i>montant + rulesetVersion + détail ligne à ligne</i>"]
    ledger --> snap["reprojeter le <b>snapshot</b><br/><i>sur SUM(ledger), jamais un +=</i>"]
    snap --> titles["évaluer les <b>titres</b><br/><i>après l'écriture : la séance qui vient<br/>d'être créditée compte pour son titre</i>"]
    titles --> loot["tirer le <b>loot</b> · mettre à jour le <b>streak</b>"]
    loot --> obx["écrire les événements dans l'<b>outbox</b>"]
    obx --> done(["COMMIT → RewardSummary"])

    classDef todo stroke-dasharray:4 4,opacity:0.5
    class loot todo
```

**Ce qui existe aujourd'hui**, c'est tout sauf la case en pointillés — et le tout n'est pas
encore branché sur la complétion de séance : `GrantXpHandler` porte déjà la séquence,
[le ticket 21](https://github.com/younesdiouri/grrind-back/issues/21) l'appellera depuis
`CompleteSessionHandler` et
[le 22](https://github.com/younesdiouri/grrind-back/issues/22) en fera le `RewardSummary`.

Pourquoi cet ordre, en trois phrases :

- **Le verrou d'abord.** Il porte sur *une ligne* — deux joueurs ne s'attendent jamais. Sans
  lui, deux complétions simultanées lisent le même total, calculent le même niveau et
  s'écrasent l'une l'autre.
- **La lecture du jour et des modificateurs après le verrou.** Sinon les rendements
  décroissants se contourneraient en clôturant deux séances à la même seconde, et un ensemble
  de bonus lu avant la transaction créditerait un streak déjà périmé.
- **Le snapshot relit le ledger, il ne s'incrémente pas.** Un `+=` transformerait chaque
  divergence en dette permanente ; là, un écart se résorbe tout seul au crédit suivant.

Et le calcul lui-même, qui est une **fonction pure et versionnée** :

```mermaid
flowchart LR
    d["durée retenue<br/>+ discipline"] --> base["socle<br/><i>durée × xp_par_heure / 3600</i>"]
    base --> dim["− rendements décroissants<br/><i>selon le temps déjà fait aujourd'hui</i>"]
    dim --> bonus["+ bonus<br/><i>en % du socle raboté, additifs</i>"]
    mods["modificateurs actifs<br/><i>ModifierResolver</i>"] --> bonus
    bonus --> cap["− dépassement du plafond<br/>quotidien de la discipline"]
    cap --> out["<b>XpAward</b><br/>montant + rulesetVersion<br/>+ le détail de chaque ligne"]
```

**Les bonus sont additifs sur le socle**, pas multiplicatifs : `90 + 18 + 13 = 121`, et non
`90 × 1,20 × 1,15`. Chaque ligne du détail reste ainsi vraie isolément — « +18 grâce à ta
série » ne dépend pas de ce qui a été appliqué avant. Et chaque garde-fou a **sa ligne dans le
détail** : montrer ce qui a été rogné est ce qui sépare une mécanique d'une punition.

---

## 4. Le modèle de données

```mermaid
erDiagram
    identity_user ||--o{ identity_refresh_token : "une famille par appareil"
    identity_user ||--o{ identity_social_identity : "Google · Apple"
    xp_transaction ||--|{ xp_transaction_line : "le détail du calcul"
    player_title ||--o| player_active_title : "clé étrangère composée"

    identity_user {
        uuid id PK "UUID v7"
        string email UK "normalisée en minuscules"
        string password "null si compte social"
        json roles
        string timezone "le streak se calcule dedans"
    }
    identity_refresh_token {
        uuid id PK
        uuid family_id "une famille = un appareil"
        string token_hash UK "SHA-256 seul, jamais le jeton"
        timestamptz consumed_at "le rejeu révoque la famille"
    }
    training_session {
        uuid id PK
        uuid user_id "pas de FK — autre module"
        enum status "index unique partiel sur ACTIVE"
        timestamptz started_at "horloge serveur"
        int duration_seconds "la durée RETENUE, écrêtée"
        enum source "MANUAL_TIMER puis STRAVA"
        enum trust "DECLARED puis PROVIDER_VERIFIED"
    }
    xp_transaction {
        uuid id PK
        uuid user_id
        uuid source_id "la séance — UK avec reason"
        int amount "signé, somme de ses lignes"
        enum reason "COMPLETED | INVALIDATED"
        int duration_seconds "signée elle aussi"
        string ruleset_version "les règles du jour du calcul"
    }
    progression_snapshot {
        uuid user_id PK "la ligne qu'on verrouille"
        int total_xp
        int level "projeté, jamais saisi"
        int earned_skill_points
    }
    player_title {
        uuid user_id PK
        string title_id PK "pas de FK — catalogue en YAML"
        timestamptz unlocked_at "définitif"
    }
    shared_idempotency_key {
        uuid user_id "l'unicité est (user, clé)"
        string key
        json response "figée et rejouée"
    }
```

Quatre lectures de ce schéma :

- **Aucune clé étrangère ne traverse une frontière de module.** `training_session.user_id` et
  `xp_transaction.user_id` ne référencent rien : la frontière vaut pour les tables autant que
  pour les classes.
- **`xp_transaction` est la vérité, `progression_snapshot` est un cache.** Le niveau est une
  projection ; le snapshot se reconstruit intégralement du ledger, et
  [le ticket 20](https://github.com/younesdiouri/grrind-back/issues/20) le prouvera en le
  réécrivant à l'identique.
- **Le ledger est append-only.** Aucun mutateur sur les entités, et un listener Doctrine refuse
  `UPDATE` et `DELETE`. Une séance invalidée écrit une transaction **négative** ; on ne supprime
  rien.
- **`player_title` ne se vide jamais.** Un titre débloqué ne se reprend pas, même si une séance
  annulée fait repasser le compteur sous le seuil : l'XP est une monnaie, un titre est un
  souvenir.

Le catalogue de titres, la courbe de niveaux, le barème d'XP et les tables de loot **ne sont pas
en base** — voir la section 6.

---

## 5. L'authentification

Le client détient deux jetons de nature différente : un JWT court qui ne se révoque pas, et un
refresh token long, à usage unique, rotatif.

```mermaid
sequenceDiagram
    autonumber
    participant C as 📱 Client
    participant F as Firewall Symfony
    participant I as Identity
    participant DB as PostgreSQL

    rect rgb(224,232,245)
    Note over C,DB: Connexion — json_login du composant, rien d'écrit à la main
    C->>F: POST /api/auth/login — email + password
    F->>DB: charge le compte par son adresse normalisée
    F->>F: vérifie le mot de passe · rehash opportuniste<br/>adresse inconnue et mot de passe faux : même réponse
    F->>I: JWT signé RS256, 15 min, sub = UUID du compte
    I->>DB: ouvre une famille de refresh tokens — une par appareil
    I-->>C: profil + accessToken + refreshToken
    end

    rect rgb(248,236,216)
    Note over C,DB: Rafraîchissement — rotation à usage unique
    C->>I: POST /api/auth/refresh — refreshToken
    I->>DB: cherche le SHA-256 du jeton
    alt jeton valide et jamais consommé
        I->>DB: le marque consommé · en émet un nouveau dans la même famille
        I-->>C: nouveau couple de jetons
    else jeton déjà consommé
        I->>DB: 🔥 révoque TOUTE la famille
        I-->>C: 401
        Note over I,DB: on ne peut pas distinguer le voleur du vrai client<br/>qui a été doublé, donc on coupe.
    end
    end
```

Ce qui compte, et qui n'est pas visible dans le code parce que c'est le framework qui le fait :

- **L'identifiant de sécurité est l'UUID, pas l'adresse.** Changer d'e-mail n'invalide aucun
  jeton, et l'adresse ne voyage pas dans le claim `sub`.
- **Aucune route ne prend d'identifiant de compte en paramètre.** Le `User` vient du jeton, via
  `#[CurrentUser]`. Une route ne peut donc pas être détournée pour lire le profil d'un autre.
- **Le social sign-in relie par le couple (fournisseur, `sub`), jamais par l'adresse.**
  Rattacher un compte préexistant exige que le fournisseur **certifie** l'adresse — sinon il
  suffirait de créer chez Google un compte portant l'adresse de la victime.

---

## 6. La balance du jeu est du code, pas de la donnée

Courbe de niveaux, barème d'XP, garde-fous, catalogue de titres : tout vit en YAML versionné
sous `config/game/v1/`, **lu une seule fois, à la compilation du conteneur**.

```mermaid
flowchart TB
    subgraph yaml["config/game/v1/ — versionné, relu en PR"]
        y1["training.yaml"]
        y2["xp.yaml"]
        y3["levels.yaml"]
        y4["titles.yaml"]
    end

    yaml --> pass["<b>GameBalancePass</b><br/><i>compilation du conteneur</i>"]
    schema["un schéma par fichier,<br/>dans le module qui le lit"] --> pass

    pass -->|"valide, ou casse le build"| params["paramètres scalaires<br/>game.xp.disciplines …"]
    pass -->|"SHA-256 de la config normalisée"| version["rulesetVersion<br/>v1-fe4edd019948"]

    params --> objs["objets typés du domaine<br/>XpRates · LevelCurve · TitleCatalog<br/>DiminishingReturns · TrainingRules"]
    version --> tx["stocké sur <b>chaque</b> XpTransaction"]

    trad["translations/titles.fr.yaml<br/>translations/titles.en.yaml"] -.->|"hors du hash — les mots<br/>ne sont pas de l'équilibrage"| objs
```

**Trois conséquences voulues :**

- **Un YAML incohérent casse le build**, pas la première requête d'un joueur. En mode worker
  FrankenPHP, aucune requête ne rouvre jamais un fichier.
- **Un fichier posé là sans schéma casse aussi le build.** Sinon ce serait du réglage que
  personne ne lit et que rien ne valide : le silence complet.
- **Le `rulesetVersion` est stocké avec chaque montant d'XP.** On peut donc rééquilibrer sans
  corrompre l'historique : une transaction dit sous quelles règles elle a été accordée. Une
  annulation reprend la version du crédit qu'elle annule — recalculer aux règles courantes ferait
  de chaque rééquilibrage une redistribution silencieuse.

Le hash porte sur la configuration **normalisée**, défauts appliqués : reformater un YAML ne
date pas un rééquilibrage, mais un défaut de schéma qui bouge, si.

---

## 7. Surface d'API

Toute erreur sort en `application/problem+json`, avec un `type` stable sous
`https://grrind.app/problems/` — c'est là-dessus que le client branche ses messages, jamais sur
le `detail`.

| Route | Auth | Réponse |
|---|---|---|
| `GET /health` | — | état de la base |
| `POST /api/auth/register` | — | 201, compte + jetons |
| `POST /api/auth/login` | — | 200, compte + jetons |
| `POST /api/auth/refresh` | refresh token | 200, compte + jetons tournés |
| `POST /api/auth/logout` | refresh token | 204 |
| `POST /api/auth/social/{google\|apple}` | code d'autorisation | 200, compte + jetons |
| `GET · PATCH /api/me` | Bearer | profil, titre porté, prochain titre |
| `POST /api/training/sessions` | Bearer | 201, séance ouverte |
| `POST /api/training/sessions/{id}/complete` | Bearer + `Idempotency-Key` | 200, séance close |
| `POST /api/training/sessions/{id}/abandon` | Bearer + `Idempotency-Key` | 200, séance abandonnée |
| `GET /api/training/sessions` | Bearer | 200, page d'historique + `nextCursor` |
| `GET /api/training/sessions/active` | Bearer | 200 séance en cours, ou **204** |
| `GET /api/titles` | Bearer | 200, catalogue situé + titre porté |
| `PUT /api/titles/active` | Bearer | 200, catalogue après changement |

**Une forme par concept, partout.** `register`, `login`, `refresh` et `social` rendent le même
`AuthResource`. Une séance ouverte et une séance close sont le même objet, avec des champs à
`null`. Un titre a la même forme dans le profil et dans le catalogue. Le client iOS décode un
seul type par concept — un champ qui apparaît et disparaît selon la route finit lu de travers.

---

## 8. Ce qui tourne en asynchrone

```mermaid
flowchart LR
    tx["transaction métier"] -->|"INSERT dans le même COMMIT"| mq[("messenger_messages")]
    mq --> w["worker Messenger<br/><i>service ECS séparé</i>"]
    w --> lb["classements"]
    w --> notif["notifications"]
    w -->|"3 échecs"| failed[("transport failed")]

    classDef todo stroke-dasharray:4 4,opacity:0.5
    class lb,notif todo
```

Le transport Doctrine n'est pas un pis-aller en attendant « un vrai broker » : c'est ce qui rend
la publication **atomique**. Publier après le `COMMIT` perd l'événement si le processus meurt
entre les deux ; publier avant annonce un fait encore annulable.

---

## 9. Les décisions qu'on « corrigerait » dans le mauvais sens

Une ligne chacune, parce qu'elles sont contre-intuitives et que le réflexe naturel est de les
défaire. Le raisonnement complet est dans le docblock du fichier concerné.

- **Pas de bus de commandes.** Les contrôleurs appellent les handlers directement. Messenger
  sert l'asynchrone, pas à ajouter une indirection synchrone.
- **Les objets commande restent** malgré l'absence de bus : ils donnent un domicile aux
  invariants (« le *quand* n'est pas un paramètre ») et rendent les handlers testables sans HTTP.
- **Une lecture sans règle n'a pas de handler.** Le contrôleur appelle le dépôt. Une classe qui
  relaie un `findOneBy` est l'indirection que la règle n°0 interdit.
- **L'append-only est tenu par l'applicatif, pas par un trigger.** Un `DELETE` en SQL direct
  passerait au travers — c'est le prix, il est assumé.
- **Les refresh tokens sont faits main** alors qu'un bundle de référence existe : il stocke le
  jeton en clair, ignore la notion de famille et ne détecte pas le rejeu.
- **Le snapshot stocke les points de compétence *accordés*, pas *disponibles*.** Un solde
  rendrait le cache irreconstructible, puisque le ledger ignore les dépenses.
- **Une clôture trop courte est refusée, pas requalifiée en abandon.** Entre deux options, celle
  qui ne détruit rien.
- **Pas de Redis en v1**, et le déclencheur est écrit : le jour où il y a plus d'un conteneur
  applicatif **et** un besoin d'état partagé. Le verrou de complétion est pessimiste sur une
  ligne ; un verrou distribué serait un affaiblissement.
- **Pas de valeur « autre » dans `Discipline`**, ni de « correction manuelle » dans `XpReason`.
  Une valeur qu'aucun code n'écrit est une porte qu'on finit par pousser.
- **Le `ModifierResolver` agrège, il ne compose pas.** Additionner ou filtrer chez lui
  obligerait ses deux consommateurs, qui ne veulent ni le même type ni la même portée, à
  défaire son travail.

---

## 10. Ce qui mord

Les pièges qui se reproduisent. Les autres sont datés, corrigés, et vivent dans `git log`.

- **`make migrate` avant `make migration`.** Le diff compare au schéma de la base de dev, pas
  aux migrations écrites : une base en retard fait reproduire ses instructions dans la suivante.
  Relire chaque diff en cherchant ce qui ne parle pas du ticket en cours.
- **Le diff Doctrine ne sait pas renommer une colonne.** Il propose `DROP` + `ADD`, et des
  `NOT NULL` sans défaut qui échouent sur une table peuplée.
- **Après tout `composer require`, lire `git status`.** Les recettes Flex écrasent des fichiers
  de config existants — `deptrac.yaml` et `compose.yaml` y sont déjà passés.
- **Un index partiel se déclare tel que PostgreSQL le *relit***, casts compris, sinon chaque
  diff reproposera le même `DROP` + `CREATE`.
- **Doctrine hydrate une projection de champ à travers son type.** `->select('u.timezone')` rend
  un `Timezone`, pas une chaîne. Se méfier de tout repli silencieux sur une valeur plausible.
- **Symfony ne charge pas `.env.local` en environnement `test`** — d'où le `.env.test.local` que
  `make secrets` écrit aussi. Un secret oublié là produit vingt échecs qui ressemblent à tout
  sauf à un problème de configuration.
- **Lexik dispatche ses événements sous un nom, pas sous leur classe.** Un
  `#[AsEventListener(event: JWTNotFoundEvent::class)]` ne se déclenche jamais, sans erreur.
- **Vérifier le corps d'une erreur, pas seulement son code.** Un membre d'extension nommé comme
  un membre standard de problem+json disparaît silencieusement de la réponse.
- **Un service tagué qui n'est pas ramassé ne lève rien.** Tag mal orthographié,
  autoconfiguration coupée, service retiré parce qu'inutilisé : l'itérateur est simplement
  vide. Un contributeur de modificateurs qui se tait sous-paie un joueur en silence, et
  l'écriture est au ledger — donc chaque port en éventail se prouve contre le vrai conteneur.
