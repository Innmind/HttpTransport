<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\Success;
use Innmind\Http\Message\{
    Request,
    Response,
    StatusCode,
};
use Innmind\Immutable\Sequence;
use PHPUnit\Framework\TestCase;

class SuccessTest extends TestCase
{
    public function testAcceptSuccessfulResponses()
    {
        Sequence::of(...StatusCode::cases())
            ->filter(static fn($code) => $code->range() === StatusCode\Range::successful)
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
        Sequence::of(...StatusCode::cases())
            ->filter(static fn($code) => $code->range() !== StatusCode\Range::successful)
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
