<?php
// CommPortal.php - Telephony integration using Metaswitch CommPortal

class RestClient
{
    public $endpoint;
    public $method;
    public $payloadArr;

    public function sendCurl()
    {
        $verbose = !empty($GLOBALS['commportal_verbose']);

        if ($verbose) {
            echo "[VERBOSE RestClient] Initiating {$this->method} request to endpoint: {$this->endpoint}\n";
            if ($this->payloadArr) {
                echo "[VERBOSE RestClient] Payload: " . json_encode($this->payloadArr) . "\n";
            }
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        if ($this->method === "POST") {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($this->payloadArr));
        } elseif ($this->method === "POSTJSON") {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($this->payloadArr));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        } elseif ($this->method === "GETJSON") {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        }

        $body = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($verbose) {
            echo "[VERBOSE RestClient] Response HTTP Code: {$http_code}\n";
            echo "[VERBOSE RestClient] Response Body: {$body}\n";
        }

        return [
            'http_code' => $http_code,
            'body' => $body
        ];
    }
}

class CommPortal
{
    private $baseURL_;
    private $phoneNumber_;
    private $password_;
    private $ext_;
    private $phonesystem_;
    private $sessionID_;
    private $state_;

    // Overload constructor to support direct dynamic login credentials from Database!
    public function __construct($row_or_ext)
    {
        $this->baseURL_ = get_setting('commportal_base_url', 'https://endpoint/');

        if (is_array($row_or_ext) || is_object($row_or_ext)) {
            $row = (array)$row_or_ext;
            $this->phoneNumber_ = $row['phone_number'] ?? '';
            $this->password_ = $row['password'] ?? '';
            $this->ext_ = $row['ext'] ?? '';
            $this->phonesystem_ = 'commPortal';
            $this->login();
        } else {
            $this->ext_ = $row_or_ext;
            $this->getLoginInfo();
            $this->login();
        }
    }

    public function __get($variable)
    {
        $privateName = $variable . "_";
        switch ($variable) {
            case "phoneNumber":
            case "password":
            case "ext":
            case "phoneSystem":
            case "state":
                return $this->$privateName;
                break;
        }
    }

    private function login()
    {
        $verbose = !empty($GLOBALS['commportal_verbose']);
        if ($verbose) {
            echo "[VERBOSE CommPortal] Logging in user: {$this->phoneNumber_}...\n";
        }

        $URL = $this->baseURL_ . "login?version=9.5.40";
        $rest = new RestClient();
        $rest->endpoint = $URL;
        $postfields = [
            "Password" => $this->password_,
            "ApplicationID" => "MS_WebClient",
            "DirectoryNumber" => $this->phoneNumber_
        ];
        $rest->method = "POST";
        $rest->payloadArr = $postfields;
        $response = $rest->sendCurl();

        if ($response['http_code'] == 200) {
            $tmp = explode("=", trim($response['body']));
            if (isset($tmp[1])) {
                $session = trim($tmp[1]);
                if (!empty($session)) {
                    $this->sessionID_ = $session;
                    $this->state_ = "loggedIn";
                    if ($verbose) {
                        echo "[VERBOSE CommPortal] Login successful! Session ID established: {$this->sessionID_}\n";
                    }
                } else {
                    $this->state_ = "Failed";
                    if ($verbose) {
                        echo "[VERBOSE CommPortal] Login failed: Session key is empty in body.\n";
                    }
                }
            } else {
                $this->state_ = "Failed";
                if ($verbose) {
                    echo "[VERBOSE CommPortal] Login failed: Response did not contain separator character.\n";
                }
            }
        } else {
            $this->state_ = "Failed";
            if ($verbose) {
                echo "[VERBOSE CommPortal] Login failed: HTTP code is not 200.\n";
            }
        }
    }

    private function getLoginInfo()
    {
        try {
            $db = get_oncall_db();
            $stmt = $db->prepare("SELECT * FROM commportal_accounts WHERE ext = ? LIMIT 1");
            $stmt->execute([$this->ext_]);
            $row = $stmt->fetch();
            if ($row) {
                $this->phoneNumber_ = $row['phone_number'];
                $this->password_ = $row['password'];
                $this->phonesystem_ = 'commPortal';
            } else {
                $this->state_ = "Failed";
            }
        } catch (Exception $e) {
            $this->state_ = "Failed";
        }
    }

    public function getUnconditionalCallForwarding()
    {
        $URL = $this->baseURL_ . "session" . $this->sessionID_ . "/line/data.js?data=Meta_Subscriber_UnconditionalCallForwarding";
        $rest = new RestClient();
        $rest->endpoint = $URL;
        $rest->method = "GETJSON";
        $response = $rest->sendCurl();

        if ($response['http_code'] == 200) {
            return json_decode($response['body'], true);
        } else {
            $this->state_ = "Failed";
            return null;
        }
    }

    public function setUnconditionalCallForwarding($data)
    {
        $URL = $this->baseURL_ . "session" . $this->sessionID_ . "/line/data.js";

        // Send back the whole data array with the updated values as requested
        $rest = new RestClient();
        $rest->endpoint = $URL;
        $rest->method = "POSTJSON";
        $rest->payloadArr = $data;
        $response = $rest->sendCurl();
        return $response['http_code'] == 200;
    }
}
