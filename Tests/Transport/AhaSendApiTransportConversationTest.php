<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Mailer\Bridge\AhaSend\Tests\Transport;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Mailer\Bridge\AhaSend\Transport\AhaSendApiTransport;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class AhaSendApiTransportConversationTest extends TestCase
{
    private array $requests = [];

    public function testToCcAndBccGoThroughTheConversationEndpoint(): void
    {
        $this->send((new Email())
            ->to(new Address('to1@example.com', 'First'), 'to2@example.com')
            ->cc('cc@example.com')
            ->bcc('bcc@example.com'));

        $request = $this->lastRequest();
        $this->assertSame('https://api.ahasend.com/v2/accounts/ACCOUNT_ID/messages/conversation', $request['url']);
        $this->assertSame([['email' => 'to1@example.com', 'name' => 'First'], ['email' => 'to2@example.com']], $request['body']['to']);
        $this->assertSame([['email' => 'cc@example.com']], $request['body']['cc']);
        $this->assertSame([['email' => 'bcc@example.com']], $request['body']['bcc']);
        $this->assertArrayNotHasKey('recipients', $request['body']);
        $this->assertArrayNotHasKey('headers', $request['body'], 'neither a Cc nor a Bcc header');
    }

    public function testWithoutCcOrBccOnlyToIsSent(): void
    {
        $this->send((new Email())->to('to@example.com'));

        $request = $this->lastRequest();
        $this->assertStringEndsWith('/messages/conversation', $request['url']);
        $this->assertSame([['email' => 'to@example.com']], $request['body']['to']);
        $this->assertArrayNotHasKey('cc', $request['body']);
        $this->assertArrayNotHasKey('bcc', $request['body']);
    }

    public function testTheEnvelopeIsComparedWithoutCaseOrOrder(): void
    {
        $email = (new Email())->to('to@example.com')->cc('cc@example.com');

        $this->send($email, new Envelope(new Address('from@example.com'), [new Address('CC@example.com'), new Address('to@example.com')]));

        $this->assertStringEndsWith('/messages/conversation', $this->lastRequest()['url']);
    }

    /**
     * Mail redirected with framework.mailer.envelope.recipients, for example, must
     * not reach the To and Cc addresses.
     */
    public function testARedirectedEnvelopeUsesMessages(): void
    {
        $email = (new Email())->to('customer@example.com')->cc('manager@example.com');

        $this->send($email, new Envelope(new Address('from@example.com'), [new Address('developer@example.com')]));

        $request = $this->lastRequest();
        $this->assertSame('https://api.ahasend.com/v2/accounts/ACCOUNT_ID/messages', $request['url']);
        $this->assertSame([['email' => 'developer@example.com']], $request['body']['recipients']);
        $this->assertArrayNotHasKey('to', $request['body']);
        $this->assertArrayNotHasKey('cc', $request['body']);
    }

    public function testAnEnvelopeWithAnExtraRecipientUsesMessages(): void
    {
        $email = (new Email())->to('to@example.com');

        $this->send($email, new Envelope(new Address('from@example.com'), [new Address('to@example.com'), new Address('audit@example.com')]));

        $this->assertSame([['email' => 'to@example.com'], ['email' => 'audit@example.com']], $this->lastRequest()['body']['recipients']);
    }

    public function testBccOnlyUsesMessages(): void
    {
        $this->send((new Email())->bcc('bcc1@example.com', 'bcc2@example.com'));

        $request = $this->lastRequest();
        $this->assertStringEndsWith('/messages', $request['url']);
        $this->assertSame([['email' => 'bcc1@example.com'], ['email' => 'bcc2@example.com']], $request['body']['recipients']);
        $this->assertArrayNotHasKey('headers', $request['body'], 'no Bcc header');
    }

    public static function provideRecipientCounts(): iterable
    {
        yield '50 recipients' => [50, '/messages/conversation'];
        yield '51 recipients' => [51, '/messages'];
    }

    #[DataProvider('provideRecipientCounts')]
    public function testTheConversationEndpointTakes50RecipientsAtMost(int $count, string $path): void
    {
        $email = (new Email())->to('to@example.com')->cc('cc@example.com');
        for ($i = 2; $i < $count; ++$i) {
            $email->addBcc("bcc{$i}@example.com");
        }

        $this->send($email);

        $this->assertSame('https://api.ahasend.com/v2/accounts/ACCOUNT_ID'.$path, $this->lastRequest()['url']);
    }

    private function send(Email $email, ?Envelope $envelope = null): void
    {
        $email->from('from@example.com')->subject('An email')->text('Test email body');
        $client = new MockHttpClient(function (string $method, string $url, array $options): JsonMockResponse {
            $this->requests[] = ['url' => $url, 'body' => json_decode($options['body'], true)];

            return new JsonMockResponse(['object' => 'list', 'data' => []], ['http_code' => 202]);
        });

        (new AhaSendApiTransport('aha-sk-KEY', $client, accountId: 'ACCOUNT_ID'))->send($email, $envelope);
    }

    private function lastRequest(): array
    {
        $this->assertCount(1, $this->requests);

        return $this->requests[0];
    }
}
