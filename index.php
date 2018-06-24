<?php
require_once __DIR__ . '/vendor/autoload.php';
include( 'GoogChart.class.php' );

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
            $userID = $event->source->userId;  //ユーザID
            $date = date('Y-m-d');             //日にち
            $time = date('H:i:s');             //時間
            //それぞれのモードに対して応答
            switch ($mode) {
            case "hello":
                $textMessages[] = "はい，こんにちは．";
                break;
            case "insert":
                $data = explode(" ", $userMessage);
                $key = $data[0];                   //キー
                $value = $data[1];                 //値
                try{
                    $pdo = connectDataBase();
                    $stmt = $pdo->prepare("insert into record values(:userid, :key, :value, :date, :time)");
                    $stmt->bindParam(':userid', $userID, PDO::PARAM_STR);
                    $stmt->bindParam(':key', $key, PDO::PARAM_STR);
                    $stmt->bindParam(':value', $value, PDO::PARAM_STR);
                    $stmt->bindParam(':date', $date, PDO::PARAM_STR);
                    $stmt->bindParam(':time', $time, PDO::PARAM_STR);
                    $stmt->execute();
                } catch (PDOException $e) {
                    error_log("PDO Error:".$e->getMessage()."\n");
                    die();
                }
                $textMessages[] = "登録しました．";
                break;
            case "disp":
                $data = explode(" ", $userMessage);
                $key = $data[1];  //キー
                try{//レコードを取得
                    $pdo = connectDataBase();
                    $stmt = $pdo->prepare("select * from record where userid = :userid and key = :key");
                    $stmt->bindParam(':userid', $userID, PDO::PARAM_STR);
                    $stmt->bindParam(':key', $key, PDO::PARAM_STR);
                    $stmt->execute();
                } catch (PDOException $e) {
                    error_log("PDO Error:".$e->getMessage()."\n");
                    die();
                }
                $chart = new GoogChart();
                $data = array();
                if ($result = $stmt->fetchAll()) {
                    foreach($result as $record){
                        $data[$record['date'].' '.$record['time']] = $record['value'];
                    }
                    $chart->setChartAttrs( array(
                        'type' => 'line',
                        'title' => $key,
                        'data' => $data,
                        'size' => array( 600, 500 ),
                        'color' => array('#99C754', '#54C7C5', '#999999',),
                        'labelsXY' => true,
                        'fill' => array( '#eeeeee', '#aaaaaa' ),
                    ));
                    $textMessages[] = $chart->display();
                }

                else {
                    $textMessages[] = "記録がありません．";
                }
                break;
            case "explain":
                return;
            default:
                $textMessages[] = $event->message->text;
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

/*ユーザ入力がレコード登録のフォーマット(key + 数値)かどうか判定*/
function isRecord($userMessage):bool
{
    if( preg_match("#^[ぁ-んァ-ヶー一-龠a-zA-Z0-9]+\s\d+\.{0,1}\d*$#u", $userMessage)){
        return true;
    }
    return false;
}
/*ユーザ入力がレコード表示リクエストのフォーマット(記録 + キー)かどうか判定*/
function isDisp($userMessage):bool
{
    if(preg_match("#^記録\s[ぁ-んァ-ヶー一-龠a-zA-Z0-9]+$#u", $userMessage)) {
        return true;
    }
    return false;
}

/*ユーザメッセージに応じて対応のモードを返す*/
function replyMode($userMessage): string
{
    if (isRecord($userMessage)) {
        return "insert";
    } else if(isDisp($userMessage)) {
        return "disp";
    }else if ($userMessage == "こんにちは") {
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