<?php

class Homebridge
{
  private $token;
  private $tokenExpiration;
  private $loginPath = '/api/auth/login';

  function __construct() {
  }

  public function getAccessories() {
    return $this->homebridgeReq('/api/accessories');
  }

  public function getAccessory($uniqueId) {
    return $this->homebridgeReq('/api/accessories/' . $uniqueId);
  }

  function homebridgeReq($path, $method = 'GET', $data = null) {
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, rtrim(getRequiredEnv('HOMEBRIDGE_PATH'), '/') . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, ($method == 'POST' ? 1 : 0));
    if ($data !== null) {
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $headers = array();
    $headers[] = 'Accept: */*';
    $headers[] = 'Content-Type: application/json';
    if ($path != $this->loginPath) {
      $headers[] = 'Authorization: Bearer ' . $this->getToken();
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        throw new Exception('CURL Error:' . curl_error($ch));
    }
    curl_close($ch);
    $data = json_decode($result, true);
    if ($data === null) {
      throw new Exception("Error decoding JSON: " . $result);
    }
    return $data;
  }

  private function getToken() {
    if (!empty($this->token) && $this->tokenExpiration - time() > 300) {
      return $this->token;
    }
    $data = $this->homebridgeReq($this->loginPath, 'POST', [
      "username" => getRequiredEnv('HOMEBRIDGE_USER'),
      "password" => getRequiredEnv('HOMEBRIDGE_PASS'),
    ]);
    if (empty($data['access_token'])) {
      throw new Exception('Cannot get Homebridge token: ' . var_export($data, true));
    }
    $this->token = $data['access_token'];
    $this->tokenExpiration = time() + $data['expires_in'];
    return $this->token;
  }

}
