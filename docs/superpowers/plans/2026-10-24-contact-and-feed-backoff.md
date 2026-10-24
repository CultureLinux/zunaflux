# Contact et temporisation de collecte Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ajouter un formulaire de contact public et empêcher la remise en file trop fréquente des flux en échec.

**Architecture:** Un contrôleur Symfony envoie le message en texte brut avec le Mailer configuré, après validation, CSRF et rate limiting. La santé du flux persiste une prochaine date autorisée ; la commande d'enqueue la consulte avant de créer un message Messenger.

**Tech Stack:** Symfony 8.1, Twig, Symfony Mailer/Rate Limiter, Doctrine ORM et Migrations, MariaDB 10.11, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-10-24-contact-and-feed-backoff-design.md`

## Global Constraints

- Ne pas ajouter de dépendance.
- Cible MariaDB 10.11 ; aucune syntaxe PostgreSQL.
- Ne pas journaliser les contenus ou adresses du formulaire.
- Le User-Agent exact est `Zunaflux/1.0 (+https://zunaflux.clinux.fr/contact)`.
- Délai exponentiel : 1 h, 2 h, 4 h, …, plafonné à 24 h ; succès = réinitialisation.
- Pas de commit, push, migration ni déploiement automatique.

## Review Focus

- Une requête POST sans jeton CSRF doit être refusée sans envoyer de mail.
- Une adresse e-mail de réponse invalide ne doit pas être transmise au Mailer.
- La limite de débit doit retourner une réponse utilisateur sans divulguer de détail interne.
- Un flux ayant réussi après des échecs doit redevenir immédiatement éligible.
- Une date de reprise exactement égale à l'instant courant doit autoriser l'enqueue.

### Task 1: État de temporisation du flux

**Files:**
- Create: `migrations/Version20261024180000.php`
- Modify: `src/Entity/Feed.php`
- Test: `tests/Unit/Entity/FeedTest.php`

**Interfaces:**
- Produces `Feed::isCollectionDue(\DateTimeImmutable $now): bool`,
  `Feed::recordFailure(\Throwable $error, \DateTimeImmutable $now): void` and
  `Feed::recordSuccess(): void`.

- [ ] Écrire les tests rouges : premier échec (1 h), doublement du délai,
  plafond 24 h, succès qui réinitialise, et échéance égale à maintenant.
- [ ] Exécuter `php85 bin/phpunit tests/Unit/Entity/FeedTest.php` et constater
  l'échec dû à l'API absente.
- [ ] Ajouter `next_collection_at` nullable à l'entité et la migration MariaDB,
  puis calculer `min(2^(failure_count) heures, 24 heures)`.
- [ ] Exécuter à nouveau le test unitaire ciblé et constater son succès.

### Task 2: Collecte respectueuse et commande d’enqueue

**Files:**
- Modify: `src/Domain/Feed/FeedFetcher.php`
- Modify: `src/Command/CollectFeedsCommand.php`
- Test: `tests/Unit/Entity/FeedTest.php`

**Interfaces:**
- Consumes `Feed::isCollectionDue()` de la tâche 1.
- Produces une commande qui n’enqueue que les flux dus et un User-Agent
  d'identification stable.

- [ ] Étendre le test rouge pour montrer qu'un flux différé n'est pas éligible.
- [ ] Exécuter le test ciblé et constater l'échec attendu.
- [ ] Utiliser `isCollectionDue(new \DateTimeImmutable())` dans la commande et
  remplacer le User-Agent du fetcher par la valeur définie par la spécification.
- [ ] Exécuter le test ciblé et constater son succès.

### Task 3: Formulaire et envoi de contact

**Files:**
- Create: `src/Controller/ContactController.php`
- Create: `templates/contact/index.html.twig`
- Modify: `config/packages/rate_limiter.yaml`
- Modify: `templates/base.html.twig`
- Test: `tests/Functional/Controller/ContactControllerTest.php`

**Interfaces:**
- Produit les routes publiques GET/POST `/contact` nommées `app_contact`.
- Envoie un `Symfony\Component\Mime\Email` à `products@clinux.fr` avec
  `Reply-To` égal à l'adresse validée du visiteur.

- [ ] Écrire les tests rouges : page accessible sans session, POST valide avec
  envoi, CSRF invalide refusé, e-mail invalide et dépassement de limite.
- [ ] Exécuter `php85 bin/phpunit tests/Functional/Controller/ContactControllerTest.php`
  et constater l'échec car la route n'existe pas.
- [ ] Ajouter le limiter, le contrôleur et le template : labels visibles,
  erreurs à proximité des champs, statut de succès et formulaire mobile.
- [ ] Exécuter le test fonctionnel ciblé et constater son succès.

### Task 4: Vérification de lot

**Files:**
- Modify: seulement les fichiers des tâches 1 à 3.

- [ ] Lancer les tests PHP ciblés puis `php85 bin/phpunit`.
- [ ] Lancer `php85 bin/console lint:container` et
  `php85 bin/console lint:twig templates/`.
- [ ] Lancer `npm test` et `npm run build` uniquement si Node et les scripts
  correspondants sont présents sur l'environnement Linux.
- [ ] Examiner `git diff --check` et `git status --short` ; ne pas créer de
  commit.
