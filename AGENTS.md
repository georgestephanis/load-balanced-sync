# Plugin Agent Notes

This file provides local guidance for AI coding agents working in this plugin.

## Scope

- Apply these notes to files under this plugin directory.
- Follow WordPress coding standards for PHP changes.
- Keep changes focused and minimal.

## Conventions

- Use existing naming and class structure patterns in the plugin.
- Prefer extending existing classes over introducing new global functions.
- Keep REST and admin concerns separated under `includes/rest-api/` and `includes/admin/`.
- Avoid changing public behavior unless explicitly requested.
- Treat `src/` as the source of truth for authored JS and SCSS.
- Treat `build/` as generated output from `@wordpress/scripts`.

## Quality Checks

- When modifying PHP files, run `composer run lint`.
- When modifying JavaScript files, run `npm run lint:js`.
- When modifying SCSS/CSS files, run `npm run lint:css`.
- When modifying multiple file types, run each corresponding lint command before wrapping up the task.
- When modifying JS or SCSS source files, run `npm run build` before wrapping up so `build/` stays in sync.
- Use `composer run format` only when intentional auto-fixes are desired.
- Use `npm run format` when updating `src/` files if formatting needs to be normalized.
