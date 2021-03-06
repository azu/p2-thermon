<?php
/**
 * rep2 - スレッド表示スクリプト
 * フレーム分割画面、右下部分
 */

require_once './conf/conf.inc.php';
require_once P2_LIB_DIR . '/NgAbornCtl.php';
require_once P2_LIB_DIR . '/ThreadRead.php';

$_login->authorize(); // ユーザ認証

// +Wiki
require_once P2_LIB_DIR . '/wiki/read.inc.php';

//================================================================
// 変数
//================================================================
$newtime = date('gis');  // 同じリンクをクリックしても再読込しない仕様に対抗するダミークエリー
// $_today = date('y/m/d');
$is_ajax = !empty($_GET['ajax']);

//=================================================
// スレの指定
//=================================================
detectThread();    // global $host, $bbs, $key, $ls

//=================================================
// レスフィルタ
//=================================================
$word = isset($_REQUEST['word']) ? $_REQUEST['word'] : null;
$res_filter = array('field' => 'hole', 'match' => 'on', 'method' => 'or');
if (!empty($_REQUEST['field']))  { $res_filter['field']  = $_REQUEST['field'];  }
if (!empty($_REQUEST['match']))  { $res_filter['match']  = $_REQUEST['match'];  }
if (!empty($_REQUEST['method'])) { $res_filter['method'] = $_REQUEST['method']; }

if (isset($word) && strlen($word) > 0) {
    if ($res_filter['method'] == 'regex' && substr_count($word, '.') == strlen($word)) {
        $word = null;
    } elseif (p2_set_filtering_word($word, $res_filter['method']) !== null) {
        $_conf['filtering'] = true;
        if ($_conf['ktai']) {
            $page = (isset($_REQUEST['page'])) ? max(1, intval($_REQUEST['page'])) : 1;
            $filter_range = array(
                'page'  => $page,
                'start' => ($page - 1) * $_conf['mobile.rnum_range'] + 1,
                'to'    => $page * $_conf['mobile.rnum_range'],
            );
        }
    } else {
        $word = null;
    }
} else {
    $word = null;
}

//=================================================
// フィルタ値保存
//=================================================
$cachefile = $_conf['pref_dir'] . '/p2_res_filter.txt';

// フィルタ指定がなければ前回保存を読み込む（フォームのデフォルト値で利用）
if (!isset($GLOBALS['word'])) {

    if ($res_filter_cont = FileCtl::file_read_contents($cachefile)) {
        $res_filter = unserialize($res_filter_cont);
    }

// フィルタ指定があれば
} else {

    // ボタンが押されていたなら、ファイルに設定を保存
    if (isset($_REQUEST['submit_filter'])) { // !isset($_REQUEST['idpopup'])
        FileCtl::make_datafile($cachefile, $_conf['p2_perm']); // ファイルがなければ生成
        if ($res_filter) {
            $res_filter_cont = serialize($res_filter);
        }
        if ($res_filter_cont && !$popup_filter) {
            if (FileCtl::file_write_contents($cachefile, $res_filter_cont) === false) {
                p2die('cannot write file.');
            }
        }
    }
}


//=================================================
// あぼーん&NGワード設定読み込み
//=================================================
$GLOBALS['ngaborns'] = NgAbornCtl::loadNgAborns();

//==================================================================
// メイン
//==================================================================

if (!isset($aThread)) {
    $aThread = new ThreadRead();
}

// lsのセット
if (!empty($ls)) {
    $aThread->ls = mb_convert_kana($ls, 'a');
}

//==========================================================
// idxの読み込み
//==========================================================

// hostを分解してidxファイルのパスを求める
if (!isset($aThread->keyidx)) {
    $aThread->setThreadPathInfo($host, $bbs, $key);
}

// 板ディレクトリが無ければ作る
// FileCtl::mkdir_for($aThread->keyidx);

$aThread->itaj = P2Util::getItaName($host, $bbs);
if (!$aThread->itaj) { $aThread->itaj = $aThread->bbs; }

// idxファイルがあれば読み込む
if ($lines = FileCtl::file_read_lines($aThread->keyidx, FILE_IGNORE_NEW_LINES)) {
    $idx_data = explode('<>', $lines[0]);
} else {
    $idx_data = array_fill(0, 12, '');
}
$aThread->getThreadInfoFromIdx();

//==========================================================
// preview >>1
//==========================================================

//if (!empty($_GET['onlyone'])) {
if (!empty($_GET['one'])) {
    $aThread->ls = '1';
    $aThread->resrange = array('start' => 1, 'to' => 1, 'nofirst' => false);

    // 必ずしも正確ではないが便宜的に
    //if (!isset($aThread->rescount) && !empty($_GET['rc'])) {
    if (!isset($aThread->rescount) && !empty($_GET['rescount'])) {
        //$aThread->rescount = $_GET['rc'];
        $aThread->rescount = (int)$_GET['rescount'];
    }

    $preview = $aThread->previewOne();
    $ptitle_ht = htmlspecialchars($aThread->itaj, ENT_QUOTES) . ' / ' . $aThread->ttitle_hd;

    // PC
    if (!$_conf['ktai']) {
        $read_header_inc_php = P2_LIB_DIR . '/read_header.inc.php';
        $read_footer_inc_php = P2_LIB_DIR . '/read_footer.inc.php';
    // 携帯
    } else {
        $read_header_inc_php = P2_LIB_DIR . '/read_header_k.inc.php';
        $read_footer_inc_php = P2_LIB_DIR . '/read_footer_k.inc.php';
    }

    require_once $read_header_inc_php;
    echo $preview;
    require_once $read_footer_inc_php;

    return;
}

//===========================================================
// DATのダウンロード
//===========================================================
$offline = !empty($_GET['offline']);

if (!$offline) {
    $aThread->downloadDat();
}

// DATを読み込み
$aThread->readDat();

// オフライン指定でもログがなければ、改めて強制読み込み
if (empty($aThread->datlines) && $offline) {
    $aThread->downloadDat();
    $aThread->readDat();
}

// タイトルを取得して設定
$aThread->setTitleFromLocal();

//===========================================================
// 表示レス番の範囲を設定
//===========================================================
if ($_conf['ktai']) {
    $before_respointer = $_conf['mobile.before_respointer'];
} else {
    $before_respointer = $_conf['before_respointer'];
}

// 取得済みなら
if ($aThread->isKitoku()) {

    //「新着レスの表示」の時は特別にちょっと前のレスから表示
    if (!empty($_GET['nt'])) {
        if (substr($aThread->ls, -1) == '-') {
            $n = $aThread->ls - $before_respointer;
            if ($n < 1) { $n = 1; }
            $aThread->ls = $n . '-';
        }

    } elseif (!$aThread->ls) {
        $from_num = $aThread->readnum +1 - $_conf['respointer'] - $before_respointer;
        if ($from_num < 1) {
            $from_num = 1;
        } elseif ($from_num > $aThread->rescount) {
            $from_num = $aThread->rescount - $_conf['respointer'] - $before_respointer;
        }
        $aThread->ls = $from_num . '-';
    }

    if ($_conf['ktai'] && strpos($aThread->ls, 'n') === false) {
        $aThread->ls = $aThread->ls . 'n';
    }

// 未取得なら
} else {
    if (!$aThread->ls) {
        $aThread->ls = $_conf['get_new_res_l'];
    }
}

// フィルタリングの時は、all固定とする
if (isset($word)) {
    $aThread->ls = 'all';
}

$aThread->lsToPoint();

//===============================================================
// プリント
//===============================================================
$ptitle_ht = htmlspecialchars($aThread->itaj, ENT_QUOTES)." / ".$aThread->ttitle_hd;

if ($_conf['ktai']) {

    if (isset($GLOBALS['word']) && strlen($GLOBALS['word']) > 0) {
        $GLOBALS['filter_hits'] = 0;
    } else {
        $GLOBALS['filter_hits'] = NULL;
    }

    require_once P2_LIB_DIR . '/ShowThreadK.php';
    $aShowThread = new ShowThreadK($aThread);

    if ($is_ajax) {
        $response = trim(mb_convert_encoding($aShowThread->getDatToHtml(true), 'UTF-8', 'CP932'));
        if (isset($_GET['respop_id'])) {
            $response = preg_replace('/<[^<>]+? id="/u', sprintf('$0_respop%d_', $_GET['respop_id']), $response);
        }
        /*if ($_conf['iphone']) {
            // HTMLの断片をXMLとして渡してもDOMでidやclassが期待通りに反映されない
            header('Content-Type: application/xml; charset=UTF-8');
            //$responseId = 'ajaxResponse' . time();
            $doc = new DOMDocument();
            $err = error_reporting(E_ALL & ~E_WARNING);
            $html = '<html><head>'
                  . '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">'
                  . '</head><body>'
                  . $response
                  . '</body></html>';
            $doc->loadHTML($html);
            error_reporting($err);
            echo '<?xml version="1.0" encoding="utf-8" ?>';
            echo $doc->saveXML($doc->getElementsByTagName('div')->item(0));
        } else {*/
            // よって、HTMLの断片をそのまま返してinnterHTMLに代入しないといけない。
            // (根本的にレスポンスのフォーマットとクライアント側での処理を変えない限りは)
            header('Content-Type: text/html; charset=UTF-8');
            echo $response;
        //}
    } else {
        if ($aThread->rescount) {
            $content = $aShowThread->getDatToHtml();
        } else if ($aThread->diedat && count($aThread->datochi_residuums) > 0) {
            $content = $aShowThread->getDatochiResiduums();
        }

        require_once P2_LIB_DIR . '/read_header_k.inc.php';

        if ($_conf['iphone'] && $_conf['expack.spm.enabled']) {
            echo $aShowThread->getSpmObjJs();
        }

        echo $content;

        require_once P2_LIB_DIR . '/read_footer_k.inc.php';
    }

} else {

    // ヘッダ 表示
    require_once P2_LIB_DIR . '/read_header.inc.php';
    flush();

    //===========================================================
    // ローカルDatを変換してHTML表示
    //===========================================================
    // レスがあり、検索指定があれば
    if (isset($word) && $aThread->rescount) {

        $all = $aThread->rescount;

        $GLOBALS['filter_hits'] = 0;

        echo $insert="<p><b id=\"filterstart\">{$all}レス中 <span id=\"searching\">{$GLOBALS['filter_hits']}</span>レスがヒット</b></p>\n";
        echo <<<EOP
<script type="text/javascript">
//<![CDATA[
var searching = document.getElementById('searching');
function filterCount(n){
    if (searching) {
        searching.innerHTML = n;
    }
}
//]]>
</script>
EOP;
    }

    //$GLOBALS['debug'] && $GLOBALS['profiler']->enterSection("datToHtml");

    if ($aThread->rescount) {
        $mainhtml = '';
        require_once P2_LIB_DIR . '/ShowThreadPc.php';
        $aShowThread = new ShowThreadPc($aThread);

        if ($_conf['expack.spm.enabled']) {
            echo $aShowThread->getSpmObjJs();
        }

        if ($_conf['coloredid.enable'] > 0 && $_conf['coloredid.click'] > 0 &&
            $_conf['coloredid.rate.type'] > 0) {
            list($body,$quoted)= $aShowThread->datToHtml(true,false,true);
            $mainhtml .=$body;
        } else {
            list($body,$quoted)=$aShowThread->datToHtml(false,false,true);
        }
        $res1 = $aShowThread->quoteOne(); // >>1ポップアップ用
        $quoted.=$res1['q'];

        // レス追跡カラー
        if ($_conf['backlink_coloring_track']) {
            echo $aShowThread->getResColorJs();
        }

        // IDカラーリング
        if ($_conf['coloredid.enable'] > 0 && $_conf['coloredid.click'] > 0) {
            echo $aShowThread->getIdColorJs();
            // ブラウザ負荷軽減のため、CSS書き換えスクリプトの後でコンテンツを
            // レンダリングさせる
            echo $mainhtml;
        }
    } else if ($aThread->diedat && count($aThread->datochi_residuums) > 0) {
        require_once P2_LIB_DIR . '/ShowThreadPc.php';
        $aShowThread = new ShowThreadPc($aThread);
        echo $aShowThread->getDatochiResiduums();
    }

    //$GLOBALS['debug'] && $GLOBALS['profiler']->leaveSection("datToHtml");

    // フィルタ結果を表示
    if ($word && $aThread->rescount) {
        echo <<<EOP
<script type="text/javascript">
//<![CDATA[
var filterstart = document.getElementById('filterstart');
if (filterstart) {
    filterstart.style.backgroundColor = 'yellow';
    filterstart.style.fontWeight = 'bold';
}
//]]>
</script>\n
EOP;
        if ($GLOBALS['filter_hits'] > 5) {
            echo "<p><b class=\"filtering\">{$all}レス中 {$GLOBALS['filter_hits']}レスがヒット</b></p>\n";
        }
    }
    echo $quoted;

    // フッタ 表示
    require_once P2_LIB_DIR . '/read_footer.inc.php';

}
flush();

//===========================================================
// idxの値を設定、記録
//===========================================================
if ($aThread->rescount) {

    // 検索の時は、既読数を更新しない
    if ((isset($GLOBALS['word']) && strlen($GLOBALS['word']) > 0) || $is_ajax) {
        $aThread->readnum = $idx_data[5];
    } else {
        $aThread->readnum = min($aThread->rescount, max(0, $idx_data[5], $aThread->resrange['to']));
    }
    $newline = $aThread->readnum + 1; // $newlineは廃止予定だが、旧互換用に念のため

    $sar = array($aThread->ttitle, $aThread->key, $idx_data[2], $aThread->rescount, '',
                $aThread->readnum, $idx_data[6], $idx_data[7], $idx_data[8], $newline,
                $idx_data[10], $idx_data[11], $aThread->datochiok);
    P2Util::recKeyIdx($aThread->keyidx, $sar); // key.idxに記録
}

//===========================================================
// 履歴を記録
//===========================================================
if ($aThread->rescount && !$is_ajax) {
    $newdata = "{$aThread->ttitle}<>{$aThread->key}<>$idx_data[2]<><><>{$aThread->readnum}<>$idx_data[6]<>$idx_data[7]<>$idx_data[8]<>{$newline}<>{$aThread->host}<>{$aThread->bbs}";
    recRecent($newdata);
}

// NGあぼーんを記録
NgAbornCtl::saveNgAborns();

// 以上 ---------------------------------------------------------------
exit;

//===============================================================================
// 関数
//===============================================================================
// {{{ detectThread()

/**
 * スレッドを指定する
 */
function detectThread()
{
    global $_conf, $host, $bbs, $key, $ls;

    list($nama_url, $host, $bbs, $key, $ls) = P2Util::detectThread();

    if (!($host && $bbs && $key)) {
        if ($nama_url) {
            $nama_url = htmlspecialchars($nama_url, ENT_QUOTES);
            p2die('スレッドの指定が変です。', "<a href=\"{$nama_url}\">{$nama_url}</a>", true);
        } else {
            p2die('スレッドの指定が変です。');
        }
    }
}

// }}}
// {{{ recRecent()

/**
 * 履歴を記録する
 */
function recRecent($data)
{
    global $_conf;

    $lock = new P2Lock($_conf['recent_idx'], false);

    // $_conf['recent_idx'] ファイルがなければ生成
    FileCtl::make_datafile($_conf['recent_idx'], $_conf['rct_perm']);

    $lines = FileCtl::file_read_lines($_conf['recent_idx'], FILE_IGNORE_NEW_LINES);
    $neolines = array();

    // {{{ 最初に重複要素を削除しておく

    if (is_array($lines)) {
        foreach ($lines as $l) {
            $lar = explode('<>', $l);
            $data_ar = explode('<>', $data);
            if ($lar[1] == $data_ar[1]) { continue; } // keyで重複回避
            if (!$lar[1]) { continue; } // keyのないものは不正データ
            $neolines[] = $l;
        }
    }

    // }}}

    // 新規データ追加
    array_unshift($neolines, $data);

    while (sizeof($neolines) > $_conf['rct_rec_num']) {
        array_pop($neolines);
    }

    // {{{ 書き込む

    if ($neolines) {
        $cont = '';
        foreach ($neolines as $l) {
            $cont .= $l . "\n";
        }

        if (FileCtl::file_write_contents($_conf['recent_idx'], $cont) === false) {
            p2die('cannot write file.');
        }
    }

    // }}}

    return true;
}

// }}}

/*
 * Local Variables:
 * mode: php
 * coding: cp932
 * tab-width: 4
 * c-basic-offset: 4
 * indent-tabs-mode: nil
 * End:
 */
// vim: set syn=php fenc=cp932 ai et ts=4 sw=4 sts=4 fdm=marker:
