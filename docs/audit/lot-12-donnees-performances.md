# Lot 12 : données, collecte et performances

**Priorité :** Moyenne · **Effort :** L · **Migration :** oui (index, colonne)

**Objectif :** terminer la fiabilité de la collecte, la rétention et les performances de lecture.

| ID d'origine | Point | Décision à arbitrer | Recommandation |
|---|---|---|---|
| 1.7 | 301/308 : mettre à jour `canonicalUrl` ; 410 : mettre le flux en pause avec incident | Redirection permanente vers une URL déjà suivie par un autre flux | Fusionner seulement s'il n'y a pas de conflit d'unicité, sinon incident |
| 1.8 | `recordSuccess()` ne doit plus remettre `nextCollectionAt` à `null` ; index `idx_feed_next_collection` | Intervalle de recollecte | `now + ZUNAFLUX_COLLECTION_INTERVAL` (15 min) |
| 1.9 | Libellé `(sans titre)` hors du domaine, charset HTTP, titre RDF | Aucune | Clé de traduction à l'affichage |
| 1.10 | `IncidentMessage` injecté et non instancié par défaut | Aucune | Injection |
| 2.5 | Médias de `var/media` liés aux articles purgés | Vérifier d'abord s'il en existe | Audit puis nettoyage dans la purge |
| 2.6 | Purge à 90 jours des tables de diagnostic | Sort de `first_read_event` (anti-recomptage des premières lectures) | Conserver `first_read_event`, purger `feed_sync_run*`, `feed_incident_occurrence` et l'historique `notification_pending` |
| 4.2 | Colonne `has_image` calculée à la collecte | Remplissage de l'historique | Colonne + commande de rattrapage par lots |
| 4.3 | `bulk` (≤ 100 ids) en SQL ensembliste | Aucune | Réutiliser `MarkSubscriptionRead` |
| 4.5 | Recherche `MATCH … AGAINST` (FULLTEXT) | Change la façon dont les recherches répondent (mots de 1-2 lettres ignorés, pas de sous-chaîne) | FULLTEXT sur titre et résumé, `LIKE` échappé sur le titre du flux ; réglages MariaDB dans le README |
| 4.6, 6.6 | `doctrine:schema:validate` vert, puis en CI | Chantier de dérive (tables sans entité, `DC2Type`) | Entités ou `schema_ignore_classes` + migration de normalisation |
| 4.7 | N+1 (barre latérale, sources) | Aucune | Jointures et `IN` |
| 4.8 | Suppression par lots au désabonnement | Atomicité de la suppression | Lots de 1 000 dans une transaction par lot |
| 4.9 | `EXPLAIN` puis suppression de `idx_article_state_user_read` | Aucune | Mesure avant et après |

## Validation
Tests PHPUnit, `tests/Performance`, `check-migrations.php`, `EXPLAIN` documentés.

## Critères d'acceptation
- `doctrine:schema:validate` sans erreur et exécuté en CI.
- Un flux n'est plus recollecté à chaque passage du cron.
- La recherche utilise l'index FULLTEXT.
