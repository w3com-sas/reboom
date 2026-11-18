<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Stopwatch\Stopwatch;
use W3com\BoomBundle\RestClient\SLRestClient;
use W3com\BoomBundle\Service\BoomManager;
use W3com\BoomBundle\Utils\RawRequest;

final class RawRequestTest extends TestCase
{
    public function testResultsAndFlatteningAreUpdatedWhenReusingRequest(): void
    {
        $raw = new RawRequest('Query');
        $first = '{"odata.metadata":"metadata","value":[{"A":{"Code":"one"},"B":{"Name":"First"}}]}';
        $raw->handleResponse($first);
        self::assertSame($first, $raw->getResponse());
        self::assertSame([['Code' => 'one', 'Name' => 'First']], $raw->getFlattenedResults());
        $raw->handleResponse('{"odata.metadata":"metadata","value":[{"A":{"Code":"two"}}]}');
        self::assertSame([['Code' => 'two']], $raw->getFlattenedResults());
        $raw->handleResponse('{"odata.metadata":"metadata","odata.etag":"tag","Code":"one"}');
        self::assertSame('one', $raw->getResults()['Code']);
        $raw->handleResponse(null);
        self::assertSame([], $raw->getResults());
        self::assertSame('', $raw->getResponse());
    }

    /** @dataProvider invalidResponses */
    public function testInvalidResponsesAreNotSilentlyAccepted(string $response): void
    {
        $this->expectException(UnexpectedValueException::class);
        (new RawRequest('Query'))->handleResponse($response);
    }

    public static function invalidResponses(): array
    {
        return [['not json'], ['null'], ['42'], ['{"odata.metadata":"meta"}'], ['{"odata.metadata":"meta","value":null}'], ['{"unexpected":true}']];
    }

    public function testRequestSendsHeadersAndKeepsRawResponse(): void
    {
        $history = [];
        $body = '{"odata.metadata":"metadata","value":[{"CardCode":"OLD001"}]}';
        $manager = $this->manager([new Response(200, [], $body)], $history);
        $raw = new RawRequest('BusinessPartners?$select=CardCode');
        $manager->rawRequestNew($raw);
        self::assertSame([['CardCode' => 'OLD001']], $raw->getResults());
        self::assertSame($body, $raw->getResponse());
        self::assertSame('GET', $history[0]['request']->getMethod());
        self::assertSame('odata.maxpagesize=10000', $history[0]['request']->getHeaderLine('Prefer'));
        self::assertSame('no-cache', $history[0]['request']->getHeaderLine('cache-control'));
        self::assertSame($body, $manager->getCollectedData()[0]['response']);
    }

    public function testUnauthorizedRequestLogsInAndRetries(): void
    {
        $history = [];
        $manager = $this->manager([new Response(401), new Response(200, [], '{"odata.metadata":"m","value":[]}')], $history, 2);
        $sl = $this->createMock(SLRestClient::class);
        $sl->expects(self::once())->method('login');
        $manager->restClients['sl'] = $sl;
        $raw = new RawRequest('Items');
        $manager->rawRequestNew($raw);
        self::assertCount(2, $history);
        self::assertSame([], $raw->getResults());
    }

    public function testUnauthorizedAfterLastAttemptThrows(): void
    {
        $history = [];
        $manager = $this->manager([new Response(401), new Response(401)], $history, 2);
        $sl = $this->createMock(SLRestClient::class);
        $sl->expects(self::once())->method('login');
        $manager->restClients['sl'] = $sl;
        $this->expectException(ClientException::class);
        $manager->rawRequestNew(new RawRequest('Items'));
    }

    public function testExpiredCookieIsRemovedBeforeRetry(): void
    {
        $history = [];
        $manager = $this->manager([new Response(400, [], '{"error": {"code": -304}}'), new Response(200, [], '{"odata.metadata":"m","value":[]}')], $history, 2);
        $manager->expects(self::once())->method('removeLastCookieFile');
        $sl = $this->createMock(SLRestClient::class);
        $sl->expects(self::once())->method('login');
        $manager->restClients['sl'] = $sl;
        $manager->rawRequestNew(new RawRequest('Items'));
        self::assertCount(2, $history);
    }

    public function testNonAuthenticationErrorIsNotRetried(): void
    {
        $history = [];
        $manager = $this->manager([new Response(400, [], 'invalid query')], $history, 2);
        $sl = $this->createMock(SLRestClient::class);
        $sl->expects(self::never())->method('login');
        $manager->restClients['sl'] = $sl;
        try {
            $manager->rawRequestNew(new RawRequest('Items'));
            self::fail('An HTTP 400 response should throw.');
        } catch (ClientException $e) {
            self::assertSame(400, $e->getCode());
            self::assertCount(1, $history);
        }
    }

    public function testBadGatewayRetriesAfterLogin(): void
    {
        $history = [];
        $manager = $this->manager([new Response(502), new Response(200, [], '{"odata.metadata":"m","value":[]}')], $history, 2);
        $sl = $this->createMock(SLRestClient::class);
        $sl->expects(self::once())->method('login');
        $manager->restClients['sl'] = $sl;
        $manager->rawRequestNew(new RawRequest('Items'));
        self::assertCount(2, $history);
    }

    public function testInvalidResponseThrowsFromManager(): void
    {
        $history = [];
        $manager = $this->manager([new Response(200, [], 'invalid json')], $history);
        $this->expectException(UnexpectedValueException::class);
        $manager->rawRequestNew(new RawRequest('Items'));
    }

    public function testNotFoundProducesEmptyResults(): void
    {
        $history = [];
        $manager = $this->manager([new Response(404)], $history);
        $raw = new RawRequest('Items');
        $manager->rawRequestNew($raw);
        self::assertSame([], $raw->getResults());
        self::assertSame(404, $manager->getCollectedData()[0]['code']);
    }

    private function manager(array $responses, array &$history, int $attempts = 1): BoomManager
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        $manager = $this->getMockBuilder(BoomManager::class)->disableOriginalConstructor()->onlyMethods(['getCurrentSLClient', 'removeLastCookieFile'])->getMock();
        $manager->method('getCurrentSLClient')->willReturn(new Client(['handler' => $stack, 'base_uri' => 'https://example.test/']));
        $manager->config = ['service_layer' => ['max_login_attempts' => $attempts]];
        $manager->logger = new NullLogger();
        $manager->stopwatch = new Stopwatch();
        return $manager;
    }
}
