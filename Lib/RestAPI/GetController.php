<?php
/*
 * Copyright © MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Alexey Portnov, 8 2020
 */

namespace Modules\ModuleMtsPbx\Lib\RestAPI;
use MikoPBX\Core\System\BeanstalkClient;
use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Core\Workers\WorkerCdr;
use MikoPBX\PBXCoreREST\Controllers\BaseController;
use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\FileCookieJar;
use Modules\ModuleCTIClient\Models\ModuleCTIClient;
use Modules\ModuleMtsPbx\Lib\CacheManager;
use Modules\ModuleMtsPbx\Models\CallHistory;
use Modules\ModuleMtsPbx\Models\ModuleMtsPbx;

class GetController extends BaseController
{

    public const USER_LIST = 'user_list';

    /**
     * Последовательная загрузка данных из cdr таблицы.
     * curl 'http://127.0.0.1:80/pbxcore/mts-pbx/cdr?offset=619640&limit=1';
     */
    public function getDataAction(): void
    {
        $offset = intval($this->request->get('offset'));
        $limit  = intval($this->request->get('limit'));
        $limit  = max($limit, 5000);
        $limit  = 5000;
        $maxOffset = 0;

        $filter = [
            'id>:id: OR start>=:start:',
            'bind' => [
                'id' => $offset,
            ],
            'order' => 'id',
            'limit' => $limit
        ];

        try {
            $dt = new DateTime();
            $dt->setTime(0, 0, 0);
            $filter['bind']['start'] = $dt->format('Y-m-d H:i:s');
            $arr_data = CallHistory::find($filter)->toArray();
        }catch (\Exception $e){
            $arr_data = [];
        }

        usort($arr_data, function($a, $b) {
            return strtotime($a['start']) <=> strtotime($b['start']);
        });
        $xml_output = "<history>".PHP_EOL;
        foreach ($arr_data as $data) {
            $maxOffset = max($maxOffset, $data['id']);
            if(!str_starts_with($data['linkedid'],'fs-mts-')){
                continue;
            }
            $xml_output .= "<history_record no=\"$data[linkedid]\" entire_id=\"$data[linkedid]\" line=\"$data[did]\">";
            $detailAttr = [
                'call_id'      => $data['linkedid'],
                'status'       => $data['disposition'] === 'ANSWERED'?'ANSWER':'CANCEL',
                'call_flow'    => '',
                'queue'        => '',
                'start'        => $data['start'],
                'started'      => (new DateTime($data['start']))->format('c'),
                'answered'     => (new DateTime($data['answer']))->format('c'),
                'finished'     => (new DateTime($data['endtime']))->format('c'),
                'duration'     => $data['duration'],
                'conversation' => $data['billsec'],
                'record_file'  => $data['recordingfile'],
                'finish_cause' => 'Normal Clearing',
            ];
            $attributesDetail = '';
            foreach ($detailAttr as $tmp_key => $tmp_val) {
                $attributesDetail .= sprintf('%s="%s" ', $tmp_key, $tmp_val);
            }
            $xml_output .= "<details $attributesDetail />";
            $xml_output .= "<from ext=\"\" number=\"$data[src_num]\"></from>";
            $xml_output .= "<to ext=\"\" number=\"$data[dst_num]\"></to>";
            $xml_output .= '</history_record>'.PHP_EOL;
        }

        $xml_output .= '</history>'.PHP_EOL;
        $this->response->setContent($xml_output);
        $this->response->setHeader('X-MIN-OFFSET', $offset);
        $this->response->setHeader('X-MAX-OFFSET', max($maxOffset, $offset));
        $this->response->sendRaw();
    }

    /**
     * curl -u test:123 -X POST -d '{"ServiceType":7,"CallID":75317940442,"Domain":"9807100241.RU","EventType":"CallAccepted","EventTime":"2025-11-27T13:49:40+03:00","From":{"ANI":"9198896688"},"To":{"DNIS":"9109136612","Virtual":"110","TrunkId":"9807100241"}}' http://127.0.0.1/pbxcore/api/mts-pbx/v1/event
     * curl -u test:123 -X POST -d '{"ServiceType":7,"CallID":75317940442,"Domain":"9807100241.RU","EventType":"CallAnswered","EventTime":"2025-11-27T13:49:49.5970279+03:00","From":{"ANI":"9198896688"},"To":{"DNIS":"9109136612","Virtual":"110","TrunkId":"9807100241"}}' http://127.0.0.1/pbxcore/api/mts-pbx/v1/event
     * curl -u test:123 -X POST -d '{"ServiceType":7,"CallID":75317940442,"Domain":"9807100241.RU","EventType":"EndCall","CallResult":"2","EventTime":"2025-11-27T13:50:23.2814344+03:00","From":{"ANI":"9198896688"},"To":{"DNIS":"9109136612","Virtual":"110","TrunkId":"9807100241"}}' http://127.0.0.1/pbxcore/api/mts-pbx/v1/event
     * @return void
     */
    public function processEvent():void
    {
        $body = $this->request->getRawBody();
        $authHeader = $this->request->getHeader('Authorization');
        if(self::checkAuth($authHeader) === false){
            openlog('ModuleMtsPbx', LOG_PID | LOG_PERROR, LOG_AUTH);
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Undefined';
            syslog(LOG_WARNING, "From {$_SERVER['REMOTE_ADDR']}. UserAgent: ({$user_agent}). Fail auth http.");
            closelog();
            $this->response->setStatusCode(401);
            $this->response->sendRaw();
            exit;
        }
        SystemMessages::sysLogMsg('ModuleMtsPbx', "Get authHeader: $authHeader POST {$_SERVER['REMOTE_ADDR']}: ".$body);

        $data =  json_decode($body, true);
        if($data['EventType'] === 'CallAccepted'){
            $state = 'Calling';
        }elseif ($data['EventType'] === 'CallAnswered'){
            $state = 'Connected';
        }elseif ($data['EventType'] === 'EndCall'){
            $state = 'Finished';
        }else{
            $this->response->sendRaw();
            exit;
        }

        $users = CacheManager::getCacheData(self::USER_LIST);
        if(empty($users)){
            $users = [];
            try{
                $response = self::makeSoapRequest1C(self::USER_LIST);
            }catch (\Throwable $exception){
                SystemMessages::sysLogMsg('ModuleMtsPbx', "Fail SoapRequest, line:".$exception->getLine().', err: '.$exception->getMessage());
                $this->response->setStatusCode(403);
                $this->response->sendRaw();
                exit;
            }
            if($response){
                foreach ($response as $userData) {
                    if(!empty($userData['mobile'])){
                        $users[substr($userData['mobile'], -10)] = $userData;
                    }
                }
            }
            CacheManager::setCacheData(self::USER_LIST, $users, 120);
        }
        $from   = $data['From']['ANI']??'';
        $to     = $data['To']['DNIS']??'';

        foreach ([$from, $to] as $phone){
            if(!isset($users[$phone])){
                continue;
            }
            $callData = [
                'user_id'  => $users[$phone]['id'],
                'entire_id'=> '',
                'feature'  => '',
                'time'     => self::formatDate($data['EventTime']),
                'state'    => $state,
                'from'     => ['number' => strlen($from)===10?'7'.$from:$from, 'extension' => ''],
                'to'       => ['number' => strlen($to)===10?'7'.$to:$to, 'extension' => ''],
                'call_id'  => $data['CallID']
            ];
            try{
                self::makeSoapRequest1C('call_event',  ['Subject' => 'provider.v1.calls', 'Data' => json_encode( $callData)] );
            }catch (\Throwable $exception){
                SystemMessages::sysLogMsg('ModuleMtsPbx', "Fail SoapRequest provider.v1.calls, line:".$exception->getLine().', err: '.$exception->getMessage());
                $this->response->setStatusCode(403);
                $this->response->sendRaw();
                exit;
            }
        }
        $this->response->sendRaw();
    }

    public static function checkAuth($authHeader)
    {
        $ok = true;
        $settings = self::getSettings();
        $validUsername = $settings['inLogin']??md5(microtime(true));
        $validPassword = $settings['inPassword']??md5(microtime(true));
        if (!$authHeader || !preg_match('/^Basic\s+(.*)$/i', $authHeader, $matches)) {
            $ok = false;
        }
        $credentials = base64_decode($matches[1]);
        if (strpos($credentials, ':') === false) {
            $ok = false;
        }
        list($username, $password) = explode(':', $credentials, 2);
        if ($username !== $validUsername || $password !== $validPassword) {
            $ok = false;
        }
        return $ok;
    }

    public static function getSettings()
    {
        $settings = CacheManager::getCacheData('ModuleMtsPbx');
        if(empty($settings)){
            $settings = ModuleMtsPbx::findFirst();
            if($settings) {
                $settings = $settings->toArray();
                CacheManager::setCacheData('ModuleMtsPbx', $settings, 60);
            }
        }
        return $settings;
    }

    /**
     * Make SOAP request to 1C with session management
     */
    public static function makeSoapRequest1C($operation, $params = [], $reLogin = false)
    {
        static $client = null;
        static $cookieJar = null;
        static $lastSettings = null;

        if(!class_exists('\Modules\ModuleCTIClient\Models\ModuleCTIClient')) {
            SystemMessages::sysLogMsg('ModuleMtsPbx', 'Module ModuleCTIClient not found');
            return null;
        }

        $settings = CacheManager::getCacheData('ModuleCTIClient');
        if(empty($settings)){
            $settings = ModuleCTIClient::findFirst();
            if($settings) {
                $settings = $settings->toArray();
                CacheManager::setCacheData('ModuleCTIClient', $settings, 60);
            }
        }
        if(empty($settings)) {
            SystemMessages::sysLogMsg('ModuleMtsPbx', 'Settings ModuleCTIClient is empty');
            return null;
        }

        $scheme   = $settings['server1c_scheme'];
        $port     = $settings['server1cport'];
        $host     = $settings['server1chost'];
        $wsLink   = $settings['database'].'/ws/miko_crm_api.1cws';
        $login    = $settings['login'];
        $password = $settings['secret'];
        $namespace= 'http://wiki.miko.ru/uniphone:crmapi';
        $ckFile   = '/tmp/1c_soap_session_cookie.json';

        if ($client === null || $lastSettings !== $settings) {
            $cookieJar = new FileCookieJar($ckFile, true);

            $client = new Client([
                 'base_uri' => "$scheme://$host:$port/$wsLink",
                 'auth' => [$login, $password],
                 'timeout' => 10,
                 'http_errors' => false,
                 'cookies' => $cookieJar,
            ]);

            $lastSettings = $settings;
        }
        if ($reLogin) {
            $cookieJar->clear();
        }
        $paramsXml = '';
        foreach ($params as $key => $value) {
            $escapedValue = htmlspecialchars($value, ENT_XML1, 'UTF-8');
            $paramsXml .= "<m:{$key}>{$escapedValue}</m:{$key}>";
        }
        $xmlDocument = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
    <soap:Body>
        <m:{$operation} xmlns:m="{$namespace}">
            {$paramsXml}
        </m:{$operation}>
    </soap:Body>
</soap:Envelope>
XML;
        $headers = [
            'Content-Type' => 'text/xml; charset=utf-8',
        ];
        if ($reLogin) {
            $headers['IBSession'] = 'start';
        }
        try {
            $response = $client->post('', [
                'headers' => $headers,
                'body' => $xmlDocument,
            ]);
            $http_code = $response->getStatusCode();
            $resultRequest = (string) $response->getBody();
            if ($http_code === 0) {
                SystemMessages::sysLogMsg('ModuleMtsPbx', "Connection error: no access to 1C");
                return null;
            } elseif (!$reLogin && in_array($http_code, [400, 500])) {
                SystemMessages::sysLogMsg('ModuleMtsPbx', "HTTP $http_code, retrying with session init...");
                return self::makeSoapRequest1C($operation, $params, true);
            } elseif (in_array($http_code, [401, 403])) {
                SystemMessages::sysLogMsg('ModuleMtsPbx', "Authentication error: HTTP $http_code");
                return null;
            } elseif ($http_code !== 200) {
                SystemMessages::sysLogMsg('ModuleMtsPbx',  "HTTP error $http_code, Response: $resultRequest");
                return null;
            }
            return self::parseSoapResponse($resultRequest, $operation);
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            SystemMessages::sysLogMsg('ModuleMtsPbx',  "Connection error: no access to 1C:" . $e->getMessage());
            return null;
        } catch (\Exception $e) {
            SystemMessages::sysLogMsg('ModuleMtsPbx',  "Exception: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Parse SOAP response
     */
    public static function parseSoapResponse($xml, $operation)
    {
        if (empty($xml)) {
            return null;
        }
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, null, null, 'http://schemas.xmlsoap.org/soap/envelope/');
        if ($doc === false) {
            SystemMessages::sysLogMsg('ModuleMtsPbx',  "Fail parse $operation response $xml");
            return null;
        }
        $ns = $doc->getNamespaces(true);
        if (!isset($ns['soap'])) {
            SystemMessages::sysLogMsg('ModuleMtsPbx',  "Node 'soap' not found in $xml");
            return null;
        }
        $soap = $doc->children($ns['soap']);
        if (isset($soap->Body->Fault)) {
            SystemMessages::sysLogMsg('ModuleMtsPbx',  "Fault ". $soap->Body->Fault->faultstring);
            return null;
        }
        // Get response with 'm' namespace
        if (isset($ns['m'])) {
            $body = $soap->Body->children($ns['m']);
            $responseName = $operation . 'Response';
            if (isset($body->$responseName)) {
                $result = $body->$responseName->children($ns['m'])->return;
                return json_decode((string) $result, true);
            }
        }
        return $xml;
    }

    public static function formatDate($dateStr)
    {
        $dt = new DateTime($dateStr);
        $dt->setTimezone(new \DateTimeZone('UTC'));
        return $dt->format('Y-m-d\TH:i:s.u\Z');
    }

}