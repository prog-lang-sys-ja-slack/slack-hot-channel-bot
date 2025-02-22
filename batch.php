<?php

use Carbon\Carbon;

require(__DIR__.'/vendor/autoload.php');

if (file_exists(__DIR__.'/.env')) {
    $dotenv = Dotenv\Dotenv::createMutable(__DIR__);
    $dotenv->load();
}

$client = new GuzzleHttp\Client(['base_uri' => 'https://slack.com/api/']);
$headers = [
  'Authorization' => 'Bearer '. getenv('SLACK_API_TOKEN')
];
$res = $client->request('GET', 'conversations.list', compact('headers'));
$channel_list = json_decode($res->getBody().'', true)['channels'];
// var_dump($res);
// var_dump($channel_list);

$report = [];
foreach ($channel_list as $channel) {
    if ($channel['is_private'] === true) { //プライベートは除く
        continue;
    }

    // channelにjoinしていないといけないらしい。チャンネルリストより全てに加入
    $res = $client->request('POST', 'conversations.join', compact('headers') + [
        'form_params' => [
            'channel' => $channel['id'],
        ]
    ]);

    // var_dump($channel);
    $res = $client->request('GET', 'conversations.history?channel='.$channel['id'], compact('headers'));
    // var_dump($res);
    $messages = json_decode($res->getBody().'', true)['messages'];
    $result = [
        'users' => [],
        'messages' => 0,
        'name' => $channel['name_normalized'],
        'id' => $channel['id']
    ];
    foreach ($messages as $idx => $message) {
        // subtypeがchannel_joinは発言じゃないっぽいので除く
        if (isset($message['subtype']) && $message['subtype'] === 'channel_join') {
            continue;
        }
        if (empty($message['user'])) {
            continue;
        }
        $time = Carbon::createFromTimestamp($message['ts'])->addHour(9);
        if ($idx === 0) {
            $result['updated_at'] = $time->format('n/j H:i');
        }
        if (Carbon::now('Asia/Tokyo')->subDays(7)->diffInSeconds($time, false) >=0) {
            $result['messages']++;
            $result['users'][] = $message['user'] ?? '';
        }
    }
    if (is_int($result['messages']) && $result['messages'] >= 100) {
        $result['messages'] = '99+';
    }
    $result['users'] = count(array_unique($result['users']));
    $report[] = $result;

    sleep(2); // Tier 3 のAPI Limitっぽいので秒間0.5アクセスぐらいにする。バーストはOKって書いてあるけどほんとかなぁ
}

$report = collect($report)->filter(function ($result) {
    return isset($result['updated_at']) && $result['users'];
})->sortBy(function ($result) {
    return $result['messages'];
})->reverse()->take(20);

$text = "";
$rank = 0;
$msgs = 100;

foreach ($report as $idx => $result) {
  if ($msgs > $result['messages']) {
    $msgs = $result['messages'];
    $rank += 1;
  }
  $text = $text.$rank.'. <#'.$result['id'].'> :busts_in_silhouette:'.$result['users'].'人 :speech_balloon:'.$result['messages']."回\n";
}

$message = [
    'blocks' => [
        [
            'type' => 'section',
            'block_id' => 'section0',
            'text' => [
                'type' => 'mrkdwn',
                'text' => $text
            ]
        ]
    ]
];

// array_push($message['blocks'],
//   [
//     'type' => 'section',
//     'block_id' => 'section1', // .($idx+1),
//     'text' => [
//       'type' => 'mrkdwn',
//       'text' => $text // '<#'.$result['id'].'> :busts_in_silhouette:'.$result['users'].'人 :speech_balloon:'.$result['messages'].'回'
//     ]
//   ]);

$client = new GuzzleHttp\Client();

$res = $client->request('POST', getenv('SLACK_WEBHOOK_ENDPOINT'), [
    'json' => $message,
    'http_errors' => false
]);

var_dump($res->getBody().'');
