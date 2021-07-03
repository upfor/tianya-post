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
$postType = str_ireplace('_author', '', $postType);

$postUrl = "http://bbs.tianya.cn/m/{$postType}-{$cat}-{$postId}-1.shtml";

$config = require __DIR__ . '/config.php';

$filterAuthor = array_flip($config['filter_author']);

$client = new Client();
$guzzleClient = new GuzzleClient($config['guzzle_client_config']);
$client->setClient($guzzleClient);

foreach ($config['headers'] as $name => $val) {
    $client->setHeader($name, $val);
}

// 1. 获取帖子信息
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
    $output->write("总共<info>{$totalPage}</info>页");

} catch (\Exception $e) {
    $output->error($e->getMessage());
    exit;
}

$output->write("开始时间: <info>" . date('Y-m-d H:i:s') . "</info>");

$dataDir = __DIR__ . '/data';
if (!file_exists($dataDir)) {
    mkdir($dataDir);
}
$fileGt = $dataDir . "/{$postId}【完整版】.txt";
$fileLz = $dataDir . "/{$postId}【楼主版】.txt";
if (file_exists($fileGt)) {
    unlink($fileGt);
}
if (file_exists($fileLz)) {
    unlink($fileLz);
}
$fileHandleGt = fopen($fileGt, 'w+'); // 含跟帖、评论
$fileHandleLz = fopen($fileLz, 'w+'); // 仅含楼主帖

$postTitle = <<<TITLE
{$title}

$postUrl

QQ群: 721981381

声明: 本群仅整理帖子内容，帖子中的内容、观点仅代表原作者、与本群无关。
\n\n
TITLE;

fwrite($fileHandleGt, $postTitle);
fwrite($fileHandleLz, $postTitle);

// 进度
$bar = Show::progressBar($totalPage, [
    'doneChar' => '=',
]);

$emailList = [];

for ($page = 1; $page <= $totalPage; $page++) {
    $pageUrl = str_ireplace('-1.shtml', '-' . $page . '.shtml', $postUrl);

    $tries = 0;
    try {
        START:
        $tries++;
        $crawler = $client->request('GET', $pageUrl);
    } catch (\Exception $e) {
        if ($tries > 10) {
            throw new $e;
            $output->error(sprintf('帖子API请求失败超过10次, 分页数(%d), URL: %s', $page, $pageUrl), true);
        }
        goto START;
    }

    $crawler->filter('#j-post-content .content .item')->each(function (Crawler $node) use (
        $postId, $cat, $client, $fileHandleGt, $fileHandleLz, &$input, &$output, &$emailList, $filterAuthor
    ) {
        $isLz = $node->filter('.hd .info .author .u-badge')->count() ? ' [楼主]' : '';
        $user = $node->attr('data-user');
        $lid = $node->attr('data-id');
        $replyId = $node->attr('data-replyid');
        $replyIdText = $replyId ? " [{$replyId}]" : '';
        $item = is_numeric($cat) ? 'develop' : $cat;
        $commentText = '';

        if ($lid > 0) {
            $datetime = $node->attr('data-time');
            $content = $node->filter('.bd .reply-div');
            if ($replyId && $node->filter('.bd .comments')->count()) {
                $commentCount = $node->filter('.bd .comments')->attr('data-total');
                $commentList = [];
                for ($num = 1; $num <= ceil($commentCount / 10); $num++) {
                    $commentUrl = "http://bbs.tianya.cn/api?method=bbs.api.getCommentList&params.item={$item}&params.articleId={$postId}&params.replyId={$replyId}&params.pageNum={$num}";

                    $client->request('GET', $commentUrl);
                    $response = $client->getResponse()->getContent();
                    $response = json_decode($response, true);

                    foreach ((array)$response['data'] as $row) {
                        if (isset($filterAuthor[$row['author_name']])) {
                            continue;
                        }

                        $ctime = date('Y-m-d H:i', strtotime($row['comment_time']));
                        $row['content'] = strip_tags($row['content']);
                        $commentList[] = <<<COMMENT
    [{$row['author_name']}] [{$ctime}]
    >>>
    {$row['content']}
COMMENT;

                        foreach (parseEmail($row['content']) as $val) {
                            $emailList[$val] = $ctime;
                        }
                    }

                    usleep(100000);
                }

                if (count($commentList)) {
                    $commentText = "\n\n↓↓↓↓↓↓↓↓【评论】↓↓↓↓↓↓↓↓\n" . implode("\n\n    ************************\n", $commentList);
                }
            }
        } else {
            $datetime = $node->filter('.info .time')->text();
            $content = $node->filter('.bd');
        }

        $contentList = [];
        $content->filter('p')->each(function (Crawler $node) use (&$contentList) {
            $text = trim($node->text());
            if ($text) {
                $contentList[] = $text;
            } elseif ($node->filter('img')->count()) {
                $img = $node->filter('img')->attr('src');
                if (strpos($img, '//') === 0) {
                    $img = 'http:' . $img;
                }
                $contentList[] = $img;
            }
        });

        $contentText = implode("\n\n", $contentList);

        foreach (parseEmail($contentText) as $val) {
            $emailList[$val] = $datetime;
        }

        $post = <<<POST
----------------------------------------
[{$lid}楼] [{$user}]{$isLz} [$datetime]
----------------------------------------
{$contentText}
{$commentText}
\n\n\n
POST;

        fwrite($fileHandleGt, $post);
        if ($isLz) {
            $post = <<<POST
----------------------------------------
[{$lid}楼] [{$user}]{$isLz} [$datetime]{$replyIdText}
----------------------------------------
{$contentText}
\n\n\n
POST;
            fwrite($fileHandleLz, $post);
        }
    });

    // 进度条更新
    $bar->send(1);

}

fclose($fileHandleGt);
fclose($fileHandleLz);

// 帖子内容中的邮箱地址
arsort($emailList);
file_put_contents($dataDir . "/email.{$postId}.json", json_encode($emailList, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

$output->write("结束时间: <info>" . date('Y-m-d H:i:s') . "</info>");

$useTime = $second = round(microtime(true) - $startTime, 3); // 任务用时
$minute = '';
if ($useTime > 60) {
    $minute = " <info>" . floor($useTime / 60) . "</info>分";
    $second = $useTime % 60;
}
$output->write("用时{$minute}<info>{$second}</info>秒");

// 解析文本中的邮箱地址
function parseEmail($str)
{
    preg_match_all('/[a-zA-Z0-9_.-]{2,}@[a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+)*\.[a-zA-Z0-9]{2,6}/i', $str, $matches);

    return array_unique($matches[0]);
}
