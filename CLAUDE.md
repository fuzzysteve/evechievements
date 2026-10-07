# CLAUDE.md

EVEchievements (evechievements.online): EVE Online pilots log in via EVE SSO and opt in to showing
skills, certificates, ship masteries, ship trees, wallet/asset values and titles on a public profile.

## Stack

- PHP 8.4, no framework. Twig 3 templates, PDO (PostgreSQL), Guzzle, league/oauth2-client, Monolog.
- `htdocs/index.php` is the front controller: loads `.env`, starts the session and calls `App\Router`.
  It also defines the `ROOT` constant, which `Router` and `TwigFactory` use.
- `src/Router.php` routes with a `match(true)` on method + path. Order matters: put the more specific
  routes (e.g. `/pilot/{id}/ships`) before the `str_starts_with($path, '/pilot/')` fallback.
  Controllers are built per request. `profileCtrl()` / `authCtrl()` wire their dependencies.
- No tests. The checks are `php -l`, `vendor/bin/phpstan analyse` (config in `phpstan.neon`; its
  bootstrap defines `ROOT`), and rendering the page.

## Data model and privacy

- **Tokens are never stored.** Each dashboard section (`skills`, `wallet`, `assets`) runs its own SSO
  round trip with only that section's scope (`AuthService::getSectionUrl`). `DataFetchService` stores the
  ESI result in `$_SESSION['fetched'][section]` and throws the token away.
- **Only what the pilot ticks is persisted**, as a snapshot taken at save time
  (`ProfileController::saveDisplaySelections`). Every submitted ID is checked against the session data
  (or the SDE), never trusted from POST. The `pilot_display_*` tables hold these snapshots.
- **Never expose a pilot's skill levels unless they ticked that skill.** Masteries may be shown only
  when published. The ship page (`/ship/{typeID}`, `ShipController`) is generic: what each level
  requires. The one exception is the logged-in viewer's OWN state, computed from their session's skills,
  shown only to them and never stored: what they're missing per level on `/ship/{typeID}`, and their
  flyable/mastery tree on `/ships` (`?view=all` shows the plain tree).
  `ShipTreeService::sessionLevels()` turns session skills into tree levels.
  `/pilot/{id}/ships/{typeID}` 301-redirects to `/ship/{typeID}`.
- SP / ISK / asset value are stored rounded to 3 significant figures. `-1` means "not shown".
- `skills` session data includes `masteries` ([typeID => {level, name}]) and `flyable` ([typeID, ...]).
  Sessions fetched before `flyable` existed don't have it. Code must handle it being missing.

## SDE

- The app reads the EVE SDE from the `evesde` schema in the same Postgres database. Identifiers are
  camelCase and must be quoted: `evesde."invTypes"."typeID"`.
- `evesde."shipSkills"` (added by the owner) holds the **direct** skill requirements of every type,
  skills included. It is not transitive.
- Masteries: `certMasteries.masteryLevel` is 0-based (0 → mastery I). A mastery level is complete when
  every cert in it is complete at ≥ that level, and the pilot can fly the ship.
- Ship tree: the SDE has the groups, factions, per-faction prerequisites and `invTypes.shipTreeGroupID`,
  but **not the layout**. `ShipTreeService` derives branches from the prerequisites, and links between
  hull classes from the hull skills' own requirements (Destroyer needs Frigate III). `ShipTreeLayout`
  turns that into lanes and pixel positions; `templates/partials/ship_tree.twig` draws it (boxes placed
  absolutely, SVG connectors) for both the pilot tree (`/pilot/{id}/ships`) and the generic `/ships`.
  Do not hard-code positions. Its size constants must match the `.tree-box` / `.tree-tile` CSS. Some non-ship types also have `shipTreeGroupID` set, so filter on published ships that have
  masteries (`treeShipFilter`).
- Use `published = true` and category 6 for ships. Ship/type icons and portraits come from
  `images.evetech.net`. Faction logos use the faction's `chrFactions."corporationID"`.

## Migrations

- `migrations/NNN_name.sql`, run by `composer migrate` (`bin/migrate.php`). The runner does **not**
  record versions itself, so each file must end with
  `INSERT INTO schema_migrations (version) VALUES ('NNN_name');` and use `IF NOT EXISTS`.
- `.env` points at the live site's database. Don't run migrations or writes against it without asking.
- Composer refuses to run as root, so run `php bin/migrate.php` directly instead of `composer migrate`.

## Logs

- Apache logs for this vhost: `logs/YYYY/MM/DD/{combined,error}.log`. PHP fatals show up in `error.log`
  as `proxy_fcgi` lines.
- App (Monolog) log: `logs/app.log`.

## Frontend

- One stylesheet, `htdocs/assets/css/app.css`. CSS variables are on `:root` (`--color-*`, `--font-*`).
  The site is dark-only and uses BEM-style class names (`block__element--modifier`). No Bootstrap.
- Mastery/skill levels use `.skill-pip--filled|empty` (5 pips). Reuse it.
- `htdocs/assets/js/app.js` is plain JS with no build step.
