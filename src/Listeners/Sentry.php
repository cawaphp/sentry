<?php

/*
 * This file is part of the Сáша framework.
 *
 * (c) tchiotludo <http://github.com/tchiotludo>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types = 1);

namespace Cawa\Sentry\Listeners;

use Cawa\App\AbstractApp;
use Cawa\App\HttpApp;
use Cawa\App\HttpFactory;
use Cawa\Console\App as ConsoleApp;
use Cawa\Core\DI;
use Cawa\Error\ErrorEvent;
use Cawa\Error\Exceptions\Error;
use Cawa\Error\Listeners\AbstractListener;
use Cawa\Events\TimerEvent;
use Cawa\Http\Request;
use Cawa\Net\Ip;
use Cawa\Net\Uri;
use Cawa\Queue\Job;
use Cawa\Queue\QueueFactory;
use Cawa\Router\RouterFactory;
use Cawa\Serializer\Serializer;
use Cawa\Session\SessionFactory;
use Symfony\Component\Debug\Exception\FatalThrowableError;

class Sentry extends AbstractListener
{
    use HttpFactory;
    use SessionFactory;
    use RouterFactory;
    use QueueFactory;

    /**
     * @var \Raven_Client
     */
    private static $client;

    /**
     * @return \Raven_Client
     */
    private static function getClient() : \Raven_Client
    {
        if (!self::$client) {
            $config = [
                // 'release' => '@TODO',
                'environment' => AbstractApp::env(),
                'transport' => function (\Raven_Client $client, $data) {
                    $request = new Request();
                    $request->setMethod('POST')
                        ->setUri(new Uri($client->getServerEndpoint('')))
                        ->addHeader('Content-Encoding', 'gzip')
                        ->addHeader('Content-Type', 'application/octet-stream')
                        ->addHeader('User-Agent', $client->getUserAgent())
                        ->addHeader('X-Sentry-Auth', $client->getAuthHeader())
                        ->setPayload(gzencode(json_encode($data)));

                    // $response = (new HttpClient())->send($request);

                    self::queue()->publish((new Job())
                        ->setClass(\Cawa\Sentry\Commands\Sentry::class)
                        ->setBody([
                            'url' => $request->getUri()->get(false, true),
                            '-X' => $request->getMethod(),
                            'headers' => json_encode($request->getHeaders()),
                            'payload' => base64_encode($request->getPayload()),
                        ])
                        ->serialize()
                    );

                    $client->context->clear();

                    self::$client = null;

                    return true;
                },
            ];

            if (AbstractApp::instance() instanceof HttpApp) {
                $config['site'] = self::request()->getUri()->getHost();
            }

            self::$client = new \Raven_Client(DI::config()->get('sentry/dsn'), $config);
        }

        return self::$client;
    }

    /**
     * @param ErrorEvent $event
     */
    public static function receive(ErrorEvent $event)
    {
        $client = self::getClient();

        $client->user_context(self::getUser());
        $client->extra_context(self::getExtra());

        $exception = $event->getException();
        if (!$exception instanceof \Exception) {
            $exception = new FatalThrowableError($exception);
        }

        $level = \Raven_Client::FATAL;
        if ($exception instanceof Error && $exception->getSeverity()) {
            $level = $client->translateSeverity($exception->getSeverity());
        }

        $client->captureException($exception, [
            'level' => $level,
        ]);
    }

    /**
     * @param TimerEvent $event
     *
     * @return bool
     */
    public static function onTimerEvent(TimerEvent $event)
    {
        if (AbstractApp::instance() instanceof ConsoleApp) {
            return true;
        }

        self::getClient()->breadcrumbs->record([
            'data' => $event->getData(),
            'category' => $event->getNamespace() . '.' . $event->getType(),
            'level' => 'info',
        ]);

        return true;
    }

    /**
     * @return array
     */
    protected static function getUser() : array
    {
        $data = ['ip_address' => Ip::get()];

        return $data;
    }

    /**
     * @param callable $listener
     *
     * @return string
     */
    private static function getControllerName($listener) : string
    {
        if (is_array($listener)) {
            if (is_string($listener[0])) {
                return $listener[0] . '::' . $listener[1];
            } else {
                return get_class($listener[0]) . '::' . $listener[1];
            }
        } elseif (is_string($listener)) {
            return $listener;
        } else {
            $reflection = new \ReflectionFunction($listener);

            return $reflection->getClosureScopeClass()->getName() . '::' .
                'closure[' . $reflection->getStartLine() . ':' .
                $reflection->getEndLine() . ']';
        }
    }

    /**
     * @return array
     */
    private static function getExtra() : array
    {
        $start = self::request()->getServer('REQUEST_TIME_FLOAT');
        $end = microtime(true);

        $data = [
            'Duration' => ($end - $start) * 1000,
        ];

        if (self::router()->current()) {
            $data['Controller'] = self::getControllerName(self::router()->current()->getController());
        }

        $data['Response Header'] = self::response()->getHeaders();

        // Session
        if (self::session()->isStarted()) {
            $session = self::session()->getData();

            foreach ($session as $key => $value) {
                if (is_object($value)) {
                    $session[$key] = Serializer::serialize($value);
                }
            }

            $data['Session'] = $session;

            unset($data['Session']['clockwork']);
        }

        return $data;
    }
}
