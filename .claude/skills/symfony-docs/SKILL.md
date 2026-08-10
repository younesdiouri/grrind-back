---
name: symfony-docs
description: Consulte la documentation officielle Symfony sur symfony.com/doc avant d'écrire du code qui touche à un composant Symfony. À utiliser dès qu'il est question de sécurité/firewall/authenticator, validation, sérialisation, Doctrine/DoctrineBundle, Messenger, HttpClient, formulaires, cache, console, DI/autowiring, routing, mailer, rate limiter, workflow, uid, clock, ou d'un bundle tiers de l'écosystème (Lexik JWT, KnpU OAuth2, MakerBundle) — et systématiquement avant de coder soi-même quelque chose que Symfony fournit déjà.
---

# Documentation Symfony

Ce projet a une règle : **on utilise ce que Symfony fournit, on ne le réécrit pas.** Ce skill
existe pour que cette règle s'appuie sur la doc courante plutôt que sur des souvenirs.

## Version de référence

Le projet tourne sur **Symfony 8.1**. Toujours lire `https://symfony.com/doc/current/…`
(`current` = la dernière stable). Si une page consultée montre un bandeau signalant une autre
version, corriger l'URL — les exemples de la 6.4 induisent régulièrement en erreur sur la
sécurité et la sérialisation.

## Quand déclencher

Avant d'écrire la première ligne, dès que la tâche touche :

- **Sécurité** — firewall, authenticator, provider, voter, hachage de mot de passe, rôles,
  `#[IsGranted]`, `access_control`, login programmatique, impersonation.
- **Validation** — contraintes, groupes, validateurs sur mesure, `#[MapRequestPayload]`.
- **Doctrine** — mapping, types personnalisés, verrous, migrations, lazy/eager, filtres.
- **Sérialisation** — normalizers, contextes, groupes, `#[Groups]`, `#[SerializedName]`.
- **Le reste du framework** — Messenger, HttpClient, Console, DI, Routing, Cache, Mailer,
  RateLimiter, Workflow, Uid, Clock, Lock, Notifier.
- **Un bundle de l'écosystème** — LexikJWTAuthenticationBundle, KnpUOAuth2ClientBundle,
  MakerBundle, DoctrineMigrationsBundle.
- **Toute intention de coder à la main** un mécanisme qui ressemble à un service Symfony.

## Comment procéder

1. **Aller à la page canonique.** Les entrées les plus utiles ici :

   | Sujet | URL |
   |---|---|
   | Sécurité (page maîtresse) | `https://symfony.com/doc/current/security.html` |
   | Authenticators sur mesure | `https://symfony.com/doc/current/security/custom_authenticator.html` |
   | User provider | `https://symfony.com/doc/current/security/user_providers.html` |
   | Voters | `https://symfony.com/doc/current/security/voters.html` |
   | Access token / API | `https://symfony.com/doc/current/security/access_token.html` |
   | Validation | `https://symfony.com/doc/current/validation.html` |
   | Contraintes disponibles | `https://symfony.com/doc/current/reference/constraints.html` |
   | Serializer | `https://symfony.com/doc/current/serializer.html` |
   | Doctrine | `https://symfony.com/doc/current/doctrine.html` |
   | Types Doctrine personnalisés | `https://symfony.com/doc/current/doctrine/custom_dbal_type.html` |
   | Messenger | `https://symfony.com/doc/current/messenger.html` |
   | Injection de dépendances | `https://symfony.com/doc/current/service_container.html` |
   | Contrôleurs et arguments | `https://symfony.com/doc/current/controller.html` |
   | Value resolvers | `https://symfony.com/doc/current/controller/value_resolver.html` |
   | Configuration / secrets | `https://symfony.com/doc/current/configuration/secrets.html` |
   | Rate limiter | `https://symfony.com/doc/current/rate_limiter.html` |
   | MakerBundle | `https://symfony.com/doc/current/bundles/SymfonyMakerBundle/index.html` |
   | Lexik JWT | `https://github.com/lexik/LexikJWTAuthenticationBundle/blob/3.x/README.md` |
   | KnpU OAuth2 Client | `https://github.com/knpuniversity/oauth2-client-bundle/blob/main/README.md` |

   Si le sujet n'est pas dans cette table, chercher d'abord sur
   `https://symfony.com/search?q=<terme>`, puis se rabattre sur une recherche web.

2. **Lire, ne pas deviner.** Récupérer la page (WebFetch) et en extraire la configuration et
   les noms de classes/services réels. Les noms de services Symfony changent entre versions
   majeures ; les inventer produit un échec silencieux à l'exécution.

3. **Vérifier ce qui est déjà installé** avant de conclure qu'il faut écrire du code :

   ```bash
   make console c="debug:container <terme>"      # un service existe-t-il déjà ?
   make console c="debug:autowiring <terme>"     # quel type injecter ?
   make console c="debug:config <bundle>"        # config effective d'un bundle
   make console c="debug:router"                 # routes réellement exposées
   make console c="debug:firewall api"           # authenticators actifs sur un firewall
   make console c="debug:event-dispatcher"       # à quel événement se brancher
   make console c="lint:container"
   ```

4. **Préférer MakerBundle** pour tout squelette qu'il sait produire (`make:entity`,
   `make:user`, `make:auth`, `make:voter`, `make:validator`, `make:migration`,
   `make:security:custom`). Générer, puis relire et adapter aux conventions du projet —
   la sortie du maker n'est pas sacrée, mais elle donne les bons points d'accroche.

   ```bash
   make console c="list make"
   ```

5. **Citer sa source.** Quand une décision découle de la doc, mentionner l'URL dans le message
   de commit ou le commentaire. La prochaine session saura d'où vient le choix.

## Arbitrage

Si la doc offre plusieurs chemins (ex. `json_login` contre un authenticator sur mesure), poser
le choix à l'utilisateur avec le compromis explicite plutôt que de trancher seul. C'est une règle
du projet, pas une politesse : voir la section « Priorité à l'écosystème Symfony » de CLAUDE.md.

## Anti-exemples déjà payés dans ce dépôt

- Un VO `Email` avec `filter_var` là où `#[Assert\Email]` faisait le travail.
- Un port `PasswordHasher` maison enveloppant `UserPasswordHasherInterface`.
- Un `LogInHandler` vérifiant le mot de passe à la main au lieu de `json_login`.
- `getRoles()` retournant `['ROLE_USER']` en dur au lieu d'une colonne `roles`.
- Un `#[AsEventListener(event: JWTNotFoundEvent::class)]` qui ne se déclenche jamais : Lexik
  dispatche sous un **nom**, pas sous la classe. Lire le README du bundle, pas l'inférer.
