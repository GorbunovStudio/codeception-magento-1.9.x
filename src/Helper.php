<?php

namespace Optimus\Magento1\Codeception;

use Codeception\Lib\Framework;
use Codeception\Configuration;
use Codeception\TestInterface;
use Optimus\Magento1\Codeception\Exceptions\Exception;
use Optimus\Magento1\Codeception\Exceptions\OAuthAppCallbackException;
use Optimus\Magento1\Codeception\OAuth\Client as OAuthClient;
use Codeception\Util\JsonArray;
use Codeception\Util\JsonType;
use Codeception\Util\XmlStructure;
use Codeception\Util\Soap as XmlUtils;
use Codeception\PHPUnit\Constraint\JsonContains;
use Codeception\PHPUnit\Constraint\JsonType as JsonTypeConstraint;

class Helper extends Framework
{
    protected $requiredFields = ['homeDir', 'baseUrl'];

    protected $tokenCredentials;
    protected $oauthClient;

    protected function getInternalDomains()
    {
        $baseUrl = trim($this->config['baseUrl']);
        if (strpos($baseUrl, ':')) {
            $baseUrl = explode(':', $baseUrl)[0];
        }
        return array_merge(parent::getInternalDomains(), [
            '~' . str_replace([':','.'], ['\\:','\\.'], $baseUrl . '~')
        ]);
    }

    public function _initialize()
    {
        spl_autoload_register([$this, 'classLoader']);
    }

    /**
     * @param string $class
     */
    public function classLoader($class)
    {
        switch ($class) {
            case 'Varien_File_Uploader':
                require_once __DIR__ . '/Rewrites/Varien_File_Uploader.php';
                return true;
        }
    }

    public function _before(TestInterface $test)
    {
        $this->client = new Connector();

        $baseUrl = trim($this->config['baseUrl']);

        if (preg_match('~^https?~', $baseUrl)) {
            throw new \InvalidArgumentException(
                "Please, define baseUrl parameter without protocol part: localhost or localhost:8000"
            );
        }

        $this->headers['HOST'] = $baseUrl;


        if (isset($this->config['https']) && $this->config['https']) {
            $this->client->https = true;
        }

        $this->client->homeDir = Configuration::projectDir() . DIRECTORY_SEPARATOR . $this->config['homeDir'];
        $this->client->oauthAppCallback = $this->config['oauth_app_callback'];

        $this->replaceConfig();
    }

    public function _after(TestInterface $test)
    {
        $_SESSION = [];
        $_FILES = [];
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_REQUEST = [];

        $this->oauthClient = null;
        $this->tokenCredentials = null;

        $this->restoreConfig();
        parent::_after($test);
    }

    /**
     * @return string
     */
    protected function getOriginalConfigFilePath()
    {
        return $this->config['homeDir'] . '/app/etc/local.xml';
    }

    /**
     * @return null|string
     */
    protected function getBackupConfigPath()
    {
        return $this->config['homeDir'] . '/app/etc/local.xml.backup';
    }

    protected function cleanCache()
    {
        $realPath = realpath($this->config['homeDir']);
        if ($realPath) {
            $dir = $realPath . '/var/cache';
            if (DIRECTORY_SEPARATOR === '\\') {
                $dir = strtr($dir, '/', '\\');
                system('RMDIR /S /Q "'. $dir .'"');
            } else {
                system("rm -rf " . escapeshellarg($dir));
            }

        }
    }

    protected function replaceConfig()
    {
        if (!isset($this->config['rewrite_config']) ||
            !$this->config['rewrite_config']) {
            return;
        }

        if (!is_file($this->config['rewrite_config'])) {
            throw new Exception("Parameter 'rewrite_config' should be a file");
        }

        if (!is_readable($this->config['rewrite_config'])) {
            throw new Exception("Cant read " . $this->config['rewrite_config']);
        }

        rename($this->getOriginalConfigFilePath(), $this->getBackupConfigPath());

        copy($this->config['rewrite_config'], $this->getOriginalConfigFilePath());
        $this->cleanCache();
    }


    protected function restoreConfig()
    {
        if (!isset($this->config['rewrite_config']) ||
            !$this->config['rewrite_config']) {
            return;
        }

        $backupConfigPath = $this->getBackupConfigPath();
        if (file_exists($backupConfigPath)) {
            rename($backupConfigPath, $this->getOriginalConfigFilePath());
            $this->cleanCache();
        }

    }

    /**
     * @return string
     */
    protected function getScheme(): string
    {
        return $this->config['https'] && $this->config['https'] === true ? 'https': 'http';
    }

    /**
     * @return string
     */
    protected function getHostUrl(): string
    {
        return $this->getScheme() . '://' . $this->config['baseUrl'];
    }

    /**
     * @param array $adminCredentials ['user' => 'admin', 'password' => 'a1223456']
     * @return OAuthClient
     * @throws Exception
     */
    protected function getOauthClient(array $adminCredentials = []): OAuthClient
    {
        if ($this->oauthClient && !$adminCredentials) {
            return $this->oauthClient;
        }

        if (!$adminCredentials) {
            $adminCredentials = [
                'user'     => $this->config['default_admin_user'],
                'password' => $this->config['default_admin_password']
            ];
        }

        if (!isset($adminCredentials['user'])) {
            throw new Exception('Missing admin user parameter');
        }

        if (!isset($adminCredentials['password'])) {
            throw new Exception('Missing admin password parameter');
        }

        $this->oauthClient = new OAuthClient($this, [
            'identifier'     => $this->config['oauth_app_key'],
            'secret'         => $this->config['oauth_app_secret'],
            'callback_uri'   => $this->config['oauth_app_callback'],
            'host'           => $this->getHostUrl(),
            'admin'          => true,
            'admin_user'     => $adminCredentials['user'],
            'admin_password' => $adminCredentials['password'],
        ]);

        $tempCredentials = $this->oauthClient->getTemporaryCredentials();
        try
        {
            $this->oauthClient->authorize($tempCredentials);
        }
        catch (OAuthAppCallbackException $e) {
            $redirectUrl = $e->getRedirectValue();
            $result = parse_url($redirectUrl);
        }

        $query = \GuzzleHttp\Psr7\parse_query($result['query']);
        $temporaryIdentifier = $query['oauth_token'];
        $oauthVerifier       = $query['oauth_verifier'];

        $this->tokenCredentials = $this->oauthClient->getTokenCredentials(
            $tempCredentials,
            $temporaryIdentifier,
            $oauthVerifier
        );

        return $this->oauthClient;

    }

    /**
     * @param string $method
     * @param string $url
     * @param array $params
     * @param array $adminCredentials ['user' => 'admin', 'password' => 'a1223456']
     * @return mixed
     */
    public function sendApiRequest(string $method, string $url, array $params = [], array $adminCredentials = [])
    {
        $client = $this->getOauthClient($adminCredentials);

        if (!preg_match('~^/api/rest~', $url)) {
            $fullUrl = $this->getHostUrl() . "/api/rest$url";
        }  else {
            $fullUrl = $this->getHostUrl() . $url;
        }

        $this->headers = $client->getHeaders($this->tokenCredentials, $method, $fullUrl);
        $this->headers['Content-Type'] = 'application/json';
        // Parameters will be intercepted and encoded into json
        // see \Optimus\Magento1\Codeception\Rewrites\Mage_Api2_Model_Request
        $result  = $this->_request($method, $fullUrl, $params);
        $this->headers = [];
        $this->client->followRedirects(true);
        return $result;
    }

    /**
     * @param string $uri
     * @param string $field
     * @param string $file
     * @param array $params
     */
    public function sendAjaxFile(string $uri, string $field, string $file, array $params = [])
    {
        $this->attachFile($field, $file);

        $form = $form = $this->getFormFor($field = $this->getFieldByLabelOrCss($field));

        $files = $form->getPhpFiles();
        $this->clientRequest('POST', $uri, $params, $files, ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'], null, false);
    }

    /**
     * @return string
     */
    public function grabAdminFormKey(): string
    {
        $crawler = $this->match('script');
        foreach ($crawler->getIterator() as $node) {
            if (preg_match("/var\\s+FORM_KEY\\s+=\\s+'([A-Za-z0-9]+)'/m", $node->textContent, $matches)) {
                return $matches[1];
            }
        }
        return '';
    }

    protected function getRunningClient()
    {
        if ($this->client->getInternalRequest() === null) {
            throw new Exception("Response is empty. Use `\$I->sendApiRequest()` methods to send HTTP request");
        }
        return $this->client;
    }


    /**
     * Sets HTTP header valid for all next requests. Use `deleteHeader` to unset it
     *
     * ```php
     * <?php
     * $I->haveHttpHeader('Content-Type', 'application/json');
     * // all next requests will contain this header
     * ?>
     * ```
     *
     * @param $name
     * @param $value
     * @part json
     * @part xml
     */
    public function haveHttpHeader($name, $value)
    {
        $this->headers[$name] = $value;
    }

    /**
     * Deletes the header with the passed name.  Subsequent requests
     * will not have the deleted header in its request.
     *
     * Example:
     * ```php
     * <?php
     * $I->haveHttpHeader('X-Requested-With', 'Codeception');
     * $I->sendGET('test-headers.php');
     * // ...
     * $I->deleteHeader('X-Requested-With');
     * $I->sendPOST('some-other-page.php');
     * ?>
     * ```
     *
     * @param string $name the name of the header to delete.
     */
    public function deleteHeader($name)
    {
        unset($this->headers[$name]);
    }

    /**
     * Checks over the given HTTP header and (optionally)
     * its value, asserting that are there
     *
     * @param $name
     * @param $value
     * @part json
     * @part xml
     */
    public function seeHttpHeader($name, $value = null)
    {
        if ($value !== null) {
            $this->assertEquals(
                $value,
                $this->getRunningClient()->getInternalResponse()->getHeader($name)
            );
            return;
        }
        $this->assertNotNull($this->getRunningClient()->getInternalResponse()->getHeader($name));
    }

    /**
     * Checks over the given HTTP header and (optionally)
     * its value, asserting that are not there
     *
     * @param $name
     * @param $value
     * @part json
     * @part xml
     */
    public function dontSeeHttpHeader($name, $value = null)
    {
        if ($value !== null) {
            $this->assertNotEquals(
                $value,
                $this->getRunningClient()->getInternalResponse()->getHeader($name)
            );
            return;
        }
        $this->assertNull($this->getRunningClient()->getInternalResponse()->getHeader($name));
    }

    /**
     * Checks that http response header is received only once.
     * HTTP RFC2616 allows multiple response headers with the same name.
     * You can check that you didn't accidentally sent the same header twice.
     *
     * ``` php
     * <?php
     * $I->seeHttpHeaderOnce('Cache-Control');
     * ?>>
     * ```
     *
     * @param $name
     * @part json
     * @part xml
     */
    public function seeHttpHeaderOnce($name)
    {
        $headers = $this->getRunningClient()->getInternalResponse()->getHeader($name, false);
        $this->assertEquals(1, count($headers));
    }

    /**
     * Returns the value of the specified header name
     *
     * @param $name
     * @param Boolean $first Whether to return the first value or all header values
     *
     * @return string|array The first header value if $first is true, an array of values otherwise
     * @part json
     * @part xml
     */
    public function grabHttpHeader($name, $first = true)
    {
        return $this->getRunningClient()->getInternalResponse()->getHeader($name, $first);
    }


    /**
     * Checks whether last response was valid JSON.
     * This is done with json_last_error function.
     *
     * @part json
     */
    public function seeResponseIsJson()
    {
        $responseContent = $this->_getResponseContent();
        \PHPUnit_Framework_Assert::assertNotEquals('', $responseContent, 'response is empty');
        json_decode($responseContent);
        $errorCode = json_last_error();
        $errorMessage = json_last_error_msg();
        \PHPUnit_Framework_Assert::assertEquals(
            JSON_ERROR_NONE,
            $errorCode,
            sprintf(
                "Invalid json: %s. System message: %s.",
                $responseContent,
                $errorMessage
            )
        );
    }

    /**
     * Checks whether the last response contains text.
     *
     * @param $text
     * @part json
     * @part xml
     */
    public function seeResponseContains($text)
    {
        $this->assertContains($text, $this->_getResponseContent(), "REST response contains");
    }

    /**
     * Checks whether last response do not contain text.
     *
     * @param $text
     * @part json
     * @part xml
     */
    public function dontSeeResponseContains($text)
    {
        $this->assertNotContains($text, $this->_getResponseContent(), "REST response contains");
    }

    /**
     * Checks whether the last JSON response contains provided array.
     * The response is converted to array with json_decode($response, true)
     * Thus, JSON is represented by associative array.
     * This method matches that response array contains provided array.
     *
     * Examples:
     *
     * ``` php
     * <?php
     * // response: {name: john, email: john@gmail.com}
     * $I->seeResponseContainsJson(array('name' => 'john'));
     *
     * // response {user: john, profile: { email: john@gmail.com }}
     * $I->seeResponseContainsJson(array('email' => 'john@gmail.com'));
     *
     * ?>
     * ```
     *
     * This method recursively checks if one array can be found inside of another.
     *
     * @param array $json
     * @part json
     */
    public function seeResponseContainsJson($json = [])
    {
        \PHPUnit_Framework_Assert::assertThat(
            $this->_getResponseContent(),
            new JsonContains($json)
        );
    }

    /**
     * Returns current response so that it can be used in next scenario steps.
     *
     * Example:
     *
     * ``` php
     * <?php
     * $user_id = $I->grabResponse();
     * $I->sendPUT('/user', array('id' => $user_id, 'name' => 'davert'));
     * ?>
     * ```
     *
     * @version 1.1
     * @return string
     * @part json
     * @part xml
     */
    public function grabResponse()
    {
        return $this->_getResponseContent();
    }

    /**
     * Returns data from the current JSON response using [JSONPath](http://goessner.net/articles/JsonPath/) as selector.
     * JsonPath is XPath equivalent for querying Json structures.
     * Try your JsonPath expressions [online](http://jsonpath.curiousconcept.com/).
     * Even for a single value an array is returned.
     *
     * This method **require [`flow/jsonpath` > 0.2](https://github.com/FlowCommunications/JSONPath/) library to be installed**.
     *
     * Example:
     *
     * ``` php
     * <?php
     * // match the first `user.id` in json
     * $firstUserId = $I->grabDataFromResponseByJsonPath('$..users[0].id');
     * $I->sendPUT('/user', array('id' => $firstUserId[0], 'name' => 'davert'));
     * ?>
     * ```
     *
     * @param string $jsonPath
     * @return array Array of matching items
     * @version 2.0.9
     * @throws \Exception
     * @part json
     */
    public function grabDataFromResponseByJsonPath($jsonPath)
    {
        return (new JsonArray($this->_getResponseContent()))->filterByJsonPath($jsonPath);
    }

    /**
     * Checks if json structure in response matches the xpath provided.
     * JSON is not supposed to be checked against XPath, yet it can be converted to xml and used with XPath.
     * This assertion allows you to check the structure of response json.
     *     *
     * ```json
     *   { "store": {
     *       "book": [
     *         { "category": "reference",
     *           "author": "Nigel Rees",
     *           "title": "Sayings of the Century",
     *           "price": 8.95
     *         },
     *         { "category": "fiction",
     *           "author": "Evelyn Waugh",
     *           "title": "Sword of Honour",
     *           "price": 12.99
     *         }
     *    ],
     *       "bicycle": {
     *         "color": "red",
     *         "price": 19.95
     *       }
     *     }
     *   }
     * ```
     *
     * ```php
     * <?php
     * // at least one book in store has author
     * $I->seeResponseJsonMatchesXpath('//store/book/author');
     * // first book in store has author
     * $I->seeResponseJsonMatchesXpath('//store/book[1]/author');
     * // at least one item in store has price
     * $I->seeResponseJsonMatchesXpath('/store//price');
     * ?>
     * ```
     * @param string $xpath
     * @part json
     * @version 2.0.9
     */
    public function seeResponseJsonMatchesXpath($xpath)
    {
        $response = $this->_getResponseContent();
        $this->assertGreaterThan(
            0,
            (new JsonArray($response))->filterByXPath($xpath)->length,
            "Received JSON did not match the XPath `$xpath`.\nJson Response: \n" . $response
        );
    }

    /**
     * Opposite to seeResponseJsonMatchesXpath
     *
     * @param string $xpath
     * @part json
     */
    public function dontSeeResponseJsonMatchesXpath($xpath)
    {
        $response = $this->_getResponseContent();
        $this->assertEquals(
            0,
            (new JsonArray($response))->filterByXPath($xpath)->length,
            "Received JSON matched the XPath `$xpath`.\nJson Response: \n" . $response
        );
    }

    /**
     * Checks if json structure in response matches [JsonPath](http://goessner.net/articles/JsonPath/).
     * JsonPath is XPath equivalent for querying Json structures.
     * Try your JsonPath expressions [online](http://jsonpath.curiousconcept.com/).
     * This assertion allows you to check the structure of response json.
     *
     * This method **require [`flow/jsonpath` > 0.2](https://github.com/FlowCommunications/JSONPath/) library to be installed**.
     *
     * ```json
     *   { "store": {
     *       "book": [
     *         { "category": "reference",
     *           "author": "Nigel Rees",
     *           "title": "Sayings of the Century",
     *           "price": 8.95
     *         },
     *         { "category": "fiction",
     *           "author": "Evelyn Waugh",
     *           "title": "Sword of Honour",
     *           "price": 12.99
     *         }
     *    ],
     *       "bicycle": {
     *         "color": "red",
     *         "price": 19.95
     *       }
     *     }
     *   }
     * ```
     *
     * ```php
     * <?php
     * // at least one book in store has author
     * $I->seeResponseJsonMatchesJsonPath('$.store.book[*].author');
     * // first book in store has author
     * $I->seeResponseJsonMatchesJsonPath('$.store.book[0].author');
     * // at least one item in store has price
     * $I->seeResponseJsonMatchesJsonPath('$.store..price');
     * ?>
     * ```
     *
     * @param string $jsonPath
     * @part json
     * @version 2.0.9
     */
    public function seeResponseJsonMatchesJsonPath($jsonPath)
    {
        $response = $this->_getResponseContent();
        $this->assertNotEmpty(
            (new JsonArray($response))->filterByJsonPath($jsonPath),
            "Received JSON did not match the JsonPath `$jsonPath`.\nJson Response: \n" . $response
        );
    }

    /**
     * Opposite to seeResponseJsonMatchesJsonPath
     *
     * @param string $jsonPath
     * @part json
     */
    public function dontSeeResponseJsonMatchesJsonPath($jsonPath)
    {
        $response = $this->_getResponseContent();
        $this->assertEmpty(
            (new JsonArray($response))->filterByJsonPath($jsonPath),
            "Received JSON matched the JsonPath `$jsonPath`.\nJson Response: \n" . $response
        );
    }

    /**
     * Opposite to seeResponseContainsJson
     *
     * @part json
     * @param array $json
     */
    public function dontSeeResponseContainsJson($json = [])
    {
        $jsonResponseArray = new JsonArray($this->_getResponseContent());
        $this->assertFalse(
            $jsonResponseArray->containsArray($json),
            "Response JSON contains provided JSON\n"
            . "- <info>" . var_export($json, true) . "</info>\n"
            . "+ " . var_export($jsonResponseArray->toArray(), true)
        );
    }

    /**
     * Checks that Json matches provided types.
     * In case you don't know the actual values of JSON data returned you can match them by type.
     * Starts check with a root element. If JSON data is array it will check the first element of an array.
     * You can specify the path in the json which should be checked with JsonPath
     *
     * Basic example:
     *
     * ```php
     * <?php
     * // {'user_id': 1, 'name': 'davert', 'is_active': false}
     * $I->seeResponseMatchesJsonType([
     *      'user_id' => 'integer',
     *      'name' => 'string|null',
     *      'is_active' => 'boolean'
     * ]);
     *
     * // narrow down matching with JsonPath:
     * // {"users": [{ "name": "davert"}, {"id": 1}]}
     * $I->seeResponseMatchesJsonType(['name' => 'string'], '$.users[0]');
     * ?>
     * ```
     *
     * In this case you can match that record contains fields with data types you expected.
     * The list of possible data types:
     *
     * * string
     * * integer
     * * float
     * * array (json object is array as well)
     * * boolean
     *
     * You can also use nested data type structures:
     *
     * ```php
     * <?php
     * // {'user_id': 1, 'name': 'davert', 'company': {'name': 'Codegyre'}}
     * $I->seeResponseMatchesJsonType([
     *      'user_id' => 'integer|string', // multiple types
     *      'company' => ['name' => 'string']
     * ]);
     * ?>
     * ```
     *
     * You can also apply filters to check values. Filter can be applied with `:` char after the type declatation.
     *
     * Here is the list of possible filters:
     *
     * * `integer:>{val}` - checks that integer is greater than {val} (works with float and string types too).
     * * `integer:<{val}` - checks that integer is lower than {val} (works with float and string types too).
     * * `string:url` - checks that value is valid url.
     * * `string:date` - checks that value is date in JavaScript format: https://weblog.west-wind.com/posts/2014/Jan/06/JavaScript-JSON-Date-Parsing-and-real-Dates
     * * `string:email` - checks that value is a valid email according to http://emailregex.com/
     * * `string:regex({val})` - checks that string matches a regex provided with {val}
     *
     * This is how filters can be used:
     *
     * ```php
     * <?php
     * // {'user_id': 1, 'email' => 'davert@codeception.com'}
     * $I->seeResponseMatchesJsonType([
     *      'user_id' => 'string:>0:<1000', // multiple filters can be used
     *      'email' => 'string:regex(~\@~)' // we just check that @ char is included
     * ]);
     *
     * // {'user_id': '1'}
     * $I->seeResponseMatchesJsonType([
     *      'user_id' => 'string:>0', // works with strings as well
     * }
     * ?>
     * ```
     *
     * You can also add custom filters y accessing `JsonType::addCustomFilter` method.
     * See [JsonType reference](http://codeception.com/docs/reference/JsonType).
     *
     * @part json
     * @version 2.1.3
     * @param array $jsonType
     * @param string $jsonPath
     */
    public function seeResponseMatchesJsonType(array $jsonType, $jsonPath = null)
    {
        $jsonArray = new JsonArray($this->_getResponseContent());
        if ($jsonPath) {
            $jsonArray = $jsonArray->filterByJsonPath($jsonPath);
        }

        \PHPUnit_Framework_Assert::assertThat($jsonArray, new JsonTypeConstraint($jsonType));
    }

    /**
     * Opposite to `seeResponseMatchesJsonType`.
     *
     * @part json
     * @see seeResponseMatchesJsonType
     * @param $jsonType jsonType structure
     * @param null $jsonPath optionally set specific path to structure with JsonPath
     * @version 2.1.3
     */
    public function dontSeeResponseMatchesJsonType($jsonType, $jsonPath = null)
    {
        $jsonArray = new JsonArray($this->_getResponseContent());
        if ($jsonPath) {
            $jsonArray = $jsonArray->filterByJsonPath($jsonPath);
        }

        \PHPUnit_Framework_Assert::assertThat($jsonArray, new JsonTypeConstraint($jsonType, false));
    }

    /**
     * Checks if response is exactly the same as provided.
     *
     * @part json
     * @part xml
     * @param $response
     */
    public function seeResponseEquals($expected)
    {
        $this->assertEquals($expected, $this->_getResponseContent());
    }

    /**
     * Checks whether last response was valid XML.
     * This is done with libxml_get_last_error function.
     *
     * @part xml
     */
    public function seeResponseIsXml()
    {
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($this->_getResponseContent());
        $num = "";
        $title = "";
        if ($doc === false) {
            $error = libxml_get_last_error();
            $num = $error->code;
            $title = trim($error->message);
            libxml_clear_errors();
        }
        libxml_use_internal_errors(false);
        \PHPUnit_Framework_Assert::assertNotSame(
            false,
            $doc,
            "xml decoding error #$num with message \"$title\", see http://www.xmlsoft.org/html/libxml-xmlerror.html"
        );
    }

    /**
     * Checks wheather XML response matches XPath
     *
     * ```php
     * <?php
     * $I->seeXmlResponseMatchesXpath('//root/user[@id=1]');
     * ```
     * @part xml
     * @param $xpath
     */
    public function seeXmlResponseMatchesXpath($xpath)
    {
        $structure = new XmlStructure($this->_getResponseContent());
        $this->assertTrue($structure->matchesXpath($xpath), 'xpath not matched');
    }

    /**
     * Checks wheather XML response does not match XPath
     *
     * ```php
     * <?php
     * $I->dontSeeXmlResponseMatchesXpath('//root/user[@id=1]');
     * ```
     * @part xml
     * @param $xpath
     */
    public function dontSeeXmlResponseMatchesXpath($xpath)
    {
        $structure = new XmlStructure($this->_getResponseContent());
        $this->assertFalse($structure->matchesXpath($xpath), 'accidentally matched xpath');
    }

    /**
     * Finds and returns text contents of element.
     * Element is matched by either CSS or XPath
     *
     * @param $cssOrXPath
     * @return string
     * @part xml
     */
    public function grabTextContentFromXmlElement($cssOrXPath)
    {
        $el = (new XmlStructure($this->_getResponseContent()))->matchElement($cssOrXPath);
        return $el->textContent;
    }

    /**
     * Finds and returns attribute of element.
     * Element is matched by either CSS or XPath
     *
     * @param $cssOrXPath
     * @param $attribute
     * @return string
     * @part xml
     */
    public function grabAttributeFromXmlElement($cssOrXPath, $attribute)
    {
        $el = (new XmlStructure($this->_getResponseContent()))->matchElement($cssOrXPath);
        if (!$el->hasAttribute($attribute)) {
            $this->fail("Attribute not found in element matched by '$cssOrXPath'");
        }
        return $el->getAttribute($attribute);
    }

    /**
     * Checks XML response equals provided XML.
     * Comparison is done by canonicalizing both xml`s.
     *
     * Parameters can be passed either as DOMDocument, DOMNode, XML string, or array (if no attributes).
     *
     * @param $xml
     * @part xml
     */
    public function seeXmlResponseEquals($xml)
    {
        \PHPUnit_Framework_Assert::assertXmlStringEqualsXmlString($this->_getResponseContent(), $xml);
    }


    /**
     * Checks XML response does not equal to provided XML.
     * Comparison is done by canonicalizing both xml`s.
     *
     * Parameter can be passed either as XmlBuilder, DOMDocument, DOMNode, XML string, or array (if no attributes).
     *
     * @param $xml
     * @part xml
     */
    public function dontSeeXmlResponseEquals($xml)
    {
        \PHPUnit_Framework_Assert::assertXmlStringNotEqualsXmlString(
            $this->_getResponseContent(),
            $xml
        );
    }

    /**
     * Checks XML response includes provided XML.
     * Comparison is done by canonicalizing both xml`s.
     * Parameter can be passed either as XmlBuilder, DOMDocument, DOMNode, XML string, or array (if no attributes).
     *
     * Example:
     *
     * ``` php
     * <?php
     * $I->seeXmlResponseIncludes("<result>1</result>");
     * ?>
     * ```
     *
     * @param $xml
     * @part xml
     */
    public function seeXmlResponseIncludes($xml)
    {
        $this->assertContains(
            XmlUtils::toXml($xml)->C14N(),
            XmlUtils::toXml($this->_getResponseContent())->C14N(),
            "found in XML Response"
        );
    }

    /**
     * Checks XML response does not include provided XML.
     * Comparison is done by canonicalizing both xml`s.
     * Parameter can be passed either as XmlBuilder, DOMDocument, DOMNode, XML string, or array (if no attributes).
     *
     * @param $xml
     * @part xml
     */
    public function dontSeeXmlResponseIncludes($xml)
    {
        $this->assertNotContains(
            XmlUtils::toXml($xml)->C14N(),
            XmlUtils::toXml($this->_getResponseContent())->C14N(),
            "found in XML Response"
        );
    }
}