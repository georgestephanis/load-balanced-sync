# Plugin Agent Notes

This file provides local guidance for AI coding agents working in this plugin.

## Scope

- Apply these notes to files under this plugin directory.
- Follow WordPress coding standards for PHP changes.
- Keep changes focused and minimal.

## Conventions

- Use existing naming and class structure patterns in the plugin.
- Prefer extending existing classes over introducing new global functions.
- Keep REST and admin concerns separated in their current directories.
- Avoid changing public behavior unless explicitly requested.

## Quality Checks

- Run `composer run lint` for PHP coding standards checks.
- Use `composer run format` only when intentional auto-fixes are desired.
