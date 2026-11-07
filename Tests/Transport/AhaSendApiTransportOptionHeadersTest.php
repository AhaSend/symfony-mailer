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
use Symfony\Component\Mailer\Bridge\AhaSend\Transport\AhaSendApiTransport;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\Header\TrackingHeader;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class AhaSendApiTransportOptionHeadersTest extends TestCase
{
    /**
     * @param array<string, string> $headers
     */
    private static function payload(array $headers, ?Email $email = null): array
    {
        $email ??= new Email();
        foreach ($headers as $name => $value) {
            $email->getHeaders()->addTextHeader($name, $value);
        }
        $envelope = new Envelope(new Address('alice@system.com'), [new Address('bob@system.com')]);
        $transport = new AhaSendApiTransport('aha-sk-ACCESS_KEY', accountId: 'ACCOUNT_ID');

        return (new \ReflectionMethod(AhaSendApiTransport::class, 'getPayload'))->invoke($transport, $email, $envelope);
    }

    public function testSmtpSpecialHeadersMoveToTheApiFields(): void
    {
        $payload = self::payload([
            'ahasend-tags' => 'welcome, onboarding ,',
            'ahasend-track-opens' => 'true',
            'AhaSend-Track-Clicks' => '0',
            'AhaSend-Sandbox' => '1',
            'AhaSend-Sandbox-Result' => 'bounce',
            'ahasend-metadata-retention' => '7',
            'ahasend-data-retention' => '0',
        ]);

        $this->assertSame(['welcome', 'onboarding'], $payload['tags']);
        $this->assertSame(['open' => true, 'click' => false], $payload['tracking']);
        $this->assertTrue($payload['sandbox']);
        $this->assertSame('bounce', $payload['sandbox_result']);
        $this->assertSame(['metadata' => 7, 'data' => 0], $payload['retention']);
        $this->assertArrayNotHasKey('headers', $payload);
    }

    public function testTagHeadersAndTheTagsHeaderAreMerged(): void
    {
        $email = new Email();
        $email->getHeaders()->add(new TagHeader('welcome'));

        $this->assertSame(['welcome', 'onboarding'], self::payload(['AhaSend-Tags' => 'onboarding,welcome'], $email)['tags']);
    }

    public function testAnExplicitHeaderWinsOverTrackingHeader(): void
    {
        $email = new Email();
        $email->getHeaders()->add(new TrackingHeader(opens: true, clicks: true));

        $payload = self::payload(['AhaSend-Track-Opens' => 'false'], $email);

        $this->assertSame(['open' => false, 'click' => true], $payload['tracking']);
    }

    public function testOtherHeadersAreStillForwarded(): void
    {
        $payload = self::payload(['AhaSend-Something-New' => 'value', 'AhaSend-Sandbox' => '', 'X-Campaign' => 'spring']);

        $this->assertSame(['AhaSend-Something-New' => 'value', 'AhaSend-Sandbox' => '', 'X-Campaign' => 'spring'], $payload['headers']);
        $this->assertArrayNotHasKey('sandbox', $payload);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function malformedHeaders(): iterable
    {
        yield 'misspelt sandbox' => ['AhaSend-Sandbox', 'tru', 'The "AhaSend-Sandbox" header must be "true" or "false", got "tru".'];
        yield 'tracking value' => ['AhaSend-Track-Opens', 'enabled', 'The "AhaSend-Track-Opens" header must be "true" or "false", got "enabled".'];
        yield 'retention value' => ['AhaSend-Data-Retention', 'abc', 'The "AhaSend-Data-Retention" header must be a whole number of days from 0 to 30, got "abc".'];
        yield 'metadata retention below 1 day' => ['AhaSend-Metadata-Retention', '0', 'The "AhaSend-Metadata-Retention" header must be a whole number of days from 1 to 30, got "0".'];
        yield 'metadata retention above 30 days' => ['AhaSend-Metadata-Retention', '31', 'The "AhaSend-Metadata-Retention" header must be a whole number of days from 1 to 30, got "31".'];
        yield 'data retention above 30 days' => ['AhaSend-Data-Retention', '31', 'The "AhaSend-Data-Retention" header must be a whole number of days from 0 to 30, got "31".'];
        yield 'negative retention' => ['AhaSend-Data-Retention', '-1', 'The "AhaSend-Data-Retention" header must be a whole number of days from 0 to 30, got "-1".'];
    }

    public function testTheRetentionLimitsAreAccepted(): void
    {
        $this->assertSame(['metadata' => 1, 'data' => 0], self::payload(['AhaSend-Metadata-Retention' => '1', 'AhaSend-Data-Retention' => '0'])['retention']);
        $this->assertSame(['metadata' => 30, 'data' => 30], self::payload(['AhaSend-Metadata-Retention' => '30', 'AhaSend-Data-Retention' => '30'])['retention']);
    }

    #[DataProvider('malformedHeaders')]
    public function testAMalformedValueThrows(string $header, string $value, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::payload([$header => $value]);
    }

    public function testTheLegacyPayloadStillForwardsThem(): void
    {
        $email = new Email();
        $email->getHeaders()->addTextHeader('AhaSend-Sandbox', 'true');
        $envelope = new Envelope(new Address('alice@system.com'), [new Address('bob@system.com')]);
        $transport = new AhaSendApiTransport('ACCESS_KEY');

        $payload = (new \ReflectionMethod(AhaSendApiTransport::class, 'getLegacyPayload'))->invoke($transport, $email, $envelope);

        $this->assertSame('true', $payload['content']['headers']['AhaSend-Sandbox']);
    }
}
