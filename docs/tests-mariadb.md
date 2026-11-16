# Tests temporels et migrations MariaDB 10.11

Sources du lot 4 ; aucun résultat PHPUnit ou MariaDB n’est revendiqué sans exécution Linux et retour du développeur.

## Horloges et périmètre

`App\Application\Time\Clock` est un contrat interne. `SystemClock` garde le fuseau PHP par défaut et l’heure réelle, comme les anciens appels à `new DateTimeImmutable()`. L’alias Symfony fournit cette horloge aux services ; les arguments de construction optionnels préservent les instanciations existantes. Aucun paquet Clock supplémentaire n’est nécessaire.

`tests/Support/FrozenClock` est réservé aux tests et permet de fixer puis déplacer l’instant sans attente réelle ni modification de l’horloge du système. Les producteurs d’événements de notification convertissent cet instant en UTC pour la base. Le compteur journalier conserve le jour du fuseau de son horloge, soit le fuseau PHP existant en production. La cadence reste calculée par `UserPreference` à partir d’instants explicites.

`SendNotificationDigests` transmet son instant de référence à la purge : la borne des événements consommés reste strictement antérieure à cet instant UTC moins 30 jours. Les événements au seuil exact, non consommés par un appareil ou appartenant à un autre compte restent conservés. La rétention d’articles conserve sa soustraction en mois calendaires avec `DateTimeImmutable::modify`, ses lots bornés et la protection des favoris/enregistrements des abonnements actifs. Aucune règle de fin de mois n’est redéfinie.

Les UUID v7 et certains champs de création d’entités restent réels ; ils ne déterminent pas les assertions temporelles de ces tests. Les tests d’âge des sources et d’expiration de session hors notifications/rétention ne sont pas modifiés par ce lot.

## Couverture à exécuter

- `UserPreferenceNotificationScheduleTest` : bornes des heures silencieuses, passage de date local/UTC, fuseaux Tokyo/Honolulu/Paris, intervalles mesurés en temps écoulé lors des deux changements d’heure, créneau quotidien non répété, les deux occurrences de l’heure d’automne.
- `NotificationCounterTest` : comptage de part et d’autre de minuit local alors que le jour UTC n’a pas encore changé ; transaction de test annulée après le scénario.
- `NotificationTraceTest`, `NotificationDigestTest`, `NotificationOutboxTest` : temps d’événement fixe, sans `+2 hours` relatif ni correction a posteriori du temps des événements de récapitulatif par SQL.
- `DigestQueueTest` : somme et curseur propres à chaque compte, lecture après curseur, résultat vide, seuil de purge à la seconde près, événements non consommés et comptes distincts préservés, exclusion d’une deuxième connexion sur le même compte sans bloquer un autre compte.
- `ArticleRetentionTest` : articles sans date de publication, borne exacte, progression de l’horloge, taille maximale des lots et favoris/enregistrements de plusieurs comptes. Ce test exerce une purge globale dans une transaction externe annulée en fin de scénario : base dédiée obligatoire, jamais une base de production.
- `MigrationDatabaseTest` : refus des environnements et URL non dédiés avant connexion, refus des paramètres de connexion détournés et acceptation d’une URL de test explicite.

Le correctif de l’ancien alias SQL `cursor` n’est pas retouché. Les assertions `max_id` → curseur sont exécutées par les vrais tests MariaDB, notamment `DigestQueueTest` et `NotificationDigestTest`. Seul leur succès sur Linux peut confirmer cette validation.

## Bases des tests applicatifs

PHPUnit continue d’utiliser la configuration de test habituelle et `DATABASE_URL`, vers une base MariaDB 10.11 dédiée ayant reçu les migrations. Les tests fonctionnels créent/modifient leurs données ; les nouveaux tests de rétention et de curseur annulent leur transaction externe. Ce mécanisme n’autorise jamais l’utilisation d’une base réelle ou partagée avec des utilisateurs.

La suite PHP reste définie dans `phpunit.dist.xml`. Les tests temporels sont déterministes par leurs horloges et données, sans figer globalement PHP, les services système ou MariaDB. Les commandes de validation complètes sont dans le [compte-rendu du lot](lot-4-tests-validation.md).

## Deux parcours de migration isolés

`tests/Migration/check-migrations.php` est un exécutable de test distinct de PHPUnit. Il appelle la vraie commande Symfony de migration avec le même interpréteur que celui ayant lancé le script (`php85`), sans shell intermédiaire et avec une URL explicitement transmise au processus enfant.

Il exige avant toute écriture :

1. `APP_ENV=test` et `MIGRATION_TEST_DATABASE_URL` renseignée, sans repli sur `DATABASE_URL`.
2. Un driver MySQL/PDO et un nom conforme à `zunaflux_migrations_test_<suffixe>` (minuscules, chiffres et underscores). Les paramètres URL autorisés sont uniquement `charset` et `serverVersion` ; aucune surcharge de nom de base, driver ou hôte dans la query string.
3. Un serveur se déclarant MariaDB 10.11 et une connexion dont `DATABASE()` correspond au nom validé.
4. Une base entièrement vide, y compris tables de métadonnées et vues. Le script refuse une base déjà utilisée et ne tente jamais de la vider.

Le parseur DSN provient du [DBAL déjà déclaré dans le lock](https://www.doctrine-project.org/projects/doctrine-dbal/en/4.3/reference/configuration.html#using-the-dsn-parser). Aucun compte administrateur MariaDB n’est nécessaire au script si les bases et droits de leur compte de test ont été préparés par le développeur.

| Mode | Parcours |
| --- | --- |
| `empty` | Toutes les migrations sur une base vide, puis nouvelle application sans changement de données. |
| `upgrade` | Migrations jusqu’à `DoctrineMigrations\Version20261114120000`, insertion de données de test, photographie des six tables concernées, migration jusqu’à la dernière version et conservation exacte de ces données ; nouvelle application sans changement. |

Le schéma précédent est la version du 14 novembre, avant la migration d’outbox du lot 3. Le test vérifie également la liste complète des migrations appliquées, les tables durables, les colonnes de curseur et la contrainte unique de déduplication. Il ne restaure pas une sauvegarde de production : les données de référence sont synthétiques (compte, flux, abonnement, article, récapitulatif et marqueur Messenger).

L’enveloppe Messenger sérialisée sert uniquement à vérifier la conservation des lignes ; elle ne doit pas être consommée pendant ce parcours. Aucun worker ni planificateur ne doit cibler les bases de migration. Après un essai, les tables et données sont laissées sur place pour diagnostic ; la suppression de ces bases relève d’une décision humaine distincte.

## Provisionnement humain — modifie l’environnement

Après approbation, créer deux bases vides dans un environnement de test avec votre client MariaDB et votre procédure d’administration :

```sql
CREATE DATABASE zunaflux_migrations_test_empty
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE zunaflux_migrations_test_upgrade
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Ces noms sont réservés à cet exercice et ne doivent contenir aucune donnée à préserver. Donner au compte de test les droits nécessaires sur ces deux seules bases selon votre procédure locale. Ne pas réutiliser le compte de production et ne pas exporter son mot de passe dans la documentation ou les journaux.

Renseigner localement `MIGRATION_EMPTY_DATABASE_URL` et `MIGRATION_UPGRADE_DATABASE_URL` avec des DSN `mysql://.../zunaflux_migrations_test_empty` et `mysql://.../zunaflux_migrations_test_upgrade`. Ne pas versionner ces valeurs. Les variables ne sont pas des paramètres de l’application : ce sont des entrées manuelles pour les commandes suivantes.

Après approbation humaine, depuis une copie avec ses dépendances de développement et sa configuration de test disponibles :

```bash
set -eu
: "${MIGRATION_EMPTY_DATABASE_URL:?Configurer la base vide dédiée}"
: "${MIGRATION_UPGRADE_DATABASE_URL:?Configurer la seconde base vide dédiée}"
APP_ENV=test MIGRATION_TEST_DATABASE_URL="$MIGRATION_EMPTY_DATABASE_URL" \
  php85 tests/Migration/check-migrations.php empty
APP_ENV=test MIGRATION_TEST_DATABASE_URL="$MIGRATION_UPGRADE_DATABASE_URL" \
  php85 tests/Migration/check-migrations.php upgrade
```

Attendu : `Migration check empty: passed ...` et `Migration check upgrade: passed ...`, code zéro. Les migrations et données de référence modifient les bases dédiées. Aucun `CREATE DATABASE`, `DROP`, vidage ou retour arrière n’est lancé par le script lui-même. Pour un nouvel exercice complet, préparer deux nouvelles bases vides aux noms autorisés ; ne pas contourner le refus d’une base remplie.

## Intégration CI

La [matrice GitHub Actions](https://docs.github.com/en/actions/how-tos/write-workflows/choose-what-workflows-do/run-job-variations) `migrations` crée deux jobs, chacun avec son propre service MariaDB 10.11 et sa base éphémère `zunaflux_migrations_test_ci`. Le même nom ne désigne pas le même serveur entre jobs. `fail-fast: false` permet de voir les deux résultats.

Après chaque succès, la CI relance le script sur la base maintenant peuplée et exige le refus précis « Migration test database must be empty ». Une erreur PHP ou réseau quelconque ne suffit pas à valider ce contrôle négatif.

La porte `quality` exige le succès du groupe de migrations en plus des validations PHP, JavaScript, assets et Docker. Les tests shell de cette porte passent de 19 à 22 scénarios, en ajoutant échec, annulation et omission des migrations. Une publication ne doit pas contourner un résultat de migration en échec.

## Diagnostic

Un refus de cible n’est pas une invitation à renommer une base réelle : vérifier l’URL de test, `APP_ENV`, le suffixe autorisé et la vacuité du schéma. En cas de panne SQL, conserver la base d’exercice pour inspection, relever la version MariaDB et la première migration en erreur. Ne pas rejouer automatiquement l’exercice sur une base partiellement créée.

Les sorties des commandes Symfony sont celles des outils existants ; les expurger avant partage. Le script ne présente pas lui-même l’URL de connexion et remplace les exceptions de driver par un diagnostic générique. Les migrations futures modifiant volontairement les colonnes photographiées demanderont de revoir les assertions de conservation ; ne pas neutraliser un échec sans examiner sa cause.
