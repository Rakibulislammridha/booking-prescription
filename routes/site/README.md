# routes/site

One file per module: `routes/site/<module>.php` (`Route::` calls only, names `site.<module>.*`).
Files here are globbed by `bootstrap/app.php` and wrapped in `['web', 'tenant']` on the tenant host.
