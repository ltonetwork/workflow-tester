<?php declare(strict_types=1);

namespace LTO\LiveContracts\Tester;

use Behat\Behat\Context\Context;
use Behat\Testwork\Hook\Scope\BeforeSuiteScope;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use Jasny\HttpDigest\ClientMiddleware as HttpDigestMiddleware;
use Jasny\HttpDigest\HttpDigest;
use Psr\Http\Message\RequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

/**
 * Context for signed HTTP requests.
 */
class HttpContext implements Context
{
    /**
     * @var HttpClient
     */
    protected static $httpClient;

    /**
     * Get HTTP endpoint.
     * @BeforeSuite
     *
     * @param BeforeSuiteScope $scope
     */
    public static function initHttpClient(BeforeSuiteScope $scope)
    {
        $environment = $scope->getEnvironment();
        $endpoint = $environment->getSuite()->getSetting('endpoint') ?? 'http://localhost';

        $stack = HandlerStack::create();
        $stack->push((new HttpDigestMiddleware(new HttpDigest('SHA-256')))->forGuzzle());
        $stack->push(new HttpSignatureMiddleware());
        $stack->push(\Closure::fromCallable([__CLASS__, 'jsonMiddleware']));
        $stack->push(\Closure::fromCallable([__CLASS__, 'outputErrorMiddleware']));

        self::$httpClient = new HttpClient(['base_uri' => $endpoint, 'handler' => $stack, 'http_errors' => false]);
    }


    /**
     * Guzzle middleware to output in case of an error response.
     *
     * @param callable $handler
     * @return callable
     */
    protected static function outputErrorMiddleware(callable $handler): callable
    {
        return static function(Request $request, array $options) use ($handler) {
            return $handler($request, $options)->then(static function(Response $response) use ($request) {
                if ($response->getStatusCode() >= 400) {
                    $msg = 'Server responded with "' . $response->getStatusCode() . ' '. $response->getReasonPhrase() .
                        '" for ' . $request->getMethod() . ' ' . $request->getUri();
                    Assert::fail($msg . "\n" . $response->getBody());
                }

                return $response;
            });
        };
    }

    /**
     * @param callable $handler
     * @return callable
     */
    protected static function jsonMiddleware(callable $handler): callable
    {
        return static function(Request $request, array $options) use ($handler) {
            if (!$request->getHeader('Accept') === 'application/json') {
                return $handler($request, $options);
            }

            return $handler($request, $options)->then(static function(Response $response) use ($handler) {
                if ($response->getStatusCode() >= 400) {
                    return $response;
                }

                list($contentType) = explode(';', $response->getHeaderLine('Content-Type'));

                if ($contentType !== 'application/json') {
                    throw new \UnexpectedValueException("Expected application/json, got $contentType");
                }

                json_decode((string)$response->getBody());

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \UnexpectedValueException("Response is not not valid JSON: ". json_last_error_msg());
                }

                return $response;
            });
        };
    }

    /**
     * Get the HTTP client.
     *
     * @return HttpClient
     */
    public function getClient(): HttpClient
    {
        return static::$httpClient;
    }
}
