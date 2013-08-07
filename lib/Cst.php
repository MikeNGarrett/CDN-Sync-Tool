<?php
/**
 * Core CST class
 *
 * Class that contains all of the methods needed to create connections, push files, etc.
 *
 * @author Ollie Armstrong
 * @package CST
 * @copyright All rights reserved 2011
 * @license GNU GPLv2
 */

function getmicrotime() {
  list($usec, $sec)=explode(" ", microtime());

  return ((float)$usec + (float)$sec);
}

class Cst {
	protected $cdnConnection, $connectionType, $fileTypes, $ftpHome;

	private $_uploadDirArray = null; 
	private $_uploadBasedir = null; 
	private $_uploadBaseurl = null;
	private $_uploadRelurl = null;
	private $_uploadPath = null;

	private function getUploadDirArray() {
		if( !$this->_uploadDirArray ) {
			$this->_uploadDirArray = wp_upload_dir();
		}

		return $this->_uploadDirArray;
	}

	private function getUploadBasedir() {
		if( !$this->_uploadBasedir ) {
			$tempDir = $this->getUploadDirArray();
			$this->_uploadBasedir = $tempDir['basedir'];
		}

		return $this->_uploadBasedir;
	}

	private function getUploadBaseurl() {
		if( !$this->_uploadBaseurl ) {
			$tempDir = $this->getUploadDirArray();
			$this->_uploadBaseurl = $tempDir['baseurl'];
		}

		return $this->_uploadBaseurl;
	}

	private function getUploadRelurl() {
		if( !$this->_uploadRelurl ) {
			$tempUploadBaseurl = $this->getUploadBaseurl();
			$this->_uploadRelurl = ltrim(str_replace(get_bloginfo('url'),'',$tempUploadBaseurl),'/');
		}

		return $this->_uploadRelurl;
	}

	private function getUploadPath() {
		if( !$this->_uploadPath ) {
			$tempDir = $this->getUploadDirArray();
			$this->_uploadPath = $tempDir['path'];
		}

		return $this->_uploadPath;
	}

	function __construct() {
		$this->connectionType = get_option('cst-cdn');
		add_action('admin_menu', array($this, 'createPages'));

		// Create nonce
		add_action('init', array($this, 'createNonce'));

		// Enqueue files
		add_action('admin_init', array($this, 'enqueueFiles'));

		add_action( 'wp_handle_upload', array( $this, 'wp_handle_upload' ), 20, 2 );

		// Add action for image uploads
		add_action('wp_update_attachment_metadata', array($this, 'uploadMedia'));
	}

	public function wp_handle_upload( $data, $type ) {
		$check_type = explode('/', $data['type']);
	    if ( $check_type[0] != 'image' ) {
	        $this->_file = $data['file'];
	    } 
	    return $data;
	}

	/**
	 * Initialises the connection to the CDN
	 * 
	 */
	public function createConnection() {
		require_once CST_DIR.'lib/pages/Options.php';
		if ($this->connectionType == 'S3') {
			require_once CST_DIR.'lib/api/S3.php';
			$awsAccessKey = get_option('cst-s3-accesskey');
			$awsSecretKey = get_option('cst-s3-secretkey');
			$this->cdnConnection = new S3($awsAccessKey, $awsSecretKey);
			if (@$this->cdnConnection->listBuckets() === false) {
				CST_page::$messages[] = 'S3 connection error, please check details';
			}
		} else if ($this->connectionType == 'FTP') {
			if (get_option('cst-ftp-sftp') == 'yes') {
				$connection = @ssh2_connect(get_option('cst-ftp-server'), get_option('cst-ftp-port'));
				if ($connection === false) {
					CST_Page::$messages[] = 'SFTP connection error, please check details.';
				} else {
					if (@ssh2_auth_password($connection, get_option('cst-ftp-username'), get_option('cst-ftp-password'))) {
						$this->cdnConnection = $connection;
					} else {
						CST_Page::$messages[] = 'SFTP username/password authentication failed, please check details.';
					}
				}
			} else {
				$this->cdnConnection = ftp_connect(get_option('cst-ftp-server'), get_option('cst-ftp-port'), 30);
				if ($this->cdnConnection === false) {
					CST_Page::$messages[] = 'FTP connection error, please check details.';
				} else {
					if (ftp_login($this->cdnConnection, get_option('cst-ftp-username'), get_option('cst-ftp-password')) === false) {
						CST_Page::$messages[] = 'FTP login error, please check details.';
					}
					$this->ftpHome = ftp_pwd($this->cdnConnection);
				}
			}
		} else if ($this->connectionType == 'Cloudfiles') {
			require_once CST_DIR.'/lib/api/cloudfiles.php';
			try {
				if (get_option('cst-cf-region') == 'uk') {
					$region = UK_AUTHURL;
				} else {
					$region = US_AUTHURL;
				}
				$cfAuth = new CF_Authentication(get_option('cst-cf-username'), get_option('cst-cf-api'), NULL, $region);
				$cfAuth->authenticate();
				$this->cdnConnection = new CF_Connection($cfAuth);
				$this->cdnConnection = $this->cdnConnection->create_container(get_option('cst-cf-container'));
			} catch (Exception $e) {
				CST_Page::$messages[] = 'Cloudfiles connection error, please check details.';
			}
		} else if ($this->connectionType == 'WebDAV') {
			require_once CST_DIR.'lib/api/webdav/Sabre/autoload.php';
			$settings = array(
				'baseUri' => get_option('cst-webdav-host'),
				'userName' => get_option('cst-webdav-username'),
				'password' => get_option('cst-webdav-password'),
			);
			$client = new Sabre_DAV_Client($settings);
			$response = $client->request('GET');
			if ($response['statusCode'] >= 400) {
				CST_Page::$messages[] = 'WebDAV connection error, server responded with code '.$response['statusCode'].'.';
			}
			$this->cdnConnection = $client;
		}
	}

	/**
	 * Pushes a file to the CDN
	 * 
	 * @param $file string path to the file to push
	 * @param $remotePath string path to the remote file
	 */
	public function pushFile($file, $remotePath) {
		if ($this->connectionType == 'S3') {
			// Puts a file to the bucket
			// putObjectFile(localName, bucketName, remoteName, ACL)
			$bucketName = get_option('cst-s3-bucket');
			$buckets = $this->cdnConnection->listBuckets();
			if (!in_array($bucketName, $buckets)) {
				$this->cdnConnection->putBucket($bucketName);
			}
			$this->cdnConnection->putObjectFile($file, $bucketName, $remotePath, S3::ACL_PUBLIC_READ);
		} else if ($this->connectionType == 'FTP') {
			if (get_option('cst-ftp-sftp') == 'yes') {
				// Create directory for the file
				$pathParts = explode('/', $remotePath);
				$fileName = array_pop($pathParts);
				$remoteDirectory = implode('/', $pathParts);
				ssh2_sftp_mkdir(ssh2_sftp($this->cdnConnection), get_option('cst-ftp-dir').'/'.$remoteDirectory, 0777, true);

				ssh2_scp_send($this->cdnConnection, $file, get_option('cst-ftp-dir').'/'.$remotePath);
			} else {
				$initDir = get_option('cst-ftp-dir');
				if ($initDir[0] != '/') {
					update_option('cst-ftp-dir', '/'.$initDir);
					$initDir = get_option('cst-ftp-dir');
				}
				// Creates the directories
				ftp_chdir($this->cdnConnection, $this->ftpHome.$initDir);
				$remotePathExploded = explode('/', $remotePath);
				$filename = array_pop($remotePathExploded);
				foreach($remotePathExploded as $dir) {
					$rawlist = ftp_rawlist($this->cdnConnection, $dir);
					if (empty($rawlist)) {
						ftp_mkdir($this->cdnConnection, $dir);
					}
					ftp_chdir($this->cdnConnection, $dir);
				}
				// Uploads files
				ftp_put($this->cdnConnection, $filename, $file, FTP_ASCII);
			}
		} else if ($this->connectionType == 'Cloudfiles') {
			require CST_DIR.'etc/mime.php';

			$object = $this->cdnConnection->create_object($remotePath);

			if (!$object) echo 'Debug: no object was created via cdnConnection create_object method.';
			$extension = pathinfo($file, PATHINFO_EXTENSION);
			$object->content_type = $mime_types[$extension];
			try {
				$result = $object->load_from_filename($file);
			} catch (Exception $e) {
				echo 'Load from Filename error: ' . $e->getMessage();
				throw $e; // throw error to ensure DB is not updated
			}
			if (!$object) echo 'Debug: no object, but still made it to end of pushFile method.';
		} else if ($this->connectionType == 'WebDAV') {
			// Ensure directory exists, create it otherwise
			$remotePathExploded = explode('/', $remotePath);
			$filename = array_pop($remotePathExploded);
			$currentPath = '';
			foreach ($remotePathExploded as $path) {
				try {
					$response = $this->cdnConnection->request('MKCOL', get_option('cst-webdav-basedir').'/'.$currentPath.'/'.$path);
				} catch (Exception $e) {
					echo 'An error occured while attempting to sync to WebDAV. Please report this to <a href="http://github.com/fubralimited/CDN-Sync-Tool/issues">GitHub</a>';
					var_dump($e);
					var_dump($response);
					exit;
				}
				$currentPath .= '/'.$path;
			}
			$this->cdnConnection->request('PUT', get_option('cst-webdav-basedir').'/'.$remotePath, file_get_contents($file));
		}
	}

	/**
	 * Sends $file to Google Closure Compiler
	 * 
	 * @param $file string absolute path to file to be minified
	 * @return $response string the resulting minified code or an error
	 */
	private function minifyFile($file) {
		$js = file_get_contents($file);
		$data = 'output_info=compiled_code&js_code='.$js;
		$url = 'http://closure-compiler.appspot.com/compile';
		$optional_headers = NULL;
		$params = array('http' => array(
              'method' => 'POST',
              'content' => $data
            ));
		if ($optional_headers !== null) {
			$params['http']['header'] = $optional_headers;
		}
		$ctx = stream_context_create($params);
		$fp = @fopen($url, 'rb', false, $ctx);
		if (!$fp) {
			throw new Exception("Problem with $url, $php_errormsg");
		}
		$response = @stream_get_contents($fp);
		if ($response === false) {
			throw new Exception("Problem reading data from $url, $php_errormsg");
		}
		return $response;
	}

	/**
	 * Checks if the file is in the excluded files array
	 * 
	 * @param $type string either 'js' or 'css'
	 * @param $file string path of file relative to site root
	 * @return boolean
	 */
	private function checkIfExcluded($type, $file) {
		$excludedFiles = get_option('cst-'.$type.'-exclude');
		$excludedFiles = explode(",", $excludedFiles);
		foreach ($excludedFiles as &$excludedFile) {
			$excludedFile = ABSPATH . $excludedFile;
		}
		if (in_array($file, $excludedFiles)) {
			return true;
		} else {
			return false;
		}
	}

	/**
	 * Checks if the file is in an ignored path
	 * 
	 * @param $file string path of file relative to site root
	 * @return boolean
	 */
	private function checkIfIgnoredPath($path) {
		$ignorePaths = get_option('cst-ignore-paths');
		foreach ($ignorePaths as &$ignorePath) {
			if ($ignorePath) {
				// for each ignore path
				$ignorePath = ABSPATH . $ignorePath;
				if (stristr($path, $ignorePath)) {
					// if the ignore path can be found within the passed path, return true (is ignored path)
					return true;
				}
			}
		}
		// otherwise return false (is not an ignored path)
		return false;
	}

	/**
 	* Concatenates the passed files and saves to specified file
 	* 
 	* @param $files array of file paths to combine
 	* @param $type string file extension
 	* @param $savePath string path to folder of where to save the combined file
 	*/
	private function combineFiles($files, $type, $savePath) {
		$savePath = ABSPATH.$savePath.'/cst-combined.'.$type;
		if (file_exists($savePath)) {
			unlink($savePath);
		}
		foreach ($files as $file) {
			if (pathinfo($file, PATHINFO_EXTENSION) == $type && !self::checkIfExcluded($type, $file)) {
				file_put_contents($savePath, file_get_contents($file)."\r\n", FILE_APPEND);
			}
		}
	}

	/**
	 * Finds all the files that need syncing and add to database
	 * 
	 */
	private function findFiles() {
		$files = array();
		if (isset($_POST['cst-options']['syncfiles']['cssjs']))
			$files[] = get_stylesheet_directory();
		if (isset($_POST['cst-options']['syncfiles']['theme']))
			$files[] = get_template_directory();
		if (isset($_POST['cst-options']['syncfiles']['wp']))
			$files[] = ABSPATH.'wp-includes';
		if (isset($_POST['cst-options']['syncfiles']['media'])) {
			$files[] = $this->getUploadBasedir(); // add the base upload directory
		}
		if (isset($_POST['cst-options']['syncfiles']['plugin'])) {
			$activePlugins = $this->getActivePlugins();
			foreach ( $activePlugins as $i => $plugin ) {
				$dirname = dirname(WP_PLUGIN_DIR."/".$plugin);
				// echo 'Plugin ' . $i . ': ' . $dirname . '<br />';
				if ($dirname != dirname(WP_PLUGIN_DIR."/plugins")) {
					// echo 'Adding to queue <br />';
					$files[] = $dirname;
				}
			}
		}

		$files = $this->getDirectoryFiles($files);

		// Combine files if required
		if (get_option('cst-js-combine') == 'yes') {
			$this->combineFiles($files, 'js', get_option('cst-js-savepath'));
		}

		if (get_option('cst-css-combine') == 'yes') {
			$this->combineFiles($files, 'css', get_option('cst-css-savepath'));
		}

		if (get_option('cst-js-combine') == 'yes' || get_option('cst-css-combine') == 'yes') {
			if (get_option('cst-css-combine') != get_option('cst-js-combine')) {
				$combinedCssJs[] = ABSPATH.get_option('cst-js-savepath');
				$combinedCssJs[] = ABSPATH.get_option('cst-css-savepath');
			} else {
				$combinedCssJs[] = ABSPATH.get_option('cst-js-savepath');
			}
			$combinedCssJs = $this->getDirectoryFiles($combinedCssJs);
			if (isset($combinedCssJs) && !empty($combinedCssJs))
				$files = array_merge($files, $combinedCssJs);
		}
		// if (isset($_POST['cst-options']['syncfiles']['media'])) {
		// 	$files = array_merge($files, $mediaFiles);
		// }

		if (get_option('cst-js-minify') == 'yes') {
			if (get_option('cst-js-combine') == 'yes' && file_exists(ABSPATH.get_option('cst-js-savepath').'/cst-combined.js')) {
				// If JS is combined then only bother minifying that one
				$this->minifyFile(ABSPATH.get_option('cst-js-savepath').'/cst-combined.js');
			} else {
				foreach ($files as $file) {
					if (pathinfo($file, PATHINFO_EXTENSION) == 'js' && !self::checkIfExcluded('js', $file)) {
						file_put_contents(ABSPATH.get_option('cst-js-savepath').'/'.pathinfo($file, PATHINFO_FILENAME).'.min.js', $this->minifyFile($file));
						$files[] = ABSPATH.get_option('cst-js-savepath').'/'.pathinfo($file, PATHINFO_FILENAME).'.min.js';
					}
				}
			}
		}
		self::_addFilesToDb($files);
	}

	/**
	 * Get all active plugins
	 */
	public static function getActivePlugins(){
		
		global $wpdb;
		$activePlugins = (is_array(get_site_option("active_sitewide_plugins")) === true) ? array_keys(get_site_option("active_sitewide_plugins")) : array();
		$activePlugins = array_merge( $activePlugins , get_option("active_plugins") );	
		$activePlugins = array_merge( $activePlugins , array_keys(get_mu_plugins()));	
		
		return $activePlugins;
	}

	/**
	 * Using the path of a given file, generate the remote path
	 * 
	 * @param $file string for file path
	 */
	private function generateRemotePath($file) {
		// retrieve pointers to the upload directory path and the relative upload url
		$uploadDirArray = $this->getUploadDirArray();
		$uploadBasedir = $this->getUploadBasedir();
		$uploadBaseurl = $this->getUploadBaseurl();
		$uploadRelurl = $this->getUploadRelurl();

		if (stristr($file, $uploadBasedir)) {
			$remotePath = preg_split('$'.$uploadBasedir.'$', $file);
			$remotePath = $uploadRelurl.$remotePath[1];
		} else if (stristr($file, 'wp-content')) {
			$remotePath = preg_split('$wp-content$', $file);
			$remotePath = 'wp-content'.$remotePath[1];
		} else if (stristr($file, 'wp-includes')) {
			$remotePath = preg_split('$wp-includes$', $file);
			$remotePath = 'wp-includes'.$remotePath[1];
		} else if (stristr($file, ABSPATH)) {
			$remotePath = preg_split('$'.ABSPATH.'$', $file);
			$remotePath = $remotePath[1];
		}
		return $remotePath;
	}

	/**
	 * Adds the files to the database
	 * 
	 * @param $files array of file paths
	 */
	private function _addFilesToDb($files, $forceSynced = null) {
		global $wpdb;

		$arrRemoteFiles = array();
		$arrFileMods = array();
		foreach($files as $file) {
			// $file = realpath($file);


			if( file_exists($file) ){
				// determine the relative path for uploads so we can identify them
				$arrRemoteFiles[] = $this->generateRemotePath($file);

				$arrFileMods[$file] = filemtime($file);
			}
		}
		$arrResults = $wpdb->get_results("SELECT * FROM `".CST_TABLE_FILES."` WHERE `remote_path` in('". implode("','", $arrRemoteFiles) ."')");

		// add in check to make sure files exist.

		$strCompare = '';

		// --- 
		$arrListToUpdate = array();
		foreach($arrResults as $row) {
			$remotePath = $row->remote_path;
			$file = str_replace('//wp-content', '/wp-content', $row->file_dir);
			$changedate = 0;
			if(isset($arrFileMods[$file])) {
				$changedate = $arrFileMods[$file];
			}

			$strCompare .= $file ."\n";

			// remove file from a total list of files because we know that is was returned by the select query results $arrResults
			unset( $arrFileMods[$file] );

			// Check to see if we want to add thie file to the list of file to be updated
				if(($changedate >= $row->changedate) || (isset($_POST['cst-options']['syncall']) && $row != NULL)) {
					$arrListToUpdate[] = $row->id;
				}
		}

		$strDebugPath = ABSPATH .'/wp-content/cache/debug_cst2.txt';
		touch($strDebugPath);

		// if the array results stored in $arrListToUpdate is NOT empty then build an update query with file list.
		$strPrepUpdate = '';
		if( !empty($arrListToUpdate) ) {
				$strPrepUpdate = $wpdb->prepare("
					UPDATE `".CST_TABLE_FILES."` 
					SET 
					  changedate='". time() ."', 
					  synced ='". (!$forceSynced ? '0' : '1') ."' 
					WHERE 
						id in('". implode("','", $arrListToUpdate) ."')",
				  null
			  );

			$wpdb->query($strPrepUpdate);
		}

		$strInsertQuery = '';
		if( !empty($arrFileMods) ) {
			$strInsertQuery = "INSERT INTO ". CST_TABLE_FILES ." (file_dir, remote_path, changedate, synced) VALUES ";

			foreach($arrFileMods as $file => $dtmFileMod) {
				$remotePath = $this->generateRemotePath($file);
				// $file = &$row->file_dir;
				$changedate = $dtmFileMod;

				$strInsertQuery .= "('". $file ."', '". $remotePath  ."', '". time()  ."', '". (!$forceSynced ? '0' : '1') ."'), ";
			}

			$strInsertQuery = trim($strInsertQuery, ', ');

			$wpdb->query( $wpdb->prepare($strInsertQuery) );
		}

		// file_put_contents($strDebugPath, print_r(
		// 	array(
		// 		'_POST' => $_POST,
		// 		'strCompare' => $strCompare, 
		// 		'arrResults' => $arrResults, 
		// 		'arrListToUpdate' => $arrListToUpdate,
		// 		'arrFileMods' => $arrFileMods, 
		// 		'strPrepUpdate' => $strPrepUpdate, 
		// 		'strInsertQuery' => $strInsertQuery
		// 	), 
		// 	true
		// ));

	}

	/**
	 * Gets all required files
	 * 
	 */
	public function getQueue() {
		global $wpdb;

		$this->createConnection();

		$filesToSync = $wpdb->get_results("SELECT * FROM `".CST_TABLE_FILES."` WHERE `synced` = '0'", ARRAY_A);

		$strCachePath = wdgPathCheck(ABSPATH .'/wp-content/cache');
		$strCachePath = wdgPathCheck(ABSPATH .'/wp-content/cache/cdn');
		$strCacheFile = wdgFileCheck($strCachePath .'/filetosync.cdn_sync');
		$strProgressPath = wdgFileCheck($strCachePath .'/progress.cdn_sync');
		$strCountPath = wdgFileCheck($strCachePath .'/progress_count.cdn_sync');

		file_put_contents($strCacheFile, serialize($filesToSync));
		file_put_contents($strProgressPath, '');
		file_put_contents($strCountPath, count($filesToSync));

		return $filesToSync;
	}

	/**
	 * Syncs all required files to CDN
	 * 
	 */
	public function syncFiles() {
		$cst_check = wp_create_nonce("cst_check_string");

		$this->createConnection();

		if (isset(CST_Page::$messages) && !empty(CST_Page::$messages)) {
			foreach (CST_Page::$messages as $message) {
				echo $message;
			}
			exit;
		}

		if ($this->connectionType == 'Origin') {
			echo 'Sync not required on origin pull CDNs.<br />';
			return false;
		} else {
			$this->findFiles();
		}

		wp_enqueue_script('cst-sync-js', plugins_url('/js/cst-sync.js', CST_FILE));
		wp_localize_script( 'cst-sync-js', 'syncAjax', array( 'ajax_url' => admin_url( 'admin-ajax.php' ), 'cst_check' => $cst_check) );

		echo '<h2>Syncing Files..</h2>';
		echo '<div class="cst-progress" style="height: 500px; overflow: auto;">';
		echo '</div><br /><br /><div class="cst-progress-return" style="display:none;">Return to <a href="'.CST_URL.'?page=cst">CST Options</a>.</div>';

	}

	/**
	 * Syncs an individual file to CDN
	 * 
	 */
	public function syncIndividualFile($file) {
		global $wpdb;

		$this->createConnection();

		echo 'Beginning pushFile call: '.$file['remote_path'].'<br />';
		try {
			$padstr = str_pad("", 512, " ");
			echo $padstr;
			$this->pushFile($file['file_dir'], $file['remote_path']);
		} catch (Exception $e) {
			echo 'File could not be uploaded: DB not updated. ';
			return false;
		}
		echo 'Syncing complete. ';
		return true;
	}

	public function syncManyFiles() {
		global $wpdb;

		$arrDebug = array();
		$strDebugPath = ABSPATH .'/wp-content/cache/debug_cst2.txt';
		touch($strDebugPath);

		$strCachePath = wdgPathCheck(ABSPATH .'/wp-content/cache');
		$strCachePath = wdgPathCheck(ABSPATH .'/wp-content/cache/cdn');
		$strFilesToSyncPath = wdgFileCheck($strCachePath .'/filetosync.cdn_sync');
		$strProgressPath = wdgFileCheck($strCachePath .'/progress.cdn_sync');
		$strCountPath = wdgFileCheck($strCachePath .'/progress_count.cdn_sync');

		$arrResults = unserialize( file_get_contents($strFilesToSyncPath) );

		if(!empty($arrResults) ) {
			$this->createConnection();
	  	$t1 = getmicrotime();

			$arrDebug['createConnection'] = getmicrotime() - $t1;

 			file_put_contents($strCountPath, count($arrResults));

			for($x = 0; ($x < 8) && !empty($arrResults); $x++){
				$file = array_shift($arrResults);


				// file_put_contents($strProgressPath, 'Beginning pushFile call: '.$file['remote_path'].'<br />', FILE_APPEND);

				$t1 = getmicrotime();
				if( file_exists($file['file_dir']) ){
					try {
						$this->pushFile($file['file_dir'], $file['remote_path']);
					} catch (Exception $e) {
						echo 'File could not be uploaded: DB not updated. ';
						return false;
					}
				}
				$arrDebug['pushFile'] = getmicrotime() - $t1;

				// file_put_contents($strProgressPath, 'Syncing complete. ', FILE_APPEND);

				$resUpdate = $wpdb->update(
						CST_TABLE_FILES,
						array('synced' => '1'),
						array('id' => $file['id'])
					);

				// file_put_contents($strProgressPath, 'DB records updated! <br /><hr />', FILE_APPEND);

	 			file_put_contents($strCountPath, count($arrResults));
				file_put_contents($strFilesToSyncPath, serialize($arrResults));

				file_put_contents($strDebugPath, print_r($arrDebug, true));
			}
		}

		return true;
	}

	public function updateDatabaseAfterSync($file) {
		global $wpdb;

		$this->createConnection();
		
		// The file_dir value will be used to update the database
		if($file) {
			$file = filter_var_array($file, FILTER_SANITIZE_STRING);
		}

		$resUpdate = $wpdb->update(
						CST_TABLE_FILES,
						array(
							'synced' => '1'
						),
						array(
							'file_dir' => $file['file_dir']
						)
					);
		echo 'DB records updated: ';
		// print_r($resUpdate);
	}

	/**
	 * Sync a specified directory to the CDN
	 * 
	 * @param $dirs array of directories to sync relative to site root
	 */
	public function syncCustomDirectory($dirs) {
		update_option('cst-custom-directories', serialize($dirs));
		foreach ($dirs as &$dir) {
			$dir = ABSPATH.$dir;
		}
		$files = self::getDirectoryFiles($dirs);
		self::_addFilesToDb($files);
		self::syncFiles();
	}

	/**
	 * Tests the CDN connection
	 */
	public function testConnection() {
		self::createConnection();
	}

	/**
 	 * Gets all media files
 	 * 
	 */
	private function getMediaFiles() {
		global $wpdb;
		$mediaFiles = array();
		$files = $wpdb->get_results("SELECT pmo.meta_value AS filename , pmt.meta_value AS meta 
							 FROM ".$wpdb->postmeta." as pmo 
							 INNER JOIN ".$wpdb->postmeta." as pmt 
							 ON pmt.post_id = pmo.post_id 
							 AND pmt.meta_key = '_wp_attachment_metadata'   
							 WHERE pmo.meta_key = '_wp_attached_file'",ARRAY_A );
		$uploadBasedir = $this->getUploadBasedir();
		foreach($files as $file) {
			$mediaFiles[] = $uploadBasedir.'/'.$file['filename'];
		}
		return $mediaFiles;
	}

	/**
	 * Loops through a directory checking file types
	 * 
	 * @param array directories to loop through
	 * @return array of file directories
	 */
	private function getDirectoryFiles($dirs) {
		$files = array();
		foreach ($dirs as $dir) {

			if (!$this->checkIfIgnoredPath($dir)) { // first, confirm that this directory is not an ignored path
				// if ($handle = opendir($dir)) {
				// 	while (false !== ($entry = readdir($handle))) {
				// 		if (preg_match('$\.(css|js|jpe?g|gif|png)$', $entry)) {
				// 			$files[] = $dir.'/'.$entry;
				// 		}
				// 	}
				// 	closedir($handle);
				// }

				$di = new RecursiveDirectoryIterator($dir);

				foreach (new RecursiveIteratorIterator($di) as $filename => $file) {
					if (preg_match('$\.(css|js|jpe?g|gif|png)$', $filename)) {
						if (!$this->checkIfIgnoredPath($filename)) {
							// if this is a valid filename, and the filename is not an ignored
							$files[] = $filename;
						} else {
							// echo "Note: the file " . $filename . " will not be synced because it is in an ignored filepath. <br />";
						}
					}
				}
			}
			else {
				// echo "Note: " . $dir . "will not be synced because it is an ignored path. <br />";
			}

		}
		return $files;
	}

	public function uploadMedia($post_id, $data = '') {
		self::createConnection();

		$files = array(); // initialize array of files. we will add any uploaded pathnames that need to be synced

		// if this is not an image, add the filepath specified via wp_handle_upload hook
		if(count($post_id) < 1 && !isset($post_id['file'])) {
			$filepath = $this->_file;
			$files[] = $filepath;
		}
		// else add the passed image file path, along with any additional image sizes
		else {
			$filepath = $this->getUploadBasedir().'/'.$post_id['file'];
			$files[] = $filepath;

			// Add other filesizes
			if (isset($post_id['sizes']) && is_array($post_id['sizes']) && !empty($post_id['sizes'])) {
				$uploadPath = $this->getUploadPath();
				foreach($post_id['sizes'] as $size) {
					$filepath = $uploadPath.'/'.$size['file'];
					$files[] = $filepath;
				}
			}
		}

		// add files to DB, setting them as synced (since we are syncing now)
		self::_addFilesToDb($files, $forceSynced=true);

		// iterate through files and push to CDN
		foreach ($files as $file) {
			$remotePath = $this->generateRemotePath($file);
			self::pushFile($file, $remotePath);
		}

		return $post_id;
	}

	public function createNonce() {
		$GLOBALS['nonce'] = wp_create_nonce('cst-nonce');
	}

	/**
	 * Enqueues the JS/CSS
	 * 
	 */
	public function enqueueFiles() {
		wp_enqueue_script('cst-generic-js', plugins_url('/js/cst-js.js', CST_FILE));
		wp_enqueue_style('cst-generic-style', plugins_url('/css/cst-style.css', CST_FILE));
	}

	/**
	 * Creates the admin page(s) required
	 * 
	 */
	public function createPages() {
		require_once CST_DIR.'lib/pages/Options.php';
		add_options_page('CST Options', 'CDN Sync Tool', 'manage_options', 'cst', array('CST_Page_Options', 'page'));
	}
}
