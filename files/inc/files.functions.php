<?php
/**
 * Files API
 *
 * @package Files
 * @author Cotonti Team
 * @copyright (c) Cotonti Team 2008-2014
 */
defined('COT_CODE') or die('Wrong URL.');

// Автозагрузка
require_once './lib/Loader.php';
Loader::registerMe();

// Additional API requirements
require_once cot_incfile('uploads');
require_once './datas/extensions.php';

// Self requirements
require_once cot_langfile('files', 'module');
require_once  cot_incfile('files', 'module', 'resources');

// Global variables
global $db_files, $db_files_folders, $db_x;

$db_files           = (isset($db_files)) ? $db_files : $db_x . 'files';
$db_files_folders   = (isset($db_files_folders)) ? $db_files_folders : $db_x . 'files_folders';


/**
 * Terminates further script execution with a given
 * HTTP response status and output.
 * If the message is omitted, then it is taken from the
 * HTTP status line.
 * @param  int    $code     HTTP/1.1 status code
 * @param  string $message  Output string
 * @param  array  $response Custom response object
 */
function cot_files_ajax_die($code, $message = null, $response = null)
{
    $status = cot_files_ajax_get_status($code);
    cot_sendheaders('application/json', $status);
    if (is_null($message))
    {
        $message = substr($status, strpos($status, ' ') + 1);
    }
    if (is_null($response))
        echo json_encode($message);
    else
    {
        $response['message'] = $message;
        echo json_encode($response);
    }
    exit;
}

/**
 * Returns HTTP satus line for a given
 * HTTP response code
 * @param  int    $code HTTP response code
 * @return string       HTTP status line
 */
function cot_files_ajax_get_status($code)
{
    static $msg_status = array(
        200 => '200 OK',
        201 => '201 Created',
        204 => '204 No Content',
        205 => '205 Reset Content',
        206 => '206 Partial Content',
        300 => '300 Multiple Choices',
        301 => '301 Moved Permanently',
        302 => '302 Found',
        303 => '303 See Other',
        304 => '304 Not Modified',
        307 => '307 Temporary Redirect',
        400 => '400 Bad Request',
        401 => '401 Authorization Required',
        403 => '403 Forbidden',
        404 => '404 Not Found',
        409 => '409 Conflict',
        411 => '411 Length Required',
        500 => '500 Internal Server Error',
        501 => '501 Not Implemented',
        503 => '503 Service Unavailable',
    );
    if (isset($msg_status[$code]))
        return $msg_status[$code];
    else
        return "$code Unknown";
}


function cot_files_getUserData($uid = 0, $cacheitem = true){
    global $db_users;

    $user = false;

    if(!$uid && cot::$usr['id'] > 0){
        $uid = cot::$usr['id'];
        $user = cot::$usr['profile'];
    }
    if(!$uid) return null;

    static $u_cache = array();

    if($cacheitem && isset($u_cache[$uid])) {
        return $u_cache[$uid];
    }

    if(!$user){
        if(is_array($uid)){
            $user = $uid;
            $uid = $user['user_id'];
        }else{
            if($uid > 0 && $uid == cot::$usr['id']){
                $user = cot::$usr['profile'];
            }else{
                $uid = (int)$uid;
                if(!$uid) return null;
                $sql = cot::$db->query("SELECT * FROM $db_users WHERE user_id = ? LIMIT 1", $uid);
                $user = $sql->fetch();
            }
        }
    }

    $cacheitem && $u_cache[$uid] = $user;

    return $user;
}

/**
 * Checks if file extension is allowed for upload. Returns error message or empty string.
 * Emits error messages via cot_error().
 *
 * @param  string  $ext   File extension
 * @return boolean        true if all checks passed, false if something was wrong
 */
function cot_files_checkFile($ext)
{
    $valid_exts = explode(',', cot::$cfg['files']['exts']);
    $valid_exts = array_map('trim', $valid_exts);
    if (empty($ext) || !in_array($ext, $valid_exts))
    {
        //cot_error('att_err_type');
        return false;
    }
    return true;
}

/**
 * Returns number of attachments for a specific item.
 * @param  string $source   Target module/plugin code
 * @param  integer $item    Target item id
 * @param  string $field    Target item field
 * @param  string $type     Attachment type filter: 'files', 'images'. By default includes all attachments.
 * @return integer          Number of attachments
 */
function cot_files_count($source, $item, $field = '', $type = 'all'){

    static $a_cache = array();

    $cacheField = ($field != '') ? $field : '_empty_field_name_';
    if (!isset($a_cache[$source][$item][$cacheField][$type])){
        $cond = array(
            array('file_source', $source),
            array('file_item', $item),
        );
        if ($type == 'files'){
            $cond[] = array('file_img', 0);
        }elseif ($type == 'images'){
            $cond[] = array('file_img', 1);
        }
        if($field != '_all_') $cond[] = array('file_field', $field);

        $a_cache[$source][$item][$cacheField][$type] = files_model_File::count($cond);
    }
    return $a_cache[$source][$item][$cacheField][$type];
}

/**
 * Fetches a single attachment object for a given item.
 * @param  string $source   Target module/plugin code.
 * @param  integer $item    Target item id.
 * @param  string $field    Target item field
 * @param  string $column   Empty string to return full row, one of the following to return a single value: 'id',
 *                              'user_id', 'path', 'name', 'ext', 'img', 'size', 'title', 'count'
 * @param  string $number   Attachment number within item, or one of these values: 'first', 'rand' or 'last'. Defines which image is selected.
 * @return mixed            Scalar column value, files_model_File object or NULL if no attachments found.
 */
function cot_files_get($source, $item, $field = '', $column = '', $number = 'first'){

    static $a_cache;
    if (!isset($a_cache[$source][$item][$number]))
    {
        $order_by = $number == 'rand' ? 'RAND()' : 'file_order';
        if ($number == 'last') $order_by .= ' DESC';

        $offset = is_numeric($number) && $number > 1 ? ((int)$number - 1) . ',' : 0;

        $cond = array(
            array('file_source', $source),
            array('file_item', $item),
        );
        if($field != '_all_') $cond[] = array('file_field', $field);
        $file = files_model_File::find($cond, 1, $offset, $order_by);
        if(!$file) return null;
        $a_cache[$source][$item][$number] = $file[0];

    }
    $tmp = 'file_'.$column;
    if($column == 'user_id') $tmp = 'user_id';

    return empty($column) ? $a_cache[$source][$item][$number] : $a_cache[$source][$item][$number]->{$tmp};
}

/**
 * Extracts filename extension with tar (.tar.gz, tar.bz2, etc.) support.
 * @param  string $filename File name
 * @return string           File extension or false on error
 */
function cot_files_get_ext($filename)
{
    if (preg_match('#((\.tar)?\.\w+)$#', $filename, $m))
    {
        return mb_strtolower(mb_substr($m[1], 1));
    }
    else
    {
        return false;
    }
}

/**
 * Gets upload space limits.
 * @param int $uid
 * @param string $source
 * @param int $item
 * @param string $field
 * @return array
 */
function cot_files_getLimits($uid = 0, $source = 'pfs', $item = 0, $field = ''){
    global $db_attach, $usr, $db, $cfg, $db_groups, $db_groups_users, $db_files;

    $limits = array(
        'size_maxfile' => 0,
        'size_maxtotal' => 0,
        'size_used' => 0,
        'size_left' => 0,
        'count_max' => 0,
        'count_used'=> 0,
        'count_left'=> 0,
    );

    if(!$uid) $uid = cot::$usr['id'];
    if(!empty($uid)) $urr = cot_files_getUserData($uid);

    if($source == 'sfs'){

        // Site file space
        if(cot_auth('files', 'a', 'A')){
            $limits['size_maxfile']  = 100000000000000000;
            $limits['size_maxtotal'] = 100000000000000000;
            $limits['size_used']     = 0;
            $limits['size_left']     = 100000000000000000;
            $limits['count_max']     = 100000000000000000;
            $limits['count_used']    = 0;
            $limits['count_left']    = 100000000000000000;

            return $limits;
        }

    }

    $tmp = cot::$db->query("SELECT MAX(g.grp_pfs_maxfile) AS size_maxfile,  MAX(g.grp_pfs_maxtotal) AS size_maxtotal,
            SUM(f.file_size) as size_used, MAX(g.grp_files_perpost) as count_max
          FROM $db_groups as g
          LEFT JOIN $db_files as f ON f.file_source!='sfs' AND f.user_id={$urr['user_id']}
          WHERE g.grp_id IN ( SELECT gru_groupid FROM $db_groups_users WHERE gru_userid = {$urr['user_id']}  )")->fetch();

    $limits['size_maxfile']  = (int)$tmp['size_maxfile'];
    if($limits['size_maxfile'] == -1){
        $limits['size_maxfile'] = 0;
    }elseif($limits['size_maxfile'] == 0){
        $limits['size_maxfile']  = 100000000000000000;
    }
    // Ограничения на загрузку файлов через POST
    // пока вынесено в контроллер
//        if(cot::$cfg['files']['chunkSize'] == 0){
//            $limits['maxfile']  = min((int)$limits['maxfile'], cot_get_uploadmax() * 1024);
//        }

    $limits['size_maxtotal'] = (int)$tmp['size_maxtotal'];
    if($limits['size_maxtotal'] == -1){
        $limits['size_maxtotal'] = 0;
    }elseif($limits['size_maxtotal'] == 0){
        $limits['size_maxtotal']  = 100000000000000000;
    }

    $limits['size_used'] = (int)$tmp['size_used'];
    $limits['size_left'] = $limits['size_maxtotal'] - $limits['size_used'];
    if($limits['size_left'] < 0) $limits['size_left'] = 0;


    if($source == 'pfs'){
        // В PFS не накладывается ограничений на количество файлов, только на размеры
        $limits['count_max']     = 100000000000000000;
        $limits['count_used']    = 0;
        $limits['count_left']    = 100000000000000000;

        // Site file space
        if($uid === 0){
            $limits['size_maxfile']  = 100000000000000000;
            $limits['size_maxtotal'] = 100000000000000000;
            $limits['size_used']     = 0;
            $limits['size_left']     = 100000000000000000;
            return $limits;
        }


        return $limits;
    }

    $limits['count_max'] = (int)$tmp['count_max'];
    if($limits['count_max'] == -1){
        $limits['count_max'] = 0;
    }elseif($limits['count_max'] == 0){
        $limits['count_max']  = 100000000000000000;
    }

    $limits['count_used'] = files_model_File::count(array(
        array('file_source', $source),
        array('file_item', $item),
        array('file_field', $field),
    ));
    $limits['count_left'] = $limits['count_max'] - $limits['count_used'];

    return $limits;
}

function cot_files_isValidImageFile($file_path) {
    $ext = cot_files_get_ext($file_path);
    if(!in_array($ext, array('gif', 'jpg', 'jpeg', 'png'))) return false;

    if (function_exists('exif_imagetype')) {
        return @exif_imagetype($file_path);
    }
    $image_info = getimagesize($file_path);
    return $image_info && $image_info[0] && $image_info[1];
}

/**
 * Calculates attachment path.
 * @param  string $source Module or plugin code
 * @param  int    $item Parent item ID
 * @param  int    $id   Attachment ID
 * @param  string $ext  File extension. Leave it empty to auto-detect.
 * @param  int    $uid   User ID for pfs
 * @return string       Path for the file on disk
 */
function cot_files_path($source, $item, $id, $ext = '', $uid = 0){

    $filesPath = cot::$cfg['files']['folder'] . '/' . $source . '/' . $item;
    if($source == 'pfs'){
        if($uid == 0) $uid = cot::$usr['id'];
        $filesPath = cot::$cfg['files']['folder'] . '/' . $source . '/'. $uid. '/' . $item;
    }

    if (empty($ext))
    {
        // Auto-detect extension
        $mask = $filesPath . '/' . cot::$cfg['files']['prefix'] . $id . '.*';
        $files = glob($mask, GLOB_NOSORT);
        if (!$files || count($files) == 0)
        {
            return false;
        }
        else
        {
            return $files[0];
        }
    }
    else
    {
        return $filesPath . '/' . cot::$cfg['files']['prefix'] . $id . '.' . $ext;
    }
}

/**
 * Returns attachment thumbnail path. Generates the thumbnail first if
 * it does not exist.
 * @param  mixed   $id     Attachment ID or a row returned by att_get() function.
 * @param  integer $width  Thumbnail width in pixels
 * @param  integer $height Thumbnail height in pixels
 * @param  string  $frame  Framing mode: 'width', 'height', 'auto', 'border_auto' or 'crop'
 * @return string          Thumbnail path on success or false on error
 */
function cot_files_thumb($id, $width = 0, $height = 0, $frame = ''){
    // Support rows fetched by att_get()
//    if (is_array($id)){
//        $row = $id;
//        $id = $row['file_id'];
//    }

    if($id instanceof files_model_File){
        $row = $id;
        $id = $row->file_id;
    }

    // Validate arguments
    if (!is_numeric($id) || $id <= 0)
    {
        return '';
    }

    if (empty($frame) || !in_array($frame, array('width', 'height', 'auto', 'crop', 'border_auto'))){
        $frame = cot::$cfg['files']['thumb_framing'];
    }

    if ($width <= 0)  $width  = (int)cot::$cfg['files']['thumb_width'];
    if ($height <= 0) $height = (int)cot::$cfg['files']['thumb_height'];


    // Attempt to load from cache
    $thumbs_folder = cot::$cfg['files']['folder'] . '/_thumbs';
    $cache_folder  = $thumbs_folder . '/' . $id;
    if (!file_exists($cache_folder)){
        mkdir($cache_folder, cot::$cfg['dir_perms'], true);
    }
    $thumb_path = cot_files_thumb_path($id, $width, $height, $frame);

    if (!$thumb_path || !file_exists($thumb_path))
    {
        // Generate a new thumbnail
        if (!isset($row)){
            $row = files_model_File::getById($id);
        }
        if (!$row || !$row->file_img) return false;

        $orig_path = $row->file_path;

        $thumbs_folder = $thumbs_folder . '/' . $id;
        $thumb_path = $thumbs_folder . '/'
            . cot::$cfg['files']['prefix'] . $id . '-' . $width . 'x' . $height . '-' . $frame . '.' . $row->file_ext;

        cot_files_thumbnail($orig_path, $thumb_path, $width, $height, $frame, (int)cot::$cfg['files']['quality'],
            (int)cot::$cfg['files']['upscale']);

        // Watermark
        if(!empty(cot::$cfg['files']['thumb_watermark']) && file_exists(cot::$cfg['files']['thumb_watermark'])){
            list($th_width, $th_height) = getimagesize($thumb_path);

            if($th_width >= cot::$cfg['files']['thumb_wm_widht'] || cot::$cfg['files']['thumb_wm_height']){
                cot_files_watermark($thumb_path, $thumb_path, cot::$cfg['files']['thumb_watermark']);
            }
        }
    }

    return $thumb_path;
}


/**
 * Creates image thumbnail
 *
 * @param string $source Original image path
 * @param string $target Thumbnail path
 * @param int $width Thumbnail width
 * @param int $height Thumbnail height
 * @param string $resize Resize options: crop auto width height
 * @param int $quality JPEG quality in %
 * @param boolean $upscale Upscale images smaller than thumb size
 * @return bool
 *
 * @todo 'border_auto' resize mode
 */
function cot_files_thumbnail($source, $target, $width, $height, $resize = 'crop', $quality = 85, $upscale = false)
{
    $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));

    if(!file_exists($source)) return false;

    list($width_orig, $height_orig) = getimagesize($source);

    if (!$upscale && $width_orig <= $width && $height_orig <= $height)
    {
        // Do not upscale smaller images, just copy them
        copy($source, $target);
        return;
    }

    $x_pos = 0;
    $y_pos = 0;

    $width = (mb_substr($width, -1, 1) == '%') ? (int) ($width_orig * (int) mb_substr($width, 0, -1) / 100) : (int) $width;
    $height = (mb_substr($height, -1, 1) == '%') ? (int) ($height_orig * (int) mb_substr($height, 0, -1) / 100) : (int) $height;

    // Avoid loading images there's not enough memory for
    if (function_exists('cot_img_check_memory') && !cot_img_check_memory($source, (int)ceil($width * $height * 4 / 1048576)))
    {
        return false;
    }

    if ($resize == 'crop')
    {
        $newimage = imagecreatetruecolor($width, $height);
        $width_temp = $width;
        $height_temp = $height;

        if ($width_orig / $height_orig > $width / $height)
        {
            $width = $width_orig * $height / $height_orig;
            $x_pos = -($width - $width_temp) / 2;
            $y_pos = 0;
        }
        else
        {
            $height = $height_orig * $width / $width_orig;
            $y_pos = -($height - $height_temp) / 2;
            $x_pos = 0;
        }
    }
    else
    {
        if ($resize == 'width' || $height == 0)
        {
            if ($width_orig > $width)
            {
                $height = $height_orig * $width / $width_orig;
            }
            else
            {
                $width = $width_orig;
                $height = $height_orig;
            }
        }
        elseif ($resize == 'height' || $width == 0)
        {
            if ($height_orig > $height)
            {
                $width = $width_orig * $height / $height_orig;
            }
            else
            {
                $width = $width_orig;
                $height = $height_orig;
            }
        }
        elseif ($resize == 'auto')
        {
            if ($width_orig < $width && $height_orig < $height)
            {
                $width = $width_orig;
                $height = $height_orig;
            }
            else
            {
                if ($width_orig / $height_orig > $width / $height)
                {
                    $height = $width * $height_orig / $width_orig;
                }
                else
                {
                    $width = $height * $width_orig / $height_orig;
                }
            }
        }

        $newimage = imagecreatetruecolor($width, $height); //
    }

    switch ($ext)
    {
        case 'gif':
            $oldimage = imagecreatefromgif($source);
            break;
        case 'png':
            imagealphablending($newimage, false);
            imagesavealpha($newimage, true);
            $oldimage = imagecreatefrompng($source);
            break;
        default:
            $oldimage = imagecreatefromjpeg($source);
            break;
    }

    imagecopyresampled($newimage, $oldimage, $x_pos, $y_pos, 0, 0, $width, $height, $width_orig, $height_orig);

    switch ($ext)
    {
        case 'gif':
            imagegif($newimage, $target);
            break;
        case 'png':
            imagepng($newimage, $target);
            break;
        default:
            imagejpeg($newimage, $target, $quality);
            break;
    }

    imagedestroy($newimage);
    imagedestroy($oldimage);
}

/**
 * Calculates path for the file thumbnail.
 * @param  int    $id     File ID
 * @param  int    $width  Thumbnail width
 * @param  int    $height Thumbnail height
 * @param  int    $frame  Thumbnail framing mode
 * @return string         Path for the file on disk or false file was not found
 */
function cot_files_thumb_path($id, $width, $height, $frame){

    $thumbs_folder = cot::$cfg['files']['folder'] . '/_thumbs/' . $id;
    $mask = $thumbs_folder . '/' . cot::$cfg['files']['prefix'] . $id . '-' . $width . 'x' . $height . '-' . $frame . '.*';
    $files = glob($mask, GLOB_NOSORT);
    if (!$files || count($files) == 0)
    {
        return false;
    }
    else
    {
        return $files[0];
    }
}

/**
 * Adds watermark for image
 * @param $source
 * @param $target
 * @param string $watermark watermark file
 * @param int $jpegquality
 * @return bool
 */
function cot_files_watermark($source, $target, $watermark = '', $jpegquality = 85){

    if (empty($watermark)) return false;

    $sourceExt = cot_files_get_ext($source);
    $targetExt = cot_files_get_ext($target);

    $is_img = (int)in_array($sourceExt, array('gif', 'jpg', 'jpeg', 'png'));
    if (!$is_img) return false;

    // Load the image
    $image = imagecreatefromstring(file_get_contents($source));
    $w = imagesx($image);
    $h = imagesy($image);

    // Load the watermark
    $watermark = imagecreatefrompng($watermark);
    $ww = imagesx($watermark);
    $wh = imagesy($watermark);

    $wmAdded = false;
    if ( ($ww + 60) < $w && ($wh + 40) < $h ){
        imageAlphaBlending($image, TRUE);

        // Insert watermark to the right bottom corner
        imagecopy($image, $watermark, $w - 40 - $ww, $h-$wh-20, 0, 0, $ww, $wh);
        unlink($target);
        switch($targetExt)
        {
            case 'gif':
                imagegif($image, $target);
                break;

            case 'png':
                imagepng($image, $target);
                break;

            default:
                imagejpeg($image, $target, $jpegquality);
                break;
        }
        $wmAdded = true;

    }

    imagedestroy($watermark);
    imagedestroy($image);
    return $wmAdded;
}

/**
 * Сборка мусора от несохраненных форм
 * @todo дописать
 */
function cot_files_formGarbageCollect(){
    return 0;
}

/**
 * workaround for splitting basename whith beginning utf8 multibyte char
 */
function mb_basename($filepath, $suffix = NULL)
{
    $splited = preg_split('/\//', rtrim($filepath, '/ '));
    return substr(basename('X' . $splited[count($splited) - 1], $suffix), 1);
}

/**
 * Recursive remove directory
 * @param $dir
 * @return bool
 */
function rrmdir($dir) {
    if(empty($dir) && $dir != '0') return false;

    if (is_dir($dir)) {
        $objects = scandir($dir);
        foreach ($objects as $object) {
            if ($object != "." && $object != "..") {
                if (filetype($dir."/".$object) == "dir") rrmdir($dir."/".$object); else unlink($dir."/".$object);
            }
        }
        reset($objects);
        rmdir($dir);
    }
}

// ===== outputs and widgets =====

/**
 * Generates a avatar selecteion
 * Use it as CoTemplate callback.
 *
 * @param int $userId for admins only
 * @param string $tpl Template code
 * @return string
 *
 * @todo no cache parameter for css
 * @todo generate formUnikey
 */
function cot_files_avatarbox($userId = null, $tpl = 'files.avatarbox' ){
    global $R, $cot_modules, $usr;

    list($usr['auth_read'], $usr['auth_write'], $usr['isadmin']) = cot_auth('files', 'a');

    $source = 'pfs';
    $item = 0;
    $filed = '';

    $uid = cot::$usr['id'];
    if($usr['isadmin']){
        $uid = $userId;

        if(is_null($uid)) $uid = cot_import('uid', 'G', 'INT');
        if(is_null($uid)) $uid = $usr['id'];
    }

    $jsFunc = (!defined('COT_HEADER_COMPLETE')) ? 'cot_rc_link_file': 'cot_rc_link_footer';
    $nc = $cot_modules['files']["version"];

    // Подключаем jQuery-templates только один раз
//    static $jQtemlatesOut = false;
//    $jQtemlates = '';
    $modUrl = cot::$cfg['modules_dir'].'/files';

    $jsFunc = (!defined('COT_HEADER_COMPLETE')) ? 'cot_rc_link_file': 'cot_rc_link_footer';
    // CSS to style the file input field as button and adjust the Bootstrap progress bars
    $jsFunc($modUrl.'/lib/upload/css/jquery.fileupload.css');
    $jsFunc($modUrl.'/lib/upload/css/jquery.fileupload-ui.css');

    /* === Java Scripts === */
    // The jQuery UI widget factory, can be omitted if jQuery UI is already included
    cot_rc_link_footer($modUrl.'/lib/upload/js/vendor/jquery.ui.widget.js?nc='.$nc);
    cot_rc_link_footer($modUrl.'/lib/upload/js/jquery.iframe-transport.js?nc='.$nc);
    cot_rc_link_footer($modUrl.'/lib/upload/js/jquery.fileupload.js?nc='.$nc);

    $formId = "{$source}_{$item}_{$filed}";
    $type = array('image');

    $type = json_encode($type);

    // Get current avatar
    $user = cot_files_getUserData($uid);
    $avatar = cot_files_user_avatar($user['user_avatar'], $user);

    $t = new XTemplate(cot_tplfile($tpl, 'module'));

    $limits = cot_files_getLimits($usr['id'], $source, $item, '');

    $unikey = mb_substr(md5($formId . '_' . rand(0, 99999999)), 0, 15);
    $params = base64_encode(serialize(array(
        'source'  => $source,
        'item'    => $item,
        'field'   => '',
        'limit'   => $limits['count_max'],
        'type'    => $type,
        'avatar'  => 1,
        'unikey'  => $unikey
    )));

    $action = 'index.php?e=files&m=upload&source='.$source.'&item='.$item;
    if($uid != $usr['id']){
        $t->assign(array(
            'UPLOAD_UID'     => $uid,
        ));
        $action .= '&uid='.$uid;
    }

    // Metadata
    $t->assign(array(
        'AVATAR'         => $avatar,
        'UPLOAD_ID'      => $formId,
        'UPLOAD_SOURCE'  => $source,
        'UPLOAD_ITEM'    => $item,
        'UPLOAD_FIELD'   => '',
        'UPLOAD_LIMIT'   => $limits['count_max'],
        'UPLOAD_TYPE'    => $type,
        'UPLOAD_PARAM'   => $params,
        'UPLOAD_CHUNK'   => (int)cot::$cfg['files']['chunkSize'],
        'UPLOAD_EXTS'    => preg_replace('#[^a-zA-Z0-9,]#', '', cot::$cfg['files']['exts']),
//        'UPLOAD_ACCEPT'  => preg_replace('#[^a-zA-Z0-9,*/-]#', '',cot::$cfg['plugin']['attach2']['accept']),
        'UPLOAD_MAXSIZE' => $limits['size_maxfile'],
        'UPLOAD_ACTION'  => $action,
        'UPLOAD_X'       => cot::$sys['xk'],
    ));

    $t->parse();
    return $t->text();
}

/**
 * Generates a link to PFS popup window
 *
 * @param int $uid User ID
 * @param string $formName Form name
 * @param string $inputName Input name
 * @param string $title Link title
 * @param string $parser Custom parser (otional)
 * @return string
 */
function cot_files_buildPfs($uid, $formName, $inputName, $title, $parser = ''){

    if ($uid == 0)
    {
        $res = "<a href=\"javascript:files_pfs('0','" . $formName . "','" . $inputName . "','" . $parser . "')\">" . $title . "</a>";
    }
    elseif (cot_auth('files', 'a', 'R'))
    {
        $res = "<a href=\"javascript:files_pfs('" . $uid . "','" . $formName . "','" . $inputName . "','" . $parser . "')\">" . $title . "</a>";
    }
    else
    {
        $res = '';
    }

    static $jsOut = false;

    if($res != '' && !$jsOut){

//        $jsFunc = (!defined('COT_HEADER_COMPLETE')) ? 'cot_rc_link_file': 'cot_rc_link_footer';
        $jsFunc = (!defined('COT_HEADER_COMPLETE')) ? 'cot_rc_embed': 'cot_rc_embed_footer';

        $jsFunc("function files_pfs(id, c1, c2, parser){
    window.open(getBaseHref() + 'index.php?e=files&m=pfs&uid=' + id + '&c1=' + c1 + '&c2=' + c2 + '&parser=' + parser, 'PFS', 'status=1, toolbar=0,location=0,directories=0,menuBar=0,resizable=1,scrollbars=yes,width=754,height=512,left=32,top=16');
    }");
        $jsOut = true;
    }

    return($res);
}

/**
 * Renders attached items on page
 * @param  string $source   Target module/plugin code
 * @param  integer $item    Target item id
 * @param  string $field
 * @param  string $tpl      Template code
 * @param  string $type     Attachment type filter: 'files', 'images'. By default includes all attachments.
 * @param  int $limit
 * @param  string $order
 * @return string           Rendered output
 */
function cot_files_display($source, $item, $field = '',  $tpl = 'files.display', $type = 'all', $limit = 0, $order = ''){

    $t = new XTemplate(cot_tplfile($tpl, 'module'));

    $t->assign(array(
        'FILES_SOURCE'  => $source,
        'FILES_ITEM'    => $item,
        'FILES_FIELD'   => $field,
    ));

    $condition = array(array('file_source', $source));
    if ($type == 'files'){
        $condition[] = array('file_img', 0);
    }elseif ($type == 'images'){
        $condition[] = array('file_img', 1);
    }

    if($field != '_all_'){
        $condition[] = array('file_field', $field);
    }

    if($order == '') $order = 'file_order ASC';

    if(is_array($item)){
        $item = array_map('intval', $item);
    }else{
        $item = intval($item);
    }
    $condition[] = array('file_item', $item);

    $files = files_model_File::find($condition, $limit, 0, $order);

    $num = 1;
    if($files){
        foreach ($files as $row){
            $t->assign(files_model_File::generateTags($row, 'FILES_ROW_'));
            $t->assign(array(
                'FILES_ROW_NUM'      => $num,
            ));
            $t->parse('MAIN.FILES_ROW');
            $num++;
        }
    }
    $t->parse();

    return $t->text();
}


/**
 * Renders files only as downloads block.
 * @param  string $source   Target module/plugin code
 * @param  integer $item    Target item id
 * @param  string $field
 * @param  string $tpl      Template code
 * @param  int $limit
 * @param  string $order
 * @return string           Rendered output
 */
function cot_files_downloads($source, $item, $field = '', $tpl = 'files.downloads', $limit = 0, $order = ''){
    return cot_files_display($source, $item, $field, $tpl, 'files', $limit, $order);
}

/**
 * Generates a form input file
 * Use it as CoTemplate callback.
 *
 * @param $source
 * @param $item
 * @param string $name Input name
 * @param string $type File types. Comma separated 'all', 'file', 'image', 'audio', 'video'
 * @param int $limit file limit
 *      -1 - use plugin config value
 *       0 - unlimited
 * @param string $tpl Template code
 * @param bool $standalone
 * @param int $userId   for admins only
 * @return string
 *
 * @todo no cache parameter for css
 * @todo generate formUnikey
 */
function cot_files_filebox($source, $item, $name = '', $type = 'all', $limit = -1, $tpl = 'files.filebox',
                           $standalone = false, $userId = null)
{
    global $R, $cot_modules, $usr;

    list($usr['auth_read'], $usr['auth_write'], $usr['isadmin']) = cot_auth('files', 'a');

    $uid = $usr['id'];
    if($source == 'pfs' && $usr['isadmin']){
        $uid = $userId;

        if(is_null($uid)) $uid = cot_import('uid', 'G', 'INT');
        if(is_null($uid)) $uid = $usr['id'];
    }

    $jsFunc = (!defined('COT_HEADER_COMPLETE')) ? 'cot_rc_link_file': 'cot_rc_link_footer';
    $nc = $cot_modules['files']["version"];

    // Подключаем jQuery-templates только один раз
    static $jQtemlatesOut = false;
    $jQtemlates = '';
    if(!$jQtemlatesOut){
        $templates = new XTemplate(cot_tplfile('files.templates', 'module'));
        $templates->assign(array(
            'IS_STANDALONE' => ($standalone) ? 1 : 0,
        ));
        $templates->parse();
        $jQtemlates = $templates->text();
        $jQtemlatesOut = true;

        $modUrl = cot::$cfg['modules_dir'].'/files';

        // Generic page styles
        $jsFunc($modUrl.'/tpl/filebox.css');

        // Bootstrap Image Gallery styles
        //$jsFunc($cfg['plugins_dir'].'/attach2/lib/Gallery/css/blueimp-gallery.min.css');

        // CSS to style the file input field as button and adjust the Bootstrap progress bars
        $jsFunc($modUrl.'/lib/upload/css/jquery.fileupload.css');
        $jsFunc($modUrl.'/lib/upload/css/jquery.fileupload-ui.css');


        /* === Java Scripts === */
        // The jQuery UI widget factory, can be omitted if jQuery UI is already included
        cot_rc_link_footer($modUrl.'/lib/upload/js/vendor/jquery.ui.widget.js?nc='.$nc);

        // The Templates plugin is included to render the upload/download listings
        cot_rc_link_footer($modUrl.'/lib/JavaScript-Templates/tmpl.min.js?nc='.$nc);

        // The Load Image plugin is included for the preview images and image resizing functionality
        cot_rc_link_footer($modUrl.'/lib/JavaScript-Load-Image/js/load-image.min.js?nc='.$nc);

        // The Canvas to Blob plugin is included for image resizing functionality
        cot_rc_link_footer($modUrl.'/lib/JavaScript-Canvas-to-Blob/canvas-to-blob.min.js?nc='.$nc);

        // blueimp Gallery script
        //cot_rc_link_footer($cfg['plugins_dir'].'/attach2/lib/Gallery/js/jquery.blueimp-gallery.min.js');

        // The Iframe Transport is required for browsers without support for XHR file uploads
        cot_rc_link_footer($modUrl.'/lib/upload/js/jquery.iframe-transport.js?nc='.$nc);

        // The basic File Upload plugin
        cot_rc_link_footer($modUrl.'/lib/upload/js/jquery.fileupload.js?nc='.$nc);

        // The File Upload file processing plugin
        cot_rc_link_footer($modUrl.'/lib/upload/js/jquery.fileupload-process.js?nc='.$nc);

        // The File Upload image preview & resize plugin
        cot_rc_link_footer($modUrl.'/lib/upload/js/jquery.fileupload-image.js?nc='.$nc);

        // The File Upload audio preview plugin
        cot_rc_link_footer($modUrl.'/lib/upload/js/jquery.fileupload-audio.js?nc='.$nc);

        // The File Upload video preview plugin
        //cot_rc_link_footer($cfg['plugins_dir'].'/attach2/lib/upload/js/jquery.fileupload-video.js');

        // The File Upload validation plugin
        cot_rc_link_footer($modUrl.'/lib/upload/js/jquery.fileupload-validate.js?nc='.$nc);

        // The File Upload user interface plugin
        cot_rc_link_footer($modUrl.'/lib/upload/js/jquery.fileupload-ui.js?nc='.$nc);

        //    // The localization script
        //    cot_rc_link_footer($cfg['plugins_dir'].'/attach2/lib/upload/js/locale.js');


        // The main application script
        cot_rc_link_footer($modUrl.'/js/files.js?nc='.$nc);

        // Table Drag&Drop plugin for reordering
        cot_rc_link_footer('js/jquery.tablednd.min.js?nc='.$nc);
    }

    $formId = "{$source}_{$item}_{$name}";
    $type = str_replace(' ', '', $type);
    if(empty($type)){
        $type = array('all');
    }else{
        $type = explode(',', $type);
    }
    $type = json_encode($type);

    $t = new XTemplate(cot_tplfile($tpl, 'module'));

    $limits = cot_files_getLimits($usr['id'], $source, $item, $name);
    if($limit == 0){
        $limit = 100000000000000000;
    }elseif($limit == -1){
        $limit = $limits['count_max'];
    }

    $unikey = mb_substr(md5($formId . '_' . rand(0, 99999999)), 0, 15);
    $params = base64_encode(serialize(array(
        'source'  => $source,
        'item'    => $item,
        'field'   => $name,
        'limit'   => $limit,
        'type'    => $type,
        'unikey'  => $unikey
    )));

    $action = 'index.php?e=files&m=upload&source='.$source.'&item='.$item;
    if(!empty($name)) $action .= '&field='.$name;
    if($uid != $usr['id']){
        $t->assign(array(
            'UPLOAD_UID'     => $uid,
        ));
        $action .= '&uid='.$uid;
    }

    // Metadata
    $t->assign(array(
        'UPLOAD_ID'      => $formId,
        'UPLOAD_SOURCE'  => $source,
        'UPLOAD_ITEM'    => $item,
        'UPLOAD_FIELD'   => $name,
        'UPLOAD_LIMIT'   => $limit,
        'UPLOAD_TYPE'    => $type,
        'UPLOAD_PARAM'   => $params,
        'UPLOAD_CHUNK'   => (int)cot::$cfg['files']['chunkSize'],
        'UPLOAD_EXTS'    => preg_replace('#[^a-zA-Z0-9,]#', '', cot::$cfg['files']['exts']),
//        'UPLOAD_ACCEPT'  => preg_replace('#[^a-zA-Z0-9,*/-]#', '',cot::$cfg['plugin']['attach2']['accept']),
        'UPLOAD_MAXSIZE' => $limits['size_maxfile'],
        'UPLOAD_ACTION'  => $action,
        'UPLOAD_X'       => cot::$sys['xk'],
    ));

    $t->parse();
    return $t->text().$jQtemlates;
}

/**
 * Renders images only as a gallery.
 * @param  string $source   Target module/plugin code
 * @param  integer $item    Target item id
 * @param  string $field
 * @param  string $tpl      Template code
 * @param  int $limit
 * @param  string $order
 * @return string           Rendered output
 */
function cot_files_gallery($source, $item, $field = '', $tpl = 'files.gallery', $limit = 0, $order = ''){
    return cot_files_display($source, $item, $field, $tpl, 'images', $limit, $order);
}

/**
 * Get current avatar
 * @param $file_id
 * @param array|int $urr
 * @param int $width
 * @param int $height
 * @param string $frame
 * @return string
 */
function cot_files_user_avatar($file_id, $urr = 0, $width = 0, $height = 0, $frame = ''){

//    $user = cot_files_getUserData($uid);
    $avatar = cot_rc('files_user_default_avatar');
    $url = cot_files_user_avatar_url($file_id, $width, $height, $frame = '');
    $alt = cot::$L['Avatar'];
    if(is_array($urr)) $alt = htmlspecialchars(cot_files_user_displayName($urr));
    if($url){
        $avatar = cot_rc('files_user_avatar', array(
            'src'=> $url,
            'alt' => $alt,
        ));
    }
    return $avatar;
}

/**
 * @param $file_id
 * @param int $width
 * @param int $height
 * @param string $frame
 * @return string
 */
function cot_files_user_avatar_url($file_id, $width = 0, $height = 0, $frame = ''){

    $file = null;
    if($file_id instanceof files_model_File){
        $file = $file_id;
        $file_id = $file->file_id;
    }else{
        $file_id = (int)$file_id;
        if(!$file_id) return '';
        $file = files_model_File::getById($file_id);
    }

    if(!$file) return '';

    if (empty($frame) || !in_array($frame, array('width', 'height', 'auto', 'crop', 'border_auto'))){
        $frame = cot::$cfg['files']['avatar_framing'];
    }

    if ($width <= 0)  $width  = (int)cot::$cfg['files']['avatar_width'];
    if ($height <= 0) $height = (int)cot::$cfg['files']['avatar_height'];

    return cot_files_thumb($file, $width, $height, $frame);

}

/**
 * user display name
 */
function cot_files_user_displayName($user){
    if(empty($user)) return '';

    if(!empty($user['user_firstname']) || !empty($user['user_lastname']) || !empty($user['user_middlename'])){
        return trim($user['user_lastname'].' '.$user['user_firstname'].' '.$user['user_middlename']);
    }

    if(!empty($user['user_first_name']) || !empty($user['user_last_name']) || !empty($user['user_middle_name'])){
        return trim($user['user_last_name'].' '.$user['user_first_name'].' '.$user['user_middle_name']);
    }

    return $user['user_name'];
}

/**
 * Generates a file upload/edit widget.
 * Use it as CoTemplate callback.
 *
 * @param  string $source Target module/plugin code.
 * @param  integer $item Target item id.
 * @param  string $field Target item field
 * @param  string $tpl Template code
 * @param string $width
 * @param string $height
 * @return string           Rendered widget
 */
function cot_files_widget($source, $item, $field = '', $tpl = 'files.widget', $width = '100%', $height = '300'){
    global $files_widget_present, $cot_modules;

    $t = new XTemplate(cot_tplfile($tpl, 'module'));

    // Metadata
    $limits = cot_files_getLimits(cot::$usr['id'], $source, $item, $field);

    $urlParams = array('m'=>'files', 'a'=>'display', 'source'=>$source, 'item'=>$item, 'field'=>$field,
        'nc'=>$cot_modules['files']['version']);

    $t->assign(array(
        'FILES_SOURCE'  => $source,
        'FILES_ITEM'    => $item,
        'FILESH_FIELD'  => $field,
        'FILES_EXTS'    => preg_replace('#[^a-zA-Z0-9,]#', '', cot::$cfg['files']['exts']),
//        'FILES_ACCEPT'  => preg_replace('#[^a-zA-Z0-9,*/-]#', '',$cfg['plugin']['attach2']['accept']),
        'FILES_MAXSIZE' => $limits['size_maxfile'],
        'FILES_WIDTH'   => $width,
        'FILES_HEIGHT'  => $height,
        'FILES_URL'     => cot_url('files', $urlParams, '', true),
    ));

    $t->parse();

    $files_widget_present = true;

    return $t->text();
}
