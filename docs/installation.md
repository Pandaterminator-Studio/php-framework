# Install Iris as a framework

Iris is a Composer library. The example `App/` and `public/` directories in its source repository are separate from a consuming application's code. The application skeleton contains infrastructure and empty routes, with no prebuilt website or business schema.

## Requirements

- PHP 8.3 or newer within the package's supported PHP range.
- Composer 2 and the PHP extensions required by `composer.json`, including cURL and OpenSSL.
- PDO SQLite for the default generated application's database, or PDO MySQL for a MySQL configuration.
- PHP's tokenizer extension for controller and middleware generation.
- A writable application storage directory and a web server whose document root is `public/`.

Inspect `composer check-platform-reqs` after installing. The local regression tests use SQLite and start isolated development servers through `proc_open`; they do not require a customer database.

## Install from the current source checkout

There is no published package release assumed by these instructions. Start from the local source checkout:

```sh
cd /path/to/php-framework
composer install --no-plugins --no-scripts
php bin/iris new ../my-application
cd ../my-application
composer install --no-plugins --no-scripts
composer check-platform-reqs
composer test
php vendor/bin/iris route:list
php vendor/bin/iris serve
```

The framework checkout installs its development dependencies in `.iris/vendor/`. A generated application uses the conventional `vendor/` directory. Its Composer configuration requires `pandaterminator-studio/iris` with `@dev` through a path repository pointing to the actual absolute framework source path. Composer is configured to link that source locally when supported. This is a development setup: keep the checkout available, and update the repository URL if it moves. A distributable application should use a reviewed, pinned package/version and deployment artifact instead of relying on a path on a developer's Mac.

`new` does not run Composer automatically. It refuses existing nonempty destinations and never writes over existing files. The generated project does not include private environment values, customer data, a homepage, or application tables.

## Application layout

```text
my-application/
  app/
    Controllers/
    Middleware/
  bootstrap.php
  composer.json
  config/
    app.php
    database.php
  database/migrations/
  public/
    index.php
    router.php
    .htaccess
  routes/web.php
  storage/
  templates/
  tests/http.php
  vendor/
```

`App\` maps to `app/` through Composer. The framework exports `Core\`; applications do not need to copy framework classes into their own source tree. `bootstrap.php` loads the application's Composer autoloader, explicitly loads `.env` if present, creates `Core\Application`, registers `routes/web.php`, and returns that application. `public/index.php` runs it.

The initial route file returns an empty registration callback. The application root returns 404 until routes are added. Keep configuration, migrations, templates, dependencies and storage outside the public document root.

`composer test` boots the generated application and exercises a route registered inside the test process. Adapt `tests/http.php` to test your application's routes as you add them. See [testing an application](testing.md).

## Configuration and environment

Configuration files in `config/` return arrays. Access values through the application's `Core\Config`, using dot-separated keys such as `database.default` or `app.views`. The scaffold reads `APP_NAME` through `Core\Config::env()`.

Create `.env` from `.env.example` if needed. Its loading is explicit in bootstrap, and existing process environment variables take precedence. The file is ignored by the generated Git configuration. Store credentials in deployment environment configuration or another appropriate secret source; never place them in public files or source control.

The default database is SQLite at `storage/database.sqlite`. Construction and HTTP requests to empty routes do not open it. Database/migration commands open the configured database and may create its metadata. Change `config/database.php` to use injected MySQL settings as described in [database documentation](database.md).

## Serving

For local development, `php vendor/bin/iris serve` uses `public/router.php` and serves only `public/`. The included router permits documented static asset types and refuses direct execution/download of other source files, dotfiles and symbolic assets.

For Apache 2.4, set the virtual host document root to the application's `public/` and permit the included `.htaccess` directives. Confirm your server supports and applies those directives; configuration that ignores `.htaccess` does not inherit its restrictions. Other production web servers need equivalent front-controller routing and access rules. Never serve the application root, `vendor/`, configuration or storage directories directly.

The CLI does not configure production infrastructure, HTTPS, backups or deployment approvals. See [console commands](console.md), [routing and application behavior](routing.md), and [database migrations](database.md).
