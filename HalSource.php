<?php

namespace Omnischolar\Hal;

use Omnischolar\Exception\NotSupportedException;
use Omnischolar\Model\Author;
use Omnischolar\Model\Contributor;
use Omnischolar\Model\Identifier;
use Omnischolar\Model\Identifiers;
use Omnischolar\Model\Scheme;
use Omnischolar\Model\Venue;
use Omnischolar\Model\Work;
use Omnischolar\Model\WorkType;
use Omnischolar\Source\Capability;
use Omnischolar\Source\HttpSource;
use Omnischolar\Source\Page;
use Omnischolar\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HAL, the French open archive (api.archives-ouvertes.fr, no key): the
 * deposits of an author - by idHAL, ORCID or the name as signed - with
 * their full texts in open access; a deposit by its HAL id, DOI or arXiv
 * id; a search; and, for every query, a filter by domain: "shs.droit" is
 * the legal scholarship (doctrine) HAL holds.
 */
final class HalSource extends HttpSource
{
    public const BASE_URI = 'https://api.archives-ouvertes.fr/';

    /** The most rows HAL serves per page. */
    public const MAX_ROWS = 10000;

    private const FIELDS = 'docid,halId_s,uri_s,docType_s,title_s,subTitle_s,authFullNamePersonIDIDHal_fs,authFirstName_s,authLastName_s,authQuality_s,producedDate_s,producedDateY_i,journalTitle_s,journalIssn_s,journalEissn_s,journalPublisher_s,publisher_s,bookTitle_s,conferenceTitle_s,volume_s,issue_s,page_s,doiId_s,arxivId_s,pubmedId_s,isbn_s,abstract_s,keyword_s,domainAllCode_s,language_s,openAccess_bool,fileMain_s,linkExtId_s,linkExtUrl_s,licence_s';

    /** HAL's document types for each kind. */
    private const TYPES = [
        'article' => ['ART'],
        'book' => ['OUV', 'DOUV', 'PROCEEDINGS', 'ISSUE'],
        'chapter' => ['COUV', 'NOTICE', 'CREPORT'],
        'conference' => ['COMM', 'POSTER', 'PRESCONF'],
        'preprint' => ['UNDEFINED'],
        'thesis' => ['THESE', 'HDR', 'MEM', 'ETABTHESE'],
        'report' => ['REPORT', 'REPACT', 'SYNTHESE'],
        'dataset' => [],
        'software' => ['SOFTWARE'],
        'review' => ['NOTE'],
        'editorial' => [],
        'other' => ['OTHER', 'PATENT', 'LECTURE', 'BLOG', 'TRAD', 'IMG', 'VIDEO', 'SON', 'MAP'],
    ];

    /**
     * @param list<string> $domains a filter on every query: HAL domain codes (shs.droit, chim, phys.cond...)
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly array $domains = [],
        string $baseUri = self::BASE_URI,
        array $headers = [],
    ) {
        parent::__construct($http, $baseUri, $headers);
    }

    public function getName(): string
    {
        return 'hal';
    }

    public function capabilities(): array
    {
        return [Capability::AUTHOR, Capability::WORKS, Capability::WORK, Capability::SEARCH, Capability::ABSTRACTS, Capability::OPEN_ACCESS, Capability::DOMAINS];
    }

    /** An author by their idHAL or ORCID: the preferred form of their name, the others as alternatives. */
    public function author(Identifier|string $author): ?Author
    {
        $id = self::identify($author, Scheme::IDHAL, Scheme::ORCID) ?? throw NotSupportedException::identifier('hal', (string) $author);
        $q = Scheme::IDHAL === $id->scheme ? 'idHal_s:'.self::quote($id->value) : 'orcidId_s:'.self::quote('https://orcid.org/'.$id->value);
        $docs = $this->getJson('ref/author/', ['q' => $q, 'wt' => 'json', 'rows' => 100, 'fl' => 'person_i,firstName_s,lastName_s,fullName_s,valid_s,idHal_s,orcidId_s,idrefId_s,researcheridId_s'])['response']['docs'] ?? [];
        if (!$docs) {
            return null;
        }
        usort($docs, static fn (array $a, array $b) => ('PREFERRED' === ($b['valid_s'] ?? null)) <=> ('PREFERRED' === ($a['valid_s'] ?? null)));
        $preferred = $docs[0];
        $names = array_values(array_unique(array_map(static fn (array $d) => (string) $d['fullName_s'], $docs)));

        $identifiers = [Identifier::tryOf(Scheme::IDHAL, $preferred['idHal_s'] ?? null)];
        foreach ($docs as $doc) {
            foreach ($doc['orcidId_s'] ?? [] as $orcid) {
                $identifiers[] = Identifier::tryOf(Scheme::ORCID, $orcid);
            }
        }
        $urls = [];
        foreach ([...($preferred['idrefId_s'] ?? []), ...($preferred['researcheridId_s'] ?? [])] as $url) {
            $urls[] = (string) $url;
        }
        if (isset($preferred['idHal_s'])) {
            array_unshift($urls, 'https://cv.hal.science/'.$preferred['idHal_s']);
        }

        return new Author(
            name: (string) $preferred['fullName_s'],
            given: $preferred['firstName_s'] ?? null,
            family: $preferred['lastName_s'] ?? null,
            identifiers: new Identifiers($identifiers),
            alternativeNames: array_values(array_diff($names, [$preferred['fullName_s']])),
            urls: array_values(array_unique($urls)),
            source: 'hal',
        );
    }

    /**
     * An author's deposits: by idHAL ("keitaro-nakatani"), ORCID (only the
     * deposits where the ORCID was linked) or the name as signed
     * ("Keitaro Nakatani", exact).
     */
    public function works(Identifier|string $author, ?Query $query = null): Page
    {
        $id = self::identify($author, Scheme::IDHAL, Scheme::ORCID);
        $filter = match ($id?->scheme) {
            Scheme::IDHAL => 'authIdHal_s:'.self::quote($id->value),
            Scheme::ORCID => 'authORCIDIdExt_s:'.self::quote($id->value),
            default => 'authFullName_s:'.self::quote(\is_string($author) ? trim($author) : (string) $author->value),
        };

        return $this->list($query ?? new Query(), '*:*', [$filter]);
    }

    public function work(Identifier|string $id): ?Work
    {
        $identifier = self::identify($id, Scheme::HAL, Scheme::DOI, Scheme::ARXIV) ?? throw NotSupportedException::identifier('hal', (string) $id);
        $field = match ($identifier->scheme) {
            Scheme::HAL => 'halId_s',
            Scheme::DOI => 'doiId_s',
            default => 'arxivId_s',
        };
        $docs = $this->getJson('search/', ['q' => $field.':'.self::quote($identifier->value), 'wt' => 'json', 'rows' => 1, 'fl' => self::FIELDS])['response']['docs'] ?? [];

        return $docs ? $this->toWork($docs[0]) : null;
    }

    public function search(Query $query): Page
    {
        $q = [];
        if (null !== $query->text && '' !== trim($query->text)) {
            $q[] = 'text:('.self::escape($query->text).')';
        }
        if (null !== $query->title) {
            $q[] = 'title_t:('.self::escape($query->title).')';
        }
        if (null !== $query->author) {
            $q[] = 'authFullName_t:'.self::quote($query->author);
        }

        return $this->list($query, $q ? implode(' AND ', $q) : '*:*', []);
    }

    /** @param list<string> $filters */
    private function list(Query $query, string $q, array $filters): Page
    {
        $domains = array_values(array_unique([...$this->domains, ...$query->domains]));
        if ($domains) {
            $filters[] = 'domainAllCode_s:('.implode(' OR ', array_map(self::quote(...), $domains)).')';
        }
        if (null !== $query->from || null !== $query->to) {
            $filters[] = \sprintf('producedDateY_i:[%s TO %s]', $query->from ?? '*', $query->to ?? '*');
        }
        if ($query->types) {
            $types = array_merge(...array_map(static fn (WorkType $t) => self::TYPES[$t->value], $query->types));
            $filters[] = $types ? 'docType_s:('.implode(' OR ', array_unique($types)).')' : '-docid:*';
        }
        if (null !== $query->openAccess) {
            $filters[] = 'openAccess_bool:'.($query->openAccess ? 'true' : 'false');
        }
        $sort = match ($query->sort) {
            Query::OLDEST => 'producedDate_tdate asc',
            Query::RELEVANCE => 'score desc',
            default => 'producedDate_tdate desc',
        };
        $cursor = $query->cursor ?? '*';
        $data = $this->getJson('search/', [
            'q' => $q,
            'fq' => $filters,
            'wt' => 'json',
            'rows' => max(1, min(self::MAX_ROWS, $query->limit)),
            'sort' => $sort.',docid asc',
            'cursorMark' => $cursor,
            'fl' => self::FIELDS,
        ]) ?? [];
        $works = array_map($this->toWork(...), $data['response']['docs'] ?? []);
        $next = $data['nextCursorMark'] ?? null;

        return new Page($works, $works && null !== $next && $next !== $cursor ? $next : null, $data['response']['numFound'] ?? null);
    }

    /** @param array<string, mixed> $d */
    private function toWork(array $d): Work
    {
        $code = (string) ($d['docType_s'] ?? 'OTHER');
        $type = WorkType::OTHER;
        foreach (self::TYPES as $kind => $codes) {
            if (\in_array($code, $codes, true)) {
                $type = WorkType::from($kind);
                break;
            }
        }

        $authors = [];
        $first = $d['authFirstName_s'] ?? [];
        $last = $d['authLastName_s'] ?? [];
        $quality = $d['authQuality_s'] ?? [];
        foreach ($d['authFullNamePersonIDIDHal_fs'] ?? [] as $i => $facet) {
            [$name, , $idhal] = explode('_FacetSep_', (string) $facet) + [1 => null, 2 => null];
            $role = match ($quality[$i] ?? 'aut') {
                'aut', 'crp' => Contributor::AUTHOR,
                'edt' => Contributor::EDITOR,
                'trl' => Contributor::TRANSLATOR,
                default => (string) $quality[$i],
            };
            if ('DOUV' === $code && Contributor::AUTHOR === $role) {
                $role = Contributor::EDITOR;
            }
            $authors[] = new Contributor((string) $name, $first[$i] ?? null, $last[$i] ?? null, Identifiers::of(Identifier::tryOf(Scheme::IDHAL, $idhal ?: null)), $role);
        }

        $venue = match (true) {
            isset($d['journalTitle_s']) => new Venue(
                name: (string) $d['journalTitle_s'],
                type: Venue::JOURNAL,
                issn: array_values(array_filter([Identifier::tryOf(Scheme::ISSN, $d['journalIssn_s'] ?? null)?->value, Identifier::tryOf(Scheme::ISSN, $d['journalEissn_s'] ?? null)?->value])),
                publisher: $d['journalPublisher_s'] ?? null,
            ),
            isset($d['bookTitle_s']) && WorkType::BOOK !== $type => new Venue((string) $d['bookTitle_s'], Venue::BOOK, publisher: $d['publisher_s'][0] ?? null),
            isset($d['conferenceTitle_s']) => new Venue((string) $d['conferenceTitle_s'], Venue::CONFERENCE),
            default => null,
        };

        $identifiers = [
            Identifier::tryOf(Scheme::HAL, $d['halId_s'] ?? null),
            Identifier::tryOf(Scheme::DOI, $d['doiId_s'] ?? null),
            Identifier::tryOf(Scheme::ARXIV, $d['arxivId_s'] ?? null),
            Identifier::tryOf(Scheme::PMID, $d['pubmedId_s'] ?? null),
        ];
        foreach ((array) ($d['isbn_s'] ?? []) as $isbn) {
            $identifiers[] = Identifier::tryOf(Scheme::ISBN, (string) $isbn);
        }

        $pdf = $d['fileMain_s'] ?? (('openaccess' === ($d['linkExtId_s'] ?? null) || 'arxiv' === ($d['linkExtId_s'] ?? null)) ? ($d['linkExtUrl_s'] ?? null) : null);
        $year = $d['producedDateY_i'] ?? null;

        return new Work(
            title: self::text($d['title_s'][0] ?? null) ?? '',
            type: $type,
            authors: $authors,
            year: null !== $year ? (int) $year : null,
            date: isset($d['producedDate_s']) ? substr((string) $d['producedDate_s'], 0, 10) : null,
            subtitle: self::text($d['subTitle_s'][0] ?? null),
            venue: $venue,
            publisher: \in_array($type, [WorkType::BOOK, WorkType::CHAPTER, WorkType::REPORT], true) ? ($d['publisher_s'][0] ?? null) : null,
            volume: $d['volume_s'] ?? null,
            issue: isset($d['issue_s']) ? (string) ((array) $d['issue_s'])[0] : null,
            pages: $d['page_s'] ?? null,
            abstract: self::text($d['abstract_s'][0] ?? null),
            language: $d['language_s'][0] ?? null,
            openAccess: $d['openAccess_bool'] ?? null,
            url: $d['uri_s'] ?? null,
            pdfUrl: $pdf,
            license: $d['licence_s'] ?? null,
            keywords: array_values(array_map('strval', $d['keyword_s'] ?? [])),
            domains: array_values(array_map('strval', $d['domainAllCode_s'] ?? [])),
            identifiers: new Identifiers($identifiers),
            source: 'hal',
        );
    }

    private static function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\"'], $value).'"';
    }

    /** Free text for a Solr query: its operators' characters escaped. */
    private static function escape(string $value): string
    {
        return (string) preg_replace('#([+\-!(){}\[\]^"~*?:\\\\/]|&&|\|\|)#', '\\\\$1', $value);
    }
}
