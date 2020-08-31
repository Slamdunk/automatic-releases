<?php

declare(strict_types=1);

namespace Laminas\AutomaticReleases\Test\Unit\Github\Api\V3;

use Laminas\AutomaticReleases\Git\Value\SemVerVersion;
use Laminas\AutomaticReleases\Github\Api\V3\CreateMilestoneFailed;
use Laminas\AutomaticReleases\Github\Api\V3\CreateMilestoneThroughApiCall;
use Laminas\AutomaticReleases\Github\Value\RepositoryName;
use Laminas\Diactoros\Request;
use Laminas\Diactoros\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;

use function uniqid;

class CreateMilestoneTest extends TestCase
{
    /** @var ClientInterface&MockObject */
    private $httpClient;
    /** @var RequestFactoryInterface&MockObject */
    private $messageFactory;
    /** @var LoggerInterface&MockObject */
    private $logger;
    /** @psalm-var non-empty-string */
    private string $apiToken;
    private CreateMilestoneThroughApiCall $createMilestone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->httpClient     = $this->createMock(ClientInterface::class);
        $this->messageFactory = $this->createMock(RequestFactoryInterface::class);
        $this->logger         = $this->createMock(LoggerInterface::class);
        $apiToken             = uniqid('apiToken', true);

        Assert::notEmpty($apiToken);

        $this->apiToken        = $apiToken;
        $this->createMilestone = new CreateMilestoneThroughApiCall(
            $this->messageFactory,
            $this->httpClient,
            $this->apiToken,
            $this->logger
        );
    }

    public function testSuccessfulRequest(): void
    {
        $this->messageFactory
            ->expects(self::any())
            ->method('createRequest')
            ->with('POST', 'https://api.github.com/repos/foo/bar/milestones')
            ->willReturn(new Request('https://the-domain.com/the-path'));

        $validResponse = (new Response())->withStatus(201);

        $validResponse->getBody()->write(
            <<<'JSON'
            {
                "html_url": "http://another-domain.com/the-pr"
            }
            JSON
        );

        $this->httpClient
            ->expects(self::once())
            ->method('sendRequest')
            ->with(self::callback(function (RequestInterface $request): bool {
                self::assertSame(
                    [
                        'Host'          => ['the-domain.com'],
                        'Content-Type'  => ['application/json'],
                        'User-Agent'    => ['Ocramius\'s minimal API V3 client'],
                        'Authorization' => ['token ' . $this->apiToken],
                    ],
                    $request->getHeaders()
                );

                self::assertJsonStringEqualsJsonString(
                    <<<'JSON'
                    {
                        "title": "1.2.3"
                    }
                    JSON,
                    $request->getBody()->__toString()
                );

                return true;
            }))
            ->willReturn($validResponse);

        $this->createMilestone->__invoke(
            RepositoryName::fromFullName('foo/bar'),
            SemVerVersion::fromMilestoneName('1.2.3')
        );
    }

    public function testExistingMilestone(): void
    {
        $this->messageFactory
            ->expects(self::any())
            ->method('createRequest')
            ->with('POST', 'https://api.github.com/repos/foo/bar/milestones')
            ->willReturn(new Request('https://the-domain.com/the-path'));

        $validResponse = (new Response())->withStatus(422);

        $validResponse->getBody()->write(
            <<<'JSON'
            {
                "documentation_url": "https://docs.github.com/rest/reference/issues#create-a-milestone",
                "errors": [
                    {
                        "code": "already_exists",
                        "field": "title",
                        "resource": "Milestone"
                    }
                ],
                "message": "Validation Failed"
            }
            JSON
        );

        $this->httpClient
            ->expects(self::once())
            ->method('sendRequest')
            ->with(self::callback(function (RequestInterface $request): bool {
                self::assertSame(
                    [
                        'Host'          => ['the-domain.com'],
                        'Content-Type'  => ['application/json'],
                        'User-Agent'    => ['Ocramius\'s minimal API V3 client'],
                        'Authorization' => ['token ' . $this->apiToken],
                    ],
                    $request->getHeaders()
                );

                self::assertJsonStringEqualsJsonString(
                    <<<'JSON'
                    {
                        "title": "1.2.3"
                    }
                    JSON,
                    $request->getBody()->__toString()
                );

                return true;
            }))
            ->willReturn($validResponse);

        $this->expectException(CreateMilestoneFailed::class);

        $this->createMilestone->__invoke(
            RepositoryName::fromFullName('foo/bar'),
            SemVerVersion::fromMilestoneName('1.2.3')
        );
    }
}