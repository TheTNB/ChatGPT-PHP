<?php

namespace HaoziTeam\ChatGPT;

use Exception;
use Ramsey\Uuid\Uuid;

class V1
{
    private $baseUrl = 'https://chatgpt.duti.tech/';
    private array $accessToken;

    private $conversation_id = null;
    private $parent_id = null;
    private $conversation_mapping = [];

    // 初始化
    public function __construct($baseUrl = null)
    {
        if ($baseUrl) {
            $this->baseUrl = $baseUrl;
        }

        // TODO: 添加代理服务器支持
    }

    // 设置账号信息
    public function addAccount($accessToken): void
    {
        $this->accessToken[] = $accessToken;
    }

    /**
     * access token 转换为 JWT
     * @throws Exception
     */
    private function accessTokenToJWT($accessToken): string
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

            $exp = $dAccessToken['exp'] ?? null;
            if ($exp !== null && $exp < time()) {
                throw new Exception("Access token expired");
            }
        }
        return $accessToken;
    }

    /**
     * Ask a question to the chatbot
     * @param string $prompt
     * @param null $conversation_id
     * @param null $parent_id
     * @param int $timeout
     * @return \Generator
     * @throws Exception
     */
    public function ask($prompt, $conversation_id = null, $parent_id = null, $timeout = 360)
    {
        if ($parent_id !== null && $conversation_id === null) {
            throw new Exception("conversation_id must be set once parent_id is set");
        }

        if ($conversation_id !== null && $conversation_id !== $this->conversation_id) {
            $this->logger->debug("Updating to new conversation by setting parent_id to None");
            $this->parent_id = null;
        }

        $conversation_id = $conversation_id ?? $this->conversation_id;
        $parent_id = $parent_id ?? $this->parent_id;
        if ($conversation_id === null && $parent_id === null) {
            $parent_id = (string)Uuid::uuid4();
            $this->logger->debug("New conversation, setting parent_id to new UUID4: {$parent_id}");
        }

        if ($conversation_id !== null && $parent_id === null) {
            if (!isset($this->conversation_mapping[$conversation_id])) {
                if ($this->lazy_loading) {
                    $this->logger->debug(
                        "Conversation ID {$conversation_id} not found in conversation mapping, try to get conversation history for the given ID"
                    );
                    try {
                        $history = $this->get_msg_history($conversation_id);
                        $this->conversation_mapping[$conversation_id] = $history['current_node'];
                    } catch (Exception $e) {
                        $this->logger->debug("Conversation ID {$conversation_id} not found in conversation history");
                    }
                } else {
                    $this->logger->debug(
                        "Conversation ID {$conversation_id} not found in conversation mapping, mapping conversations"
                    );
                }

                $this->map_conversations();
            }

            if (isset($this->conversation_mapping[$conversation_id])) {
                $this->logger->debug(
                    "Conversation ID {$conversation_id} found in conversation mapping, setting parent_id to {$this->conversation_mapping[$conversation_id]}"
                );
                $parent_id = $this->conversation_mapping[$conversation_id];
            } else { // invalid conversation_id provided, treat as a new conversation
                $conversation_id = null;
                $parent_id = (string)Uuid::uuid4();
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
            'conversation_id' => $conversation_id,
            'parent_message_id' => $parent_id,
            'model' => $this->config['paid'] ? 'text-davinci-002-render-paid' : 'text-davinci-002-render-sha',
        ];
        $this->logger->debug("Sending the payload");
        $this->logger->debug(json_encode($data, JSON_PRETTY_PRINT));
        $response = $this->session->post(
            $this->config['base_url'] . 'api/conversation',
            [
                'json' => $data,
                'timeout' => $timeout,
                'stream' => true,
            ]
        );
        $this->checkResponse($response);
        for ($line = $response->getBody()->read(1024); !empty($line); $line = $response->getBody()->read(1024)) {
            $line = trim($line);
            if ($line === 'Internal Server Error') {
                throw new Exception("Error: {$line}");
            }
            if ($line === '' || $line === null) {
                continue;
            }
            if (strpos($line, 'data: ') === 0) {
                $line = substr($line, 6);
            }
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
                throw new Exception('Field missing');
            }
            $message = $line['message']['content']['parts'][0];
            if ($message === $prompt) {
                continue;
            }
            $conversation_id = $line['conversation_id'];
            $parent_id = $line['message']['id'];
            try {
                $model = $line['message']['metadata']['model_slug'];
            } catch (Exception $e) {
                $model = null;
            }
            yield [
                'message' => $message,
                'conversation_id' => $conversation_id,
                'parent_id' => $parent_id,
                'model' => $model,
            ];
        }
        $this->conversation_mapping[$conversation_id] = $parent_id;
        if ($parent_id !== null) {
            $this->parent_id = $parent_id;
        }
        if ($conversation_id !== null) {
            $this->conversation_id = $conversation_id;
        }


    }
}