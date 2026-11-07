<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Mailer\Bridge\AhaSend\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Mailer\Bridge\AhaSend\Transport\AhaSendApiTransport;
use Symfony\Component\Mailer\Bridge\AhaSend\Transport\AhaSendSmtpTransport;
use Symfony\Component\Mailer\Bridge\AhaSend\Webhook\AhaSendRequestParser;
use Symfony\Component\RemoteEvent\Consumer\ConsumerInterface;
use Symfony\Component\RemoteEvent\Event\Mailer\MailerDeliveryEvent;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * This package keeps the bridge's class names, so FrameworkBundle wires it the
 * way it wires the official bridge, with no bundle of its own. These tests boot
 * an application configured like one using symfony/aha-send-mailer.
 */
final class FrameworkBundleTest extends TestCase
{
    private const WEBHOOK_SECRET = 'webhook-secret';

    private ?TestKernel $kernel = null;
    private mixed $errorHandler;
    private mixed $exceptionHandler;

    /**
     * The cache is removed per class, not per test: below PHP 8.4 a compiled
     * container requires its lazy-proxy files from the cache directory, and
     * PHP keeps that container's classes loaded for the whole process.
     */
    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(TestKernel::cacheRoot());
    }

    public static function tearDownAfterClass(): void
    {
        (new Filesystem())->remove(TestKernel::cacheRoot());
    }

    protected function setUp(): void
    {
        $this->errorHandler = self::currentErrorHandler();
        $this->exceptionHandler = self::currentExceptionHandler();
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();

        // Some FrameworkBundle versions register Symfony's ErrorHandler when
        // the kernel boots; put PHP's handlers back as they were.
        for ($i = 0; $i < 10 && self::currentErrorHandler() !== $this->errorHandler; ++$i) {
            restore_error_handler();
        }
        for ($i = 0; $i < 10 && self::currentExceptionHandler() !== $this->exceptionHandler; ++$i) {
            restore_exception_handler();
        }
    }

    /**
     * @return iterable<string, array{string, class-string, string}>
     */
    public static function dsnProvider(): iterable
    {
        yield 'API v2' => ['ahasend+api://aha-sk-key:ACCOUNT_ID@default', AhaSendApiTransport::class, 'ahasend+api://api.ahasend.com'];
        yield 'API v1' => ['ahasend+api://LEGACY_KEY@default', AhaSendApiTransport::class, 'ahasend+api://api.ahasend.com'];
        yield 'SMTP' => ['ahasend+smtp://user:pass@default', AhaSendSmtpTransport::class, 'smtp://send.ahasend.com:587'];
        yield 'SMTP short scheme' => ['ahasend://user:pass@default', AhaSendSmtpTransport::class, 'smtp://send.ahasend.com:587'];
    }

    #[DataProvider('dsnProvider')]
    public function testMailerDsnResolvesToTheBridge(string $dsn, string $expectedClass, string $expectedString): void
    {
        $transport = $this->boot($dsn)->get('mailer.transport_factory')->fromString($dsn);

        $this->assertInstanceOf($expectedClass, $transport);
        $this->assertSame($expectedString, (string) $transport);
    }

    public function testTheWebhookServiceIdsAreTheBridges(): void
    {
        $this->assertInstanceOf(AhaSendRequestParser::class, $this->boot()->get('mailer.webhook.request_parser.ahasend'));
    }

    /**
     * The whole path a customer's configuration uses: framework.webhook.routing
     * with the bridge's service ID, the webhook route, Messenger and a remote
     * event consumer.
     */
    public function testASignedWebhookReachesTheConsumerWithTheMessageIdAsItsId(): void
    {
        $container = $this->boot();
        $response = $this->kernel->handle($this->signedWebhook('{"type":"message.delivered","timestamp":"2024-10-27T19:37:30.928534039Z","data":{"recipient":"someone@example.com","message_id_header":"<message-id@example.com>","id":"ahasend-internal-id"}}', self::WEBHOOK_SECRET));

        $this->assertSame(202, $response->getStatusCode());

        $events = $container->get(RecordingConsumer::class)->events;
        $this->assertCount(1, $events);
        $this->assertInstanceOf(MailerDeliveryEvent::class, $events[0]);
        $this->assertSame(MailerDeliveryEvent::DELIVERED, $events[0]->getName());
        $this->assertSame('<message-id@example.com>', $events[0]->getId(), 'the same Message-ID the v2 send API returns');
    }

    public function testAWebhookWithAWrongSignatureIsRejected(): void
    {
        $container = $this->boot();
        $response = $this->kernel->handle($this->signedWebhook('{"type":"message.delivered","timestamp":"2024-10-27T19:37:30.928534039Z","data":{"recipient":"someone@example.com","message_id_header":"id"}}', 'another-secret'));

        $this->assertSame(406, $response->getStatusCode());
        $this->assertSame([], $container->get(RecordingConsumer::class)->events);
    }

    private function boot(string $dsn = 'ahasend+smtp://user:pass@default'): ContainerInterface
    {
        $this->kernel = new TestKernel($dsn, self::WEBHOOK_SECRET);
        $this->kernel->boot();

        return $this->kernel->getContainer()->get('test.service_container');
    }

    private function signedWebhook(string $body, string $secret): Request
    {
        $timestamp = (string) time();

        return Request::create('/webhook/ahasend', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_webhook-id' => 'event-1',
            'HTTP_webhook-timestamp' => $timestamp,
            'HTTP_webhook-signature' => 'v1,'.base64_encode(hash_hmac('sha256', 'event-1.'.$timestamp.'.'.$body, $secret, true)),
        ], $body);
    }

    private static function currentErrorHandler(): mixed
    {
        $handler = set_error_handler(static fn (): bool => false);
        restore_error_handler();

        return $handler;
    }

    private static function currentExceptionHandler(): mixed
    {
        $handler = set_exception_handler(static function (): void {});
        restore_exception_handler();

        return $handler;
    }
}

final class RecordingConsumer implements ConsumerInterface
{
    /** @var list<RemoteEvent> */
    public array $events = [];

    public function consume(RemoteEvent $event): void
    {
        $this->events[] = $event;
    }
}

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function __construct(private readonly string $dsn, private readonly string $webhookSecret)
    {
        parent::__construct('test', false);
    }

    public static function cacheRoot(): string
    {
        return sys_get_temp_dir().'/ahasend-symfony-mailer-bridge';
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
    }

    public function getCacheDir(): string
    {
        return self::cacheRoot().'/'.md5($this->dsn);
    }

    public function getLogDir(): string
    {
        return $this->getCacheDir().'/log';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $container->loadFromExtension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'http_client' => true,
            'mailer' => ['dsn' => $this->dsn],
            'messenger' => ['enabled' => true],
            'webhook' => [
                'enabled' => true,
                'routing' => [
                    'ahasend' => ['service' => 'mailer.webhook.request_parser.ahasend', 'secret' => $this->webhookSecret],
                ],
            ],
        ]);

        $container->register('logger', NullLogger::class);
        $container->register(RecordingConsumer::class)
            ->setPublic(true)
            ->addTag('remote_event.consumer', ['consumer' => 'ahasend']);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('webhook', '/webhook/{type}')->controller('webhook.controller::handle');
    }
}
