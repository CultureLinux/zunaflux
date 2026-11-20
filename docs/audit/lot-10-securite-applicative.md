# Lot 10 : sécurité applicative et valeurs par défaut

**Priorité :** Haute · **Effort :** M · **Migration :** non · **Changements incompatibles :** oui (à consigner dans le README)

**Objectif :** que Zunaflux soit sûr dès l'installation, sans réglage, pour une première version publique.

| ID d'origine | Point | Décision à arbitrer | Recommandation |
|---|---|---|---|
| 3.1 | `cookie_secure` : passer de `auto` à `true` en production | Casse une installation en HTTP simple derrière aucun proxy | `true` en `prod`, variable `ZUNAFLUX_INSECURE_COOKIES=1` pour les tests locaux ; section README |
| 3.1 | Test du cookie `Secure` derrière proxy (`X-Forwarded-Proto: https`) | Aucune | À écrire (test fonctionnel) |
| 3.3 | CSP en `Report-Only` avec nonces pour les scripts en ligne | Où envoyer les rapports (journal applicatif ou point de collecte) | Journal applicatif, route `POST /csp-report` limitée en débit |
| 3.3 | Remplacer le widget Ko-fi (script tiers sur `/about`) par un simple lien | Aucune | Lien |
| 3.7 | `switch_user` déclenché par un simple GET (`?_switch_user=`) | Garder l'imitation, mais la rendre non déclenchable par lien piégé | Bascule serveur après contrôle CSRF (route POST dédiée), interdiction d'imiter un autre administrateur |
| 3.8 | `/setup` public tant qu'aucun compte n'existe | `ZUNAFLUX_SETUP_TOKEN` obligatoire ou documentation seule | Jeton facultatif : s'il est défini, il est exigé ; sinon `app:user:create --admin` documenté dans le README |
| 3.6 | Test d'un XML UTF-16 avec entités (flux et OPML) | Aucune | À écrire |
| 3.10 | Test du refus de rétrograder le dernier administrateur (jeton CSRF) | Aucune | Déjà couvert par `AdminUserRoutesTest` : à confirmer |

## Validation
Tests PHPUnit (cookie, jeton de `/setup`, imitation), puis contrôle manuel derrière un proxy HTTPS (cookie `Secure`, rapports CSP dans le journal).

## Critères d'acceptation
- Cookie de session `Secure` par défaut en production.
- Plus aucune action d'administration déclenchable par un simple lien.
- La CSP en `Report-Only` produit des rapports exploitables sans rien bloquer.

## Statut d'avancement

*Mis à jour le 20 novembre 2026. Codé et testé en local ; en attente du retour de validation du développeur (contrôle derrière un vrai proxy HTTPS, lecture du journal CSP).*

| ID | État | Note |
|---|---|---|
| 3.1 | Appliqué | Cookies de session et remember-me `Secure` en production, désactivables par `ZUNAFLUX_COOKIE_SECURE=0`. Test `ProductionCookiesTest` (noyau de production). **Non vérifié :** un vrai proxy HTTPS. |
| 3.3 | Appliqué | `Content-Security-Policy-Report-Only` avec nonce par requête (`CspNonce`, `csp_nonce()`), point de collecte `POST /csp-report` (public, limité à 30 par minute et par IP, 8 Ko, journal sans chaîne de requête). Le widget Ko-fi n'existait plus : la page « À propos » n'a qu'un lien. HSTS reste à poser sur le proxy. |
| 3.6 | Appliqué | Tests d'un XML UTF-16 avec entités (flux et OPML). |
| 3.7 | Appliqué | Le paramètre `switch_user` du pare-feu est supprimé ; la bascule se fait côté serveur (`SwitchUserToken`) après contrôle CSRF, interdite vers soi-même, un autre administrateur ou un compte inactif, et pendant une imitation. Tests dont la régression du lien piégé. |
| 3.8 | Appliqué | `ZUNAFLUX_SETUP_TOKEN` facultatif, comparé à temps constant, champ affiché seulement s'il est défini. |
| 3.10 | Confirmé | Le refus de rétrograder le dernier administrateur est couvert par `AdminUserRoutesTest`. |

**Retour du développeur (20 novembre 2026) :** commandes de validation Linux réussies ; derrière un nginx, connexion, déconnexion, reconnexion et changement de langue sans erreur. **Contrôlés ensuite :** drapeau `Secure` du cookie, imitation d'utilisateur (« Imiter » puis « Revenir à mon compte ») et journal CSP : les trois points sont OK. **Lot validé.**

**Rappel de validation :** README, section « Changements incompatibles ».

