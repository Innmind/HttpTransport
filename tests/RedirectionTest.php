<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\Redirection;
use Innmind\Http\Message\{
    Request,
    Response,
    StatusCode,
};
use PHPUnit\Framework\TestCase;

class RedirectionTest extends TestCase
{
    public function testAcceptRedirectionfulResponses()
    {
        StatusCode::codes()
            ->values()
            ->filter(static fn($code) => $code >= 300 && $code < 400)
            ->map(static fn($code) => new StatusCode($code))
            ->foreach(function($code) {
                $request = $this->createMock(Request::class);
                $response = $this->createMock(Response::class);
                $response
                    ->method('statusCode')
                    ->willReturn($code);

                $redirection = new Redirection($request, $response);
                $this->assertSame($request, $redirection->request());
                $this->assertSame($response, $redirection->response());
            });
    }

    public function testRejectOtherKindOfResponse()
    {
        StatusCode::codes()
            ->values()
            ->filter(static fn($code) => $code < 300 || $code >= 400)
            ->map(static fn($code) => new StatusCode($code))
            ->foreach(function($code) {
                $request = $this->createMock(Request::class);
                $response = $this->createMock(Response::class);
                $response
                    ->method('statusCode')
                    ->willReturn($code);

                try {
                    new Redirection($request, $response);
                    $this->fail('it should throw');
                } catch (\Throwable $e) {
                    $this->assertInstanceOf(\LogicException::class, $e);
                }
            });
    }
}
