# PROJECT_BLUEPRINT

A complete, self-contained foundation: Laravel 13 JSON API + Vue 3 SPA + JWT authentication + Docker. It holds the architecture and conventions of an existing production app with all of that app's business logic removed. Every file below was built, run and verified exactly as written (see section 13 for how, and for what was not tested).

## 1. Instructions for the executing agent

You are a Claude Code session in an **empty folder**. This file is your only source. Build the project it describes.

### Step 1: ask first

Before writing anything, ask the user for:

1. **App name** (`{{APP_NAME}}`), e.g. `Acme Tracker`. No `"`, `@`, `{`, `}` or `|`.
2. **App slug** (`{{APP_SLUG}}`): lowercase letters, digits and hyphens, starting with a letter, e.g. `acme-tracker`. `{{DB_NAME}}` is derived from it: hyphens become underscores (`acme_tracker`).
3. **Short description** of what the new app does (`{{APP_DESCRIPTION}}`), one sentence.
4. **Keep the example module?** (`Item`, section 8.) Recommend yes: it is the template for every new feature and is easy to delete later.

### Step 2: write the files

Every project file is in this document under a heading of the form `` #### `path/to/file` ``, followed by one code fence holding its exact content. Sections 5 to 11 hold them all, in build order: core → auth → frontend → reference module → Docker → tooling → CLAUDE.md.

The only substitutions are the four exact, uppercase tokens above. Blade and Vue's `{{ ... }}` in lowercase code are real template syntax: leave them alone.

**Fast path (preferred).** If `python3` is available, run this from the empty folder with the user's answers. It does everything in this step and avoids copying errors:

````python
# Save outside the project, e.g. /tmp/extract_blueprint.py, then run:
#   python3 /tmp/extract_blueprint.py "App Name" app-slug "Short description" yes|no
import json, os, re, sys

app_name, slug, description, keep = sys.argv[1:5]
if not re.fullmatch(r'[a-z][a-z0-9-]*', slug):
    sys.exit('The slug must be lowercase letters, digits and hyphens, starting with a letter')
if re.search(r'["@{}|]', app_name):
    sys.exit('The app name cannot contain " @ { } |')
keep = keep.lower() in ('yes', 'y', 'true')

text = open('PROJECT_BLUEPRINT.md', encoding='utf8').read()
replacements = {
    '{{APP_NAME}}': app_name,
    '{{APP_SLUG}}': slug,
    '{{DB_NAME}}': slug.replace('-', '_'),
    '{{APP_DESCRIPTION}}': description.replace('"', "'").replace('\n', ' '),
}

def fill(content):
    for token, value in replacements.items():
        content = content.replace(token, value)
    return content

# The reference module's section, by its exact headings
module_start = text.index('\n## 8. Reference module')
module_end = text.index('\n## 9. Docker')
file_pattern = re.compile(r'^#### `([^`\n]+)`\n+(`{3,})[^\n]*\n(.*?)^\2[ \t]*$', re.M | re.S)

for match in file_pattern.finditer(text):
    path, content = match.group(1), match.group(3)
    if not keep and module_start < match.start() < module_end:
        continue
    if keep:
        # Keep the code, drop the marker lines
        content = re.sub(r'^[ \t]*(?://|<!--) example-module:(?:start|end).*\n', '', content, flags=re.M)
    else:
        # Drop the marked blocks
        content = re.sub(r'^[ \t]*(?://|<!--) example-module:start.*?example-module:end[^\n]*\n', '', content, flags=re.M | re.S)
    content = fill(content)
    if path == 'resources/js/locales/en.json' and not keep:
        messages = json.loads(content)
        del messages['items']
        del messages['nav']['items']
        content = json.dumps(messages, indent=4, ensure_ascii=False) + '\n'
    os.makedirs(os.path.dirname(path) or '.', exist_ok=True)
    with open(path, 'w', encoding='utf8') as file:
        file.write(content)

os.chmod('artisan', 0o755)

if not keep:
    # The template for new features stays available, as documentation
    with open('docs/reference-module.md', 'w', encoding='utf8') as file:
        file.write(fill(text[module_start:module_end]).lstrip())

# So files created in the containers belong to the host user
env = open('.env.example', encoding='utf8').read()
if hasattr(os, 'getuid'):
    env = re.sub(r'^WWWUSER=.*$', f'WWWUSER={os.getuid()}', env, flags=re.M)
    env = re.sub(r'^WWWGROUP=.*$', f'WWWGROUP={os.getgid()}', env, flags=re.M)
open('.env', 'w', encoding='utf8').write(env)
print('Project files written')
````

**Without Python,** write each file by hand in the order of the sections and then:

- Replace the four tokens everywhere.
- Example module **kept**: delete only the marker lines (`// example-module:start`, `// example-module:end`, and the `<!-- ... -->` forms).
- Example module **not kept**: skip every file in section 8. In `routes/api.php`, `resources/js/Index.vue`, `resources/js/services/apiEndPoints.ts` and `resources/js/services/router.ts`, delete each block between the markers, including the markers. In `resources/js/locales/en.json` delete the `items` object and `nav.items`. Copy section 8 to `docs/reference-module.md` (`CLAUDE.md` points there).
- `artisan` must be executable (`chmod +x artisan`). Copy `.env.example` to `.env` and set `WWWUSER` / `WWWGROUP` to the output of `id -u` / `id -g`.

### Step 3: start and verify

1. Run `docker compose up -d --build`. It installs dependencies, generates `APP_KEY` and `JWT_SECRET`, migrates, seeds and only returns when the app answers. The first run takes a few minutes. If a port is taken, change `APP_PORT`, `VITE_PORT`, `FORWARD_DB_PORT` or `PHPMYADMIN_PORT` in `.env` (when changing `APP_PORT`, change `APP_URL` too).
2. Work through **section 12** in order.
3. Report to the user: each checklist item with pass or fail, plus the real output of anything that failed. Mention that the versions of indirect dependencies were resolved at install (`composer.lock`, `package-lock.json` were just created: commit them).

### Rules

- **Use the exact versions listed; do not upgrade or swap libraries.** If a version can't be installed, stop and report it (Composer refuses versions with a published security advisory: pick the nearest fixed patch in the same major.minor and say so).
- **No business logic.** Only the foundation, plus the example module if the user kept it. The app's actual features come later, following `CLAUDE.md`.
- **Don't change the architecture.** No API Resources, repository interfaces, service-provider bindings or `/v1` prefixes: section 13 explains why they're absent.
- If something fails, fix the real cause and tell the user what you changed. Don't weaken or delete a test to make it pass.
- Don't commit or push unless asked.

## 2. Tech stack

| Layer | What | Version |
|---|---|---|
| Runtime | PHP (Docker image `php:8.4-fpm-bookworm`, verified on 8.4.27) | ^8.4 |
| Framework | `laravel/framework` | 13.37.0 |
| JWT | `firebase/php-jwt` (HS256; v7 rejects keys shorter than 32 bytes) | 7.2.1 |
| HTTP (PHP) | `guzzlehttp/guzzle` (behind Laravel's `Http` facade) | 7.15.5 |
| REPL | `laravel/tinker` | 3.0.2 |
| Database | MySQL (`mysql:8.0`, verified on 8.0.46); tests use in-memory SQLite | 8.0 |
| Queue / cache | Laravel `database` driver (`jobs`, `cache` tables) | |
| Web server | nginx (`nginx:1.28-alpine`) + PHP-FPM | 1.28 |
| Node | `node:24.15.0-bookworm-slim` | 24.15.0 |
| Frontend | `vue` / `vue-router` / `pinia` / `pinia-plugin-persistedstate` | 3.5.44 / 5.4.0 / 4.1.0 / 4.7.1 |
| Texts | `vue-i18n` (Composition API, `legacy: false`) | 11.4.14 |
| HTTP (JS) | `axios` | 1.20.0 |
| Styling | `tailwindcss` + `@tailwindcss/vite` | 4.3.3 |
| Build | `vite` (Rolldown), `laravel-vite-plugin`, `@vitejs/plugin-vue` | 8.3.4 / 3.2.0 / 6.0.10 |
| Types | `typescript` (not 7: `typescript-eslint` supports < 6.1), `vue-tsc` | 6.0.3 / 3.3.13 |
| PHP tools | `phpunit/phpunit`, `larastan/larastan` (level 5), `laravel/pint`, `laravel/pail`, `mockery/mockery`, `nunomaduro/collision`, `fakerphp/faker` | 13.4.5 / 3.13.0 / 1.32.1 / 1.2.7 / 1.6.15 / 8.9.5 / 1.24.1 |
| JS tools | `eslint`, `typescript-eslint`, `eslint-plugin-vue`, `prettier` | 10.13.0 / 8.71.2 / 10.11.1 / 3.9.10 |
| Tools image | `phpmyadmin:5` | 5 |

## 3. Directory tree

Generated at runtime and not listed: `vendor/`, `node_modules/`, `.env`, `composer.lock`, `package-lock.json`, `storage/`, `bootstrap/cache/`, `public/build/`. If the example module isn't kept, every `Item`/`items` path below is left out.

```text
{{APP_SLUG}}/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── AuthController.php
│   │   │   ├── Controller.php
│   │   │   └── ItemController.php
│   │   ├── Middleware/
│   │   │   └── JwtAuthMiddleware.php
│   │   └── Requests/
│   │       ├── AuthenticatedRequest.php
│   │       ├── BaseFormRequest.php
│   │       ├── ItemIdRequest.php
│   │       ├── ItemListRequest.php
│   │       ├── ItemRequest.php
│   │       ├── LoginRequest.php
│   │       └── RegisterRequest.php
│   ├── Jobs/
│   │   └── PruneRefreshTokensJob.php
│   ├── Models/
│   │   ├── Item.php
│   │   ├── RefreshToken.php
│   │   └── User.php
│   ├── Providers/
│   │   └── AppServiceProvider.php
│   ├── Repository/
│   │   ├── ItemRepository.php
│   │   ├── RefreshTokenRepository.php
│   │   └── UserRepository.php
│   └── Services/
│       ├── Auth/
│       │   ├── AuthService.php
│       │   └── JwtService.php
│       └── Item/
│           └── ItemService.php
├── bootstrap/
│   ├── app.php
│   └── providers.php
├── config/
│   ├── app.php
│   ├── items.php
│   ├── jwt.php
│   └── rate_limits.php
├── database/
│   ├── migrations/
│   │   ├── 0001_01_01_000001_create_cache_table.php
│   │   ├── 0001_01_01_000002_create_jobs_table.php
│   │   ├── 2026_01_01_000001_create_users_table.php
│   │   ├── 2026_01_01_000002_create_refresh_tokens_table.php
│   │   └── 2026_01_01_000003_create_items_table.php
│   └── seeders/
│       ├── DatabaseSeeder.php
│       └── UserSeeder.php
├── docker/
│   ├── nginx/
│   │   └── default.conf
│   └── php/
│       ├── Dockerfile
│       ├── entrypoint.sh
│       └── php.ini
├── docs/
│   └── production-deploy.md
├── public/
│   ├── index.php
│   └── robots.txt
├── resources/
│   ├── css/
│   │   └── app.css
│   ├── js/
│   │   ├── locales/
│   │   │   └── en.json
│   │   ├── pages/
│   │   │   ├── auth/
│   │   │   │   ├── Login.vue
│   │   │   │   └── Register.vue
│   │   │   ├── dashboard/
│   │   │   │   └── Dashboard.vue
│   │   │   └── items/
│   │   │       ├── ItemForm.vue
│   │   │       └── Items.vue
│   │   ├── services/
│   │   │   ├── apiEndPoints.ts
│   │   │   ├── apiErrors.ts
│   │   │   ├── httpCommon.ts
│   │   │   ├── router.ts
│   │   │   ├── store.ts
│   │   │   └── toast.ts
│   │   ├── Index.vue
│   │   ├── app.ts
│   │   ├── env.d.ts
│   │   └── i18n.ts
│   └── views/
│       └── welcome.blade.php
├── routes/
│   ├── api.php
│   ├── console.php
│   └── web.php
├── tests/
│   ├── Feature/
│   │   ├── AuthTest.php
│   │   └── ItemTest.php
│   ├── Unit/
│   │   └── JwtServiceTest.php
│   └── TestCase.php
├── .dockerignore
├── .editorconfig
├── .env.example
├── .gitignore
├── .prettierrc
├── CLAUDE.md
├── Makefile
├── artisan
├── composer.json
├── docker-compose.yml
├── eslint.config.js
├── package.json
├── phpstan.neon
├── phpunit.xml
├── tsconfig.json
└── vite.config.ts
```

## 4. Architecture & conventions

`CLAUDE.md` (section 11) holds the full rules for future sessions. The short version:

**Layers**

| Layer | Location | Does | Never does |
|---|---|---|---|
| Route | `routes/api.php` | Prefix + middleware group, verb-like segments (`items/create`) | Logic, versioning |
| Middleware | `app/Http/Middleware` | Verifies the token, puts the user on the request | Business rules |
| Form Request | `app/Http/Requests` | `rules()` in pipe syntax, extends `BaseFormRequest` | Queries |
| Controller | `app/Http/Controllers` | Calls one service, returns the response | Logic, queries |
| Service | `app/Services/<Feature>` | All logic, not-found checks, logging, jobs; throws readable exceptions | Queries, HTTP responses |
| Repository | `app/Repository` | Every query and write, one concrete class per model | Rules |
| Model | `app/Models` | `$fillable`, `$casts`, `$hidden`, relationships, constants | Queries |

**Request lifecycle**

```text
Browser → nginx → php-fpm → routes/api.php
  → jwtAuth (401, or sets userModel on the request)
  → throttle:api (per user, 429)
  → Form Request (422)
  → Controller → Service → Repository → Model
  ← ['data' => …]   or   catch (\Exception) → exceptionResponse() → ['error' => …]
Frontend: apiEndPoints.ts → httpCommon.ts (adds the Bearer token; on 401: one refresh, one retry)
  → page reads response.data.data; on failure apiErrors.ts turns the error into a readable message
```

**Formats**

```jsonc
// Success, always status 200
{ "data": { "id": 1, "name": "First" } }

// List
{ "data": { "items": [ ], "pagination": { "page": 1, "per_page": 15, "total": 0, "last_page": 1 } } }

// Error (4xx/5xx): a readable sentence, never technical text
{ "error": "We couldn't find this item. It may have been deleted." }

// Validation (422): Laravel's format
{ "message": "The name field is required.", "errors": { "name": ["The name field is required."] } }
```

**Rules future-you must follow**

- Services report fixable problems as `throw new \Exception('readable message', 404)` (4xx or 502). Everything else becomes a logged, generic 500 in `Controller::exceptionResponse()`.
- Ids from the client are always looked up within the owner (`getItemForUser($id, $userId)`).
- List params: `page`, `per_page` (validated against a config list), `q`, one param per filter, `sort` as a named list.
- Components never call axios: one method per endpoint in `apiEndPoints.ts`. Every text is in `en.json`. Every failed call shows a message through `apiErrors.ts`.
- A failed load never falls through to an "empty" state.

## 5. Core code

The framework boot files, the base classes every feature extends, and the config that differs from Laravel's defaults. Laravel merges a file in `config/` over its own defaults, so these contain only what differs; every option not listed here keeps the framework's default.

There is deliberately no custom exception handler (`withExceptions` is empty) and no response helper class: `Controller::exceptionResponse()` plus `response()->json(['data' => …])` are the standard. There are no project interfaces, traits, base services or base repositories either: those classes don't exist in the source architecture.

#### `artisan`

```php
#!/usr/bin/env php
<?php

use Illuminate\Foundation\Application;
use Symfony\Component\Console\Input\ArgvInput;

define('LARAVEL_START', microtime(true));

// Register the Composer autoloader...
require __DIR__.'/vendor/autoload.php';

// Bootstrap Laravel and handle the command...
/** @var Application $app */
$app = require_once __DIR__.'/bootstrap/app.php';

$status = $app->handleCommand(new ArgvInput);

exit($status);
```

#### `public/index.php`

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
```

#### `public/robots.txt`

```
User-agent: *
Disallow:
```

#### `bootstrap/app.php`

```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\ThrottleRequests;
use App\Http\Middleware\JwtAuthMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Laravel runs `throttle` early by priority; the api limit is keyed by the user, so the access token
        // (which identifies the user) must be checked first
        $middleware->prependToPriorityList(ThrottleRequests::class, JwtAuthMiddleware::class);

        $middleware->alias([
            // Verified access token (401 otherwise); puts the User model on the request
            'jwtAuth' => JwtAuthMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
```

#### `bootstrap/providers.php`

```php
<?php

return [
    App\Providers\AppServiceProvider::class,
];
```

#### `app/Providers/AppServiceProvider.php`

```php
<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Only these proxies may set X-Forwarded-* (client IP, https); empty = none (config/app.php trusted_proxies)
        TrustProxies::at(config('app.trusted_proxies') ?: []);

        $this->configureRateLimiting();
    }

    /**
     * Limiters used by the routes (`throttle:api`, `throttle:auth`), see config/rate_limits.php.
     * The api limit is per user: it runs after jwtAuth, which verified the access token.
     */
    private function configureRateLimiting()
    {
        $tooMany = fn () => response()->json(['error' => 'Too many requests. Please wait a minute and try again.'], 429);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(config('rate_limits.api_per_minute'))
            ->by('api|' . ($request->attributes->get('userModel')->id ?? $request->ip()))
            ->response($tooMany));

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(config('rate_limits.auth_per_minute'))
            ->by('auth|' . $request->ip())
            ->response($tooMany));
    }
}
```

#### `app/Http/Controllers/Controller.php`

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

abstract class Controller
{
    /**
     * Error response for an exception from a service that throws readable messages with an HTTP-like code
     * (4xx, or 502 when a third party couldn't be reached). Validation errors keep Laravel's 422 format. Anything
     * else (a database error, a bug, a raw API error) is logged and answered with a generic message, so technical
     * texts never reach the user.
     */
    protected function exceptionResponse(\Exception $e)
    {
        if ($e instanceof ValidationException || $e instanceof HttpResponseException) {
            throw $e;
        }

        $code = $e->getCode();

        // HTTP client exceptions carry the remote status code, but their message is the raw API response
        if (!$e instanceof HttpClientException && is_int($code) && (($code >= 400 && $code < 500) || $code === 502)) {
            return response()->json(['error' => $e->getMessage()], $code);
        }

        Log::error('Unexpected error in API request', [
            'exception' => get_class($e),
            'error' => $e->getMessage(),
        ]);

        return response()->json(['error' => 'Something went wrong on our side. Please try again in a few minutes.'], 500);
    }
}
```

#### `app/Http/Requests/BaseFormRequest.php`

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class BaseFormRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    /**
     * The current user, resolved by the jwtAuth middleware. (Not getUser(): Symfony's Request already has that method,
     * it returns the HTTP basic auth name.)
     */
    public function getAuthUser()
    {
        return $this->attributes->get('userModel');
    }

    /**
     * The verified claims of the access token, set by the jwtAuth middleware
     */
    public function getAccessTokenClaims()
    {
        return $this->attributes->get('accessTokenClaims');
    }
}
```

#### `app/Http/Requests/AuthenticatedRequest.php`

```php
<?php

namespace App\Http\Requests;

/**
 * Endpoints without input of their own: gives the controller getAuthUser() (BaseFormRequest is abstract)
 */
class AuthenticatedRequest extends BaseFormRequest
{
    public function rules()
    {
        return [];
    }
}
```

#### `config/app.php`

```php
<?php

// Only the options that differ from the framework's config/app.php; Laravel merges this file over its own
return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Proxies / load balancers allowed to set X-Forwarded-* headers (client IP,
    | https). Comma-separated IPs or CIDR ranges, "*" for any (only when the app
    | can't be reached except through the proxy). Empty = none: X-Forwarded-*
    | headers are ignored.
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES') === '*'
        ? '*'
        : array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))),

];
```

#### `config/rate_limits.php`

```php
<?php

/**
 * Request limits per minute (AppServiceProvider defines the limiters, routes use them with `throttle:<name>`).
 * Over the limit -> 429 with a plain message the frontend shows as-is.
 */
return [

    // Every endpoint behind jwtAuth, per user
    'api_per_minute' => (int) env('RATE_LIMIT_API_PER_MINUTE', 240),

    // Register, login and refresh (no access token yet), per IP
    'auth_per_minute' => (int) env('RATE_LIMIT_AUTH_PER_MINUTE', 30),

];
```

#### `routes/api.php`

```php
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
// example-module:start
use App\Http\Controllers\ItemController;
// example-module:end

// No access token yet: limited per IP. refresh reads the httpOnly refresh token cookie.
Route::group([
    'prefix' => 'auth',
    'middleware' => 'throttle:auth',
], function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('refresh', [AuthController::class, 'refresh']);
});

Route::group([
    'prefix' => 'auth',
    'middleware' => ['jwtAuth', 'throttle:api'],
], function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);
});

// example-module:start
Route::group([
    'prefix' => 'items',
    'middleware' => ['jwtAuth', 'throttle:api'],
], function () {
    Route::get('list', [ItemController::class, 'getItems']);
    Route::get('get', [ItemController::class, 'getItem']);
    Route::post('create', [ItemController::class, 'createItem']);
    Route::post('update', [ItemController::class, 'updateItem']);
    Route::post('delete', [ItemController::class, 'deleteItem']);
});
// example-module:end
```

#### `routes/web.php`

```php
<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

// SPA deep links (/items, /login, …): a page refresh loads the current vue-router path from the server, so every other
// GET path returns the SPA shell. Registered last; api/, build/, storage/ and the health check keep their own routes
// (and real 404s)
Route::get('{path}', function () {
    return view('welcome');
})->where('path', '^(?!api/|api$|build/|storage/|up$).+');
```

#### `routes/console.php`

```php
<?php

use App\Jobs\PruneRefreshTokensJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('auth:prune-refresh-tokens', function () {
    PruneRefreshTokensJob::dispatch();
    $this->info('Queued the refresh token cleanup');
})->purpose('Delete refresh tokens that expired a while ago');

Schedule::command('auth:prune-refresh-tokens')->daily();
```

#### `resources/views/welcome.blade.php`

```php
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ config('app.name') }}</title>
    <link rel="icon" href="data:,">

    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body class="antialiased">
<div id="app"></div>
</body>
</html>
```

#### `database/migrations/0001_01_01_000001_create_cache_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }
};
```

#### `database/migrations/0001_01_01_000002_create_jobs_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
    }
};
```

#### `database/seeders/DatabaseSeeder.php`

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
        ]);
    }
}
```

#### `app/Jobs/PruneRefreshTokensJob.php`

```php
<?php

namespace App\Jobs;

use App\Repository\RefreshTokenRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Deletes refresh tokens that expired a while ago. Queued daily by auth:prune-refresh-tokens (routes/console.php).
 */
class PruneRefreshTokensJob implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public $backoff = [30, 60, 120];

    public function handle(RefreshTokenRepository $refreshTokenRepository): void
    {
        $deleted = $refreshTokenRepository->deleteExpiredBefore(now()->subDays(config('jwt.prune_after_days')));

        Log::info('Expired refresh tokens deleted', ['deleted' => $deleted]);
    }
}
```

## 6. JWT authentication

**Endpoints** (routes are in `routes/api.php`, section 5)

| Method | Path | Protection | Input | Answer `data` |
|---|---|---|---|---|
| POST | `/api/auth/register` | `throttle:auth` | `name`, `email`, `password`, `password_confirmation` | `{user, access_token, expires_in}` + cookie |
| POST | `/api/auth/login` | `throttle:auth` | `email`, `password` | same |
| POST | `/api/auth/refresh` | `throttle:auth` | the refresh cookie | same; the cookie rotates |
| GET | `/api/auth/me` | `jwtAuth`, `throttle:api` | | the user |
| POST | `/api/auth/logout` | `jwtAuth`, `throttle:api` | the cookie | `true`; the cookie is cleared |

**How it works**

- **Access token:** HS256 JWT signed with `JWT_SECRET`, claims `iss aud sub jti iat nbf exp`, 15 minutes. Kept in memory in the browser (a variable in `httpCommon.ts`), never in web storage. Sent as `Authorization: Bearer`.
- **Refresh token:** 64 random characters, 30 days. Only its sha256 is in the database. It lives in an `httpOnly`, `SameSite=Strict` cookie with `path=/api/auth`, so scripts can't read it and it only goes to the auth endpoints.
- **Rotation:** every refresh replaces the token, inside the same `family` (one login session). A conditional `UPDATE … WHERE replaced_at IS NULL` decides which of two parallel requests owns the rotation.
- **Reuse:** a rotated token used again within 10 seconds is a race between two tabs: it gets an access token and the cookie stays as it is. Later, it's treated as stolen: the whole family is revoked and the user must sign in again.
- **Logout:** revokes the family and denies the access token's `jti` in the cache until it would have expired.
- **Page reload:** the access token is gone, so `store.restoreSession()` (called by the router guard) gets a new one with the cookie. The persisted `hasSession` flag spares guests a failing refresh request on every page load.
- **Expired token during use:** the axios interceptor refreshes once (all failing requests share that one refresh) and repeats the request. If the refresh is rejected, the session ends and the user goes to the login page, with a safe `redirect` back. Being offline or a server error doesn't end the session.
- **Roles and permissions:** none. The source project has none; it only scopes data by tenant. Don't add them until a feature needs them.

### Backend

#### `config/jwt.php`

```php
<?php

return [

    // HS256 signing key, at least 32 bytes (firebase/php-jwt v7 rejects shorter ones). Generated by the Docker entrypoint
    'secret' => env('JWT_SECRET'),

    // The `iss` and `aud` claims: a token issued for another app is rejected
    'issuer' => env('JWT_ISSUER', env('APP_URL', 'http://localhost')),
    'audience' => env('JWT_AUDIENCE', env('APP_URL', 'http://localhost')),

    // Tolerated clock drift when checking exp/nbf/iat
    'leeway_seconds' => 10,

    // Short-lived, kept in the browser's memory only
    'access_ttl_seconds' => (int) env('JWT_ACCESS_TTL_SECONDS', 900),

    // Rotated on every refresh; the expiry moves forward with each rotation
    'refresh_ttl_days' => (int) env('JWT_REFRESH_TTL_DAYS', 30),

    // A rotated refresh token used again within this time is a race (two tabs refreshing at once): it gets an access
    // token. Later it is treated as stolen and the whole session is revoked.
    'refresh_reuse_grace_seconds' => 10,

    // httpOnly cookie holding the refresh token, only sent to the auth endpoints
    'refresh_cookie' => [
        'name' => env('JWT_REFRESH_COOKIE_NAME', 'refresh_token'),
        'path' => '/api/auth',
        'domain' => env('JWT_REFRESH_COOKIE_DOMAIN'),
        // Local Docker runs on plain http: .env.example sets false. Production must leave it true.
        'secure' => (bool) env('JWT_REFRESH_COOKIE_SECURE', true),
        'same_site' => 'strict',
    ],

    // Expired refresh tokens are kept this long (reuse detection), then deleted by auth:prune-refresh-tokens
    'prune_after_days' => 7,

];
```

#### `database/migrations/2026_01_01_000001_create_users_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
```

#### `database/migrations/2026_01_01_000002_create_refresh_tokens_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            // One login session: every rotation of a refresh token stays in the same family
            $table->uuid('family')->index();
            // sha256 of the token; the token itself is only ever in the user's cookie
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('replaced_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
    }
};
```

#### `database/seeders/UserSeeder.php`

```php
<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * A sign-in for local development only: never created outside APP_ENV=local.
     */
    public function run(): void
    {
        if (!app()->isLocal()) {
            return;
        }

        User::firstOrCreate(['email' => 'demo@example.com'], ['name' => 'Demo User', 'password' => 'password']);
    }
}
```

#### `app/Models/User.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Model
{
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'password' => 'hashed',
    ];

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }
}
```

#### `app/Models/RefreshToken.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefreshToken extends Model
{
    protected $fillable = [
        'user_id',
        'family',
        'token_hash',
        'expires_at',
        'replaced_at',
        'revoked_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'replaced_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

#### `app/Repository/UserRepository.php`

```php
<?php

namespace App\Repository;

use App\Models\User;

class UserRepository
{

    public function getUserById($id)
    {
        return User::where('id', $id)->first();
    }

    public function findByEmail($email)
    {
        return User::where('email', $email)->first();
    }

    public function createUser($data)
    {
        $user = new User();
        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->password = $data['password'];
        $user->save();

        return $user;
    }

}
```

#### `app/Repository/RefreshTokenRepository.php`

```php
<?php

namespace App\Repository;

use App\Models\RefreshToken;

class RefreshTokenRepository
{

    public function createRefreshToken($data)
    {
        $refreshToken = new RefreshToken();
        $refreshToken->user_id = $data['user_id'];
        $refreshToken->family = $data['family'];
        $refreshToken->token_hash = $data['token_hash'];
        $refreshToken->expires_at = $data['expires_at'];
        $refreshToken->save();

        return $refreshToken;
    }

    public function findByHash($tokenHash)
    {
        return RefreshToken::where('token_hash', $tokenHash)->first();
    }

    /**
     * Marks the token as rotated, only if nothing else did it first: true = this request owns the rotation
     */
    public function claimForRotation($refreshToken)
    {
        return RefreshToken::where('id', $refreshToken->id)
            ->whereNull('replaced_at')
            ->whereNull('revoked_at')
            ->update(['replaced_at' => now()]) === 1;
    }

    public function revokeFamily($family)
    {
        RefreshToken::where('family', $family)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function deleteExpiredBefore($date)
    {
        return RefreshToken::where('expires_at', '<', $date)->delete();
    }

}
```

#### `app/Services/Auth/JwtService.php`

```php
<?php

namespace App\Services\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Access tokens: short-lived HS256 JWTs (firebase/php-jwt), verified without a database table. Logging out denies the
 * token's `jti` in the cache until it would have expired anyway.
 */
class JwtService
{
    private const ALGORITHM = 'HS256';

    /**
     * {token, expires_in (seconds)}
     */
    public function issueAccessToken($user)
    {
        $now = time();
        $ttl = config('jwt.access_ttl_seconds');

        $token = JWT::encode([
            'iss' => config('jwt.issuer'),
            'aud' => config('jwt.audience'),
            'sub' => (string) $user->id,
            'jti' => (string) Str::uuid(),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
        ], config('jwt.secret'), self::ALGORITHM);

        return ['token' => $token, 'expires_in' => $ttl];
    }

    /**
     * The verified claims. Throws on a bad signature, an expired token or any wrong claim; the reason is for the log only.
     */
    public function decodeAccessToken($token)
    {
        JWT::$leeway = config('jwt.leeway_seconds');

        $claims = JWT::decode($token, new Key(config('jwt.secret'), self::ALGORITHM));

        foreach (['iss', 'aud', 'sub', 'jti', 'exp'] as $claim) {
            if (!isset($claims->$claim)) {
                throw new \UnexpectedValueException('Missing claim: ' . $claim);
            }
        }

        if ($claims->iss !== config('jwt.issuer') || $claims->aud !== config('jwt.audience')) {
            throw new \UnexpectedValueException('Token was issued for another app');
        }

        if (Cache::has($this->denyKey($claims->jti))) {
            throw new \UnexpectedValueException('Token was revoked');
        }

        return $claims;
    }

    /**
     * Logout: this access token stops working now instead of when it expires
     */
    public function denyAccessToken($claims)
    {
        $seconds = $claims->exp + config('jwt.leeway_seconds') - time();

        if ($seconds > 0) {
            Cache::put($this->denyKey($claims->jti), true, $seconds);
        }
    }

    private function denyKey($jti)
    {
        return "jwt_denied_{$jti}";
    }
}
```

#### `app/Services/Auth/AuthService.php`

```php
<?php

namespace App\Services\Auth;

use App\Repository\RefreshTokenRepository;
use App\Repository\UserRepository;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Register, login, refresh, logout. Every method that starts or continues a session returns
 * {user, access_token, expires_in, refresh_token}; refresh_token is null when the cookie must stay as it is.
 */
class AuthService
{
    public function __construct(private readonly UserRepository $userRepository,
                                private readonly RefreshTokenRepository $refreshTokenRepository,
                                private readonly JwtService $jwtService)
    {
    }

    public function register($data)
    {
        $email = $this->normalizeEmail($data['email']);

        if ($this->userRepository->findByEmail($email)) {
            throw ValidationException::withMessages(['email' => 'This email address is already registered.']);
        }

        $user = $this->userRepository->createUser([
            'name' => $data['name'],
            'email' => $email,
            'password' => $data['password'],
        ]);

        Log::info('User registered', ['user_id' => $user->id]);

        return $this->buildSession($user, $this->issueRefreshToken($user->id, (string) Str::uuid()));
    }

    public function login($data)
    {
        $user = $this->userRepository->findByEmail($this->normalizeEmail($data['email']));

        if (!$user) {
            // Same work as a real check, so the response time doesn't reveal which emails exist
            Hash::make($data['password']);
            throw new \Exception('The email or password is incorrect.', 401);
        }

        if (!Hash::check($data['password'], $user->password)) {
            throw new \Exception('The email or password is incorrect.', 401);
        }

        return $this->buildSession($user, $this->issueRefreshToken($user->id, (string) Str::uuid()));
    }

    /**
     * A new access token and a rotated refresh token. A token that was already rotated is either a race between two tabs
     * (within the grace time: an access token, the browser already has the new cookie) or a stolen token (the session ends).
     */
    public function refresh($plainToken)
    {
        $refreshToken = $plainToken ? $this->refreshTokenRepository->findByHash($this->hashToken($plainToken)) : null;

        if (!$refreshToken || $refreshToken->revoked_at || $refreshToken->expires_at->isPast()) {
            throw new \Exception('Your session has expired. Please sign in again.', 401);
        }

        $user = $this->userRepository->getUserById($refreshToken->user_id);

        if (!$user) {
            throw new \Exception('Your session has expired. Please sign in again.', 401);
        }

        if ($this->refreshTokenRepository->claimForRotation($refreshToken)) {
            return $this->buildSession($user, $this->issueRefreshToken($user->id, $refreshToken->family));
        }

        // Rotated meanwhile: replaced_at is only on the row, not on the model read before the claim
        $replacedAt = $this->refreshTokenRepository->findByHash($refreshToken->token_hash)->replaced_at;

        if ($replacedAt && $replacedAt->diffInSeconds(now()) <= config('jwt.refresh_reuse_grace_seconds')) {
            return $this->buildSession($user, null);
        }

        $this->refreshTokenRepository->revokeFamily($refreshToken->family);
        Log::warning('Refresh token reused, session revoked', ['user_id' => $user->id, 'family' => $refreshToken->family]);

        throw new \Exception('Your session has expired. Please sign in again.', 401);
    }

    public function logout($plainToken, $claims)
    {
        $this->jwtService->denyAccessToken($claims);

        $refreshToken = $plainToken ? $this->refreshTokenRepository->findByHash($this->hashToken($plainToken)) : null;

        if ($refreshToken) {
            $this->refreshTokenRepository->revokeFamily($refreshToken->family);
        }
    }

    private function buildSession($user, $plainRefreshToken)
    {
        $accessToken = $this->jwtService->issueAccessToken($user);

        return [
            'user' => $user,
            'access_token' => $accessToken['token'],
            'expires_in' => $accessToken['expires_in'],
            'refresh_token' => $plainRefreshToken,
        ];
    }

    private function issueRefreshToken($userId, $family)
    {
        $plainToken = Str::random(64);

        $this->refreshTokenRepository->createRefreshToken([
            'user_id' => $userId,
            'family' => $family,
            'token_hash' => $this->hashToken($plainToken),
            'expires_at' => now()->addDays(config('jwt.refresh_ttl_days')),
        ]);

        return $plainToken;
    }

    private function hashToken($plainToken)
    {
        return hash('sha256', $plainToken);
    }

    private function normalizeEmail($email)
    {
        return Str::lower(trim($email));
    }
}
```

#### `app/Http/Middleware/JwtAuthMiddleware.php`

```php
<?php

namespace App\Http\Middleware;

use App\Repository\UserRepository;
use App\Services\Auth\JwtService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Endpoints of a signed-in user: a valid access token (Authorization: Bearer) of a user that still exists, 401 otherwise.
 * The model is put on the request (`userModel`, read with BaseFormRequest::getAuthUser()), so controllers don't look it up
 * again. The frontend answers a 401 by refreshing the session once (httpCommon.ts).
 */
class JwtAuthMiddleware
{
    public function __construct(private readonly JwtService $jwtService,
                                private readonly UserRepository $userRepository)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return $this->unauthorized();
        }

        try {
            $claims = $this->jwtService->decodeAccessToken($token);
        } catch (\Exception $e) {
            // The reason (expired, bad signature, ...) goes to the log only
            Log::info('Invalid access token', ['path' => $request->path(), 'error' => $e->getMessage()]);

            return $this->unauthorized();
        }

        $user = $this->userRepository->getUserById($claims->sub);

        if (!$user) {
            return $this->unauthorized();
        }

        $request->attributes->set('userModel', $user);
        $request->attributes->set('accessTokenClaims', $claims);

        return $next($request);
    }

    private function unauthorized()
    {
        return response()->json(['error' => 'Your session has expired. Please sign in again.'], 401);
    }
}
```

#### `app/Http/Requests/RegisterRequest.php`

```php
<?php

namespace App\Http\Requests;

class RegisterRequest extends BaseFormRequest
{
    public function rules()
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|min:8|max:255|confirmed',
        ];
    }
}
```

#### `app/Http/Requests/LoginRequest.php`

```php
<?php

namespace App\Http\Requests;

class LoginRequest extends BaseFormRequest
{
    public function rules()
    {
        return [
            'email' => 'required|string|email|max:255',
            'password' => 'required|string|max:255',
        ];
    }
}
```

#### `app/Http/Controllers/AuthController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\AuthenticatedRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Services\Auth\AuthService;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService)
    {
    }

    public function register(RegisterRequest $request)
    {
        try {
            $session = $this->authService->register($request->all());
        } catch (\Exception $e) {
            return $this->exceptionResponse($e);
        }

        return $this->sessionResponse($session);
    }

    public function login(LoginRequest $request)
    {
        try {
            $session = $this->authService->login($request->all());
        } catch (\Exception $e) {
            return $this->exceptionResponse($e);
        }

        return $this->sessionResponse($session);
    }

    // No Form Request: the only input is the cookie
    public function refresh(Request $request)
    {
        try {
            $session = $this->authService->refresh($request->cookie(config('jwt.refresh_cookie.name')));
        } catch (\Exception $e) {
            return $this->exceptionResponse($e)->withCookie($this->refreshCookie('', -1));
        }

        return $this->sessionResponse($session);
    }

    public function me(AuthenticatedRequest $request)
    {
        return response()->json(['data' => $request->getAuthUser()]);
    }

    public function logout(AuthenticatedRequest $request)
    {
        try {
            $this->authService->logout($request->cookie(config('jwt.refresh_cookie.name')), $request->getAccessTokenClaims());
        } catch (\Exception $e) {
            return $this->exceptionResponse($e);
        }

        return response()->json(['data' => true])->withCookie($this->refreshCookie('', -1));
    }

    private function sessionResponse($session)
    {
        $response = response()->json([
            'data' => [
                'user' => $session['user'],
                'access_token' => $session['access_token'],
                'expires_in' => $session['expires_in'],
            ],
        ]);

        if ($session['refresh_token']) {
            $response->withCookie($this->refreshCookie($session['refresh_token'], config('jwt.refresh_ttl_days') * 1440));
        }

        return $response;
    }

    private function refreshCookie($value, $minutes)
    {
        $cookie = config('jwt.refresh_cookie');

        return cookie($cookie['name'], $value, $minutes, $cookie['path'], $cookie['domain'], $cookie['secure'], true, false, $cookie['same_site']);
    }
}
```

#### `tests/TestCase.php`

```php
<?php

namespace Tests;

use App\Models\User;
use App\Services\Auth\JwtService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function createUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => 'correct-password',
        ], $attributes));
    }

    /**
     * Headers of a signed-in request (routes behind jwtAuth)
     */
    protected function authHeaders(User $user): array
    {
        return ['Authorization' => 'Bearer ' . app(JwtService::class)->issueAccessToken($user)['token']];
    }
}
```

#### `tests/Unit/JwtServiceTest.php`

```php
<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Auth\JwtService;
use Firebase\JWT\JWT;
use Tests\TestCase;

class JwtServiceTest extends TestCase
{
    private function user(): User
    {
        $user = new User();
        $user->id = 7;

        return $user;
    }

    public function test_an_issued_token_decodes_to_its_user()
    {
        $service = new JwtService();

        $claims = $service->decodeAccessToken($service->issueAccessToken($this->user())['token']);

        $this->assertSame('7', $claims->sub);
        $this->assertSame(config('jwt.issuer'), $claims->iss);
    }

    public function test_a_token_signed_with_another_secret_is_rejected()
    {
        $token = JWT::encode(['sub' => '7', 'exp' => time() + 60], 'another-secret-of-at-least-32-bytes-long', 'HS256');

        $this->expectException(\Exception::class);

        (new JwtService())->decodeAccessToken($token);
    }

    public function test_an_expired_token_is_rejected()
    {
        config(['jwt.access_ttl_seconds' => -60]);
        $service = new JwtService();

        $this->expectException(\Exception::class);

        $service->decodeAccessToken($service->issueAccessToken($this->user())['token']);
    }

    public function test_a_token_for_another_audience_is_rejected()
    {
        $service = new JwtService();
        $token = $service->issueAccessToken($this->user())['token'];
        config(['jwt.audience' => 'https://other-app.example']);

        $this->expectException(\UnexpectedValueException::class);

        $service->decodeAccessToken($token);
    }

    public function test_a_denied_token_is_rejected()
    {
        $service = new JwtService();
        $token = $service->issueAccessToken($this->user())['token'];
        $service->denyAccessToken($service->decodeAccessToken($token));

        $this->expectException(\UnexpectedValueException::class);

        $service->decodeAccessToken($token);
    }
}
```

#### `tests/Feature/AuthTest.php`

```php
<?php

namespace Tests\Feature;

use App\Jobs\PruneRefreshTokensJob;
use App\Models\RefreshToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-password';

    private function register(string $email = 'new@example.com'): TestResponse
    {
        return $this->postJson('/api/auth/register', [
            'name' => 'New User',
            'email' => $email,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ]);
    }

    private function login(): TestResponse
    {
        return $this->postJson('/api/auth/login', ['email' => 'user@example.com', 'password' => self::PASSWORD]);
    }

    private function refreshCookieOf(TestResponse $response): string
    {
        return $response->getCookie(config('jwt.refresh_cookie.name'), false)->getValue();
    }

    private function refreshWith(string $cookie): TestResponse
    {
        // withCredentials: JSON test requests leave cookies out otherwise
        return $this->withCredentials()->withUnencryptedCookie(config('jwt.refresh_cookie.name'), $cookie)->postJson('/api/auth/refresh');
    }

    public function test_register_returns_a_session_and_never_the_password()
    {
        $response = $this->register();

        $response->assertOk()
            ->assertJsonPath('data.user.email', 'new@example.com')
            ->assertJsonPath('data.expires_in', config('jwt.access_ttl_seconds'))
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.refresh_token');

        $cookie = $response->getCookie(config('jwt.refresh_cookie.name'), false);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('/api/auth', $cookie->getPath());
        $this->assertSame('strict', $cookie->getSameSite());
    }

    public function test_register_validates_and_rejects_a_taken_email_in_any_case()
    {
        $this->postJson('/api/auth/register', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'password']);

        $this->register('taken@example.com')->assertOk();
        $this->register('TAKEN@example.com')->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_login_with_wrong_credentials_gives_the_same_answer_for_any_email()
    {
        $this->createUser();

        $wrongPassword = $this->postJson('/api/auth/login', ['email' => 'user@example.com', 'password' => 'wrong-password']);
        $unknownEmail = $this->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password']);

        $wrongPassword->assertStatus(401);
        $unknownEmail->assertStatus(401);
        $this->assertSame($wrongPassword->json('error'), $unknownEmail->json('error'));
    }

    public function test_protected_routes_need_a_valid_token()
    {
        $this->getJson('/api/auth/me')->assertStatus(401)->assertJsonStructure(['error']);
        $this->getJson('/api/auth/me', ['Authorization' => 'Bearer not-a-token'])->assertStatus(401);
    }

    public function test_login_gives_access_to_me()
    {
        $this->createUser();

        $token = $this->login()->assertOk()->json('data.access_token');

        $this->getJson('/api/auth/me', ['Authorization' => 'Bearer ' . $token])
            ->assertOk()
            ->assertJsonPath('data.email', 'user@example.com');
    }

    public function test_refresh_rotates_the_token()
    {
        $this->createUser();
        $first = $this->refreshCookieOf($this->login());

        $response = $this->refreshWith($first)->assertOk()->assertJsonStructure(['data' => ['user', 'access_token', 'expires_in']]);

        $this->assertNotSame($first, $this->refreshCookieOf($response));
        $this->assertSame(2, RefreshToken::count());
    }

    public function test_refresh_without_a_cookie_or_with_a_bad_one_is_rejected()
    {
        $this->postJson('/api/auth/refresh')->assertStatus(401);
        $this->refreshWith('not-a-token')->assertStatus(401);
    }

    public function test_an_expired_refresh_token_is_rejected()
    {
        $this->createUser();
        $cookie = $this->refreshCookieOf($this->login());

        $this->travel(config('jwt.refresh_ttl_days') + 1)->days();

        $this->refreshWith($cookie)->assertStatus(401);
    }

    public function test_two_tabs_refreshing_at_once_both_keep_the_session()
    {
        $this->createUser();
        $first = $this->refreshCookieOf($this->login());

        $this->refreshWith($first)->assertOk();

        // The second tab still sent the old cookie, within the grace time: an access token, the cookie stays as it is
        $second = $this->refreshWith($first)->assertOk();
        $this->assertNull($second->getCookie(config('jwt.refresh_cookie.name'), false));
        $this->assertSame(2, RefreshToken::count());
    }

    public function test_reusing_a_rotated_token_after_the_grace_time_ends_the_session()
    {
        $this->createUser();
        $first = $this->refreshCookieOf($this->login());
        $second = $this->refreshCookieOf($this->refreshWith($first));

        $this->travel(config('jwt.refresh_reuse_grace_seconds') + 1)->seconds();

        $this->refreshWith($first)->assertStatus(401);
        // The newest token of that session stops working too: whoever holds it may be the thief
        $this->refreshWith($second)->assertStatus(401);
    }

    public function test_logout_ends_the_access_token_and_the_refresh_token()
    {
        $this->createUser();
        $login = $this->login();
        $headers = ['Authorization' => 'Bearer ' . $login->json('data.access_token')];
        $cookie = $this->refreshCookieOf($login);

        $this->withCredentials()->withUnencryptedCookie(config('jwt.refresh_cookie.name'), $cookie)
            ->postJson('/api/auth/logout', [], $headers)
            ->assertOk();

        $this->getJson('/api/auth/me', $headers)->assertStatus(401);
        $this->refreshWith($cookie)->assertStatus(401);
    }

    public function test_the_auth_endpoints_are_rate_limited()
    {
        config(['rate_limits.auth_per_minute' => 2]);

        $this->postJson('/api/auth/login', [])->assertStatus(422);
        $this->postJson('/api/auth/login', [])->assertStatus(422);
        $this->postJson('/api/auth/login', [])->assertStatus(429)->assertJsonStructure(['error']);
    }

    public function test_unexpected_errors_never_show_technical_text()
    {
        $this->createUser();
        config(['jwt.secret' => 'too-short']);

        $this->login()->assertStatus(500)
            ->assertExactJson(['error' => 'Something went wrong on our side. Please try again in a few minutes.']);
    }

    public function test_the_cleanup_job_deletes_only_tokens_that_expired_a_while_ago()
    {
        $this->createUser();
        $this->login();

        $this->travel(config('jwt.refresh_ttl_days') + config('jwt.prune_after_days') + 1)->days();
        $this->login();

        (new PruneRefreshTokensJob())->handle(app(\App\Repository\RefreshTokenRepository::class));

        $this->assertSame(1, RefreshToken::count());
    }

    public function test_the_spa_shell_answers_deep_links_but_not_unknown_api_paths()
    {
        $this->withoutVite();

        $this->get('/any/deep/link')->assertOk()->assertViewIs('welcome');
        $this->getJson('/api/nothing-here')->assertStatus(404);
    }
}
```

### Frontend

The interceptor refresh logic is in `resources/js/services/httpCommon.ts` (section 7).

#### `resources/js/services/store.ts`

```ts
import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import http from '@/services/apiEndPoints';
import { refreshSession, setAccessToken } from '@/services/httpCommon';

export const useApplicationStore = defineStore('applicationSetup', () => {
    const user = ref<any>(null);
    const isAuthenticated = computed(() => !!user.value);

    // Whether this browser was signed in (persisted): the cookie can't be read from JS, and a visitor who never signed in
    // shouldn't get a failing refresh request on every page load
    const hasSession = ref(false);

    function startSession(session: any) {
        setAccessToken(session.access_token);
        user.value = session.user;
        hasSession.value = true;
    }

    function clearSession() {
        setAccessToken(null);
        user.value = null;
        hasSession.value = false;
    }

    async function register(data: { name: string; email: string; password: string; password_confirmation: string }) {
        const response = await http.register(data);
        startSession(response.data.data);
    }

    async function login(data: { email: string; password: string }) {
        const response = await http.login(data);
        startSession(response.data.data);
    }

    async function logout() {
        try {
            await http.logout();
        } catch (error: any) {
            // Already signed out on the server: the browser still has to forget it. Other errors are shown to the user.
            if (error.response?.status !== 401) {
                throw error;
            }
        }
        clearSession();
    }

    // After a page reload the access token is gone: get a new one with the refresh token cookie. Once per page load, unless
    // it failed for a reason other than the session (offline, server error): the next navigation tries again.
    let restored = false;

    async function restoreSession() {
        if (restored || user.value || !hasSession.value) {
            return;
        }

        try {
            startSession(await refreshSession());
            restored = true;
        } catch (error: any) {
            if (error.response?.status === 401) {
                clearSession();
                restored = true;
            } else {
                console.error('Error restoring the session:', error);
            }
        }
    }

    return { user, isAuthenticated, hasSession, startSession, clearSession, register, login, logout, restoreSession };
}, {
    persist: {
        storage: localStorage,
        pick: ['hasSession'],
    },
});
```

#### `resources/js/services/router.ts`

```ts
import { createRouter, createWebHistory } from 'vue-router';
import Dashboard from '@/pages/dashboard/Dashboard.vue';
import Login from '@/pages/auth/Login.vue';
import Register from '@/pages/auth/Register.vue';
// example-module:start
import ItemForm from '@/pages/items/ItemForm.vue';
import Items from '@/pages/items/Items.vue';
// example-module:end
import { useApplicationStore } from '@/services/store';

const routes = [
    { path: '/', component: Dashboard, name: 'Dashboard' },
    { path: '/login', component: Login, name: 'Login', meta: { guest: true } },
    { path: '/register', component: Register, name: 'Register', meta: { guest: true } },
    // example-module:start
    // Filters live in the query string (?q=&status=&sort=&page=&per_page=)
    { path: '/items', component: Items, name: 'Items' },
    { path: '/items/new', component: ItemForm, name: 'ItemCreate' },
    { path: '/items/:id/edit', component: ItemForm, name: 'ItemEdit' },
    // example-module:end
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

// Every page needs a signed-in user unless it has meta.guest (and guest pages send a signed-in user home). Unknown routes
// (the SPA catch-all) also go home.
router.beforeEach(async (to) => {
    if (to.matched.length === 0) {
        return { name: 'Dashboard' };
    }

    const store = useApplicationStore();
    await store.restoreSession();

    if (to.meta.guest) {
        return store.isAuthenticated ? { name: 'Dashboard' } : true;
    }

    return store.isAuthenticated ? true : { name: 'Login', query: { redirect: to.fullPath } };
});

export default router;
```

#### `resources/js/env.d.ts`

```ts
/// <reference types="vite/client" />

import 'vue-router';

declare module 'vue-router' {
    interface RouteMeta {
        // Open without signing in; a signed-in user is sent home (router.ts)
        guest?: boolean;
    }
}
```

#### `resources/js/pages/auth/Login.vue`

```vue
<template>
    <section class="mx-auto mt-12 max-w-sm rounded border border-gray-200 bg-white p-6">
        <h1 class="mb-4 text-xl font-semibold">{{ t('auth.loginTitle') }}</h1>

        <p v-if="errorMessage" role="alert" class="mb-4 rounded bg-red-50 px-3 py-2 text-sm text-red-800">{{ errorMessage }}</p>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <label class="flex flex-col gap-1 text-sm">
                {{ t('auth.email') }}
                <input v-model="email" name="email" type="email" autocomplete="email" required class="rounded border border-gray-300 px-3 py-2" />
            </label>
            <label class="flex flex-col gap-1 text-sm">
                {{ t('auth.password') }}
                <input v-model="password" name="password" type="password" autocomplete="current-password" required class="rounded border border-gray-300 px-3 py-2" />
            </label>
            <button type="submit" class="rounded bg-gray-900 px-4 py-2 text-white hover:bg-gray-700 disabled:opacity-50" :disabled="loading">
                {{ loading ? t('common.loading') : t('auth.signIn') }}
            </button>
        </form>

        <p class="mt-4 text-sm text-gray-600">
            {{ t('auth.noAccount') }}
            <RouterLink :to="{ name: 'Register' }" class="underline">{{ t('auth.signUp') }}</RouterLink>
        </p>
    </section>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { getErrorMessage } from '@/services/apiErrors';
import { useApplicationStore } from '@/services/store';

const { t } = useI18n();
const store = useApplicationStore();
const route = useRoute();
const router = useRouter();

const email = ref('');
const password = ref('');
const loading = ref(false);
const errorMessage = ref('');

const submit = async () => {
    loading.value = true;
    errorMessage.value = '';
    try {
        await store.login({ email: email.value, password: password.value });
        // Only paths inside the app: a crafted ?redirect= must not lead to another site
        const redirect = route.query.redirect;
        router.push(typeof redirect === 'string' && /^\/(?!\/)/.test(redirect) ? redirect : { name: 'Dashboard' });
    } catch (error) {
        console.error('Error signing in:', error);
        errorMessage.value = getErrorMessage(error, t('auth.loginFailed'));
    } finally {
        loading.value = false;
    }
};
</script>
```

#### `resources/js/pages/auth/Register.vue`

```vue
<template>
    <section class="mx-auto mt-12 max-w-sm rounded border border-gray-200 bg-white p-6">
        <h1 class="mb-4 text-xl font-semibold">{{ t('auth.registerTitle') }}</h1>

        <p v-if="errorMessage" role="alert" class="mb-4 rounded bg-red-50 px-3 py-2 text-sm text-red-800">{{ errorMessage }}</p>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <label class="flex flex-col gap-1 text-sm">
                {{ t('auth.name') }}
                <input v-model="name" name="name" type="text" autocomplete="name" required class="rounded border border-gray-300 px-3 py-2" />
            </label>
            <label class="flex flex-col gap-1 text-sm">
                {{ t('auth.email') }}
                <input v-model="email" name="email" type="email" autocomplete="email" required class="rounded border border-gray-300 px-3 py-2" />
            </label>
            <label class="flex flex-col gap-1 text-sm">
                {{ t('auth.password') }}
                <input v-model="password" name="password" type="password" autocomplete="new-password" required class="rounded border border-gray-300 px-3 py-2" />
            </label>
            <label class="flex flex-col gap-1 text-sm">
                {{ t('auth.passwordConfirmation') }}
                <input v-model="passwordConfirmation" name="password_confirmation" type="password" autocomplete="new-password" required class="rounded border border-gray-300 px-3 py-2" />
            </label>
            <button type="submit" class="rounded bg-gray-900 px-4 py-2 text-white hover:bg-gray-700 disabled:opacity-50" :disabled="loading">
                {{ loading ? t('common.loading') : t('auth.signUp') }}
            </button>
        </form>

        <p class="mt-4 text-sm text-gray-600">
            {{ t('auth.haveAccount') }}
            <RouterLink :to="{ name: 'Login' }" class="underline">{{ t('auth.signIn') }}</RouterLink>
        </p>
    </section>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { getErrorMessage } from '@/services/apiErrors';
import { useApplicationStore } from '@/services/store';

const { t } = useI18n();
const store = useApplicationStore();
const router = useRouter();

const name = ref('');
const email = ref('');
const password = ref('');
const passwordConfirmation = ref('');
const loading = ref(false);
const errorMessage = ref('');

const submit = async () => {
    loading.value = true;
    errorMessage.value = '';
    try {
        await store.register({
            name: name.value,
            email: email.value,
            password: password.value,
            password_confirmation: passwordConfirmation.value,
        });
        router.push({ name: 'Dashboard' });
    } catch (error) {
        console.error('Error creating the account:', error);
        errorMessage.value = getErrorMessage(error, t('auth.registerFailed'));
    } finally {
        loading.value = false;
    }
};
</script>
```

## 7. Frontend API layer

- `httpCommon.ts`: one axios instance (`baseURL: '/api'`), adds the token, handles 401 → refresh → retry.
- `apiEndPoints.ts`: class `endPointApis`, **one method per endpoint**, exported as a singleton. Components import it as `http`.
- `apiErrors.ts`: the only way to turn a failed call into text.
- `i18n.ts` + `locales/en.json`: every text. `toast.ts`: short feedback messages, rendered by `Index.vue`.

The pattern in a page:

```ts
import http from '@/services/apiEndPoints';
import { getErrorMessage } from '@/services/apiErrors';

const load = async () => {
    loading.value = true;
    errorMessage.value = '';
    try {
        const response = await http.getItems({ page: 1 });
        items.value = response.data.data.items;
    } catch (error) {
        console.error('Error loading items:', error);
        errorMessage.value = getErrorMessage(error, t('items.loadFailed'));
    } finally {
        loading.value = false;
    }
};
```

#### `resources/js/services/httpCommon.ts`

```ts
import axios from 'axios';

const http = axios.create({
    baseURL: `/api`,
    headers: {
        'Content-type': 'application/json',
        Accept: 'application/json',
    },
});

// The access token lives in memory only (never in localStorage, where any script could read it). A page reload gets a new
// one with the httpOnly refresh token cookie (store.restoreSession()).
let accessToken: string | null = null;
let onSessionExpired: () => void = () => {};

export function setAccessToken(token: string | null) {
    accessToken = token;
}

export function setSessionExpiredHandler(handler: () => void) {
    onSessionExpired = handler;
}

// One refresh at a time: requests that fail together all wait for the same one. Refresh tokens rotate, so a second
// parallel refresh would send an already used token.
let refreshRequest: Promise<any> | null = null;

export function refreshSession(): Promise<any> {
    if (!refreshRequest) {
        // The bare axios: a failed refresh must not run into the 401 handling below
        refreshRequest = axios
            .post('/api/auth/refresh', null, { headers: { Accept: 'application/json' } })
            .then((response) => {
                const session = response.data.data;
                setAccessToken(session.access_token);
                return session;
            })
            .finally(() => {
                refreshRequest = null;
            });
    }

    return refreshRequest;
}

http.interceptors.request.use((config) => {
    if (accessToken) {
        config.headers.Authorization = `Bearer ${accessToken}`;
    }

    return config;
});

// These answer 401 for their own reasons (wrong password): never a reason to refresh
const NO_REFRESH_URLS = ['/auth/login', '/auth/register'];

http.interceptors.response.use(
    (response) => response,
    async (error) => {
        const config: any = error.config;

        if (error.response?.status !== 401 || !config || config.retriedAfterRefresh || NO_REFRESH_URLS.includes(config.url)) {
            return Promise.reject(error);
        }

        // Access token expired: refresh once and send the request again
        try {
            await refreshSession();
        } catch (refreshError: any) {
            // Only a rejected refresh token ends the session; being offline or a server error doesn't
            if (refreshError.response?.status === 401) {
                setAccessToken(null);
                onSessionExpired();
            }
            return Promise.reject(error);
        }

        config.retriedAfterRefresh = true;
        return http(config);
    },
);

export default http;
```

#### `resources/js/services/apiEndPoints.ts`

```ts
import http from './httpCommon';

class endPointApis {
    register(data: { name: string; email: string; password: string; password_confirmation: string }) {
        return http.post('/auth/register', data);
    }

    login(data: { email: string; password: string }) {
        return http.post('/auth/login', data);
    }

    logout() {
        return http.post('/auth/logout');
    }

    getCurrentUser() {
        return http.get('/auth/me');
    }

    // example-module:start
    // {items, pagination {page, per_page, total, last_page}}
    getItems(params?: { q?: string; status?: string; sort?: string; page?: number; per_page?: number }) {
        return http.get('/items/list', { params });
    }

    getItem(id: number | string) {
        return http.get('/items/get', { params: { id } });
    }

    createItem(data: { name: string; description?: string | null; status?: string }) {
        return http.post('/items/create', data);
    }

    updateItem(data: { id: number | string; name: string; description?: string | null; status?: string }) {
        return http.post('/items/update', data);
    }

    deleteItem(id: number | string) {
        return http.post('/items/delete', { id });
    }
    // example-module:end
}

export default new endPointApis();
```

#### `resources/js/services/apiErrors.ts`

```ts
import { t } from '@/i18n';
import { showErrorMessage } from '@/services/toast';

// Turns a failed API call into a message a user can understand. Every component shows errors through this
// (inline banners use getErrorMessage, calls without an error area use showErrorToast), never raw response data.
//
//  - no response (offline, timeout, server unreachable) -> connection message
//  - 401 / 419                                          -> the backend's own text (e.g. wrong password), else session message
//  - 429                                                -> the backend's own text (it says when to retry), else a generic one
//  - 5xx                                                -> generic "our side" message (never the server's text)
//  - 422 validation errors                              -> the first field message
//  - other 4xx                                          -> the backend's `error` text (written for users), else the fallback

export function getErrorMessage(error: any, fallback?: string): string {
    fallback = fallback ?? t('errors.generic');
    const response = error?.response;

    if (!response) {
        return error?.code === 'ERR_CANCELED' ? fallback : t('errors.offline');
    }

    const status: number = response.status;
    const data = response.data ?? {};

    if (status === 401 || status === 419) {
        return textOrNull(data.error) ?? t('errors.session');
    }

    if (status >= 500) {
        return t('errors.server');
    }

    if (status === 429) {
        return textOrNull(data.error) ?? t('errors.tooMany');
    }

    return textOrNull(data.error) ?? firstValidationError(data) ?? fallback;
}

/**
 * The validation message of one field (Laravel 422 `errors`), e.g. fieldError(error, 'name')
 */
export function fieldError(error: any, field: string): string | null {
    return textOrNull(error?.response?.data?.errors?.[field]?.[0]);
}

/**
 * For calls without their own error area
 */
export function showErrorToast(error: any, fallback?: string) {
    showErrorMessage(getErrorMessage(error, fallback));
}

function firstValidationError(data: any): string | null {
    const errors = data?.errors;

    if (!errors || typeof errors !== 'object') {
        return null;
    }

    for (const messages of Object.values(errors)) {
        const text = Array.isArray(messages) ? textOrNull(messages[0]) : textOrNull(messages);
        if (text) {
            return text;
        }
    }

    return null;
}

function textOrNull(value: any): string | null {
    return typeof value === 'string' && value.trim() !== '' ? value : null;
}
```

#### `resources/js/services/toast.ts`

```ts
import { ref } from 'vue';

// Short feedback messages, shown by Index.vue
export const toasts = ref<{ id: number; message: string; isError: boolean }[]>([]);

let lastId = 0;

function show(message: string, isError: boolean, duration: number) {
    const id = ++lastId;
    toasts.value.push({ id, message, isError });
    setTimeout(() => {
        toasts.value = toasts.value.filter((toast) => toast.id !== id);
    }, duration);
}

export function showSuccessMessage(message: string) {
    show(message, false, 4000);
}

export function showErrorMessage(message: string) {
    show(message, true, 6000);
}
```

#### `resources/js/i18n.ts`

```ts
import { createI18n } from 'vue-i18n';
import en from '@/locales/en.json';

// App texts. en.json holds every text the app shows; other files in locales/ are its translations, with the same keys.
// A file that is empty or misses a key falls back to English. Texts that come from the backend (error messages) are not
// in here. To add a language: add its file, its code in SUPPORTED_LOCALES and its plural forms in PLURAL_CATEGORIES.
export const SUPPORTED_LOCALES = ['en'];

// Plural forms per language, in the order they are written in a message ("form 1 | form 2 | ..."), as CLDR plural
// categories that whole numbers can hit (Intl.PluralRules). E.g. Polish ['one', 'few', 'many'], Japanese ['other'].
export const PLURAL_CATEGORIES: Record<string, string[]> = {
    en: ['one', 'other'],
};

// The form to use for a count: the position of its plural category in PLURAL_CATEGORIES (a category a language only uses
// for fractions or very large numbers falls back to the last form)
function pluralRule(locale: string) {
    const categories = PLURAL_CATEGORIES[locale];
    const rules = new Intl.PluralRules(locale);

    return (choice: number, choicesLength: number) => {
        let index = categories.indexOf(rules.select(Math.abs(choice)));
        if (index === -1) {
            index = categories.length - 1;
        }
        return Math.min(index, choicesLength - 1);
    };
}

// Loaded on demand (raw text, so an empty file is allowed)
const localeFiles = import.meta.glob(['./locales/*.json', '!./locales/en.json'], { query: '?raw', import: 'default' });

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    messages: { en } as Record<string, any>,
    // Missing texts fall back to English on purpose: no console warning per text
    missingWarn: false,
    fallbackWarn: false,
    pluralRules: Object.fromEntries(Object.keys(PLURAL_CATEGORIES).map((locale) => [locale, pluralRule(locale)])),
});

export default i18n;

// Shorthand for .ts modules (components use useI18n())
export const t = (...args: any[]): string => (i18n.global.t as any)(...args);

/**
 * The supported locale for a browser language ("de", "pt-BR", "en-US"...), English when there is none
 */
export function resolveLocale(locale?: string | null): string {
    if (!locale) {
        return 'en';
    }

    const wanted = locale.replace('_', '-').toLowerCase();
    const exact = SUPPORTED_LOCALES.find((code) => code.toLowerCase() === wanted);
    if (exact) {
        return exact;
    }

    const language = wanted.split('-')[0];
    return SUPPORTED_LOCALES.find((code) => code.toLowerCase() === language) ?? 'en';
}

/**
 * Switches to the user's language
 */
export async function setupLocale(wanted?: string | null) {
    let locale = resolveLocale(wanted);

    if (locale !== 'en') {
        try {
            const raw = (await localeFiles[`./locales/${locale}.json`]?.()) as string | undefined;
            if (raw && raw.trim() !== '') {
                i18n.global.setLocaleMessage(locale, JSON.parse(raw));
            } else {
                // Empty file: the page is English, so say so (screen readers, browser translation)
                locale = 'en';
            }
        } catch (error) {
            console.error('Could not load the language ' + locale + ':', error);
            locale = 'en';
        }
    }

    i18n.global.locale.value = locale;
    document.documentElement.lang = locale;
}
```

#### `resources/js/locales/en.json`

```json
{
    "app": {
        "name": "{{APP_NAME}}"
    },
    "common": {
        "loading": "Loading…",
        "tryAgain": "Try again",
        "cancel": "Cancel",
        "save": "Save",
        "delete": "Delete",
        "edit": "Edit",
        "previous": "Previous",
        "next": "Next",
        "pageOf": "Page {page} of {last}",
        "perPageLabel": "Items per page",
        "perPage": "per page"
    },
    "nav": {
        "items": "Items",
        "logout": "Sign out"
    },
    "errors": {
        "generic": "Something went wrong. Please try again.",
        "offline": "We couldn't reach the app. Check your internet connection and try again.",
        "session": "Your session has expired. Please sign in again.",
        "tooMany": "Too many attempts. Please wait a moment and try again.",
        "server": "Something went wrong on our side. Please try again in a few minutes."
    },
    "auth": {
        "loginTitle": "Sign in",
        "registerTitle": "Create your account",
        "name": "Name",
        "email": "Email",
        "password": "Password",
        "passwordConfirmation": "Confirm password",
        "signIn": "Sign in",
        "signUp": "Create account",
        "noAccount": "No account yet?",
        "haveAccount": "Already have an account?",
        "loginFailed": "Could not sign you in. Please try again.",
        "registerFailed": "Could not create your account. Please try again.",
        "logoutFailed": "Could not sign you out. Please try again."
    },
    "dashboard": {
        "title": "Dashboard",
        "welcome": "Welcome, {name}."
    },
    "items": {
        "title": "Items",
        "create": "New item",
        "editTitle": "Edit item",
        "createTitle": "New item",
        "name": "Name",
        "description": "Description",
        "status": "Status",
        "created": "Created",
        "actions": "Actions",
        "statusActive": "Active",
        "statusArchived": "Archived",
        "allStatuses": "All statuses",
        "search": "Search by name",
        "sort": "Sort",
        "sortNewest": "Newest first",
        "sortOldest": "Oldest first",
        "sortName": "By name",
        "count": "{count} item | {count} items",
        "empty": "No items yet. Create your first one.",
        "emptyFiltered": "No items match your search.",
        "confirmDelete": "Delete?",
        "saved": "Item saved",
        "deleted": "Item deleted",
        "loadFailed": "Could not load your items. Please try again.",
        "loadOneFailed": "Could not load this item. Please try again.",
        "saveFailed": "Could not save this item. Please try again.",
        "deleteFailed": "Could not delete this item. Please try again."
    }
}
```

#### `resources/js/app.ts`

```ts
import '../css/app.css';
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import piniaPluginPersistedstate from 'pinia-plugin-persistedstate';
import Index from './Index.vue';
import i18n, { setupLocale } from './i18n';
import router from './services/router';
import { setSessionExpiredHandler } from './services/httpCommon';
import { useApplicationStore } from './services/store';

const pinia = createPinia();
pinia.use(piniaPluginPersistedstate);

const app = createApp(Index);

app.use(pinia);
app.use(router);
app.use(i18n);

// The refresh token stopped working while a page was open: back to the login page, then back to this page
setSessionExpiredHandler(() => {
    useApplicationStore().clearSession();
    const current = router.currentRoute.value;
    if (!current.meta.guest) {
        router.push({ name: 'Login', query: { redirect: current.fullPath } });
    }
});

// The user's language is loaded before the first render, so no page shows English first
setupLocale(navigator.language).finally(() => {
    app.mount('#app');
});
```

#### `resources/js/Index.vue`

```vue
<template>
    <div class="min-h-screen bg-gray-50 text-gray-900">
        <header v-if="store.isAuthenticated" class="border-b border-gray-200 bg-white">
            <nav class="mx-auto flex max-w-5xl flex-wrap items-center gap-4 px-4 py-3">
                <RouterLink :to="{ name: 'Dashboard' }" class="font-semibold">{{ t('app.name') }}</RouterLink>
                <!-- example-module:start -->
                <RouterLink :to="{ name: 'Items' }" class="text-sm text-gray-600 hover:text-gray-900">{{ t('nav.items') }}</RouterLink>
                <!-- example-module:end -->
                <span class="ml-auto text-sm text-gray-600">{{ store.user?.name }}</span>
                <button
                    type="button"
                    class="rounded border border-gray-300 bg-white px-3 py-1 text-sm hover:bg-gray-100 disabled:opacity-50"
                    :disabled="loggingOut"
                    @click="logout"
                >
                    {{ t('nav.logout') }}
                </button>
            </nav>
        </header>

        <main class="mx-auto max-w-5xl px-4 py-6">
            <RouterView />
        </main>

        <div class="fixed right-4 bottom-4 flex flex-col gap-2" aria-live="polite">
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="rounded px-4 py-2 text-sm text-white shadow"
                :class="toast.isError ? 'bg-red-700' : 'bg-gray-900'"
            >
                {{ toast.message }}
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { showErrorToast } from '@/services/apiErrors';
import { useApplicationStore } from '@/services/store';
import { toasts } from '@/services/toast';

const { t } = useI18n();
const store = useApplicationStore();
const router = useRouter();
const loggingOut = ref(false);

const logout = async () => {
    loggingOut.value = true;
    try {
        await store.logout();
        router.push({ name: 'Login' });
    } catch (error) {
        console.error('Error signing out:', error);
        showErrorToast(error, t('auth.logoutFailed'));
    } finally {
        loggingOut.value = false;
    }
};
</script>
```

#### `resources/js/pages/dashboard/Dashboard.vue`

```vue
<template>
    <h1 class="mb-4 text-2xl font-semibold">{{ t('dashboard.title') }}</h1>
    <p>{{ t('dashboard.welcome', { name: store.user?.name }) }}</p>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { useApplicationStore } from '@/services/store';

const { t } = useI18n();
const store = useApplicationStore();
</script>
```

#### `resources/css/app.css`

```css
@import 'tailwindcss';
```

## 8. Reference module

> **THE TEMPLATE TO COPY FOR EVERY NEW FEATURE.** `Item` has no meaning of its own. It is one complete vertical slice showing where everything goes: a per-user record with create, read, update, delete, a filtered and sorted list, pagination and tests. `CLAUDE.md` section 8 has the step-by-step recipe.

It has no repository interface and no API Resource, on purpose: see section 13.

Endpoints: `GET /api/items/list`, `GET /api/items/get?id=`, `POST /api/items/create`, `POST /api/items/update`, `POST /api/items/delete`. Its routes and API methods are in the files of sections 5 and 7, between `example-module` markers.

#### `database/migrations/2026_01_01_000003_create_items_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
```

#### `app/Models/Item.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Item extends Model
{
    const STATUS_ACTIVE = 'active';
    const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

#### `app/Repository/ItemRepository.php`

```php
<?php

namespace App\Repository;

use App\Models\Item;

class ItemRepository
{

    /**
     * One page of the user's items for the filters: {items, total}. Every query is limited to the owner.
     */
    public function getItemsForUser($userId, $filters, $page, $perPage)
    {
        $query = Item::where('user_id', $userId);

        if (!empty($filters['q'])) {
            $query->where('name', 'like', '%' . addcslashes($filters['q'], '\\%_') . '%');
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $total = $query->count();

        match ($filters['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('id'),
            'name' => $query->orderBy('name')->orderBy('id'),
            default => $query->orderByDesc('id'),
        };

        return [
            'items' => $query->forPage($page, $perPage)->get(),
            'total' => $total,
        ];
    }

    public function getItemForUser($id, $userId)
    {
        return Item::where('id', $id)->where('user_id', $userId)->first();
    }

    public function createItem($data)
    {
        $item = new Item();
        $item->user_id = $data['user_id'];
        $item->name = $data['name'];
        $item->description = $data['description'] ?? null;
        $item->status = $data['status'] ?? Item::STATUS_ACTIVE;
        $item->save();

        return $item;
    }

    public function updateItem($item, $data)
    {
        $item->name = $data['name'];
        $item->description = $data['description'] ?? null;
        $item->status = $data['status'] ?? $item->status;
        $item->save();

        return $item;
    }

    public function deleteItem($item)
    {
        $item->delete();
    }

}
```

#### `app/Services/Item/ItemService.php`

```php
<?php

namespace App\Services\Item;

use App\Repository\ItemRepository;
use Illuminate\Support\Facades\Log;

class ItemService
{
    public function __construct(private readonly ItemRepository $itemRepository)
    {
    }

    /**
     * {items, pagination {page, per_page, total, last_page}} for the filters in the query string
     */
    public function getItems($user, $filters)
    {
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? config('items.per_page'));

        $result = $this->itemRepository->getItemsForUser($user->id, $filters, $page, $perPage);

        return [
            'items' => $result['items'],
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $result['total'],
                'last_page' => max(1, (int) ceil($result['total'] / $perPage)),
            ],
        ];
    }

    public function getItem($user, $id)
    {
        $item = $this->itemRepository->getItemForUser($id, $user->id);

        if (!$item) {
            throw new \Exception('We couldn\'t find this item. It may have been deleted.', 404);
        }

        return $item;
    }

    public function createItem($user, $data)
    {
        $item = $this->itemRepository->createItem([
            'user_id' => $user->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => $data['status'] ?? null,
        ]);

        Log::info('Item created', ['user_id' => $user->id, 'item_id' => $item->id]);

        return $item;
    }

    public function updateItem($user, $data)
    {
        return $this->itemRepository->updateItem($this->getItem($user, $data['id']), $data);
    }

    public function deleteItem($user, $id)
    {
        $this->itemRepository->deleteItem($this->getItem($user, $id));

        Log::info('Item deleted', ['user_id' => $user->id, 'item_id' => $id]);
    }
}
```

#### `app/Http/Requests/ItemRequest.php`

```php
<?php

namespace App\Http\Requests;

class ItemRequest extends BaseFormRequest
{
    public function rules()
    {
        return [
            'id' => 'nullable|integer', // For updates
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'status' => 'nullable|in:active,archived',
        ];
    }
}
```

#### `app/Http/Requests/ItemIdRequest.php`

```php
<?php

namespace App\Http\Requests;

class ItemIdRequest extends BaseFormRequest
{
    public function rules()
    {
        return [
            'id' => 'required|integer',
        ];
    }
}
```

#### `app/Http/Requests/ItemListRequest.php`

```php
<?php

namespace App\Http\Requests;

/**
 * Filters of the items list. All optional; the defaults are the page's defaults.
 */
class ItemListRequest extends BaseFormRequest
{
    public function rules()
    {
        return [
            'q' => 'nullable|string|max:100',
            'status' => 'nullable|in:active,archived',
            'sort' => 'nullable|in:newest,oldest,name',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|in:' . implode(',', config('items.per_page_options')),
        ];
    }
}
```

#### `app/Http/Controllers/ItemController.php`

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\ItemIdRequest;
use App\Http\Requests\ItemListRequest;
use App\Http\Requests\ItemRequest;
use App\Services\Item\ItemService;

class ItemController extends Controller
{
    public function __construct(private readonly ItemService $itemService)
    {
    }

    public function getItems(ItemListRequest $request)
    {
        try {
            $result = $this->itemService->getItems($request->getAuthUser(), $request->all());
        } catch (\Exception $e) {
            return $this->exceptionResponse($e);
        }

        return response()->json(['data' => $result]);
    }

    public function getItem(ItemIdRequest $request)
    {
        try {
            $item = $this->itemService->getItem($request->getAuthUser(), $request->get('id'));
        } catch (\Exception $e) {
            return $this->exceptionResponse($e);
        }

        return response()->json(['data' => $item]);
    }

    public function createItem(ItemRequest $request)
    {
        try {
            $item = $this->itemService->createItem($request->getAuthUser(), $request->all());
        } catch (\Exception $e) {
            return $this->exceptionResponse($e);
        }

        return response()->json(['data' => $item]);
    }

    public function updateItem(ItemRequest $request)
    {
        try {
            $item = $this->itemService->updateItem($request->getAuthUser(), $request->all());
        } catch (\Exception $e) {
            return $this->exceptionResponse($e);
        }

        return response()->json(['data' => $item]);
    }

    public function deleteItem(ItemIdRequest $request)
    {
        try {
            $this->itemService->deleteItem($request->getAuthUser(), $request->get('id'));
        } catch (\Exception $e) {
            return $this->exceptionResponse($e);
        }

        return response()->json(['data' => true]);
    }
}
```

#### `config/items.php`

```php
<?php

return [

    // Page sizes of the list (ItemListRequest validates against these)
    'per_page' => 15,
    'per_page_options' => [5, 10, 15, 25, 50],

];
```

#### `tests/Feature/ItemTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->createUser();
    }

    private function createItem(User $user, array $attributes = []): Item
    {
        return Item::create(array_merge(['user_id' => $user->id, 'name' => 'Item', 'status' => Item::STATUS_ACTIVE], $attributes));
    }

    public function test_routes_require_a_token()
    {
        $this->getJson('/api/items/list')->assertStatus(401);
        $this->postJson('/api/items/create', ['name' => 'Item'])->assertStatus(401);
    }

    public function test_create_get_update_delete()
    {
        $headers = $this->authHeaders($this->user);

        $id = $this->postJson('/api/items/create', ['name' => 'First', 'description' => 'Text'], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', Item::STATUS_ACTIVE)
            ->json('data.id');

        $this->getJson('/api/items/get?id=' . $id, $headers)->assertOk()->assertJsonPath('data.name', 'First');

        $this->postJson('/api/items/update', ['id' => $id, 'name' => 'Renamed', 'status' => Item::STATUS_ARCHIVED], $headers)
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.status', Item::STATUS_ARCHIVED);

        $this->postJson('/api/items/delete', ['id' => $id], $headers)->assertOk();
        $this->assertSame(0, Item::count());
    }

    public function test_create_validates_its_input()
    {
        $this->postJson('/api/items/create', ['name' => '', 'status' => 'other'], $this->authHeaders($this->user))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'status']);
    }

    public function test_another_users_item_is_not_found_and_stays_untouched()
    {
        $item = $this->createItem($this->createUser(['email' => 'other@example.com']));
        $headers = $this->authHeaders($this->user);

        $this->getJson('/api/items/get?id=' . $item->id, $headers)->assertStatus(404)->assertJsonStructure(['error']);
        $this->postJson('/api/items/update', ['id' => $item->id, 'name' => 'Stolen'], $headers)->assertStatus(404);
        $this->postJson('/api/items/delete', ['id' => $item->id], $headers)->assertStatus(404);

        $this->assertSame('Item', $item->fresh()->name);
    }

    public function test_list_only_has_own_items_with_pagination()
    {
        foreach (range(1, 6) as $number) {
            $this->createItem($this->user, ['name' => 'Item ' . $number]);
        }
        $this->createItem($this->createUser(['email' => 'other@example.com']), ['name' => 'Foreign']);

        $this->getJson('/api/items/list?per_page=5&page=2', $this->authHeaders($this->user))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.name', 'Item 1')
            ->assertJsonPath('data.pagination', ['page' => 2, 'per_page' => 5, 'total' => 6, 'last_page' => 2]);
    }

    public function test_list_filters_sorts_and_rejects_unknown_page_sizes()
    {
        $this->createItem($this->user, ['name' => 'Banana']);
        $this->createItem($this->user, ['name' => 'Apple']);
        $this->createItem($this->user, ['name' => 'Cherry', 'status' => Item::STATUS_ARCHIVED]);
        $headers = $this->authHeaders($this->user);

        $this->getJson('/api/items/list?sort=name&status=active', $headers)
            ->assertJsonPath('data.items.0.name', 'Apple')
            ->assertJsonCount(2, 'data.items');
        $this->getJson('/api/items/list?q=err', $headers)->assertJsonPath('data.items.0.name', 'Cherry');
        // A literal % is searched for, not used as a wildcard
        $this->getJson('/api/items/list?q=' . urlencode('%'), $headers)->assertJsonCount(0, 'data.items');
        $this->getJson('/api/items/list?per_page=7', $headers)->assertStatus(422);
    }
}
```

#### `resources/js/pages/items/Items.vue`

```vue
<template>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-2xl font-semibold">{{ t('items.title') }}</h1>
        <RouterLink :to="{ name: 'ItemCreate' }" class="rounded bg-gray-900 px-4 py-2 text-sm text-white hover:bg-gray-700">{{ t('items.create') }}</RouterLink>
    </div>

    <div v-if="pageLoading" class="flex justify-center p-12" role="status">{{ t('common.loading') }}</div>

    <!-- A failed load never falls through to the empty state, which would mean "you have no items" -->
    <div v-else-if="errorMessage" role="alert" class="rounded bg-red-50 p-4 text-sm text-red-800">
        <p class="mb-2">{{ errorMessage }}</p>
        <button type="button" class="rounded border border-red-300 bg-white px-3 py-1" @click="loadItems">{{ t('common.tryAgain') }}</button>
    </div>

    <template v-else>
        <div class="mb-4 flex flex-wrap gap-2">
            <input
                name="q"
                :value="filters.q"
                :placeholder="t('items.search')"
                :aria-label="t('items.search')"
                class="rounded border border-gray-300 bg-white px-3 py-2 text-sm"
                @input="onSearch(($event.target as HTMLInputElement).value)"
            />
            <select v-model="filters.status" name="status" :aria-label="t('items.status')" class="rounded border border-gray-300 bg-white px-3 py-2 text-sm" @change="applyFilters">
                <option value="">{{ t('items.allStatuses') }}</option>
                <option value="active">{{ t('items.statusActive') }}</option>
                <option value="archived">{{ t('items.statusArchived') }}</option>
            </select>
            <select v-model="filters.sort" name="sort" :aria-label="t('items.sort')" class="rounded border border-gray-300 bg-white px-3 py-2 text-sm" @change="applyFilters">
                <option value="newest">{{ t('items.sortNewest') }}</option>
                <option value="oldest">{{ t('items.sortOldest') }}</option>
                <option value="name">{{ t('items.sortName') }}</option>
            </select>
        </div>

        <p v-if="items.length === 0" class="rounded border border-gray-200 bg-white p-8 text-center text-gray-600">
            {{ hasActiveFilters ? t('items.emptyFiltered') : t('items.empty') }}
        </p>

        <template v-else>
            <p class="mb-2 text-sm text-gray-600">{{ t('items.count', { count: pagination.total }, pagination.total) }}</p>

            <div class="overflow-x-auto rounded border border-gray-200 bg-white">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-gray-200 text-gray-600">
                        <tr>
                            <th class="px-4 py-2 font-medium">{{ t('items.name') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('items.status') }}</th>
                            <th class="px-4 py-2 font-medium">{{ t('items.created') }}</th>
                            <th class="px-4 py-2 text-right font-medium">{{ t('items.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in items" :key="item.id" class="border-b border-gray-100 last:border-0">
                            <td class="px-4 py-2">{{ item.name }}</td>
                            <td class="px-4 py-2">{{ item.status === 'archived' ? t('items.statusArchived') : t('items.statusActive') }}</td>
                            <td class="px-4 py-2">{{ new Date(item.created_at).toLocaleDateString() }}</td>
                            <td class="px-4 py-2 text-right whitespace-nowrap">
                                <template v-if="confirmingId === item.id">
                                    <span class="mr-2">{{ t('items.confirmDelete') }}</span>
                                    <button type="button" class="mr-2 text-red-700 underline disabled:opacity-50" :disabled="deleting" @click="deleteItem(item.id)">{{ t('common.delete') }}</button>
                                    <button type="button" class="underline" @click="confirmingId = null">{{ t('common.cancel') }}</button>
                                </template>
                                <template v-else>
                                    <RouterLink :to="{ name: 'ItemEdit', params: { id: item.id } }" class="mr-2 underline">{{ t('common.edit') }}</RouterLink>
                                    <button type="button" class="text-red-700 underline" @click="confirmingId = item.id">{{ t('common.delete') }}</button>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination: the same row under every paged list -->
            <div class="mt-3 flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-2">
                    <select name="per_page" :value="pagination.per_page" :aria-label="t('common.perPageLabel')" :disabled="loading" class="rounded border border-gray-300 bg-white px-2 py-1 text-sm" @change="changePerPage(($event.target as HTMLSelectElement).value)">
                        <option v-for="size in PER_PAGE_OPTIONS" :key="size" :value="size">{{ size }}</option>
                    </select>
                    <span class="text-sm text-gray-600">{{ t('common.perPage') }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" class="rounded border border-gray-300 bg-white px-3 py-1 text-sm disabled:opacity-50" :disabled="loading || pagination.page <= 1" @click="goToPage(pagination.page - 1)">{{ t('common.previous') }}</button>
                    <span class="text-sm text-gray-600">{{ t('common.pageOf', { page: pagination.page, last: pagination.last_page }) }}</span>
                    <button type="button" class="rounded border border-gray-300 bg-white px-3 py-1 text-sm disabled:opacity-50" :disabled="loading || pagination.page >= pagination.last_page" @click="goToPage(pagination.page + 1)">{{ t('common.next') }}</button>
                </div>
            </div>
        </template>
    </template>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import http from '@/services/apiEndPoints';
import { getErrorMessage, showErrorToast } from '@/services/apiErrors';
import { showSuccessMessage } from '@/services/toast';

const PER_PAGE_OPTIONS = [5, 10, 15, 25, 50];
const DEFAULT_PER_PAGE = 15;
const DEFAULT_SORT = 'newest';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const items = ref<any[]>([]);
const pagination = ref({ page: 1, per_page: DEFAULT_PER_PAGE, total: 0, last_page: 1 });
const pageLoading = ref(true);
const loading = ref(false);
const deleting = ref(false);
const errorMessage = ref('');
const confirmingId = ref<number | null>(null);

// The URL is the source of the filters, so a refresh or a shared link shows the same list
const filters = reactive({ q: '', status: '', sort: DEFAULT_SORT });
const hasActiveFilters = computed(() => !!filters.q || !!filters.status);

function readQuery() {
    filters.q = String(route.query.q ?? '');
    filters.status = String(route.query.status ?? '');
    filters.sort = String(route.query.sort ?? DEFAULT_SORT);
}

// Defaults are left out of the URL
function writeQuery(page: number, perPage: number) {
    const query: Record<string, string | number> = {};
    if (filters.q) query.q = filters.q;
    if (filters.status) query.status = filters.status;
    if (filters.sort !== DEFAULT_SORT) query.sort = filters.sort;
    if (page > 1) query.page = page;
    if (perPage !== DEFAULT_PER_PAGE) query.per_page = perPage;
    router.replace({ query });
}

const loadItems = async () => {
    loading.value = true;
    errorMessage.value = '';
    try {
        const response = await http.getItems({
            q: filters.q || undefined,
            status: filters.status || undefined,
            sort: filters.sort,
            page: Number(route.query.page ?? 1),
            per_page: Number(route.query.per_page ?? DEFAULT_PER_PAGE),
        });
        items.value = response.data.data.items;
        pagination.value = response.data.data.pagination;
    } catch (error) {
        console.error('Error loading items:', error);
        errorMessage.value = getErrorMessage(error, t('items.loadFailed'));
    } finally {
        loading.value = false;
    }
};

function applyFilters() {
    writeQuery(1, pagination.value.per_page);
}

let searchTimer: ReturnType<typeof setTimeout> | undefined;

function onSearch(value: string) {
    filters.q = value;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 300);
}

function goToPage(page: number) {
    writeQuery(page, pagination.value.per_page);
}

// A new page size goes back to page 1
function changePerPage(value: string) {
    writeQuery(1, Number(value));
}

const deleteItem = async (id: number) => {
    deleting.value = true;
    try {
        await http.deleteItem(id);
        confirmingId.value = null;
        showSuccessMessage(t('items.deleted'));
        // The last item of the last page: the page before it, which reloads through the query watcher
        if (items.value.length === 1 && pagination.value.page > 1) {
            goToPage(pagination.value.page - 1);
        } else {
            await loadItems();
        }
    } catch (error) {
        console.error('Error deleting item:', error);
        showErrorToast(error, t('items.deleteFailed'));
    } finally {
        deleting.value = false;
    }
};

// Later reloads (filters, paging) never bring the page spinner back
watch(() => route.query, () => {
    if (route.name === 'Items') {
        readQuery();
        loadItems();
    }
});

onMounted(async () => {
    readQuery();
    await loadItems();
    pageLoading.value = false;
});
</script>
```

#### `resources/js/pages/items/ItemForm.vue`

```vue
<template>
    <h1 class="mb-4 text-2xl font-semibold">{{ isEdit ? t('items.editTitle') : t('items.createTitle') }}</h1>

    <div v-if="pageLoading" class="flex justify-center p-12" role="status">{{ t('common.loading') }}</div>

    <div v-else-if="loadError" role="alert" class="rounded bg-red-50 p-4 text-sm text-red-800">
        <p class="mb-2">{{ loadError }}</p>
        <button type="button" class="rounded border border-red-300 bg-white px-3 py-1" @click="loadItem">{{ t('common.tryAgain') }}</button>
    </div>

    <form v-else class="flex max-w-lg flex-col gap-4 rounded border border-gray-200 bg-white p-6" @submit.prevent="save">
        <p v-if="errorMessage" role="alert" class="rounded bg-red-50 px-3 py-2 text-sm text-red-800">{{ errorMessage }}</p>

        <label class="flex flex-col gap-1 text-sm">
            {{ t('items.name') }}
            <input v-model="name" name="name" type="text" required class="rounded border border-gray-300 px-3 py-2" />
            <span v-if="errors.name" class="text-red-700">{{ errors.name }}</span>
        </label>
        <label class="flex flex-col gap-1 text-sm">
            {{ t('items.description') }}
            <textarea v-model="description" name="description" rows="4" class="rounded border border-gray-300 px-3 py-2"></textarea>
            <span v-if="errors.description" class="text-red-700">{{ errors.description }}</span>
        </label>
        <label class="flex flex-col gap-1 text-sm">
            {{ t('items.status') }}
            <select v-model="status" name="status" class="rounded border border-gray-300 bg-white px-3 py-2">
                <option value="active">{{ t('items.statusActive') }}</option>
                <option value="archived">{{ t('items.statusArchived') }}</option>
            </select>
        </label>

        <div class="flex justify-end gap-2">
            <RouterLink :to="{ name: 'Items' }" class="rounded border border-gray-300 bg-white px-4 py-2 text-sm">{{ t('common.cancel') }}</RouterLink>
            <button type="submit" class="rounded bg-gray-900 px-4 py-2 text-sm text-white hover:bg-gray-700 disabled:opacity-50" :disabled="saving">
                {{ saving ? t('common.loading') : t('common.save') }}
            </button>
        </div>
    </form>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import http from '@/services/apiEndPoints';
import { fieldError, getErrorMessage } from '@/services/apiErrors';
import { showSuccessMessage } from '@/services/toast';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const isEdit = computed(() => route.name === 'ItemEdit');

const name = ref('');
const description = ref('');
const status = ref('active');
const pageLoading = ref(true);
const saving = ref(false);
const loadError = ref('');
const errorMessage = ref('');
const errors = reactive<{ name: string | null; description: string | null }>({ name: null, description: null });

const loadItem = async () => {
    pageLoading.value = true;
    loadError.value = '';
    try {
        const response = await http.getItem(route.params.id as string);
        const item = response.data.data;
        name.value = item.name;
        description.value = item.description ?? '';
        status.value = item.status;
    } catch (error) {
        console.error('Error loading item:', error);
        loadError.value = getErrorMessage(error, t('items.loadOneFailed'));
    } finally {
        pageLoading.value = false;
    }
};

const save = async () => {
    saving.value = true;
    errorMessage.value = '';
    errors.name = null;
    errors.description = null;
    try {
        const data = { name: name.value, description: description.value || null, status: status.value };
        if (isEdit.value) {
            await http.updateItem({ id: route.params.id as string, ...data });
        } else {
            await http.createItem(data);
        }
        showSuccessMessage(t('items.saved'));
        router.push({ name: 'Items' });
    } catch (error) {
        console.error('Error saving item:', error);
        errors.name = fieldError(error, 'name');
        errors.description = fieldError(error, 'description');
        if (!errors.name && !errors.description) {
            errorMessage.value = getErrorMessage(error, t('items.saveFailed'));
        }
    } finally {
        saving.value = false;
    }
};

onMounted(() => {
    if (isEdit.value) {
        loadItem();
    } else {
        pageLoading.value = false;
    }
});
</script>
```

## 9. Docker

`docker compose up -d` is all it takes, even from a clean checkout with no `vendor/`, `node_modules/` or `.env`.

| Service | What | Host port |
|---|---|---|
| `app` | PHP-FPM. On start: creates `.env`, `composer install`, `APP_KEY`, `JWT_SECRET`, `migrate --seed` | |
| `nginx` | Serves `public/`, passes PHP to `app` | `APP_PORT` (8000) |
| `mysql` | MySQL 8.0, data in the `mysql-data` volume | `127.0.0.1:FORWARD_DB_PORT` (3307) |
| `queue` | `php artisan queue:listen --tries=1` | |
| `scheduler` | `php artisan schedule:work` | |
| `node` | `npm install`, then the Vite dev server | `VITE_PORT` (5173) |
| `phpmyadmin` | Database UI | `127.0.0.1:PHPMYADMIN_PORT` (8080) |

Start order is enforced with health checks: `mysql` → `app` → `queue`, `scheduler`, `node` → `nginx`. So when `up -d` returns, the app answers. The project folder is bind-mounted into the containers, which run as your host user (`WWWUSER`/`WWWGROUP`).

This stack is for local development. Production: `docs/production-deploy.md` (section 10).

#### `docker/php/Dockerfile`

```dockerfile
FROM php:8.4-fpm-bookworm

# The container's user takes your host uid/gid, so files created in the bind mount (vendor, logs, uploads) belong to you
ARG WWWUSER=1000
ARG WWWGROUP=1000

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev \
    && docker-php-ext-install bcmath intl opcache pcntl pdo_mysql zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer --2

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

RUN groupadd --force --gid "$WWWGROUP" app \
    && useradd --create-home --shell /bin/bash --no-user-group --gid "$WWWGROUP" --uid "$WWWUSER" app

WORKDIR /var/www/html
USER app

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]
```

#### `docker/php/php.ini`

```ini
[PHP]
post_max_size = 100M
upload_max_filesize = 100M
memory_limit = 512M
variables_order = EGPCS

[opcache]
opcache.enable = 1
; Local development: code changes apply at once
opcache.validate_timestamps = 1
opcache.revalidate_freq = 0
```

#### `docker/php/entrypoint.sh`

```sh
#!/bin/sh
set -e

cd /var/www/html

# Only the `app` container prepares the project; queue and scheduler start after it is healthy (docker-compose.yml)
if [ "$1" = "php-fpm" ]; then
    mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/testing \
        storage/framework/views storage/logs bootstrap/cache

    [ -f .env ] || cp .env.example .env

    if [ ! -f vendor/autoload.php ] || [ composer.json -nt vendor/autoload.php ] || [ composer.lock -nt vendor/autoload.php ]; then
        composer install --no-interaction --prefer-dist
    fi

    grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force --no-interaction

    if ! grep -Eq '^JWT_SECRET=.{32,}' .env; then
        secret=$(php -r 'echo bin2hex(random_bytes(32));')
        sed -i "s|^JWT_SECRET=.*|JWT_SECRET=$secret|" .env
    fi

    php artisan config:clear --no-interaction
    php artisan migrate --force --seed --no-interaction
    [ -e public/storage ] || php artisan storage:link --no-interaction
fi

exec "$@"
```

#### `docker/nginx/default.conf`

```nginx
server {
    listen 80;
    server_name _;
    root /var/www/html/public;
    index index.php;

    charset utf-8;
    client_max_body_size 100m;

    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass app:9000;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

#### `docker-compose.yml`

```yaml
# Local development: `docker compose up -d` builds and starts everything. It returns once the app answers.
# The project folder is bind-mounted, so code changes apply at once. Order: mysql -> app (installs, migrates) -> the rest.
name: {{APP_SLUG}}

x-app-image: &app-image
    build:
        context: .
        dockerfile: docker/php/Dockerfile
        args:
            WWWUSER: '${WWWUSER:-1000}'
            WWWGROUP: '${WWWGROUP:-1000}'
    image: '{{APP_SLUG}}/app:local'
    extra_hosts:
        - 'host.docker.internal:host-gateway'
    volumes:
        - '.:/var/www/html'
    networks:
        - app

services:
    # PHP-FPM. On start: creates .env, composer install, APP_KEY, JWT_SECRET, migrate --seed (docker/php/entrypoint.sh)
    app:
        <<: *app-image
        restart: unless-stopped
        depends_on:
            mysql:
                condition: service_healthy
        healthcheck:
            test: ['CMD', 'php', '-r', 'exit(@fsockopen("127.0.0.1", 9000) ? 0 : 1);']
            interval: 3s
            timeout: 3s
            retries: 5
            # The first start runs composer install
            start_period: 600s
            start_interval: 3s
    # Queue worker + scheduler (same image, code and user as the app; restart on crash).
    # queue:listen reloads the code for every job, so code changes apply without restarting these containers.
    queue:
        <<: *app-image
        command: ['php', 'artisan', 'queue:listen', '--tries=1']
        restart: unless-stopped
        depends_on:
            app:
                condition: service_healthy
    scheduler:
        <<: *app-image
        command: ['php', 'artisan', 'schedule:work']
        restart: unless-stopped
        depends_on:
            app:
                condition: service_healthy
    nginx:
        image: 'nginx:1.28-alpine'
        restart: unless-stopped
        ports:
            - '${APP_PORT:-8000}:80'
        volumes:
            - '.:/var/www/html:ro'
            - './docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro'
        networks:
            - app
        depends_on:
            app:
                condition: service_healthy
            node:
                condition: service_healthy
    # Vite dev server (HMR). npm install on start; public/hot tells Laravel to load the assets from it.
    node:
        image: 'node:24.15.0-bookworm-slim'
        user: '${WWWUSER:-1000}:${WWWGROUP:-1000}'
        working_dir: /var/www/html
        command: ['sh', '-c', 'rm -f public/hot && npm install --no-audit --no-fund && exec npm run dev']
        restart: unless-stopped
        environment:
            HOME: /tmp
        ports:
            - '${VITE_PORT:-5173}:5173'
        volumes:
            - '.:/var/www/html'
        networks:
            - app
        depends_on:
            app:
                condition: service_healthy
        healthcheck:
            test: ['CMD', 'test', '-f', 'public/hot']
            interval: 3s
            timeout: 3s
            retries: 5
            # The first start runs npm install
            start_period: 600s
            start_interval: 3s
    mysql:
        image: 'mysql:8.0'
        restart: unless-stopped
        ports:
            - '127.0.0.1:${FORWARD_DB_PORT:-3307}:3306'
        environment:
            MYSQL_ROOT_PASSWORD: '${DB_PASSWORD:-local-dev-password}'
            MYSQL_DATABASE: '${DB_DATABASE:-{{DB_NAME}}}'
            MYSQL_USER: '${DB_USERNAME:-{{DB_NAME}}}'
            MYSQL_PASSWORD: '${DB_PASSWORD:-local-dev-password}'
        volumes:
            - 'mysql-data:/var/lib/mysql'
        networks:
            - app
        healthcheck:
            # Over TCP: the image's temporary init server only listens on the socket, a socket ping would pass too early
            test: ['CMD-SHELL', 'mysqladmin ping -h 127.0.0.1 -uroot -p"$$MYSQL_ROOT_PASSWORD" --silent']
            interval: 3s
            timeout: 5s
            retries: 40
    phpmyadmin:
        image: 'phpmyadmin:5'
        restart: unless-stopped
        ports:
            - '127.0.0.1:${PHPMYADMIN_PORT:-8080}:80'
        environment:
            PMA_HOST: mysql
        networks:
            - app
        depends_on:
            mysql:
                condition: service_healthy
networks:
    app:
        driver: bridge
volumes:
    mysql-data:
        driver: local
```

#### `.dockerignore`

```
# The project is bind-mounted, not copied: only these files go into the image
*
!docker/php
```

#### `.env.example`

```dotenv
APP_NAME="{{APP_NAME}}"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000
# Proxies allowed to set X-Forwarded-* (client IP, https): comma-separated IPs/CIDR ranges, * = any.
# Production: the load balancer's IPs, or empty when nothing sits in front of the app
TRUSTED_PROXIES=
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

# Local Docker database: the same values as the defaults in docker-compose.yml. Change them together.
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE={{DB_NAME}}
DB_USERNAME={{DB_NAME}}
DB_PASSWORD=local-dev-password

SESSION_DRIVER=file
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=log
MAIL_FROM_ADDRESS=hello@example.com
MAIL_FROM_NAME="${APP_NAME}"

# JWT (config/jwt.php). The Docker entrypoint generates JWT_SECRET when it is empty. At least 32 bytes.
JWT_SECRET=
JWT_ACCESS_TTL_SECONDS=900
JWT_REFRESH_TTL_DAYS=30
JWT_REFRESH_COOKIE_NAME={{DB_NAME}}_refresh_token
# false only because local Docker runs on plain http. Production: true (https)
JWT_REFRESH_COOKIE_SECURE=false

# Requests per minute (config/rate_limits.php): endpoints behind jwtAuth per user, register/login/refresh per IP
RATE_LIMIT_API_PER_MINUTE=240
RATE_LIMIT_AUTH_PER_MINUTE=30

# Docker (docker-compose.yml)
# Your host user and group ids (run `id -u` / `id -g`), so files created in the containers belong to you
WWWUSER=1000
WWWGROUP=1000
APP_PORT=8000
VITE_PORT=5173
FORWARD_DB_PORT=3307
PHPMYADMIN_PORT=8080
```

## 10. Tooling

`make test`, `make analyse`, `make lint`, `make typecheck`, `make build`, `make shell`, `make migrate`, `make fresh`, `make logs`, `make up`, `make down`, `make setup`. Tests run on in-memory SQLite with a synchronous queue. Test classes are in sections 6 and 8.

#### `composer.json`

```json
{
    "$schema": "https://getcomposer.org/schema.json",
    "name": "{{APP_SLUG}}/app",
    "type": "project",
    "description": "{{APP_DESCRIPTION}}",
    "license": "proprietary",
    "require": {
        "php": "^8.4",
        "firebase/php-jwt": "7.2.1",
        "guzzlehttp/guzzle": "7.15.5",
        "laravel/framework": "13.37.0",
        "laravel/tinker": "3.0.2"
    },
    "require-dev": {
        "fakerphp/faker": "1.24.1",
        "larastan/larastan": "3.13.0",
        "laravel/pail": "1.2.7",
        "laravel/pint": "1.32.1",
        "mockery/mockery": "1.6.15",
        "nunomaduro/collision": "8.9.5",
        "phpunit/phpunit": "13.4.5"
    },
    "autoload": {
        "psr-4": {
            "App\\": "app/",
            "Database\\Factories\\": "database/factories/",
            "Database\\Seeders\\": "database/seeders/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\": "tests/"
        }
    },
    "scripts": {
        "post-autoload-dump": [
            "Illuminate\\Foundation\\ComposerScripts::postAutoloadDump",
            "@php artisan package:discover --ansi"
        ],
        "test": [
            "@php artisan config:clear --ansi",
            "@php artisan test"
        ],
        "analyse": [
            "phpstan analyse --memory-limit=1G"
        ]
    },
    "extra": {
        "laravel": {
            "dont-discover": []
        }
    },
    "config": {
        "optimize-autoloader": true,
        "preferred-install": "dist",
        "sort-packages": true,
        "allow-plugins": {
            "php-http/discovery": true
        }
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

#### `package.json`

```json
{
    "name": "{{APP_SLUG}}",
    "private": true,
    "type": "module",
    "scripts": {
        "build": "vite build",
        "dev": "vite",
        "typecheck": "vue-tsc --noEmit",
        "format": "prettier --write resources/",
        "format:check": "prettier --check resources/",
        "lint": "eslint . --fix"
    },
    "devDependencies": {
        "@eslint/js": "10.0.1",
        "@types/node": "24.19.2",
        "@vue/eslint-config-typescript": "14.9.0",
        "eslint": "10.13.0",
        "eslint-config-prettier": "10.1.8",
        "eslint-plugin-vue": "10.11.1",
        "prettier": "3.9.10",
        "prettier-plugin-organize-imports": "4.3.0",
        "prettier-plugin-tailwindcss": "0.8.1",
        "typescript-eslint": "8.71.2",
        "vue-tsc": "3.3.13"
    },
    "dependencies": {
        "@tailwindcss/vite": "4.3.3",
        "@vitejs/plugin-vue": "6.0.10",
        "axios": "1.20.0",
        "laravel-vite-plugin": "3.2.0",
        "pinia": "4.1.0",
        "pinia-plugin-persistedstate": "4.7.1",
        "tailwindcss": "4.3.3",
        "typescript": "6.0.3",
        "vite": "8.3.4",
        "vue": "3.5.44",
        "vue-i18n": "11.4.14",
        "vue-router": "5.4.0"
    }
}
```

#### `vite.config.ts`

```ts
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import path from 'path';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    resolve: {
        alias: {
            '@': path.resolve(import.meta.dirname, './resources/js'),
        },
    },
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: {
            host: 'localhost',
        },
    },
});
```

#### `tsconfig.json`

```json
{
    "compilerOptions": {
        "target": "ESNext",
        "useDefineForClassFields": true,
        "lib": ["ESNext", "DOM", "DOM.Iterable"],
        "jsx": "preserve",
        "jsxImportSource": "vue",
        "module": "ESNext",
        "moduleResolution": "bundler",
        "paths": {
            "@/*": ["./resources/js/*"]
        },
        "types": ["vite/client"],
        "resolveJsonModule": true,
        "allowJs": true,
        "sourceMap": true,
        "noEmit": true,
        "isolatedModules": true,
        "esModuleInterop": true,
        "forceConsistentCasingInFileNames": true,
        "strict": true,
        "skipLibCheck": true
    },
    "include": ["resources/js/**/*.ts", "resources/js/**/*.d.ts", "resources/js/**/*.tsx", "resources/js/**/*.vue"]
}
```

#### `eslint.config.js`

```js
import prettier from 'eslint-config-prettier';
import vue from 'eslint-plugin-vue';

import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript';

export default defineConfigWithVueTs(
    vue.configs['flat/essential'],
    vueTsConfigs.recommended,
    {
        ignores: ['vendor', 'node_modules', 'public', 'storage', 'bootstrap/cache'],
    },
    {
        rules: {
            'vue/multi-word-component-names': 'off',
            '@typescript-eslint/no-explicit-any': 'off',
        },
    },
    prettier,
);
```

#### `.prettierrc`

```
{
    "tabWidth": 4,
    "singleQuote": true,
    "printWidth": 120,
    "plugins": ["prettier-plugin-organize-imports", "prettier-plugin-tailwindcss"]
}
```

#### `.editorconfig`

```
root = true

[*]
charset = utf-8
end_of_line = lf
indent_size = 4
indent_style = space
insert_final_newline = true
trim_trailing_whitespace = true

[*.md]
trim_trailing_whitespace = false

[*.{yml,yaml}]
indent_size = 2

[Makefile]
indent_style = tab
```

#### `phpunit.xml`

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
>
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>app</directory>
        </include>
    </source>
    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="APP_KEY" value="base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="/>
        <env name="APP_MAINTENANCE_DRIVER" value="file"/>
        <env name="BCRYPT_ROUNDS" value="4"/>
        <env name="CACHE_STORE" value="array"/>
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
        <env name="JWT_SECRET" value="test-jwt-secret-of-at-least-32-bytes-long"/>
        <env name="JWT_REFRESH_COOKIE_SECURE" value="false"/>
        <env name="MAIL_MAILER" value="array"/>
        <env name="QUEUE_CONNECTION" value="sync"/>
        <env name="SESSION_DRIVER" value="array"/>
    </php>
</phpunit>
```

#### `phpstan.neon`

```yaml
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    paths:
        - app
        - routes
        - config
        - database
    # 0 (loosest) .. 10; 5 = argument types, unknown methods/properties, dead code
    level: 5
    tmpDir: storage/framework/cache/phpstan
```

#### `Makefile`

```makefile
# Thin wrappers around docker compose. Nothing here is required: `docker compose up -d` alone starts everything.
.PHONY: setup up down restart logs ps shell migrate fresh test analyse lint typecheck build

setup: up

up:
	docker compose up -d --build

down:
	docker compose down

restart:
	docker compose restart

logs:
	docker compose logs -f

ps:
	docker compose ps

shell:
	docker compose exec app bash

migrate:
	docker compose exec app php artisan migrate

fresh:
	docker compose exec app php artisan migrate:fresh --seed

test:
	docker compose exec app composer test

analyse:
	docker compose exec app composer analyse

lint:
	docker compose exec node npm run lint

typecheck:
	docker compose exec node npm run typecheck

build:
	docker compose exec node npm run build
```

#### `.gitignore`

```
# Dependencies: installed by the Docker entrypoint / the node service (composer install / npm install)
/vendor
/node_modules

# Built assets: npm run build
/public/build
/public/hot

# Created by php artisan storage:link
/public/storage

# Environment and secrets
.env
.env.backup
.env.production
/auth.json
/storage/*.key

# Created at runtime (the Docker entrypoint creates the folders)
/storage/app/*
/storage/framework/*
/storage/logs/*
/bootstrap/cache/*

# Tool caches and logs
.phpunit.result.cache
/.phpunit.cache
/storage/pail
*.log

# Editors and OS files
/.fleet
/.idea
/.nova
/.vscode
/.zed
.phpactor.json
.DS_Store
Thumbs.db
```

#### `docs/production-deploy.md`

````markdown
# Production deploy checklist

`docker-compose.yml` is the local development stack (Vite dev server, bind mount, phpMyAdmin). Don't deploy it as is.

## 1. File ownership

Laravel writes to `storage/` and `bootstrap/cache/` at runtime. If a command runs as **root**, the files it creates are
owned by root and the web server user can't write to them any more; one root `php artisan ...` can make every `Log::` call
fail for the rest of the day (with daily logs). Run **every** artisan / composer command, the cron entry and the queue
workers as the user PHP-FPM runs as:

```bash
sudo -u www-data php artisan migrate --force
sudo chown -R www-data:www-data storage bootstrap/cache
```

## 2. Processes

- **Scheduler:** one cron entry as the web user, on one server only: `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1`
- **Queue worker:** Supervisor with `command=php /path/to/app/artisan queue:work --sleep=3 --tries=1 --max-time=3600` and `user=www-data`.
- `queue:work` keeps the code and config it booted with: run `php artisan queue:restart` after **every** deploy.

## 3. Every deploy

```bash
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data npm ci && sudo -u www-data npm run build
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php artisan queue:restart
```

`config:cache` means `env()` is only read while caching. Run it again after changing `.env`. Make sure `public/hot` doesn't
exist on the server: it makes Laravel load the assets from a Vite dev server.

## 4. Once per server

- `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` = the public https URL, `LOG_LEVEL=info`,
  `TRUSTED_PROXIES` = the load balancer's IPs (empty when nothing sits in front of the app; never `*` when the server is
  reachable directly).
- **`JWT_SECRET`**: at least 32 random bytes (`php -r 'echo bin2hex(random_bytes(32));'`), only in this file. Changing it signs
  everyone's access token out (the refresh cookie gets them a new one).
- **`JWT_REFRESH_COOKIE_SECURE=true`** (or leave it out): the app must be on https.
- `APP_KEY`: keep it safe and unchanged.
- The app is served from one origin (SPA + `/api`): the refresh cookie is `SameSite=Strict`. A frontend on another site can't
  use it.
- `php artisan storage:link`. PHP extensions: `pdo_mysql`, `mbstring`, `intl`, `bcmath`, `zip`, `pcntl`.
````

## 11. CLAUDE.md for the new project

Future sessions read this file automatically. It is already part of the file set: don't summarize it.

#### `CLAUDE.md`

````markdown
# CLAUDE.md

Coding guide for this repo. It describes how the code **is** written. When adding code, match these patterns. Don't "improve" existing code unless asked.

## 1. Project overview

**{{APP_NAME}}**: {{APP_DESCRIPTION}}

A Laravel JSON API + a Vue 3 SPA served from the same origin. Users sign in with email and password (JWT access token + rotating refresh token). Everything runs in Docker: `docker compose up -d`, then http://localhost:8000.

## 2. Tech stack

PHP 8.4, Laravel 13, `firebase/php-jwt` 7, MySQL 8.0, `database` queue + cache. Vue 3.5 + vue-router 5 + Pinia 4, vue-i18n 11, axios, Tailwind 4, Vite 8, TypeScript 6 (not 7: `typescript-eslint` supports < 6.1). PHPUnit 13, Larastan 3 (level 5), Pint, ESLint 10, Prettier 3. Versions are pinned in `composer.json` / `package.json`; don't upgrade or swap a library as part of another change.

## 3. Structure

```
app/
  Http/Controllers/   One controller per feature, thin. Controller::exceptionResponse() is the one error mapper
  Http/Middleware/    JwtAuthMiddleware (alias jwtAuth)
  Http/Requests/      Form Requests, all extending BaseFormRequest (authorize() => true, getAuthUser())
  Jobs/               Queued jobs
  Models/             Eloquent models
  Repository/         (singular!) Concrete repository classes, one per model: ALL DB access. No interfaces
  Services/<Feature>/ All logic
config/               Only files that differ from the framework's defaults (Laravel merges them over its own)
database/migrations/  Anonymous-class migrations
resources/js/
  app.ts, Index.vue   SPA bootstrap + root (nav, toasts, <RouterView>)
  i18n.ts, locales/   vue-i18n; en.json has every text
  services/           httpCommon.ts (axios + 401 refresh), apiEndPoints.ts (all API calls), apiErrors.ts, router.ts, store.ts, toast.ts
  pages/<feature>/    Page components; subcomponents next to the page
routes/api.php        All JSON endpoints (prefixed /api)
routes/web.php        SPA shell `/` + catch-all (deep links survive a refresh; excludes api/ build/ storage/ up)
routes/console.php    Artisan commands + the schedule
docker/, docker-compose.yml  Local stack: app (php-fpm), nginx, mysql, queue, scheduler, node (Vite), phpmyadmin
```

## 4. Request lifecycle

Route group (middleware `jwtAuth` + `throttle:api`) → Form Request (validates) → Controller → Service (logic; throws readable exceptions) → Repository (query) → Model. The controller returns `response()->json(['data' => $result])` or, in `catch`, `$this->exceptionResponse($e)`.

## 5. Backend rules

**Routes**
- JSON endpoints in `routes/api.php`, in `Route::group(['prefix' => ..., 'middleware' => [...]], function () {...})`. Not the fluent `Route::prefix()->group()`.
- URL segments are verb-like actions, not REST resources: `items/list`, `items/get`, `items/create`, `items/update`, `items/delete`. Reads use GET with query params, every write is POST. No versioning.
- Every signed-in group has `['jwtAuth', 'throttle:api']`; `bootstrap/app.php` makes jwtAuth run before throttle, so the limit is per verified user. Public endpoints get a per-IP limiter (`throttle:auth`, config/rate_limits.php).

**Controllers: in charge of the process, doing no work themselves**
- They take the validated input and `$request->getAuthUser()`, call a service, return the response. Constructor injection, `private readonly`, one per line. Descriptive camelCase verbs (`getItems`, `createItem`). Private helpers at the bottom.
- The shape of every action:
  ```php
  try {
      $item = $this->itemService->createItem($request->getAuthUser(), $request->all());
  } catch (\Exception $e) {
      return $this->exceptionResponse($e);
  }

  return response()->json(['data' => $item]);
  ```

**Requests**
- One class per input, named after it (`ItemRequest`, `ItemIdRequest`, `ItemListRequest`), extending `BaseFormRequest`. Only `rules()`, pipe-string syntax. Read input with `$request->get('key')` / `$request->all()`, not `validated()`. No DTOs: data moves as arrays. A plain `Request` only where the input isn't user data (the refresh cookie).

**Services**
- All logic: not-found checks, rules, logging, job dispatch, response data. Report what the user can fix as `throw new \Exception('readable message', <4xx or 502>)` or a `ValidationException::withMessages([...])`. Anything else becomes a generic 500 through `exceptionResponse()` (and is logged), so technical text never reaches the user.
- Messages are written for the general public: what happened + what to do. Never "token", "not found", ids, hosts or third-party error text.
- No custom exception classes; `bootstrap/app.php` `withExceptions` stays empty.

**Repositories**
- `app/Repository/XRepository.php`, one per model, concrete, no interfaces. Methods `findByX`, `getXById`, `getXForUser`, `createX`, `updateX`, `deleteX`. Create/update by assigning properties and `->save()`.
- **Every query and write lives in a repository.** Controllers, services, jobs and `routes/console.php` never call `Model::where/find`, `->save()`, `->update()`. Eloquent only, no `DB::` query builder; `DB::transaction()` in services only wraps repository calls.
- **Tenant scoping:** ids sent by the client are looked up within the user (`getItemForUser($id, $userId)`; 404 otherwise). Do the same for every new endpoint.

**Responses**
- Success: `response()->json(['data' => $result])`, status 200. Models are returned directly: no API Resources. Secrets go in the model's `$hidden`.
- Error: `response()->json(['error' => 'message'], 4xx)`. Validation: Laravel's 422 `{message, errors: {field: [...]}}`.
- **Pagination:** no Laravel paginator. Params `page`, `per_page` (validated against a list in config: 5, 10, 15, 25, 50; default 15). Answer `{items, pagination: {page, per_page, total, last_page}}`. See `ItemService::getItems`.
- **Filtering/sorting:** optional query params validated in the Form Request: `q` (text search, LIKE wildcards escaped), one param per filter, `sort` as a named list (`newest`, `oldest`, `name`), never a raw column name.

**Auth**
- No guards, Sanctum or `$request->user()`: the `jwtAuth` middleware verifies the Bearer token and puts the user on the request (`getAuthUser()`). Not `getUser()`: Symfony's Request already has that method.
- Access token: HS256 JWT, 15 min, in the browser's memory only. Refresh token: random, 30 days, sha256 in `refresh_tokens`, in an httpOnly `SameSite=Strict` cookie with path `/api/auth`. It rotates on every refresh. An old token used within 10 s is a race between tabs (gets an access token); later it counts as stolen and the session's whole family is revoked. Logout revokes the family and denies the access token's `jti` in the cache.
- `AuthService` / `JwtService` are the only code that touches tokens.

**Errors and logging**
- `Log::info` success traces, `warning` recoverable problems, `error` failures, `critical` permanent job failures. A readable message + a snake_case context array: `Log::error('Could not …', ['user_id' => $user->id, 'error' => $e->getMessage()])`. Never tokens or passwords.

**Outgoing HTTP / jobs**
- Third-party calls with the `Http` facade, in a class in `app/Services/<Provider>/`, wrapped in `try/catch` with `Log::error`.
- Slow or retryable work goes in a queued job: `use Queueable` (`Illuminate\Foundation\Queue\Queueable`), promoted constructor props, `public $tries`, `public $backoff`. Make it idempotent with `Cache::lock` when a webhook can trigger it. Retries only through jobs.
- Scheduled work: an `Artisan::command` in `routes/console.php` that dispatches a job, plus `Schedule::command(...)`.
- Cache external data with `Cache::remember("<thing>_{$id}", <seconds>, fn)` and clear it when the data is saved.

**Conventions**
- PHP classes: PascalCase + role suffix (`XController`, `XService`, `XRepository`, `XRequest`, `XJob`, `XMiddleware`). camelCase methods/variables, snake_case API array keys.
- Type hints and docblocks are sparse; short `//` step comments are fine. New code may add types but shouldn't go out of its way.
- DI: Laravel auto-wiring of concrete classes, no bindings. Config through `config('file.key')`; `env()` only in `config/`. Every new env key goes in `.env.example` (empty value for secrets).

## 6. Frontend rules

- **Never call axios from a component.** One method per endpoint in `services/apiEndPoints.ts` (class `endPointApis`, exported as a singleton, imported as `http`). The payload is at `response.data.data`.
- `<script setup lang="ts">`, Composition API, `async/await` + `try/catch/finally` with one boolean ref per action (`loading`, `saving`, `deleting`). `@/` alias. `any` is allowed. Interfaces inline in the component. PascalCase component files; snake_case page folders.
- **Errors:** every failed call shows a plain message through `services/apiErrors.ts`: `getErrorMessage(error, fallback)` for inline messages, `fieldError(error, 'field')` for one field, `showErrorToast(error, fallback)` where there's no error area; plus `console.error`. Never raw response data. Success feedback: `showSuccessMessage()` from `services/toast.ts`.
- **Texts:** every text the app shows is in `resources/js/locales/en.json`, grouped per page (`items.*`), shared ones in `common.*`. Components: `const { t } = useI18n()`. Plain `.ts` modules: `import { t } from '@/i18n'`, called inside functions. Plurals: `"{count} item | {count} items"` with `t(key, { count }, count)`. Remove unused keys.
- **Page loading:** a page shows its title and one centered loading state until all the data it loads on open has arrived: `pageLoading = ref(true)`, false after the first load. Later reloads (filters, paging) never bring it back.
- **A failed load never falls through to a state that means "there is nothing"** (an empty state, a create form): show the error with "Try again" in its place.
- **Pagination:** the same row directly under every paged list: left the per-page select + "per page", right Previous / "Page X of Y" / Next, disabled at the ends and while loading. Shown whenever the list has items. A new page size goes back to page 1.
- **Filters** live in the URL query (`router.replace`), defaults left out, so a refresh or shared link shows the same list.
- **Auth state:** `useApplicationStore` (`services/store.ts`). The access token stays in `httpCommon.ts`'s memory, never in web storage. Pages need a signed-in user unless `meta: { guest: true }`; `router.beforeEach` handles it, pages do nothing.
- **Styling:** Tailwind utility classes in templates. No other UI library, no `<style>` blocks.
- Add routes in `services/router.ts` with a PascalCase `name`, nav links in `Index.vue`.

## 7. Database

- snake_case plural tables, singular PascalCase models. Anonymous-class migrations with `up()`/`down()`, named `create_x_table` / `add_y_to_x_table`. Once a migration ran anywhere that matters, never edit it: add a new one.
- `$table->id()`, `$table->timestamps()`, `foreignId('user_id')->constrained()->onDelete('cascade')`. Markers are `*_at`.
- Models: `$fillable`, `$casts` as a property, `$hidden` for secrets, relationship methods with a return type (`user(): BelongsTo`), status values as class constants.

## 8. How to add a new feature

`Item` is the reference module (if it isn't in this repo: `docs/reference-module.md`). Copy it file by file and rename:

1. **Migration** `database/migrations/<date>_create_widgets_table.php`, with `foreignId('user_id')->constrained()`.
2. **Model** `app/Models/Widget.php`.
3. **Repository** `app/Repository/WidgetRepository.php`: `getWidgetsForUser`, `getWidgetForUser`, `createWidget`, `updateWidget`, `deleteWidget`. Every query limited to the owner.
4. **Service** `app/Services/Widget/WidgetService.php`: rules, 404s, logging.
5. **Requests** `WidgetRequest`, `WidgetIdRequest`, `WidgetListRequest`.
6. **Controller** `app/Http/Controllers/WidgetController.php`: the try/catch shape above.
7. **Routes:** a group in `routes/api.php` with `['jwtAuth', 'throttle:api']`. Config (page sizes) in `config/widgets.php`.
8. **Tests** `tests/Feature/WidgetTest.php`: needs a token, CRUD, validation, **another user's record → 404 and untouched**, list + pagination.
9. **API methods** in `resources/js/services/apiEndPoints.ts`.
10. **Pages** `resources/js/pages/widgets/Widgets.vue` + `WidgetForm.vue`; routes in `router.ts`; nav link in `Index.vue`; texts in `en.json`.
11. **Check:** `make test`, `make analyse`, `make lint`, `make typecheck`, `make build`.

A feature that isn't a per-user CRUD still keeps the layers and the checks. Pick the protection per route; if it's unclear which applies, ask.

## 9. Commands

```bash
docker compose up -d      # Build and start everything (first start: composer install, npm install, migrate --seed)
make logs                 # docker compose logs -f
make shell                # bash in the app container
make migrate              # php artisan migrate
make fresh                # migrate:fresh --seed
make test                 # composer test (PHPUnit, in-memory SQLite)
make analyse              # Larastan: must stay at 0 errors
make lint                 # eslint --fix
make typecheck            # vue-tsc
make build                # production assets in public/build
docker compose exec app composer audit && docker compose exec node npm audit
```

App http://localhost:8000, phpMyAdmin http://localhost:8080, local sign-in `demo@example.com` / `password` (seeded only with `APP_ENV=local`). Ports are in `.env`. `queue:listen` reloads code for every job. A long-running `queue:work` doesn't: `php artisan queue:restart` after every change. Production: [docs/production-deploy.md](docs/production-deploy.md).

## 10. Do / Don't

**Do**
- Return `['data' => …]` on success and `['error' => …]` on failure, from every new endpoint.
- Keep Larastan at 0 errors, and run `composer audit` / `npm audit` before a release.
- Write a feature test for every endpoint, including the other-user case.
- **Remove code that isn't used.** When a change leaves code without a caller (functions, endpoints, config keys, texts), delete it in the same change.

**Don't**
- Don't add `/v1` versioning, API Resources, DTOs, repository interfaces or service-provider bindings.
- Don't put queries in services or logic in controllers.
- Don't call `env()` outside `config/`, or axios outside `services/`.
- Don't hardcode ids, URLs or secrets. Don't commit `.env`.
- Don't return secrets to the frontend; add them to `$hidden` on new models.
- Don't store tokens in localStorage or sessionStorage.
- Don't run `pint` / `prettier` over the whole repo as part of a change; match the surrounding style.
````

## 12. Verification checklist

Run these from the project root and report each one. `{{DB_NAME}}` in the cookie name is the value you filled in.

**1. Containers build and start**

```bash
docker compose up -d --build
docker compose ps
```

Expect `app`, `mysql` and `node` healthy, and `nginx`, `queue`, `scheduler`, `phpmyadmin` up.

**2. Migrations ran**

```bash
docker compose exec -T app php artisan migrate:status
```

Expect `create_users_table`, `create_refresh_tokens_table`, `create_cache_table`, `create_jobs_table` (and `create_items_table` when kept) as `Ran`.

**3. Auth flow against the running API**

```bash
U=http://localhost:8000/api; J=$(mktemp); E="check$(date +%s)@example.com"
H='Accept: application/json'; C='Content-Type: application/json'
token() { sed -E 's/.*"access_token":"([^"]+)".*/\1/'; }
code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

T=$(curl -s -c "$J" -H "$H" -H "$C" -d "{\"name\":\"Check\",\"email\":\"$E\",\"password\":\"password123\",\"password_confirmation\":\"password123\"}" $U/auth/register | token)
echo "register: token length ${#T} (expect > 100)"
T=$(curl -s -c "$J" -H "$H" -H "$C" -d "{\"email\":\"$E\",\"password\":\"password123\"}" $U/auth/login | token)
echo "login: token length ${#T} (expect > 100)"
echo "wrong password: $(code -H "$H" -H "$C" -d "{\"email\":\"$E\",\"password\":\"wrong-password\"}" $U/auth/login) (expect 401)"
echo "me without token: $(code -H "$H" $U/auth/me) (expect 401)"
echo "me with token: $(code -H "$H" -H "Authorization: Bearer $T" $U/auth/me) (expect 200)"
OLD=$(awk '/refresh_token/ {print $7}' "$J")
T=$(curl -s -b "$J" -c "$J" -X POST -H "$H" $U/auth/refresh | token)
NEW=$(awk '/refresh_token/ {print $7}' "$J")
[ "$OLD" != "$NEW" ] && echo "refresh: cookie rotated (expect this line)"
echo "me with the new token: $(code -H "$H" -H "Authorization: Bearer $T" $U/auth/me) (expect 200)"
echo "logout: $(code -b "$J" -c "$J" -X POST -H "$H" -H "Authorization: Bearer $T" $U/auth/logout) (expect 200)"
echo "me after logout: $(code -H "$H" -H "Authorization: Bearer $T" $U/auth/me) (expect 401)"
echo "refresh after logout: $(code -b "$J" -X POST -H "$H" $U/auth/refresh) (expect 401)"
rm -f "$J"
```

**4. Example module CRUD** (skip when not kept)

```bash
U=http://localhost:8000/api; E="crud$(date +%s)@example.com"; H='Accept: application/json'; C='Content-Type: application/json'
T=$(curl -s -H "$H" -H "$C" -d "{\"name\":\"Check\",\"email\":\"$E\",\"password\":\"password123\",\"password_confirmation\":\"password123\"}" $U/auth/register | sed -E 's/.*"access_token":"([^"]+)".*/\1/')
A="Authorization: Bearer $T"
ID=$(curl -s -H "$H" -H "$A" -H "$C" -d '{"name":"First"}' $U/items/create | sed -E 's/.*"id":([0-9]+).*/\1/')
echo "created id: $ID"
curl -s -H "$H" -H "$A" "$U/items/get?id=$ID"; echo
curl -s -H "$H" -H "$A" -H "$C" -d "{\"id\":$ID,\"name\":\"Renamed\"}" $U/items/update; echo
curl -s -H "$H" -H "$A" "$U/items/list?per_page=5&sort=name"; echo
curl -s -H "$H" -H "$A" -H "$C" -d "{\"id\":$ID}" $U/items/delete; echo
curl -s -H "$H" -H "$A" "$U/items/get?id=$ID"; echo
```

Expect the created item, the renamed item, a list with `pagination`, `{"data":true}`, and finally an `{"error":...}` message.

**5. Tests and static analysis**

```bash
docker compose exec -T app composer test
docker compose exec -T app composer analyse
```

Expect all tests passing (26 with the example module, 20 without) and `[OK] No errors`.

**6. Frontend**

```bash
docker compose exec -T node npm run typecheck
docker compose exec -T node npx eslint .
docker compose exec -T node npm run build
```

Expect no errors, and `public/build/manifest.json` created. While the `node` service runs, the app keeps loading assets from the Vite dev server (`public/hot`).

**7. The SPA calls the API** (in a browser, or headless Chrome if the user has no time to click through)

Open http://localhost:8000.

1. You land on `/login`. Create an account: you land on the Dashboard.
2. Reload the page: you're still signed in. `localStorage` holds only `{"hasSession":true}`, and the refresh cookie isn't in `document.cookie`.
3. With the example module: on **Items**, create two items. Sort, search, reload (the filters stay), edit, delete.
4. Sign out: you land on `/login`. Open `/items` (or `/` without the module): you're sent to `/login` with a `redirect`. Sign in: you return there.

**8. Queue and scheduler**

```bash
docker compose exec -T app php artisan auth:prune-refresh-tokens
sleep 5
docker compose logs queue --tail=3
docker compose exec -T app php artisan schedule:list
```

Expect `PruneRefreshTokensJob … DONE` and the daily command listed.

## 13. Architecture notes

### How this file was checked

The code in this document was generated from a tree that passed everything below. The document was then run with its own extractor in an empty folder and started with `docker compose up --build -d`, both with and without the example module. Verified on Linux x86_64, Docker 29, host uid 1000: section 12 items 1-6 and 8, plus a scripted headless Chrome run of item 7 (including a forced 401 → one refresh → retry, and a rejected refresh ending the session).

**Not tested:** macOS and Windows hosts, ARM CPUs, a host uid other than 1000, writing the files by hand instead of with the Python extractor, a fresh Claude Code session following these instructions, and the production path in `docs/production-deploy.md`.

### Requested, but not in the source project: built new

The source app is an embedded Shopify app. Its tenant is a shop, identified by a short-lived token that Shopify issues. It has **no users, passwords, login or refresh**. These parts were written for this blueprint, in the source project's style, with the library it already uses:

| Part | Source project | Here |
|---|---|---|
| Register / login / refresh / logout / me, `users`, `refresh_tokens` | Only *verifies* Shopify's JWT in a middleware (`firebase/php-jwt`) | Full flow on the same library. The middleware keeps the same shape: verify → put the model on the request |
| Frontend 401 → refresh → retry, login pages, guards | App Bridge supplies a fresh token per request; guards check plan and connection | New interceptor and store; the guard keeps the `router.beforeEach` + `meta` flag pattern |
| Dockerfile, nginx, entrypoint, `.dockerignore` | Laravel Sail (`artisan serve`) | New. Sail can't start from an empty folder: its Dockerfile lives in `vendor/`, which doesn't exist until Composer runs. The service layout (app, mysql, queue, scheduler, phpMyAdmin, host uid) is kept |
| `Makefile`, `.prettierrc`, `.editorconfig` | None | New |
| Tests in the recipe | "Tests are out of scope" unless asked | Part of every feature, because a template must verify itself |

### Requested, but deliberately left out

- **Repository interfaces** and **API Resources.** The brief's reference-module list named them, and it also said not to invent patterns the project doesn't use. The source project has neither and its guide forbids both ("Don't add API Resources, DTOs, repository interfaces, or service-provider bindings"); models are returned directly and repositories are concrete, auto-wired classes. The project's actual pattern won. To add them later: bind each interface in `AppServiceProvider::register()`, and return `new ItemResource($item)` inside the existing `['data' => …]` envelope.
- **`/v1` versioning, global exception mapping, custom exception classes, DTOs, enums, roles and permissions, events, CI config:** none exist in the source project.
- **Password reset, email verification:** not in the source project, and they need a mail setup. The first feature most apps will add on top.

### Removed as business logic

Everything Shopify (OAuth, billing, GraphQL, webhooks, App Proxy, theme extension, App Bridge, Polaris) and everything Instagram/Meta (publishing, scheduling, comment-to-DM, analytics, feed), with their tables, jobs, configs, pages, the 35 translation files, secrets and URLs. Also `intervention/image`, `league/flysystem-aws-s3-v3`, `gnikyt/basic-shopify-api`.

### Normalized (the source had several styles, or a weak spot)

- **Exact versions in `composer.json`/`package.json`** (locked versions of the source's direct dependencies; the source uses ranges + lock files). Lock files would add about 500 KB here, so indirect dependencies resolve at first install.
- **`phpunit/phpunit` 13.4.5 instead of the source's 13.4.3:** Composer refuses 13.4.3 (advisory PKSA-qhjm-rpqc-qkn6, CVE-2026-119193, affected `>=13.4.0,<13.4.4`).
- **`mysql:8.0` instead of `mysql/mysql-server:8.0`:** the same version line on the maintained official image. MySQL 8.0 itself reached end of life in April 2026; moving to 8.4 is a decision for your first real project.
- **UI: Tailwind utility classes** (already installed in the source, mostly unused). The source's Polaris web components only make sense inside the Shopify admin. The page rules carried over: page loading, failed loads, pagination row, filters in the URL.
- **Response shape:** only `['data' => …]` / `['error' => …]`; the source's older ad-hoc shapes are gone.
- **Reads are always GET.** The source also has POST reads.
- **`401` shows the backend's text** in `apiErrors.ts` (the source always shows "session expired", because a wrong password can't happen there).
- **`getAuthUser()`** takes the place of the source's `getShop()`; `getUser()` would collide with Symfony's method.
- **Only the newest style of each inconsistency:** `foreignId()->constrained()`, `Http` facade, the `Queueable` job style, `async/await`, 4-space indent, the `@/` alias, snake_case page folders. No Guzzle in app code.
- **Dropped known defects:** the `ssr.ts` reference, runtime template compiler alias, leftover Inertia/SSR composer scripts, `PHP_CLI_SERVERaa_WORKERS` typo, unprocessed Tailwind CSS.
- **`config/`** only has what differs from the framework (Laravel merges it over its defaults).

### Known limits of this foundation

- An access token stays valid for up to 15 minutes after *reuse detection* revokes a session (logout denies it at once). Lower `JWT_ACCESS_TTL_SECONDS` for stricter needs.
- The refresh cookie requires the SPA and API on one site (`SameSite=Strict`). A separate-domain frontend needs CORS and a different cookie policy.
- `npm audit` reports 15 moderate advisories at these exact versions, from `minimatch` in the ESLint tooling (dev only). `composer audit` was clean when this was written.
- `throttle:auth` limits per IP only: no per-account lockout.
- Pint isn't enforced: the constructor style (one aligned parameter per line) isn't Pint-clean, the same as the source project.
