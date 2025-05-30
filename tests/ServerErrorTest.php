<?php
declare(strict_types = 1);

namespace Tests\Innmind\HttpTransport;

use Innmind\HttpTransport\ServerError;
use Innmind\Http\{
    Request,
    Response,
    Method,
    ProtocolVersion,
    Response\StatusCode,
};
use Innmind\Url\Url;
use Innmind\Immutable\Sequence;
use Innmind\BlackBox\PHPUnit\Framework\TestCase;

class ServerErrorTest extends TestCase
{
    public function testAcceptServerErrorfulResponses()
    {
        Sequence::of(...StatusCode::cases())
            ->filter(static fn($code) => $code->range() === StatusCode\Range::serverError)
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

                $serverError = new ServerError($request, $response);
                $this->assertSame($request, $serverError->request());
                $this->assertSame($response, $serverError->response());
            });
    }

    public function testRejectOtherKindOfResponse()
    {
        Sequence::of(...StatusCode::cases())
            ->filter(static fn($code) => $code->range() !== StatusCode\Range::serverError)
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
                    new ServerError($request, $response);
                    $this->fail('it should throw');
                } catch (\Throwable $e) {
                    $this->assertInstanceOf(\LogicException::class, $e);
                }
            });
    }
}
