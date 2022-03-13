<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\ClientError;
use Innmind\Http\Message\{
    Request,
    Response,
    StatusCode,
};
use Innmind\Immutable\Sequence;
use PHPUnit\Framework\TestCase;

class ClientErrorTest extends TestCase
{
    public function testAcceptClientErrorfulResponses()
    {
        Sequence::of(...StatusCode::cases())
            ->filter(static fn($code) => $code->range() === StatusCode\Range::clientError)
            ->foreach(function($code) {
                $request = $this->createMock(Request::class);
                $response = $this->createMock(Response::class);
                $response
                    ->method('statusCode')
                    ->willReturn($code);

                $clientError = new ClientError($request, $response);
                $this->assertSame($request, $clientError->request());
                $this->assertSame($response, $clientError->response());
            });
    }

    public function testRejectOtherKindOfResponse()
    {
        Sequence::of(...StatusCode::cases())
            ->filter(static fn($code) => $code->range() !== StatusCode\Range::clientError)
            ->foreach(function($code) {
                $request = $this->createMock(Request::class);
                $response = $this->createMock(Response::class);
                $response
                    ->method('statusCode')
                    ->willReturn($code);

                try {
                    new ClientError($request, $response);
                    $this->fail('it should throw');
                } catch (\Throwable $e) {
                    $this->assertInstanceOf(\LogicException::class, $e);
                }
            });
    }
}