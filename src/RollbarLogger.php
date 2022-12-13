<?php declare(strict_types=1);

namespace Rollbar;

use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;
use Psr\Log\AbstractLogger;
use Rollbar\Payload\Payload;
use Rollbar\Payload\Level;
use Rollbar\Truncation\Truncation;
use Monolog\Logger as MonologLogger;
use Rollbar\Payload\EncodedPayload;

class RollbarLogger extends AbstractLogger
{
    private $config;
    private $truncation;
    private $queue;
    private $reportCount = 0;

    public function __construct(array $config)
    {
        $this->config = new Config($config);
        $this->truncation = new Truncation($this->config);
        $this->queue = array();
    }

    /**
     * @since 3.0
     */
    public function getConfig()
    {
        return $this->config;
    }
    
    public function enable()
    {
        return $this->config->enable();
    }
    
    public function disable()
    {
        return $this->config->disable();
    }
    
    public function enabled()
    {
        return $this->config->enabled();
    }
    
    public function disabled()
    {
        return $this->config->disabled();
    }

    public function configure(array $config)
    {
        $this->config->configure($config);
    }

    public function scope(array $config)
    {
        return new RollbarLogger($this->extend($config));
    }

    public function extend(array $config)
    {
        return $this->config->extend($config);
    }
    
    public function addCustom($key, $data)
    {
        $this->config->addCustom($key, $data);
    }
    
    public function removeCustom($key)
    {
        $this->config->removeCustom($key);
    }
    
    public function getCustom()
    {
        return $this->config->getCustom();
    }

    /**
     * Logs a message to the Rollbar service with the specified level.
     *
     * @param Level|string      $level   The severity level of the message.
     *                                   Must be one of the levels as defined in
     *                                   the {@see Level} constants.
     * @param string|Stringable $message The log message.
     * @param array             $context Arbitrary data.
     *
     * @return void
     *
     * @throws InvalidArgumentException If $level is not a valid level.
     * @throws Throwable Rethrown $message if it is {@see Throwable} and {@see Config::raiseOnError} is true.
     */
    public function log($level, string|Stringable $message, array $context = array()): void
    {
        $this->report($level, $message, $context);
    }

    /**
     * Creates the {@see Response} object and reports the message to the Rollbar
     * service.
     *
     * @param string|Level      $level   The severity level to send to Rollbar.
     * @param string|Stringable $message The log message.
     * @param array             $context Any additional context data.
     *
     * @return Response
     *
     * @throws InvalidArgumentException If $level is not a valid level.
     * @throws Throwable Rethrown $message if it is {@see Throwable} and {@see Config::raiseOnError} is true.
     *
     * @since  4.0.0
     */
    public function report($level, string|Stringable $message, array $context = array()): Response
    {
        if ($this->disabled()) {
            $this->verboseLogger()->notice('Rollbar is disabled');
            return new Response(0, "Disabled");
        }

        // Convert a Level proper into a string proper, as the code paths that
        // follow have allowed both only by virtue that a Level downcasts to a
        // string. With strict types, that no longer happens. We should consider
        // tightening the boundary so that we convert from string to Level
        // enum here, and work with Level enum through protected level.
        if ($level instanceof Level) {
            $level = (string)$level;
        } elseif (!LevelFactory::isValidLevel($level)) {
            $exception = new InvalidArgumentException("Invalid log level '$level'.");
            $this->verboseLogger()->error($exception->getMessage());
            throw $exception;
        }

        $this->verboseLogger()->info("Attempting to log: [$level] " . $message);

        if ($this->config->internalCheckIgnored($level, $message)) {
            $this->verboseLogger()->info('Occurrence ignored');
            return new Response(0, "Ignored");
        }

        $accessToken = $this->getAccessToken();
        $payload     = $this->getPayload($accessToken, $level, $message, $context);

        $isUncaught = $this->isUncaughtLogData($message);
        if ($this->config->checkIgnored($payload, $accessToken, $message, $isUncaught)) {
            $this->verboseLogger()->info('Occurrence ignored');
            $response = new Response(0, "Ignored");
        } else {
            $serialized = $payload->serialize($this->config->getMaxNestingDepth());

            $scrubbed = $this->scrub($serialized);

            $encoded = $this->encode($scrubbed);

            $truncated = $this->truncate($encoded);

            $response = $this->send($truncated, $accessToken);
        }

        $this->handleResponse($payload, $response);

        if ($response->getStatus() === 0) {
            $this->verboseLogger()->error('Occurrence rejected by the SDK: ' . $response);
        } elseif ($response->getStatus() >= 400) {
            $info = $response->getInfo();
            $this->verboseLogger()->error(
                'Occurrence rejected by the API: ' . ($info['message'] ?? 'message not set')
            );
        } else {
            $this->verboseLogger()->info('Occurrence successfully logged');
        }

        if ($message instanceof Throwable && $this->config->getRaiseOnError()) {
            throw $message;
        }

        return $response;
    }

    public function flush(): ?Response
    {
        if ($this->getQueueSize() > 0) {
            $batch = $this->queue;
            $this->queue = array();
            return $this->config->sendBatch($batch, $this->getAccessToken());
        }
        $this->verboseLogger()->debug('Queue flushed');
        return new Response(0, "Queue empty");
    }

    public function flushAndWait(): void
    {
        $this->flush();
        $this->config->wait($this->getAccessToken());
    }

    public function shouldIgnoreError($errno)
    {
        return $this->config->shouldIgnoreError($errno);
    }

    public function getQueueSize(): int
    {
        return count($this->queue);
    }

    protected function send(EncodedPayload $payload, string $accessToken): Response
    {
        if ($this->reportCount >= $this->config->getMaxItems()) {
            $response = new Response(
                0,
                "Maximum number of items per request has been reached. If you " .
                "want to report more items, please use `max_items` " .
                "configuration option."
            );
            $this->verboseLogger()->warning($response->getInfo());
            return $response;
        } else {
            $this->reportCount++;
        }

        if ($this->config->getBatched()) {
            $response = new Response(0, "Pending");
            if ($this->getQueueSize() >= $this->config->getBatchSize()) {
                $response = $this->flush();
            }
            $this->queue[] = $payload;
            $this->verboseLogger()->debug("Added payload to the queue (running in `batched` mode).");
            return $response;
        }
        
        return $this->config->send($payload, $accessToken);
    }

    protected function getPayload(string $accessToken, $level, $toLog, $context)
    {
        $data = $this->config->getRollbarData($level, $toLog, $context);
        $payload = new Payload($data, $accessToken);
        return $this->config->transform($payload, $level, $toLog, $context);
    }

    protected function getAccessToken(): string
    {
        return $this->config->getAccessToken();
    }
    
    public function getDataBuilder()
    {
        return $this->config->getDataBuilder();
    }

    /**
     * Returns the logger responsible for logging request payload and response dumps, if enabled.
     *
     * @return LoggerInterface
     */
    public function logPayloadLogger(): LoggerInterface
    {
        return $this->config->logPayloadLogger();
    }

    public function verboseLogger()
    {
        return $this->config->verboseLogger();
    }

    protected function handleResponse(Payload $payload, mixed $response): void
    {
        $this->config->handleResponse($payload, $response);
    }
    
    /**
     * @param array $serializedPayload
     * @return array
     */
    protected function scrub(array &$serializedPayload): array
    {
        $serializedPayload['data'] = $this->config->getScrubber()->scrub($serializedPayload['data']);
        return $serializedPayload;
    }
    
    /**
     * @param \Rollbar\Payload\EncodedPayload $payload
     * @return \Rollbar\Payload\EncodedPayload
     */
    protected function truncate(\Rollbar\Payload\EncodedPayload &$payload)
    {
        return $this->truncation->truncate($payload);
    }
    
    /**
     * @param array &$payload
     * @return \Rollbar\Payload\EncodedPayload
     */
    protected function encode(array &$payload)
    {
        $encoded = new EncodedPayload($payload);
        $encoded->encode();
        return $encoded;
    }

    /**
     * Check whether the data to log represents an uncaught error, exception,
     * or fatal error. This works in concert with src/Handlers/, which sets
     * the `isUncaught` property on the `Throwable` representation of data.
     *
     * @since 3.0.1
     */
    public function isUncaughtLogData(mixed $toLog): bool
    {
        if (! $toLog instanceof Throwable) {
            return false;
        }
        if (! isset($toLog->isUncaught)) {
            return false;
        }
        return $toLog->isUncaught === true;
    }
}
