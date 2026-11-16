# Lot 7B — Extraction des vues et sources du lecteur

Périmètre : déplacer les conversions des paramètres de vue et la synchronisation des sources dans `assets/reading/sources.js`, import ES natif depuis le contrôleur déjà chargé en module. Aucun changement de filtre, de navigation, de sélection, de requête API ou de présentation.

Préparation : Node 24.22.3 déjà présent, dépendances npm verrouillées et Chromium headless téléchargés après autorisation. À la demande explicite du développeur, réutiliser la base `zunaflux_performance_test_local` pour les fixtures navigateur et élargir le garde uniquement aux deux préfixes de bases de test. Aucun banc de performance exécuté simultanément.

1. Établir la référence JavaScript et navigateur. Corriger l’amorçage du routeur de test, qui chargeait dev au lieu de browser : variables explicites avant Runtime, dotenv désactivé dans ce routeur réservé aux tests. Référence obtenue : 14 parcours réussis.
2. Adapter les tests de vues/sources pour importer le futur module réel ; constater leur échec avant création du module.
3. Extraire `viewFromParams`, `setViewParams`, `isReadingSourceVisible`, `updateSourceMarkReadControls`, `updateReadingSources(root, view, categoryFilter)`. Garder un appel mince depuis le contrôleur ; conserver les règles existantes, même si elles semblent perfectibles.
4. Adapter les anciens tests VM du contrôleur pour injecter les vrais exports du module, sans copier leur code ; tests des sources/vues exécutés directement contre le module.
5. Rejouer les tests JS, compiler les assets, rejouer les 14 parcours. Vérifier par une mutation temporaire contrôlée que le parcours d’ouverture échoue sans son listener, puis restaurer et recompiler ; aucune commande Git de restauration.
6. Rejouer les tests PHP et les vérifications configurées, documenter résultat et limites. Retirer uniquement la régénération incidente de `config/reference.php` issue des commandes console. Aucun commit, push, migration ou déploiement.

La CI reste celle existante : tous les fichiers `tests/JavaScript/*.test.cjs` sont exécutés, et le job browser obligatoire conserve sa base `zunaflux_browser_test_ci`. La dérogation de base locale ne modifie ni le DSN CI ni les environnements de production. Les décisions fonctionnelles du cahier des charges restent inchangées.

Intégration retenue : nouvel `importmap.php` limité à l’entrée locale `reader` et `importmap('reader')` dans Twig. AssetMapper référence ses modules par noms sans empreinte dans les imports ; le rendu d’import map assure leur résolution vers les fichiers compilés. Les autres scripts de la page restent chargés comme auparavant. Contrôles terminés : 57 tests JS, 14 parcours avant/après, preuve négative puis 14 parcours restaurés, 187 tests PHP sans échec (5 notices connues). Détails et limites dans le compte-rendu de 7B.
