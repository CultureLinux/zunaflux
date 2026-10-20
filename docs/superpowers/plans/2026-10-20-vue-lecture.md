# Vue de lecture inspirée de la maquette — plan

**Objectif :** proposer une lecture à trois panneaux sur ordinateur et une navigation utilisable au tactile, avec les données et actions existantes de Zunaflux.

**Architecture :** conserver les contrôles d’accès et les API de lecture ; construire une navigation de lecture personnelle, une liste et un aperçu utilisant les mêmes états. La présentation mobile privilégie un panneau à la fois. L’option de barre repliée utilise la préférence utilisateur existante.

**Contraintes :** Symfony, Twig, Tailwind et MariaDB existants ; aucune dépendance nouvelle ; pas de contenu ou de compteurs fictifs ; contenu HTML et images d’articles servis par les mécanismes de protection existants ; conserver les actions groupées, filtres enregistrés et navigation clavier.

## Étapes

1. Définir les données de navigation : abonnements actifs et catégories de l’utilisateur, et compteurs calculés côté serveur ; tests d’isolation entre comptes et de sources sans articles.
2. Installer la structure à trois panneaux et la barre repliable persistée ; conserver l’accès aux préférences, sources, administration et déconnexion. Prévoir absence de JavaScript, petit écran et libellés accessibles.
3. Adapter liste et aperçu : sélection d’article, états lu/favori/à lire plus tard, lecture selon la préférence, recherche, pagination et états vides. Préserver le retour à la liste sur mobile.
4. Raccorder les filtres existants et les actions groupées ; vérifier l’actualisation de la liste et des compteurs après modification, sans réponses périmées lors de navigations rapides.
5. Vérifier les thèmes clair et sombre, la navigation au clavier et le rendu aux différentes largeurs ; exécuter les tests applicables, les lint Twig et la compilation Tailwind.

## Points à préciser

- La préférence désactivée conserve la vue actuelle ; activée, elle affiche la nouvelle vue sur ordinateur. Sur mobile, elle n’a pas d’effet.
- Sur mobile, conserver la vue de lecture actuelle et sa fenêtre d’article.
- N’afficher que les fonctionnalités réellement prises en charge : les dossiers illustrés dans la maquette correspondent aux catégories existantes, sans inventer de nouvelles données.
