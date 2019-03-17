<?php declare(strict_types=1);

namespace LTO\LiveContracts\Tester;

use Jasny\HttpSignature\HttpSignature;
use LTO\Account;
use LTO\Account\SignCallback;
use Psr\Http\Message\RequestInterface;

/**
 * Custom Guzzle middleware to sign HTTP requests.
 */
class HttpSignatureMiddleware
{
    /**
     * Invoke the middleware.
     *
     * @param callable $handler
     * @return \Closure
     */
    public function __invoke(callable $handler)
    {
        return function(RequestInterface $request, $options) use ($handler) {
            if (isset($options['account']) && $options['account'] instanceof Account) {
                /** @var Account $account */
                $account = $options['account'];

                $request = $this->createService($account)->sign($request, $account->getPublicSignKey());
            }

            return $handler($request, $options);
        };
    }

    /**
     * Create a service to sign requests.
     *
     * @param Account $account
     * @return HttpSignature
     */
    protected function createService(Account $account): HttpSignature
    {
        $service = new HttpSignature(
            'ed25519-sha256',
            new SignCallback($account),
            function() {
                throw new \BadMethodCallException('Signature verification not supported');
            }
        );

        return $service
            ->withRequiredHeaders('default', ['(request-target)', 'date'])
            ->withRequiredHeaders('POST', ['(request-target)', 'date', 'content-type', 'content-length', 'digest']);
    }
}
