<?php

namespace Optimus\Magento1\Codeception\Rewrites;

use Optimus\Magento1\Codeception\Interfaces\CollectedHeadersInterface;

class Mage_Core_Controller_Response_Http extends \Mage_Core_Controller_Response_Http
    implements CollectedHeadersInterface
{
    /**
     * Method send already collected headers and exit from script
     */
    public function sendHeadersAndExit()
    {
        $this->sendHeaders();
        $e = new \Optimus\Magento1\Codeception\Exceptions\ExitException();
        $e->setResponse($this);
        throw $e;
    }
    

    /**
     * @return  array
     */
    public function getCollectedHeaders(): array
    {
        $headers = [];
        foreach ($this->_headersRaw as $header) {
            list($name, $value) = explode(':', $header);
            if (!$value) {
                continue;
            }

            $headers[trim($name)] = trim($value);
        }

        foreach ($this->_headers as $header) {
            $headers[$header['name']] =  $header['value'];
        }

        return $headers;
    }

}