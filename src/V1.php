<?php

namespace HaoziTeam\ChatGPT;

use Exception;
use Ramsey\Uuid\Uuid;
use GuzzleHttp\Client;

class V1
{
    private $baseUrl = 'https://chatgpt.duti.tech/';
    private array $accessToken;

    private $conversationId = null;
    private $parentId = null;

    private $http = null;
    private $paid = false;

    // 初始化
    public function __construct($baseUrl = null)
    {
        if ($baseUrl) {
            $this->baseUrl = $baseUrl;
        }

        $this->http = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => 360,
        ]);

        // TODO: 添加代理服务器支持
    }

    // 设置账号信息
    public function addAccountAccessToken($accessToken): void
    {
        $this->accessToken[] = $accessToken;
    }

    /**
     * access token 转换为 JWT
     * @throws Exception
     */
    public function accessTokenToJWT($accessToken): string
    {
        if ($accessToken !== null) {
            try {
                $sAccessToken = explode(".", $accessToken);
                $sAccessToken[1] .= str_repeat("=", (4 - strlen($sAccessToken[1]) % 4) % 4);
                $dAccessToken = base64_decode($sAccessToken[1]);
                $dAccessToken = json_decode($dAccessToken, true);
            } catch (Exception $e) {
                throw new Exception("Access token invalid");
            }

            // 检查是否过期
            $exp = $dAccessToken['exp'] ?? null;
            if ($exp !== null && $exp < time()) {
                throw new Exception("Access token expired");
            }
        }
        return 'Bearer ' . $accessToken;
    }

    /**
     * 向ChatGPT发送消息
     * @param string $prompt
     * @param null $conversation_id
     * @param null $parent_id
     * @param int $timeout
     * @throws Exception
     */
    public function ask($prompt, $conversationId = null, $parentId = null, $timeout = 360, $account = null)
    {
        // 如果账号为空，则随机选择一个账号
        if ($account === null) {
            $token = $this->accessTokenToJWT($this->accessToken[array_rand($this->accessToken)]);
        } else {
            $token = isset($this->accessToken[$account]) ? $this->accessTokenToJWT($this->accessToken[$account]) : null;
        }

        // 如果账号为空，则抛出异常
        if ($token === null) {
            throw new Exception("No account available");
        }

        // 设置了父消息ID，必须设置会话ID
        if ($parentId !== null && $conversationId === null) {
            throw new Exception("conversation_id must be set once parent_id is set");
        }

        // 如果传入的会话ID与当前会话ID不一致，则清空父消息ID以开启新的会话
        if ($conversationId !== null && $conversationId !== $this->conversationId) {
            $this->parentId = null;
        }

        $conversationId = $conversationId ?? $this->conversationId;
        $parentId = $parentId ?? $this->parentId;

        // 如果会话ID与父消息ID都为空，则开启新的会话
        if ($conversationId === null && $parentId === null) {
            $parentId = (string)Uuid::uuid4();
        }

        // 如果会话ID不为空，但是父消息ID为空，则尝试从映射表中获取父消息ID
        if ($conversationId !== null && $parentId === null) {
            // 尝试从ChatGPT获取历史记录
            $response = $this->http->get('api/conversation/' . $conversationId, [
                'headers' => [
                    'Authorization' => $token,
                ],
            ]);
            $response = json_decode($response->getBody()->getContents(), true);
            if (isset($response['current_node'])) {
                // 如果获取到了父消息ID，则使用该父消息ID
                $conversationId = $response['current_node'];
            } else {
                // 如果没有获取到父消息ID，则开启新的会话
                $conversationId = null;
                $parentId = (string)Uuid::uuid4();
            }
        }

        $data = [
            'action' => 'next',
            'messages' => [
                [
                    'id' => (string)Uuid::uuid4(),
                    'role' => 'user',
                    'content' => ['content_type' => 'text', 'parts' => [$prompt]],
                ],
            ],
            'conversation_id' => $conversationId,
            'parent_message_id' => $parentId,
            'model' => $this->paid ? 'text-davinci-002-render-paid' : 'text-davinci-002-render-sha',
        ];

        $response = $this->http->post(
            'api/conversation',
            [
                'json' => $data,
                'timeout' => $timeout,
                'headers' => [
                    'Authorization' => $token,
                    'Accept' => 'text/event-stream',
                    'Content-Type' => 'application/json',
                    'X-Openai-Assistant-App-Id' => '',
                    'Connection' => 'close',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Referer' => 'https://chatbot.openai.com/chat',
                ],
                'stream' => true,
            ]
        );

        $this->checkResponse($response);

        foreach (explode("\n", $response->getBody()->getContents()) as $line) {
            $line = trim($line);
            if ($line === 'Internal Server Error') {
                throw new Exception("Error: {$line}");
            }
            if ($line === '' || $line === null) {
                continue;
            }

            // data:开头，表示数据流开始
            if (strpos($line, 'data: ') === 0) {
                $line = substr($line, 6);
            }

            // [DONE]结尾，表示数据流结束
            if ($line === '[DONE]') {
                break;
            }

            $line = str_replace('\\"', '"', $line);
            $line = str_replace("\\'", "'", $line);
            $line = str_replace("\\\\", "\\", $line);

            try {
                $line = json_decode($line, true);
            } catch (Exception $e) {
                continue;
            }

            if (!$this->checkFields($line)) {
                if (isset($line["detail"]) && $line["detail"] === "Too many requests in 1 hour. Try again later.") {
                    throw new Exception("Error: Rate limit exceeded");
                }
                if (isset($line["detail"]["code"]) && $line["detail"]["code"] === "invalid_api_key") {
                    throw new Exception("Error: Invalid access token");
                }
                throw new Exception('Field missing');
            }

            if ($line['message']['content']['parts'][0] === $prompt) {
                continue;
            }

            $message = $line['message']['content']['parts'][0];
            $conversation_id = $line['conversation_id'] ?? null;
            $parent_id = $line['message']['id'] ?? null;
            $model = isset($line["message"]["metadata"]["model_slug"]) ? $line["message"]["metadata"]["model_slug"] : null;
        }

        return [
            'message' => $message,
            'conversation_id' => $conversation_id,
            'parent_id' => $parent_id,
            'model' => $model,
        ];
    }

    /**
     * 检查响应状态
     * @param $response
     * @return void
     * @throws Exception
     */
    private function checkResponse($response)
    {
        if ($response->getStatusCode() !== 200) {
            throw new Exception("Error: {$response->getStatusCode()}");
        }
    }

    /**
     * 检查响应行是否包含必要的字段
     * @param $line
     * @return bool
     */
    private function checkFields($line)
    {
        return isset($line['message']['content']['parts'][0])
            && isset($line['conversation_id'])
            && isset($line['message']['id']);
    }
}
