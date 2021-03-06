<?php
/**
 * User: zach
 * Date: 5/1/13
 * Time: 9:59 PM
 */

namespace Elasticsearch\Connections;

use Elasticsearch\Common\Exceptions\AlreadyExpiredException;
use Elasticsearch\Common\Exceptions\Conflict409Exception;
use Elasticsearch\Common\Exceptions\Forbidden403Exception;
use Elasticsearch\Common\Exceptions\InvalidArgumentException;
use Elasticsearch\Common\Exceptions\Missing404Exception;
use Elasticsearch\Common\Exceptions\NoDocumentsToGetException;
use Elasticsearch\Common\Exceptions\NoShardAvailableException;
use Elasticsearch\Common\Exceptions\RoutingMissingException;
use Elasticsearch\Common\Exceptions\RuntimeException;
use Elasticsearch\Common\Exceptions\ScriptLangNotSupportedException;
use Elasticsearch\Common\Exceptions\ServerErrorResponseException;
use Elasticsearch\Common\Exceptions\TransportException;
use Guzzle\Parser\ParserRegistry;
use Psr\Log\LoggerInterface;

/**
 * Class Connection
 *
 * @category Elasticsearch
 * @package  Elasticsearch\CurlMultiConnection
 * @author   Zachary Tong <zachary.tong@elasticsearch.com>
 * @license  http://www.apache.org/licenses/LICENSE-2.0 Apache2
 * @link     http://elasticsearch.org
 */
class CurlMultiConnection extends AbstractConnection implements ConnectionInterface
{

    /**
     * @var Resource
     */
    private $multiHandle;

    private $headers;


    /**
     * Constructor
     *
     * @param string                   $host             Host string
     * @param int                      $port             Host port
     * @param array                    $connectionParams Array of connection parameters
     * @param \Psr\Log\LoggerInterface $log              logger object
     * @param \Psr\Log\LoggerInterface $trace            logger object (for curl traces)
     *
     * @throws \Elasticsearch\Common\Exceptions\RuntimeException
     * @throws \Elasticsearch\Common\Exceptions\InvalidArgumentException
     * @return CurlMultiConnection
     */
    public function __construct($host, $port, $connectionParams, LoggerInterface $log, LoggerInterface $trace)
    {
        if (function_exists('curl_version') !== true) {
            $log->critical('Curl library/extension is required for CurlMultiConnection.');
            throw new RuntimeException('Curl library/extension is required for CurlMultiConnection.');
        }

        if (isset($connectionParams['curlMultiHandle']) !== true) {
            $log->critical('curlMultiHandle must be set in connectionParams');
            throw new InvalidArgumentException('curlMultiHandle must be set in connectionParams');
        }

        if (isset($port) !== true) {
            $port = 9200;
        }

        $connectionParams = $this->transformAuth($connectionParams);

        $this->multiHandle = $connectionParams['curlMultiHandle'];
        return parent::__construct($host, $port, $connectionParams, $log, $trace);

    }


    /**
     * Returns the transport schema
     *
     * @return string
     */
    public function getTransportSchema()
    {
        return $this->transportSchema;

    }


    /**
     * Perform an HTTP request on the cluster
     *
     * @param string      $method  HTTP method to use for request
     * @param string      $uri     HTTP URI to use for request
     * @param null|string $params  Optional URI parameters
     * @param null|string $body    Optional request body
     * @param array       $options Optional options
     *
     * @throws \Elasticsearch\Common\Exceptions\TransportException
     * @throws \Elasticsearch\Common\Exceptions\ServerErrorResponseException
     * @return array
     */
    public function performRequest($method, $uri, $params = null, $body = null, $options = array())
    {
        $uri = $this->getURI($uri, $params);

        $curlHandle = curl_init();

        $opts = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS     => 1000,
            CURLOPT_CONNECTTIMEOUT_MS => 1000,
            CURLOPT_URL            => $uri,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HEADER         => true
        );

        if ($method === 'GET') {
            //Force these since Curl won't reset by itself
            $opts[CURLOPT_NOBODY] = false;
        } else if ($method === 'HEAD') {
            $opts[CURLOPT_NOBODY] = true;
        }

        if (isset($body) === true) {
            if ($method === 'GET'){
                $opts[CURLOPT_CUSTOMREQUEST] = 'POST';
            }
            $opts[CURLOPT_POSTFIELDS] = $body;
        }

        if (isset($this->headers) && count($this->headers) > 0) {
            $opts[CURLOPT_HTTPHEADER] = $this->headers;
        }

        // TODO reconcile these with $options
        if (isset($this->connectionParams['connectionParams']) === true) {

            //MUST use union operator, array_merge rekeys numeric
            $opts = $opts + $this->connectionParams['connectionParams'];
        }

        $this->log->debug("Curl Options:", $opts);

        curl_setopt_array($curlHandle, $opts);
        curl_multi_add_handle($this->multiHandle, $curlHandle);

        $response = array();


        do {

            do {
                $execrun = curl_multi_exec($this->multiHandle, $running);
            } while ($execrun == CURLM_CALL_MULTI_PERFORM && $running === 1);

            if ($execrun !== CURLM_OK) {
                $this->log->critical('Unexpected Curl error: ' . $execrun);
                throw new TransportException('Unexpected Curl error: ' . $execrun);
            }

            /*
                Curl_multi_select not strictly necessary, since we are only
                performing one request total.  May be useful if we ever
                implement batching

                From Guzzle: https://github.com/guzzle/guzzle/blob/master/src/Guzzle/Http/Curl/CurlMulti.php#L314
                Select the curl handles until there is any
                activity on any of the open file descriptors. See:
                https://github.com/php/php-src/blob/master/ext/curl/multi.c#L170
            */

            if ($running === 1 && $execrun === CURLM_OK && curl_multi_select($this->multiHandle, 0.5) === -1) {
                /*
                    Perform a usleep if a previously executed select returned -1
                    @see https://bugs.php.net/bug.php?id=61141
                */

                usleep(100);
            }

            // A request was just completed.
            while ($transfer = curl_multi_info_read($this->multiHandle)) {
                $response['responseText'] = curl_multi_getcontent($transfer['handle']);
                $response['errorNumber']  = curl_errno($transfer['handle']);
                $response['error']        = curl_error($transfer['handle']);
                $response['requestInfo']  = curl_getinfo($transfer['handle']);
                curl_multi_remove_handle($this->multiHandle, $transfer['handle']);

            }
        } while ($running === 1);

        // If there was an error response, something like a time-out or
        // refused connection error occurred.
        if ($response['error'] !== '') {
            $this->processCurlError($method, $uri, $response);
        }

        // Use Guzzle's message parser.  Loads an additional two classes
        // TODO consider inlining this code
        $response['responseText'] = ParserRegistry::getInstance()->getParser('message')->parseResponse($response['responseText']);
        $response['responseText'] = $response['responseText']['body'];

        if ($response['requestInfo']['http_code'] >= 400 && $response['requestInfo']['http_code'] < 500) {
            $this->process4xxError($method, $uri, $response);
        } else if ($response['requestInfo']['http_code'] >= 500) {
            $this->process5xxError($method, $uri, $response);
        }

        $this->logRequestSuccess(
            $method,
            $uri,
            $body,
            "",  //TODO FIX THIS
            $response['requestInfo']['http_code'],
            $response['responseText'],
            $response['requestInfo']['total_time']
        );



        return array(
            'status' => $response['requestInfo']['http_code'],
            'text'   => $response['responseText'],
            'info'   => $response['requestInfo'],
        );

    }


    /**
     * @param $method
     * @param $uri
     * @param $response
     *
     * @throws \Elasticsearch\Common\Exceptions\ScriptLangNotSupportedException
     * @throws \Elasticsearch\Common\Exceptions\Forbidden403Exception
     * @throws \Elasticsearch\Common\Exceptions\Conflict409Exception
     * @throws \Elasticsearch\Common\Exceptions\Missing404Exception
     * @throws \Elasticsearch\Common\Exceptions\AlreadyExpiredException
     */
    private function process4xxError($method, $uri, $response)
    {
        $this->logErrorDueToFailure($method, $uri, $response);

        $statusCode    = $response['requestInfo']['http_code'];
        $exceptionText = $response['error'];
        $responseBody  = $response['responseText'];

        $exceptionText = "$statusCode Server Exception: $exceptionText\n$responseBody";

        if ($statusCode === 400 && strpos($responseBody, "AlreadyExpiredException") !== false) {
            throw new AlreadyExpiredException($exceptionText, $statusCode);
        } elseif ($statusCode === 403) {
            throw new Forbidden403Exception($exceptionText, $statusCode);
        } elseif ($statusCode === 404) {
            throw new Missing404Exception($exceptionText, $statusCode);
        } elseif ($statusCode === 409) {
            throw new Conflict409Exception($exceptionText, $statusCode);
        } elseif ($statusCode === 400 && strpos($responseBody, 'script_lang not supported') !== false) {
            throw new ScriptLangNotSupportedException($exceptionText. $statusCode);
        }
    }


    /**
     * @param $method
     * @param $uri
     * @param $response
     *
     * @throws \Elasticsearch\Common\Exceptions\RoutingMissingException
     * @throws \Elasticsearch\Common\Exceptions\NoShardAvailableException
     * @throws \Elasticsearch\Common\Exceptions\NoDocumentsToGetException
     * @throws \Elasticsearch\Common\Exceptions\ServerErrorResponseException
     */
    private function process5xxError($method, $uri, $response)
    {
        $this->logErrorDueToFailure($method, $uri, $response);

        $statusCode    = $response['requestInfo']['http_code'];
        $exceptionText = $response['error'];
        $responseBody  = $response['responseText'];

        $exceptionText = "$statusCode Server Exception: $exceptionText\n$responseBody";
        $this->log->error($exceptionText);

        if ($statusCode === 500 && strpos($responseBody, "RoutingMissingException") !== false) {
            throw new RoutingMissingException($exceptionText, $statusCode);
        } elseif ($statusCode === 500 && preg_match('/ActionRequestValidationException.+ no documents to get/',$responseBody) === 1) {
            throw new NoDocumentsToGetException($exceptionText, $statusCode);
        } elseif ($statusCode === 500 && strpos($responseBody, 'NoShardAvailableActionException') !== false) {
            throw new NoShardAvailableException($exceptionText, $statusCode);
        } else {
            throw new ServerErrorResponseException($exceptionText, $statusCode);
        }


    }


    /**
     * @param $method
     * @param $uri
     * @param $response
     */
    private function processCurlError($method, $uri, $response)
    {
        $error = 'Curl error: ' . $response['error'];
        $this->log->error($error);
        $this->throwCurlException($response['errorNumber'], $response['error']);
    }


    /**
     * @param $method
     * @param $uri
     * @param $response
     */
    private function logErrorDueToFailure($method, $uri, $response)
    {
        $this->logRequestFail(
            $method,
            $uri,
            $response['requestInfo']['total_time'],
            $response['requestInfo']['http_code'],
            $response['responseText'],
            $response['error']
        );
    }



    /**
     * @param array $connectionParams
     * @return array
     */
    private function transformAuth($connectionParams)
    {
        if (isset($connectionParams['auth']) !== true) {
            return $connectionParams;
        }

        $username = $connectionParams['auth'][0];
        $password = $connectionParams['auth'][1];

        switch ($connectionParams['auth'][2]) {
            case 'Basic':
                $connectionParams['connectionParams'][CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
                $this->headers['authorization'] =  'Basic '.base64_encode("$username:$password");
                unset($connectionParams['auth']);
                return $connectionParams;
                break;

            case 'Digest':
                $connectionParams['connectionParams'][CURLOPT_HTTPAUTH] = CURLAUTH_DIGEST;
                break;

            case 'NTLM':
                $connectionParams['connectionParams'][CURLOPT_HTTPAUTH] = CURLAUTH_NTLM;
                break;

            case 'Any':
                $connectionParams['connectionParams'][CURLOPT_HTTPAUTH] = CURLAUTH_ANY;
                break;
        }


        $connectionParams['connectionParams'][CURLOPT_USERPWD] = "$username:$password";

        unset($connectionParams['auth']);
        return $connectionParams;

    }

    /**
     * @param string $uri
     * @param array $params
     *
     * @return string
     */
    private function getURI($uri, $params)
    {
        $uri = $this->host . $uri;

        if (isset($params) === true) {
            $uri .= '?' . http_build_query($params);
        }

        return $uri;
    }

}