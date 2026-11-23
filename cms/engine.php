<?php
    require_once(dirname(__FILE__).'/security.php');
    include_once(dirname(__FILE__).'/slug.php');
    // optional per-installation hook (e.g. the playground picks a per-visitor sandbox database here)
    if (file_exists(dirname(__FILE__).'/bootstrap.php')) include_once(dirname(__FILE__).'/bootstrap.php');

    if (!isset($EVOLVIT_DB_MAIN))      $EVOLVIT_DB_MAIN      = dirname(__FILE__).'/db/main.sqlite';
    if (!isset($EVOLVIT_DB_USERFILES)) $EVOLVIT_DB_USERFILES = dirname(__FILE__).'/db/userfiles.sqlite';
    // uploaded files are stored on disk (not in sqlite); only metadata lives in userfiles._files
    if (!isset($EVOLVIT_UPLOADS_DIR))  $EVOLVIT_UPLOADS_DIR  = defined('EVOLVIT_UPLOADS_DIR') ? EVOLVIT_UPLOADS_DIR : dirname(__FILE__).'/uploads';

    // read-only connections (e.g. playground visitors that have not changed anything yet)
    if (!isset($EVOLVIT_DB_READONLY)) $EVOLVIT_DB_READONLY = false;
    try{
        $dbopts = $EVOLVIT_DB_READONLY ? array(PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY) : array();
        if (class_exists('Pdo\Sqlite')) $db = new Pdo\Sqlite('sqlite:'.$EVOLVIT_DB_MAIN, null, null, $dbopts);
        else                            $db = new PDO('sqlite:'.$EVOLVIT_DB_MAIN, null, null, $dbopts);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $att = $db->prepare("ATTACH DATABASE :path AS userfiles");
        $att->execute(array('path' => $EVOLVIT_DB_USERFILES));
    }
    catch(PDOException $e) {
        error_log('EvolvitCMS: unable to open database: '.$e->getMessage());
        die('Unable to open database connection');
    }

    function evolvit_db_function($name, $callback, $argc) {
        global $db;
        if (method_exists($db, 'createFunction')) $db->createFunction($name, $callback, $argc);
        else $db->sqliteCreateFunction($name, $callback, $argc);
    }
    function evolvit_db_collation($name, $callback) {
        global $db;
        if (method_exists($db, 'createCollation')) $db->createCollation($name, $callback);
        else $db->sqliteCreateCollation($name, $callback);
    }

    // helper functions for splitting comma separated string for old sqlite (without with statement)
    evolvit_db_function('substr_count', 'substr_count', 2);
    evolvit_db_function('password_hash', 'password_hash2', 1);
    evolvit_db_function('password_verify', 'password_verify', 2);
    evolvit_db_function('explode_by_index', 'explode_by_index', 2);
    evolvit_db_function('makeSlugs', 'makeSlugs', 1);

    evolvit_db_collation('NATURAL_CMP', 'strnatcmp');
    evolvit_db_collation('SR_LATIN_CMP', 'SR_LATIN_CMP');

    /*
     * One-time migration: move file contents out of sqlite blobs into $EVOLVIT_UPLOADS_DIR.
     */
    function evolvit_uploads_dir(){
        global $EVOLVIT_UPLOADS_DIR;
        if (!is_dir($EVOLVIT_UPLOADS_DIR)) @mkdir($EVOLVIT_UPLOADS_DIR, 0755, true);
        return rtrim($EVOLVIT_UPLOADS_DIR, '/');
    }
    function evolvit_new_upload_name($extension){
        return bin2hex(random_bytes(12)).($extension !== '' ? '.'.strtolower($extension) : '');
    }
    function evolvit_upload_path($file_path){
        $b = basename((string)$file_path);
        if ($b === '' || $b[0] === '.' || $b !== $file_path) return null;
        return evolvit_uploads_dir().'/'.$b;
    }
    function evolvit_migrate_files_to_disk(){
        global $db;
        try {
            $hasTable = $db->query("select count(*) from userfiles.sqlite_master where type='table' and name='_files'")->fetchColumn();
            if (!$hasTable) return;
            $cols = array_column($db->query('PRAGMA userfiles.table_info(_files)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (!in_array('file_path', $cols, true)) $db->exec('ALTER TABLE userfiles._files ADD COLUMN file_path TEXT');
            if (!$db->query('select 1 from userfiles._files where image_blob is not null limit 1')->fetchColumn()) return;
            $dir = evolvit_uploads_dir();
            if (!is_writable($dir)) { error_log('EvolvitCMS: uploads dir not writable: '.$dir); return; }
            $ids = $db->query('select id from userfiles._files where image_blob is not null')->fetchAll(PDO::FETCH_COLUMN);
            $sel = $db->prepare('select image_blob, extension from userfiles._files where id = :id');
            $upd = $db->prepare('update userfiles._files set file_path = :p, image_blob = null where id = :id');
            foreach ($ids as $id) {
                $sel->execute(array('id' => $id));
                $row = $sel->fetch(PDO::FETCH_ASSOC);
                $sel->closeCursor();
                $ext = preg_match('/^[A-Za-z0-9]{1,10}$/', (string)$row['extension']) ? $row['extension'] : '';
                $name = evolvit_new_upload_name($ext);
                if (file_put_contents($dir.'/'.$name, $row['image_blob']) === false) continue;
                $upd->execute(array('p' => $name, 'id' => $id));
            }
            $sel = null; $upd = null;
            $db->exec('VACUUM userfiles'); // reclaim the space of the removed blobs
        } catch (Exception $e) {
            error_log('EvolvitCMS: file migration failed: '.$e->getMessage());
        }
    }
    if (!$EVOLVIT_DB_READONLY) evolvit_migrate_files_to_disk();

    /*
     * SQLite authorizer: even a fully privileged SQL user must not be able to touch the
     * filesystem (ATTACH '/var/www/x.php' + CREATE TABLE = remote code execution).
     */
    $EVOLVIT_HAS_AUTHORIZER = false;
    if (method_exists($db, 'setAuthorizer')) {
        $db->setAuthorizer(function ($action, $a, $b, $dbname, $trigger) {
            // https://www.sqlite.org/c3ref/c_alter_table.html
            if ($action === 24 /* SQLITE_ATTACH */ || $action === 25 /* SQLITE_DETACH */) return 1; // DENY
            if ($action === 19 /* SQLITE_PRAGMA */) {
                $allowed = array('table_info', 'table_xinfo', 'index_list', 'index_info', 'foreign_key_list', 'table_list');
                return in_array(strtolower((string)$a), $allowed, true) ? 0 : 1;
            }
            if ($action === 31 /* SQLITE_FUNCTION */ && in_array(strtolower((string)$b), array('load_extension', 'readfile', 'writefile', 'fts3_tokenizer'), true)) return 1;
            return 0; // OK
        });
        $EVOLVIT_HAS_AUTHORIZER = true;
    }

    /** Returns an error string when SQL text contains statements that could escape the database. */
    function evolvit_dangerous_sql($sql) {
        global $EVOLVIT_HAS_AUTHORIZER;
        if (preg_match('/\b(vacuum|load_extension)\b/i', $sql)) return 'VACUUM / load_extension are not allowed';
        if (!$EVOLVIT_HAS_AUTHORIZER) {
            if (preg_match('/\b(attach|detach)\b/i', $sql)) return 'ATTACH / DETACH are not allowed';
            if (preg_match_all('/\bpragma\s+(?:\w+\s*\.\s*)?(\w+)\s*(=|\()?/i', $sql, $m, PREG_SET_ORDER)) {
                foreach ($m as $p) {
                    $isRead = in_array(strtolower($p[1]), array('table_info', 'table_xinfo', 'index_list', 'index_info', 'foreign_key_list', 'table_list'), true);
                    if (!$isRead) return 'PRAGMA '.$p[1].' is not allowed';
                }
            }
        }
        return false;
    }

    $coll = null;
    if(function_exists('collator_create')){
        $coll = collator_create( 'sr_Latn_RS' ); // en_US
    }

    function SR_LATIN_CMP($s1, $s2){
        global $coll;
        if (is_null($coll))
            return strcmp($s1, $s2);
        else
            return collator_compare( $coll, $s1, $s2 );
    }

    function explode_by_index($string, $index){
        $arr = explode(",", (string)$string);
        return isset($arr[$index]) ? $arr[$index] : null;
    }

    function password_hash2($pass){
      return password_hash((string)$pass, PASSWORD_BCRYPT);
    }

    function remove_extra_params($sql, $in_params){
        $res = array();
        preg_match_all("/\:(\w+)/", $sql, $matches);
        foreach($matches[1] as $keyname) $res[$keyname] = isset($in_params[$keyname])?$in_params[$keyname]:'';
        return $res;
    }
    // json
    function apiJson($sqlstring, $params=array()){
        echo json_encode( api($sqlstring, $params) );
        return;
    }
    // for php
    function fetch($sqlstring, $params=array()){
        return api($sqlstring, $params, true);
    }

    /*
     * Stored queries may contain `:name` / :`name` placeholders which are spliced into the SQL
     * text (used for table / column names, which cannot be bound). Only plain identifiers are
     * accepted; anything else is SQL injection.
     */
    function evolvit_splice_identifiers($sqlstring, $params, &$error){
        $error = false;
        $cb = function ($m) use ($params, &$error) {
            $v = isset($params[$m[2]]) ? $params[$m[2]] : '';
            if (!evolvit_is_identifier($v)) { $error = 'Invalid identifier for '.$m[2]; return $m[0]; }
            return $m[1] === '`' ? '`'.$v.'`' : $v;
        };
        // `:param`  -> `value`
        $sqlstring = preg_replace_callback('/(`):(\w+)`/', $cb, $sqlstring);
        // :`param`  -> value
        $sqlstring = preg_replace_callback('/:()`(\w+)`/', $cb, $sqlstring);
        return $sqlstring;
    }

    function evolvit_role($params){
        return isset($params['_session_user_role']) ? (int)$params['_session_user_role'] : 1;
    }

    // Write access to these stored queries changes the schema / generated SQL: system admin only.
    $EVOLVIT_SA_ONLY_WRITES = array('_engine_schema', '_engine_schema_column', '_recreate_user_table', '_sql', '_generate_sql', '_table', '_table_field');
    $EVOLVIT_SQL_TYPES = array('', 'TEXT', 'INTEGER', 'REAL', 'NUMERIC', 'BLOB');
    $EVOLVIT_COLLATIONS = array('', 'BINARY', 'NOCASE', 'RTRIM', 'NATURAL_CMP', 'SR_LATIN_CMP');

    /** Extra authorization / validation for stored queries. Returns an error string or false. */
    function evolvit_check_write($name, $action, &$params){
        global $db, $EVOLVIT_SA_ONLY_WRITES, $EVOLVIT_SQL_TYPES, $EVOLVIT_COLLATIONS;
        $role = evolvit_role($params);
        $isWrite = in_array($action, array('insert', 'update', 'delete'), true);
        if (!$isWrite) return false;

        if (in_array($name, $EVOLVIT_SA_ONLY_WRITES, true) && $role !== 2) return 'No privilegies';

        if ($name === '_engine_schema') {
            if (!evolvit_is_identifier(isset($params['tablename']) ? $params['tablename'] : '')) return 'Invalid table name';
            if (substr($params['tablename'], 0, 1) === '_' || strtolower(substr($params['tablename'], 0, 7)) === 'sqlite_') return 'Table names may not start with _ or sqlite_';
            if ($action === 'update') {
                $sf = isset($params['sort_field']) ? (string)$params['sort_field'] : '';
                if ($sf !== '' && !evolvit_is_identifier($sf)) return 'Invalid sort field';
                $sc = isset($params['sort_collate']) ? (string)$params['sort_collate'] : '';
                if (!in_array(strtoupper($sc), $EVOLVIT_COLLATIONS, true)) return 'Invalid collation';
            }
        }
        if ($name === '_engine_schema_column') {
            foreach (array('table_name', 'field_name') as $k)
                if (!evolvit_is_identifier(isset($params[$k]) ? $params[$k] : '')) return 'Invalid '.$k;
            foreach (array('lookup_table', 'lookup_field') as $k) {
                $v = isset($params[$k]) ? (string)$params[$k] : '';
                if ($v !== '' && !evolvit_is_identifier($v)) return 'Invalid '.$k;
            }
            if (isset($params['sqlite_field_type']) && !in_array(strtoupper((string)$params['sqlite_field_type']), $EVOLVIT_SQL_TYPES, true)) return 'Invalid column type';
        }

        if ($name === '_user' || $name === 'has_users') {
            $passKey = $name === 'has_users' ? 'password' : 'user_pass';
            $pass = isset($params[$passKey]) ? (string)$params[$passKey] : '';
            if ($action === 'insert' && strlen($pass) < 6) return 'Password must have at least 6 characters';
            if ($action === 'update' && $pass !== '' && strlen($pass) < 6) return 'Password must have at least 6 characters';
        }

        if ($name === '_user') {
            $me = isset($params['_session_user_name']) ? (string)$params['_session_user_name'] : '';
            if ($me === '' || $role <= 1) return 'No privilegies';
            $target = isset($params['user_name']) ? (string)$params['user_name'] : '';
            if ($target === '') return 'User name is required';
            if ($action !== 'delete') {
                $newRole = isset($params['user_role']) ? (int)$params['user_role'] : 0;
                $st = $db->prepare('select count(*) from _role where id = :id and id > 1');
                $st->execute(array('id' => $newRole));
                if (!$st->fetchColumn()) return 'Invalid role';
                if ($newRole < $role) return 'You can not grant a role higher than your own';
            }
            if ($action !== 'insert') {
                $st = $db->prepare('select user_role from _user where user_name = :u');
                $st->execute(array('u' => $target));
                $cur = $st->fetchColumn();
                if ($cur === false) return 'User not found';
                if ($target === $me) {
                    if ($action === 'delete') return 'You can not delete yourself';
                    if ($newRole !== (int)$cur) return 'You can not change your own role';
                } elseif ((int)$cur <= $role) {
                    return 'No privilegies';
                }
            }
        }
        return false;
    }

    /** Native replacement for the former eval()-based `_recreate_user_table` stored query. */
    function evolvit_recreate_user_table($tablename){
        global $db;
        if (!evolvit_is_identifier($tablename)) return array('error' => 'Invalid table name');
        $st = $db->prepare('select count(*) from _table where name = :t');
        $st->execute(array('t' => $tablename));
        if (!$st->fetchColumn()) return array('error' => 'Unknown table');

        $old = array();
        foreach ($db->query('PRAGMA table_info("'.$tablename.'")')->fetchAll(PDO::FETCH_ASSOC) as $r) $old[] = $r['name'];

        $st = $db->prepare("select field_name, sqlite_field_type from _table_field where table_name = :t
            and field_name not in ( select field_name || '_label' from _table_field where table_name = :t and field_type = 'DROPDOWN' )
            and field_type <> 'CHILD'
            order by field_sort");
        $st->execute(array('t' => $tablename));
        $defs = array(); $copy = array();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            if (!evolvit_is_identifier($f['field_name'])) return array('error' => 'Invalid column name '.$f['field_name']);
            if ($f['field_name'] === 'id') $type = 'INTEGER  PRIMARY KEY';
            else {
                $type = strtoupper((string)$f['sqlite_field_type']);
                if (!in_array($type, array('', 'TEXT', 'INTEGER', 'REAL', 'NUMERIC', 'BLOB'), true)) return array('error' => 'Invalid column type');
            }
            $defs[] = '`'.$f['field_name'].'` '.$type;
            if (in_array($f['field_name'], $old, true)) $copy[] = '"'.$f['field_name'].'"';
        }
        if (!$defs) return array('error' => 'No columns');

        $db->beginTransaction();
        try {
            $db->exec('DROP TABLE IF EXISTS _backup');
            $db->exec('CREATE TABLE _backup AS SELECT * FROM "'.$tablename.'"');
            $db->exec('DROP TABLE "'.$tablename.'"');
            $db->exec('CREATE TABLE `'.$tablename.'` ( '.implode(',', $defs).' )');
            if ($copy) $db->exec('INSERT INTO "'.$tablename.'" ('.implode(',', $copy).') SELECT '.implode(',', $copy).' FROM _backup');
            $db->exec('DROP TABLE _backup');
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        return array(array('msg' => 1));
    }

    function api($sqlstring, $params=array(), $safe=false){
        try {
            return api_unsafe($sqlstring, $params, $safe);
        } catch (Exception $e) {
            global $db;
            if ($db->inTransaction()) $db->rollBack();
            error_log('EvolvitCMS SQL error: '.$e->getMessage());
            // system admins get the real message (they write SQL); everyone else a generic one
            if (EVOLVIT_DEBUG || evolvit_role($params) === 2) return array('error' => $e->getMessage());
            return array('error' => 'Database error');
        }
    }

    function api_unsafe($sqlstring, $params=array(), $safe=false){
        global $db;
        if (!is_string($sqlstring)) $sqlstring = '';
        if (!is_array($params)) $params = array();
        $orgsqlstring = $sqlstring;
        $action = isset($params['_action'])?(string)$params['_action']:'';
        $is_multiple = true; // $is_multiple results, not queries

        // copy keys from SESSION to params (session values always win over request values)
        if ( session_status() == PHP_SESSION_ACTIVE  )
          foreach ($_SESSION as $key => $value) { if (is_string($value) || is_int($value)) $params[$key] = (string)$value; }
        if (!isset($params['_session_user_role'])) $params['_session_user_role'] = 1;

        if ($orgsqlstring == 'get_usec') {
          $t = gettimeofday();
          $usec = ($t['sec'] ) . str_pad($t['usec'], 6, "0", STR_PAD_LEFT) ;
          return array('usec'=> $usec);
        }

        $wc = str_word_count($sqlstring,0, '1234567890:@&_');
        if ($wc==0) {
            return array();
        }
        // free-form SQL: only from PHP/twig code ($safe) or from a logged in system administrator
        if ( !$safe && $wc>1 && evolvit_role($params) !== 2 ){
          return array('message'=>'Custom queryes from outer world are disabled');
        }
        if ($wc==1) {
            $stmt = $db->prepare("
            select case :_action
            when '' then  sql_select
            when 'select' then  sql_select
            when 'insert' then sql_insert
            when 'update' then sql_update
            when 'delete' then sql_delete
            else '' end sql_select
            , read_role
            , write_role
            , is_multiple
            , case when :_action in ('', 'select') then
            case  when ( read_role & cast(:_session_user_role as integer) ) = cast(:_session_user_role  as integer) then 'true' else 'false' end
            else
            case  when ( write_role & cast(:_session_user_role as integer) ) = cast(:_session_user_role  as integer) then 'true' else 'false' end
            end as can_exec
            , eval
            from _sql
            where sql_name = :sql_name");

            $stmt->execute( array(
                'sql_name' => $sqlstring,
                '_action' => $action,
                '_session_user_role' => evolvit_role($params) > 0 ? evolvit_role($params) : 1
            ) );

            $respp = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($respp)<1) {
                return array('error'=>"query missing!");
            }
            if ($respp[0]['can_exec']=='false') {
                return array('error'=>"No privilegies");
            }
            $err = evolvit_check_write($orgsqlstring, $action, $params);
            if ($err) return array('error' => $err);

            // Executing PHP code stored in the database was removed (remote code execution).
            if ($respp[0]['eval']==='true') {
                if ($orgsqlstring === '_recreate_user_table')
                    return evolvit_recreate_user_table(isset($params['tablename']) ? $params['tablename'] : '');
                return array('error' => 'eval queries are disabled');
            }

            $sqlstring = $respp[0]['sql_select'];
            $is_multiple = $respp[0]['is_multiple']==='false'?false:true;

            // `_user` update with an empty password keeps the existing password
            if ($orgsqlstring === '_user' && $action === 'update' && (!isset($params['user_pass']) || $params['user_pass'] === '')) {
                $sqlstring = 'update `_user` set `user_role` = :user_role where `user_name` = :user_name';
            }

            $err = false;
            $sqlstring = evolvit_splice_identifiers($sqlstring, $params, $err);
            if ($err) return array('error' => $err);
        }

        if (empty($sqlstring)) return array('error'=>"Query missing");
        $bad = evolvit_dangerous_sql($sqlstring);
        if ($bad) return array('error' => $bad);
        $sqls = explode(';', $sqlstring);

        // deleting a file record also removes the stored file
        $unlink = array();
        if ($orgsqlstring === '_files' && $action === 'delete') {
            $f = evolvit_find_file(isset($params['id']) ? $params['id'] : '');
            if ($f && !empty($f['file_path'])) $unlink[] = $f['file_path'];
        }

        $resp = array();
        foreach( $sqls as $sql )
            if ( trim($sql)!=false ){
                $stmt = $db->prepare($sql);
                $stmt->execute( remove_extra_params($sql, $params) );
                $resp = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

        foreach ($unlink as $fp) {
            $st = $db->prepare('select count(*) from _files where file_path = :p');
            $st->execute(array('p' => $fp));
            $path = evolvit_upload_path($fp);
            if (!$st->fetchColumn() && $path && is_file($path)) @unlink($path);
        }

        // recreate table, only for _engine_schema_column update and delete (sqlite dont support drop column)
        if ($orgsqlstring=='_engine_schema_column' && ($action=='insert' || $action=='update' || $action=='delete') ) {
            $params['_action'] = '';
            $params['tablename'] = isset($params['table_name']) ? $params['table_name'] : '';
            return api_unsafe('_recreate_user_table', $params);
        }

        if ($is_multiple) {
            return nest_array($resp); // response only from last query if multiple
        } else {
            return count($resp)>0 ? nest_array($resp)[0] : new stdClass() ; // response only from last query if multiple
        }
    }

    function fetch_twig_templates(){
        global $db;
        $data = $db->query('select name as id, template from Twig')->fetchAll(PDO::FETCH_KEY_PAIR);
        return $data;
    }

    function evolvit_find_file($id){
        global $db;
        $stmt = $db->prepare("SELECT id, file_path, image_blob, mime_type, nice_url, extension, ver from _files where id = :id
        union all SELECT id, file_path, image_blob, mime_type, nice_url, extension, ver from _files where (nice_url ||  case when ver=0 then '' else '_'||ver end  || '.' ||  extension) = :id limit 1");
        $stmt->execute(array('id' => (string)$id));
        $file = $stmt->fetch(PDO::FETCH_ASSOC);
        return $file ? $file : false;
    }

    function evolvit_file_contents($file){
        if (!empty($file['file_path'])) {
            $path = evolvit_upload_path($file['file_path']);
            return ($path && is_file($path)) ? file_get_contents($path) : false;
        }
        return $file['image_blob'] !== null ? $file['image_blob'] : false; // not migrated yet
    }

    function get_image_blob($id){
      global $db;
      $file = evolvit_find_file($id);
      if (!$file) return false;
      $blob = evolvit_file_contents($file);
      if ($blob === false) return false;
      return array('blob' => $blob, 'mime_type' => $file['mime_type']);
    }

    // mime types that are safe to render inline from our origin
    function evolvit_inline_mime($mime){
        $mime = strtolower(trim(explode(';', (string)$mime)[0]));
        $safe = array('image/png', 'image/jpeg', 'image/pjpeg', 'image/gif', 'image/webp', 'image/avif', 'image/bmp',
            'image/x-icon', 'image/vnd.microsoft.icon', 'image/svg+xml', 'application/pdf', 'text/plain');
        if (in_array($mime, $safe, true)) return true;
        $top = explode('/', $mime)[0];
        return $top === 'video' || $top === 'audio';
    }

    /** Headers that stop uploaded files from being interpreted as HTML/JS on our origin. */
    function evolvit_send_file_headers($mime, $filename = null){
        $mime = (string)$mime;
        if (!preg_match('#^[\w.+-]+/[\w.+-]+$#', $mime)) $mime = 'application/octet-stream';
        header('Content-Type: '.$mime);
        header('X-Content-Type-Options: nosniff');
        if (strtolower($mime) !== 'application/pdf')
            header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; sandbox");
        if (!evolvit_inline_mime($mime))
            header('Content-Disposition: attachment'.($filename ? '; filename="'.preg_replace('/[^\w.-]/', '_', $filename).'"' : ''));
    }

    function print_image($id){
        // redirect if param is an absolute url (external images stored in image fields).
        // Only honoured for <img> loads so it can not be abused as an open redirect for phishing.
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $p = false;
        foreach (array(strpos($uri, 'http://'), strpos($uri, 'https://')) as $pos) if ($pos !== false && ($p === false || $pos < $p)) $p = $pos;
        if ($p !== false) {
            $target = preg_replace('/&forcethumb$/', '', substr($uri, $p));
            $dest = isset($_SERVER['HTTP_SEC_FETCH_DEST']) ? $_SERVER['HTTP_SEC_FETCH_DEST'] : '';
            $isImgLoad = $dest === 'image' || ($dest === '' && isset($_GET['forcethumb']));
            if ($isImgLoad && filter_var($target, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $target)) {
                header('Location: ' . $target);
            } else {
                http_response_code(400);
            }
            return;
        }

        // else get image from DB
        $found = evolvit_find_file($id);
        $file = $found ? array($found) : array();
        if (count($file)<1) {
            header("Content-type: image/png");
            readfile(dirname(__FILE__).'/images/noimage.png');
            return;
        }
        $ftypes = array(
              'application/vnd.ms-excel'=>'excel.png'
            , 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'excel.png'
            , 'application/vnd.oasis.opendocument.spreadsheet'=>'excel.png'
            , 'application/msword'=>'word.png'
            , 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' =>'word.png'
            , 'application/vnd.oasis.opendocument.text' =>'word.png'
            , 'application/pdf'=>'pdf.png'

            , 'video/x-msvideo'=>'video.png'
            , 'video/mpeg'=>'video.png'
            , 'video/3gpp'=>'video.png'
            , 'audio/3gpp'=>'video.png'
            , 'video/3gpp2'=>'video.png'
            , 'audio/3gpp2'=>'video.png'
            , 'audio/wav' =>'video.png'
            , 'audio/webm' =>'video.png'
            , 'video/webm' =>'video.png'
            , 'audio/ogg' =>'video.png'
            , 'video/ogg' =>'video.png'
            , 'application/ogg' =>'video.png'
            , 'audio/midi' =>'video.png'
            , 'audio/x-midi' =>'video.png'
            , 'audio/aac' =>'video.png'

            , 'text/plain'=>'text.png'

            , 'application/x-rar-compressed'=>'zip.png'
            , 'application/x-7z-compressed'=>'zip.png'
            , 'application/zip'=>'zip.png'
            , 'application/java-archive'=>'zip.png'
            , 'application/epub+zip'=>'zip.png'
            , 'application/x-bzip'=>'zip.png'
            , 'application/x-bzip2'=>'zip.png'
        );
        if (isset($_GET['forcethumb']) && isset($ftypes[ $file[0]['mime_type'] ])){
            header("Content-type: image/png");
            readfile(dirname(__FILE__).'/images/'. $ftypes[ $file[0]['mime_type'] ] );
            return;
        }

        // any file with mime type != 'image/*'
        $m = explode('/', (string)$file[0]['mime_type']);
        if (isset($_GET['forcethumb']) &&  (reset($m) != 'image')  ){
            header("Content-type: image/png");
            readfile(dirname(__FILE__).'/images/unknown.png' );
            return;
        }

        header('Pragma: public');
        header('Cache-Control: max-age=86400');
        header('Expires: '. gmdate('D, d M Y H:i:s \G\M\T', time() + 86400));
        $fname = $file[0]['nice_url'].(($file[0]['ver']==0)?'':'_'.$file[0]['ver']).'.'.$file[0]['extension'];
        $path = !empty($file[0]['file_path']) ? evolvit_upload_path($file[0]['file_path']) : null;
        if ($path && is_file($path)) {
            evolvit_send_file_headers($file[0]['mime_type'], $fname);
            header('Content-Length: '.filesize($path));
            readfile($path);
        } elseif (empty($file[0]['file_path']) && $file[0]['image_blob'] !== null) {
            evolvit_send_file_headers($file[0]['mime_type'], $fname);
            echo $file[0]['image_blob'];
        } else {
            http_response_code(404);
        }
    }

    // Uploading server-side executable / active content is never allowed.
    function evolvit_upload_extension_allowed($ext){
        $ext = strtolower($ext);
        $blocked = array('php', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phtml', 'phar', 'phps', 'inc',
            'cgi', 'pl', 'py', 'sh', 'bash', 'asp', 'aspx', 'jsp', 'htaccess', 'htpasswd', 'ini', 'shtml',
            'html', 'htm', 'xhtml', 'xht', 'js', 'mjs', 'exe', 'dll', 'bat', 'cmd', 'com', 'scr', 'msi', 'hta');
        return $ext !== '' && preg_match('/^[a-z0-9]{1,10}$/', $ext) && !in_array($ext, $blocked, true);
    }

    function upload2sqlite($uploaded_file, $selectedfolder = null){ return evolvit_upload_file($uploaded_file, $selectedfolder); }

    function evolvit_upload_file($uploaded_file, $selectedfolder = null){
        // [uploaded_file] => Array ( [name] => bzvz (1).jpg [type] => image/jpeg [tmp_name] => /tmp/phpvZ85y1 [error] => 0 [size] => 23814
        global $db;

        if (!is_array($uploaded_file) || !isset($uploaded_file['error']) || is_array($uploaded_file['error'])
            || $uploaded_file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($uploaded_file['tmp_name'])) {
            http_response_code(400);
            echo 'Upload failed';
            return;
        }

        $path_parts = pathinfo($uploaded_file['name']);
        $extension = isset( $path_parts['extension'] ) ? strtolower($path_parts['extension'])  : '';
        $filename = isset( $path_parts['filename'] ) ? $path_parts['filename'] : ''; // Without extension
        if (!evolvit_upload_extension_allowed($extension)) {
            http_response_code(415);
            echo 'File type not allowed';
            return;
        }
        $slug = makeSlugs($filename);// Without extension
        if ($slug === '' || $slug === null) $slug = 'file';

        // detect the real type instead of trusting the browser supplied one
        $mime = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $detected = finfo_file($fi, $uploaded_file['tmp_name']);
            if ($detected) $mime = $detected;
        }
        if ($mime === 'text/plain' && $extension === 'svg') $mime = 'image/svg+xml';
        if ($mime === 'image/svg' ) $mime = 'image/svg+xml';

        if (defined('EVOLVIT_UPLOADS_QUOTA_BYTES') && EVOLVIT_UPLOADS_QUOTA_BYTES > 0) {
            $used = 0;
            foreach (glob(evolvit_uploads_dir().'/*') ?: array() as $f) $used += (int)@filesize($f);
            if ($used + (int)$uploaded_file['size'] > EVOLVIT_UPLOADS_QUOTA_BYTES) {
                http_response_code(413);
                echo 'Upload quota exceeded';
                return;
            }
        }

        $folder = ($selectedfolder === null || $selectedfolder === '' || !is_numeric($selectedfolder)) ? null : (int)$selectedfolder;

        $stmt = $db->prepare("select ifnull( max(ver), -1) + 1 as ver from _files ff where ff.nice_url = :slug and ff.extension = :extension");
        $stmt->execute( array( 'extension' => $extension, 'slug' => $slug ) );
        $ver = $stmt->fetchColumn();

        $dir = evolvit_uploads_dir();
        $stored = evolvit_new_upload_name($extension);
        if (!move_uploaded_file($uploaded_file['tmp_name'], $dir.'/'.$stored)) {
            http_response_code(500);
            echo 'Could not store file';
            return;
        }
        @chmod($dir.'/'.$stored, 0644);

        $query = $db->prepare("INSERT INTO _files (image_blob, file_path, mime_type, name, nice_url, size, extension, ver, folder_id) VALUES (NULL, :file_path, :mime_type, :name, :slug, :size, :extension, :ver, :folder_id)");
        $query->bindValue(':file_path',  $stored, PDO::PARAM_STR);
        $query->bindValue(':mime_type',  $mime, PDO::PARAM_STR);
        $query->bindValue(':name',       $filename, PDO::PARAM_STR);
        $query->bindValue(':slug',       $slug, PDO::PARAM_STR);
        $query->bindValue(':size',       (int)$uploaded_file['size'], PDO::PARAM_INT);
        $query->bindValue(':extension',  $extension, PDO::PARAM_STR);
        $query->bindValue(':ver',        $ver, PDO::PARAM_INT);
        $query->bindValue(':folder_id',  $folder, $folder === null ? PDO::PARAM_NULL : PDO::PARAM_INT);

        try {
            $query->execute();
        } catch (Exception $e) {
            @unlink($dir.'/'.$stored);
            throw $e;
        }
        echo htmlspecialchars($slug.(($ver==0)?'':'_'.$ver).'.'.$extension);
    }

    function nest_array($arr){
        $any_col_has_dot_in_name = false;
        if (count($arr)>0) {
            $fieldsArr = array_keys($arr[0]);
            foreach($fieldsArr as $rk => $rv) {
                if ( strpos($rv, '.')!== false ) {
                    $any_col_has_dot_in_name = true;
                    break;
                }
            }
        }

        if (!$any_col_has_dot_in_name) return convert_str_array_cols_to_array($arr); //->fetchAll(PDO::FETCH_ASSOC);

        // Nesting starts here
        $res = array(); $last_col_id = -1; 
        foreach($arr as $rowkey => $rowvalue) {

            $add_new = false;
            if ($last_col_id !== $rowvalue['id']) {
                $last_col_id = $rowvalue['id'];
                $add_new = true;
            }

            $tmp_nest_obj = array();
            $nest_key_name = '';
            $are_all_nest_values_not_null = false;
            foreach($rowvalue as $colkey => $colvalue) {
                //print_r( $rowvalue ); 
                $dot_pos = strpos($colkey, '.');
                if ($dot_pos !== false) {
                    $are_all_nest_values_not_null = $are_all_nest_values_not_null || !is_null($colvalue);
                    $tmp_nest_obj[ substr($colkey, $dot_pos+1 ) ] = $colvalue;
                    unset( $rowvalue[$colkey] );
                    $nest_key_name = substr($colkey, 0, $dot_pos);
                }
            }    

            if ($add_new) {
                $rowvalue[$nest_key_name] = array();
                if ($are_all_nest_values_not_null) $rowvalue[$nest_key_name][] = $tmp_nest_obj;
                $res[] = $rowvalue;
            }
            else {
                $res[ count($res)-1 ][$nest_key_name][] = $tmp_nest_obj;
            }
        }
        return $res;
    }

    function convert_str_array_cols_to_array($arr){
      $ends_with_ = array();
      if (count($arr)>0) {
          $fieldsArr = array_keys($arr[0]);
          foreach($fieldsArr as $rk => $rv) {
//            if ( strpos($rv, '.')!== false ) {
            if ( substr_compare($rv, '_', -1) === 0 ) { // endsWith
                $ends_with_[] = $rv;
              }
          }
      }
      if (count($ends_with_)==0) return $arr; //->fetchAll(PDO::FETCH_ASSOC);

      // replace all columns named something_ ; values from string to json_decode(string, array=true)
      foreach($arr as $rowkey => $rowvalue) {
        foreach($ends_with_ as $colkey => $colname) {
          $arr[$rowkey][$colname] = json_decode($rowvalue[$colname], true);
        }    
      }
      return $arr;
  }    
?>