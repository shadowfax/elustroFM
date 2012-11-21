<?php
/**
 * upload.php
 *
 * Copyright 2012, Juan Pedro Gonzalez Gutierrez (Modified for File Manager)
 * Copyright 2009, Moxiecode Systems AB
 * Released under GPL License.
 *
 * License: http://www.plupload.com/license
 * Contributing: http://www.plupload.com/contributing
 */


// HTTP headers for no cache etc
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// --- File Manager MOD Start ---
// Sanity checks
if (strcasecmp($_SERVER['REQUEST_METHOD'], 'POST') !== 0) {
	// Bad request
	header("HTTP/1.1 405 Method Not Allowed");
	die("<html><head><title>Method Not Allowed</title></head><body><h1>Method Not Allowed</h1></body></html>");
} elseif ((!isset($_POST['type'])) || (!isset($_POST['path']))) {
	header("HTTP/1.1 400 Bad Request");
	die("<html><head><title>Bad Request</title></head><body><h1>Bad Request</h1></body></html>");
}

// Session stuff
$sid = null;
if (isset($_POST['SID'])) {
	if (!empty($_POST['SID'])) session_id($_POST['SID']);	
}

if (!isset($_SESSION)) {
	session_start();
}
require 'yoursessioncheck.php';
		
// From here on we are issuing JSON RPC messages so...
header("Content-Type: application/json");

// Load the configuration file
include_once 'config.php';

// Build the target directory base path
$base_path = null;

// Check if we are using a custom folder
if (isset($_POST['folders'])) {
	// Load the folder configuration array
	$public_folders = (include('config.folders.php'));
	
	if (isset($public_folders[$_POST['folders']][$_POST['type']]['path'])) {
		$base_path = realpath(DIR_ROOT . $public_folders[$_POST['folders']][$_POST['type']]['path']);
		if (empty($base_path)) {
			die('{"jsonrpc" : "2.0", "error" : {"code": -32000, "message": "Invalid directory"}, "id" : "id"}');
		}
	} else {
		die('{"jsonrpc" : "2.0", "error" : {"code": -32000, "message": "Invalid directory"}, "id" : "id"}');
	}
	unset($public_folders);
} else {
	// Load configuration defaults
	switch ($_POST['type']) {
		case 'image':
			{
				$base_path = realpath(DIR_ROOT . DIR_IMAGES);
				break;
			}
		case 'file':
			{
				$base_path = realpath(DIR_ROOT . DIR_FILES);
				break;
			}
		default:
			{
				die('{"jsonrpc" : "2.0", "error" : {"code": -32000, "message": "Invalid directory type"}, "id" : "id"}');
			}
	}
}

// sanity check for base path
if (empty($base_path)) {
	die('{"jsonrpc" : "2.0", "error" : {"code": -32000, "message": "Invalid directory"}, "id" : "id"}');
}

// Build the target directory
$targetDir = realpath($base_path . DIRECTORY_SEPARATOR . $_POST['path']);
if (strpos($targetDir, $base_path) !== 0) {
	die('{"jsonrpc" : "2.0", "error" : {"code": -32000, "message": "Possible directory traversal detected"}, "id" : "id"}');
}

// --- File Manager MOD End ---

$cleanupTargetDir = true; // Remove old files
$maxFileAge = 5 * 3600; // Temp file age in seconds

// 5 minutes execution time
@set_time_limit(5 * 60);

// Uncomment this one to fake upload time
// usleep(5000);

// Get parameters
$chunk = isset($_REQUEST["chunk"]) ? intval($_REQUEST["chunk"]) : 0;
$chunks = isset($_REQUEST["chunks"]) ? intval($_REQUEST["chunks"]) : 0;
$fileName = isset($_REQUEST["name"]) ? $_REQUEST["name"] : '';

// Clean the fileName for security reasons
$fileName = preg_replace('/[^\w\._]+/', '_', $fileName);

// Make sure the fileName is unique but only if chunking is disabled
if ($chunks < 2 && file_exists($targetDir . DIRECTORY_SEPARATOR . $fileName)) {
	$ext = strrpos($fileName, '.');
	$fileName_a = substr($fileName, 0, $ext);
	$fileName_b = substr($fileName, $ext);

	$count = 1;
	while (file_exists($targetDir . DIRECTORY_SEPARATOR . $fileName_a . '_' . $count . $fileName_b))
		$count++;

	$fileName = $fileName_a . '_' . $count . $fileName_b;
}

$filePath = $targetDir . DIRECTORY_SEPARATOR . $fileName;

// Create target dir
if (!file_exists($targetDir))
	@mkdir($targetDir);

// Remove old temp files	
if ($cleanupTargetDir && is_dir($targetDir) && ($dir = opendir($targetDir))) {
	while (($file = readdir($dir)) !== false) {
		$tmpfilePath = $targetDir . DIRECTORY_SEPARATOR . $file;

		// Remove temp file if it is older than the max age and is not the current file
		if (preg_match('/\.part$/', $file) && (filemtime($tmpfilePath) < time() - $maxFileAge) && ($tmpfilePath != "{$filePath}.part")) {
			@unlink($tmpfilePath);
		}
	}

	closedir($dir);
} else
	die('{"jsonrpc" : "2.0", "error" : {"code": 100, "message": "Failed to open temp directory."}, "id" : "id"}');
	

// Look for the content type header
if (isset($_SERVER["HTTP_CONTENT_TYPE"]))
	$contentType = $_SERVER["HTTP_CONTENT_TYPE"];

if (isset($_SERVER["CONTENT_TYPE"]))
	$contentType = $_SERVER["CONTENT_TYPE"];

// Handle non multipart uploads older WebKit versions didn't support multipart in HTML5
if (strpos($contentType, "multipart") !== false) {
	if (isset($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
		// Open temp file
		$out = fopen("{$filePath}.part", $chunk == 0 ? "wb" : "ab");
		if ($out) {
			// Read binary input stream and append it to temp file
			$in = fopen($_FILES['file']['tmp_name'], "rb");

			if ($in) {
				while ($buff = fread($in, 4096))
					fwrite($out, $buff);
			} else
				die('{"jsonrpc" : "2.0", "error" : {"code": 101, "message": "Failed to open input stream."}, "id" : "id"}');
			fclose($in);
			fclose($out);
			@unlink($_FILES['file']['tmp_name']);
		} else
			die('{"jsonrpc" : "2.0", "error" : {"code": 102, "message": "Failed to open output stream."}, "id" : "id"}');
	} else
		die('{"jsonrpc" : "2.0", "error" : {"code": 103, "message": "Failed to move uploaded file."}, "id" : "id"}');
} else {
	// Open temp file
	$out = fopen("{$filePath}.part", $chunk == 0 ? "wb" : "ab");
	if ($out) {
		// Read binary input stream and append it to temp file
		$in = fopen("php://input", "rb");

		if ($in) {
			while ($buff = fread($in, 4096))
				fwrite($out, $buff);
		} else
			die('{"jsonrpc" : "2.0", "error" : {"code": 101, "message": "Failed to open input stream."}, "id" : "id"}');

		fclose($in);
		fclose($out);
	} else
		die('{"jsonrpc" : "2.0", "error" : {"code": 102, "message": "Failed to open output stream."}, "id" : "id"}');
}

// Check if file has been uploaded
if (!$chunks || $chunk == $chunks - 1) {
	// Strip the temp .part suffix off 
	rename("{$filePath}.part", $filePath);
}


// Return JSON-RPC response
die('{"jsonrpc" : "2.0", "result" : null, "id" : "id"}');

?>