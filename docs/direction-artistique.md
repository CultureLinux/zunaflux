# Direction artistique — Zunaflux

## Territoire : « minimalisme éditorial »

Zunaflux est un poste de lecture personnel, pas un tableau de bord publicitaire. Son identité emprunte à la presse indépendante, aux bibliothèques numériques et aux outils professionnels sobres. L'interface privilégie la lecture, la hiérarchie des informations et le contrôle utilisateur. Elle est neutre, intemporelle, précise et chaleureuse sans effets décoratifs.

La signature visuelle repose sur le contraste entre un **blanc doux**, un **graphite profond** et un **bleu acier mesuré**. Les titres éditoriaux en sérif apportent la personnalité ; les commandes en sans-sérif et les métadonnées en mono apportent la clarté. Les thèmes clair et sombre sont de première classe.

## Principes visuels

1. **Le contenu précède l'interface.** Les articles sont des entrées éditoriales séparées par des filets, sans accumulation de cartes ou d'ombres.
2. **L'accent est rare.** Le bleu acier identifie la sélection, les liens importants, les actions principales et le focus. Succès, avertissement et danger possèdent des couleurs distinctes réservées à leur sens.
3. **La structure est une grille.** Alignements constants, séparateurs fins, surfaces calmes et espacements systématiques.
4. **Chaque police a un rôle.** Sérif pour les titres et la lecture ; sans-sérif pour l'interface ; mono pour les dates, chiffres et états techniques.
5. **La densité est réglable.** Modes compact, normal et lecture, qui modifient la quantité d'information visible, pas seulement les marges.
6. **Le lecteur garde la main.** Thème clair/sombre/automatique, taille et police de lecture, largeur de prose et densité mémorisés.
7. **La couleur ne suffit jamais.** Les états lus/non lus, favoris, erreurs de collecte et actions dangereuses restent identifiables par texte, icône ou structure.

## Tokens de référence

| Rôle / token | Thème clair | Thème sombre | Usage |
|---|---|---|---|
| `background` | `#F8F8F6` | `#0F1419` | Fond principal |
| `surface` | `#FFFFFF` | `#1A2028` | Panneaux, champs, modales |
| `surface-muted` | `#ECEBE6` | `#232B36` | Surfaces secondaires |
| `foreground` | `#1F2937` | `#F8FAFC` | Texte prioritaire |
| `muted` | `#64748B` | `#94A3B8` | Texte secondaire |
| `line` | `#D7DFE8` | `#334155` | Filets et bordures |
| `accent` | `#5475A5` | `#6D9FD0` | Sélection, liens, focus, CTA |
| `accent-soft` | `#E8EEF7` | `#22344A` | Sélection discrète |
| `accent-foreground` | `#FFFFFF` | `#0F1419` | Texte sur CTA plein |
| `success` | `#16764C` | `#34D399` | Collecte saine, confirmation |
| `warning` | `#A65B12` | `#F59E0B` | Collecte dégradée, avertissement |
| `danger` | `#B42332` | `#F87171` | Erreur critique, suppression |

Ces valeurs doivent être définies comme **tokens CSS sémantiques**, exposés à Tailwind v4 via `@theme inline`. Ne pas disperser les hexadécimaux ni les classes de couleur arbitraires dans Twig. Prévoir des tokens distincts pour les états (`selected`, `unread`, `disabled`, `critical`) si nécessaire ; tester leurs contrastes dans les deux thèmes.

### Implémentation Tailwind v4

```css
@import "tailwindcss";

:root {
  color-scheme: light;
  --background: #F8F8F6;
  --surface: #FFFFFF;
  --surface-muted: #ECEBE6;
  --foreground: #1F2937;
  --muted: #64748B;
  --line: #D7DFE8;
  --accent: #5475A5;
  --accent-soft: #E8EEF7;
  --accent-foreground: #FFFFFF;
  --success: #16764C;
  --warning: #A65B12;
  --danger: #B42332;
}

:root[data-theme="dark"] {
  color-scheme: dark;
  --background: #0F1419;
  --surface: #1A2028;
  --surface-muted: #232B36;
  --foreground: #F8FAFC;
  --muted: #94A3B8;
  --line: #334155;
  --accent: #6D9FD0;
  --accent-soft: #22344A;
  --accent-foreground: #0F1419;
  --success: #34D399;
  --warning: #F59E0B;
  --danger: #F87171;
}

@theme inline {
  --color-background: var(--background);
  --color-surface: var(--surface);
  --color-surface-muted: var(--surface-muted);
  --color-foreground: var(--foreground);
  --color-muted: var(--muted);
  --color-line: var(--line);
  --color-accent: var(--accent);
  --color-accent-soft: var(--accent-soft);
  --color-accent-foreground: var(--accent-foreground);
  --color-success: var(--success);
  --color-warning: var(--warning);
  --color-danger: var(--danger);
  --font-editorial: "Iowan Old Style", "Palatino Linotype", Georgia, serif;
  --font-interface: Inter, system-ui, sans-serif;
  --font-data: "IBM Plex Mono", "Courier New", monospace;
}
```

Le choix `auto` suit `prefers-color-scheme`; un choix explicite `light` ou `dark` le remplace. Appliquer le thème avant le premier rendu pour éviter le flash du mauvais thème. Respecter `color-scheme` pour les contrôles natifs.

## Typographie

- **Titres et prose :** `Iowan Old Style`, puis `Palatino Linotype`, puis `Georgia`. Titres généreux, interlignage resserré ; prose à 17–18 px et interlignage proche de 1,65 par défaut.
- **Navigation et contrôles :** `Inter`, puis `system-ui`. Libellés courts, explicites et lisibles.
- **Données :** `IBM Plex Mono`, puis `Courier New`. Dates, compteurs et états techniques ; capitales espacées réservées aux rubriques courtes.
- **Longueur de ligne :** environ 65–75 caractères en mode lecture, réglable. Éviter les résumés pleine largeur.
- **Personnalisation :** taille du corps, police de lecture et largeur de prose ajustables, sans rupture de mise en page.

## Composition et composants

### Navigation

Sur desktop large, organiser l'interface en trois zones : **sources/dossiers**, **liste d'articles**, **lecture**. Barre supérieure compacte avec logo-mot sérif, recherche et préférences. La sélection utilise un fond `accent-soft` et un repère net ; elle ne repose pas sur un simple changement de teinte. Sur largeur intermédiaire, privilégier deux zones ou une lecture pleine largeur.

### Liste d'articles

Chaque entrée présente source et date discrètes, titre sérif, résumé de deux ou trois lignes maximum et actions secondaires accessibles. Filet entre les entrées, sans cartes systématiques. Les miniatures sont facultatives, cadrées et ne doivent pas concurrencer les titres. Distinguer visuellement non lu, lu, sélectionné, favori et sauvegardé, sans se reposer uniquement sur la couleur.

### Panneau de lecture

Prose à largeur contrôlée, source au-dessus du titre, marges généreuses et barre d'actions compacte. Prévoir « ouvrir la source », « marquer lu/non lu », « favori » et « à lire plus tard ». Le contenu RSS HTML doit être assaini et stylé de façon cohérente : images fluides, citations, tableaux, blocs de code, liens et contenus incomplets.

### Filtres, recherche et formulaires

Filtres segmentés sobres, état actif explicite, champs sur `surface`, bordure `line` et focus `accent`. Recherche accessible au clavier. Les boutons primaires utilisent `accent` + `accent-foreground`; les boutons secondaires restent neutres. Les actions destructives sont visuellement isolées.

### Administration et gestion des sources

Tableaux denses, chiffres alignés à droite, libellés compréhensibles, états de collecte explicites : sain, en cours, dégradé, inaccessible. Une suppression irréversible exige une confirmation explicite, éventuellement textuelle selon la gravité. Ne pas appliquer les grands titres éditoriaux aux tableaux techniques.

### États vides et erreurs

Messages courts, spécifiques et utiles : aucun abonnement, aucun résultat, tout lu, flux indisponible, contenu tronqué, synchronisation en cours. Toujours proposer une action pertinente quand elle existe. Pas d'illustrations génériques.

## Densité et préférences

| Mode | Comportement |
|---|---|
| Compact | Métadonnées et titres prioritaires, extraits masqués ou réduits, lignes resserrées |
| Normal | Titres, métadonnées et courts extraits ; mode par défaut |
| Lecture | Titres plus amples, extraits développés, davantage d'espace |

Conserver les préférences entre sessions. Ne pas confondre densité de la liste et taille de police du lecteur : ces paramètres sont indépendants.

## Mouvement et interaction

Transitions de 150–250 ms maximum, limitées à couleur, opacité ou déplacement léger (4–8 px). Aucun effet de défilement imposé. Respecter `prefers-reduced-motion`. Cibles tactiles d'au moins 44 × 44 px pour les actions courantes. Focus clavier visible et contrasté dans les deux thèmes. Les boutons à icône seule portent un nom accessible. Tester navigation clavier, zoom 200 %, contrastes et absence de dépendance au survol.

## Responsive

- **375 px :** une vue à la fois (sources → articles → lecture), navigation retour explicite, actions essentielles accessibles sans survol, marges `px-4`.
- **768 px :** filtres ou sources dans une colonne compacte si l'espace le permet, marges `sm:px-6`.
- **1024 px :** deux zones confortables ; la lecture peut passer en pleine largeur.
- **Écrans larges :** trois zones uniquement si la colonne de lecture conserve une largeur de prose confortable, marges `lg:px-8`.

Conserver la position de lecture et le contexte lors des changements de vue.

## À éviter

- Dégradés décoratifs, glassmorphism, ombres molles et cartes arrondies partout.
- Bleu saturé utilisé comme décoration, accents multiples sans signification.
- Icônes emoji, animations imposées, badges inutiles.
- Gris trop pâle sur fond clair, gris sur gris sombre, corps de texte trop petit.
- Actions dangereuses visuellement équivalentes aux actions de lecture.
- Mise en page à trois colonnes comprimée sur un écran insuffisamment large.
- Interface qui ressemble à un tableau de bord de monitoring plutôt qu'à un espace de lecture.

## Critères d'acceptation de la refonte

1. Thèmes clair, sombre et automatique cohérents, sans flash de thème au chargement.
2. Couleurs utilisées exclusivement via tokens sémantiques dans les templates.
3. Liste, lecture, recherche, dossiers, gestion des sources et états vides cohérents.
4. Modes compact, normal et lecture fonctionnels ; préférences persistantes.
5. Responsive vérifié à 375, 768, 1024 px et sur grand écran.
6. Navigation clavier, focus visible, noms accessibles, réduction du mouvement et contrastes vérifiés.
7. Les titres et le contenu restent prioritaires par rapport aux composants d'interface.

**Intention finale :** Zunaflux doit offrir l'efficacité d'un outil de veille et le confort d'une revue de presse personnelle. La sobriété n'est pas une absence d'identité : elle laisse les articles prendre toute leur place.
