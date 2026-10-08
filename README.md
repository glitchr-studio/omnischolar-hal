# omnischolar/hal

HAL, the French open archive, for [glitchr/omnischolar](https://github.com/glitchr-studio/omnischolar):
an author's deposits - by idHAL, ORCID or the name they sign - with their full texts in open
access; a deposit by its HAL id, DOI or arXiv id; a search; and on every query a filter by domain:
`shs.droit` is the legal scholarship (doctrine) HAL holds. No key.

```php
$hal = (new HalSourceFactory($http))->create();
$hal->works('Keitaro Nakatani');                                         // the deposits signed so
$hal->works('remi-metivier');                                            // by idHAL
$droit = (new HalSourceFactory($http))->create(['domains' => ['shs.droit']]);
$droit->search(new Query(text: 'responsabilité civile', from: 2024));
```

```yaml
omnischolar:
    sources:
        hal: { factory: hal }
        droit: { factory: hal, options: { domains: [shs.droit] } }   # every query filtered on that domain
```

See [docs/](docs/index.md).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
