<?php
return [
    'mode' => 'auto', // auto|fulltext|like
    'limit' => 10,
    'summaryLength'=> 200,
    // 'minChars' => 2,
    'keyword' => 'keyword',
    // 'basicAuth' => 'username:password',
    'css'  => file_get_contents(__DIR__ . '/template/style.css'),
    'tplForm' => [
        'wrap'    => file_get_contents(__DIR__ . '/template/form.html'),
        'admin-widget' => '<div class="admin-widget"><span><a href="[+url+]?reset=index">インデックスを再構築する</a></span></div>'
    ],
    'tplResults' => [
        'wrap'    => '<div>[+info+][+results+][+paginate+]</div>',
        'info'    => '<p>「<span class="ajaxSearch_highlight">[+keyword+]</span>」で検索した結果、[+total+]件の記事が見つかりました。[+time+]秒</p>',
        'result'  => file_get_contents(__DIR__ . '/template/result.html'),
        'highlight' => '<span class="ajaxSearch_highlight ajaxSearch_highlight1">[+keyword+]</span>',
        'noResult' => '<p>記事はありません。</p>',
        'enoughCharResult' => '検索文字数が足りません。'
    ],
    'tplPaginate' => [
        'wrap'         => '<div class="paginate">[+prev+][+links+][+next+]</div>',
        'prev'         => '<span class="prev"><a href="[+prev-link+]">前へ</a></span>',
        'linked-item'  => '<span><a href="[+url+]">[+num+]</a></span>',
        'current-item' => '<span class="current">[+num+]</span>',
        'next'         => '<span class="next"><a href="[+next-link+]">次へ</a></span>',
        'no-prev'      => '<span class="prev inactive">前へ</span>',
        'no-next'      => '<span class="next inactive">次へ</span>',
    ]
];
