# Format d’export Zunaflux v1

L’export est une archive ZIP téléchargée directement dans la réponse HTTP : aucun fichier d’export n’est conservé par l’application.

`manifest.json` fixe `formatVersion` à `1`. L’archive contient également le profil non sensible, les préférences, catégories, abonnements, filtres, états et statistiques. Les mots de passe, sessions et secrets système n’y figurent jamais.

Les fichiers `states.json` sont fournis à des fins de portabilité et d’audit. Leur réimportation complète ne fait pas partie de la V1. `subscriptions.opml` peut être téléchargé séparément.
