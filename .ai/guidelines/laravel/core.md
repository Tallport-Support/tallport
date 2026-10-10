# Laravel in Tallport

- Tallport keeps FreeScout's Laravel 5.5-era layout:
  - models in `app/` (`App\User`, `App\Conversation`, …), not `app/Models`;
  - kernels in `app/Http/Kernel.php` and `app/Console/Kernel.php`;
  - providers in `config/app.php`;
  - routes in `routes/web.php`.
- Put new files where their siblings are. If you use `php artisan make:` commands, move and rename the output to match.
- Pass `--no-interaction` to Artisan commands.
- Many app actions go through `ajax()` controller methods switched on an `action` parameter, not one route per action. Follow that pattern where it is used.
- Prefer named routes and `route()` when generating links.
- Frontend assets are joined into minified build files at runtime by `\Minify` (`App\Misc\Minify`; modules add files through the `javascripts`/`stylesheets` filters). There is no build step: no Laravel Mix, Vite or npm. FruitUI's compiled assets are published to `public/vendor/fruitui`; Livewire's scripts come from `@livewireScripts`.
- Tests don't use model factories or Faker for new code. See the Tallport testing rules.
