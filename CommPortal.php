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

        $URL = $this->baseURL_ . "login.html?version=9.5.40";
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
            $tmp = explode("=", $response['body']);
            if (isset($tmp[1])) {
                $session = $tmp[1];
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
        $cb = time() . '000'; // Unix timestamp with milliseconds
        $URL = $this->baseURL_ . "session" . $this->sessionID_ . "/line/data?version=9.6.50&callback=dataObjectManager.callback&data=Meta_Subscriber_CallWaiting,Meta_Subscriber_UC9000_ForwardingDestinations,Meta_Subscriber_UnconditionalCallForwarding&ContextInfo=version%3D9.6.50&cb=" . $cb;

        $rest = new RestClient();
        $rest->endpoint = $URL;
        $rest->method = "GETJSON";
        $response = $rest->sendCurl();

        if ($response['http_code'] == 200) {
            $body = $response['body'];

            // Extract the Meta_Subscriber_UnconditionalCallForwarding JSON block from JSONP response
            // We search for: "Meta_Subscriber_UnconditionalCallForwarding" followed by comma, followed by a JSON object
            $pattern = '/"Meta_Subscriber_UnconditionalCallForwarding"\s*,\s*(\{.*?\})\s*,\s*null/s';
            if (preg_match($pattern, $body, $matches)) {
                $decoded = json_decode($matches[1], true);
                if ($decoded) {
                    return $decoded;
                }
            }

            // Fallback: try to find anything like {"Subscribed":...}
            if (preg_match('/(\{.*?\})/s', $body, $matches)) {
                $decoded = json_decode($matches[1], true);
                if (isset($decoded['Subscribed'])) {
                    return $decoded;
                }
            }
            return null;
        } else {
            $this->state_ = "Failed";
            return null;
        }
    }

    public function setUnconditionalCallForwarding($data)
    {
        $URL = $this->baseURL_ . "session" . $this->sessionID_ . "/line/data";

        // Structure the payload inside "Meta_Subscriber_UnconditionalCallForwarding" as expected by CommPortal
        $payload = [
            "Meta_Subscriber_UnconditionalCallForwarding" => $data
        ];

        $rest = new RestClient();
        $rest->endpoint = $URL;
        $rest->method = "POSTJSON";
        $rest->payloadArr = $payload;
        $response = $rest->sendCurl();
        return $response['http_code'] == 200;
    }
}
