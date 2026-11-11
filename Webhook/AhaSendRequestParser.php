<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Mailer\Bridge\AhaSend\Webhook;

use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\ChainRequestMatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\IsJsonRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\Mailer\Bridge\AhaSend\RemoteEvent\AhaSendPayloadConverter;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\RemoteEvent\Exception\ParseException;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Webhook\Client\AbstractRequestParser;
use Symfony\Component\Webhook\Exception\RejectWebhookException;

final class AhaSendRequestParser extends AbstractRequestParser
{
    private const TIMESTAMP_TOLERANCE = 300;
    private const MAILER_EVENTS = [
        'message.reception',
        'message.delivered',
        'message.transient_error',
        'message.failed',
        'message.bounced',
        'message.suppressed',
        'message.opened',
        'message.clicked',
        'message.complained',
        'message.unsubscribed',
    ];

    public function __construct(
        private readonly AhaSendPayloadConverter $converter,
        private readonly ?ClockInterface $clock = null,
    ) {
    }

    protected function getRequestMatcher(): RequestMatcherInterface
    {
        return new ChainRequestMatcher([
            new MethodRequestMatcher('POST'),
            new IsJsonRequestMatcher(),
        ]);
    }

    protected function doParse(Request $request, #[\SensitiveParameter] string $secret): ?RemoteEvent
    {
        if (!$secret) {
            throw new InvalidArgumentException('A non-empty secret is required.');
        }

        $eventID = $request->headers->get('webhook-id');
        $signature = $request->headers->get('webhook-signature');
        $timestamp = $request->headers->get('webhook-timestamp');
        if (empty($eventID) || empty($signature) || empty($timestamp)) {
            throw new RejectWebhookException(406, 'Signature is required.');
        }
        if (!is_numeric($timestamp) || \is_float($timestamp + 0) || (int) $timestamp != $timestamp || (int) $timestamp <= 0) {
            throw new RejectWebhookException(406, 'Invalid timestamp.');
        }
        if (abs(($this->clock?->now()->getTimestamp() ?? time()) - (int) $timestamp) > self::TIMESTAMP_TOLERANCE) {
            throw new RejectWebhookException(406, 'Timestamp is outside the allowed time window.');
        }
        $expectedSignature = $this->sign($eventID, $timestamp, $request->getContent(), $secret);
        if (!hash_equals($expectedSignature, $signature)) {
            throw new RejectWebhookException(406, 'Invalid signature');
        }
        $payload = $request->toArray();
        if (!isset($payload['type']) || !isset($payload['timestamp']) || !(isset($payload['data']))) {
            throw new RejectWebhookException(406, 'Payload is malformed.');
        }

        // AhaSend disables a webhook after 100 failed deliveries in a row, and asks receivers to accept
        // the events they don't handle, so the other events are passed on as plain remote events.
        if (\is_string($payload['type']) && !\in_array($payload['type'], self::MAILER_EVENTS, true)) {
            return new RemoteEvent($payload['type'], $eventID, $payload);
        }

        try {
            return $this->converter->convert($payload);
        } catch (ParseException $e) {
            throw new RejectWebhookException(406, $e->getMessage(), $e);
        }
    }

    private function sign(string $eventID, string $timestamp, string $payload, #[\SensitiveParameter] string $secret): string
    {
        $signaturePayload = "{$eventID}.{$timestamp}.{$payload}";
        $hash = hash_hmac('sha256', $signaturePayload, $secret);
        $signature = base64_encode(pack('H*', $hash));

        return "v1,{$signature}";
    }
}
