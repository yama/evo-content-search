<?php

class EvoContentSearch
{
    private $keyword;
    private $config = [];
    private $saveIndex;
    private $request;
    private $limit;
    private $results;
    private $rows;
    private $total;
    private $time;
    private $mode;
    private $current_base_url;
    private $form;
    private $orderby;
    private $paginate;
    private $likeEscapeChar = '!';

    public function __construct() {
        include_once __DIR__ . '/save-index.class.php';
        $this->saveIndex = new csIndex($this->config);

        $this->keyword = $this->treatedKeyword(getv('keyword'));
        $this->current_base_url = serverv('query_string')
            ? strstr(serverv('request_uri'), '?', true)
            : serverv('request_uri')
        ;
        $this->config = $this->loadConfig();
    }

    private function loadConfig() {
        $defaultConfig = include $this->themePath() . '/_default/config.php';
        if(!event()->params) {
            return $defaultConfig;
        }

        $themeConfigPath = $this->themePath() . event()->params['theme'].'/config.php';
        if(!event()->params['theme'] || !is_file($themeConfigPath)) {
            return $this->array_merge($defaultConfig, event()->params);
        }

        $themeConfig = include $themeConfigPath;
        if (!$themeConfig) {
            return $this->array_merge($defaultConfig, event()->params);
        }
        foreach (['css', 'tplForm', 'tplResults', 'tplPaginate'] as $k) {
            if (!isset($themeConfig[$k])) {
                $themeConfig[$k] = null;
            }
        }

        return $this->array_merge(
            $this->array_merge($defaultConfig, $themeConfig)
            , event()->params
        );
    }

    public function run() {
        if(getv('reset') || !db()->tableExists(db()->config['table_prefix'] . 'search_content')) {
            if(evo()->isLoggedIn('manager')) {
                $this->firstRun();
            }
            return;
        }
        $recent_update = evo()->getConfig('recent_update', globalv('recent_update'));
        $cs_recent_update = evo()->getConfig('cs_recent_update',0);

        if($cs_recent_update >= $recent_update && !$this->recentUpdate($cs_recent_update)) {
            return;
        }

        $this->saveIndex->saveAll(
            $cs_recent_update,
            ['basicAuth' => $this->config('basicAuth')]
        );
        $this->saveIndex->saveSystemSetting(
            'cs_recent_update',
            serverv('request_time') + evo()->getConfig('server_offset_time', 0)
        );
        evo()->clearCache('full');
    }

    private function firstRun() {
        if(!$this->saveIndex->hasPlugin()) {
            $this->saveIndex->createPlugin();
        }
        $this->saveIndex->createTable();
        $this->saveIndex->saveAll(null, ['basicAuth' => $this->config('basicAuth')]);
        $this->saveIndex->saveSystemSetting(
            'cs_recent_update',
            serverv('request_time')  + evo()->getConfig('server_offset_time', 0)
        );
        evo()->clearCache('full');
    }

    private function recentUpdate($cs_recent_update) {
        return db()->getRecordCount(
            db()->select(
                'id',
                db()->getFullTableName('site_content'), [
                    'published=1 and deleted=0 and searchable=1 and privateweb=0',
                    'AND editedon > ' . db()->escape($cs_recent_update)
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
    public function csBasePath() {
        return dirname(__DIR__) . '/';
    }
    public function themePath() {
        return $this->csBasePath() . 'themes/';
    }

    public function setProps() {
        $this->orderby = getv('orderby','rel');
        $this->limit = getv('limit') ?: $this->config('limit',10);
        $this->form = evo()->parseText(
            $this->config('tplForm.wrap'), [
                'keyword' => $this->keyword,
                'orderby.rel.checked'  => $this->orderby==='rel' ? 'checked' : '',
                'orderby.date.checked' => $this->orderby==='date' ? 'checked' : '',
                'limit' => $this->limit,
                'offset' => getv('offset',0),
                'admin-widget' => $this->adminWidgetMessage()
            ]
        );

        if($this->config('css')) {
            evo()->regClientCSS(
                sprintf('<style>%s</style>', $this->config('css'))
            );
        }

        if(!$this->keyword) {
            return;
        }

        $mode = getv('mode') ?: $this->mode($this->keyword);
        $total = $this->total($this->keyword, $mode);
        if(!$total && $mode === 'fulltext') {
            $total = $this->total($this->keyword, 'like');
            if(!$total) {
                return;
            }
            $mode = 'like';
        }
        $this->mode = $mode;

        $this->total = $total;
        $this->request = $_GET;

        $bt = microtime(true);
        $this->rows = $this->keyword
            ? $this->find($this->keyword)
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
        evo()->setPlaceholder(
            $this->config('placeholder-key'), [
                'results'  => $this->results,
                'time'     => $this->time,
                'total'    => $this->total,
                'keyword'  => $this->keyword,
                'form'     => $this->form,
                'orderby'  => $this->orderby,
                'paginate' => $this->paginate
            ]
        );
    }

    private function adminWidgetMessage() {
        if (!evo()->isLoggedIn('manager')) {
            return '';
        }
        return evo()->parseText(
            $this->config('tplForm.admin-widget'), [
                'url' => $this->current_base_url
            ]
        );
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
            $min_token_size = $this->saveIndex->tokenMinSize();
            $this->saveIndex->saveSystemSetting('innodb_ft_min_token_size',$min_token_size);
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
                "stext.*,content.*, MATCH (`tokens`) AGAINST (%s) as base_score",
                $this->matchAgainst($keyword)
            )
        ];
        if($this->orderby==='rel') {
            $field[] = $this->generateRelevanceScore($keyword);
        }

        return db()->select(
            implode(',', $field),
            [
                db()->getFullTableName('search_content') . ' stext',
                'LEFT JOIN ' . db()->getFullTableName('site_content') . ' content ON content.id=`stext`.doc_id'
            ],
            sprintf(
                "MATCH (`tokens`) AGAINST (%s)",
                // "MATCH (tokens) AGAINST ('%s' IN BOOLEAN MODE)",
                $this->matchAgainst($keyword)
            ),
            $this->orderby==='rel' ? 'relevance_score DESC' : 'stext.publishedon DESC',
            $this->limit($this->limit, getv('offset'))
        );
    }
    private function matchAgainst($keyword) {
        $_ = preg_split('/\s+/u', trim($keyword));
        if(count($_)==1) {
            return sprintf("'%s*'", db()->escape($keyword));
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
        $escapedKeyword = db()->escape($keyword);
        $field = [
            sprintf(
                "stext.*,content.*,(LENGTH(stext.plain_text) - LENGTH(REPLACE(stext.plain_text, '%s', ''))) / LENGTH('%s') AS cnt",
                $escapedKeyword,
                $escapedKeyword
            )
        ];
        if($this->orderby==='rel') {
            $field[] = $this->generateRelevanceScore($keyword);
        }
        return db()->select(
            implode(',', $field), [
                db()->getFullTableName('search_content') . ' `stext`',
                'LEFT JOIN ' . db()->getFullTableName('site_content') . ' content ON content.id=`stext`.doc_id'
            ],
            $this->likeWhere($keyword),
            $this->orderby==='rel' ? 'relevance_score DESC' : 'stext.publishedon DESC',
            $this->limit($this->limit, getv('offset'))
        );
    }

    /**
     * 高度なスコアリング式を生成
     *
     * 以下の要素を考慮した総合スコアを計算:
     * - フィールド重み付け（タイトル、ディスクリプション、本文冒頭、本文）
     * - キーワード密度
     * - 文書の長さによる正規化
     * - 新しさ（フレッシュネス）
     * - 短いキーワードの誤ヒット対策
     *
     * @param string $keyword 検索キーワード
     * @return string SQL式
     */
    private function generateRelevanceScore($keyword) {
        $escaped = db()->escape($keyword);
        $escapedLike = db()->escape($this->escapeLikeWildcard($keyword));
        $escapeChar = db()->escape($this->likeEscapeChar);

        return sprintf("
            (
                -- ベーススコア（FULLTEXT または 出現回数）
                COALESCE(base_score, cnt, 1.0)

                -- フィールドブースト（多段階の重み付け）
                * CASE
                    -- タイトルに完全一致（最高優先）
                    WHEN stext.pagetitle = '%s' THEN 20.0
                    -- タイトルの先頭に一致
                    WHEN stext.pagetitle LIKE '%s%%' ESCAPE '%s' THEN 15.0
                    -- タイトルに部分一致
                    WHEN stext.pagetitle LIKE '%%%s%%' ESCAPE '%s' THEN 8.0
                    -- ディスクリプションに一致
                    WHEN content.description LIKE '%%%s%%' ESCAPE '%s' THEN 4.0
                    -- 本文の冒頭300文字以内（重要度高）
                    WHEN SUBSTRING(stext.plain_text, 1, 300) LIKE '%%%s%%' ESCAPE '%s' THEN 2.5
                    -- 本文のみ
                    ELSE 1.0
                END

                -- 短いキーワード（2-3文字）の誤ヒット対策
                * CASE
                    WHEN CHAR_LENGTH('%s') <= 3 THEN
                        CASE
                            -- タイトルまたは本文冒頭にあれば信頼度高
                            WHEN stext.pagetitle LIKE '%%%s%%' ESCAPE '%s'
                              OR SUBSTRING(stext.plain_text, 1, 200) LIKE '%%%s%%' ESCAPE '%s'
                            THEN 1.0
                            -- 本文の中ほど以降は信頼度低（誤ヒットの可能性）
                            ELSE 0.3
                        END
                    ELSE 1.0
                END

                -- 文書長による正規化（短い文書での一致を優遇）
                * (1000.0 / (1000.0 + CHAR_LENGTH(stext.plain_text)))

                -- キーワード密度ボーナス（適度な出現頻度を評価）
                * (1.0 + LEAST(3.0,
                    (CHAR_LENGTH(stext.plain_text) -
                     CHAR_LENGTH(REPLACE(stext.plain_text, '%s', '')))
                    / CHAR_LENGTH('%s') / 20.0
                ))

                -- フレッシュネスブースト（新しい記事を優遇）
                -- 90日（7776000秒）ごとに半減
                * (1.0 + 0.5 * EXP(-1.0 * (UNIX_TIMESTAMP() - stext.publishedon) / 7776000))

            ) as relevance_score
        ",
            $escaped,      // タイトル完全一致
            $escapedLike,  // タイトル先頭一致
            $escapeChar,   // タイトル先頭一致: ESCAPE
            $escapedLike,  // タイトル部分一致
            $escapeChar,   // タイトル部分一致: ESCAPE
            $escapedLike,  // ディスクリプション
            $escapeChar,   // ディスクリプション: ESCAPE
            $escapedLike,  // 本文冒頭
            $escapeChar,   // 本文冒頭: ESCAPE
            $escaped,      // 短いキーワードチェック用
            $escapedLike,  // 短いキーワード: タイトル
            $escapeChar,   // 短いキーワード: タイトル ESCAPE
            $escapedLike,  // 短いキーワード: 本文冒頭
            $escapeChar,   // 短いキーワード: 本文冒頭 ESCAPE
            $escaped,      // キーワード密度計算用
            $escaped       // キーワード密度計算用
        );
    }

    public function buildPaginate($total,$limit,$offset) {
        if(!$this->config('paginateAlwaysShow') && $total <= $limit) {
            return null;
        }
        return evo()->parseText(
            $this->config('tplPaginate.wrap'),
            [
                'prev' => $this->buildPaginatePrev($limit,$offset),
                'pages' => $this->buildPaginatePages($total,$limit),
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

    public function buildPaginatePages($total,$limit) {
        static $content=null;
        if($content) {
            return $content;
        }
        if(!$this->isInt($total) || !$this->isInt($limit)) {
            return null;
        }
        $offset = getv('offset',0);
        $c=0;
        $pages = [];
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
                $pages[] = evo()->parsetext(
                    $this->config('tplPaginate.current-item'), [
                        'num' => $num
                    ]
                );
            } else {
                $offset = $num==1 ? 0 : $limit * ($num-1);
                $pages[] = evo()->parsetext(
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
        $content = implode("\n", $pages);
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
                db()->getFullTableName('search_content'),
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
                db()->getFullTableName('search_content'),
                $this->likeWhere($keyword)
            )
        );
    }

    private function likeWhere($keyword) {
        $keyword = $this->escapeLikeWildcard($keyword);
        $escapeChar = db()->escape($this->likeEscapeChar);

        $_ = explode(' ', $keyword);
        if(count($_)==1) {
            return sprintf(
                "plain_text LIKE CONCAT('%%%s%%') ESCAPE '%s'",
                db()->escape($keyword),
                $escapeChar
            );
        }
        $where = [];
        foreach($_ as $v) {
            $where[] = sprintf(
                "plain_text LIKE CONCAT('%%%s%%') ESCAPE '%s'",
                db()->escape($v),
                $escapeChar
            );
        }
        return implode(' AND ', $where);
    }

    private function escapeLikeWildcard($keyword) {
        $escapeChar = $this->likeEscapeChar;

        return strtr(
            $keyword,
            [
                $escapeChar => $escapeChar . $escapeChar,
                '\\' => $escapeChar . '\\',
                '%' => $escapeChar . '%',
                '_' => $escapeChar . '_'
            ]
        );
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
        $pettern = strpos(evo()->getConfig('manager_language'), 'japanese')===0
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
            if(mb_stripos($v, $keyword)===false) {
                continue;
            }
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
        $summary = $this->trimSummary($summary);
        return mb_substr(
            ($summary ? implode(' ', $summary) : $text),
            0,
            $this->config('summaryLength')
        ). '...';
    }

    private function trimSummary($summary) {
        // 空の要素を削除
        $summary = array_filter($summary, function($value) {
            return !empty($value);
        });

        // 重複する要素を削除
        $summary = array_unique($summary);

        // 一文字目が「[」の要素を削除
        $summary = array_filter($summary, function($value) {
            return $value[0] !== '[';
        });
        return $summary;
    }

    public function treatedKeyword($keyword=null) {
        if (!$keyword) {
            return '';
        }
        return hsc(
            preg_replace(
                '@\s\s+@',
                ' ',
                strip_tags(
                    mb_convert_kana($keyword, 'Kas')
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
