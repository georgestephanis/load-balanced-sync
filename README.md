# Load Balanced Sync

Synchronizes plugin, theme, and core update events across multiple WordPress instances in a distributed or load-balanced environment.

## Development

### Requirements

- PHP 8.0+
- Composer

### Install Dependencies

```bash
composer install
```

### Run Coding Standards (WPCS)

```bash
composer run lint
```

### Auto-fix Supported Issues

```bash
composer run format
```

## Project Layout

- `includes/`: core services and update execution logic
- `rest-api/`: REST route controllers
- `admin/`: wp-admin UI and notices
- `assets/`: static assets
