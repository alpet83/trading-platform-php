<?php
    require_once 'lib/common.php';
    require_once 'lib/esctext.php';
    require_once 'lib/db_tools.php';
    require_once 'lib/auth_lib.php';    

    $log_file = fopen(__DIR__."/logs/tele_login.log", 'a');
    error_reporting(E_ERROR | E_PARSE);
    mysqli_report(MYSQLI_REPORT_ERROR);  
    const DB_CONFIG = '/usr/local/etc/php/db_config.php';
    if (!file_exists(DB_CONFIG)) {
        http_response_code(500);
        printf("FATAL: no DB config installed\n");
        error_exit("~C91#FATAL:~C00 no DB config installed");    
    }

    require_once DB_CONFIG;

    $mysqli = init_remote_db('trading');
    if (!$mysqli) {
        http_response_code(500);
        printf("FATAL: DB inaccessible!");
        error_exit("~C91#FATAL:~C00 DB inaccessible! Last server %s", $db_alt_server);
    }
        
    $title = "Trader login";
    $IP = $_SERVER['REMOTE_ADDR'];
    $debug = rqs_param("debugging", false);
    error_reporting(E_ERROR | E_PARSE | E_WARNING);
?>
<!DOCTYPE html>
<HTML>
    <HEAD>
    <TITLE><?php echo $title; ?></TITLE>   
    <script type="text/javascript">      
    function closeTab() {
        window.close();
    }
    function goBack() {            
        window.history.back();
    }
    function logOut() {
        document.location = '/tele_logout.php';
    }           
    function reload() {
        var base = '/tele_login.php';
        if (document.location == base)
            document.location.reload();
        else
            document.location = base;
    }
    function onLoad() {
       setTimeout(reload, 60000);  
    }
    </script>
    </HEAD>
<BODY onLoad='onLoad()'>
<?php
    if (session_status() == PHP_SESSION_NONE) 
        session_start();

    $color_scheme = 'none';
    $host = gethostbyaddr($IP);     
    log_cmsg("~C93#REQUEST:~C00 %s from %s (%s)", json_encode($_REQUEST), $host, $IP);    
    if (str_in($host, 'telegram.org')) {
        error_exit("~C91WARN:~C00 Telegram preview loader not allowed to login");
    }
        
    if ($debug)    
        printf("<!-- %s\n%s -->\n", 
                    print_r($_SERVER, true),
                            print_r($_SESSION, true));
    $atok = ($_SESSION['auth_token'] ?? false);
    if (!is_string($atok)) {
        log_cmsg("~C33#LOGIN:~C00 no auth token in session %s", json_encode($_SESSION));
        goto LOGIN;    
    }    
    $sid = intval($_SESSION['session_id']);        
    $ref = $mysqli->select_row('*', 'trader__sessions', "WHERE (id = $sid) AND (IP = '$IP')", MYSQLI_OBJECT);    
    if (is_null($ref)) {
        log_cmsg("~C31#WARN:~C00 no exists session #%d for %s", $sid, $IP);
        goto LOGIN;    
    }    
    $actual = fmt_auth_token($ref->ts, $ref->user_id, $ref->IP);
    if ($actual != $atok) {
        log_cmsg("~C31#WARN:~C00 Session %d is invalid, bad token %s\n", $sid,  $atok);
        goto LOGIN;    
    }    
    if ($ref->IP != $IP) {
        log_cmsg("~C31#STRANGE:~C00 Session %d is invalid, bad IP %s\n", $sid, $IP);
        goto LOGIN;
    }

    $user_name = $_SESSION['user_name'] ?? $mysqli->select_value('user_name', 'chat_users', "WHERE chat_id = {$ref->user_id}");
    $rights = $mysqli->select_value('rights', 'chat_users', "WHERE chat_id = {$ref->user_id}") ?? 'none';
    printf("<h2>Hello %s, U already logged in</h2>\n", $user_name);    
    $query = "UPDATE `chat_users` SET auth_pass = 0 WHERE chat_id = {$ref->user_id}";   
    $mysqli->try_query($query);         

    $id = $mysqli->safe_select('chat_id', '`chat_users`', "user_name = ? AND enabled = 1", [$user_name]);
    if (is_null($id)) 
        log_cmsg("#STRANGE: user id for %s not retrieved", $user_name);

?>  </PRE>      
    <h2>Server time:<?php echo gmdate('H:i:s') ?></h2>
    <h3>Session ID: <?php echo $_SESSION['session_id']; ?></h3>
    <h3>Auth token: <?php echo $atok; ?></h3>        
    <h3>Trader rights: <?php echo $rights; ?></h3>
    <input type="button" onClick="goBack()" value="Go back"> 
    <input type="button" onClick="closeTab()" value="Close Tab">    
    <input type="button" onClick="logOut()" value="Log out...">    
    <div>
        <a href="/bot">Trading bots control Room</a><br>
        <a href="/sig_edit.php">Signals editor</a><br>
        <a href="/grid_edit.php">Grid editor</a><br>
        <a href="/pairs_config.php">Pairs configurator</a><br>
    </div>

<?php
    function sync_session() {
        global $debug;
        $data = session_encode();
        $opts = new CurlOptions();
        $opts->extra[CURLOPT_COOKIE] = $_SERVER['HTTP_COOKIE']; 
        $sync = curl_http_request('http://vm-office.vpn/session_sync.php', ['session_data' => $data], $opts);
        if ($debug)    
            printf("<!-- %s, stored at %s, sync %s -->\n", $data, session_save_path(), $sync);
    }
    sync_session();
    die('.');
LOGIN:
    $user_name = rqs_param('login', false, 'none');
    
    if (!$user_name) {
        log_cmsg("~C31#WARN:~C00 no user name in request %s", json_encode($_REQUEST));
        goto LOGIN_FORM;
    }    
    
    $user_name = str_replace('@', '', $user_name);
    $user_name = trim($user_name);
    $res = $mysqli->safe_select('*', '`chat_users`', "(enabled = 1) AND (user_name = ?)", [$user_name]);
    // var_export($res);    
    if (!is_object($res)) {
        log_cmsg("~C91#ERROR_INTRUSION:~C00 user %s from %s not registered or disabled: %s / %s", 
                    $user_name, $IP, var_export($res, true), $mysqli->error); 
        printf("<h3>Failed: [%s] not registered or disabled</h3>\n", $user_name);
        http_response_code(403);
        die('!');
    }    
    $row = $res->fetch_assoc();
    if (!is_array($row))  {
        log_cmsg("~C91#ERROR:~C00 returned %s from DB", var_export($row, true));
        goto LOGIN_FORM;
    }
    $user_pass = $row['auth_pass'] * 1;
    $user_name = $row['user_name'];
    $user_id = $row['chat_id'];
    $pass = rqs_param('pass', 0) * 1;
    if ($pass < 100000 || $pass != $user_pass) {
        log_cmsg("~C91#ERROR:~C00 no pass %d active or OTP failure %s", $pass, json_encode($row));
        die("<h3>Failed: user $user_name not allowed to login. Send request to bot before repeat</h3>\n");        
    }    
    $query = "INSERT IGNORE `trader__sessions` (ts, user_id, IP) VALUES \n";
    $now = gmdate(SQL_TIMESTAMP);
    $row = sprintf("'%s', %d, '%s'", $now, $row['chat_id'], $IP);        
    $query .= "($row)";                        
    $query .= " ON DUPLICATE KEY UPDATE ts = '$now'";
    log_cmsg("#REQUEST: %s", $query);        
    if (!$mysqli->try_query($query))
        die("<h4>Failed: DB returned error</h4>\n");

    $new_sess = $mysqli->affected_rows > 0;   
    $res = $mysqli->select_row('*', 'trader__sessions', "WHERE (user_id = $user_id) AND (IP = '$IP')", MYSQLI_OBJECT);               
    if (is_object($res)) {
        $sess_id = $res->id;
        $atok = fmt_auth_token($res->ts, $res->user_id, $res->IP);                
        if ($new_sess)
            log_cmsg("~C04~C92#LOGIN_SUCCESS:~C00 new session for %s added: %d", $user_name, $sess_id);
        else
            log_cmsg("~C04~C97#LOGIN_SUCCESS:~C00 %s from %s, session = %d", $user_name, $IP,$sess_id);

        $host_id = $mysqli->select_value('id', 'hosts', "WHERE ip = '$IP'") ?? 9;   
        $query = "INSERT INTO `events` (`ts`, `host`, `tag`, `event`, `chat`, `value`, `flags`) VALUES\n";
        $row = "NOW(), $host_id, 'LOGIN', 'Session logged in from $IP by $user_name', $user_id, $sess_id, 4096";
        $query .= " ($row)";        
        if ($mysqli->try_query($query))
            log_msg("~C93#EVENT_LOGIN:~C00 insert %s", $mysqli->affected_rows > 0 ? 'success' : 'failed');
        $_SESSION['auth_token'] = $atok;
        $_SESSION['session_id'] = $sess_id;
        $_SESSION['user_name'] = $user_name;                    
        
        printf("<h2>Success: [%s] logged in</h2>\n", $user_name);            
        printf("<script>onLoad();</script>\n");               
        sync_session();
        session_write_close();
    } else {
        printf("<h2>Failed: [%s] not logged in</h2>\n", $user_name);
        log_cmsg("~C91#ERROR:~C00 from DB returned %s ", var_export($res, true));
    }
    
    die('.');

LOGIN_FORM:      
?>
<form action="tele_login.php" method="POST">
    Telegram login<input type="text" name="login" value="@user"><input type="submit" value="Login">  
</form>  