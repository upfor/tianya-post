<?php

return [
    // 请求客户端配置
    'headers'              => [
        'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
        'User-Agent'      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/62.0.3202.94 Safari/537.36',
    ],
    'guzzle_client_config' => [
        'allow_redirects' => false,
        'cookies'         => true,
        'connect_timeout' => 3,
        'timeout'         => 10,
    ],
    // 被过滤的账号
    'filter_author'        => [
        'ty_小黑麦759',
        '天下行走s',
    ],
    'statement'            => 'QQ群: XXX

声明: 本群仅整理帖子内容，帖子中的内容、观点仅代表原作者、与本群无关。',
];
