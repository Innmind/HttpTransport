<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\ServerError;
use Innmind\Http\Message\{
    Request,
    Response,
    StatusCode,
};
use PHPUnit\Framework\TestCase;

class ServerErrorTest extends TestCase
{
    public function testAcceptServerErrorfulResponses()
    {
        StatusCode::codes()
            ->values()
            ->filter(static fn($code) => $code >= 500 && $code < 600)
            ->map(static fn($code) => new StatusCode($code))
            ->foreach(function($code) {
                $request = $this->createMock(Request::class);
                $response = $this->createMock(Response::class);
                $response
                    ->method('statusCode')
                    ->willReturn($code);

                $serverError = new ServerError($request, $response);
                $this->assertSame($request, $serverError->request());
                $this->assertSame($response, $serverError->response());
            });
    }

    public function testRejectOtherKindOfResponse()
    {
        StatusCode::codes()
            ->values()
            ->filter(static fn($code) => $code < 500 || $code >= 600)
            ->map(static fn($code) => new StatusCode($code))
            ->foreach(function($code) {
                $request = $this->createMock(Request::class);
                $response = $this->createMock(Response::class);
                $response
                    ->method('statusCode')
                    ->willReturn($code);

                try {
                    new ServerError($request, $response);
                    $this->fail('it should throw');
                } catch (\Throwable $e) {
                    $this->assertInstanceOf(\LogicException::class, $e);
                }
            });
    }
}
