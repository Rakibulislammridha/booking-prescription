# routes/central

One file per module (SaaS owns this surface): `routes/central/<module>.php`, names `central.*`, served on the bare
central domain (and `www.`) with `['web', 'central']`. Globbed by `bootstrap/app.php`.
