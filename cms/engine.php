<?php
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    include(dirname(__FILE__).'/slug.php'); 

    try{
        $db = new PDO('sqlite:'.dirname(__FILE__).'/db/main.sqlite');
        $db->exec("ATTACH DATABASE '".dirname(__FILE__).'/db/userfiles.sqlite'."' AS userfiles");
    } 
    catch(PDOException $e) { 
        die('Unable to open database connection'); 
    } 
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // helper functions for splitting comma separated string for old sqlite (without with statement)
    $db->sqliteCreateFunction('substr_count', 'substr_count', 2); // or createFunction if type is Sqlite3 or SQLiteDatabase
    $db->sqliteCreateFunction('password_hash', 'password_hash2', 1); 
    $db->sqliteCreateFunction('password_verify', 'password_verify', 2); 
    $db->sqliteCreateFunction('explode_by_index', 'explode_by_index', 2);
    $db->sqliteCreateFunction('makeSlugs', 'makeSlugs', 1);
    
    $db->sqliteCreateCollation('NATURAL_CMP', 'strnatcmp');  // !!! UNDOCUMENTED PDO FUNCTION
    $db->sqliteCreateCollation('SR_LATIN_CMP', 'SR_LATIN_CMP');  // !!! UNDOCUMENTED PDO FUNCTION

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
        $arr = explode(",", $string);
        return $arr[$index];
    }
    
    function password_hash2($pass){
      return password_hash($pass, PASSWORD_BCRYPT);
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
        return api($sqlstring, $params);        
    }
    function api($sqlstring, $params=array()){
        global $db;
        $orgsqlstring = $sqlstring;
        $action = isset($params['_action'])?$params['_action']:'';
        $is_multiple = true; // $is_multiple results, not queries
        $is_eval = false;
        //print_r($orgsqlstring);
        //print_r($sqlstring);
        //print_r($params);
        if (!isset($params['_session_user_role'])) $params['_session_user_role'] = 1;
        
        //$params = array_replace($params, $_SESSION); // remove keys containing array 
        // copy keys from SESSION to params
        if ( session_status() == PHP_SESSION_ACTIVE  )
          foreach ($_SESSION as $key => $value) { if (is_string($value)) $params[$key] = $value; }

        if ($orgsqlstring == 'get_usec') {
          $t = gettimeofday();
//          $usec = ($t['sec'] - 1543000000) . str_pad($t['usec'], 6, "0", STR_PAD_LEFT) ;
          $usec = ($t['sec'] ) . str_pad($t['usec'], 6, "0", STR_PAD_LEFT) ;
          return array('usec'=> $usec);        
        }
        
        $wc = str_word_count($sqlstring,0, '1234567890:@&_');
        if ($wc==0) {
            return array();
            //echo json_encode(array()); // todo
            //return;
        }
        if ( $wc>1 && session_status() == PHP_SESSION_ACTIVE && $params['_session_user_role'] != 2 ){
          // only api.php starts session  
          return array('message'=>'Custom queryes from outer word are disabled');
        }        
        if ($wc==1) {
//            $stmt = $db->prepare(" select sql_select, is_multiple from _sql where sql_name = :sql_name");
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
            , :_session_user_role as _session_user_role
            , :_action as _action
            , eval
            from _sql 
            where sql_name = :sql_name");

            //$stmt->execute( array('sql_name' => $sqlstring) );
            $stmt->execute( array(
                'sql_name' => $sqlstring,
                '_action' => $action,
                '_session_user_role' => isset($params['_session_user_role'])?$params['_session_user_role']:'1'
            ) );

            //            $sqlstring = $stmt->fetchColumn();    
            $respp = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($respp)<1) {
                //echo json_encode(array('error'=>"query $sqlstring missing!"));
                //return;
                return array('error'=>"query $sqlstring missing!");
            }   
            if ($respp[0]['can_exec']=='false') {
                //echo json_encode(array('error'=>"query $sqlstring missing!"));
                //return;
                //print_r ($respp[0]);
                return array('error'=>"No privilegies");
            }   
            $sqlstring = $respp[0]['sql_select'];
            $is_multiple = $respp[0]['is_multiple']==='false'?false:true;

            // only for superuser
            // replace `:param` with `value`
            $patterns = array_keys($params);
            foreach ($patterns as $key => $value) { $patterns[$key] = '/`:\b'.$value.'\b`/i'; }
            $replacements = array_values($params);
            foreach ($replacements as $key => $value) {  $replacements[$key] = '`'.$value.'`'; }
            $sqlstring = preg_replace($patterns,$replacements,$sqlstring);            

            // replace :`param` with value
            $patterns = array_keys($params);
            foreach ($patterns as $key => $value) {  $patterns[$key] = '/:`\b'.$value.'\b`/i'; }
            $replacements = array_values($params);
            $sqlstring = preg_replace($patterns,$replacements,$sqlstring);            
            //echo json_encode(array('s'=>$sqlstring)); // todo
            //return;            

            $is_eval = $respp[0]['eval']==='true'?true:false;

            if ($is_eval){
                $resp = eval($sqlstring);
                if ($is_multiple) 
                    return nest_array($resp);
                else 
                    return count($resp)>0 ? nest_array($resp)[0] : new stdClass() ;      
            }

        }

        if (empty($sqlstring)) return array('error'=>"Query missing");
        $sqls = explode(';', $sqlstring);
        
        foreach( $sqls as $sql )
            if ( trim($sql)!=false ){
                //echo $sql;
                $stmt = $db->prepare($sql);
                $stmt->execute( remove_extra_params($sql, $params) );
                $resp = $stmt->fetchAll(PDO::FETCH_ASSOC);  
            }

        // recreate table, only for _engine_schema_column update and delete (sqlite dont support drop column)
        if ($orgsqlstring=='_engine_schema_column' && ($action=='insert' || $action=='update' || $action=='delete') ) {
            $params['_action'] = '';
            $params['tablename'] = $params['table_name'];
            //echo('calling recr with');
            //print_r($params);
            return api('_recreate_user_table', $params);
        }

        if ($is_multiple) {
//            echo json_encode(nest_array($resp)); // response only from last query if multiple
            return nest_array($resp); // response only from last query if multiple
        } else {
//            echo json_encode( count($resp)>0 ? $resp[0] : array() , JSON_FORCE_OBJECT ); // response only from last query if multiple
            return count($resp)>0 ? nest_array($resp)[0] : new stdClass() ; // response only from last query if multiple
        }
    }

    function fetch_twig_templates(){
        global $db;
        $data = $db->query('select name as id, template from Twig')->fetchAll(PDO::FETCH_KEY_PAIR);
        return $data;
    }
    
    function print_image($id){
        // redirect if param is absolute url
        $v1 = strpos($_SERVER['REQUEST_URI'], 'http://'); 
        $v2 = strpos($_SERVER['REQUEST_URI'], 'https://'); 
        if ( $v1!==false || $v2!==false ) {
            if ($v1!==false) {
                header('Location: ' . rtrim(substr( $_SERVER['REQUEST_URI'], $v1), '&forcethumb') );
                return;
            }
            if ($v2!==false) {
                header('Location: ' . rtrim(substr( $_SERVER['REQUEST_URI'], $v2), '&forcethumb') );
                return;
            }
        } 

        // else get image from DB 
        global $db;
        $stmt = $db->prepare("SELECT image_blob, mime_type from _files where id = :id 
        union all SELECT image_blob, mime_type from _files where (nice_url ||  case when ver=0 then '' else '_'||ver end  || '.' ||  extension) = :id limit 1");
        $stmt->execute(array('id' => $id));
        $file = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($file)<1) { 
            //echo 'no image';
            header("Content-type: image/png");
            readfile(dirname(__FILE__).'/images/noimage.png');            
            //http_response_code(404); 
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
            readfile(dirname(__FILE__).'/images//'. $ftypes[ $file[0]['mime_type'] ] );             
            return;
        }

        // any file with mime type != 'image/*'
        $m = explode('/', $file[0]['mime_type']);
        if (isset($_GET['forcethumb']) &&  (reset($m) != 'image')  ){
            header("Content-type: image/png");
            readfile(dirname(__FILE__).'/images/unknown.png' );             
            return;
        }
                
        //$ftype = strtolower(end(explode('/', $file[0]['mime_type'])));
        //if (in_array( array('pdf'), $ftype))
        header('Pragma: public');
        header('Cache-Control: max-age=86400');
        header('Expires: '. gmdate('D, d M Y H:i:s \G\M\T', time() + 86400));
        header('Content-type:'.$file[0]['mime_type']);
        echo $file[0]['image_blob'];
    }

    function upload2sqlite($uploaded_file, $selectedfolder){
        // [uploaded_file] => Array ( [name] => bzvz (1).jpg [type] => image/jpeg [tmp_name] => /tmp/phpvZ85y1 [error] => 0 [size] => 23814
        global $db;

        // TODO: check if upload error

        $path_parts = pathinfo($uploaded_file['name']);
        $extension = isset( $path_parts['extension'] ) ? $path_parts['extension']  : '';
        $filename = isset( $path_parts['filename'] ) ? $path_parts['filename'] : ''; // Without extension // Since PHP 5.2.0
        $slug = makeSlugs($filename);// Without extension 

        $stmt = $db->prepare("select ifnull( max(ver), -1) + 1 as ver from _files ff where ff.nice_url = :slug and ff.extension = :extension");
        $stmt->execute( array( 'extension' => $extension, 'slug' => $slug ) );
        $ver = $stmt->fetchColumn();
        
        $query = $db->prepare("INSERT INTO _files (image_blob, mime_type, name, nice_url, size, extension, ver, folder_id) VALUES (:image_blob, :mime_type, :name, :slug, :size, :extension, :ver, :folder_id)");
        $query->bindValue(':image_blob', fopen($uploaded_file['tmp_name'], "rb"), PDO::PARAM_LOB);
        $query->bindParam(':mime_type',  $uploaded_file['type'], PDO::PARAM_STR);
        $query->bindParam(':name',       $filename, PDO::PARAM_STR);
        $query->bindParam(':slug',       $slug, PDO::PARAM_STR);
        $query->bindParam(':size',       $uploaded_file['size'], PDO::PARAM_INT);
        $query->bindValue(':extension',  $extension, PDO::PARAM_STR);
        $query->bindValue(':ver',        $ver, PDO::PARAM_INT);
        $query->bindValue(':folder_id',  $selectedfolder, PDO::PARAM_INT);
        
        $query->execute();
        //echo $db->lastInsertId(); 
        echo $slug.(($ver==0)?'':'_'.$ver).'.'.$extension; 
    }
    /*
    function fetch($objName){
        global $db;
        $result = $db->query('SELECT * FROM Dogs order by id')->fetchAll(PDO::FETCH_ASSOC);
        return nest_array($result);
    }

    function get_single_post($id){
        global $db;
        $stmt = $db->prepare("select post.* 
        , comment.id as \"comment.id\"
        , comment.text as \"comment.text\"
        , comment.date as \"comment.date\"
        , comment.user_name as \"comment.user_name\"
        from post
        left join comment on comment.post_id = post.id
        where post.id = :id or post.nice_url = :id");
        $stmt->execute(array('id' => $id));
        $rez = $stmt->fetchAll(PDO::FETCH_ASSOC);;
        $arr = nest_array($rez);
        //print_r( $arr );
        return count($arr)==1 ? $arr[0]:null;
    }

    function fetch2($objName, $params=null){
        global $db;
        $query = '';
        if ($objName=='post')
            $query = 'select post.* 
            , comment.id as "comment.id"
            , comment.text as "comment.text"
            , comment.date as "comment.date"
            , comment.user_name as "comment.user_name"
            from post
            left join comment on comment.post_id = post.id';

        if ($objName=='dogs')
            $query = 'SELECT dogs.*, tip.tip_boja as "tip.tip_boja"
            , tip.tip_label as "tip.tip_label" 
            from dogs 
            left join tip on tip.tip_id = dogs.tip
            union all SELECT dogs.*, tip.tip_boja as "tip.tip_boja", tip.tip_label as "tip.tip_label" from dogs left join tip on tip.tip_id = dogs.tip
            order by Id';

        if ($query == ''){
            $stmt = $db->prepare("SELECT name FROM sqlite_master where type = 'table' and name = :tablename");
            $stmt->execute(array('tablename' => $objName));
            $tablename = $stmt->fetchColumn();
            
            //echo $tablename;
           // $query = "select 'table missing' as error ";
           $query = $objName;
            if ($tablename)
                $query = "select * from $tablename ";
    
        }

        $result = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
        
        //$result = $db->query('SELECT * FROM Dogs order by id')->fetchAll(PDO::FETCH_ASSOC);
        return nest_array($result);
        //return $result;
    }
*/
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