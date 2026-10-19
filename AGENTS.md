# AGENTS.md — Mon Flux RSS

## Rôle et contexte
Tu interviens comme agent de développement sur Mon Flux RSS, lecteur RSS multi-utilisateur sous Symfony LTS, Twig, Stimulus, Tailwind CSS et MariaDB 10.11. La licence cible est AGPL-3.0-only. Le cahier des charges du dépôt est la référence fonctionnelle ; ne modifie pas les décisions validées sans accord du développeur.

## Environnement de travail : Windows
- L'agent s'exécute sur **Windows** (terminal PowerShell).
- **PHP, Composer et Node.js/npm ne sont pas installés** sur cette machine. Ne suppose jamais qu'ils sont disponibles.
- Ne tente pas d'installer ces outils, de modifier le PATH ou de contourner l'absence d'environnement sans accord explicite.
- Tu peux lire, créer et modifier les sources, configurations, migrations, tests et documentation ; tu peux effectuer des vérifications statiques qui n'exigent pas ces outils.
- N'affirme jamais avoir exécuté PHPUnit, PHPStan, Composer, npm, migrations ou tests applicatifs sur Windows si ce n'est pas le cas.

## Git : interdictions absolues
- **Ne fais jamais de `git commit`, `git push`, `git tag`, `git merge`, `git rebase` ou création de release.**
- Ne modifie pas l'historique Git et ne change pas de branche sans demande explicite.
- Ne fais pas de `git add` automatiquement ; le développeur choisit ce qui est indexé.
- Les modifications restent dans l'arbre de travail. Le développeur est seul responsable des commits, des pushes et de la publication.
- `git status` et `git diff` sont autorisés pour rendre compte des changements.

## Validation et déploiement : responsabilité du développeur
- L'environnement cible de validation et de déploiement est un **serveur Linux** avec les dépendances nécessaires.
- L'agent **ne déploie pas**, ne lance pas de migrations sur une base distante et ne redémarre pas les services de production.
- Pour **chaque lot**, fournis au développeur les commandes exactes à exécuter sur Linux pour installer les dépendances nécessaires, vérifier la syntaxe, lancer les analyses statiques, les tests, les migrations (si applicables), construire les assets et contrôler les services.
- Distingue clairement les commandes de **validation sans effet sur les données** de celles qui **modifient la base ou l'environnement**. Les secondes exigent une validation humaine avant exécution.
- Les commandes proposées doivent être adaptées aux scripts et fichiers réellement présents dans le dépôt : ne suppose pas qu'une commande custom existe.
- Pour chaque commande, indique le résultat attendu et les points de contrôle. En cas d'échec, propose une procédure de diagnostic et ne déclare pas le lot validé.
- Ne considère un lot terminé qu'après retour explicite du développeur sur les validations Linux.

## Contrat de livraison de chaque lot
À la fin de chaque lot, rends un compte-rendu contenant :
1. Résumé des fonctionnalités livrées et fichiers modifiés.
2. Migrations, nouvelles variables d'environnement et impacts de compatibilité.
3. Vérifications réellement effectuées sur Windows et celles non exécutées.
4. **Bloc PowerShell** pour les vérifications locales possibles.
5. **Bloc Bash Linux** avec les commandes de validation, dans l'ordre, sans opérations destructrices implicites.
6. Commandes de migration/déploiement **séparées**, signalées comme nécessitant une approbation humaine.
7. Résultats attendus, risques connus et critères d'acceptation du lot.
8. État Git (`git status --short`) et rappel : **aucun commit ni push effectué**.

## Qualité et sécurité
- Respecte Symfony, Doctrine, PSR-12, typage strict lorsque pertinent et conventions du dépôt.
- Préfère les changements ciblés ; ne refactorise pas hors périmètre sans accord.
- Ajoute des tests pour les règles métier et les régressions.
- Ne crée pas de dépendance supplémentaire sans expliquer son utilité.
- Les autorisations multi-utilisateurs sont contrôlées côté serveur, jamais uniquement dans Twig ou JavaScript.
- Protège la collecte et le cache d'images contre SSRF, XSS, redirections et contenus malveillants.
- Ne journalise pas les secrets ; ne versionne jamais `.env.local`, clés ou identifiants.
- Utilise **MariaDB 10.11**, pas PostgreSQL ; toute migration et toute requête SQL doivent être compatibles MariaDB 10.11.
- Pour la recherche, utiliser les mécanismes compatibles MariaDB (notamment FULLTEXT) ; ne pas introduire de fonctionnalités PostgreSQL.

## Exemple de trame de validation Linux à adapter au lot
Les commandes suivantes sont **illustratives** et ne doivent être fournies que si les fichiers/outils correspondants existent :

```bash
# Depuis la racine du projet, sur Linux, avec les dépendances installées
php -v
composer --version
php bin/console lint:container
php bin/console lint:twig templates/
php bin/phpunit
# Si configurés :
# vendor/bin/phpstan analyse
# npm ci && npm run build
```

Ne jamais présenter cette trame comme un résultat de tests effectivement exécutés.
