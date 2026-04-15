# Load Balanced Sync

Synchronizes plugin, theme, and core update events across multiple WordPress instances in a distributed or load-balanced environment.

## Features

- Synchronizes plugin, theme, and core update events across trusted peers.
- Separates same-group automatic updates from cross-group notifications.
- Provides an admin log browser backed by JSONL log files in uploads.
- Supports downloading and viewing per-server weekly log files.

## Development

### Requirements

- PHP 8.0+
- Composer
- Node.js and npm

### Install Dependencies

```bash
composer install
npm install
```

### Run PHP Coding Standards (WPCS)

```bash
composer run lint
```

### Run JavaScript Lint

```bash
npm run lint:js
```

### Run SCSS/CSS Lint

```bash
npm run lint:css
```

### Build Admin Assets

```bash
npm run build
```

### Watch Admin Assets During Development

```bash
npm run start
```

### Auto-format Source Files

```bash
npm run format
```

## Project Layout

- `includes/`: core services and update execution logic
- `rest-api/`: REST route controllers
- `admin/`: wp-admin UI and notices
- `src/`: authored JavaScript and SCSS source files
- `build/`: generated admin assets produced by `@wordpress/scripts`

## Notes

- The plugin admin UI expects built assets in `build/`.
- If `build/index.asset.php` is missing, the plugin shows an admin notice on its settings screen.
- PHP lint excludes generated and dependency directories: `vendor/`, `node_modules/`, and `build/`.
