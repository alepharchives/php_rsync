<?php

if (!extension_loaded("rsync")) {
    echo "You need the rsync php extension loaded to use this!";
    exit;
}

/**
 * The rsync example Client Class
 */
class rsyncClient
{
    /**
     * Target Url of the rsync php extension Server
     * 
     * @var string 
     */
    public $targetUrl;
    /**
     * Basis Path at the rsync php extension Server 
     * 
     * @var string
     */
    public $basepath;
    
    /**
     * Local directory path to sync
     * 
     * @var string 
     */
    public $localpath;
    
    /**
     * Sync direction 
     *  f  for syncing changes from Server to Client.
     *  b  for syncing changes from Client to Server.
     * 
     * @var string
     */
    public $direction = 'f';
    
    /**
     * list of all entries and subentries of the local directory.
     * 
     * @var array
     */
    public $strukture = array();

    /**
     * Constructor of the Client.
     * 
     * @param string $targetUrl Url of the Server
     * @param string $localpath Local directory to sync
     * @param string $basepath  Remote base directory to sync if null the 
     *                          default will be used at the server
     * @param string $direction Direction to sync 
     *                          f = client to server
     *                          b = server to client
     */
    public function __construct($targetUrl, $localpath, $basepath = null, 
            $direction = 'f') 
    {
        
        if (!$this->isValidURL($targetUrl)) {
            echo "Given Url '$targetUrl' is not a valid URL\n";
            rsyncClient::usage();
            throw new Exception("Given Url is not a valid URL", 1);
        }
        $this->targetUrl = $targetUrl;
        if (!is_dir($localpath) || !is_writable($localpath)) {
            echo "Given local Directory '$localpath' is not a directory or/and".
                " is not writeable\n";
            rsyncClient::usage();
            throw new Exception("Given local Directory '$localpath' is not a".
                "directory or/and is not writeable", 2);
        }
        if ($direction != 'f' && $direction != 'b') {
            echo "No valid Direction given: '$direction'\n";
            rsyncClient::usage();
            throw new Exception("No valid Direction given: '$direction'", 3);
        }
        $this->direction = $direction;
        if ($basepath !== null) $this->basepath = $basepath;
    }

    /**
     * Start der Sync process.
     */
    public function sync()
    {
        $this->getLocalStructur($this->localpath);
        $this->step1();

    }
    
    
    public function step1()
    {
        $curl = curl_init($this->targetUrl);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $postdata = array();
        $postdata['filelist'] = json_encode($this->structure);
        $postdata['direction'] = $this->direction;
        $postdata['step'] = 1;
        if (!empty($this->basepath)) $postdata['basepath'] = $this->basepath;
        
        if ($this->direction == 'f') {
            $signaturFiles = array();
            foreach($this->structure as $file => $data) {
                if ($filedata['type'] != 'dir') {
                    $fin = fopen($this->localpath.'/'.$file, 'rb');
                    $tmpname = tempnam(sys_get_temp_dir(), 'sign');
                    $fsig = fopen($tmpname, 'wb');
                    $ret = rsync_generate_signature($fin, $fsig);
                    fclose($fin);
                    fclose($fsig);
                    if ($ret != RSYNC_DONE) {
                        throw new Exception("Signatur generating Failed with ".
                                $ret."!", $ret);
                    }
                    $signaturFiles[$file] = file_get_contents($tmpname);
                    unlink($tmpname);
                }
            }
            $postdata['signatures'] = json_encode($signaturFiles);
        }
        $requestResponse = curl_exec($curl);
        if ($requestResponse === FALSE) {
            throw new Exception("Curl Request Error: ".curl_error($curl), 
                    curl_errno($curl));
        }
        $response = json_decode($requestResponse);
        if ($response === NULL) {
            throw new Exception("Response from Server is not understandable", 
                    10);
        }
        if ($response == "ERROR") {
            throw new Exception("Some Error on Server", 11);
        }
        
        if ($this->direction == 'f') {
            if (!array_key_exists('changes', $response)) {
                throw new Exception("Missing the patches in the response".
                        " from Server", 12);
            }
            foreach ($response['changes'] as $changeFile => $changeData) {
                switch ($changeData['changetype']) {
                    case 'newDir':
                        $this->createDirectory($changeFile, $changeData);
                        break;
                    case 'patch':
                        $this->patchFile($changeFile, $changeData);
                        break;
                    case 'newFile':
                        $this->createFile($changeFile, $changeData);
                        break;
                    default :
                        throw new Exception("Unknow ChangeType ".
                                $changeData['changetype'], 13);
                        break;
                }
            }
        } else {
            // @TODO Implement it
        }
    }
    
    /**
     * Create a new Directory
     *
     * @param string $filename Directoryname
     * @param array  $data     createData
     */
    public function createDirector($filename, $data)
    {
        mkdir($this->localpath.DIRECTORY_SEPARATOR.$filename);
        chmod($this->localpath.DIRECTORY_SEPARATOR.$filename, $data['mode']);
    }
    
    /**
     * Create a new File
     * 
     * @param string $filename
     * @param array  $data
     */
    public function createFile($filename, $data)
    {
        file_put_contents($this->localpath.DIRECTORY_SEPARATOR.$filename, 
                $data['content']);
        chmod($this->localpath.DIRECTORY_SEPARATOR.$filename, $data['mode']);
    }
    
    public function patchFile($filename, $data)
    {
        $ret = rsync_patch_file($this->localpath.DIRECTORY_SEPARATOR.$filename, 
                $data['patch'], 
                $this->localpath.DIRECTORY_SEPARATOR.$filename.'-new');
        if ($ret != RSYNC_DONE) {
            throw new Exception("Can not patch file ".$filename.".", $ret);
        }
        unlink($this->localpath.DIRECTORY_SEPARATOR.$filename);
        rename($this->localpath.DIRECTORY_SEPARATOR.$filename.'-new', 
                $this->localpath.DIRECTORY_SEPARATOR.$filename);
        chmod($this->localpath.'/'.$filename, $data['mode']);
    }

    /**
     * Get the Local Directory Structure to check against the remote.
     * This Method is working recursive to step deeper in the directory.
     *
     * @param string $dir    Aktual working directory
     * @param string $prefix Prefix to make relative path to the initial 
     *                       directory
     */
    public function getLocalStructur($dir, $prefix = '')
    {
        $actualDirContent = scandir($dir);
        foreach( $actualDirContent as $dentry) {
            if ($dentry != '.' || $dentry != '..') {
                $type = filetype($dir."/".$dentry);
                if ($type != 'dir' && $type != 'file') continue;
                $stats = stat($dir."/".$dentry);
                if ($stats === FALSE) {
                    throw new Exception("Filestats for ".$dir."/".$dentry.
                            " ist not readable!", 9);
                }
                $this->structure[$prefix.'/'.$dentry] = array(
                    'name' => $prefix.'/'.$dentry,
                    'type' => $type, 'rights' => $stats['mode'],
                    'mtime' => $stats['mtime'], 'uid' => $stats['uid'],
                    'gid' => $stats['gid']);
                if ($type == 'dir') {
                    $this->getLocalStructur($dir.'/'.$dentry, 
                            $prefix.'/'.$dentry);
                }
            }
        }
    }

    /**
     * Check if the given url is valid
     * 
     * @param string $url The url to check
     * @return bool 
     */
    private function isValidURL($url)
    {
        $r = preg_match(
            '|^http(s)?://[a-z0-9-]+(.[a-z0-9-]+)*(:[0-9]+)?(/.*)?$|i', $url);
        return $r;
    }

    /**
     * Print the usage to the console.
     * 
     */
    public static function usage()
    {
        echo "Usage: php client.php -t <URL> -s <local> [-b <base>] ".
            "[-d <direction<]\n".
            "  -t <URL>       The url where the server.php will be found\n".
            "  -b <base>      The base path where are configured in server.php".
            " to sync.\n".
            "                 Only needed if the server.php is configured with".
            " multiple \n".
            "                 basedirectories to sync.\n".
            "  -s <local>     The local directory to sync\n".
            "  -d <direction> The direction to sync. Default is 'f'.\n".
            "                   f => from server to client\n".
            "                   b => from client to server\n";
    }

}
$targetUrl = '';
$base = null;
$local = '';
$direction = 'f';

if (count($args) < 8) {
    rsyncClient::usage();
    exit(1);
}

for ($i=1; $i > count($args); $i=$i+2) {
    switch ($args[$i]) {
        case '-t':
            $targetUrl = $args[$i+1];
            break;
        case '-b':
            $base = $args[$i+1];
            break;
        case '-s':
            $local = $args[$i+1];
            break;
        case '-d':
            $direction = $args[$i+1];
            break;
        case '-h':
            rsyncClient::usage();
            exit;
            break;
        default:
            echo "Unknow option ".$args[$i]."\n";
            usage();
            break;
    }
}