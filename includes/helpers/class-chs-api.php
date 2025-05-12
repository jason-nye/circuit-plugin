<?php

if (!class_exists('CHS_API')) {
    class CHS_API {

        private $base_url;
        private $api_key;

        public function __construct() {
            $apiUrl = get_option('chs_api_url');
            $this->base_url = $apiUrl ? ($apiUrl . '/api') : 'https://trade.circuithospitality.com/api';
            $this->api_key = get_option('chs_api_key') ?? null;
        }

        // Generic method to perform GET requests
        public function get($endpoint, $params = []) {
            $url = $this->base_url . '/' . $endpoint;
            if (!empty($params)) {
                $url .= '?' . http_build_query($params);
            }
            return $this->request('GET', $url);
        }

        // Generic method to perform POST requests
        public function post($endpoint, $data = []) {
            $url = $this->base_url . '/' . $endpoint;
            return $this->request('POST', $url, $data);
        }

        // Core cURL request handler
        private function request($method, $url, $data = null) {
            if($this->api_key === null){
                throw new Exception('API key not set');
            }

            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

            if ($method == 'POST' && !empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                
            }

            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json', 
                'Authorization: Bearer ' . $this->api_key
            ]);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $isOk = ($http_code >= 200 && $http_code < 300) || ($http_code >= 401 && $http_code < 500);

            if ($response === false || !$isOk) {
                curl_close($ch);
                if($http_code === 401){
                    throw new Exception('Invalid API key');
                }else{
                    $error = curl_error($ch);
                    throw new Exception("Request failed to Stockroom Server ($http_code): $error $response");
                }
            }

            curl_close($ch);

            return json_decode($response);
        }
    }
}
