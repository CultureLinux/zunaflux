# Lot 3 : exposition HTTP, sessions et durcissement sécurité

**Priorité :** Haute · **Effort :** S–M · **Migration :** non

**Objectif :** corriger le fonctionnement derrière un proxy HTTPS, invalider les sessions des comptes désactivés, ajouter les en-têtes de sécurité et fermer les angles morts SSRF résiduels.

L'audit n'a trouvé **aucune faille critique ou haute**. SSRF, sanitizer HTML, CSRF et cloisonnement multi-utilisateur sont solides. Ce lot regroupe 4 constats moyens et 7 bas.

## Constats et actions

| ID | Sévérité | Constat | Preuve | Action | Effort |
|---|---|---|---|---|---|
| 3.1 | Moyenne | `trusted_proxies` est absent alors que l'application tourne derrière un proxy HTTPS. Conséquences : `cookie_secure: auto` produit des cookies non `Secure`, les URL générées sont en `http`, et `login_throttling` et le limiteur de contact ne voient qu'une seule IP, donc quelques échecs bloquent tout le monde. | `config/packages/framework.yaml` ; `security.yaml:31` ; `ContactController.php:28` ; `docs/SYNTHESE.md:15` | Configurer `trusted_proxies: '%env(default::SYMFONY_TRUSTED_PROXIES)%'` avec `trusted_headers` (`x-forwarded-for`, `x-forwarded-proto`, `x-forwarded-host`). Ajouter la variable à l'ancre d'environnement de `compose.yaml`, à `docker/env.example` et à `.env.example`. Passer `cookie_secure: true` en prod. | S |
| 3.2 | Moyenne | Un compte désactivé garde sa session : Symfony ne compare que le mot de passe, l'identifiant et les rôles. | `src/Entity/User.php:16` (pas d'`EquatableInterface`) ; `src/Security/UserChecker.php` (`checkPostAuth` vide) ; `AdminController.php:80` | Implémenter `EquatableInterface::isEqualTo()` en incluant `status`, ou ajouter un écouteur qui déconnecte si `!isActive()`. | S |
| 3.3 | Moyenne | Aucun en-tête de sécurité : pas de CSP, `X-Frame-Options`/`frame-ancestors`, `nosniff`, `Referrer-Policy` ni HSTS, ce qui laisse l'application exposée au clickjacking. Le widget Ko-fi charge un script tiers sur `/about`. | `docker/frankenphp/Caddyfile` ; `templates/about/index.html.twig:8` | Ajouter les en-têtes via un `ResponseListener` Symfony, qui couvre aussi l'installation hors Docker. CSP en deux temps : `Content-Security-Policy-Report-Only` puis application stricte, avec nonces pour les scripts inline. Remplacer le widget Ko-fi par un simple lien. HSTS à documenter côté proxy. | S (en-têtes) / M (CSP) |
| 3.4 | Moyenne | Inscription publique sans limitation de débit. Le limiteur `login` est défini mais jamais utilisé. | `SecurityController.php:71-124` ; `config/packages/rate_limiter.yaml:3` | Ajouter un limiteur `register` par IP, par exemple 3 par heure (nécessite 3.1). Supprimer ou brancher le limiteur `login`. | S |
| 3.5 | Basse | Le filtrage SSRF laisse passer `100.64.0.0/10` (CGNAT), `198.18.0.0/15`, `64:ff9b::/96` (NAT64), `2002::/16` (6to4) et `2001::/32`, pour `FeedFetcher` et `MastodonHttpClient`. | `src/Domain/Feed/UrlSafetyValidator.php:39` | Centraliser ces exclusions dans `UrlSafetyValidator`, ou imposer l'IPv4 partout comme `FeedImageFetcher`. | S |
| 3.6 | Basse | Le blocage du DOCTYPE par regex se contourne en UTF-16 (BOM) : les entités internes sont alors développées. Aucune entité externe n'est chargée (`LIBXML_NOENT` absent) et le « billion laughs » est rejeté par libxml. | `FeedParser.php:11` ; `OpmlSubscriptionParser.php:12` | Après `loadXML`, rejeter si `$document->doctype?->entities->length > 0`, en défense en profondeur. | S |
| 3.7 | Basse | `switch_user` se déclenche par un simple GET (`?_switch_user=`), sans CSRF : un lien piégé fait basculer un admin dans un autre compte. | `config/packages/security.yaml:34` | Passer par un en-tête (`parameter: X-Switch-User`), ou faire la bascule côté serveur après contrôle CSRF. Interdire d'imiter un autre admin. | M |
| 3.8 | Basse | `/setup` est public tant qu'aucun utilisateur n'existe. | `SecurityController.php:21-27` | Exiger un `ZUNAFLUX_SETUP_TOKEN` facultatif, ou documenter la création de l'admin par la console avant exposition. | S |
| 3.9 | Basse | Des identifiants UUID ou des dates invalides provoquent une erreur 500. | `SubscriptionController.php:235,310` ; `ArticleController::list` (`subscription`, `category`, `from`, `to`) | Utiliser `#[MapQueryParameter]` ou les `requirements` de route, ou un `try/catch` qui renvoie 400/404. | S |
| 3.10 | Basse | L'administration affiche `$e->getMessage()` d'un `\Throwable`, ce qui peut révéler un message SQL. On peut aussi retirer le rôle admin au dernier administrateur. | `AdminController.php:80-82` | Afficher un message générique traduit et journaliser l'erreur. Refuser de rétrograder ou désactiver le dernier admin actif. | S |

## Tests à ajouter

- Fonctionnel : un admin désactive l'utilisateur B pendant que sa session est ouverte ; la requête suivante de B est déconnectée.
- Fonctionnel : avec `SYMFONY_TRUSTED_PROXIES` et `X-Forwarded-Proto: https`, le cookie de session porte `Secure`.
- Fonctionnel : les en-têtes de sécurité sont présents sur `/login` et `/articles`.
- Unitaire : `UrlSafetyValidator` refuse les plages de 3.5.
- Unitaire : un XML UTF-16 avec entités internes est refusé.
- Fonctionnel : l'inscription est limitée après N tentatives ; un UUID invalide renvoie 404 et non 500 ; le dernier admin ne peut pas être rétrogradé.

## Nouvelles variables d'environnement

- `SYMFONY_TRUSTED_PROXIES` : par exemple `127.0.0.1,REMOTE_ADDR` ou le sous-réseau du proxy.
- `ZUNAFLUX_SETUP_TOKEN` : optionnelle, si l'option est retenue en 3.8.

## Validation Linux

Socle commun, puis :

```bash
APP_ENV=test php85 vendor/bin/phpunit --filter 'Security|UrlSafety|Admin|Register|Header'
php85 bin/console debug:config framework trusted_proxies --env=prod
curl -sI http://127.0.0.1:8080/login | grep -Ei 'content-security|x-frame|x-content-type|referrer'
```

## Risques et critères d'acceptation

**Risques :**
- Une CSP stricte peut casser Matomo, les scripts inline ou les images. D'où la phase `Report-Only`.
- Une valeur trop large de `trusted_proxies` permettrait de falsifier l'IP. La documenter précisément.

**Critères d'acceptation :**
- Les cookies sont `Secure` derrière le proxy.
- Le throttling est par IP réelle.
- Un compte désactivé est déconnecté à la requête suivante.
- Les en-têtes sont présents sans régression des tests Playwright.

## Statut d'avancement

*Mis à jour le 20 novembre 2026. Codé, tests exécutés sur la base de test ; en attente du retour de validation du développeur (contrôles derrière le proxy HTTPS en particulier).*

| ID | État | Note |
|---|---|---|
| 3.1 | Partiel | `trusted_proxies`/`trusted_headers` et variable `SYMFONY_TRUSTED_PROXIES` ajoutés (compose, `docker/env.example`, `.env.example`). **Non fait :** `cookie_secure: true` en prod, laissé à `auto` pour ne pas casser une installation HTTP. Le cookie `Secure` derrière proxy n'est pas testé. |
| 3.2 | Appliqué | `User::isEqualTo()` (id, identifiant, mot de passe, rôles, statut). |
| 3.3 | Partiel | `SecurityHeadersSubscriber` : `nosniff`, `X-Frame-Options`, `Referrer-Policy`, `frame-ancestors 'none'`. **Non fait :** CSP stricte avec phase `Report-Only` et nonces, remplacement du widget Ko-fi, HSTS (à poser sur le proxy). |
| 3.4 | Appliqué | Limiteur `register` 3/heure/IP ; limiteur `login` inutilisé supprimé. |
| 3.5 | Appliqué | Plages CGNAT, `198.18.0.0/15`, NAT64, 6to4, Teredo, IPv4-mapped refusées dans `UrlSafetyValidator`. |
| 3.6 | Appliqué | `FeedParser` le faisait déjà ; contrôle `doctype` ajouté à `OpmlSubscriptionParser`. Pas de test avec un XML UTF-16. |
| 3.7 | **Non traité** | `switch_user` en GET : change le parcours d'impersonation, décision requise. |
| 3.8 | **Non traité** | `/setup` public : choix entre `ZUNAFLUX_SETUP_TOKEN` et documentation. |
| 3.9 | Appliqué | UUID de catégorie invalide : 404 ; filtres `subscription`, `category`, `from`, `to` de `/api/articles` : 400. Les autres routes du constat n'ont pas été auditées une à une. |
| 3.10 | Appliqué | Message générique + journalisation à la création d'utilisateur ; refus de désactiver ou rétrograder le dernier admin actif (sans test automatisé). |
