# Subscription Refresh and Edit Implementation Plan

> **For agentic workers:** Execute the tasks natively, one test cycle at a time. Do not commit, push, or stage changes.

**Goal:** Let an authenticated user refresh one owned RSS source from the subscriptions list while making the rest of the row open its existing edit dialog.

**Architecture:** Add two user-scoped JSON endpoints that create and read a single-feed synchronization run only after checking subscription ownership and CSRF. Reuse the existing collection message and run-entry persistence. The subscriptions list gets a dedicated icon button and localized status dialog; a semantic button covering the source information opens the edit dialog, while drag, refresh, and delete controls retain independent behavior.

**Tech Stack:** Symfony 8.1, Doctrine ORM/MariaDB 10.11, Twig, Asset Mapper JavaScript, Tailwind CSS.

**Spec:** User-approved chat description on 2026-10-25.

## Global Constraints

- AGPL-3.0-only; no new dependency.
- User authorization is enforced server-side; a user can only refresh an owned subscription.
- MariaDB 10.11-compatible SQL and existing `feed_sync_run` persistence only.
- Labels and live status are translated through Twig catalogues.
- Preserve keyboard access, 44px controls, current drag behavior, and destructive controls.
- Do not commit, push, stage, deploy, or run migrations.

## Review Focus

- A user requests a different user’s subscription ID: return 403 and dispatch nothing (Task 1).
- Invalid or missing CSRF token: return 403 (Task 1).
- The status endpoint is guessed from another user: return 403 and reveal no feed data (Task 1).
- Refresh icon click must not open the edit dialog (Task 2).
- Keyboard activation of the source row opens its edit dialog, while the drag handle remains the only draggable area (Task 2).

---

### Task 1: Owned-subscription synchronization endpoints

**Files:**
- Modify: `src/Controller/SubscriptionController.php`
- Test: `tests/Functional/Controller/SubscriptionControllerTest.php`

**Interfaces:**
- Produces `POST /subscriptions/{id}/refresh` and `GET /subscriptions/{id}/refresh/{runId}` JSON endpoints.
- Consumes `CollectFeedMessage`, `Subscription`, `MessageBusInterface`, and `feed_sync_run` / `feed_sync_run_entry`.

- [ ] Write functional tests proving an owner receives `202` with a run identifier and a non-owner or missing CSRF receives `403`.
- [ ] Run the focused PHPUnit test; it must fail because the routes do not exist.
- [ ] Implement ownership and CSRF checks, create exactly one run entry for the owned feed, dispatch `CollectFeedMessage`, and restrict status rows to that subscription’s feed.
- [ ] Run the focused PHPUnit test; it must pass.

### Task 2: Accessible list edit control and localized refresh dialog

**Files:**
- Modify: `templates/subscription/_source_list.html.twig`
- Create: `assets/controllers/subscription-refresh.js`
- Modify: `templates/subscription/index.html.twig`
- Modify: `translations/messages.en.json`
- Modify: `translations/messages.de.json`
- Test: `tests/JavaScript/subscription-refresh.test.cjs`

**Interfaces:**
- Consumes the endpoints from Task 1 and `data-subscription-refresh-*` attributes emitted by Twig.
- Produces a 202-started, polling status dialog and a `button[data-open-delete]` edit trigger embedded in each source row.

- [ ] Write a JavaScript test proving a refresh response starts polling and displays localized statuses; write a template/interaction test proving refresh does not open the edit dialog while the row edit button does.
- [ ] Run the focused Node test; it must fail because the controller and markup are absent.
- [ ] Implement semantic buttons, SVG refresh icon with translated accessible name, live status dialog, and localized data attributes; keep refresh action separate from row editing and dragging.
- [ ] Run the focused Node test; it must pass.

### Task 3: Cross-layer verification

**Files:**
- Verify: files from Tasks 1–2

- [ ] Run PHP syntax, Twig lint, translation lint, focused PHPUnit, and focused Node tests.
- [ ] Verify the list at a narrow viewport and by keyboard: edit opens from the main row, refresh opens the status dialog, and icon controls retain names.
- [ ] Report only evidence actually observed; leave all changes uncommitted.
