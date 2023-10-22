<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\Success;
use Innmind\Http\{
    Request,
    Response,
    Method,
    ProtocolVersion,
    Response\StatusCode,
};
use Innmind\Url\Url;
use Innmind\Immutable\Sequence;
use PHPUnit\Framework\TestCase;

class SuccessTest extends TestCase
{
    public function testAcceptSuccessfulResponses()
    {
        Sequence::of(...StatusCode::cases())
            ->filter(static fn($code) => $code->range() === StatusCode\Range::successful)
            ->foreach(function($code) {
                $request = Request::of(
                    Url::of('/'),
                    Method::get,
                    ProtocolVersion::v11,
                );
                $response = Response::of(
                    $code,
                    $request->protocolVersion(),
                );

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
                $request = Request::of(
                    Url::of('/'),
                    Method::get,
                    ProtocolVersion::v11,
                );
                $response = Response::of(
                    $code,
                    $request->protocolVersion(),
                );

                try {
                    new Success($request, $response);
                    $this->fail('it should throw');
                } catch (\Throwable $e) {
                    $this->assertInstanceOf(\LogicException::class, $e);
                }
            });
    }
}
