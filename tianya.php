<?php

require_once __DIR__ . '/vendor/autoload.php';

use Goutte\Client;
use GuzzleHttp\Client as GuzzleClient;
use Symfony\Component\DomCrawler\Crawler;

use Inhere\Console\IO\Input;
use Inhere\Console\IO\Output;
use Inhere\Console\Utils\Show;

$input = new Input();
$output = new Output();

$startTime = microtime(true);

$url = $input->getCommand();
if (!$url) {
    $output->error('帖子 URL 缺失');
    exit;
}

list($postType, $cat, $postId, $page) = explode('-', pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME));

$postUrl = "http://bbs.tianya.cn/m/{$postType}-{$cat}-{$postId}-1.shtml";

$config = require __DIR__ . '/config.php';

$client = new Client();
$guzzleClient = new GuzzleClient($config['guzzle_client_config']);
$client->setClient($guzzleClient);

foreach ($config['headers'] as $name => $val) {
    $client->setHeader($name, $val);
}

try {
    $client->setHeader('Referer', 'bbs.tianya.cn');
    $crawler = $client->request('GET', $postUrl);
    $response = $client->getResponse();
    $statusCode = $response->getStatus();
    if ($statusCode != 200) {
        $output->error('页面不存在');
        exit;
    }

    $title = $crawler->filter('#j-post-content .title h1')->first()->text();
    $output->write("<info>{$title}</info>");

    $totalPage = 1;
    $totalPageCrawler = $crawler->filter('#j-post-content .u-pager .page-txt input.txt');
    if ($totalPageCrawler->count()) {
        list(, $totalPage) = explode('/', $totalPageCrawler->first()->attr('placeholder'));
    }
    $output->write("总共 <info>{$totalPage}</info> 页");

} catch (\Exception $e) {
    $output->error($e->getMessage());
    exit;
}

$fileHandleAll = fopen(__DIR__ . "/data/{$postId}.all.log", 'w+');
$fileHandleLz = fopen(__DIR__ . "/data/{$postId}.lz.log", 'w+');

$postTitle = <<<TITLE
{$title}

$postUrl
\n\n
TITLE;

fwrite($fileHandleAll, $postTitle);
fwrite($fileHandleLz, $postTitle);

// 进度
$bar = Show::progressBar($totalPage, [
    'msg' => '进度',
    'doneChar' => '=',
]);

for ($page = 1; $page <= $totalPage; $page++) {
    $pageUrl = str_ireplace('-1.shtml', '-' . $page . '.shtml', $postUrl);

    $crawler = $client->request('GET', $pageUrl);

    $crawler->filter('#j-post-content .content .item')->each(function (Crawler $node) use (
        &$fileHandleAll, &$fileHandleLz, &$input, &$output
    ) {
        $isLz = $node->filter('.hd .info .author .u-badge')->count() ? ' [楼主]' : '';
        $user = $node->attr('data-user');
        $lid = $node->attr('data-id');
        $replyId = $node->attr('data-replyid');
        $replyIdText = $replyId ? " [{$replyId}]" : '';

        if ($lid > 0) {
            $datetime = $node->attr('data-time');
            $content = $node->filter('.bd .reply-div');
            $commentCount = $node->filter('.bd .comments')->attr('data-total');
        } else {
            $datetime = $node->filter('.info .time')->text();
            $content = $node->filter('.bd');
        }

        $contentList = [];
        $content->filter('p')->each(function (Crawler $node) use (&$contentList) {
            $contentList[] = $node->text();
        });

        $contentText = implode("\n\n", $contentList);

        $post = <<<POST
----------------------------------------------------------------
[{$lid}楼] [{$user}]{$isLz} $datetime{$replyIdText}
----------------------------------------------------------------
$contentText
\n\n
POST;

        fwrite($fileHandleAll, $post);
        if ($isLz) {
            fwrite($fileHandleLz, $post);
        }
    });

    // 进度条更新
    $bar->send(1);

}

fclose($fileHandleAll);
fclose($fileHandleLz);

$useTime = round(microtime(true) - $startTime, 3); // 任务用时
$output->write("用时 <info>{$useTime}s</info>");
