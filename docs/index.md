# omnischolar/hal

## Installation

```sh
composer require omnischolar/hal
```

```php
use Omnischolar\Hal\HalSourceFactory;

$hal = (new HalSourceFactory($httpClient))->create(['domains' => []]);
```

Option `domains`: HAL domain codes every query is filtered on (`shs.droit`, `chim`,
`phys.cond`...); `Query::$domains` adds to them.

## Calls

| Method | Reads | HAL API |
|---|---|---|
| `author()` | idHAL, ORCID | `ref/author/?q=idHal_s:` / `orcidId_s:`: the preferred form of the name, the others as alternatives, ORCID, IdRef and ResearcherID pages |
| `works()` | idHAL (`authIdHal_s`), ORCID (`authORCIDIdExt_s`), a name (`authFullName_s`, exact) | `search/`, Solr `cursorMark` paging |
| `work()` | HAL id, DOI, arXiv id | `search/?q=halId_s:` / `doiId_s:` / `arxivId_s:` |
| `search()` | `text` (`text:`), `title` (`title_t`), `author` (`authFullName_t`) | `search/` |

`Query`: `from`/`to` (`producedDateY_i`), `types` (HAL document types: `ART`; `OUV`, `DOUV`;
`COUV`; `COMM`, `POSTER`; `UNDEFINED` for preprints; `THESE`, `HDR`...), `domains`
(`domainAllCode_s`), `openAccess`, `sort` (`newest`, `oldest`, `relevance`), `limit` (10 000 at
most), `cursor`.

## What a work holds

Title and subtitle, type, contributors (name, given and family names, idHAL, role: author,
editor - a `DOUV`'s authors are its editors -, translator, or HAL's own code), date
(`producedDate`), venue (journal with ISSNs and publisher, book title, or conference), volume,
issue, pages, abstract, language, keywords, domains (`domainAllCode_s`: `shs.droit`...), open
access, the HAL page, the deposited PDF (or the open-access link HAL records), licence;
identifiers: HAL, DOI, arXiv, PMID, ISBN.

## What HAL does not give

No citation counts, no metrics. The contributors' ORCIDs are not aligned with their names in the
search answers: a work's contributors carry their idHAL only. `works()` by ORCID finds only the
deposits where the ORCID was linked (24 of Keitaro Nakatani's 108); by name, only the exact form
signed. Some deposits are dated in the future (a forthcoming book).

## Tests

Recorded on 2026-10-04: two pages of Keitaro Nakatani's deposits (108), a search in
`shs.droit` (510 answers for "responsabilité civile" since 2024), a deposit by DOI, an author by
idHAL (remi-metivier).
