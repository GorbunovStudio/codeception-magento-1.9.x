<?php

namespace Optimus\Magento1\Codeception\OAuth;

use Optimus\Magento1\Codeception\OAuth\Credentials\ClientCredentialsInterface;
use Optimus\Magento1\Codeception\OAuth\Credentials\ClientCredentials;
use Optimus\Magento1\Codeception\OAuth\Credentials\CredentialsInterface;
use Optimus\Magento1\Codeception\OAuth\Credentials\CredentialsException;
use Optimus\Magento1\Codeception\OAuth\Credentials\TemporaryCredentials;
use Optimus\Magento1\Codeception\OAuth\Credentials\TokenCredentials;
use Optimus\Magento1\Codeception\OAuth\Signature\HmacSha1Signature;
use Optimus\Magento1\Codeception\OAuth\Signature\SignatureInterface;

class Client
{
    /**
     * Client credentials.
     *
     * @var ClientCredentials
     */
    protected $clientCredentials;

    /**
     * Signature.
     *
     * @var SignatureInterface
     */
    protected $signature;

    /**
     * The response type for data returned from API calls.
     *
     * @var string
     */
    protected $responseType = 'json';


    /**
     * Optional user agent.
     *
     * @var string
     */
    protected $userAgent;

    /**
     * Admin url.
     *
     * @var string
     */
    protected $adminUrl;

    /**
     * Base uri.
     *
     * @var string
     */
    protected $baseUri;

    /**
     * Server is admin.
     *
     * @var bool
     */
    protected $isAdmin = false;

    /**
     * oauth_verifier stored for use with.
     *
     * @var string
     */
    private $verifier;

    /**
     * @var \Optimus\Magento1\Codeception\Helper
     */
    private $module;

    /**
     * Create a new server instance.
     *
     * @param \Optimus\Magento1\Codeception\Helper $module
     * @param ClientCredentialsInterface|array $clientCredentials
     * @param SignatureInterface               $signature
     */
    public function __construct(
        \Optimus\Magento1\Codeception\Helper $module,
        $clientCredentials,
        SignatureInterface $signature = null
    )
    {
        $this->module = $module;

        if (is_array($clientCredentials)) {
            $this->parseConfigurationArray($clientCredentials);
        }

        // Pass through an array or client credentials, we don't care
        if (is_array($clientCredentials)) {
            $clientCredentials = $this->createClientCredentials($clientCredentials);
        } elseif (!$clientCredentials instanceof ClientCredentialsInterface) {
            throw new \InvalidArgumentException('Client credentials must be an array or valid object.');
        }

        $this->clientCredentials = $clientCredentials;
        $this->signature = $signature ?: new HmacSha1Signature($clientCredentials);
    }

    /**
     * Gets temporary credentials by performing a request to
     * the server.
     *
     * @return TemporaryCredentials
     */
    public function getTemporaryCredentials()
    {
        $uri = $this->urlTemporaryCredentials();

        $header = $this->temporaryCredentialsProtocolHeader($uri);
        $authorizationHeader = array('Authorization' => $header);

        $this->module->headers = $this->buildHttpClientHeaders($authorizationHeader);
        $response = $this->module->_request('POST', $uri);

        return $this->createTemporaryCredentials($response);
    }

    /**
     * Get the authorization URL by passing in the temporary credentials
     * identifier or an object instance.
     *
     * @param TemporaryCredentials|string $temporaryIdentifier
     *
     * @return string
     */
    public function getAuthorizationUrl($temporaryIdentifier)
    {
        // Somebody can pass through an instance of temporary
        // credentials and we'll extract the identifier from there.
        if ($temporaryIdentifier instanceof TemporaryCredentials) {
            $temporaryIdentifier = $temporaryIdentifier->getIdentifier();
        }

        $parameters = array('oauth_token' => $temporaryIdentifier);

        $url = $this->urlAuthorization();
        $queryString = http_build_query($parameters);

        return $this->buildUrl($url, $queryString);
    }

    /**
     * Redirect the client to the authorization URL.
     *
     * @param TemporaryCredentials|string $temporaryIdentifier
     */
    public function authorize($temporaryIdentifier)
    {
        $url = $this->getAuthorizationUrl($temporaryIdentifier);

        //http://budsies2.local.optimuspro.ru:8085/admin/oauth_authorize?oauth_token=035f7051eb556791bcf2b855e17ec384
        $this->module->amOnPage($url);

        //POST TO: http://budsies2.local.optimuspro.ru:8085/index.php/admin/oauth_authorize/index/
//        $formUrl = $this->module->grabFromCurrentUrl();
//        $formKey = $this->module->grabValueFrom('[name=form_key]');
//
//        $response = $this->module->_request('POST', $formUrl, [
//           'form_key'        => $formKey,
//           'login[username]' => 'admin',
//           'login[password]' => 'a123456'
//        ]);

        $this->module->fillField('login[username]', 'admin');
        $this->module->fillField('login[password]', 'a123456');
        $this->module->click('[type=submit]');

        $this->module->click('[title=Authorize]');

//        $formUrl    = $this->module->grabAttributeFrom('#oauth_authorize_confirm', 'action');
//        $oauthToken =  $this->module->grabValueFrom('[name=oauth_token]');
//
//        $response = $this->module->_request('POST', $formUrl, [
//            'oauth_token' => $oauthToken,
//        ]);

        return;
    }

    /**
     * Retrieves token credentials by passing in the temporary credentials,
     * the temporary credentials identifier as passed back by the server
     * and finally the verifier code.
     *
     * @param TemporaryCredentials $temporaryCredentials
     * @param string               $temporaryIdentifier
     * @param string               $verifier
     *
     * @return TokenCredentials
     */
    public function getTokenCredentials(TemporaryCredentials $temporaryCredentials, $temporaryIdentifier, $verifier)
    {
        $this->verifier = $verifier;

        if ($temporaryIdentifier !== $temporaryCredentials->getIdentifier()) {
            throw new \InvalidArgumentException(
                'Temporary identifier passed back by server does not match that of stored temporary credentials.
                Potential man-in-the-middle.'
            );
        }

        $uri = $this->urlTokenCredentials();
        $bodyParameters = array('oauth_verifier' => $verifier);


        $this->module->headers = $this->getHeaders($temporaryCredentials, 'POST', $uri, $bodyParameters);
        $response = $this->module->_request('POST', $uri, $bodyParameters);

        return $this->createTokenCredentials($response);
    }


    /**
     * Get the client credentials associated with the server.
     *
     * @return ClientCredentialsInterface
     */
    public function getClientCredentials()
    {
        return $this->clientCredentials;
    }

    /**
     * Get the signature associated with the server.
     *
     * @return SignatureInterface
     */
    public function getSignature()
    {
        return $this->signature;
    }


    /**
     * Set the user agent value.
     *
     * @param string $userAgent
     *
     * @return Client
     */
    public function setUserAgent($userAgent = null)
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    /**
     * Get all headers required to created an authenticated request.
     *
     * @param CredentialsInterface $credentials
     * @param string               $method
     * @param string               $url
     * @param array                $bodyParameters
     *
     * @return array
     */
    public function getHeaders(CredentialsInterface $credentials, $method, $url, array $bodyParameters = array())
    {
        $header = $this->protocolHeader(strtoupper($method), $url, $credentials, $bodyParameters);
        $authorizationHeader = array('Authorization' => $header);
        $headers = $this->buildHttpClientHeaders($authorizationHeader);

        return $headers;
    }

    /**
     * Get Guzzle HTTP client default headers.
     *
     * @return array
     */
    protected function getHttpClientDefaultHeaders()
    {
        $defaultHeaders = array(
            'Accept' => 'application/json'
        );

        if (!empty($this->userAgent)) {
            $defaultHeaders['User-Agent'] = $this->userAgent;
        }

        return $defaultHeaders;
    }

    /**
     * Build Guzzle HTTP client headers.
     *
     * @return array
     */
    protected function buildHttpClientHeaders($headers = array())
    {
        $defaultHeaders = $this->getHttpClientDefaultHeaders();

        return array_merge($headers, $defaultHeaders);
    }

    /**
     * Creates a client credentials instance from an array of credentials.
     *
     * @param array $clientCredentials
     *
     * @return ClientCredentials
     */
    protected function createClientCredentials(array $clientCredentials)
    {
        $keys = array('identifier', 'secret');

        foreach ($keys as $key) {
            if (!isset($clientCredentials[$key])) {
                throw new \InvalidArgumentException("Missing client credentials key [$key] from options.");
            }
        }

        $_clientCredentials = new ClientCredentials();
        $_clientCredentials->setIdentifier($clientCredentials['identifier']);
        $_clientCredentials->setSecret($clientCredentials['secret']);

        if (isset($clientCredentials['callback_uri'])) {
            $_clientCredentials->setCallbackUri($clientCredentials['callback_uri']);
        }

        return $_clientCredentials;
    }

    /**
     * Handle a bad response coming back when getting temporary credentials.
     *
     * @param BadResponseException $e
     *
     * @throws CredentialsException
     */
    protected function handleTemporaryCredentialsBadResponse(BadResponseException $e)
    {
        $response = $e->getResponse();
        $body = $response->getBody();
        $statusCode = $response->getStatusCode();

        throw new CredentialsException(
            "Received HTTP status code [$statusCode] with message \"$body\" when getting temporary credentials."
        );
    }

    /**
     * Creates temporary credentials from the body response.
     *
     * @param string $body
     *
     * @return TemporaryCredentials
     */
    protected function createTemporaryCredentials($body)
    {
        parse_str($body, $data);

        if (!$data || !is_array($data)) {
            throw new CredentialsException('Unable to parse temporary credentials response.');
        }

        if (!isset($data['oauth_callback_confirmed']) || $data['oauth_callback_confirmed'] != 'true') {
            throw new CredentialsException('Error in retrieving temporary credentials.');
        }

        $temporaryCredentials = new TemporaryCredentials();
        $temporaryCredentials->setIdentifier($data['oauth_token']);
        $temporaryCredentials->setSecret($data['oauth_token_secret']);

        return $temporaryCredentials;
    }


    /**
     * Creates token credentials from the body response.
     *
     * @param string $body
     *
     * @return TokenCredentials
     */
    protected function createTokenCredentials($body)
    {
        parse_str($body, $data);

        if (!$data || !is_array($data)) {
            throw new CredentialsException('Unable to parse token credentials response.');
        }

        if (isset($data['error'])) {
            throw new CredentialsException("Error [{$data['error']}] in retrieving token credentials.");
        }

        $tokenCredentials = new TokenCredentials();
        $tokenCredentials->setIdentifier($data['oauth_token']);
        $tokenCredentials->setSecret($data['oauth_token_secret']);

        return $tokenCredentials;
    }

    /**
     * Get the base protocol parameters for an OAuth request.
     * Each request builds on these parameters.
     *
     * @return array
     *
     * @see    OAuth 1.0 RFC 5849 Section 3.1
     */
    protected function baseProtocolParameters()
    {
        $dateTime = new \DateTime();

        return array(
            'oauth_consumer_key' => $this->clientCredentials->getIdentifier(),
            'oauth_nonce' => $this->nonce(),
            'oauth_signature_method' => $this->signature->method(),
            'oauth_timestamp' => $dateTime->format('U'),
            'oauth_version' => '1.0',
        );
    }

    /**
     * Any additional required protocol parameters for an
     * OAuth request.
     *
     * @return array
     */
    protected function additionalProtocolParameters()
    {
        return array(
            'oauth_verifier' => $this->verifier,
        );
    }

    /**
     * Generate the OAuth protocol header for a temporary credentials
     * request, based on the URI.
     *
     * @param string $uri
     *
     * @return string
     */
    protected function temporaryCredentialsProtocolHeader($uri)
    {
        $parameters = array_merge($this->baseProtocolParameters(), array(
            'oauth_callback' => $this->clientCredentials->getCallbackUri(),
        ));

        $parameters['oauth_signature'] = $this->signature->sign($uri, $parameters, 'POST');

        return $this->normalizeProtocolParameters($parameters);
    }

    /**
     * Generate the OAuth protocol header for requests other than temporary
     * credentials, based on the URI, method, given credentials & body query
     * string.
     *
     * @param string               $method
     * @param string               $uri
     * @param CredentialsInterface $credentials
     * @param array                $bodyParameters
     *
     * @return string
     */
    protected function protocolHeader($method, $uri, CredentialsInterface $credentials, array $bodyParameters = array())
    {
        $parameters = array_merge(
            $this->baseProtocolParameters(),
            $this->additionalProtocolParameters(),
            array(
                'oauth_token' => $credentials->getIdentifier(),
            )
        );

        $this->signature->setCredentials($credentials);

        $parameters['oauth_signature'] = $this->signature->sign(
            $uri,
            array_merge($parameters, $bodyParameters),
            $method
        );

        return $this->normalizeProtocolParameters($parameters);
    }

    /**
     * Takes an array of protocol parameters and normalizes them
     * to be used as a HTTP header.
     *
     * @param array $parameters
     *
     * @return string
     */
    protected function normalizeProtocolParameters(array $parameters)
    {
        array_walk($parameters, function (&$value, $key) {
            $value = rawurlencode($key).'="'.rawurlencode($value).'"';
        });

        return 'OAuth '.implode(', ', $parameters);
    }

    /**
     * Generate a random string.
     *
     * @param int $length
     *
     * @return string
     *
     * @see    OAuth 1.0 RFC 5849 Section 3.3
     */
    protected function nonce($length = 32)
    {
        $pool = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

        return substr(str_shuffle(str_repeat($pool, 5)), 0, $length);
    }

    /**
     * Build a url by combining hostname and query string after checking for
     * exisiting '?' character in host.
     *
     * @param string $host
     * @param string $queryString
     *
     * @return string
     */
    protected function buildUrl($host, $queryString)
    {
        return $host.(strpos($host, '?') !== false ? '&' : '?').$queryString;
    }

    /**
     * Get the URL for retrieving temporary credentials.
     *
     * @return string
     */
    public function urlTemporaryCredentials()
    {
        return $this->baseUri.'/oauth/initiate';
    }

    /**
     * Get the URL for redirecting the resource owner to authorize the client.
     *
     * @return string
     */
    public function urlAuthorization()
    {
        return $this->isAdmin
            ? $this->adminUrl
            : $this->baseUri.'/oauth/authorize';
    }

    /**
     * Get the URL retrieving token credentials.
     *
     * @return string
     */
    public function urlTokenCredentials()
    {
        return $this->baseUri.'/oauth/token';
    }

    /**
     * Parse configuration array to set attributes.
     *
     * @param array $configuration
     * @throws \Exception
     */
    private function parseConfigurationArray(array $configuration = array())
    {
        if (!isset($configuration['host'])) {
            throw new \Exception('Missing Magento Host');
        }
        $url = parse_url($configuration['host']);
        $this->baseUri = sprintf('%s://%s', $url['scheme'], $url['host']);

        if (isset($url['port'])) {
            $this->baseUri .= ':'.$url['port'];
        }

        if (isset($url['path'])) {
            $this->baseUri .= '/'.trim($url['path'], '/');
        }
        $this->isAdmin = !empty($configuration['admin']);
        if (!empty($configuration['adminUrl'])) {
            $this->adminUrl = $configuration['adminUrl'].'/oauth_authorize';
        } else {
            $this->adminUrl = $this->baseUri.'/admin/oauth_authorize';
        }
    }

}