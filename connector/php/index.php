<?php
/**
 * Heavily modified by Juan Pedro González Gutierrez
 * Original author Anton Syuvaev (http://h8every1.ru/)
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);
include 'config.php';

include_once 'Json/Server.php';

class TinyImageManager extends Json_Server {

	var $dir;
	var $firstAct = false;
	var $folderAct = false;
	var $SID;
	var $total_pages = 1;
	var $http_root;

	
	protected $_config;
  
	/**
	 * Конструктор
	 * Constructor
	 *
	 * @return TinyImageManager
	 */
	public function __construct() 
	{
		/*
		// Bypass the constructor on file uploads
		if (isset($_POST['action'])) {
			if (strcasecmp($_POST['action'], 'uploadFile') === 0) {
				// this is a special case as it is not purelly JSON-RPC
				// Lets fake things a little
				$this->_request = new Json_Server_Request();
				$this->_request->setVersion("2.0");
				
				$params = array();
				if (isset($_POST['folders'])) {
					$params['folders'] = $_POST['folders'];
				}
				$this->_request->setParams($params);
				
				// call the method
				$this->uploadfileAction();
				return;	
			}
		}
		*/
		
		// Initialize the JSON-RPC server
		parent::__construct();
	}
	
	protected function init()
	{
		ob_start("ob_gzhandler");
	
		$sid = $this->getRequest()->getParam('SID');
		if(null !== $sid) session_id($sid);
		
		if (!isset($_SESSION)) {
			session_start();
		}
		require 'yoursessioncheck.php';

		if (!isset($_SESSION['tiny_image_manager_path'])) {
			$_SESSION['tiny_image_manager_path'] = '';
		}
		if (!isset($_SESSION['tiny_image_manager_type'])) {
			$_SESSION['tiny_image_manager_type'] = '';
		}
		if (!isset($_SESSION['tiny_image_manager_page'])) {
			$_SESSION['tiny_image_manager_page'] = 1;
		}

		$this->_config['ALLOWED_IMAGES'] = array( 'jpeg', 'jpg', 'gif', 'png' );
		$this->_config['ALLOWED_FILES'] = array( '3gp', 'avi', 'bmp', 'bz', 'cpp', 'css', 'doc', 'docx', 'exe', 'flac', 'flv', 'gz',
		                                         'htm', 'html', 'm4v', 'mkv', 'mov', 'mp3', 'mp4', 'mpg', 'ogg', 'pdf', 'ppt', 'pptx',
		                                         'psd', 'ptt', 'rar', 'rb', 'rtf', 'swf', 'tar', 'tiff', 'txt', 'vob', 'wav', 'wmv',
		                                         'xhtml', 'xls', 'xlsx', 'xml', 'zip'
		);

		$this->_config['DIR_IMAGES'] = DIR_IMAGES;
		$this->_config['DIR_FILES'] = DIR_FILES;
    
		// Get the user defined folder
		$folders = $this->getRequest()->getParam('folders');
		if (null !== $folders) {
			// Load the folder configuration array
			$public_folders = (include('config.folders.php'));
    	
			if (isset($public_folders[$folders])) {
				$public_folders = $public_folders[$folders];
				if (isset($public_folders['image'])) {
					if (isset($public_folders['image']['path'])) $this->_config['DIR_IMAGES'] = $public_folders['image']['path'];
					if (isset($public_folders['image']['allowed'])) $this->config['ALLOWED_IMAGES'] = $public_folders['image']['allowed'];
				}
				if (isset($public_folders['file'])) {
					if (isset($public_folders['file']['path'])) $this->_config['DIR_FILES'] = $public_folders['file']['path'];
					if (isset($public_folders['file']['allowed'])) $this->_config['ALLOWED_FILES'] = $public_folders['file']['allowed']; 
				}
			}
			unset($public_folders);
			unset($folders);
		}
    
		$this->dir = array( 'image' => realpath(DIR_ROOT . $this->_config['DIR_IMAGES']), 'file' => realpath(DIR_ROOT . $this->_config['DIR_FILES']));

		$this->http_root = rtrim(HTTP_ROOT, '/');

		include WIDE_IMAGE_LIB;
	}
  
	public function setupdataAction()
	{
		$lang = $this->getRequest()->getParam('lang');
		if (null === $lang) $lang = LANG;

		$return['lang'] = '{}';
		$langFile = '../../langs/' . mb_strtolower($lang) . '_data.js';
		if (file_exists($langFile)) {
			$return['lang'] = file_get_contents($langFile);
		}

		$return['upload']['images']['allowed'] = $this->_config['ALLOWED_IMAGES'];
		$return['upload']['images']['width'] = MAX_WIDTH;
		$return['upload']['images']['height'] = MAX_HEIGHT;
		$return['upload']['files']['allowed'] = array_merge($this->_config['ALLOWED_IMAGES'], $this->_config['ALLOWED_FILES']);
		
		return $return;
	}
	
	/**
	 * Создать папку
	 */
	public function newfolderAction()
	{
		$result = array();
		
		$dir = $this->AccessDir($this->getRequest()->getParam('path'), $this->getRequest()->getParam('type'));
		if ($dir) {
			$fullName = $dir . '/' . $this->getRequest()->getParam('name');
			if (preg_match('/[a-z0-9-_]+/sim', $this->getRequest()->getParam('name'))) {
				if (is_dir($fullName)) {
					$response = $this->getResponse();
					$response->setError( new Json_Server_Error("Folder with this name already exists"));
					$response->sendResponse();
					exit(0);
				} else {
					if (!mkdir($fullName)) {
						$response = $this->getResponse();
						$response->setError( new Json_Server_Error("Error creating folder"));
						$response->sendResponse();
						exit(0);
					}
				}
			} else {
				$response = $this->getResponse();
				$response->setError( new Json_Server_Error("Folder name can only contain latin letters, digits and underscore"));
				$response->sendResponse();
				exit(0);
			}
		} else {
			$response = $this->getResponse();
			$response->setError( new Json_Server_Error("Folder access denied"));
			$response->sendResponse();
			exit(0);
		}

		return $result;
	}

	/**
	 * Загрузка папки
	 */
	public function openfolderAction()
	{
		// здесь будем хранить результат
		$result = array();

		// чистим исходные данные
		$path = $this->getRequest()->getParam('path');
		
		if (!isset($path) || $path == '/') {
			$path = '';
		}
		$type = $this->getRequest()->getParam('type');

		// если зашли первый раз, показываем предыдущую папку
		// if you went for the first time, show the previous folder
		$default = $this->getRequest()->getParam('default');
		if (isset($default) && isset($_SESSION['tiny_image_manager_path'], $_SESSION['tiny_image_manager_type']) && $_SESSION['tiny_image_manager_path'] !== 'undefined' && $_SESSION['tiny_image_manager_type'] !== 'undefined' && $_SESSION['tiny_image_manager_type']) {
			$path = $_SESSION['tiny_image_manager_path'];
			$type = $_SESSION['tiny_image_manager_type'];
		} else {
			$path = $_SESSION['tiny_image_manager_path'] = $this->getRequest()->getParam('path');
			// если тип не задан, показываем изображения
			$type = $type ? $type : 'image';
			$_SESSION['tiny_image_manager_type'] = $type;
		}

		if (isset($default) && $_SESSION['tiny_image_manager_page'] != 1 && $_SESSION['tiny_image_manager_page'] != 'undefined') {
			$page = $_SESSION['tiny_image_manager_page'];
		} else {
			$page = $this->getRequest()->getParam('page');
			if (null === $page) $page = 1;
			$_SESSION['tiny_image_manager_page'] = $page;
		}


		// генерируем хлебные крошки
		$result['path'] = $this->DirPath($type, $this->AccessDir($path, $type));

		// генерируем дерево каталогов
		$result['tree'] = '';
		// если мы показываем файлы, а не картинки, то картинки надо пропускать
		if ($type == 'file') {
			$this->firstAct = false;
			$result['tree'] .= $this->DirStructure('image', 'first');
			$this->firstAct = $path ? false : true;
			$result['tree'] .= $this->DirStructure('file', 'first', $this->AccessDir($path, 'file'));
		} else {
			// иначе показываем каталог в разделе изображения
			$this->firstAct = $path ? false : true;
			$result['tree'] .= $this->DirStructure('image', 'first', $this->AccessDir($path, 'image'));
			$this->firstAct = false;
			$result['tree'] .= $this->DirStructure('file', 'first');
		}

		// генерируем список файлов
		$result['files'] = $this->ShowDir($path, $type, $page);
		$result['pages'] = $this->ShowPages($path, $type, $page);
		$result['totalPages'] = $this->total_pages;

		return $result;
	}
	
	/**
	 * Загрузить изображение
	 */
	public function uploadfileAction()
	{
		// ToDo: Try the config first
		$url = dirname($_SERVER['REQUEST_URI']) . '/upload.php';
		
		// return the setup for plupload
		$result = array(
			'runtimes'		=> 'html5,html4',
			'max_file_size'	=> '50mb',
			'url'			=> $url
		);
		
		return $result;
		
		/*
		// Must initialize as we skipped the parent class initialization
		$this->init();
		
		// info about file
    	$files = array();
    	
		// HTTP headers for no cache etc
		header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
		header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
		header("Cache-Control: no-store, no-cache, must-revalidate");
		header("Cache-Control: post-check=0, pre-check=0", false);
		header("Pragma: no-cache");
		
		// JSON header
		header('Content-Type: application/json');

		// Settings
		$files_path = trim($_POST['path']);
		$tinyMCE_type = trim($_POST['pathtype']);
		
		$targetDir = $this->AccessDir($files_path, $tinyMCE_type);
		if (!$targetDir) {
			die('{"jsonrpc" : "2.0", "error" : {"code": 102, "message": "Failed to open output stream."}, "id" : "id"}');
		}
				
		$cleanupTargetDir = true; // Remove old files
		$maxFileAge = 5 * 3600; // Temp file age in seconds

		// 5 minutes execution time
		@set_time_limit(5 * 60);

		// Get parameters
		$chunk = isset($_REQUEST["chunk"]) ? intval($_REQUEST["chunk"]) : 0;
		$chunks = isset($_REQUEST["chunks"]) ? intval($_REQUEST["chunks"]) : 0;
		$fileName = isset($_REQUEST["name"]) ? $_REQUEST["name"] : '';
		
		// Clean the fileName for security reasons
		// $fileName = $this->encodestring($filename)
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
		} else {
			die('{"jsonrpc" : "2.0", "error" : {"code": 100, "message": "Failed to open temp directory."}, "id" : "id"}');
		}

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

		$files[$fileName] = $this->getFileInfo($targetDir, $_POST['pathtype'], $fileName, $fileName);
		$this->updateDbFile($_POST['path'], $_POST['pathtype'], false, $files);

		// Return JSON-RPC response
		die('{"jsonrpc" : "2.0", "result" : null, "id" : "id"}');
		*/
	}
	
	/**
	 * Удалить файл, или несколько файлов 
	 */
	public function delfileAction()
	{
		$return = array();
		$files = $this->getRequest()->getParam('files');
		foreach ($files as $file) {
			$return[$file['filename']] = $this->DelFile($this->getRequest()->getParam('type'), $this->getRequest()->getParam('path'), $file['md5'], $file['filename']);
		}
		
		return $return;
	}
	
	public function delfolderAction()
	{	
		$realPath = $this->AccessDir($this->getRequest()->getParam('path'), $this->getRequest()->getParam('type'));
		if (!$realPath) {
			return false;
		}

		$result = array();
		$folder = ($this->getRequest()->getParam('type') == 'image') ? $this->_config['DIR_IMAGES'] : $this->_config['DIR_FILES'];
		if (realpath($realPath . '/') == realpath(DIR_ROOT . $folder . '/')) {
			$response = $this->getResponse();
			$response->setError( new Json_Server_Error("Root folder cannot be deleted!"));
			$response->sendResponse();
			exit(0);
		} else {

			$files = array();

			$dir = new DirectoryIterator($realPath);
			foreach ($dir as $file) {
				if ($file->isDir() && !$file->isDot() && ($file->getFilename() !== '.thumbs')) {
					$response = $this->getResponse();
					$response->setError( new Json_Server_Error("Folder cannot be delete while it has subfolders"));
					$response->sendResponse();
					exit(0);
				} elseif ($file->isFile() && (substr($this->getFilename(), 0, 1) !== '.')) {
					$files[] = $file;
				}	
			}

			// Delete all files in the .thumbs directory
			if (is_dir($realPath . DIRECTORY_SEPARATOR . '.thumbs')) {
				$dir = new DirectoryIterator($realPath . DIRECTORY_SEPARATOR . '.thumbs');
				foreach ($dir as $file) {
					if ($file->isFile()) {
						unlink($realPath . DIRECTORY_SEPARATOR . '.thumbs' . DIRECTORY_SEPARATOR . $file->getFilename());
					}
				}
				
				// If not unset will cause Permission denied errors
				// As the handle stays open
				unset($dir);
				unset($file);
				
				// remove the directory itself
				if(!@rmdir($realPath . DIRECTORY_SEPARATOR . '.thumbs')) {
					$last_error = error_get_last();
					$response = $this->getResponse();
					$response->setError( new Json_Server_Error("Cannot delete .thumbs directory\n\n" . $last_error['message']));
					$response->sendResponse();
					exit(0);
				}
			}
			
			// Now delete the main path files
			foreach ($files as $f) {
				unlink($realPath . DIRECTORY_SEPARATOR . $f);
			}

			if (!@rmdir($realPath)) {
				$response = $this->getResponse();
				$response->setError( new Json_Server_Error("Error deleting folder"));
				$response->sendResponse();
				exit(0);
			}
		}
		
		
		return "ok";
	}
	
	public function renamefileAction()
	{	
		$dir = $this->AccessDir($this->getRequest()->getParam('path'), $this->getRequest()->getParam('type'));
		if (!$dir) {
			return;
		}

		$filename = $this->getRequest()->getParam('filename');
		if( null === $filename ) {
			$response = $this->getResponse();
			$response->setError( new Json_Server_Error("Missing original filename"));
			$response->sendResponse();
			exit(0);
		}
		$filename = trim($filename);
		if (empty($filename)) {
			$response = $this->getResponse();
			$response->setError( new Json_Server_Error("Missing original filename"));
			$response->sendResponse();
			exit(0);
		}

		if (!is_dir($dir . '/.thumbs')) {
			$response = $this->getResponse();
			$response->setError( new Json_Server_Error("Directory .thumbs could not de found"));
			$response->sendResponse();
			exit(0);
		}


		$dbfile = $dir . '/.thumbs/.db';
		if (is_file($dbfile)) {
			$dbfilehandle = fopen($dbfile, "r");
			$dblength = filesize($dbfile);
			if ($dblength > 0) {
				$dbdata = fread($dbfilehandle, $dblength);
			}
			fclose($dbfilehandle);
		} else {
			$response = $this->getResponse();
			$response->setError( new Json_Server_Error("Missing database file"));
			$response->sendResponse();
			exit(0);
		}

		$files = unserialize($dbdata);

		foreach ($files as $file => $fdata) {
			if ($file == $filename) {
				$files[$file]['name'] = $this->getRequest()->getParam('newname');
				break;
			}
		}

		$dbfilehandle = fopen($dbfile, "w");
		fwrite($dbfilehandle, serialize($files));
		fclose($dbfilehandle);

		return "ok";
	}
	
	
	/**
	 * Проверка на разрешение записи в папку (не системное)
	 * Directory transversal checks
	 *
	 * @param string $requestDirectory Запрашиваемая папка (относительно DIR_IMAGES или DIR_FILES)
	 * @param (images|files) $typeDirectory Тип папки, изображения или файлы
	 * @return path|false
	 */
	protected function AccessDir($requestDirectory, $typeDirectory) {
		
		if (strcasecmp($typeDirectory, 'image') === 0) {
			$full_request_images_dir = realpath($this->dir['image'] . DIRECTORY_SEPARATOR . $requestDirectory);
			if (strpos($full_request_images_dir, $this->dir['image']) === 0) {
				return $full_request_images_dir;
			} else {
				$response = $this->getResponse();
				$response->setError( new Json_Server_Error("Possible directory traversal detected"));
				$response->sendResponse();
				exit(0);				
			}
		} elseif (strcasecmp($typeDirectory, 'file') === 0) {
			$full_request_files_dir = realpath($this->dir['file'] . DIRECTORY_SEPARATOR . $requestDirectory);
			if (strpos($full_request_files_dir, $this->dir['file']) === 0) {
				return $full_request_files_dir;
			} else {
				$response = $this->getResponse();
				$response->setError( new Json_Server_Error("Possible directory traversal detected"));
				$response->sendResponse();
				exit(0);
			}
		}
		
    	$response = $this->getResponse();
		$response->setError( new Json_Server_Error("Somehow the requested directory could not be located"));
		$response->sendResponse();
		exit(0);
	}


	/**
  	 * Дерево каталогов
	 * функция рекурсивная
	 * 
	 * The directory tree
	 * Recursive function
	 *
	 * @return array
	 */
	function Tree($beginFolder) {
		//if (!is_dir($beginFolder)) return false;
  	
		$struct = array();
    	
		// Remove the initial path
		$path = $beginFolder;
		if (strlen($beginFolder) >= strlen($this->dir['file'])) {
			if (strcmp(substr($beginFolder, 0, strlen($this->dir['file'])), $this->dir['file']) === 0) {
				$path = substr($beginFolder, strlen($this->dir['file']));				
			}
		}
		
		if (strcmp($path, $beginFolder) === 0) {
			if (strlen($beginFolder) >= strlen($this->dir['image'])) {
				if (strcmp(substr($beginFolder, 0, strlen($this->dir['image'])), $this->dir['image']) === 0) {
					$path = substr($beginFolder, strlen($this->dir['image']));				
				}
			}	
		}
		
		$struct[$beginFolder]['path'] = $path;
		$tmp = preg_split('[\\/]', $beginFolder);
		$tmp = array_filter($tmp);
		end($tmp);
		$struct[$beginFolder]['name'] = current($tmp);
		$struct[$beginFolder]['count'] = 0;

		if (!is_readable($beginFolder)) return $struct;
	
		$dir = new DirectoryIterator($beginFolder);
		foreach ($dir as $file) {
			if ((!$file->isDot()) && (substr($file->getFilename(),0, 1) !== ".")) {
				if ($file->isDir()) {
					$struct[$beginFolder]['childs'][] = $this->Tree($beginFolder . '/' . $file->getFilename());
				} else {
					$struct[$beginFolder]['count']++;
				}
			}	
		}
    
		asort($struct);
		return $struct;
	}

	/**
	 * Визуализация дерева каталогов
	 * функция рекурсивная
	 * 
	 * Visualization of the directory tree
	 * Recursive function
	 *
	 * @param images|files $type
	 * @param first|String $innerDirs
	 * @param String $currentDir
	 * @param int $level
	 * @return html
	 */
	function DirStructure($type, $innerDirs = 'first', $currentDir = '', $level = 0) {
		//Пока отключим файлы
		//if($type=='file') return ;

		$currentDirArr = array();
		if (!empty($currentDir)) {
			$currentDirArr = preg_split('/([\/\\\])/', str_replace($this->dir[$type], '', realpath($currentDir)));
			$currentDirArr = array_filter($currentDirArr);
		}

		if ($innerDirs == 'first') {
			$innerDirs = $this->Tree($this->dir[$type]);
			$firstAct = '';
			if (realpath($currentDir) == $this->dir[$type] && $this->firstAct) {
				$firstAct = 'folderAct';
				$this->firstAct = false;
			}
			$ret = '';
			if ($innerDirs == false) {
				$directory_name = $type == 'image' ? $this->config['DIR_IMAGES'] : $this->_config['DIR_FILES'];

				return 'Wrong root directory (' . $directory_name . ')<br>';
			}
			foreach ($innerDirs as $v) {
				#TODO: language dependent root folder name
				$ret = '<div class="folder folder' . ucfirst($type) . ' ' . $firstAct . '" path="" pathtype="' . $type . '">' . ($type == 'image' ? 'Images' : 'Files') . ($v['count'] > 0 ? ' (' . $v['count'] . ')' : '') . '</div><div class="folderOpenSection" style="display:block;">';
				if (isset($v['childs'])) {
					$ret .= $this->DirStructure($type, $v['childs'], $currentDir, $level);
				}
				break;
			}
			$ret .= '</div>';

			return $ret;
		}

		if (sizeof($innerDirs) == 0) {
			return false;
		}
		$ret = '';
		foreach ($innerDirs as $v) {
			foreach ($v as $v) {
			}
			if (isset($v['count'])) {
				$files = 'Файлов: ' . $v['count'];
				$count_childs = isset($v['childs']) ? sizeof($v['childs']) : 0;
				if ($count_childs != 0) {
					$files .= ', папок: ' . $count_childs;
				}
			} else {
				$files = '';
			}
			if (isset($v['childs'])) {
				$folderOpen = '';
				$folderAct = '';
				$folderClass = 'folderS';
				if (isset($currentDirArr[$level + 1])) {
					if ($currentDirArr[$level + 1] == $v['name']) {
						$folderOpen = 'style="display:block;"';
						$folderClass = 'folderOpened';
						if ($currentDirArr[sizeof($currentDirArr)] == $v['name'] && !$this->folderAct) {
							$folderAct = 'folderAct';
							$this->folderAct = true;
						} else {
							$folderAct = '';
						}
					}
				}
				$folderClass .= ' folder';
				$ret .= '<div class="' . $folderClass . ' ' . $folderAct . '" path="' . $v['path'] . '" title="' . $files . '" pathtype="' . $type . '">' . $v['name'] . ($v['count'] > 0 ? ' (' . $v['count'] . ')' : '') . '</div><div class="folderOpenSection" ' . $folderOpen . '>';
				$ret .= $this->DirStructure($type, $v['childs'], $currentDir, $level + 1);
				$ret .= '</div>';
			} else {
				$folderAct = '';
				$soc = count($currentDirArr);
				if ($soc > 0 && $currentDirArr[$soc] == $v['name']) {
					$folderAct = 'folderAct';
				}
				$ret .= '<div class="folder folderClosed ' . $folderAct . '" path="' . $v['path'] . '" title="' . $files . '" pathtype="' . $type . '">' . $v['name'] . ($v['count'] > 0 ? ' (' . $v['count'] . ')' : '') . '</div>';
			}
		}

		return $ret;
	}

	/**
	 * Путь (хлебные крошки)
	 *
	 * @param images|files $type
	 * @param String $path
	 * @return html
	 */
	function DirPath($type, $path = '') {

		if (!empty($path)) {
			$path = preg_split('/([\/\\\])/', str_replace($this->dir[$type], '', realpath($path)));
			$path = array_filter($path);
		}

		$ret = '<div class="addrItem" path="" pathtype="' . $type . '" title=""><img src="img/' . ($type == 'image' ? 'folder_open_image' : 'folder_open_document') . '.png" width="16" height="16" alt="Корневая директория" /></div>';
		$i = 0;
		$addPath = '';
		if (is_array($path)) {
			foreach ($path as $v) {
				$i++;
				$addPath .= '/' . $v;
				if (sizeof($path) == $i) {
					$ret .= '<div class="addrItemEnd" path="' . $addPath . '" pathtype="' . $type . '" title=""><div>' . $v . '</div></div>';
				} else {
					$ret .= '<div class="addrItem" path="' . $addPath . '" pathtype="' . $type . '" title=""><div>' . $v . '</div></div>';
				}
			}
		}

		return $ret;
	}


	function CallDir($dir, $type, $page) {

		$files = $this->updateDbFile($dir, $type, true);
		if ($files) {
			$this->total_pages = ceil(count($files) / FILES_PER_PAGE);
			$startFile = ($page - 1) * FILES_PER_PAGE;

			return array_slice($files, $startFile, FILES_PER_PAGE);
		} else {
			return false;
		}
	}

	/**
	 * Not used.
	 * 
	 * Just an alias of updateDBFile.
	 * 
	 * @param string $dir
	 * @param string $type
	 */
	function getFileList($dir, $type) {
		return $this->updateDbFile($dir, $type, true);
	}

	/**
	 * Not used.
	 * 
	 * Just an alias of updateDBFile.
	 * 
	 * @param string $dir
	 * @param string $type
	 */
	function addFilesInfo($dir, $type, $data) {
		return $this->updateDbFile($dir, $type, false, $data);
	}

	function updateDbFile($inputDir, $type, $return, $newData = array()) {
		$dir = $this->AccessDir($inputDir, $type);
		if (!$dir) {
			return false;
		}

		if (!ini_get('safe_mode')) {
			set_time_limit(120);
		}

		if (!is_dir($dir . '/.thumbs')) {
			mkdir($dir . '/.thumbs');
		}

		$dbfile = $dir . '/.thumbs/.db';


		if (is_file($dbfile)) {
			$dbfilehandle = fopen($dbfile, "r");
			$dblength = filesize($dbfile);
			if ($dblength > 0) {
				$dbdata = fread($dbfilehandle, $dblength);
			}
			fclose($dbfilehandle);
		}
		if (!empty($dbdata)) {
			$files = unserialize($dbdata);

			// test if files were deleted
			foreach ($files as $file) {
				if (!is_file($dir . '/' . $file['filename'])) {
					// delete file from .db
					$this->DelFile($type, $inputDir, $file['md5'], $file['filename']);
					// and don't show it now
					unset($files[$file['filename']]);
				}
			}
		} else {
			$files = array();
		}

		$newFiles = 0;
    
		$dirIterator = new DirectoryIterator($dir);
		foreach ($dirIterator as $file) {
			if ($file->isFile() && (substr($file->getFilename(),0, 1) !== ".") && !isset($files[$file->getFilename()])) {
				if (!empty($newData[$file->getFilename()])) {
					$files[$file->getFilename()] = $newData[$file->getFilename()];
				} else {
					$files[$file->getFilename()] = $this->getFileInfo($dir, $type, $file->getFilename());
				}
				$newFiles++;
			}	
		}
		
		// if there are new files in directory, re-sort and resave .db file
		if ($newFiles > 0) {
			//$this->sortFiles($files);
			uasort($files, array($this, 'cmp_date_name'));
			// save the file
			$dbfilehandle = fopen($dbfile, "w");
			fwrite($dbfilehandle, serialize($files));
			fclose($dbfilehandle);
		}

		if ($return) {
			return $files;
		} else {
			return true;
		}
	}

	function getFileInfo($dir, $type, $file, $realname = '') {
		$fileFullPath = $dir . '/' . $file;
		$file_info = pathinfo($fileFullPath);
		$file_info['extension'] = strtolower($file_info['extension']);

		$link = str_replace(array( '/\\', '//', '\\\\', '\\'
                        ), DS, DS . str_replace(realpath(DIR_ROOT), '', realpath($fileFullPath)));
		$path = pathinfo($link);
		$link = $this->http_root . $link;


		// проверяем размер загруженного изображения (только для загруженных в папку изображений)
		// и уменьшаем его
		if ($type == 'image' && in_array(strtolower($file_info['extension']), $this->_config['ALLOWED_IMAGES'])) {
			$maxWidth = MAX_WIDTH ? MAX_WIDTH : '100%';
			$maxHeight = MAX_HEIGHT ? MAX_HEIGHT : '100%';
			try {
				WideImage::load($fileFullPath)->resizeDown($maxWidth, $maxHeight)->saveToFile($fileFullPath);
				$fileImageInfo = getimagesize($fileFullPath);
			} catch (WideImage_InvalidImageSourceException $e) {
				$e->getMessage();
			}
		}
		$files[$file] = array( 'filename' => $file,
		                       'name' => $realname ? $realname : basename(mb_strtolower($file_info['basename']), '.' . $file_info['extension']),
		                       'ext' => $file_info['extension'], 'path' => $path['dirname'], 'link' => $link,
		                       'size' => filesize($fileFullPath), 'date' => filemtime($fileFullPath),
		                       'width' => !empty($fileImageInfo[0]) ? $fileImageInfo[0] : 'N/A',
		                       'height' => !empty($fileImageInfo[1]) ? $fileImageInfo[1] : 'N/A',
		                       'md5' => md5_file($fileFullPath)
		);

		return $files[$file];
	}

	/**
	 * Used to sort files by date
	 * 
	 * @param unknown_type $a
	 * @param unknown_type $b
	 */
	private function cmp_date_name($a, $b)
	{
		$r1 = strcmp($a['date'], $b['date']) * (-1);

		return ($r1 == 0) ? strcmp($a['filename'], $b['filename']) : $r1;
	}

	function bytes_to_str($bytes) {
		$d = '';
		if ($bytes >= 1048576) {
			$num = $bytes / 1048576;
			$d = 'Mb';
		} elseif ($bytes >= 1024) {
			$num = $bytes / 1024;
			$d = 'kb';
		} else {
			$num = $bytes;
			$d = 'b';
		}

		return number_format($num, 2, ',', ' ') . $d;
	}


  function ShowDir($inputDir, $type, $page) {

    $dir = $this->CallDir($inputDir, $type, $page);

    if (!is_array($dir)) {
      $dir = $this->CallDir($inputDir, $type, 1);
    }
    //    if (!is_array($dir)) {
    //      $dir = $this->CallDir('', $type, 1);
    //    }

    if (!is_array($dir)) {
      return '';
    }

    $ret = '';
    foreach ($dir as $v) {
      $thumb = $this->GetThumb($v['path'], $v['md5'], $v['filename'], 2, 100, 100);
      if ((WIDTH_TO_LINK > 0 && $v['width'] > WIDTH_TO_LINK) || (HEIGHT_TO_LINK > 0 && $v['height'] > HEIGHT_TO_LINK)
      ) {
        $middle_thumb = $this->GetThumb($v['path'], $v['md5'], $v['filename'], 0, WIDTH_TO_LINK, HEIGHT_TO_LINK);
        list($middle_width, $middle_height) = getimagesize($middle_thumb);
        $middle_thumb_attr = 'fmiddle="' . $middle_thumb . '" fmiddlewidth="' . $middle_width . '" fmiddleheight="' . $middle_height . '" fclass="' . CLASS_LINK . '" frel="' . REL_LINK . '"';
      } else {
        $middle_thumb = '';
        $middle_thumb_attr = '';
      }

      $img_params = '';
      $div_params = '';

      if ($type == 'file' || in_array($v['ext'], $this->_config['ALLOWED_FILES'])) {
        $img_params = '';
        //        $div_params = 'style="width: 100px; height: 100px; padding-top: 16px;"';
        $div_params = 'fileIcon';
      }

      $filename = $v['name'];

      if (mb_strlen($filename) > 30) {
        $filename = mb_substr($filename, 0, 25, 'UTF-8') . '...';
      }

      $ret .= '<div class="imageBlock0" filename="' . $v['filename'] . '" fname="' . $v['name'] . '" type="' . $type . '" ext="' . $v['ext'] . '" path="' . $v['path'] . '" linkto="' . $v['link'] . '" fsize="' . $v['size'] . '" fsizetext="' . $this->bytes_to_str($v['size']) . '" date="' . date('d.m.Y H:i', $v['date']) . '" fwidth="' . $v['width'] . '" fheight="' . $v['height'] . '" md5="' . $v['md5'] . '" ' . $middle_thumb_attr . '><div class="imageBlock1"  title="' . $v['name'] . '"><div class="imageImage ' . $div_params . '"><img src="' . $thumb . '" ' . $img_params . ' alt="' . $v['name'] . '" /></div><div class="imageName">' . $filename . '</div></div></div>';
    }

    return $ret;
  }

	function showPages($path, $type, $activePage) {
		$result = '';
		if ($this->total_pages > 1) {
			$result .= '<ul>';
			for ($i = 1; $i <= $this->total_pages; $i++) {
				$class = '';
				if ($i == $activePage) {
					$class = ' class="active"';
				}
				$result .= '<li' . $class . '><a href="#" pathtype="' . $type . '" path="' . $path . '" data-page="' . $i . '">' . $i . '</a></li> ';
			}
			$result .= '</ul>';
		}

		return $result;
	}


	function GetThumb($dir, $md5, $filename, $mode, $width = 100, $height = 100) {
		$path = realpath(DIR_ROOT . DS . $dir);
		$ext = explode('.', $filename);
		$ext = strtolower(end($ext)); // filename extention
		$thumbFilename = DS . '.thumbs' . DS . $md5 . '_' . $width . '_' . $height . '_' . $mode . '.' . $ext;

    	// if thumb already exists
		if (is_file($path . $thumbFilename)) {
			return $this->http_root . $dir . $thumbFilename;
		} else {
			// if not an image or we are in 'file' folder
			if (in_array($ext, $this->_config['ALLOWED_IMAGES']) && strpos($dir, $this->_config['DIR_IMAGES']) === 0) {
				//if it's an image, create thumb
				try {
					// if no width or height specified
					$width = $width ? $width : null;
					$height = $height ? $height : null;

					$thumb = WideImage::load($path . '/' . $filename)->resizeDown($width, $height);

					if ($mode == 2) { // if generating small thumb for imageManager inner use - make it exactly 100x100 with white background
						$thumb = $thumb->resizeCanvas($width, $height, 'center', 'center', 0x00FFFFFF);
					}
					$thumb-> //				roundCorners(20,0x00FFFFFF,4)->
						saveToFile($path . $thumbFilename);
					// clear some memory
					unset($thumb);

					return $this->http_root . $dir . $thumbFilename;
				} catch (WideImage_InvalidImageSourceException $e) {
					$e->getMessage();
				}
			}
		}

		// get path to img/fileicons folder
		$server_url = rtrim(dirname(__FILE__), '/') . '/../../';
		$server_url = realpath($server_url);
		$server_url = rtrim($server_url, '/') . '/img/fileicons/';
		$url = $this->http_root . substr($server_url, strlen(DIR_ROOT));

		// show the file-type icon
		if (!empty($ext) && file_exists($server_url . $ext . '.png')) {
			return $url . $ext . '.png';
		} else {
			return $url . 'none.png';
		}
	}
	

	function DelFile($pathtype, $path, $md5, $filename) {
		$path = $this->AccessDir($path, $pathtype);
		if (!$path) {
			return false;
		}

		if (is_dir($path . '/.thumbs')) {
			if ($pathtype == 'image') {
				$handle = opendir($path . '/.thumbs');
				if ($handle) {
					while (false !== ($file = readdir($handle))) {
						if ($file != "." && $file != "..") {
							if (substr($file, 0, 32) == $md5) {
								unlink($path . '/.thumbs/' . $file);
							}
						}
					}
				}
			}

			$dbfile = $path . '/.thumbs/.db';
			if (is_file($dbfile)) {
				$dbfilehandle = fopen($dbfile, "r");
				$dblength = filesize($dbfile);
				if ($dblength > 0) {
					$dbdata = fread($dbfilehandle, $dblength);
				}
				fclose($dbfilehandle);
				$dbfilehandle = fopen($dbfile, "w");
			} else {
				$dbfilehandle = fopen($dbfile, "w");
			}


			if (isset($dbdata)) {
				$files = unserialize($dbdata);
			} else {
				$files = array();
			}

			unset($files[$filename]);

			fwrite($dbfilehandle, serialize($files));
			fclose($dbfilehandle);
		}

		if (is_file($path . '/' . $filename)) {
			if (unlink($path . '/' . $filename)) {
				return true;
			}
		}

		return false;
	}


	function translit($string) {
		$cyr = array( "А", "Б", "В", "Г", "Д", "Е", "Ё", "Ж", "З", "И", "Й", "К", "Л", "М", "Н", "О", "П", "Р", "С", "Т",
		          "У", "Ф", "Х", "Ц", "Ч", "Ш", "Щ", "Ъ", "Ы", "Ь", "Э", "Ю", "Я", "а", "б", "в", "г", "д", "е", "ё",
		          "ж", "з", "и", "й", "к", "л", "м", "н", "о", "п", "р", "с", "т", "у", "ф", "х", "ц", "ч", "ш", "щ",
		          "ъ", "ы", "ь", "э", "ю", "я"
		);
		$lat = array( "A", "B", "V", "G", "D", "E", "YO", "ZH", "Z", "I", "Y", "K", "L", "M", "N", "O", "P", "R", "S", "T",
		          "U", "F", "H", "TS", "CH", "SH", "SHCH", "", "YI", "", "E", "YU", "YA", "a", "b", "v", "g", "d", "e",
		          "yo", "zh", "z", "i", "y", "k", "l", "m", "n", "o", "p", "r", "s", "t", "u", "f", "h", "ts", "ch",
		          "sh", "shch", "", "yi", "", "e", "yu", "ya"
		);
		/*for ($i = 0; $i < count($cyr); $i++) {
			$c_cyr = $cyr[$i];
			$c_lat = $lat[$i];
		}*/
		$string = str_replace($cyr, $lat, $string);

		$string = preg_replace("/([qwrtpsdfghklzxcvbnmQWRTPSDFGHKLZXCVBNM]+)[jJ]e/", "\${1}e", $string);
		$string = preg_replace("/([qwrtpsdfghklzxcvbnmQWRTPSDFGHKLZXCVBNM]+)[jJ]/", "\${1}'", $string);
		$string = preg_replace("/([eyuioaEYUIOA]+)[Kk]h/", "\${1}h", $string);
		$string = preg_replace("/^kh/", "h", $string);
		$string = preg_replace("/^Kh/", "H", $string);

		if (function_exists('iconv')) {
			$string = iconv('UTF-8', 'ASCII//TRANSLIT', $string);
		}
		return $string;
	}

	function encodestring($string) {
		$string = str_replace(array( " ", '"', "&", "<", ">" ), ' ', $string);
		$string = preg_replace("/[_\s,?!\[\](){}]+/", "_", $string);
		$string = preg_replace("/-{2,}/", "-", $string);
		$string = preg_replace("/\.{2,}/", ".", $string);
		$string = preg_replace("/_-+_/", '-', $string);
		$string = preg_replace("/[_\-]+$/", '', $string);
		$string = $this->translit($string);
		$string = preg_replace("/j{2,}/", "j", $string);
		$string = preg_replace("/[^0-9A-Za-z_\-\.]+/", "", $string);

		return $string;
	}

	public function getRequest()
	{
		return $this->_request;
	}
}

$letsGo = new TinyImageManager();

?>