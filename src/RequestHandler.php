<?php
/**
 *
 * This file is licensed under the MIT License. See the LICENSE file.
 *
 * @author Dmitry Volynkin <thesaturn@thesaturn.me>
 */

namespace thesaturn\C14BackupTool;

use ErrorException;
/**
 * Dummy class to send http queries
 * Class RequestHandler
 * @package TheSaturn\C14BackupTool
 */
class RequestHandler
{
    /**
     * @param string $httpMethod
     * @param string $endpoint
     * @param string $token
     * @param string $params
     * @return mixed
     * @throws ErrorException
     */
    public function sendQuery($httpMethod, $endpoint, $token, $params)
    {
        $curl = curl_init();
        switch ($httpMethod)
        {
            case 'GET':
                $endpoint .= '?' . http_build_query($params);
                break;
            case 'POST':
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($params));
                break;
            case 'PATCH':
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PATCH');
                curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($params));
                break;
            default:
                throw new ErrorException('Unknown method: ' . $httpMethod);
        }
        curl_setopt($curl, CURLOPT_HTTPHEADER,
            ['Authorization: Bearer ' . $token, 'X-Pretty-JSON: 1']);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, TRUE);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_BINARYTRANSFER, true);
        curl_setopt($curl, CURLOPT_URL, 'https://api.online.net/api/v1' . $endpoint);
        return curl_exec($curl);
    }
}