<?php

require_once __DIR__ . '/vendor/autoload.php';

// 运行程序
(new TianYaV2())->start();


use Goutte\Client as GoutteClient;
use GuzzleHttp\Client as GuzzleClient;
use Inhere\Console\IO\Input;
use Inhere\Console\IO\Output;
use Inhere\Console\Util\Show;
use Symfony\Component\DomCrawler\Crawler;

/**
 * V2版本
 */
class TianYaV2
{

    /**
     * @var int 程序开始执行时间
     */
    private $startTime;

    /**
     * @var Input
     */
    private $input;

    /**
     * @var Output
     */
    private $output;

    /**
     * @var string 传入的帖子URL
     */
    private $url;

    /**
     * @var string 解析后的帖子URL
     */
    private $postUrl;

    /**
     * @var string 帖子类型
     */
    private $postType;

    /**
     * @var string 帖子分类
     */
    private $postCat;

    /**
     * @var int 帖子ID
     */
    private $postId;

    /**
     * @var string 帖子标题
     */
    private $title;

    /**
     * @var int 帖子分页数
     */
    private $totalPage = 1;

    /**
     * @var GoutteClient
     */
    private $client;

    /**
     * @var array 配置信息
     */
    private $config;

    /**
     * @var string 数据存放目录
     */
    private $dataDir = __DIR__ . '/data';

    /**
     * @var resource 含回复、评论的完整内容
     */
    private $fileHandleGt;

    /**
     * @var resource 仅含楼主帖子的脱水版
     */
    private $fileHandleLz;

    /**
     * @var resource 帖子&评论内容中解析出的邮箱地址
     */
    private $fileHandleEmail;

    /**
     * @var Generator 进度条
     */
    private $progressBar;

    public function __construct()
    {
        $this->startTime = microtime(true);
        $this->input     = new Input();
        $this->output    = new Output();
        $this->config    = require __DIR__ . '/config.php';

        $this->checkArgs();
        $this->initClient();
        $this->parsePostUrl();
        $this->initFileHandle();
    }

    /**
     * 检查传参
     */
    private function checkArgs()
    {
        $this->url = $this->input->getCommand();
        if (!$this->url) {
            $this->output->error('帖子 URL 缺失');
            exit;
        }
    }

    /**
     * 解析帖子URL信息
     */
    private function parsePostUrl()
    {
        [$this->postType, $this->postCat, $this->postId]
            = explode('-', pathinfo(parse_url($this->url, PHP_URL_PATH), PATHINFO_FILENAME));

        $this->postType = str_ireplace('_author', '', $this->postType);

        $this->postUrl = "https://bbs.tianya.cn/m/{$this->postType}-{$this->postCat}-{$this->postId}-1.shtml";
    }

    /**
     * 初始化帖子文件句柄
     */
    private function initFileHandle()
    {
        if (!file_exists($this->dataDir)) {
            mkdir($this->dataDir);
        }
        $fileGt    = $this->dataDir . "/{$this->postCat}-{$this->postId}.完整版.txt";
        $fileLz    = $this->dataDir . "/{$this->postCat}-{$this->postId}.楼主版.txt";
        $fileEmail = $this->dataDir . "/{$this->postCat}-{$this->postId}.email.txt";
        if (file_exists($fileGt)) {
            unlink($fileGt);
        }
        if (file_exists($fileLz)) {
            unlink($fileLz);
        }
        if (file_exists($fileEmail)) {
            unlink($fileEmail);
        }
        $this->fileHandleGt    = fopen($fileGt, 'w+'); // 含跟帖、评论
        $this->fileHandleLz    = fopen($fileLz, 'w+'); // 仅含楼主帖
        $this->fileHandleEmail = fopen($fileEmail, 'w+'); // 帖子中的邮件地址
    }

    /**
     * 关闭文件句柄
     */
    private function closeFileHandle()
    {
        fclose($this->fileHandleGt);
        fclose($this->fileHandleLz);
        fclose($this->fileHandleEmail);
    }

    /**
     * 初始化HTTP请求客户端
     */
    private function initClient()
    {
        $this->client = new GoutteClient();
        $guzzleClient = new GuzzleClient($this->config['guzzle_client_config']);
        $this->client->setClient($guzzleClient);

        foreach ($this->config['headers'] as $name => $val) {
            $this->client->setHeader($name, $val);
        }
    }

    /**
     * 进度条
     */
    private function initProgressBar()
    {
        $this->progressBar = Show::dynamicText('执行完成', '');
    }

    /**
     * 开始分析帖子
     *
     * @throws Exception
     */
    public function start()
    {
        $this->fetchPostInfo();
        $this->writePostInfo();

        $this->initProgressBar();

        $this->fetchPostContent();
    }

    /**
     * 获取帖子基本信息
     */
    public function fetchPostInfo()
    {
        try {
            $this->client->setHeader('Referer', 'bbs.tianya.cn');
            $crawler    = $this->client->request('GET', $this->postUrl);
            $response   = $this->client->getResponse();
            $statusCode = $response->getStatus();
            if ($statusCode != 200) {
                $this->output->error('页面不存在');
                exit;
            }

            $this->title = $crawler->filter('#j-post-content .title h1')->first()->text();
            $this->output->write("<info>{$this->title}</info>");

            $totalPageCrawler = $crawler->filter('#j-post-content .u-pager .page-txt input.txt');
            if ($totalPageCrawler->count()) {
                [, $this->totalPage] = explode('/', $totalPageCrawler->first()->attr('placeholder'));
            }
            $this->output->write("总共<info>{$this->totalPage}</info>页");

        } catch (Exception $e) {
            $this->output->error($e->getMessage());
            exit;
        }
    }

    private function writePostInfo()
    {
        $postTitle = <<<TITLE
{$this->title}

{$this->postUrl}

{$this->config['statement']}
\n\n
TITLE;

        fwrite($this->fileHandleGt, $postTitle);
        fwrite($this->fileHandleLz, $postTitle);
    }

    public function fetchPostContent()
    {
        for ($page = 1; $page <= $this->totalPage; $page++) {
            $this->progressBar->send(date('[Y-m-d H:i:s] ') . "{$page}/{$this->totalPage}页");
            $pageUrl = str_ireplace('-1.shtml', '-' . $page . '.shtml', $this->postUrl);

            $tries = 0;
            try {
                START:
                $tries++;
                $crawler = $this->client->request('GET', $pageUrl);
            } catch (Exception $e) {
                if ($tries > 10) {
                    $this->output->error(sprintf('帖子API请求失败超过10次, 分页数(%d), URL: %s', $page, $pageUrl));
                    throw new $e;
                }
                goto START;
            }

            $crawler->filter('#j-post-content .content .item')->each(function (Crawler $node) use ($page) {
                // 是否为楼主发的帖子
                $isLz        = $node->filter('.hd .info .author .u-badge')->count() ? ' [楼主]' : '';
                $user        = $node->attr('data-user'); // 发帖人名称
                $lid         = $node->attr('data-id'); // 第N楼
                $replyId     = $node->attr('data-replyid'); // 评论ID
                $replyIdText = $replyId ? " [{$replyId}]" : '';
                $commentText = '';
                $this->progressBar->send(date('[Y-m-d H:i:s] ') . "{$page}/{$this->totalPage}页, 帖子{$lid}楼");

                if ($lid > 0) {
                    $datetime    = $node->attr('data-time');
                    $content     = $node->filter('.bd .reply-div');
                    $commentText = $this->fetchPostComment($node, $page, $lid);
                } else {
                    $datetime = $node->filter('.info .time')->text();
                    $content  = $node->filter('.bd');
                }

                // 帖子内容，按段落获取后格式化
                $contentList = [];
                $content->filter('p')->each(function (Crawler $node) use (&$contentList) {
                    $text = trim($node->text());
                    if ($text) {
                        $contentList[] = $text;
                    } // 图片内容, 用图片地址替代
                    elseif ($node->filter('img')->count()) {
                        $img = $node->filter('img')->attr('src');
                        if (strpos($img, '//') === 0) {
                            $img = 'http:' . $img;
                        }
                        $contentList[] = $img;
                    }
                });

                $contentText = implode("\n\n", $contentList);

                // 解析email地址
                foreach ($this->parseEmail($contentText) as $email) {
                    // 邮箱,类型,帖子楼层,时间
                    fputcsv($this->fileHandleEmail, [$email, '帖子', $lid, $datetime]);
                }

                // 完整版
                $postGt = <<<POST
----------------------------------------
[{$lid}楼] [{$user}]{$isLz} [$datetime]
----------------------------------------
{$contentText}
{$commentText}
\n\n\n
POST;

                fwrite($this->fileHandleGt, $postGt);

                // 楼主脱水版
                if ($isLz) {
                    $postLz = <<<POST
----------------------------------------
[{$lid}楼] [{$user}]{$isLz} [$datetime]{$replyIdText}
----------------------------------------
{$contentText}
\n\n\n
POST;
                    fwrite($this->fileHandleLz, $postLz);
                }
            });

        }

        // 发送false表示结束
        $this->progressBar->send(false);
    }

    /**
     * 获取本楼帖子评论
     *
     * @param  Crawler $node 帖子维度的Node
     * @return string
     * @throws Exception
     */
    private function fetchPostComment(Crawler $node, $page, $lid)
    {
        $commentText = '';
        $replyId     = $node->attr('data-replyid');
        $item        = is_numeric($this->postCat) ? 'develop' : $this->postCat;

        if ($replyId && $node->filter('.bd .comments')->count()) {
            $commentCount = $node->filter('.bd .comments')->attr('data-total');
            $commentList  = [];
            // 只能每次10条
            $commentPageTotal = ceil($commentCount / 10);
            for ($num = 1; $num <= $commentPageTotal; $num++) {
                $this->progressBar->send(date('[Y-m-d H:i:s] ') . "{$page}/{$this->totalPage}页, 帖子{$lid}楼, 评论{$num}/{$commentPageTotal}页");

                $commentUrl = "https://bbs.tianya.cn/api?method=bbs.api.getCommentList&params.item={$item}&params.articleId={$this->postId}&params.replyId={$replyId}&params.pageNum={$num}";

                // 尝试N次(避免因网络或接口响应慢导致中断)
                $tries = 0;
                try {
                    START:
                    $tries++;
                    $this->client->request('GET', $commentUrl);
                } catch (Exception $e) {
                    if ($tries > 5) {
                        $this->output->error(sprintf('评论API请求失败超过10次, URL: %s', $commentUrl));
                        throw new $e;
                    }
                    goto START;
                }
                $response = $this->client->getResponse()->getContent();
                $response = json_decode($response, true);

                // 解析评论内容
                foreach ((array)$response['data'] as $row) {
                    $ctime          = date('Y-m-d H:i:s', strtotime($row['comment_time']));
                    $row['content'] = trim(strip_tags($row['content']));
                    $commentList[]  = <<<COMMENT
    [{$row['author_name']}] [{$ctime}]
    >>>
    {$row['content']}
COMMENT;

                    // 解析email地址
                    foreach ($this->parseEmail($row['content']) as $email) {
                        // 邮箱,类型,评论ID,时间
                        fputcsv($this->fileHandleEmail, [$email, '评论', $row['id'], $ctime]);
                    }
                }

                // 延迟10ms
                usleep(10000);
            }

            if (count($commentList)) {
                $commentText .= "\n\n↓↓↓↓↓↓↓↓【本楼评论】↓↓↓↓↓↓↓↓\n";
                $commentText .= implode("\n\n    ************************\n", $commentList);
            }
        }

        return $commentText;
    }

    public function __destruct()
    {
        $this->closeFileHandle();

        $this->output->write("结束时间: <info>" . date('Y-m-d H:i:s') . "</info>");

        $useTime = $second = round(microtime(true) - $this->startTime, 3); // 任务用时
        $minute  = '';
        if ($useTime > 60) {
            $minute = "<info>" . floor($useTime / 60) . "</info>分";
            $second = $useTime % 60;
        }
        $this->output->write("用时{$minute}<info>{$second}</info>秒");
    }

    /**
     * 解析文本中的邮箱地址
     *
     * @param  string $str
     * @return array
     */
    function parseEmail($str)
    {
        preg_match_all('/[a-zA-Z0-9_.-]{2,}@[a-zA-Z0-9-]+(\.[a-zA-Z0-9-]+)*\.[a-zA-Z0-9]{2,6}/i', $str, $matches);

        return array_unique($matches[0]);
    }

}
