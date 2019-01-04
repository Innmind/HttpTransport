<?php
declare(strict_types = 1);

namespace Innmind\HttpTransport;

use Innmind\Http\{
    Message\Request,
    Message\Response,
    Translator\Response\Psr7Translator
};
use GuzzleHttp\Exception\BadResponseException;

final class CatchGuzzleBadResponseExceptionTransport implements Transport
{
    private $fulfill;
    private $translator;

    public function __construct(
        Transport $fulfill,
        Psr7Translator $translator
    ) {
        $this->fulfill = $fulfill;
        $this->translator = $translator;
    }

    public function __invoke(Request $request): Response
    {
        try {
            return ($this->fulfill)($request);
        } catch (BadResponseException $e) {
            if ($e->hasResponse()) {
                return $this->translator->translate($e->getResponse());
            }

            throw $e;
        }
    }
}
