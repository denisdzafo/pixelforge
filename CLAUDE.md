# CLAUDE.md

Coding guide for this repo. It describes how the code **is** written. When adding code, match these patterns. Don't "improve" existing code unless asked.

## 1. Project overview

**PixelForge**: PixelForge application.

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
