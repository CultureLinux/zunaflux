# Feed Explorer Implementation Plan

> **For agentic workers:** Execute natively, one test cycle at a time. Do not commit, push, stage, deploy, or run migrations.

**Goal:** Add an administrator-only Explorer for adding a curated French, English, or German feed to the current administrator’s subscriptions.

**Architecture:** A build-time catalogue preparation step derives safe local YAML/asset data from `zunaflux-package`. Symfony reads the local catalogue through a dedicated service; controller routes enforce `ROLE_ADMIN` and route additions through `SubscribeToFeed`. Twig renders categories and feed cards using translated labels and local favicon URLs.

**Tech Stack:** Symfony 8.1, Twig, Doctrine ORM/MariaDB 10.11, Asset Mapper, YAML catalogue assets.

**Spec:** `docs/superpowers/specs/2026-10-25-feed-explorer-design.md`

## Global Constraints

- Restrict all Explorer routes to `ROLE_ADMIN` server-side.
- Catalogue includes only `fr`, `en`, `de`, and `validation: documented` entries.
- Render only local, reviewed favicon assets; no remote image loading.
- Reuse existing subscription ownership, duplicate and limit checks.
- No migration, new runtime dependency, commit, push, or deployment.

## Review Focus

- Non-admin access returns 403 for both browse and add routes.
- A crafted feed identifier not in the curated catalogue cannot create a subscription.
- An administrator cannot select another user’s category.
- Missing local logo falls back without breaking the feed card.
- Duplicate catalogue URLs remain governed by existing subscription behavior.

---

### Task 1: Prepare the curated local catalogue and favicons

**Files:**
- Create: `resources/feed-explorer/feeds.yaml`
- Create: `public/images/feed-logos/`
- Create: `scripts/prepare-feed-explorer-catalog.py`
- Test: `tests/Unit/Application/FeedExplorer/CatalogTest.php`

- [ ] Write a test proving only documented FR/EN/DE entries are accepted and logo paths are local.
- [ ] Run the test and confirm it fails before the catalogue reader exists.
- [ ] Implement deterministic filtering from `zunaflux-package/feeds.yaml`; download and validate only selected favicons after user approval.
- [ ] Run the focused test and confirm it passes.

### Task 2: Add catalogue reader and protected Explorer routes

**Files:**
- Create: `src/Application/FeedExplorer/FeedCatalog.php`
- Create: `src/Controller/FeedExplorerController.php`
- Modify: `config/services.yaml`
- Test: `tests/Functional/Controller/FeedExplorerControllerTest.php`

- [ ] Write functional tests for administrator browse/add success, non-admin 403, unknown feed rejection, and foreign-category rejection.
- [ ] Run focused tests and confirm they fail before routes exist.
- [ ] Implement read-only catalogue access and the `ROLE_ADMIN` routes, reusing `SubscribeToFeed`.
- [ ] Run focused tests and confirm they pass.

### Task 3: Render Explorer and navigation

**Files:**
- Create: `templates/feed_explorer/index.html.twig`
- Modify: `templates/_navigation_links.html.twig`
- Modify: `translations/messages.en.json`
- Modify: `translations/messages.de.json`
- Test: `tests/Functional/Controller/FeedExplorerControllerTest.php`

- [ ] Write assertions for localized Explorer content, local logos, category selection, and add forms.
- [ ] Implement the category-first layout and translated cards with safe local image fallback.
- [ ] Run Twig lint and focused tests.

### Task 4: End-to-end validation

**Files:**
- Verify: Tasks 1–3 files

- [ ] Run syntax, container, Twig, translation, focused PHPUnit, full PHPUnit, and JavaScript checks where applicable.
- [ ] Confirm an admin can add to an owned category and a non-admin cannot access Explorer.
- [ ] Report observed results; preserve the working tree without commits.
