<?php

class EvoContentSearch
{
    protected $config = [];
    private $keyword;
    private $request;
    private $limit;
    private $results;
    private $rows;
    private $total;
    private $time;
    private $mode;
    private $current_base_url;

    public function __construct() {
        $this->current_base_url = serverv('query_string')
            ? strstr(serverv('request_uri'), '?', true)
            : serverv('request_uri')
        ;
        $this->config = $this->loadConfig();
    }

    private function loadConfig() {
        $config = include __DIR__ . '/config/config.php';
        if(!event()->params) {
            return $config;
        }

        $app_config_path = $this->appPath() . event()->params['theme'].'/config.php';
        if(!event()->params['theme'] || !is_file($app_config_path)) {
            return $this->array_merge($config, event()->params);
        }

        $app_config = include $app_config_path;
        if (!$app_config) {
            return $this->array_merge($config, event()->params);
        }
        foreach (['css', 'tplForm', 'tplResults', 'tplPaginate'] as $k) {
            if (!isset($app_config[$k])) {
                $app_config[$k] = null;
            }
        }

        return $this->array_merge(
            $this->array_merge($config, $app_config)
            , event()->params
        );
    }

    public function run() {
        if(getv('reset') || !$this->tableExists(db()->config['table_prefix'] . 'search_content')) {
            if(evo()->isLoggedIn('manager')) {
                $this->firstRun();
                return;
            }
        }
        $recent_update = evo()->getConfig('recent_update', globalv('recent_update'));
        $ds_recent_update = evo()->getConfig('ds_recent_update',0);

        if($ds_recent_update >= $recent_update && !$this->recentUpdate($ds_recent_update)) {
            return;
        }

        include_once __DIR__ . '/save-index.class.php';
        dsIndex::saveAll(
            $ds_recent_update,
            ['basicAuth' => $this->config('basicAuth')]
        );
        dsIndex::saveSystemSetting(
            'ds_recent_update',
            serverv('request_time') + evo()->getConfig('server_offset_time', 0)
        );
        evo()->clearCache('full');
    }

    private function firstRun() {
        include_once __DIR__ . '/save-index.class.php';
        if(!dsIndex::hasPlugin()) {
            dsIndex::createPlugin();
        }
        dsIndex::createTable();
        dsIndex::saveAll(null, ['basicAuth' => $this->config('basicAuth')]);
        dsIndex::saveSystemSetting(
            'ds_recent_update',
            serverv('request_time')  + evo()->getConfig('server_offset_time', 0)
        );
        evo()->clearCache('full');
    }

    private function recentUpdate($ds_recent_update) {
        return db()->getRecordCount(
            db()->select(
                'id',
                db()->config['table_prefix'] . 'site_content', [
                    'published=1 and deleted=0 and searchable=1 and privateweb=0',
                    'AND editedon > ' . db()->escape($ds_recent_update)
                ]
            )
        );
    }
    public function config($key, $default=null) {
        if (array_get($this->config, $key)===null) {
            return $default;
        }
        $str = array_get($this->config, $key);
        if (stripos($str, '@CHUNK') === 0) {
            return evo()->getChunk(substr($str, 7));
        }
        return $str;
    }
    public function dsBasePath() {
        return dirname(__DIR__) . '/';
    }
    public function appPath() {
        return $this->dsBasePath() . 'themes/';
    }
    public function tableExists($table_name)
    {
        $sql = sprintf(
            "SHOW TABLES FROM `%s` LIKE '%s'"
            , trim(db()->config['dbase'], '`')
            , str_replace('[+prefix+]', db()->config['table_prefix'], $table_name)
        );
        return db()->getRecordCount(db()->query($sql)) ? 1 : 0;
    }

    public function setProps() {
        $keyword = $this->keyword();
        $this->orderby = getv('orderby','rel');
        $this->limit = getv(
            'limit',
            $this->config('limit',10)
        );
        if(!$this->limit) {
            $this->limit = $this->config('limit',10);
        }
        $this->form = evo()->parseText(
            $this->config('tplForm.wrap'), [
                'keyword' => $keyword,
                'orderby.rel.checked'  => $this->orderby==='rel' ? 'checked' : '',
                'orderby.date.checked' => $this->orderby==='date' ? 'checked' : '',
                'limit' => $this->limit,
                'offset' => getv('offset',0),
                'admin-widget' => evo()->isLoggedIn('manager') && evo()->config['site_status']
                    ? evo()->parseText(
                        $this->config('tplForm.admin-widget'), [
                            'url' => $this->current_base_url
                        ]
                    )
                    : ''
            ]
        );

        if($this->config('css')) {
            evo()->regClientCSS(
                sprintf('<style>%s</style>', $this->config('css'))
            );
        }

        if(!$keyword) {
            return;
        }

        $this->keyword = $keyword;

        evo()->setPlaceholder('ds.keyword', $keyword);

        $mode = getv('mode') ?: $this->mode($keyword);
        $total = $this->total($keyword, $mode);
        if(!$total && $mode === 'fulltext') {
            $total = $this->total($keyword, 'like');
            if(!$total) {
                return;
            }
            $mode = 'like';
        }
        $this->mode = $mode;

        $this->total = $total;
        $this->request = $_GET;

        $bt = microtime(true);
        $this->rows = $keyword
            ? $this->find($keyword)
            : null
        ;

        if(!$this->rows) {
            return;
        }

        $this->time = round(microtime(true)-$bt, 5);
        $results = [];
        foreach ($this->rows as $row) {
            $results[] = evo()->parseText(
                $this->config('tplResults.result'),
                $row
            );
        }
        $this->results = implode("\n", $results);
        $this->paginate = $this->total
            ? $this->buildPaginate($this->total, $this->limit, getv('offset',0))
            : null
        ;
        evo()->toPlaceholders([
            'ds.results'              => $this->results,
            'ds.results.time'         => $this->time,
            'ds.results.total'        => $this->total,
            'ds.keyword'              => $keyword,
            'ds.form'                 => $this->form,
            'ds.orderby.rel.checked'  => $this->orderby==='rel' ? 'checked' : '',
            'ds.orderby.date.checked' => $this->orderby==='date' ? 'checked' : '',
            'ds.paginate'             => $this->paginate
        ]);
    }

    public function set($key, $value) {
        $this->$key = $value;
    }

    public function get($key, $default=null) {
        if($this->$key === null) {
            return $default;
        }
        return $this->$key;
    }

    public function find($keyword) {
        if($this->mode==='fulltext') {
            $rs = $this->fulltextQuery($keyword);
        } else {
            $rs = $this->likeQuery($keyword);
        }
        $result = [];
        while($row = db()->getRow($rs)) {
            $row['summary'] = $this->addHighlight(
                $this->summary(
                    $row['plain_text'],
                    $keyword
                ),
                $keyword
            );
            $row['id'] = $row['doc_id'];
            $result[] = $row;
        }
        return $result;
    }

    private function addHighlight($str, $keyword) {
        $keywords = explode(' ', $keyword);
        foreach ($keywords as $i=>$v) {
            $keywords[$i] = sprintf('(%s)', preg_quote($v, '/'));
        }
        return preg_replace(
            '/('.implode('|', $keywords).')/ui',
            evo()->parseText(
                $this->config('tplResults.highlight'),
                [
                    'keyword' => '$0'
                ]
            ),
            $str
        );
    }

    public function total($keyword, $mode) {
        if($mode==='fulltext') {
            return $this->fetchTotalFulltext($keyword);
        }
        return $this->fetchTotalLike($keyword);
    }

    private function mode($keyword) {
        if($this->config('mode')!=='auto') {
            return $this->config('mode');
        }
        $min_token_size = evo()->getConfig('innodb_ft_min_token_size');
        if(!$min_token_size) {
            include_once __DIR__ . '/save-index.class.php';
            $min_token_size = dsIndex::tokenMinSize();
            dsIndex::saveSystemSetting('innodb_ft_min_token_size',$min_token_size);
            evo()->clearCache('full');
        }
        if(mb_strlen($keyword) < $min_token_size) {
            return 'like';
        }
        return 'fulltext';
    }

    private function fulltextQuery($keyword) {
        $field = [
            sprintf(
                "stext.*,content.*, MATCH (`tokens`) AGAINST (%s) as score",
                $this->matchAgainst($keyword)
            )
        ];
        if($this->orderby==='rel') {
            $field[] = $this->pagetitleScore($keyword);
        }

        return db()->select(
            implode(',', $field),
            [
                db()->config['table_prefix'] . 'search_content stext',
                'LEFT JOIN ' . db()->config['table_prefix'] . 'site_content content ON content.id=`stext`.doc_id'
            ],
            sprintf(
                "MATCH (`tokens`) AGAINST (%s)",
                // "MATCH (tokens) AGAINST ('%s' IN BOOLEAN MODE)",
                $this->matchAgainst($keyword)
            ),
            $this->orderby==='rel' ? 'contains_title DESC, score DESC' : 'stext.publishedon DESC',
            $this->limit($this->limit, getv('offset'))
        );
    }
    private function matchAgainst($keyword) {
        $_ = preg_split('/\s+/u', trim($keyword));
        if(count($_)==1) {
            return sprintf("'%s*'", $keyword);
        }
        $keywords = [];
        foreach($_ as $v) {
            $keywords[] = '+' . db()->escape($v) . '*';
        }
        return sprintf(
            "'%s' IN BOOLEAN MODE",
            implode(' ', $keywords)
        );
    }

    private function likeQuery($keyword) {
        $keyword = trim($keyword);
        $field = [
            sprintf(
                "stext.*,content.*,(LENGTH(stext.plain_text) - LENGTH(REPLACE(stext.plain_text, '%s', ''))) / LENGTH('%s') AS cnt",
                $keyword,
                $keyword
            )
        ];
        if($this->orderby==='rel') {
            $field[] = $this->pagetitleScore($keyword);
        }
        return db()->select(
            implode(',', $field), [
                db()->config['table_prefix'] . 'search_content `stext`',
                'LEFT JOIN ' . db()->config['table_prefix'] . 'site_content content ON content.id=`stext`.doc_id'
            ],
            $this->likeWhere($keyword),
            $this->orderby==='rel' ? 'contains_title DESC, cnt DESC' : 'stext.publishedon DESC',
            $this->limit($this->limit, getv('offset'))
        );
    }

    private function pagetitleScore ($keyword) {
        return evo()->parseText(
            "CASE WHEN stext.pagetitle LIKE '%[+keyword+]%' THEN 2 ELSE 1 END as contains_title",
            ['keyword'=>$keyword]
        );
    }

    public function buildPaginate($total,$limit,$offset) {
        if($total <= $limit) {
            return null;
        }
        return evo()->parseText(
            $this->config('tplPaginate.wrap'),
            [
                'prev' => $this->buildPaginatePrev($limit,$offset),
                'links' => $this->buildPaginateLinks($total,$limit),
                'next' => $this->buildPaginateNext($total,$limit,$offset)
            ]
        );
    }

    private function buildPaginatePrev($limit,$offset) {
        if(!$offset) {
            return $this->config('tplPaginate.no-prev');
        }
        $prevNum = $offset - $limit;
        unset($this->request['offset']);
        return evo()->parseText(
            $this->config('tplPaginate.prev'), [
                'prev-link' => $this->build_url(
                    $this->http_merge_query(
                        $prevNum <= 0
                            ? []
                            : ['offset'=>$prevNum]
                    )
                )
            ]
        );
    }

    private function build_url($query_string) {
        if(!$query_string) {
            return $this->current_base_url;
        }
        return $this->current_base_url . '?' . $query_string;
    }

    public function buildPaginateLinks($total,$limit) {
        static $content=null;
        if($content) {
            return $content;
        }
        if(!$this->isInt($total) || !$this->isInt($limit)) {
            return null;
        }
        $offset = getv('offset',0);
        $c=0;
        $links = [];
        unset($this->request['offset']);
        $num = !getv('offset') ? 1 : round(getv('offset')/$limit)+1;
        if($num <= 5) {
            $num = 1;
        } elseif(5<$num) {
            $num = $num-4;
        }
        $current_num = (getv('offset',0)/$limit)+1;
        // $this->config('tplPaginate.current-item'),
        while($c<10) {
            if($num==$current_num) {
                $links[] = evo()->parsetext(
                    $this->config('tplPaginate.current-item'), [
                        'num' => $num
                    ]
                );
            } else {
                $offset = $num==1 ? 0 : $limit * ($num-1);
                $links[] = evo()->parsetext(
                    $this->config('tplPaginate.linked-item'), [
                        'num' => $num,
                        'url' => $this->build_url(
                            $this->http_merge_query(
                                $offset
                                ? ['offset'=>$offset]
                                : []
                            )
                        )
                    ]
                );
            }
            if($total < $limit*$num) {
                break;
            }
            $num++;
            $c++;
        }
        $content = implode("\n", $links);
        return $content;
    }

    private function isInt($str) {
        return preg_match('@^[0-9]+$@',$str);
    }

    public function buildPaginateNext($total,$limit,$offset) {
        if($total < $limit + $offset) {
            return $this->config('tplPaginate.no-next');
        }
        return evo()->parseText(
            $this->config('tplPaginate.next'), [
                'next-link' => $this->build_url(
                    $this->http_merge_query(['offset'=>($offset + $limit)])
                )
            ]
        );
    }

    private function http_merge_query($array=[]) {
        if(!$this->request && !$array) {
            return null;
        }
        return http_build_query($this->array_merge($this->request, $array));
    }

    private function limit($limit, $offset=0) {
        if(!$offset) {
            return $limit;
        }
        return $limit . ' OFFSET ' . $offset;
    }

    private function fetchTotalFulltext($keyword) {
        return db()->getValue(
            db()->select(
                'count(*) as total',
                db()->config['table_prefix'] . 'search_content',
                sprintf(
                    "MATCH (`tokens`) AGAINST (%s)",
                    // "MATCH (tokens) AGAINST ('%s' IN BOOLEAN MODE)",
                    $this->matchAgainst($keyword)
                )
            )
        );
    }
    private function fetchTotalLike($keyword) {
        return db()->getValue(
            db()->select(
                'count(*) as total',
                db()->config['table_prefix'] . 'search_content',
                $this->likeWhere($keyword)
            )
        );
    }

    private function likeWhere($keyword) {
        $_ = explode(' ', $keyword);
        if(count($_)==1) {
            return evo()->parseText(
                "plain_text LIKE CONCAT('%[+keyword+]%')",
                ['keyword' => db()->escape($keyword)]
            );
        }
        $where = [];
        foreach($_ as $v) {
            $where[] = evo()->parseText(
                "plain_text LIKE CONCAT('%[+keyword+]%')",
                ['keyword' => db()->escape($v)]
            );
        }
        return implode(' AND ', $where);
    }

    private function summary($text, $keyword) {
        if(mb_strlen($text) < ($this->config('summaryLength')-30)) {
            return $text;
        }
        if(strpos($keyword, ' ')!==false) {
            return mb_substr($text, 0, $this->config('summaryLength'));
        }
        if(!preg_match('/[亜-熙ぁ-んァ-ヶ]/u', mb_substr($text, 0, $this->config('summaryLength')))) {
            return mb_substr($text, 0, $this->config('summaryLength'));
        }
        $pettern = strpos(evo()->config['manager_language'], 'japanese')===0
            ? '/[\s\n\.\!\?\,。、]+/u'
            : '/[\n\.\!\?]+/u'
        ;
        $_ = preg_split(
            $pettern,
            str_replace(
                '&nbsp;',
                ' ',
                strip_tags(
                    htmlspecialchars_decode($text)
                )
            )
        );
        $summary = [];
        foreach($_ as $i=>$v) {
            if(mb_stripos($v, $keyword)!==false) {
                if(!$i) {
                    $summary[0] = $v;
                    continue;
                }
                $prev=$i-1;
                $next=$i+1;
                if(!isset($summary[$prev])) {
                    $summary[$prev] = $_[$prev];
                }
                if(!isset($summary[$i])) {
                    $summary[$i] = $v;
                }
                if(isset($_[$next])) {
                    $summary[$next] = $_[$next];
                }
            }
        }
        return mb_substr(
            implode(' / ', $summary),
            0,
            $this->config('summaryLength')
        );
    }
    public function keyword() {
        return hsc(
            preg_replace(
                '@\s\s+@',
                ' ',
                strip_tags(
                    mb_convert_kana(getv($this->config('keyword')), 'Kas')
                )
            )
        );
    }

    private function array_merge($a,$b) {
        foreach($b as $k=>$v) {
            if(isset($a[$k]) && is_array($v)) {
                $a[$k] = $this->array_merge($a[$k], $v);
                continue;
            }
            $a[$k] = $v;
        }
        return $a;
    }
}
