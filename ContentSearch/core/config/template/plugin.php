// check OnLoadWebDocument

if(empty($_GET['template']) || $_GET['template']!=='blank') {
    return;
}

if(event()->name === 'OnLoadWebDocument') {
//    $modx->config['cache_type'] = 0;
    $modx->documentContent = '[*content*]';
}
