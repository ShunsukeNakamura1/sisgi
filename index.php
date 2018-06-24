<?php
require_once __DIR__ . '/vendor/autoload.php';

date_default_timezone_set('Asia/Tokyo');

error_log("start");

// POSTを受け取る
$postData = file_get_contents('php://input');
error_log($postData);

// jeson化
$json = json_decode($postData);

// ChannelAccessTokenとChannelSecret設定
$httpClient = setHttpClient();
$bot = createBot($httpClient);

foreach ($json->events as $event) {
    // イベントタイプがmessage
    if (isMessage($event)) {
        //ここから応答
        $textMessages = array(); //送信する文字列たちを格納する配列
        // メッセージタイプが文字列の場合
        if (isMessage_Text($event)) {
            $userMessage = $event->message->text;
            $mode = replyMode($userMessage);
            //それぞれのモードに対して応答
            switch ($mode) {
            case "hello":
                $textMessages[] = "はい，こんにちは．";
                break;
            case "insert_request":
                $num = explode("/", $userMessage);
                $now = date('Y-m-d H:i:s');
                $confirmMessage = "射数:".$num[1]."\n的中数:".$num[0]."\nで登録をします\n".$now;
                //はい ボタン
                $yes_post = new LINE\LINEBot\TemplateActionBuilder\PostbackTemplateActionBuilder("はい", $userMessage."@".$now);
                //いいえボタン
                $no_post = new LINE\LINEBot\TemplateActionBuilder\PostbackTemplateActionBuilder("いいえ", "no@".$now);
                //Confirmテンプレート
                $confirm = new LINE\LINEBot\MessageBuilder\TemplateBuilder\ConfirmTemplateBuilder($confirmMessage, [$yes_post, $no_post]);
                // Confirmメッセージを作る
                $replyMessage = new LINE\LINEBot\MessageBuilder\TemplateMessageBuilder("メッセージ", $confirm);
                $response = $bot->replyMessage($event->replyToken, $replyMessage);
                error_log(var_export($response,true));
                return;
            case "insert":
                $textMessages[] = "insert";
                
                break;
            case "explain":
                return;
            default:
                $textMessages[] = $event->message->text;
                $textMessages[] = "aiueo";
            }
        }
        //文字列以外は無視
        else {
            $textMessages[] = "分からん";
            return;
        }
        
        //応答メッセージをLINE用に変換
        $replyMessage = buildMessages($textMessages);
        //メッセージ送信
        $response = $bot->replyMessage($event->replyToken, $replyMessage);
        error_log(var_export($response,true));
    } //end of [ if (isMessage($event)) ]
    else {
        return;
    }
}
return;

//---------------------------------------------------------------------
function setHttpClient(): \LINE\LINEBot\HTTPClient\CurlHTTPClient
{
    $client = new \LINE\LINEBot\HTTPClient\CurlHTTPClient(getenv('LineMessageAPIChannelAccessToken'));
    return $client;
}

function createBot(\LINE\LINEBot\HTTPClient\CurlHTTPClient $httpClient): \LINE\LINEBot
{
    $bot = new \LINE\LINEBot($httpClient, ['channelSecret' => getenv('LineMessageAPIChannelSecret')]);
    return $bot;
}
/*データベース接続*/
function connectDataBase(): PDO
{
    $url = parse_url(getenv('DATABASE_URL'));
    $dsn = sprintf('pgsql:host=%s;dbname=%s', $url['host'], substr($url['path'], 1));
    $pdo = new PDO($dsn, $url['user'], $url['pass']);
    return $pdo;
}

function isPostback($event): bool
{
    if ($event->type == "postback") {
        return true;
    } else {
        return false;
    }
}
function isMessage($event): bool
{
    if ($event->type == "message") {
        return true;
    } else {
        return false;
    }
}
function isMessage_Text($event): bool
{
    if($event->message->type == "text") {
        return true;
    } else {
        return false;
    }
}
function isGroup($event): bool
{
    if ($event->source->type == "group") {
        return true;
    } else {
        return false;
    }
}

/*登録しようとしているデータが新しいもの(登録済みでない or Noが押されてない)か調べる
insert :その日初めてのデータ > 登録
old    :最新のものではないデータ > 無視
update :最新のデータ > 更新する*/
function insertMode($userID, $newDateTime): array
{
    $buf = explode(" ", $newDateTime);
    $date = $buf[0];
    $newTime = $buf[1];
    //時間を見て調べる
    try{
        $pdo = connectDataBase();
        $stmt = $pdo->prepare("select hit, atmpt, time from record where userid = :userID and date=:date");
        $stmt->bindParam(':userID', $userID, PDO::PARAM_STR);
        $stmt->bindParam(':date', $date, PDO::PARAM_STR);
        $stmt->execute();
    } catch (PDOException $e) {
        echo "PDO Error:".$e->getMessage()."\n";
        die();
    }
    if ($result = $stmt->fetch(PDO::FETCH_ASSOC)) {
        //登録済みだった場合時間を比較
        $buf = explode(":", $newTime);
        error_log(var_export($buf, true));
        $newTime = (int)$buf[0]*10000 + (int)$buf[1]*100 + (int)$buf[2]*1;
        $buf = explode(":", $result['time']);
        $time = (int)$buf[0]*10000 + (int)$buf[1]*100 + (int)$buf[2]*1;
        //新しいデータなら更新、古ければ無視
        if ($newTime > $time) {
            return array("mode" => "update", "hit" => $result['hit'], "atmpt" => $result['atmpt']);
        } else {
            return array("mode" => "old", "hit" => 0, "atmpt" => 0);
        }
    } else {
        //登録されていなかった場合レコードを登録
        return array("mode" => "insert", "hit" => 0, "atmpt" => 0);
    }
}

/*ユーザ入力が分数の形かつ分母が大きいかを調べる*/
function isFraction($userMessage): bool
{
    if ( preg_match("#^\d+/\d+$#", $userMessage, $matches) ) {
        $numbers = explode("/", $userMessage);
        if ($numbers[0] <= $numbers[1]) {
            return true;
        }
    }
    return false;
}

/*ユーザ入力がレコード登録のフォーマット(key + 数値かどうか判定)*/
function isRecord($userMessage):bool
{
    mb_regex_encoding("UTF-8");
    if( preg_match("#^[ぁ-んァ-ヶー一-龠a-zA-Z0-9]+$/u\s\d+$#")){
        return true;
    }
    return false;
}
/*ユーザメッセージに応じて対応のモードを返す*/
function replyMode($userMessage): string
{
    if (isFraction($userMessage)) {
        return "insert_request";
    } else if (isRecord($userMessage)) {
        return "insert";
    } else if ($userMessage == "こんにちは") {
        return "hello";
    } else if ($userMessage == "使い方") {
        return "explain";
    } else {
        return "copy";
    }
}

/*文字列の配列を引数として送信用メッセージ(LINE用)を返す*/
function buildMessages($textMessages): \LINE\LINEBot\MessageBuilder\MultiMessageBuilder
{
    $replyMessage = new \LINE\LINEBot\MessageBuilder\MultiMessageBuilder();
    foreach($textMessages as $message){
        $a = new \LINE\LINEBot\MessageBuilder\TextMessageBuilder($message);
        $replyMessage->add($a);
    }
    return $replyMessage;
}