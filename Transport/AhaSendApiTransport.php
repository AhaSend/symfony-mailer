<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Mailer\Bridge\AhaSend\Transport;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Bridge\AhaSend\Event\AhaSendDeliveryEvent;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\IncompleteDsnException;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\Header\TrackingHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @author Farhad Hedayatifard <farhad@ahasend.com>
 */
final class AhaSendApiTransport extends AbstractApiTransport
{
    private const HOST = 'api.ahasend.com';
    private const CONVERSATION_RECIPIENT_LIMIT = 50;
    private const MAX_RETENTION_DAYS = 30;

    private bool $legacy;

    public function __construct(
        #[\SensitiveParameter] private readonly string $apiKey,
        ?HttpClientInterface $client = null,
        private ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
        private readonly ?string $accountId = null,
    ) {
        parent::__construct($client, $dispatcher, $logger);

        $this->legacy = !$accountId;

        if ($this->legacy && str_starts_with($apiKey, 'aha-sk-')) {
            throw new IncompleteDsnException('A v2 AhaSend API key requires an account id: use the "ahasend+api://API_KEY:ACCOUNT_ID@default" DSN.');
        }
    }

    public function __toString(): string
    {
        return \sprintf('ahasend+api://%s', $this->getEndpoint());
    }

    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        if ($this->legacy) {
            return $this->doSendLegacyApi($sentMessage, $email, $envelope);
        }

        $payload = $this->getPayload($email, $envelope);
        $path = isset($payload['recipients']) ? '/messages' : '/messages/conversation';

        $response = $this->client->request('POST', 'https://'.$this->getEndpoint().'/v2/accounts/'.$this->accountId.$path, [
            'json' => $payload,
            'headers' => [
                'Authorization' => 'Bearer '.$this->apiKey,
            ],
        ]);

        try {
            $statusCode = $response->getStatusCode();
            $result = $response->toArray(false);
        } catch (DecodingExceptionInterface) {
            throw new HttpTransportException('Unable to send an email: '.$response->getContent(false).\sprintf(' (code %d).', $response->getStatusCode()), $response);
        } catch (TransportExceptionInterface $e) {
            throw new HttpTransportException('Could not reach the remote AhaSend server.', $response, 0, $e);
        }

        if (202 !== $statusCode) {
            throw new HttpTransportException('Unable to send an email: '.$response->getContent(false).\sprintf(' (code %d).', $statusCode), $response);
        }

        // One entry per recipient. Responses from before "message_id_header" was added have the Message-ID header in "id".
        // The angle brackets are removed so that the ID matches the one of the remote events.
        $messageId = null;
        foreach ($result['data'] ?? [] as $message) {
            $header = \array_key_exists('message_id_header', $message) ? $message['message_id_header'] : ($message['id'] ?? null);
            if (null === $messageId && $header) {
                $messageId = self::withoutAngleBrackets($header);
            }
            if (null !== $this->dispatcher && !empty($message['error'])) {
                $this->dispatcher->dispatch(new AhaSendDeliveryEvent($message['error']));
            }
        }
        if (null !== $messageId) {
            $sentMessage->setMessageId($messageId);
        }

        return $response;
    }

    private function doSendLegacyApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        trigger_deprecation('ahasend/symfony-mailer', '1.0','Sending through the legacy AhaSend v1 API is deprecated, use a v2 API key and add your account id to the DSN.');

        $response = $this->client->request('POST', 'https://'.$this->getEndpoint().'/v1/email/send', [
            'json' => $this->getLegacyPayload($email, $envelope),
            'headers' => [
                'X-Api-Key' => $this->apiKey,
            ],
        ]);

        try {
            $statusCode = $response->getStatusCode();
            $result = $response->toArray(false);
        } catch (DecodingExceptionInterface) {
            throw new HttpTransportException('Unable to send an email: '.$response->getContent(false).\sprintf(' (code %d).', $response->getStatusCode()), $response);
        } catch (TransportExceptionInterface $e) {
            throw new HttpTransportException('Could not reach the remote AhaSend server.', $response, 0, $e);
        }

        if (201 !== $statusCode) {
            throw new HttpTransportException('Unable to send an email: '.$response->getContent(false).\sprintf(' (code %d).', $statusCode), $response);
        }

        if (null !== $this->dispatcher && \array_key_exists('fail_count', $result) && $result['fail_count'] > 0) {
            foreach ($result['errors'] as $error) {
                $this->dispatcher->dispatch(new AhaSendDeliveryEvent($error));
            }
        }

        return $response;
    }

    /**
     * @param Address[] $addresses
     *
     * @return list<array{email: string, name?: string}>
     */
    private function formatAddresses(array $addresses): array
    {
        return array_map(fn (Address $address) => $this->formatAddress($address), $addresses);
    }

    /**
     * The conversation endpoint sends one message to its To, Cc and Bcc addresses,
     * so recipients see each other and reply-all works; "/messages" sends each
     * recipient a copy of their own. The conversation endpoint is only used when
     * the envelope sends to exactly those addresses: an application that redirects
     * its mail, with framework.mailer.envelope.recipients for example, must never
     * send to the addresses in the headers.
     *
     * @return array{to: list<array{email: string, name?: string}>, cc?: list<array{email: string, name?: string}>, bcc?: list<array{email: string, name?: string}>}|null
     */
    private function getConversationRecipients(Email $email, Envelope $envelope): ?array
    {
        $to = $email->getTo();
        $cc = $email->getCc();
        $bcc = $email->getBcc();
        if (!$to || \count($to) + \count($cc) + \count($bcc) > self::CONVERSATION_RECIPIENT_LIMIT) {
            return null;
        }

        $normalize = static function (array $addresses): array {
            $addresses = array_map(static fn (Address $address): string => strtolower($address->getEncodedAddress()), $addresses);
            sort($addresses);

            return $addresses;
        };
        if ($normalize([...$to, ...$cc, ...$bcc]) !== $normalize($envelope->getRecipients())) {
            return null;
        }

        return array_filter([
            'to' => $this->formatAddresses($to),
            'cc' => $this->formatAddresses($cc),
            'bcc' => $this->formatAddresses($bcc),
        ]);
    }

    private function getPayload(Email $email, Envelope $envelope): array
    {
        // "From" and "Subject" headers are handled by the message itself
        $payload = ($this->getConversationRecipients($email, $envelope) ?? ['recipients' => $this->formatAddresses($envelope->getRecipients())]) + [
            'from' => $this->formatAddress($envelope->getSender()),
            'subject' => $email->getSubject(),
        ];

        if ($text = $email->getTextBody()) {
            $payload['text_content'] = $text;
        }
        if ($html = $email->getHtmlBody()) {
            $payload['html_content'] = $html;
        }
        if ($replyTo = $email->getReplyTo()) {
            $payload['reply_to'] = $this->formatAddress(array_pop($replyTo));
        }

        [$headers, $tags] = $this->prepareHeaders($email->getHeaders());
        // Bcc addresses are already recipients; as a header they could be shown to everyone
        unset($headers['Bcc']);
        if (!isset($payload['recipients'])) {
            // the conversation endpoint sets the Cc header from "cc", and refuses both
            unset($headers['Cc']);
        }
        [$headers, $tags, $options] = $this->extractOptionHeaders($headers, $tags);
        $tracking = TrackingHeader::fromHeaders($email->getHeaders());
        if ($headers) {
            $payload['headers'] = $headers;
        }
        if ($tags) {
            $payload['tags'] = $tags;
        }
        if (null !== $tracking?->getOpens()) {
            $payload['tracking']['open'] = $tracking->getOpens();
        }
        if (null !== $tracking?->getClicks()) {
            $payload['tracking']['click'] = $tracking->getClicks();
        }
        // an explicit AhaSend-* header wins over the generic TrackingHeader, as on SMTP
        $payload = array_replace_recursive($payload, $options);

        if ($email->getAttachments()) {
            // v2 needs the MIME form of the Content-ID, in angle brackets: without
            // them it delivers an inline image as a regular attachment. The HTML
            // keeps referencing it without brackets, as cid:<id>.
            $payload['attachments'] = array_map(
                static fn (array $attachment): array => isset($attachment['content_id']) ? ['content_id' => '<'.$attachment['content_id'].'>'] + $attachment : $attachment,
                $this->getAttachments($email),
            );
        }

        return $payload;
    }

    /**
     * Moves AhaSend's SMTP special headers to the API v2 fields they correspond
     * to, so they keep their meaning on this transport. A malformed value throws:
     * a typo in AhaSend-Sandbox must not turn a test into a real send. Any other
     * header is left to be forwarded.
     *
     * @param array<string, string> $headers
     * @param list<string>          $tags
     *
     * @return array{0: array<string, string>, 1: list<string>, 2: array<string, mixed>}
     */
    private function extractOptionHeaders(array $headers, array $tags): array
    {
        $options = [];
        foreach ($headers as $name => $value) {
            $value = trim($value);

            match ('' === $value ? null : strtolower($name)) {
                'ahasend-tags' => $tags = array_merge($tags, array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'))),
                'ahasend-track-opens' => $options['tracking']['open'] = $this->parseBooleanHeader($name, $value),
                'ahasend-track-clicks' => $options['tracking']['click'] = $this->parseBooleanHeader($name, $value),
                'ahasend-sandbox' => $options['sandbox'] = $this->parseBooleanHeader($name, $value),
                'ahasend-sandbox-result' => $options['sandbox_result'] = $value,
                'ahasend-metadata-retention' => $options['retention']['metadata'] = $this->parseDaysHeader($name, $value, 1),
                'ahasend-data-retention' => $options['retention']['data'] = $this->parseDaysHeader($name, $value, 0),
                default => $name = null,
            };

            if (null !== $name) {
                unset($headers[$name]);
            }
        }

        return [$headers, array_values(array_unique($tags)), $options];
    }

    private function parseBooleanHeader(string $name, string $value): bool
    {
        return filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? throw new InvalidArgumentException(\sprintf('The "%s" header must be "true" or "false", got "%s".', $name, $value));
    }

    private function parseDaysHeader(string $name, string $value, int $minDays): int
    {
        if (!ctype_digit($value) || (int) $value < $minDays || (int) $value > self::MAX_RETENTION_DAYS) {
            throw new InvalidArgumentException(\sprintf('The "%s" header must be a whole number of days from %d to %d, got "%s".', $name, $minDays, self::MAX_RETENTION_DAYS, $value));
        }

        return (int) $value;
    }

    private function getLegacyPayload(Email $email, Envelope $envelope): array
    {
        // "From" and "Subject" headers are handled by the message itself
        $payload = [
            'recipients' => $this->formatAddresses($envelope->getRecipients()),
            'from' => $this->formatAddress($envelope->getSender()),
            'content' => [
                'subject' => $email->getSubject(),
            ],
        ];

        if ($text = $email->getTextBody()) {
            $payload['content']['text_body'] = $text;
        }
        if ($html = $email->getHtmlBody()) {
            $payload['content']['html_body'] = $html;
        }
        if ($replyTo = $email->getReplyTo()) {
            $payload['content']['reply_to'] = $this->formatAddress(array_pop($replyTo));
        }

        [$headers, $tags] = $this->prepareHeaders($email->getHeaders());
        // Bcc addresses are already in "recipients"; the v1 API keeps the header, so every recipient would see them
        unset($headers['Bcc']);
        $tracking = TrackingHeader::fromHeaders($email->getHeaders());
        if ($tags) {
            $tagsStr = implode(',', $tags);
            $email->getHeaders()->addTextHeader('AhaSend-Tags', $tagsStr);
            $headers['AhaSend-Tags'] = $tagsStr;
        }
        // the v1 API reads tracking settings from the message headers, not from a payload field
        if (null !== $tracking?->getOpens() && !$email->getHeaders()->has('AhaSend-Track-Opens')) {
            $headers['AhaSend-Track-Opens'] = $tracking->getOpens() ? 'true' : 'false';
        }
        if (null !== $tracking?->getClicks() && !$email->getHeaders()->has('AhaSend-Track-Clicks')) {
            $headers['AhaSend-Track-Clicks'] = $tracking->getClicks() ? 'true' : 'false';
        }
        if ($headers) {
            $payload['content']['headers'] = $headers;
        }

        if ($email->getAttachments()) {
            $payload['content']['attachments'] = $this->getAttachments($email);
        }

        return $payload;
    }

    /**
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private function prepareHeaders(Headers $headers): array
    {
        $headersPrepared = [];
        // AhaSend API does not accept these headers.
        $headersToBypass = ['To', 'From', 'Subject', 'Reply-To'];
        $tags = [];
        foreach ($headers->all() as $header) {
            if (\in_array($header->getName(), $headersToBypass, true)) {
                continue;
            }

            if ($header instanceof TagHeader) {
                $tags[] = $header->getValue();
                continue;
            }

            if (0 === strcasecmp($header->getName(), TrackingHeader::NAME)) {
                continue;
            }

            $headersPrepared[$header->getName()] = $header->getBodyAsString();
        }

        return [$headersPrepared, $tags];
    }

    private function getAttachments(Email $email): array
    {
        $attachments = [];
        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();

            $contentType = $headers->get('Content-Type')->getBody();
            $base64 = 'text/plain' !== $contentType;
            $disposition = $headers->getHeaderBody('Content-Disposition');

            if ($base64) {
                $body = base64_encode($attachment->getBody());
            } else {
                $body = $attachment->getBody();
            }

            $att = [
                'content_type' => $contentType,
                'file_name' => $attachment->getFilename(),
                'data' => $body,
                'base64' => $base64,
            ];

            if ($attachment->hasContentId()) {
                $att['content_id'] = $attachment->getContentId();
            } elseif ('inline' === $disposition) {
                $att['content_id'] = $attachment->getFilename();
            }

            $attachments[] = $att;
        }

        return $attachments;
    }

    private function formatAddress(Address $address): array
    {
        $formattedAddress = ['email' => $address->getEncodedAddress()];

        if ($address->getName()) {
            $formattedAddress['name'] = $address->getName();
        }

        return $formattedAddress;
    }

    private static function withoutAngleBrackets(string $messageId): string
    {
        return str_starts_with($messageId, '<') && str_ends_with($messageId, '>') ? substr($messageId, 1, -1) : $messageId;
    }

    private function getEndpoint(): string
    {
        return ($this->host ?: self::HOST).($this->port ? ':'.$this->port : '');
    }
}
