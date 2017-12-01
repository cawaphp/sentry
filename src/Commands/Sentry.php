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

namespace Cawa\Sentry\Commands;

use Cawa\Console\Command;
use Cawa\Http\Request;
use Cawa\HttpClient\Exceptions\RequestException;
use Cawa\HttpClient\HttpClientFactory;
use Cawa\Net\Uri;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Sentry extends Command
{
    use HttpClientFactory;

    /**
     *
     */
    protected function configure()
    {
        $this->setName('sys:sentry')
            ->setDescription('Send error to sentry')
            ->addOption('method', 'X', InputOption::VALUE_OPTIONAL, 'Http Method', 'POST')
            ->addArgument('url', InputArgument::REQUIRED, 'Url')
            ->addArgument('headers', InputArgument::REQUIRED, 'Json headers')
            ->addArgument('payload', InputArgument::REQUIRED, 'Json payload')
        ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @throws RequestException
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        $client = self::httpClient(self::class);

        $request = (new Request())
            ->setMethod($input->getOption('method'))
            ->setUri(new Uri($input->getArgument('url')))
            ->addHeaders(json_decode($input->getArgument('headers'), true))
            ->setPayload(base64_decode($input->getArgument('payload')))
        ;

        try {
            $response = $client->send($request);

            return $response->getStatus() === 200 ? 0 : 1;
        } catch (RequestException $exception) {
            if (stripos($exception->getMessage(), 'An event with the same ID') !== false) {
                return 0;
            }

            throw $exception;
        }
    }
}
