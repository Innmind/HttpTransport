<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\Success;
use Innmind\Http\Message\{
    Request,
    Response,
    StatusCode,
};
use PHPUnit\Framework\TestCase;

class SuccessTest extends TestCase
{
    public function testAcceptSuccessfulResponses()
    {
        StatusCode::codes()
            ->values()
            ->filter(static fn($code) => $code >= 200 && $code < 300)
            ->map(static fn($code) => new StatusCode($code))
            ->foreach(function($code) {
                $request = $this->createMock(Request::class);
                $response = $this->createMock(Response::class);
                $response
                    ->method('statusCode')
                    ->willReturn($code);

                $success = new Success($request, $response);
                $this->assertSame($request, $success->request());
                $this->assertSame($response, $success->response());
            });
    }

    public function testRejectOtherKindOfResponse()
    {
        StatusCode::codes()
            ->values()
            ->filter(static fn($code) => $code < 200 || $code >= 300)
            ->map(static fn($code) => new StatusCode($code))
            ->foreach(function($code) {
                $request = $this->createMock(Request::class);
                $response = $this->createMock(Response::class);
                $response
                    ->method('statusCode')
                    ->willReturn($code);

                try {
                    new Success($request, $response);
                    $this->fail('it should throw');
                } catch (\Throwable $e) {
                    $this->assertInstanceOf(\LogicException::class, $e);
                }
            });
    }
}
