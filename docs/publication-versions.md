# Politique de publication des versions

Politique du lot 2 approuvée le 15 novembre 2026. Elle s’applique aux tags contenant les workflows mis à jour. Le développeur réalise les opérations Git et la publication ; l’agent ne crée ni tag, ni release, ni push.

## Format des versions

Un tag publiable suit `vMAJEUR.MINEUR.CORRECTIF[-préversion]` :

- `v1.2.3`, `v0.2.0`, `v1.3.0-rc.1` et `v1.3.0-beta` sont acceptés.
- Les trois nombres sont des entiers décimaux sans zéro initial, sauf `0`.
- Les identifiants de préversion sont séparés par des points et contiennent uniquement lettres ASCII, chiffres et tirets. Un identifiant entièrement numérique ne peut pas avoir de zéro initial : `rc.01` est refusé, `alpha-01` est accepté.
- Le tag Docker enlève uniquement le `v` initial et ne dépasse pas 128 caractères. Aucune normalisation silencieuse ne transforme une version invalide en version publiable.
- Les suffixes `+build` ne sont pas acceptés pour les nouvelles publications : `+` n’est pas un caractère Docker valide. Il s’agit d’une restriction du format de publication, pas d’une affirmation selon laquelle ces suffixes seraient invalides en SemVer.

Ces règles suivent la [spécification SemVer 2.0.0](https://semver.org/spec/v2.0.0.html), restreinte aux caractères des [tags Docker acceptés par metadata-action](https://github.com/docker/metadata-action/tree/v5#image-name-and-tag-sanitization).

## Choix des tags Docker

L’image reste `clinuxfr/zunaflux`. Chaque publication conserve son tag exact, par exemple `1.2.3` ou `1.3.0-rc.1`, et le tag SHA court existant `sha-<commit court>`. Les préversions ne reçoivent aucun alias stable.

Pour une version stable :

| Alias | Condition de mise à jour |
| --- | --- |
| `MAJEUR.MINEUR` | Aucune version stable supérieure de la même série majeure/mineure dans l’inventaire distant. |
| `MAJEUR` | Aucune version stable supérieure du même majeur dans l’inventaire distant. |
| `latest` | Aucune version stable supérieure dans tout l’inventaire distant. |

La comparaison est numérique, composante par composante : `1.10.0` est supérieur à `1.9.99`. Les comparaisons restent exactes pour de grands nombres. La règle vaut également pour le majeur zéro : `v0.2.0` peut produire `0.2`, `0` et `latest`.

Exemples avec `v1.2.3`, `v1.3.0` et `v2.0.0` déjà déclarés :

| Nouvelle publication | Tags de version et alias autorisés, en plus du SHA |
| --- | --- |
| `v2.0.1` | `2.0.1`, `2.0`, `2`, `latest` |
| `v1.3.1` | `1.3.1`, `1.3`, `1` |
| `v1.2.4` | `1.2.4`, `1.2` |
| Nouvelle tentative de `v1.2.3` | `1.2.3` seulement |
| `v3.0.0-rc.1` | `3.0.0-rc.1` seulement |

Les anciens tags SemVer contenant `+build` participent à la comparaison, sans leur métadonnée : `v2.0.0+build.1` réserve la priorité de `2.0.0`. Ils ne deviennent pas publiables par ce nouveau workflow. Les tags mal formés sont ignorés dans l’inventaire historique.

`docker/metadata-action` reçoit les seules valeurs explicitement sélectionnées ; `flavor: latest=false` désactive son [alias latest automatique](https://github.com/docker/metadata-action/tree/v5#latest-tag). Le label OCI de version garde la référence Git complète.

## Inventaire et concurrence

La référence de priorité est l’ensemble des tags Git distants conservés, et non la date des commits, la branche, l’ordre d’arrivée des jobs ou l’inventaire Docker Hub. Une version stable déclarée mais pas encore publiée réserve donc déjà ses alias : si elle échoue en CI, relancer sa publication après correction ou publier une version supérieure ; ne pas supprimer son tag pour faire redescendre `latest`.

Le job `build-and-push` est sérialisé pour toute l’image, depuis le calcul des alias jusqu’au push. Il utilise `queue: max` et `cancel-in-progress: false` : une publication active n’est pas interrompue par une nouvelle version ; jusqu’à 100 publications peuvent attendre. Au-delà, GitHub annule les demandes supplémentaires. Cette limite et la sémantique de file sont décrites dans la [documentation GitHub Actions](https://docs.github.com/en/actions/how-tos/write-workflows/choose-when-workflows-run/control-workflow-concurrency).

Chaque job admis dans la section sérialisée lit à nouveau les tags distants. Le checkout cible le commit de l’événement ; le relevé distant doit confirmer que le tag pointe toujours sur ce commit, y compris pour un tag annoté. Un tag absent, déplacé ou une erreur réseau fait échouer le job avant connexion au registre.

La garantie de progression suppose que les tags de version sont conservés et ne sont jamais déplacés, et que toutes les publications de cette image utilisent ce workflow et le même groupe de concurrence. Protéger les tags `v*` contre suppression et déplacement dans les règles GitHub relève du développeur. Les écritures manuelles dans Docker Hub et les anciens workflows ne sont pas couvertes. Avant activation, vérifier la cohérence des alias existants avec les tags Git conservés ; une anomalie historique demande une décision humaine.

Les builds et écritures multiples d’alias ne constituent pas une transaction de registre. Une panne pendant le push peut laisser une publication partielle ; inspecter les tags et relancer le même workflow, sans changer de tag Git. Un tag créé pendant un build sera pris en compte par le job suivant ; sa création ne modifie pas rétroactivement le relevé du job actif.

## Nouvelle tentative d’une version et maintenance

Une nouvelle tentative du même workflow est autorisée si le tag Git pointe toujours sur le même commit. Elle recalcule les alias depuis l’état distant actuel. Elle peut réécrire l’image de version exacte et son tag SHA, ainsi que les alias auxquels elle a encore droit. Les tags Docker ne sont donc pas déclarés immuables : reconstruire la même source peut produire un digest différent. Pour figer une installation, conserver le digest effectivement validé.

Une branche de maintenance peut publier une nouvelle version inférieure à la version courante globale ; seules ses séries éligibles sont mises à jour. Elle doit embarquer les nouveaux scripts et workflows : un tag sur un ancien commit exécute les workflows de cet ancien commit. Un report des changements sur la branche de maintenance doit être réalisé et revu par le développeur avant la publication.

## Procédure du développeur

1. Vérifier les résultats Linux et GitHub des lots 1 et 2, les tests de politique, les règles de protection et les alias déjà présents sur Docker Hub.
2. Choisir la version et le commit revu ; vérifier que celui-ci contient les scripts et workflows actuels, les notes de version et les éventuelles migrations approuvées.
3. Prévisualiser sans publication depuis une copie du code, avec un inventaire local explicite incluant le futur tag :

   ```bash
   printf '%s\n' v1.2.3 v1.3.0 v2.0.0 v1.2.4 |
     RELEASE_TAG=v1.2.4 LC_ALL=C awk -f .github/scripts/release-tags.awk
   ```

   Résultat attendu : `1.2.4` puis `1.2`. Cette simulation ne consulte ni ne modifie Git ou Docker Hub.
4. Après approbation humaine de publication, créer et pousser le tag selon la procédure Git habituelle du développeur. Aucun exemple exécutable de création/push n’est inclus dans les commandes de validation.
5. Vérifier la réussite de `validate-version`, de la CI réutilisable et du job de publication. Examiner les tags calculés dans l’étape metadata ; confirmer qu’aucun alias non autorisé n’est émis.
6. Contrôler le digest, les plateformes `linux/amd64` et `linux/arm64`, et les alias effectivement publiés avant toute mise à jour d’installation. En cas d’échec, diagnostiquer puis relancer le workflow concerné ; ne pas recréer ni déplacer le tag.

La publication d’une image ne déclenche aucun déploiement de l’application ni aucune migration de production.
