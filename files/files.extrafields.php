<?php
/* ====================
[BEGIN_COT_EXT]
Hooks=admin.extrafields.first
[END_COT_EXT]
==================== */

/**
 * module Files for Cotonti Siena
 *
 * @package Files
 * @author Cotonti Team
 * @copyright (c) Cotonti Team
 */
defined('COT_CODE') or die('Wrong URL');

require_once cot_incfile('files', 'module');
$extra_whitelist[$db_files] = array(
	'name' => $db_files,
	'caption' => $L['Module'].' Files',
	'type' => 'module',
	'code' => 'files',
	'tags' => array(

	)
);

$extra_whitelist[$db_files_folders] = array(
    'name' => $db_files_folders,
    'caption' => $L['Module'].' Files',
    'type' => 'module',
    'code' => 'files',
    'tags' => array(

    )
);