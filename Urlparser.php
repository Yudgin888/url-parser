<?php
require_once "simplehtmldom/simple_html_dom.php";
set_time_limit(0);
define("INTERNAL", 1); // только внутренние
define("EXTERNAL", 2); // только внешние
define("ALL", 3); // внутренние и внешние (переход на внешние не делается)
define("RABBITHOLE", 4); // внутренние и внешние (делается переход по всем ссылкам)

class Urlparser
{
    public $max_depth; // глубина прохода по ссылкам
    public $checkstatus; // проверка http статуса
    public $transition; // стратегия отсеивания ссылок (INTERNAL / EXTERNAL / ALL / RABBITHOLE)

    private $save_to_db;
    private $starturl;
    private $root; // корневой адрес сайта

    public $links; // обработанные ссылки
    private $unique_links; // массив уникальных ссылок
    private $queue_internal; // массив для обработки внутренних ссылок
    private $queue_external; // массив для обработки внешних ссылок

    public $internal_counter;
    public $external_counter;

    public function __construct()
    {
        $this->unique_links = [];
        $this->queue_internal = [];
        $this->queue_external = [];

        $this->links = [];
        // структура
        // 'url' => $url
        // 'status' => http статус
        // 'depth' => глубина ссылки от начальной

        $this->internal_counter = 1;
        $this->external_counter = 0;
    }

    public function getCountUnique(){
        return count($this->unique_links);
    }

    public function parse($url, $checkstatus = false, $transition = INTERNAL, $max_depth = 0, $save_to_db = false)
    {
        $this->max_depth = $max_depth;
        $this->save_to_db = $save_to_db;
        $this->checkstatus = $checkstatus;
        $this->transition = $transition;
        $this->starturl = $url;
        $parts = parse_url($url);
        $this->root = $parts['scheme'] . '://' . $parts['host'];
        $this->unique_links[] = $url;
        $this->queue_internal[] = $url;
        $this->worker(0);
        if($save_to_db) {
            $this->saveToDB(true);
        }
    }

    private function worker($depth = 0)
    {
        while (count($this->queue_internal) > 0) {
            $url = array_shift($this->queue_internal);
            $this->url_worker($url, $depth);
        }
        while (count($this->queue_external) > 0) {
            $url = array_shift($this->queue_external);
            $this->url_worker($url, $depth);
        }
        echo '<br>Round: ' . ($depth + 1) . '<br>';
        flush();
        if (count($this->queue_internal) > 0 || count($this->queue_external) > 0) {
            $this->worker($depth + 1);
        }
    }

    private function url_worker($url, $depth)
    {
        if ($this->max_depth != 0 && $depth > $this->max_depth) {
            return;
        }

        if ($this->checkTypeUrl($url) === $this->transition || $this->transition === ALL || $this->transition === RABBITHOLE) {
            $arr = [
                'url' => $url,
                'status' => $this->checkstatus ? $this->getStatus($url) : null,
                'depth' => $depth,
            ];
            $this->links[] = $arr;
            echo $arr['url'] . ' - status: ' . $arr['status'] . ' - depth: ' . $arr['depth'] . '<br>';
            flush();
        }

        //--- получение списка ссылок и добавление их в очередь на обработку ---
        // если ссылка является внешней и не установлен режим прохода по внешним ссылкам
        if ($this->checkTypeUrl($url) === EXTERNAL && $this->transition !== RABBITHOLE) {
            return;
        } else {
            $html = new simple_html_dom();
            try {
                $html->load_file($url);
                if ($html !== null && is_object($html) && isset($html->nodes) && count($html->nodes) > 0) {
                    $alllinks = $html->find('a[href]');
                    foreach ($alllinks as $link) {
                        $href = $link->attr['href'];
                        if ($href != null) {
                            if (preg_match('/\.(png|jpeg|gif|jpg|js|css|xml|pdf)/', $href)) {
                                continue;
                            }
                            $typeUrl = $this->checkTypeUrl($href);
                            $href = $this->prepareUrl($href);
                            if (!in_array($href, $this->unique_links)) {
                                $this->unique_links[] = $href;
                                if ($typeUrl === INTERNAL) {
                                    $this->internal_counter++;
                                    $this->queue_internal[] = $href;
                                } else {
                                    $this->external_counter++;
                                    $this->queue_external[] = $href;
                                }
                            }
                        }
                    }
                }
            } catch (Exception $ex) {
                echo 'Error: ' . $url . '<br>';
            }
        }
    }

    public function getStatus($url)
    {
        $handle = curl_init($url);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, TRUE);
        curl_exec($handle);
        return curl_getinfo($handle, CURLINFO_HTTP_CODE);
    }

    public function saveToDB($overwrite)
    {
        if (file_exists("mysql_wrapper.php")) {
            include_once "mysql_wrapper.php";
            saveAll($this->links, $overwrite);
            return true;
        } else {
            return false;
        }
    }

    public function checkTypeUrl($url)
    {
        $parts = parse_url($url);
        if (!isset($parts['scheme']) && !isset($parts['host'])) {
            return INTERNAL;
        } else {
            if (strcmp($this->root, $parts['scheme'] . '://' . $parts['host']) === 0) {
                return INTERNAL;
            } else return EXTERNAL;
        }
    }

    private function prepareUrl($url)
    {
        if ($url === '/') {
            $url = $this->root;
        }
        $parts = parse_url($url);
        if (!isset($parts['scheme']) && !isset($parts['host'])) {
            $url = $this->root . $url;
        } elseif (!isset($parts['scheme'])) {
            $url = 'http:' . $url;
        }
        return $url;
    }
}