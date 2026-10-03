<?php

namespace Omnischolar\Hal\Tests;

use Omnischolar\Exception\NotSupportedException;
use Omnischolar\Hal\HalSourceFactory;
use Omnischolar\Model\Contributor;
use Omnischolar\Model\Identifier;
use Omnischolar\Model\Scheme;
use Omnischolar\Model\Work;
use Omnischolar\Model\WorkType;
use Omnischolar\Source\Capability;
use Omnischolar\Source\Query;
use Omnischolar\Source\SourceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from api.archives-ouvertes.fr on 2026-10-04:
 * works-page1.json and works-page2.json (search/?fq=authFullName_s:"Keitaro Nakatani",
 * 25 a page, newest first, by cursorMark), search-droit.json
 * (q=text:(responsabilité civile), fq=domainAllCode_s:("shs.droit"), from 2024),
 * work-doi.json (q=doiId_s:"10.3762/bjoc.10.151"), author-idhal.json
 * (ref/author/?q=idHal_s:"remi-metivier").
 */
final class HalSourceTest extends TestCase
{
    private const NEXT = 'AoJwwL/LvawCJzUzNDYwMjQ=';

    /** @var list<array{string, array<string, list<string>>}> the path and the query of each call, repeated keys kept */
    private array $calls = [];

    private function source(array $options = []): SourceInterface
    {
        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            $query = [];
            foreach (explode('&', (string) parse_url($url, \PHP_URL_QUERY)) as $pair) {
                [$key, $value] = explode('=', $pair, 2) + [1 => ''];
                $query[rawurldecode($key)][] = rawurldecode($value);
            }
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $this->calls[] = [$path, $query];
            $q = $query['q'][0] ?? '';
            $fq = implode(' ', $query['fq'] ?? []);
            $fixture = match (true) {
                '/ref/author/' === $path && 'idHal_s:"remi-metivier"' === $q => 'author-idhal.json',
                '/search/' === $path && 'doiId_s:"10.3762/bjoc.10.151"' === $q => 'work-doi.json',
                '/search/' === $path && str_contains($fq, 'shs.droit') => 'search-droit.json',
                '/search/' === $path && str_contains($fq, 'Keitaro Nakatani') && '*' === $query['cursorMark'][0] => 'works-page1.json',
                '/search/' === $path && str_contains($fq, 'Keitaro Nakatani') && self::NEXT === $query['cursorMark'][0] => 'works-page2.json',
                default => null,
            };

            return new MockResponse($fixture ? (string) file_get_contents(__DIR__.'/Fixtures/'.$fixture) : '{"response":{"numFound":0,"start":0,"docs":[]},"nextCursorMark":"*"}');
        });

        return (new HalSourceFactory($http))->create($options);
    }

    public function testAnAuthorsDepositsByTheNameTheySign(): void
    {
        $source = $this->source();
        $page = $source->works('Keitaro Nakatani', new Query(limit: 25));

        self::assertSame(108, $page->total);
        self::assertCount(25, $page);
        self::assertSame(self::NEXT, $page->next);
        [$path, $query] = $this->calls[0];
        self::assertSame('/search/', $path);
        self::assertSame(['authFullName_s:"Keitaro Nakatani"'], $query['fq'], 'a filter, repeated as Solr reads it');
        self::assertSame('producedDate_tdate desc,docid asc', $query['sort'][0]);

        $works = [...$page->works, ...$source->works('Keitaro Nakatani', new Query(limit: 25, cursor: $page->next))->works];
        self::assertCount(50, $works);

        $acid = $this->find($works, 'hal-04772417');
        self::assertSame(WorkType::ARTICLE, $acid->type);
        self::assertSame('10.1039/d4sc04973j', $acid->doi());
        self::assertSame('Chemical Science', $acid->venue->name);
        self::assertSame('2024-09-25', $acid->date);
        self::assertTrue($acid->openAccess);
        self::assertSame('https://hal.science/hal-04772417/document', $acid->pdfUrl, 'the full text deposited');
        self::assertSame('hal', $acid->source);

        $talk = $this->find($works, 'hal-05366361');
        self::assertSame(WorkType::CONFERENCE, $talk->type, 'a communication');
        self::assertSame('La recherche comme levier pour mieux enseigner : Innovations et résultats en éducation aux sciences', $talk->venue->name);
        self::assertSame(['Jonathan Piard', 'Keitaro Nakatani'], array_map(static fn (Contributor $c) => $c->name, $talk->authors));
        self::assertSame('jonathan-piard', $talk->authors[0]->identifiers->value(Scheme::IDHAL));
        self::assertSame('Nakatani', $talk->authors[1]->family);

        self::assertSame(WorkType::CONFERENCE, $this->find($works, 'hal-05366363')->type, 'a poster too');
        self::assertSame(WorkType::CHAPTER, $this->find($works, 'hal-04065740')->type);
    }

    public function testAnIdHalOrAnOrcidFilterOnTheirField(): void
    {
        $source = $this->source();
        $source->works('keitaro-nakatani');
        $source->works(Identifier::orcid('0009-0005-1387-7295'), new Query(from: 2020, to: 2024, types: [WorkType::ARTICLE, WorkType::CONFERENCE], openAccess: true));

        self::assertSame(['authIdHal_s:"keitaro-nakatani"'], $this->calls[0][1]['fq']);
        self::assertSame([
            'authORCIDIdExt_s:"0009-0005-1387-7295"',
            'producedDateY_i:[2020 TO 2024]',
            'docType_s:(ART OR COMM OR POSTER OR PRESCONF)',
            'openAccess_bool:true',
        ], $this->calls[1][1]['fq']);
    }

    public function testTheLegalScholarshipThroughTheDroitDomain(): void
    {
        $page = $this->source(['domains' => ['shs.droit']])->search(new Query(text: 'responsabilité civile', from: 2024, limit: 5));

        self::assertSame(510, $page->total);
        self::assertCount(5, $page);
        foreach ($page as $work) {
            self::assertContains('shs.droit', $work->domains);
        }
        [, $query] = $this->calls[0];
        self::assertSame('text:(responsabilité civile)', $query['q'][0]);
        self::assertSame(['domainAllCode_s:("shs.droit")', 'producedDateY_i:[2024 TO *]'], $query['fq']);
        self::assertContains(Capability::DOMAINS, $this->source()->capabilities());

        $book = array_values(array_filter($page->works, static fn (Work $w) => WorkType::BOOK === $w->type));
        self::assertNotEmpty($book, 'an OUV is a book');
    }

    public function testADomainAskedByTheQuery(): void
    {
        $this->source()->search(new Query(text: 'responsabilité civile', domains: ['shs.droit']));
        self::assertSame(['domainAllCode_s:("shs.droit")'], $this->calls[0][1]['fq']);
    }

    public function testADepositByItsDoi(): void
    {
        $work = $this->source()->work(Identifier::doi('10.3762/bjoc.10.151'));

        self::assertSame('Multichromophoric sugar for fluorescence photoswitching', $work->title);
        self::assertSame('hal-02489392', $work->id(Scheme::HAL));
        self::assertSame(['1860-5397'], $work->venue->issn);
        self::assertSame('Beilstein-Institut', $work->venue->publisher);
        self::assertSame(['chim.orga'], $work->domains);
        self::assertSame('https://hal.science/hal-02489392/document', $work->pdfUrl);
        self::assertStringStartsWith('A multichromophoric glucopyranoside', $work->abstract);
        self::assertNull($this->source()->work('hal-09999999'));
    }

    public function testAnAuthorByIdHal(): void
    {
        $author = $this->source()->author('remi-metivier');

        self::assertSame('Rémi Métivier', $author->name, 'the preferred form');
        self::assertSame('0000-0001-5612-8327', $author->orcid());
        self::assertSame('remi-metivier', $author->identifiers->value(Scheme::IDHAL));
        self::assertContains('R. Métivier', $author->alternativeNames);
        self::assertSame('https://cv.hal.science/remi-metivier', $author->urls[0]);
    }

    public function testWhatItCannotRead(): void
    {
        $this->expectException(NotSupportedException::class);
        $this->source()->work('A5108007452');
    }

    /** @param list<Work> $works */
    private function find(array $works, string $hal): Work
    {
        foreach ($works as $work) {
            if ($hal === $work->id(Scheme::HAL)) {
                return $work;
            }
        }
        self::fail($hal.' not found');
    }
}
