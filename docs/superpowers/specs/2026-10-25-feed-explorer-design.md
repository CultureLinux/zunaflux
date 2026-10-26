# Feed Explorer Design

## Intent

Provide an administrator-only discovery page that lets an administrator browse a curated catalogue of RSS and Atom feeds and subscribe to one in a personal category. The first version proves the catalogue and addition flow before the Explorer is opened to ordinary users.

## Goals

- Show only high-quality French, English, and German catalogue entries.
- Organize discovery by catalogue category such as technology, gaming, and news.
- Show each feed’s language, name, URL, and downloaded favicon.
- Let the current administrator add a selected feed to one of their own categories or leave it uncategorized.
- Keep authorization server-side and reuse the existing subscription flow’s ownership and limits.
- Download only the filtered catalogue’s favicons as local PNG assets.

## Non-goals

- No public access and no regular-user access in this version.
- No in-app catalogue curation UI, ratings, search, or pagination.
- No automatic HTTP/XML validation of feeds in this version.
- No cataloguing of `to_validate` entries.

## Catalogue rules

`zunaflux-package/feeds.yaml` is the working source. Create a versioned application catalogue derived from it that retains only entries where `language` is `fr`, `en`, or `de` and `validation` is `documented`. The derived catalogue and downloaded local PNG favicons are the runtime source; no network request is made when rendering Explorer.

Category labels, language labels, empty states, and actions use the active application locale. Favicons are retrieved from the catalogue’s declared logo URL, verified as images, stored under a public application asset directory, and referenced by a safe local relative path.

## Access and data flow

`ROLE_ADMIN` is required for every Explorer route. The listing reads the local derived catalogue. The add action validates the catalogue entry identifier server-side, uses its URL, and reuses `SubscribeToFeed`; the administrator may choose only an owned category. Existing duplicate and subscription-limit behavior remains authoritative.

## UX

Explorer presents category cards first. Choosing a category shows feed cards with language and favicon. Each card contains an explicit add action and a category selector for the current administrator. A successful add confirms the result and returns to Explorer or updates the card state; failures remain visible through the existing translated feedback pattern.

## Compatibility and safety

No database migration or new PHP dependency is required. MariaDB is unaffected. Logos are static local files; arbitrary remote image URLs are never rendered. The download process is a controlled build-time operation, not application functionality.
