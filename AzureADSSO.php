<?php
// AzureADSSO.php - Handle Azure Active Directory SSO login flow.

class AzureADSSO
{
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $tenantId;
    private $authUrl;
    private $tokenUrl;
    private $scopes = "openid profile email offline_access Group.Read.All";
    private $logoutUrl = "https://login.microsoftonline.com/{tenant}/oauth2/v2.0/logout";

    public function __construct($clientId, $clientSecret, $redirectUri, $tenantId = 'common')
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->redirectUri = $redirectUri;
        $this->tenantId = $tenantId;

        $this->authUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/authorize";
        $this->tokenUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token";
        $this->logoutUrl = str_replace("{tenant}", $tenantId, $this->logoutUrl);
    }

    public function getAuthUrl($state)
    {
        $queryParams = http_build_query([
            'client_id'     => $this->clientId,
            'response_type' => 'code',
            'redirect_uri'  => $this->redirectUri,
            'response_mode' => 'query',
            'scope'         => $this->scopes,
            'state'         => $state,
        ]);

        return $this->authUrl . '?' . $queryParams;
    }

    public function getAccessToken($authorizationCode)
    {
        $postFields = [
            'grant_type'    => 'authorization_code',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code'          => $authorizationCode,
            'redirect_uri'  => $this->redirectUri,
            'scope'         => $this->scopes,
        ];

        return $this->makePostRequest($this->tokenUrl, $postFields);
    }

    /**
     * Renew access token using an OAuth refresh token.
     *
     * @param string $refreshToken
     * @return array|null Token response payload
     */
    public function refreshAccessToken($refreshToken)
    {
        $postFields = [
            'grant_type'    => 'refresh_token',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
            'scope'         => $this->scopes,
        ];

        return $this->makePostRequest($this->tokenUrl, $postFields);
    }

    /**
     * Acquire a fresh access token by using the stored refresh token or falling back to App Client Credentials.
     * Automatically updates session token states if a new user token is acquired.
     *
     * @return string|null New OAuth access token
     */
    public function acquireFreshToken()
    {
        $refreshToken = $_SESSION['user']['refresh_token'] ?? $_SESSION['refresh_token'] ?? $_SESSION['tokens']['refresh_token'] ?? null;

        if (!empty($refreshToken)) {
            $tokens = $this->refreshAccessToken($refreshToken);
            if ($tokens && !empty($tokens['access_token'])) {
                $newAccessToken = $tokens['access_token'];
                $newRefreshToken = $tokens['refresh_token'] ?? $refreshToken;
                $this->updateSessionToken($newAccessToken, $newRefreshToken);
                $this->logAzureAction('AZURE_TOKEN_REFRESH_SUCCESS', [
                    'reason' => 'Successfully renewed access token using refresh_token'
                ]);
                return $newAccessToken;
            }
        }

        // Fallback: App Client Credentials token
        $tokenUrl = "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token";
        $postFields = [
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope'         => 'https://graph.microsoft.com/.default'
        ];

        $tokenRes = $this->makePostRequest($tokenUrl, $postFields);
        if ($tokenRes && !empty($tokenRes['access_token'])) {
            $appToken = $tokenRes['access_token'];
            $this->logAzureAction('AZURE_APP_TOKEN_ACQUIRED', [
                'reason' => 'Acquired client credentials app token after refresh token unavailable/expired'
            ]);
            return $appToken;
        }

        return null;
    }

    /**
     * Synchronize newly acquired OAuth tokens across all standard session keys.
     *
     * @param string $accessToken
     * @param string|null $refreshToken
     */
    public function updateSessionToken($accessToken, $refreshToken = null)
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
            $_SESSION['user']['access_token'] = $accessToken;
            if ($refreshToken) $_SESSION['user']['refresh_token'] = $refreshToken;
        }

        $_SESSION['access_token'] = $accessToken;
        $_SESSION['azure_access_token'] = $accessToken;
        if ($refreshToken) $_SESSION['refresh_token'] = $refreshToken;

        if (!isset($_SESSION['tokens']) || !is_array($_SESSION['tokens'])) {
            $_SESSION['tokens'] = [];
        }
        $_SESSION['tokens']['access_token'] = $accessToken;
        if ($refreshToken) $_SESSION['tokens']['refresh_token'] = $refreshToken;
    }

    public function getUserInfo($idToken)
    {
        list($header, $payload, $signature) = explode(".", $idToken);
        $decodedPayload = base64_decode(str_replace(['-', '_'], ['+', '/'], $payload));
        return json_decode($decodedPayload, true);
    }

    public function getLogoutUrl($postLogoutRedirectUri)
    {
        return $this->logoutUrl . '?post_logout_redirect_uri=' . urlencode($postLogoutRedirectUri);
    }

    /**
     * Retrieve full list of groups assigned to the authenticated user via Graph API,
     * supporting @odata.nextLink pagination and automatic token renewal on 401 errors.
     *
     * @param string $accessToken
     * @return array List of group display names
     */
    public function getUserGroups($accessToken)
    {
        if (empty($accessToken)) {
            if (function_exists('get_azure_access_token')) {
                $accessToken = get_azure_access_token();
            } else {
                $accessToken = $_SESSION['user']['access_token'] ?? $_SESSION['access_token'] ?? $_SESSION['azure_access_token'] ?? $_SESSION['tokens']['access_token'] ?? null;
            }
        }

        $fetchUserGroupPages = function($token) {
            $groupNames = [];
            $nextUrl = "https://graph.microsoft.com/v1.0/me/memberOf?\$select=id,displayName&\$top=999";
            $pageCount = 0;

            while (!empty($nextUrl) && $pageCount < 50) { // Safety cap of 50 pages
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $nextUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/json',
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($httpCode != 200) {
                    return ['success' => false, 'code' => $httpCode, 'response' => $response, 'error' => $curlError, 'groups' => $groupNames];
                }

                $data = json_decode($response, true);
                if (isset($data['value']) && is_array($data['value'])) {
                    foreach ($data['value'] as $group) {
                        if (!empty($group['displayName'])) {
                            $groupNames[] = $group['displayName'];
                        }
                    }
                }

                // Check for Microsoft Graph @odata.nextLink pagination URL
                $nextUrl = $data['@odata.nextLink'] ?? null;
                $pageCount++;
            }

            return ['success' => true, 'code' => 200, 'groups' => array_values(array_unique($groupNames))];
        };

        $res = $fetchUserGroupPages($accessToken);

        // Automatic retry if token expired / 401 error
        $isTokenExpired = (!$res['success'] && ($res['code'] == 401 || str_contains($res['response'] ?? '', 'InvalidAuthenticationToken') || str_contains($res['response'] ?? '', 'token is expired')));
        if ($isTokenExpired && ($freshToken = $this->acquireFreshToken())) {
            $this->logAzureAction('AZURE_GET_USER_GROUPS_RETRY_FRESH_TOKEN', [
                'initial_code' => $res['code'],
                'reason' => 'Access token expired; automatically renewed token and retrying getUserGroups'
            ]);
            $res = $fetchUserGroupPages($freshToken);
        }

        if ($res['success']) {
            $this->logAzureAction('AZURE_GET_USER_GROUPS_SUCCESS', ['count' => count($res['groups']), 'groups' => $res['groups']]);
            return $res['groups'];
        }

        $this->logAzureAction('AZURE_GET_USER_GROUPS_ERROR', ['http_code' => $res['code'], 'response' => $res['response'], 'curl_error' => $res['error']]);
        return [];
    }

    /**
     * Retrieve members of a specific Azure AD group using Microsoft Graph API.
     * Strictly queries live Graph API without mock fallbacks.
     *
     * @param string $groupId Group Object ID or Group Display Name
     * @param string|null $accessToken Optional access token (if null, checks session locations or gets app token)
     * @return array Array of user member objects
     */
    /**
     * Retrieve members of a specific Azure AD group using Microsoft Graph API.
     * Sanitizes group ID, resolves group Display Names to Object ID UUIDs,
     * and falls back to App Client Credentials token if delegated token has insufficient rights.
     *
     * @param string $groupId Group Object ID UUID or Group Display Name
     * @param string|null $accessToken Optional access token
     * @return array Array of user member objects
     */
    public function getGroupMembers($groupId, $accessToken = null)
    {
        $arg1 = trim((string)$groupId);
        $arg2 = trim((string)$accessToken);

        // Detect if caller swapped arguments: getGroupMembers($accessToken, $groupId)
        if (str_starts_with($arg1, 'eyJ') || str_contains($arg1, '.') || strlen($arg1) > 150) {
            $groupId = $arg2;
            $accessToken = $arg1;
            $this->logAzureAction('AZURE_GET_GROUP_MEMBERS_SWAPPED_ARGS', [
                'reason' => 'Detected inverted parameters: accessToken was passed as first argument and groupId as second. Automatically swapped.',
                'group_id' => $groupId
            ]);
        } else {
            $groupId = $arg1;
            $accessToken = $arg2;
        }

        if (empty($groupId)) {
            $this->logAzureAction('AZURE_GET_GROUP_MEMBERS_SKIPPED', ['reason' => 'Empty Group ID provided']);
            return [];
        }

        // Determine access token if not passed explicitly
        if (empty($accessToken)) {
            if (function_exists('get_azure_access_token')) {
                $accessToken = get_azure_access_token();
            } else {
                $accessToken = $_SESSION['user']['access_token'] ?? $_SESSION['access_token'] ?? $_SESSION['azure_access_token'] ?? $_SESSION['tokens']['access_token'] ?? null;
            }
        }

        // Helper closure to acquire an App-Level Client Credentials Token
        $getAppToken = function() {
            $tokenUrl = "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token";
            $postFields = [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope'         => 'https://graph.microsoft.com/.default'
            ];
            $tokenRes = $this->makePostRequest($tokenUrl, $postFields);
            return $tokenRes['access_token'] ?? null;
        };

        if (empty($accessToken)) {
            $accessToken = $getAppToken();
        }

        if (empty($accessToken)) {
            $this->logAzureAction('AZURE_GET_GROUP_MEMBERS_FAILED', ['group_id' => $groupId, 'reason' => 'No OAuth access token available']);
            return [];
        }

        // If $groupId is not a UUID (format: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx), resolve Display Name to Object ID
        $isUuid = preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $groupId);
        $resolvedObjectId = $groupId;

        if (!$isUuid) {
            $filterUrl = "https://graph.microsoft.com/v1.0/groups?\$filter=" . urlencode("displayName eq '" . addslashes($groupId) . "'") . "&\$select=id,displayName";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $filterUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code == 200) {
                $filterData = json_decode($resp, true);
                if (!empty($filterData['value'][0]['id'])) {
                    $resolvedObjectId = $filterData['value'][0]['id'];
                }
            }
        }

        // Query Group Members using resolved Object ID
        $executeGroupQuery = function($token) use ($resolvedObjectId) {
            $graphUrl = "https://graph.microsoft.com/v1.0/groups/" . urlencode($resolvedObjectId) . "/members?\$select=id,displayName,userPrincipalName,mail";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $graphUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            return ['code' => $httpCode, 'response' => $response, 'error' => $curlError];
        };

        $res = $executeGroupQuery($accessToken);

        // If 401 (Invalid token) or 403 (Insufficient Privileges), retry once using App Client Credentials Token
        if (($res['code'] == 401 || $res['code'] == 403) && ($appToken = $getAppToken())) {
            $this->logAzureAction('AZURE_GET_GROUP_MEMBERS_RETRY_APP_TOKEN', [
                'group_id' => $groupId,
                'initial_code' => $res['code'],
                'reason' => 'User delegated token lacked privileges; retried with app-level token'
            ]);
            $res = $executeGroupQuery($appToken);
        }

        if ($res['code'] == 200) {
            $data = json_decode($res['response'], true);
            if (isset($data['value'])) {
                $members = $data['value'];
                $this->logAzureAction('AZURE_GET_GROUP_MEMBERS_SUCCESS', [
                    'group_identifier' => $groupId,
                    'resolved_object_id' => $resolvedObjectId,
                    'member_count' => count($members)
                ]);
                return $members;
            }
        }

        $this->logAzureAction('AZURE_GET_GROUP_MEMBERS_ERROR', [
            'group_identifier' => $groupId,
            'resolved_object_id' => $resolvedObjectId,
            'http_code' => $res['code'],
            'response' => $res['response'],
            'curl_error' => $res['error']
        ]);

        return [];
    }

    /**
     * Retrieve all groups in the Microsoft Active Directory tenant, supporting @odata.nextLink pagination.
     *
     * @return array Array of group objects with id, displayName, and description
     */
    public function getAllGroups()
    {
        // Determine user delegated access token
        $userAccessToken = null;
        if (function_exists('get_azure_access_token')) {
            $userAccessToken = get_azure_access_token();
        } else {
            $userAccessToken = $_SESSION['user']['access_token'] ?? $_SESSION['access_token'] ?? $_SESSION['azure_access_token'] ?? $_SESSION['tokens']['access_token'] ?? null;
        }

        // Closure to fetch all pages of groups using @odata.nextLink
        $fetchAllPages = function($token) {
            $allGroups = [];
            $nextUrl = "https://graph.microsoft.com/v1.0/groups?\$select=id,displayName,description&\$top=999";
            $pageCount = 0;

            while (!empty($nextUrl) && $pageCount < 50) { // Safety cap of 50 pages (up to ~50,000 groups)
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $nextUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $token,
                    'Content-Type: application/json',
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);

                if ($httpCode != 200) {
                    return ['success' => false, 'code' => $httpCode, 'response' => $response, 'error' => $curlError, 'groups' => $allGroups];
                }

                $data = json_decode($response, true);
                if (isset($data['value']) && is_array($data['value'])) {
                    $allGroups = array_merge($allGroups, $data['value']);
                }

                // Check for Microsoft Graph @odata.nextLink pagination URL
                $nextUrl = $data['@odata.nextLink'] ?? null;
                $pageCount++;
            }

            return ['success' => true, 'code' => 200, 'groups' => $allGroups];
        };

        // 1. First try using user's delegated OAuth access token
        if (!empty($userAccessToken)) {
            $res = $fetchAllPages($userAccessToken);
            if ($res['success']) {
                $this->logAzureAction('AZURE_GET_ALL_GROUPS_SUCCESS', ['group_count' => count($res['groups']), 'token_type' => 'delegated_user_token']);
                return $res['groups'];
            } else {
                $this->logAzureAction('AZURE_GET_ALL_GROUPS_DELEGATED_FAILED', ['http_code' => $res['code'], 'response' => $res['response']]);
            }
        }

        // 2. Fall back to App Client Credentials Token
        $tokenUrl = "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token";
        $postFields = [
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope'         => 'https://graph.microsoft.com/.default'
        ];

        $tokenRes = $this->makePostRequest($tokenUrl, $postFields);
        if ($tokenRes && !empty($tokenRes['access_token'])) {
            $appAccessToken = $tokenRes['access_token'];
            $res = $fetchAllPages($appAccessToken);
            if ($res['success']) {
                $this->logAzureAction('AZURE_GET_ALL_GROUPS_SUCCESS', ['group_count' => count($res['groups']), 'token_type' => 'app_client_credentials']);
                return $res['groups'];
            } else {
                $this->logAzureAction('AZURE_GET_ALL_GROUPS_ERROR', ['http_code' => $res['code'], 'response' => $res['response'], 'curl_error' => $res['error']]);
            }
        }

        return [];
    }

    /**
     * Create a Microsoft Teams Chat conversation using Microsoft Graph API (POST /chats).
     * Flexible argument handling supports various caller signatures:
     *   - createChat($members, $topic, $accessToken)
     *   - createChat($accessToken, $members, $topic)
     *   - createChat($topic, $members, $accessToken)
     *
     * @param mixed $arg1 Members list, Topic title, or OAuth Access Token
     * @param mixed $arg2 Members list, Topic title, or OAuth Access Token
     * @param mixed $arg3 Members list, Topic title, or OAuth Access Token
     * @return array|null Created Chat payload object (containing 'id', 'webUrl', etc.)
     */
    public function createChat($arg1 = null, $arg2 = null, $arg3 = null)
    {
        $membersInput = [];
        $topic = 'Incident Chat';
        $accessToken = null;

        // Inspect all 3 arguments to classify them
        $args = [$arg1, $arg2, $arg3];

        foreach ($args as $a) {
            if (empty($a)) continue;

            if (is_array($a)) {
                $membersInput = $a;
            } elseif (is_string($a)) {
                $str = trim($a);
                if (str_starts_with($str, 'eyJ') || str_contains($str, '.') || strlen($str) > 150) {
                    $accessToken = $str;
                } else {
                    $topic = $str;
                }
            }
        }

        // Determine access token if not explicitly supplied
        if (empty($accessToken)) {
            if (function_exists('get_azure_access_token')) {
                $accessToken = get_azure_access_token();
            } else {
                $accessToken = $_SESSION['user']['access_token'] ?? $_SESSION['access_token'] ?? $_SESSION['azure_access_token'] ?? $_SESSION['tokens']['access_token'] ?? null;
            }
        }

        if (empty($accessToken)) {
            $this->logAzureAction('AZURE_CREATE_CHAT_FAILED', ['reason' => 'No OAuth access token available']);
            return null;
        }

        // Ensure current caller ID/UPN is included in members list
        $currentCallerId = $_SESSION['user']['azure_oid'] ?? $_SESSION['user']['email'] ?? null;
        if (!empty($currentCallerId)) {
            $membersInput[] = $currentCallerId;
        }

        // Format Graph API Chat Members payload (#microsoft.graph.aadUserConversationMember) with deduplication
        $formattedMembers = [];
        $seenBinds = [];

        foreach ($membersInput as $m) {
            $userId = null;
            if (is_array($m)) {
                // Prefer id (Object ID UUID) if present, then userPrincipalName, mail, or user_id
                $userId = !empty($m['id']) ? $m['id'] : (!empty($m['userPrincipalName']) ? $m['userPrincipalName'] : (!empty($m['mail']) ? $m['mail'] : ($m['user_id'] ?? null)));
            } elseif (is_string($m)) {
                $userId = trim($m);
            }

            if (!empty($userId)) {
                // Check if $userId is a valid UUID or valid email address (containing @)
                $isGuid = preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $userId);
                $isEmail = str_contains($userId, '@');

                // Filter out non-UUID, non-email strings (like pairwise subject tokens MWfRNK...)
                if ($isGuid || $isEmail) {
                    $userBindUrl = $isGuid
                        ? "https://graph.microsoft.com/v1.0/users('{$userId}')"
                        : "https://graph.microsoft.com/v1.0/users('" . urlencode($userId) . "')";

                    if (!isset($seenBinds[$userBindUrl])) {
                        $seenBinds[$userBindUrl] = true;
                        $formattedMembers[] = [
                            '@odata.type' => '#microsoft.graph.aadUserConversationMember',
                            'roles' => ['owner'],
                            'user@odata.bind' => $userBindUrl
                        ];
                    }
                } else {
                    $this->logAzureAction('AZURE_CREATE_CHAT_SKIPPED_INVALID_USER', [
                        'skipped_identifier' => $userId,
                        'reason' => 'Identifier is neither a valid UUID nor email address'
                    ]);
                }
            }
        }

        $payload = [
            'chatType' => 'group',
            'topic'    => $topic,
            'members'  => $formattedMembers
        ];

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $graphUrl = "https://graph.microsoft.com/v1.0/chats";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $graphUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode == 201 || $httpCode == 200) {
            $chatData = json_decode($response, true);
            $this->logAzureAction('AZURE_CREATE_CHAT_SUCCESS', [
                'topic' => $topic,
                'chat_id' => $chatData['id'] ?? null,
                'web_url' => $chatData['webUrl'] ?? null,
                'request_payload_json' => $jsonPayload
            ]);
            return $chatData;
        }

        $this->logAzureAction('AZURE_CREATE_CHAT_ERROR', [
            'topic' => $topic,
            'http_code' => $httpCode,
            'request_payload_json' => $jsonPayload,
            'response' => $response,
            'curl_error' => $curlError
        ]);

        return null;
    }

    /**
     * Send an Adaptive Card or message to a Microsoft Teams chat conversation using Microsoft Graph API
     * (POST /chats/{chatId}/messages).
     * Flexible argument handling supports various caller signatures:
     *   - sendAdaptiveCardToChat($chatId, $cardContent, $accessToken)
     *   - sendAdaptiveCardToChat($accessToken, $chatId, $cardContent)
     *   - sendAdaptiveCardToChat($chatId, $accessToken, $cardContent)
     *
     * @param mixed $arg1 Chat ID, Adaptive Card content, or Access Token
     * @param mixed $arg2 Chat ID, Adaptive Card content, or Access Token
     * @param mixed $arg3 Chat ID, Adaptive Card content, or Access Token
     * @return array|null Message payload object returned by Graph API
     */
    public function sendAdaptiveCardToChat($arg1 = null, $arg2 = null, $arg3 = null)
    {
        $chatId = null;
        $cardContent = null;
        $accessToken = null;

        // Inspect all 3 arguments to classify them
        $args = [$arg1, $arg2, $arg3];

        foreach ($args as $a) {
            if (empty($a)) continue;

            if (is_array($a)) {
                // If array is a chat object returned by createChat (contains 'id' or 'chat_id' matching thread pattern)
                if (isset($a['id']) && (str_contains($a['id'], '@thread.v2') || str_contains($a['id'], '19:'))) {
                    $chatId = $a['id'];
                } elseif (isset($a['chat_id']) && (str_contains($a['chat_id'], '@thread.v2') || str_contains($a['chat_id'], '19:'))) {
                    $chatId = $a['chat_id'];
                } else {
                    $cardContent = $a;
                }
            } elseif (is_string($a)) {
                $str = trim($a);
                if (str_starts_with($str, 'eyJ') || strlen($str) > 200) {
                    $accessToken = $str;
                } elseif (str_contains($str, '@thread.v2') || str_contains($str, '19:')) {
                    $chatId = $str;
                } elseif (is_array(json_decode($str, true))) {
                    $cardContent = json_decode($str, true);
                } else {
                    if (empty($chatId)) {
                        $chatId = $str;
                    } else {
                        $cardContent = $str;
                    }
                }
            }
        }

        // Determine access token if not explicitly supplied
        if (empty($accessToken)) {
            if (function_exists('get_azure_access_token')) {
                $accessToken = get_azure_access_token();
            } else {
                $accessToken = $_SESSION['user']['access_token'] ?? $_SESSION['access_token'] ?? $_SESSION['azure_access_token'] ?? $_SESSION['tokens']['access_token'] ?? null;
            }
        }

        if (empty($chatId)) {
            $this->logAzureAction('AZURE_SEND_ADAPTIVE_CARD_FAILED', ['reason' => 'Empty Chat ID provided']);
            return null;
        }

        if (empty($accessToken)) {
            $this->logAzureAction('AZURE_SEND_ADAPTIVE_CARD_FAILED', ['chat_id' => $chatId, 'reason' => 'No OAuth access token available']);
            return null;
        }

        // Format Microsoft Graph API chat message payload containing Adaptive Card attachment
        if (is_array($cardContent)) {
            // Adaptive Card JSON schema
            if (!isset($cardContent['type'])) {
                $cardContent['type'] = 'AdaptiveCard';
            }
            if (!isset($cardContent['$schema'])) {
                $cardContent['$schema'] = 'http://adaptivecards.io/schemas/adaptive-card.json';
            }
            if (!isset($cardContent['version'])) {
                $cardContent['version'] = '1.2';
            }

            $messagePayload = [
                'body' => [
                    'contentType' => 'html',
                    'content' => '<attachment id="adaptiveCardAttachment"></attachment>'
                ],
                'attachments' => [
                    [
                        'id' => 'adaptiveCardAttachment',
                        'contentType' => 'application/vnd.microsoft.card.adaptive',
                        'content' => json_encode($cardContent, JSON_UNESCAPED_SLASHES)
                    ]
                ]
            ];
        } else {
            // Plain text or HTML message
            $messagePayload = [
                'body' => [
                    'contentType' => str_contains($cardContent ?? '', '<') ? 'html' : 'text',
                    'content' => (string)($cardContent ?? 'Incident Notification')
                ]
            ];
        }

        $graphUrl = "https://graph.microsoft.com/v1.0/chats/" . urlencode($chatId) . "/messages";
        $jsonPayload = json_encode($messagePayload, JSON_UNESCAPED_SLASHES);

        $executeSendMessage = function($token) use ($graphUrl, $jsonPayload) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $graphUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            return ['code' => $httpCode, 'response' => $response, 'error' => $curlError];
        };

        $res = $executeSendMessage($accessToken);

        // Automatic retry on 401 or token expired error
        $isTokenExpired = ($res['code'] == 401 || str_contains($res['response'] ?? '', 'InvalidAuthenticationToken') || str_contains($res['response'] ?? '', 'token is expired'));
        if ($isTokenExpired && ($freshToken = $this->acquireFreshToken())) {
            $this->logAzureAction('AZURE_SEND_ADAPTIVE_CARD_RETRY_FRESH_TOKEN', [
                'chat_id' => $chatId,
                'initial_code' => $res['code'],
                'reason' => 'Access token expired or 401 error received; automatically renewed token and retrying message send'
            ]);
            $res = $executeSendMessage($freshToken);
        }

        if ($res['code'] == 201 || $res['code'] == 200) {
            $msgData = json_decode($res['response'], true);
            $this->logAzureAction('AZURE_SEND_ADAPTIVE_CARD_SUCCESS', [
                'chat_id' => $chatId,
                'message_id' => $msgData['id'] ?? null,
                'request_payload_json' => $jsonPayload
            ]);
            return $msgData;
        }

        $this->logAzureAction('AZURE_SEND_ADAPTIVE_CARD_ERROR', [
            'chat_id' => $chatId,
            'http_code' => $res['code'],
            'request_payload_json' => $jsonPayload,
            'response' => $res['response'],
            'curl_error' => $res['error']
        ]);

        return [
            'chat_id' => $chatId,
            'http_code' => $res['code'],
            'request_payload_json' => $jsonPayload,
            'response' => $res['response'],
            'curl_error' => $res['error']
        ];
    }

    /**
     * Add user member(s) to an existing Microsoft Teams chat conversation using Microsoft Graph API
     * (POST /chats/{chatId}/members).
     * Flexible argument handling supports various caller signatures:
     *   - addMembersToChat($chatId, $members, $accessToken)
     *   - addMembersToChat($accessToken, $chatId, $members)
     *   - addMembersToChat($chatId, $accessToken, $members)
     *
     * @param mixed $arg1 Chat ID, Members list, or Access Token
     * @param mixed $arg2 Chat ID, Members list, or Access Token
     * @param mixed $arg3 Chat ID, Members list, or Access Token
     * @return array|null Added member object(s) or response payload returned by Graph API
     */
    public function addMembersToChat($arg1 = null, $arg2 = null, $arg3 = null)
    {
        $chatId = null;
        $membersInput = [];
        $accessToken = null;

        // Inspect all 3 arguments to classify them
        $args = [$arg1, $arg2, $arg3];

        foreach ($args as $a) {
            if (empty($a)) continue;

            if (is_array($a)) {
                // If array is a chat object (contains 'id' or 'chat_id' matching thread pattern)
                if (isset($a['id']) && (str_contains($a['id'], '@thread.v2') || str_contains($a['id'], '19:'))) {
                    $chatId = $a['id'];
                } elseif (isset($a['chat_id']) && (str_contains($a['chat_id'], '@thread.v2') || str_contains($a['chat_id'], '19:'))) {
                    $chatId = $a['chat_id'];
                } else {
                    $membersInput = $a;
                }
            } elseif (is_string($a)) {
                $str = trim($a);
                if (str_starts_with($str, 'eyJ') || strlen($str) > 200) {
                    $accessToken = $str;
                } elseif (str_contains($str, '@thread.v2') || str_contains($str, '19:')) {
                    $chatId = $str;
                } else {
                    if (empty($chatId)) {
                        $chatId = $str;
                    } else {
                        $membersInput[] = $str;
                    }
                }
            }
        }

        // Determine access token if not explicitly supplied
        if (empty($accessToken)) {
            if (function_exists('get_azure_access_token')) {
                $accessToken = get_azure_access_token();
            } else {
                $accessToken = $_SESSION['user']['access_token'] ?? $_SESSION['access_token'] ?? $_SESSION['azure_access_token'] ?? $_SESSION['tokens']['access_token'] ?? null;
            }
        }

        if (empty($chatId)) {
            $this->logAzureAction('AZURE_ADD_MEMBERS_TO_CHAT_FAILED', ['reason' => 'Empty Chat ID provided']);
            return null;
        }

        if (empty($accessToken)) {
            $this->logAzureAction('AZURE_ADD_MEMBERS_TO_CHAT_FAILED', ['chat_id' => $chatId, 'reason' => 'No OAuth access token available']);
            return null;
        }

        // Process each member individually or as batch payload
        $results = [];
        $graphUrl = "https://graph.microsoft.com/v1.0/chats/" . urlencode($chatId) . "/members";

        foreach ($membersInput as $m) {
            $userId = null;
            if (is_array($m)) {
                $userId = !empty($m['id']) ? $m['id'] : (!empty($m['userPrincipalName']) ? $m['userPrincipalName'] : (!empty($m['mail']) ? $m['mail'] : ($m['user_id'] ?? null)));
            } elseif (is_string($m)) {
                $userId = trim($m);
            }

            if (!empty($userId)) {
                $isGuid = preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $userId);
                $isEmail = str_contains($userId, '@');

                if ($isGuid || $isEmail) {
                    $userBindUrl = $isGuid
                        ? "https://graph.microsoft.com/v1.0/users('{$userId}')"
                        : "https://graph.microsoft.com/v1.0/users('" . urlencode($userId) . "')";

                    $memberPayload = [
                        '@odata.type' => '#microsoft.graph.aadUserConversationMember',
                        'roles' => ['owner'],
                        'user@odata.bind' => $userBindUrl
                    ];

                    $jsonPayload = json_encode($memberPayload, JSON_UNESCAPED_SLASHES);

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $graphUrl);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Authorization: Bearer ' . $accessToken,
                        'Content-Type: application/json',
                    ]);

                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $curlError = curl_error($ch);
                    curl_close($ch);

                    if ($httpCode == 201 || $httpCode == 200) {
                        $resData = json_decode($response, true);
                        $results[] = $resData;
                        $this->logAzureAction('AZURE_ADD_MEMBER_TO_CHAT_SUCCESS', [
                            'chat_id' => $chatId,
                            'user_id' => $userId,
                            'member_id' => $resData['id'] ?? null
                        ]);
                    } else {
                        $this->logAzureAction('AZURE_ADD_MEMBER_TO_CHAT_ERROR', [
                            'chat_id' => $chatId,
                            'user_id' => $userId,
                            'http_code' => $httpCode,
                            'request_payload_json' => $jsonPayload,
                            'response' => $response,
                            'curl_error' => $curlError
                        ]);
                    }
                }
            }
        }

        return !empty($results) ? $results : null;
    }

    /**
     * Send a text, HTML, or Adaptive Card message to a Microsoft Teams chat conversation using Microsoft Graph API
     * (POST /chats/{chatId}/messages).
     * Flexible argument handling supports various caller signatures:
     *   - sendMessageToChat($chatId, $messageContent, $accessToken)
     *   - sendMessageToChat($accessToken, $chatId, $messageContent)
     *   - sendMessageToChat($chatId, $accessToken, $messageContent)
     *
     * @param mixed $arg1 Chat ID, Message content, or Access Token
     * @param mixed $arg2 Chat ID, Message content, or Access Token
     * @param mixed $arg3 Chat ID, Message content, or Access Token
     * @return array|null Message payload object returned by Graph API
     */
    public function sendMessageToChat($arg1 = null, $arg2 = null, $arg3 = null)
    {
        // Delegate directly to sendAdaptiveCardToChat which handles text, HTML, and Adaptive Cards flexibly
        return $this->sendAdaptiveCardToChat($arg1, $arg2, $arg3);
    }

    private function makePostRequest($url, $postFields)
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode == 200) {
            return json_decode($response, true);
        }

        $this->logAzureAction('AZURE_POST_REQUEST_ERROR', ['url' => $url, 'http_code' => $httpCode, 'response' => $response, 'curl_error' => $curlError]);
        return null;
    }

    private function logAzureAction($action, $details)
    {
        if (function_exists('log_action')) {
            log_action($action, $details);
        }
        error_log("[AzureADSSO] {$action}: " . json_encode($details, JSON_UNESCAPED_SLASHES));
    }
}
